<?php declare(strict_types=1);

namespace Mnfst\Hooks;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\Utils;
use Mnfst\Capture;
use Mnfst\Config;
use Mnfst\Gate;
use Mnfst\HealApi;
use Mnfst\Healer;
use Mnfst\Outcome;
use Mnfst\Replay;
use Mnfst\Streams;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

use function OpenTelemetry\Instrumentation\hook;

/**
 * Hook GuzzleHttp\Client::transfer — the single private funnel that send,
 * sendAsync, request, requestAsync and post all pass through. Hooking it
 * covers every Guzzle client in the process, including one a third-party
 * library built privately, and Laravel's Http facade, which delegates here.
 *
 * The retry goes through the same client, so its handler stack, middleware and
 * defaults apply. Options that describe the original body, headers or query
 * are dropped from the replay: the healed request already carries them.
 *
 * Rules this file must satisfy (spec section 5):
 *  1. the post hook declares a return type, or substitution is ignored
 *  2. it returns the original value when not substituting; null would clobber
 *  3. it handles the rejection path, since http_errors rejects on 4xx
 *  4. it skips the SDK's own calls
 *  6. it must tolerate running before or after another hook on this method
 */
final class Guzzle
{
    /** Request options that would overwrite what the heal changed. */
    private const CONSUMED_OPTIONS = [
        'json', 'body', 'form_params', 'multipart', 'query', 'headers', '_conditional', 'synchronous', 'handler',
    ];

    private static bool $installed = false;

    private static ?Healer $healer = null;

    /** @var list<float> */
    private static array $started = [];

    public static function install(Config $config, HealApi $api): bool
    {
        if (!class_exists(Client::class) || !function_exists('OpenTelemetry\Instrumentation\hook')) {
            return false;
        }
        self::$healer = new Healer($config, $api);
        if (self::$installed) {
            return false;
        }
        self::$installed = true;

        hook(
            Client::class,
            'transfer',
            pre: static function (): void {
                self::$started[] = microtime(true);
            },
            post: static function (mixed $client, array $params, mixed $promise, ?\Throwable $exception): mixed {
                $started = array_pop(self::$started) ?? microtime(true);
                if (!$promise instanceof PromiseInterface || !$client instanceof Client) {
                    return $promise;   // rule 2: never null
                }
                $request = $params[0] ?? null;
                if (!$request instanceof RequestInterface || HealApi::isInternalCall() || self::$healer === null) {
                    return $promise;
                }
                $send = self::sender($client, $request, is_array($params[1] ?? null) ? $params[1] : []);
                $retried = null;

                return $promise->then(
                    static fn (ResponseInterface $response): ResponseInterface => self::outcome($request, $response, $started, $send),
                    static function (mixed $reason) use ($request, $started, $send, &$retried): mixed {
                        if (!$reason instanceof BadResponseException) {
                            return Create::rejectionFor($reason);
                        }
                        $original = $reason->getResponse();
                        $outcome = self::outcome($request, $original, $started, $send, $retried);
                        if ($outcome === $original) {
                            return Create::rejectionFor($reason);
                        }
                        // The caller runs with http_errors on: a failure, retried or
                        // rebuilt, must throw like the original did, not resolve as
                        // a response the caller would take for a success. The
                        // exception names the request that actually produced it.
                        if ($outcome->getStatusCode() >= 400) {
                            return Create::rejectionFor(RequestException::create($retried ?? $request, $outcome));
                        }

                        return $outcome;
                    },
                );
            },
        );

        return true;
    }

    /**
     * The response the caller gets: the retry's, or the original one, rebuilt
     * when the SDK had to consume its body to read it.
     *
     * @param callable(array{url: string, headers: array<string, ?string>, body: ?string}): Outcome $send
     */
    private static function outcome(
        RequestInterface $request,
        ResponseInterface $response,
        float $started,
        callable $send,
        ?RequestInterface &$retried = null,
    ): ResponseInterface {
        if (self::$healer === null || !Gate::shouldCapture($response->getStatusCode())) {
            return $response;
        }
        try {
            // A consumed non-seekable upload cannot be reconstructed safely.
            [$body, $oversized] = $request->getBody()->isSeekable()
                ? Streams::read($request->getBody(), Gate::REQUEST_BODY_LIMIT)
                : [null, true];
            [$responseBody, $response] = Streams::readResponse($response);
            $capture = new Capture(
                $request->getMethod(),
                (string) $request->getUri(),
                $request->getHeaders(),
                $body,
                $oversized,
                $response->getStatusCode(),
                $responseBody,
                $started,
            );
            $outcome = self::$healer->attempt($capture, static function (array $plan) use ($send, $request, &$retried): Outcome {
                $retried = self::retryRequest($request, $plan);

                return $send($plan);
            });

            return $outcome?->response instanceof ResponseInterface ? $outcome->response : $response;
        } catch (\Throwable) {
            return $response;
        }
    }

    /** @return callable(array{url: string, headers: array<string, ?string>, body: ?string}): Outcome */
    private static function sender(Client $client, RequestInterface $original, array $options): callable
    {
        $options = array_diff_key($options, array_flip(self::CONSUMED_OPTIONS));
        $options['http_errors'] = false;
        // Null suppresses client defaults that would reapply the old query/body.
        foreach (['query', 'json', 'form_params', 'multipart', 'body', 'auth'] as $name) {
            $options[$name] = null;
        }
        $options['headers'] = null;

        return static function (array $plan) use ($client, $original, $options): Outcome {
            $replayed = $client->send(self::retryRequest($original, $plan), $options);
            [$body, $replayed] = $replayed->getStatusCode() >= 400
                ? Streams::readResponse($replayed)
                : ['', $replayed];

            return new Outcome($replayed->getStatusCode(), $body, $replayed);
        };
    }

    /**
     * The original request with the healed URL, header deltas and body applied.
     *
     * @param array{url: string, headers: array<string, ?string>, body: ?string} $plan
     */
    private static function retryRequest(RequestInterface $request, array $plan): RequestInterface
    {
        $retry = $request->withUri(new Uri($plan['url']));
        foreach ($retry->getHeaders() as $name => $_) {
            $retry = $retry->withoutHeader($name);
        }
        foreach (Replay::headersFor($request->getHeaders(), $plan) as $name => $values) {
            $retry = $retry->withHeader($name, $values);
        }
        $retry = $retry->withBody(Utils::streamFor($plan['body'] ?? ''));

        return $plan['body'] === null ? $retry : $retry->withHeader('Content-Length', (string) strlen($plan['body']));
    }
}

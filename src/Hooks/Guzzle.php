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
use Mnfst\Replay;
use Mnfst\Retry;
use Mnfst\Streams;
use Mnfst\Wire;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

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
            post: static function (mixed $client, array $params, mixed $promise, ?\Throwable $exception): mixed {
                if (!$promise instanceof PromiseInterface) {
                    return $promise;   // rule 2: never null
                }
                $request = $params[0] ?? null;
                if (!$request instanceof RequestInterface || HealApi::isInternalCall() || self::$healer === null) {
                    return $promise;
                }
                // transfer() returns before the response arrives, so this is
                // effectively the request's start time.
                $started = microtime(true);
                $send = self::sender($client, $request, is_array($params[1] ?? null) ? $params[1] : []);

                return $promise->then(
                    static fn (ResponseInterface $response): ResponseInterface => self::outcome($request, $response, $started, $send),
                    static function (mixed $reason) use ($request, $started, $send): mixed {
                        if (!$reason instanceof BadResponseException) {
                            return Create::rejectionFor($reason);
                        }
                        $original = $reason->getResponse();
                        $outcome = self::outcome($request, $original, $started, $send);
                        if ($outcome === $original) {
                            return Create::rejectionFor($reason);
                        }
                        // The caller runs with http_errors on: a failure, retried or
                        // rebuilt, must throw like the original did, not resolve as
                        // a response the caller would take for a success.
                        if ($outcome->getStatusCode() >= 400) {
                            return Create::rejectionFor(RequestException::create($request, $outcome));
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
     * @param callable(Retry): Replay $send
     */
    private static function outcome(RequestInterface $request, ResponseInterface $response, float $started, callable $send): ResponseInterface
    {
        if (self::$healer === null || !Gate::shouldCapture($response->getStatusCode())) {
            return $response;
        }
        [$body, $oversized] = Streams::read($request->getBody(), Gate::REQUEST_BODY_LIMIT);
        [$responseBody, $response] = Streams::readResponse($response, static fn (string $raw): StreamInterface => Utils::streamFor($raw));
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
        $replay = self::$healer->attempt($capture, $send);

        return $replay?->response instanceof ResponseInterface ? $replay->response : $response;
    }

    /** @return callable(Retry): Replay */
    private static function sender(mixed $client, RequestInterface $original, array $options): callable
    {
        $sender = $client instanceof Client ? $client : new Client();
        $options = array_diff_key($options, array_flip(self::CONSUMED_OPTIONS));
        $options['http_errors'] = false;

        return static function (Retry $retry) use ($sender, $original, $options): Replay {
            $replayed = $sender->send(self::request($original, $retry), $options);
            [$body, $replayed] = Streams::readResponse($replayed, static fn (string $raw): StreamInterface => Utils::streamFor($raw));

            return new Replay($replayed->getStatusCode(), $body, $replayed);
        };
    }

    /** The original request with the healed URL, headers and body swapped in. */
    private static function request(RequestInterface $original, Retry $retry): RequestInterface
    {
        $request = $original->withUri(new Uri($retry->url));
        foreach (array_keys($original->getHeaders()) as $name) {
            $request = $request->withoutHeader($name);
        }
        foreach ($retry->headers as $name => $values) {
            $request = $request->withHeader($name, $values);
        }

        return $request->withBody(Utils::streamFor($retry->body ?? ''));
    }
}

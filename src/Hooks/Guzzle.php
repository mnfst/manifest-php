<?php declare(strict_types=1);

namespace Mnfst\Hooks;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\Utils;
use Mnfst\Bodies;
use Mnfst\Config;
use Mnfst\Gate;
use Mnfst\HealApi;
use Mnfst\Replay;
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
 * Rules this file must satisfy (spec section 5):
 *  1. the post hook declares a return type, or substitution is ignored
 *  2. it returns the original value when not substituting; null would clobber
 *  3. it handles the rejection path, since http_errors rejects on 4xx
 *  4. it skips the SDK's own calls
 *  6. it must tolerate running before or after another hook on this method
 *
 * The retry goes back through the SAME client with the caller's own options,
 * so it keeps the handler stack (Laravel's Http::fake, mocks, middleware),
 * proxy, TLS and timeout settings the original call had. A retry through a
 * fresh client would leave a test suite's faked 4xx retrying on the real
 * network.
 */
final class Guzzle
{
    /**
     * Options the retry must not repeat: the ones that describe the original
     * body, headers or URL (the retry request already carries the healed
     * ones), and `handler`, which the client's own config supplies and which
     * Guzzle refuses as a per-request option.
     */
    private const REQUEST_SHAPING_OPTIONS = ['json', 'form_params', 'multipart', 'body', 'query', 'headers', 'synchronous', 'handler'];

    private static bool $installed = false;

    /** @var array{config: Config, api: HealApi}|null */
    private static ?array $deps = null;

    /**
     * hook() takes a static closure that cannot capture $config and $api,
     * because registration is process-global. The dependencies are held here
     * instead and read by attempt().
     */
    public static function install(Config $config, HealApi $api): bool
    {
        if (!class_exists(Client::class) || !function_exists('OpenTelemetry\Instrumentation\hook')) {
            return false;
        }
        self::$deps = ['config' => $config, 'api' => $api];
        if (self::$installed) {
            return false;
        }
        self::$installed = true;

        hook(
            Client::class,
            'transfer',
            post: static function (mixed $client, array $params, mixed $promise, ?\Throwable $exception): mixed {
                if (!$promise instanceof PromiseInterface || !$client instanceof Client) {
                    return $promise;   // rule 2: never null
                }
                $request = $params[0] ?? null;
                $options = is_array($params[1] ?? null) ? $params[1] : [];
                if (!$request instanceof RequestInterface || HealApi::isInternalCall()) {
                    return $promise;
                }

                // transfer() returns before the response arrives, so this is
                // effectively the request's start time.
                $started = microtime(true);

                return $promise->then(
                    static fn (ResponseInterface $response): ResponseInterface
                        => self::attempt($client, $options, $request, $response, $started)['response'] ?? $response,
                    static function (mixed $reason) use ($client, $options, $request, $started): mixed {
                        if (!$reason instanceof BadResponseException) {
                            return Create::rejectionFor($reason);
                        }
                        $retried = self::attempt($client, $options, $request, $reason->getResponse(), $started);
                        if ($retried === null) {
                            return Create::rejectionFor($reason);
                        }

                        // The caller asked for exceptions on a 4xx (http_errors), so a
                        // retry that fails again rejects the same way, naming the retry.
                        return $retried['response']->getStatusCode() >= 400
                            ? Create::rejectionFor(RequestException::create($retried['request'], $retried['response']))
                            : $retried['response'];
                    },
                );
            },
        );

        return true;
    }

    /**
     * One capture: heal, apply, retry once, report. Null means "no change".
     *
     * @return array{request: RequestInterface, response: ResponseInterface}|null
     */
    private static function attempt(
        Client $client,
        array $options,
        RequestInterface $request,
        ResponseInterface $response,
        float $started,
    ): ?array {
        try {
            if (self::$deps === null || !Gate::shouldCapture($response->getStatusCode())) {
                return null;
            }
            $api = self::$deps['api'];

            $contentType = Bodies::contentTypeOf($request->getHeaders());
            [$body, $replayable] = Bodies::parseRequestBody(self::text($request->getBody()), $contentType);
            [$responseBody, $truncated] = Wire::cappedResponseBody(self::text($response->getBody()));

            $result = $api->heal(Wire::healPayload(
                bin2hex(random_bytes(16)),
                $request->getMethod(),
                (string) $request->getUri(),
                $request->getHeaders(),
                $body,
                $response->getStatusCode(),
                $responseBody,
                $truncated,
                (int) round((microtime(true) - $started) * 1000),
            ));
            $attemptId = is_array($result) ? ($result['healAttemptId'] ?? null) : null;
            $attemptId = is_string($attemptId) ? $attemptId : null;

            $plan = Replay::plan($request->getMethod(), (string) $request->getUri(), $body, $replayable, $contentType, $result);
            if ($plan === null) {
                if ($attemptId !== null) {
                    $api->reportFailure($attemptId, 'not_attempted', HealApi::NOT_ATTEMPTED);
                }

                return null;
            }

            $retry = self::retryRequest($request, $plan);
            try {
                $replayed = HealApi::withInternalCall(
                    static fn (): ResponseInterface => $client->send($retry, self::retryOptions($options)),
                );
            } catch (\Throwable $e) {
                if ($attemptId !== null) {
                    $api->reportFailure($attemptId, 'transport_error', $e->getMessage());
                }

                return null;
            }

            if ($attemptId !== null) {
                self::report($api, $attemptId, $replayed);
            }

            return ['request' => $retry, 'response' => $replayed];
        } catch (\Throwable) {
            return null;   // rule 5: fail open
        }
    }

    /** @param array{url: string, headers: array<string, ?string>, body: ?string} $plan */
    private static function retryRequest(RequestInterface $request, array $plan): RequestInterface
    {
        $retry = $request->withUri(new Uri($plan['url']))->withoutHeader('Content-Length');
        foreach ($plan['headers'] as $name => $value) {
            $retry = $value === null ? $retry->withoutHeader($name) : $retry->withHeader($name, $value);
        }
        $retry = $retry->withBody(Utils::streamFor($plan['body'] ?? ''));

        return $plan['body'] === null ? $retry : $retry->withHeader('Content-Length', (string) strlen($plan['body']));
    }

    private static function retryOptions(array $options): array
    {
        foreach (self::REQUEST_SHAPING_OPTIONS as $name) {
            unset($options[$name]);
        }
        $options['http_errors'] = false;

        return $options;
    }

    private static function report(HealApi $api, string $attemptId, ResponseInterface $replayed): void
    {
        $status = $replayed->getStatusCode();
        if ($status < 400) {
            $api->reportResponse($attemptId, $status);

            return;
        }
        [$body, $truncated] = Wire::cappedResponseBody(self::text($replayed->getBody()));
        $api->reportResponse($attemptId, $status, $body, $truncated);
    }

    /** Read a stream without leaving it drained for whoever reads it next. */
    private static function text(StreamInterface $stream): string
    {
        $text = (string) $stream;
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        return $text;
    }
}

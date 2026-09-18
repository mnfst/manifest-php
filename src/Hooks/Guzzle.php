<?php declare(strict_types=1);

namespace Mnfst\Hooks;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Utils;
use Mnfst\Bodies;
use Mnfst\Config;
use Mnfst\Gate;
use Mnfst\HealApi;
use Mnfst\Merge;
use Mnfst\Wire;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

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
 */
final class Guzzle
{
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
                if (!$promise instanceof PromiseInterface) {
                    return $promise;   // rule 2: never null
                }
                $request = $params[0] ?? null;
                if (!$request instanceof RequestInterface || HealApi::isInternalCall()) {
                    return $promise;
                }

                // transfer() returns before the response arrives, so this is
                // effectively the request's start time.
                $started = microtime(true);

                return $promise->then(
                    static fn (ResponseInterface $response): ResponseInterface
                        => self::attempt($request, $response, $started) ?? $response,
                    static function (mixed $reason) use ($request, $started): mixed {
                        if (!$reason instanceof BadResponseException) {
                            return Create::rejectionFor($reason);
                        }

                        return self::attempt($request, $reason->getResponse(), $started)
                            ?? Create::rejectionFor($reason);
                    },
                );
            },
        );

        return true;
    }

    /** One capture: heal, apply, retry once, report. Null means "no change". */
    private static function attempt(RequestInterface $request, ResponseInterface $response, float $started): ?ResponseInterface
    {
        try {
            if (self::$deps === null || !Gate::shouldCapture($response->getStatusCode())) {
                return null;
            }
            $api = self::$deps['api'];

            $contentType = Bodies::contentTypeOf($request->getHeaders());
            [$body, $replayable] = Bodies::parseRequestBody((string) $request->getBody(), $contentType);
            [$responseBody, $truncated] = Wire::cappedResponseBody((string) $response->getBody());

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

            if (!is_array($result)) {
                return null;
            }

            $attemptId = $result['healAttemptId'] ?? null;
            $healedRequest = $result['healedRequest'] ?? null;

            if (!in_array($result['status'] ?? null, ['patched', 'unverified'], true) || !is_array($healedRequest)) {
                return null;
            }

            if (!$replayable || !array_key_exists('body', $healedRequest)) {
                self::abandon($api, $attemptId);

                return null;
            }

            $merged = Merge::healedBody($body, Wire::travelingBody($body), $healedRequest['body']);
            $encoded = Bodies::encodeRequestBody($merged, $contentType);
            if ($encoded === null) {
                self::abandon($api, $attemptId);

                return null;
            }

            $retry = $request
                ->withBody(Utils::streamFor($encoded))
                ->withHeader('Content-Length', (string) strlen($encoded));

            $replayed = HealApi::withInternalCall(
                static fn (): ResponseInterface => (new Client(['http_errors' => false]))
                    ->send($retry, ['http_errors' => false]),
            );

            if (is_string($attemptId)) {
                $status = $replayed->getStatusCode();
                $failedBody = $status >= 400 ? Wire::cappedResponseBody((string) $replayed->getBody())[0] : null;
                $api->reportOutcome($attemptId, $status, is_array($failedBody) ? $failedBody : null);
            }

            return $replayed;
        } catch (\Throwable) {
            return null;   // rule 5: fail open
        }
    }

    /** Close an attempt we opened but could not replay. */
    private static function abandon(HealApi $api, mixed $attemptId): void
    {
        if (is_string($attemptId)) {
            $api->reportOutcome($attemptId, null, null, HealApi::NOT_ATTEMPTED);
        }
    }
}

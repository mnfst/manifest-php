<?php declare(strict_types=1);

namespace Mnfst\Hooks;

use Cake\Http\Client;
use Cake\Http\Client\Response as CakeResponse;
use Mnfst\Bodies;
use Mnfst\Config;
use Mnfst\Gate;
use Mnfst\HealApi;
use Mnfst\Merge;
use Mnfst\Wire;
use Psr\Http\Message\RequestInterface;

use function OpenTelemetry\Instrumentation\hook;

/**
 * Hook Cake\Http\Client::send — the funnel that post, get and the rest reach
 * through _doRequest. Cake has adapters rather than middleware, so there is no
 * stack to push onto; the hook is the only global insertion point.
 *
 * The post hook returns `mixed` and never null: a nullable return type plus an
 * early `return null` replaces the caller's response with null and fatals the
 * app (spec section 5, rule 2).
 */
final class Cake
{
    private static bool $installed = false;

    /** @var array{config: Config, api: HealApi}|null */
    private static ?array $deps = null;

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
            'send',
            post: static function (mixed $client, array $params, mixed $response, ?\Throwable $exception): mixed {
                if (!$response instanceof CakeResponse || HealApi::isInternalCall()) {
                    return $response;
                }
                $request = $params[0] ?? null;
                if (!$request instanceof RequestInterface) {
                    return $response;
                }

                return self::attempt($request, $response) ?? $response;
            },
        );

        return true;
    }

    private static function attempt(RequestInterface $request, CakeResponse $response): ?CakeResponse
    {
        try {
            if (self::$deps === null || !Gate::shouldCapture($response->getStatusCode())) {
                return null;
            }
            $api = self::$deps['api'];

            $contentType = Bodies::contentTypeOf($request->getHeaders());
            [$body, $replayable] = Bodies::parseRequestBody((string) $request->getBody(), $contentType);
            [$responseBody, $truncated] = Wire::cappedResponseBody($response->getStringBody());

            $result = $api->heal(Wire::healPayload(
                bin2hex(random_bytes(16)),
                $request->getMethod(),
                (string) $request->getUri(),
                $request->getHeaders(),
                $body,
                $response->getStatusCode(),
                $responseBody,
                $truncated,
                0,
            ));

            if (!is_array($result)) {
                return null;
            }

            $attemptId = $result['healAttemptId'] ?? null;
            $healedRequest = $result['healedRequest'] ?? null;

            if (!in_array($result['status'] ?? null, ['patched', 'unverified'], true)
                || !is_array($healedRequest)
                || !array_key_exists('body', $healedRequest)
                || !$replayable
            ) {
                self::abandon($api, $attemptId);

                return null;
            }

            $merged = Merge::healedBody($body, Wire::travelingBody($body), $healedRequest['body']);
            $encoded = Bodies::encodeRequestBody($merged, $contentType);
            if ($encoded === null) {
                self::abandon($api, $attemptId);

                return null;
            }

            $type = str_contains($contentType, 'json') || $contentType === '' ? 'json' : 'form';
            $replayed = HealApi::withInternalCall(
                static fn (): CakeResponse => (new Client())
                    ->post((string) $request->getUri(), $encoded, ['type' => $type]),
            );

            if (is_string($attemptId)) {
                $status = $replayed->getStatusCode();
                $failedBody = $status >= 400 ? Wire::cappedResponseBody($replayed->getStringBody())[0] : null;
                $api->reportOutcome($attemptId, $status, is_array($failedBody) ? $failedBody : null);
            }

            return $replayed;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function abandon(HealApi $api, mixed $attemptId): void
    {
        if (is_string($attemptId)) {
            $api->reportOutcome($attemptId, null, null, HealApi::NOT_ATTEMPTED);
        }
    }
}

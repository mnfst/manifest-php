<?php declare(strict_types=1);

namespace Mnfst\Hooks;

use Cake\Http\Client;
use Cake\Http\Client\Response as CakeResponse;
use Laminas\Diactoros\Stream;
use Laminas\Diactoros\Uri;
use Mnfst\Bodies;
use Mnfst\Config;
use Mnfst\Gate;
use Mnfst\HealApi;
use Mnfst\Replay;
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
 *
 * The retry is the caller's own request — same method, same headers, healed
 * URL and body — sent back through the same client with the same adapter
 * options. It is not a fresh POST.
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
                if (!$response instanceof CakeResponse || !$client instanceof Client || HealApi::isInternalCall()) {
                    return $response;
                }
                $request = $params[0] ?? null;
                $options = is_array($params[1] ?? null) ? $params[1] : [];
                if (!$request instanceof RequestInterface) {
                    return $response;
                }

                return self::attempt($client, $options, $request, $response) ?? $response;
            },
        );

        return true;
    }

    private static function attempt(Client $client, array $options, RequestInterface $request, CakeResponse $response): ?CakeResponse
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
                    static fn (): CakeResponse => $client->send($retry, $options),
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

            return $replayed;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array{url: string, headers: array<string, ?string>, body: ?string} $plan */
    private static function retryRequest(RequestInterface $request, array $plan): RequestInterface
    {
        $retry = $request->withUri(new Uri($plan['url']))->withoutHeader('Content-Length');
        foreach ($plan['headers'] as $name => $value) {
            $retry = $value === null ? $retry->withoutHeader($name) : $retry->withHeader($name, $value);
        }
        $stream = new Stream('php://memory', 'wb+');
        $stream->write($plan['body'] ?? '');
        $stream->rewind();
        $retry = $retry->withBody($stream);

        return $plan['body'] === null ? $retry : $retry->withHeader('Content-Length', (string) strlen($plan['body']));
    }

    private static function report(HealApi $api, string $attemptId, CakeResponse $replayed): void
    {
        $status = $replayed->getStatusCode();
        if ($status < 400) {
            $api->reportResponse($attemptId, $status);

            return;
        }
        [$body, $truncated] = Wire::cappedResponseBody($replayed->getStringBody());
        $api->reportResponse($attemptId, $status, $body, $truncated);
    }
}

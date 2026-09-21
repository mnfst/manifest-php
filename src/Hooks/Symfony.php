<?php declare(strict_types=1);

namespace Mnfst\Hooks;

use Mnfst\Config;
use Mnfst\HealApi;
use Mnfst\Healer;
use Mnfst\Response\LazyHealingResponse;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NativeHttpClient;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Symfony\Component\HttpClient\Response\ResponseStream;

use function OpenTelemetry\Instrumentation\hook;

/**
 * Symfony HttpClient. Its responses are lazy — the request only runs when the
 * app reads the status, headers or body — and a stream() call reads several at
 * once for concurrency. So the hook cannot heal at request() time without
 * serialising everything; it wraps each response in a decorator that heals on
 * first read, and unwraps in stream() so concurrent reads keep their
 * parallelism (those are captured on read, not while streaming).
 *
 * prepareRequest() is where the transport folds json, query, auth and defaults
 * into the final URL, headers and body; the hook records its result so the
 * heal payload and the retry match exactly what went on the wire.
 */
final class Symfony
{
    private static bool $installed = false;

    private static ?Healer $healer = null;

    /** @var list<array{0: string, 1: array<string, mixed>}> the last prepareRequest results, innermost last */
    private static array $prepared = [];

    /** @var list<array<int, LazyHealingResponse>> */
    private static array $streams = [];

    public static function install(Config $config, HealApi $api): bool
    {
        if (!interface_exists(HttpClientInterface::class) || !function_exists('OpenTelemetry\Instrumentation\hook')) {
            return false;
        }
        self::$healer = new Healer($config, $api);
        if (self::$installed) {
            return false;
        }
        self::$installed = true;

        // Record the normalized request each transport builds, so the wrapper
        // has the real URL, headers and body when a capture happens.
        foreach ([CurlHttpClient::class, NativeHttpClient::class] as $transport) {
            if (!class_exists($transport)) {
                continue;
            }
            hook($transport, 'prepareRequest', post: static function (mixed $client, array $params, mixed $ret): void {
                if (is_array($ret) && count($ret) === 2 && is_array($ret[0]) && is_array($ret[1])) {
                    self::$prepared[] = [implode('', $ret[0]), $ret[1]];
                }
            });
        }

        hook(
            HttpClientInterface::class,
            'request',
            post: static function (mixed $client, array $params, mixed $response, ?\Throwable $exception): mixed {
                $prepared = array_pop(self::$prepared);
                if (!$response instanceof ResponseInterface
                    || $response instanceof LazyHealingResponse
                    || $prepared === null
                    || HealApi::isInternalCall()
                    || self::$healer === null
                    || !$client instanceof HttpClientInterface
                    || $client instanceof MockHttpClient
                ) {
                    return $response;
                }
                [$url, $options] = $prepared;
                $method = is_string($params[0] ?? null) ? $params[0] : 'GET';

                return new LazyHealingResponse($response, $client, self::$healer, $method, $url, $options);
            },
        );

        hook(
            HttpClientInterface::class,
            'stream',
            pre: static function (mixed $client, array $params): ?array {
                // stream() needs the transport's own responses; unwrap ours,
                // reading concurrently as the caller intended (no per-response heal here).
                $mapping = [];
                if (!isset($params[0])) {
                    self::$streams[] = $mapping;
                    return null;
                }
                $params[0] = LazyHealingResponse::unwrap($params[0], $mapping);
                self::$streams[] = $mapping;

                return $params;
            },
            post: static function (mixed $client, array $params, mixed $stream): mixed {
                $mapping = array_pop(self::$streams) ?? [];
                if ($mapping === [] || !$stream instanceof ResponseStreamInterface) {
                    return $stream;
                }

                return new ResponseStream((static function () use ($stream, $mapping): \Generator {
                    foreach ($stream as $response => $chunk) {
                        yield $mapping[spl_object_id($response)] ?? $response => $chunk;
                    }
                })());
            },
        );

        return true;
    }
}

<?php declare(strict_types=1);

namespace Mnfst\Hooks;

use Cake\Http\Client;
use Cake\Http\Client\Request as CakeRequest;
use Cake\Http\Client\Response as CakeResponse;
use Mnfst\Config;
use Mnfst\HealApi;
use Mnfst\Healer;
use Mnfst\Retry;
use Psr\Http\Message\RequestInterface;

use function OpenTelemetry\Instrumentation\hook;

/**
 * Hook Cake\Http\Client::send — the funnel that post, get and the rest reach
 * through _doRequest, and that sendRequest (PSR-18) calls too. Cake has
 * adapters rather than middleware, so there is no stack to push onto; the hook
 * is the only global insertion point.
 *
 * The retry goes through the same client instance with the same options, so
 * the adapter, timeout, ssl and proxy settings of the original call apply.
 *
 * The post hook returns `mixed` and never null: a nullable return type plus an
 * early `return null` replaces the caller's response with null and fatals the
 * app (spec section 5, rule 2).
 */
final class Cake
{
    private static bool $installed = false;

    private static ?Healer $healer = null;

    /** @var list<float> start times of the sends in flight, innermost last */
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
            'send',
            pre: static function (): void {
                self::$started[] = microtime(true);
            },
            post: static function (mixed $client, array $params, mixed $response, ?\Throwable $exception): mixed {
                $started = array_pop(self::$started) ?? microtime(true);
                if (!$response instanceof CakeResponse || HealApi::isInternalCall() || self::$healer === null) {
                    return $response;
                }
                $request = $params[0] ?? null;
                if (!$request instanceof RequestInterface) {
                    return $response;
                }
                $options = is_array($params[1] ?? null) ? $params[1] : [];
                $sender = $client instanceof Client ? $client : new Client();

                $replayed = self::$healer->attempt(
                    $request,
                    $response,
                    $started,
                    static fn (Retry $retry): CakeResponse => $sender->send(self::request($request, $retry), $options),
                );

                return $replayed instanceof CakeResponse ? $replayed : $response;
            },
        );

        return true;
    }

    /** The original request with the healed URL, headers and body swapped in. */
    private static function request(RequestInterface $original, Retry $retry): CakeRequest
    {
        return (new CakeRequest($retry->url, $original->getMethod(), $retry->headers, $retry->body))
            ->withProtocolVersion($original->getProtocolVersion());
    }
}

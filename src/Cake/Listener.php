<?php declare(strict_types=1);

namespace Mnfst\Cake;

use Cake\Event\EventInterface;
use Cake\Event\EventManager;
use Cake\Http\Client;
use Cake\Http\Client\ClientEvent;
use Cake\Http\Client\Request as CakeRequest;
use Cake\Http\Client\Response;
use Mnfst\HealApi;
use Mnfst\Manifest;
use Mnfst\Outcome;
use Mnfst\Pipeline;
use Mnfst\Replay;
use Mnfst\Streams;
use Psr\Http\Message\RequestInterface;

/**
 * Cake\Http\Client dispatches HttpClient.beforeSend and HttpClient.afterSend
 * (CakePHP 5.1+), and every client's own event manager also runs the
 * listeners of the global one. So two global listeners cover every client in
 * the app: one notes when a call leaves, the other heals the answer and
 * replaces the result with ClientEvent::setResult().
 *
 * afterSend fires once per redirect hop; a redirect Cake is about to follow
 * is left alone, and the hop it lands on is the call.
 */
final class Listener
{
    private static bool $registered = false;

    /** @var array<int, list<float>> start times per client, innermost last */
    private static array $started = [];

    public static function register(): void
    {
        if (self::$registered || !class_exists(ClientEvent::class)) {
            return;
        }
        self::$registered = true;
        Manifest::register('cake');

        $events = EventManager::instance();
        $events->on('HttpClient.beforeSend', static function (EventInterface $event): void {
            self::$started[spl_object_id($event->getSubject())][] = microtime(true);
        });
        $events->on('HttpClient.afterSend', static function (EventInterface $event): void {
            if ($event instanceof ClientEvent) {
                self::afterSend($event);
            }
        });
    }

    private static function afterSend(ClientEvent $event): void
    {
        $client = $event->getSubject();
        $id = spl_object_id($client);
        $started = isset(self::$started[$id]) ? array_pop(self::$started[$id]) : null;
        if ((self::$started[$id] ?? null) === []) {
            unset(self::$started[$id]);
        }

        $response = $event->getResult();
        $healer = Manifest::healer();
        if ($healer === null
            || !$client instanceof Client
            || !$response instanceof Response
            || HealApi::isInternalCall()
            || $event->getData('requestSent') !== true
            || ($response->isRedirect() && (int) $event->getData('redirects') > 0)
        ) {
            return;
        }
        $request = $event->getRequest();
        $options = $event->getAdapterOptions();

        $healed = Pipeline::respond($healer, $request, $response, $started ?? microtime(true), static function (array $plan) use ($client, $request, $options): Outcome {
            $replayed = $client->send(self::retryRequest($request, $plan), $options);
            [$body, $replayed] = $replayed->getStatusCode() >= 400
                ? Streams::readResponse($replayed)
                : ['', $replayed];

            return new Outcome($replayed->getStatusCode(), $body, $replayed);
        });
        if ($healed !== $response && $healed instanceof Response) {
            $event->setResult($healed);
        }
    }

    /**
     * The original request with the healed URL, header deltas and body applied.
     *
     * @param array{url: string, headers: array<string, ?string>, body: ?string} $plan
     */
    private static function retryRequest(RequestInterface $original, array $plan): CakeRequest
    {
        return (new CakeRequest($plan['url'], $original->getMethod(), Replay::headersFor($original->getHeaders(), $plan), $plan['body']))
            ->withProtocolVersion($original->getProtocolVersion());
    }
}

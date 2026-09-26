<?php declare(strict_types=1);

namespace Mnfst\Guzzle;

use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\Utils;
use Mnfst\HealApi;
use Mnfst\Manifest;
use Mnfst\Outcome;
use Mnfst\Pipeline;
use Mnfst\Replay;
use Mnfst\Streams;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Guzzle handler middleware: push it on any HandlerStack. Laravel's Http
 * facade gets it through Factory::globalMiddleware().
 *
 * The retry goes to the next handler with the call's own options, so
 * everything below this middleware (the transport, a MockHandler, redirects,
 * cookies) applies again. http_errors is off for the retry and reapplied
 * here: a failure the caller would have seen thrown is thrown again, naming
 * the request that produced it.
 *
 * The first Manifest middleware a call meets owns it (OWNED on the handler
 * options), so a client that carries it twice heals and tracks once.
 */
final class Middleware
{
    public const OWNED = 'mnfst_owned';

    public static function create(): callable
    {
        Manifest::register('guzzle');

        return static fn (callable $handler): callable => static function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            $healer = Manifest::healer();
            if ($healer === null || HealApi::isInternalCall() || ($options[self::OWNED] ?? false) === true) {
                return $handler($request, $options);
            }
            $options[self::OWNED] = true;
            $started = microtime(true);
            $retried = null;
            $send = static function (array $plan) use ($handler, $request, $options, &$retried): Outcome {
                $retried = self::retryRequest($request, $plan);
                $retryOptions = ['http_errors' => false] + $options;
                // prepare_body would re-add a Content-Type the heal removed.
                unset($retryOptions['_conditional']);
                $replayed = $handler($retried, $retryOptions)->wait();
                [$body, $replayed] = $replayed->getStatusCode() >= 400
                    ? Streams::readResponse($replayed)
                    : ['', $replayed];

                return new Outcome($replayed->getStatusCode(), $body, $replayed);
            };

            return $handler($request, $options)->then(
                static function (ResponseInterface $response) use ($healer, $request, $options, $started, $send, &$retried): mixed {
                    $healed = Pipeline::respond($healer, $request, $response, $started, $send);
                    // This middleware sits below http_errors, so a still-failing
                    // retry would otherwise reach it as a fulfilled response and
                    // get thrown there, naming the pre-retry request. Reapply
                    // http_errors here instead, naming the request that produced it.
                    if ($retried !== null && $healed->getStatusCode() >= 400 && !empty($options['http_errors'])) {
                        return Create::rejectionFor(RequestException::create($retried, $healed));
                    }

                    return $healed;
                },
                static function (mixed $reason) use ($healer, $request, $started, $send, &$retried): mixed {
                    if (!$reason instanceof BadResponseException) {
                        return Create::rejectionFor($reason);
                    }
                    $original = $reason->getResponse();
                    $outcome = Pipeline::respond($healer, $request, $original, $started, $send);
                    if ($outcome === $original) {
                        return Create::rejectionFor($reason);
                    }
                    // The caller runs with http_errors on: a failure, retried or
                    // rebuilt, must throw like the original did, not resolve as
                    // a response the caller would take for a success.
                    if ($outcome->getStatusCode() >= 400) {
                        return Create::rejectionFor(RequestException::create($retried ?? $request, $outcome));
                    }

                    return $outcome;
                },
            );
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

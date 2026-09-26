<?php declare(strict_types=1);

namespace Mnfst;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * What every PSR-7 client does once its call has answered: record a call
 * that is not healed, or read both bodies within their limits, capture,
 * attempt the heal, and hand back the response the caller should get. The
 * adapter only knows how to send the planned retry with its own client.
 *
 * Fails open: any problem returns the original response, rebuilt when its
 * body had to be read.
 */
final class Pipeline
{
    /**
     * @param callable(array{url: string, headers: array<string, ?string>, body: ?string}): Outcome $send
     */
    public static function respond(
        Healer $healer,
        RequestInterface $request,
        ResponseInterface $response,
        float $started,
        callable $send,
    ): ResponseInterface {
        if (!$healer->willHeal($response->getStatusCode(), (string) $request->getUri())) {
            $healer->track($request->getMethod(), (string) $request->getUri(), $response->getStatusCode(), $started);

            return $response;
        }
        try {
            // A consumed non-seekable upload cannot be reconstructed safely.
            [$body, $oversized] = $request->getBody()->isSeekable()
                ? Streams::read($request->getBody(), Gate::REQUEST_BODY_LIMIT)
                : [null, true];
            [$responseBody, $response] = Streams::readResponse($response);
            $outcome = $healer->attempt(new Capture(
                $request->getMethod(),
                (string) $request->getUri(),
                $request->getHeaders(),
                $body,
                $oversized,
                $response->getStatusCode(),
                $responseBody,
                $started,
            ), $send);

            return $outcome?->response instanceof ResponseInterface ? $outcome->response : $response;
        } catch (\Throwable) {
            return $response;
        }
    }
}

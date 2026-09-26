<?php declare(strict_types=1);

namespace Mnfst\Symfony;

use Mnfst\HealApi;
use Mnfst\Manifest;
use Mnfst\Response\LazyHealingResponse;
use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\ResponseStream;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Decorates a Symfony HttpClient. Responses are lazy, so each one is wrapped
 * in a LazyHealingResponse that heals on first read; stream() unwraps them so
 * concurrent reads keep their parallelism (those are not healed).
 *
 * The decorator sees the request before the transport applies its own
 * default options. The URL is taken from the response, where the transport
 * already resolved base_uri; headers set only as transport defaults are not
 * in the capture, and the retry, sent through the same transport, gets them
 * again.
 */
final class HealingHttpClient implements HttpClientInterface, ResetInterface
{
    use DecoratorTrait;

    public function __construct(?HttpClientInterface $client = null)
    {
        $this->client = $client ?? \Symfony\Component\HttpClient\HttpClient::create();
        Manifest::register('symfony');
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $response = $this->client->request($method, $url, $options);
        $healer = Manifest::healer();
        if ($healer === null || HealApi::isInternalCall() || $this->client instanceof MockHttpClient) {
            return $response;
        }
        try {
            $target = $response->getInfo('url');
            // The resolved URL already carries the query and the base URI.
            [$target, $prepared] = Prepared::request(
                $method,
                is_string($target) && $target !== '' ? $target : $url,
                array_diff_key($options, ['query' => true, 'base_uri' => true]),
            );
        } catch (\Throwable) {
            return $response;
        }

        return new LazyHealingResponse($response, $this->client, $healer, $method, $target, $prepared);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        $mapping = [];
        $stream = $this->client->stream(LazyHealingResponse::unwrap($responses, $mapping), $timeout);
        if ($mapping === []) {
            return $stream;
        }

        return new ResponseStream((static function () use ($stream, $mapping): \Generator {
            foreach ($stream as $response => $chunk) {
                yield $mapping[spl_object_id($response)] ?? $response => $chunk;
            }
        })());
    }
}

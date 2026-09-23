<?php declare(strict_types=1);

namespace Mnfst\Response;

use Mnfst\Bodies;
use Mnfst\Capture;
use Mnfst\Gate;
use Mnfst\HealApi;
use Mnfst\Healer;
use Mnfst\Outcome;
use Mnfst\Replay;
use Mnfst\Wire;
use Symfony\Component\HttpClient\Response\StreamableInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * A Symfony HttpClient response that heals on first read. Symfony responses are
 * lazy, so the wrapper does nothing until the app asks for the status, headers
 * or body; then it lets the request finish, and on a capturable 4xx runs one
 * heal and, on success, delegates to the retry's response from there on.
 *
 * Every method the contract defines is delegated; the object the app holds
 * behaves exactly like the response it wraps, healed or not.
 */
final class LazyHealingResponse implements ResponseInterface, StreamableInterface
{
    private ResponseInterface $current;

    private bool $healed = false;

    private float $started;

    /**
     * @param array<string, mixed> $options the normalized options from prepareRequest (headers, body)
     */
    public function __construct(
        private readonly ResponseInterface $inner,
        private readonly HttpClientInterface $client,
        private readonly Healer $healer,
        private readonly string $method,
        private readonly string $url,
        private readonly array $options,
    ) {
        $this->current = $inner;
        $this->started = microtime(true);
    }

    /**
     * Replace any wrapped responses with the transport's own, for stream().
     * Reads nothing, so concurrent streaming keeps its parallelism.
     *
     * @param \Symfony\Contracts\HttpClient\ResponseInterface|iterable<mixed, \Symfony\Contracts\HttpClient\ResponseInterface> $responses
     * @return \Symfony\Contracts\HttpClient\ResponseInterface|iterable<mixed, \Symfony\Contracts\HttpClient\ResponseInterface>
     */
    public static function unwrap(mixed $responses, array &$mapping): mixed
    {
        if ($responses instanceof self) {
            // Once handed to stream(), leave the body and concurrency to the caller.
            $responses->healed = true;
            $mapping[spl_object_id($responses->current)] = $responses;

            return $responses->current;
        }
        if (is_iterable($responses)) {
            $out = [];
            foreach ($responses as $key => $response) {
                $out[$key] = self::unwrap($response, $mapping);
            }

            return $out;
        }

        return $responses;
    }

    public function getStatusCode(): int
    {
        return $this->settle()->getStatusCode();
    }

    public function getHeaders(bool $throw = true): array
    {
        return $this->settle()->getHeaders($throw);
    }

    public function getContent(bool $throw = true): string
    {
        return $this->settle()->getContent($throw);
    }

    public function toArray(bool $throw = true): array
    {
        return $this->settle()->toArray($throw);
    }

    public function cancel(): void
    {
        $this->healed = true;
        $this->current->cancel();
    }

    public function getInfo(?string $type = null): mixed
    {
        return $this->current->getInfo($type);
    }

    public function toStream(bool $throw = true)
    {
        $response = $this->settle();
        if (!$response instanceof StreamableInterface) {
            throw new \RuntimeException('The transport does not expose a response stream');
        }

        return $response->toStream($throw);
    }

    /**
     * Let the request finish, heal once on a capturable 4xx, and return the
     * response to delegate to from now on. Fails open: any problem keeps the
     * original response.
     */
    private function settle(): ResponseInterface
    {
        if ($this->healed) {
            return $this->current;
        }
        $this->healed = true;
        try {
            $status = $this->inner->getStatusCode();
            if (!$this->healer->willHeal($status) || ($this->options['buffer'] ?? true) === false) {
                $this->healer->track($this->method, $this->url, $status, $this->started);

                return $this->current;
            }
            $capture = new Capture(
                $this->method,
                $this->url,
                $this->headers(),
                $this->requestBody(),
                $this->oversized(),
                $status,
                self::readBody($this->inner),
                $this->started,
            );
            $outcome = $this->healer->attempt($capture, $this->send(...));
            if ($outcome?->response instanceof ResponseInterface) {
                $this->current = $outcome->response;
            }
        } catch (\Throwable) {
            // fail open: keep the original response
        }

        return $this->current;
    }

    /**
     * Re-send the healed request through the same client and read its outcome.
     *
     * @param array{url: string, headers: array<string, ?string>, body: ?string} $plan
     */
    private function send(array $plan): Outcome
    {
        return HealApi::withInternalCall(function () use ($plan): Outcome {
            $original = $this->headers();
            $headers = Replay::headersFor($original, $plan);
            // An empty list explicitly suppresses a header inherited from defaults.
            foreach ($original as $name => $_) {
                $headers[strtolower($name)] ??= [];
            }
            $options = $this->options;
            unset($options['normalized_headers']);
            $options['json'] = null;
            $options['headers'] = $headers;
            $options['query'] = [];
            $options['body'] = $plan['body'] ?? '';
            $options['auth_basic'] = null;
            $options['auth_bearer'] = null;
            $response = $this->client->request($this->method, $plan['url'], $options);
            $status = $response->getStatusCode();

            return new Outcome($status, $status >= 400 ? self::readBody($response) : '', $response);
        });
    }

    /** Read a prefix from Symfony's buffered stream, without materializing its full body. */
    private static function readBody(ResponseInterface $response): string
    {
        if (!$response instanceof StreamableInterface) {
            throw new \RuntimeException('Cannot capture this response without consuming it');
        }
        $stream = $response->toStream(false);
        if (!stream_get_meta_data($stream)['seekable']) {
            throw new \RuntimeException('Unbuffered responses are left to the caller');
        }
        $position = ftell($stream);
        try {
            rewind($stream);
            $body = stream_get_contents($stream, Wire::RESPONSE_BODY_CAP + 1);

            return is_string($body) ? $body : '';
        } finally {
            fseek($stream, $position === false ? 0 : $position);
        }
    }

    /**
     * The normalized request headers as `name => [values]`. prepareRequest
     * stores them as `lowercase => ["Name: value", …]`.
     *
     * @return array<string, list<string>>
     */
    private function headers(): array
    {
        $out = [];
        foreach ($this->options['normalized_headers'] ?? [] as $lines) {
            foreach ((array) $lines as $line) {
                if (!is_string($line) || !str_contains($line, ':')) {
                    continue;
                }
                [$name, $value] = explode(':', $line, 2);
                $out[trim($name)][] = trim($value);
            }
        }

        return $out;
    }

    /** The request body when it is a plain string we can resend; null otherwise. */
    private function requestBody(): ?string
    {
        $body = $this->options['body'] ?? null;

        return is_string($body) && $body !== '' ? $body : null;
    }

    /** A non-string body (a stream or generator) cannot be replayed: report absent, never retry. */
    private function oversized(): bool
    {
        $body = $this->options['body'] ?? null;

        return $body !== null && $body !== '' && !is_string($body);
    }
}

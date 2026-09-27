<?php declare(strict_types=1);

namespace Mnfst;

use Mnfst\HttpRedact\Redactor;

/**
 * What of a failing request leaves the machine, masked by mnfst/http-redact.
 *
 * Credential values are replaced in place by REDACTED; names, structure and
 * everything else travel so the server has the whole context. `masks` lists
 * each replaced value (`in` + `at`: a header name, a query name, a 1-based path
 * segment index, or a JSON Pointer into the body) so Replay can put the
 * originals back before a retry. What is masked and what is not:
 * https://github.com/mnfst/http-redact#threat-model
 */
final class Masked
{
    public const MASK = 'REDACTED';

    /** Never sent at all: cookies carry sessions, and fixing an API call does not need them. */
    private const DROPPED_HEADERS = ['cookie', 'set-cookie'];

    /**
     * @param array<string, string> $headers lowercased, masked, capped
     * @param list<array{in: string, at: string}> $masks
     */
    private function __construct(
        public readonly string $url,
        public readonly array $headers,
        public readonly mixed $body,
        public readonly array $masks,
    ) {
    }

    /** @param mixed $body the parsed request body (Bodies::parseRequestBody), or null */
    public static function request(string $method, string $url, iterable $headers, mixed $body): self
    {
        $flat = [];
        foreach ($headers as $name => $value) {
            if (in_array(strtolower((string) $name), self::DROPPED_HEADERS, true)) {
                continue;
            }
            $flat[(string) $name] = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
        }
        $encoded = $body === null ? null : Json::encode($body);
        $out = Redactor::redact([
            'method' => $method,
            'url' => self::withoutUserinfo($url),
            'headers' => $flat,
            'body' => $encoded,
            'contentType' => $encoded === null ? null : 'application/json',
        ]);

        $sentHeaders = [];
        foreach ($out['request']['headers'] as $name => $value) {
            $sentHeaders[strtolower((string) $name)] = substr((string) $value, 0, Wire::HEADER_VALUE_CAP);
        }
        $masks = array_map(
            static fn (array $m): array => $m['in'] === 'header' ? ['in' => 'header', 'at' => strtolower($m['at'])] : $m,
            $out['masked'],
        );

        return new self($out['request']['url'], $sentHeaders, self::decoded($encoded, $out['request']['body']), $masks);
    }

    /** A response body (a string, or JSON already decoded), masked. Responses are never retried. */
    public static function response(mixed $body): mixed
    {
        if ($body === null) {
            return null;
        }
        $encoded = is_string($body) ? $body : Json::encode($body);
        if ($encoded === null) {
            return null;   // cannot be scanned, so it does not travel
        }
        $masked = Redactor::redact(['method' => 'GET', 'url' => '/', 'response' => $encoded])['request']['response'];

        return is_string($body) ? $masked : self::decoded($encoded, $masked);
    }

    /** A URL for a message or a callback: masked, without userinfo. */
    public static function url(string $url): string
    {
        return Redactor::redactUrl(self::withoutUserinfo($url));
    }

    /** @return list<string> the `at` of every mask in `$in` */
    public function at(string $in): array
    {
        $out = [];
        foreach ($this->masks as $m) {
            if ($m['in'] === $in) {
                $out[] = $m['at'];
            }
        }

        return $out;
    }

    /** user:password@host never travels, not even masked: a retry refuses a URL that carries it. */
    private static function withoutUserinfo(string $url): string
    {
        // Up to the LAST @ before the path: a password may itself contain @.
        return preg_replace('~^((?:[A-Za-z][A-Za-z0-9+.\-]*:)?//)[^/?#]*@~', '$1', $url) ?? $url;
    }

    /** The redacted body in the shape it arrived in: unchanged bytes give the original value back. */
    private static function decoded(?string $encoded, ?string $redacted): mixed
    {
        if ($encoded === null || $redacted === null) {
            return null;
        }
        if ($redacted === self::MASK) {
            return self::MASK;   // masked whole: its structure could not be proven safe
        }
        try {
            return Json::decode($redacted);
        } catch (\JsonException) {
            return self::MASK;
        }
    }
}

<?php declare(strict_types=1);

namespace Mnfst\Symfony;

use Symfony\Component\HttpClient\HttpClientTrait;

/**
 * Symfony's own request normalization (json, query, auth_bearer folded into
 * the URL, headers and body), so the capture and the retry see the request
 * as the transport will send it.
 *
 * @internal
 */
final class Prepared
{
    use HttpClientTrait;

    /**
     * @param array<string, mixed> $options
     * @return array{0: string, 1: array<string, mixed>} the URL and the normalized options
     */
    public static function request(string $method, string $url, array $options): array
    {
        [$parts, $options] = self::prepareRequest($method, $url, $options, [], true);

        return [implode('', $parts), $options];
    }
}

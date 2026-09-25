<?php declare(strict_types=1);

namespace Mnfst\WordPress;

use Mnfst\Bodies;
use Mnfst\Capture;
use Mnfst\HealApi;
use Mnfst\Manifest;
use Mnfst\Outcome;
use Mnfst\Replay;
use Mnfst\Wire;

/**
 * WordPress HTTP (wp_remote_*, WP_Http) through two core filters:
 * pre_http_request notes when a call leaves, and http_response heals the
 * response array and returns the retry's. The retry is a wp_remote_request()
 * with the same arguments, so WordPress's own transport settings apply.
 *
 * GET and HEAD send array data as the query string (WP_Http's data_format),
 * so the capture carries it in the URL, as it went on the wire.
 */
final class Filters
{
    private static bool $registered = false;

    /** @var array<string, list<float>> start times by URL, innermost last */
    private static array $started = [];

    public static function register(): void
    {
        if (self::$registered || !function_exists('add_filter')) {
            return;
        }
        self::$registered = true;
        Manifest::register('wordpress');
        // Last on the way out, so a call another filter answered is not timed.
        \add_filter('pre_http_request', [self::class, 'before'], PHP_INT_MAX, 3);
        // First on the way back, so other filters see the healed response.
        \add_filter('http_response', [self::class, 'after'], PHP_INT_MIN, 3);
    }

    public static function before(mixed $preempt, mixed $args = null, mixed $url = null): mixed
    {
        if ($preempt === false && is_string($url)) {
            self::$started[$url][] = microtime(true);
        }

        return $preempt;
    }

    public static function after(mixed $response, mixed $args = null, mixed $url = null): mixed
    {
        $started = is_string($url) && isset(self::$started[$url]) ? array_pop(self::$started[$url]) : null;
        $healer = Manifest::healer();
        if ($healer === null || HealApi::isInternalCall() || !is_array($response) || !is_array($args) || !is_string($url)) {
            return $response;
        }
        try {
            $status = (int) ($response['response']['code'] ?? 0);
            $method = strtoupper(is_string($args['method'] ?? null) ? $args['method'] : 'GET');
            $headers = self::headers($args['headers'] ?? []);
            $data = $args['body'] ?? null;
            $target = $url;
            if (in_array($method, ['GET', 'HEAD'], true) && is_array($data) && $data !== []) {
                $target .= (str_contains($target, '?') ? '&' : '?') . http_build_query($data, '', '&');
                $data = null;
            }
            $started ??= microtime(true);
            if (!$healer->willHeal($status)) {
                $healer->track($method, $target, $status, $started);

                return $response;
            }
            [$body, $contentType] = self::body($data, $headers);
            if ($contentType !== '' && Bodies::contentTypeOf($headers) === '') {
                $headers['Content-Type'] = [$contentType];
            }
            $capture = new Capture(
                $method,
                $target,
                $headers,
                $body,
                false,
                $status,
                substr((string) ($response['body'] ?? ''), 0, Wire::RESPONSE_BODY_CAP + 1),
                $started,
            );
            $outcome = $healer->attempt($capture, static function (array $plan) use ($args, $headers, $method): Outcome {
                $flat = [];
                foreach (Replay::headersFor($headers, $plan) as $name => $values) {
                    $flat[$name] = implode(', ', $values);
                }
                $retry = \wp_remote_request($plan['url'], ['method' => $method, 'headers' => $flat, 'body' => $plan['body']] + $args);
                if (!is_array($retry)) {
                    throw new \RuntimeException(is_object($retry) && method_exists($retry, 'get_error_message')
                        ? (string) $retry->get_error_message()
                        : 'the retry failed');
                }

                return new Outcome(
                    (int) ($retry['response']['code'] ?? 0),
                    substr((string) ($retry['body'] ?? ''), 0, Wire::RESPONSE_BODY_CAP + 1),
                    $retry,
                );
            });

            return is_array($outcome?->response) ? $outcome->response : $response;
        } catch (\Throwable) {
            return $response;
        }
    }

    /**
     * WordPress takes headers as `name => value` or as one raw string;
     * normalise to the `name => [values]` the wire code expects.
     *
     * @return array<string, list<string>>
     */
    private static function headers(mixed $headers): array
    {
        if (is_string($headers)) {
            $parsed = [];
            foreach (preg_split('/\r?\n/', $headers) ?: [] as $line) {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $parsed[trim($name)][] = trim($value);
                }
            }

            return $parsed;
        }
        if (!is_array($headers)) {
            return [];
        }
        $out = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name)) {
                continue;
            }
            foreach ((array) $value as $one) {
                $out[$name][] = (string) $one;
            }
        }

        return $out;
    }

    /**
     * The body as a string plus its content type. A string is sent as is, an
     * array is a form: encoded so the heal sees, and the retry resends, the
     * same bytes.
     *
     * @param array<string, list<string>> $headers
     * @return array{0: string|null, 1: string}
     */
    private static function body(mixed $data, array $headers): array
    {
        $contentType = '';
        foreach ($headers as $name => $values) {
            if (strtolower($name) === 'content-type') {
                $contentType = strtolower(trim(explode(';', $values[0] ?? '')[0]));
                break;
            }
        }
        if (is_string($data)) {
            return [$data === '' ? null : $data, $contentType];
        }
        if (is_array($data) && $data !== []) {
            return [http_build_query($data, '', '&'), Bodies::FORM];
        }

        return [null, $contentType];
    }
}

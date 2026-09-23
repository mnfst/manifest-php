<?php declare(strict_types=1);

namespace Mnfst\Hooks;

use Mnfst\Bodies;
use Mnfst\Capture;
use Mnfst\Config;
use Mnfst\Gate;
use Mnfst\HealApi;
use Mnfst\Healer;
use Mnfst\Outcome;
use Mnfst\Replay;
use Mnfst\Wire;
use WpOrg\Requests\Requests;
use WpOrg\Requests\Response;

use function OpenTelemetry\Instrumentation\hook;

/**
 * WordPress HTTP. `WP_Http` and `wp_remote_get`/`wp_remote_post` run on
 * `WpOrg\Requests\Requests` (the rmccue/requests library), whose curl
 * transport would otherwise be seen by the capture-only curl hook and never
 * healed. Requests::request is the funnel every call passes through and
 * returns a complete response, so the hook heals right there.
 */
final class WordPress
{
    private static bool $installed = false;

    private static ?Healer $healer = null;

    /** @var list<float> */
    private static array $started = [];

    public static function install(Config $config, HealApi $api): bool
    {
        if (!class_exists(Requests::class) || !function_exists('OpenTelemetry\Instrumentation\hook')) {
            return false;
        }
        self::$healer = new Healer($config, $api);
        if (self::$installed) {
            return false;
        }
        self::$installed = true;

        hook(
            Requests::class,
            'request',
            pre: static function (): void {
                self::$started[] = microtime(true);
            },
            post: static function (mixed $class, array $params, mixed $response, ?\Throwable $exception): mixed {
                $started = array_pop(self::$started) ?? microtime(true);
                if (!$response instanceof Response || HealApi::isInternalCall() || self::$healer === null) {
                    return $response;
                }
                $status = is_int($response->status_code) ? $response->status_code : 0;
                $url = is_string($params[0] ?? null) ? $params[0] : '';
                // The nested request at the redirect target already handled this response.
                if ($response->redirects > 0 && $response->url !== $url) {
                    return $response;
                }
                if (!self::$healer->willHeal($status)) {
                    $method = is_string($params[3] ?? null) ? $params[3] : Requests::GET;
                    self::$healer->track($method, $url, $status, $started);

                    return $response;
                }
                try {
                    $headers = self::headers($params[1] ?? []);
                    $method = is_string($params[3] ?? null) ? $params[3] : Requests::GET;
                    $options = is_array($params[4] ?? null) ? $params[4] : [];
                    $data = $params[2] ?? null;
                    $format = $options['data_format'] ?? (in_array(strtoupper($method), ['GET', 'HEAD', 'DELETE'], true) ? 'query' : 'body');
                    if ($format === 'query' && is_array($data) && $data !== []) {
                        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($data, '', '&');
                        $data = null;
                    }
                    [$body, $contentType] = self::body($data, $headers);
                    if ($contentType !== '' && Bodies::contentTypeOf($headers) === '') {
                        $headers['Content-Type'] = [$contentType];
                    }

                    $capture = new Capture(
                        $method,
                        $url,
                        $headers,
                        $body,
                        false,
                        $status,
                        substr((string) $response->body, 0, Wire::RESPONSE_BODY_CAP + 1),
                        $started,
                    );
                    $outcome = self::$healer->attempt($capture, static function (array $plan) use ($headers, $method, $options): Outcome {
                        $flat = [];
                        foreach (Replay::headersFor($headers, $plan) as $name => $values) {
                            $flat[$name] = implode(', ', $values);
                        }
                        $options['data_format'] = 'body'; // the planned URL already contains the query
                        $replayed = HealApi::withInternalCall(
                            static fn (): Response => Requests::request($plan['url'], $flat, $plan['body'] ?? '', $method, $options),
                        );

                        return new Outcome(
                            is_int($replayed->status_code) ? $replayed->status_code : 0,
                            substr((string) $replayed->body, 0, Wire::RESPONSE_BODY_CAP + 1),
                            $replayed,
                        );
                    });

                    return $outcome?->response instanceof Response ? $outcome->response : $response;
                } catch (\Throwable) {
                    return $response;
                }
            },
        );

        return true;
    }

    /**
     * Requests headers are `name => value` (a value may be a list); normalise to
     * the `name => [values]` the wire code expects.
     *
     * @param mixed $headers
     * @return array<string, list<string>>
     */
    private static function headers(mixed $headers): array
    {
        if (!is_array($headers) && !$headers instanceof \Traversable) {
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
     * The body as a string plus its content type. Requests takes `$data` before
     * encoding: a string is sent as is, an array is a form. A form array is
     * encoded so the heal sees, and the retry resends, the same bytes.
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
            $encoded = http_build_query($data, '', '&');

            return [$encoded, Bodies::FORM];
        }

        return [null, $contentType];
    }
}

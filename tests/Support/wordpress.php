<?php declare(strict_types=1);

/*
 * The slice of WordPress the adapter talks to, running on the real
 * rmccue/requests library the way WP_Http does: pre_http_request can
 * short-circuit, GET and HEAD send array data as the query string, the call
 * goes through Requests, and http_response filters the response array.
 * Loaded only by tests that run in their own process.
 */

if (!function_exists('add_filter')) {
    $GLOBALS['mnfst_wp_filters'] = [];

    final class WP_Error
    {
        public function __construct(public string $code = '', public string $message = '')
        {
        }

        public function get_error_message(): string
        {
            return $this->message;
        }
    }

    function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        $GLOBALS['mnfst_wp_filters'][$hook][$priority][] = [$callback, $acceptedArgs];

        return true;
    }

    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        $byPriority = $GLOBALS['mnfst_wp_filters'][$hook] ?? [];
        ksort($byPriority);
        foreach ($byPriority as $callbacks) {
            foreach ($callbacks as [$callback, $acceptedArgs]) {
                $value = $callback(...array_slice([$value, ...$args], 0, $acceptedArgs));
            }
        }

        return $value;
    }

    function wp_remote_request(string $url, array $args = []): array|WP_Error
    {
        $args += ['method' => 'GET', 'headers' => [], 'body' => null];
        $pre = apply_filters('pre_http_request', false, $args, $url);
        if ($pre !== false) {
            return $pre;
        }
        $method = strtoupper((string) $args['method']);
        $options = in_array($method, ['GET', 'HEAD'], true) ? ['data_format' => 'query'] : ['data_format' => 'body'];
        try {
            $r = \WpOrg\Requests\Requests::request($url, (array) $args['headers'], $args['body'] ?? [], $method, $options);
        } catch (\WpOrg\Requests\Exception $e) {
            return new WP_Error('http_request_failed', $e->getMessage());
        }
        $response = [
            'headers' => $r->headers->getAll(),
            'body' => $r->body,
            'response' => ['code' => $r->status_code, 'message' => ''],
            'cookies' => [],
            'http_response' => $r,
        ];

        return apply_filters('http_response', $response, $args, $url);
    }

    function wp_remote_get(string $url, array $args = []): array|WP_Error
    {
        return wp_remote_request($url, ['method' => 'GET'] + $args);
    }

    function wp_remote_post(string $url, array $args = []): array|WP_Error
    {
        return wp_remote_request($url, ['method' => 'POST'] + $args);
    }
}

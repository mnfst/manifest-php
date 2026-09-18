<?php declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');

if ($path === '/__ready') {
    echo json_encode(['ready' => true, 'token' => getenv('MNFST_STUB_TOKEN')]);

    return true;
}

if ($path === '/unauthorized') {
    http_response_code(401);
    echo json_encode(['error' => 'no key']);

    return true;
}

if ($path === '/ping') {
    echo json_encode(['pong' => true]);

    return true;
}

// Rejects a duplicated `page` query param the way TMDB does, else echoes the
// request so a test can see exactly what was replayed: method, raw query,
// headers and body.
if ($path === '/search') {
    $query = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
    if (substr_count($query, 'page=') > 1) {
        http_response_code(400);
        echo json_encode(['error' => 'duplicate page parameter']);

        return true;
    }
    $headers = [];
    foreach ($_SERVER as $name => $value) {
        if (str_starts_with($name, 'HTTP_')) {
            $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = $value;
        }
    }
    echo json_encode([
        'method' => $_SERVER['REQUEST_METHOD'],
        'query' => $query,
        'headers' => $headers,
        'body' => file_get_contents('php://input'),
    ]);

    return true;
}

if ($path === '/slow') {
    usleep(30000);
    http_response_code(400);
    echo json_encode(['error' => 'slow and wrong']);

    return true;
}

$raw = file_get_contents('php://input');
$in = json_decode($raw, true);
if (!is_array($in)) {
    parse_str($raw, $in);
}

if ((int) ($in['limit'] ?? 0) > 100) {
    http_response_code(400);
    echo json_encode(['error' => 'limit must be at most 100, too big']);

    return true;
}

echo json_encode(['ok' => true, 'got' => $in]);

return true;

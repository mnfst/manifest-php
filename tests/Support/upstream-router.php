<?php declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');

if ($path === '/__ready') {
    echo json_encode(['ready' => true]);

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

$raw = file_get_contents('php://input');
$in = json_decode($raw, true);
if (!is_array($in)) {
    parse_str($raw, $in);
}
$headers = array_change_key_case(getallheaders(), CASE_LOWER);

// The limit may arrive in the body, the query string or the X-Limit header,
// so a heal can move it to any of the three places an operation can address.
$limit = $in['limit'] ?? $_GET['limit'] ?? $headers['x-limit'] ?? 0;
if ((int) $limit > 100) {
    http_response_code(400);
    echo json_encode(['error' => 'limit must be at most 100, too big']);

    return true;
}

echo json_encode([
    'ok' => true,
    'got' => $in,
    'method' => $_SERVER['REQUEST_METHOD'],
    'headers' => $headers,
    'rawLength' => strlen($raw),
]);

return true;

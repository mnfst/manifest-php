<?php declare(strict_types=1);

$state = getenv('MNFST_STUB_STATE');
$read = static fn (): array => json_decode((string) @file_get_contents($state . '.state'), true) ?: [];
$append = static function (string $log, array $entry) use ($state): void {
    file_put_contents($state . '.' . $log, json_encode($entry) . "\n", FILE_APPEND);
};

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
$current = $read();
header('Content-Type: application/json');

if ($path === '/__ready') {
    echo json_encode(['ready' => true]);

    return true;
}

if ($current['rejectKey'] ?? false) {
    http_response_code(401);
    echo json_encode(['message' => 'missing project key', 'error' => 'Unauthorized', 'statusCode' => 401]);

    return true;
}

if ($current['disabled'] ?? false) {
    http_response_code(403);
    echo json_encode(['error' => 'project_disabled']);

    return true;
}

if ($path === '/v1/hello') {
    $append('hellos', is_array($body) ? $body : []);
    echo json_encode(['project' => ['name' => 'Stub'], 'requests' => 0]);

    return true;
}

if ($path === '/v1/heal') {
    $append('heals', is_array($body) ? $body : []);
    echo json_encode($current['result'] ?? ['status' => 'no_patch', 'issueId' => 'stub-issue']);

    return true;
}

if (str_starts_with((string) $path, '/v1/heal-attempts/')) {
    $append('outcomes', [basename((string) $path), is_array($body) ? $body : []]);
    http_response_code(204);

    return true;
}

http_response_code(404);
echo json_encode(['error' => 'not found']);

return true;

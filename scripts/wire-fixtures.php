#!/usr/bin/env php
<?php declare(strict_types=1);

/**
 * Print the bytes this SDK puts on the wire, one JSON document per line, so
 * the server can pin its request schemas against them. Needs no extension:
 * only the pure payload builders run. Run from the package root:
 *
 *   php scripts/wire-fixtures.php > fixtures.jsonl
 *
 * The app's contract test (colibri: backend/src/v1/sdk-wire.spec.ts) parses
 * each line with the zod schema named by `schema`.
 */

spl_autoload_register(static function (string $class): void {
    $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen('Mnfst\\'))) . '.php';
    if (str_starts_with($class, 'Mnfst\\') && is_file($file)) {
        require $file;
    }
});

use Mnfst\Bodies;
use Mnfst\HealApi;
use Mnfst\Wire;

$fixtures = [
    // a raw-curl capture: no headers are known, and the body is a JSON object
    ['schema' => 'capture', 'name' => 'curl capture without headers', 'body' => Wire::healPayload(
        'trace-1', 'post', 'https://api.example.com/orders?api_key=sk_live_1&page=2', [],
        Bodies::parseRequestBody('{"limit":500,"api_key":"sk_live_1"}', Bodies::JSON)[0],
        400, ['error' => 'limit must be at most 100'], false, 25,
    )],
    ['schema' => 'capture', 'name' => 'empty object body', 'body' => Wire::healPayload(
        'trace-2', 'POST', 'https://api.example.com/orders', ['Content-Type' => 'application/json', 'Authorization' => 'Bearer t'],
        Bodies::parseRequestBody('{}', Bodies::JSON)[0], 422, 'not json', true, 0,
    )],
    ['schema' => 'capture', 'name' => 'form body with repeated keys', 'body' => Wire::healPayload(
        'trace-3', 'POST', 'https://api.example.com/orders', ['content-type' => [Bodies::FORM]],
        Bodies::parseRequestBody('tag=a&tag=b&first.name=x', Bodies::FORM)[0], 400, null, false, 3,
    )],
    ['schema' => 'capture', 'name' => 'bodyless GET', 'body' => Wire::healPayload(
        'trace-4', 'GET', 'https://api.example.com/orders?limit=500', ['Accept' => 'application/json'],
        Bodies::parseRequestBody(null, '')[0], 404, ['error' => 'no such order'], false, 12,
    )],
    ['schema' => 'outcome', 'name' => 'clean retry', 'body' => ['response' => ['statusCode' => 200]]],
    ['schema' => 'outcome', 'name' => 'failed retry with a truncated non-JSON body', 'body' => [
        'response' => ['statusCode' => 400, 'body' => '<html>', 'truncated' => true],
    ]],
    ['schema' => 'outcome', 'name' => 'transport failure', 'body' => [
        'failure' => ['kind' => 'transport_error', 'message' => HealApi::safeMessage('cURL error 7 for https://api.example.com/x?token=abc')],
    ]],
    ['schema' => 'outcome', 'name' => 'unattempted replay', 'body' => [
        'failure' => ['kind' => 'not_attempted', 'message' => HealApi::NOT_ATTEMPTED],
    ]],
    ['schema' => 'hello', 'name' => 'install handshake', 'body' => ['runtime' => 'php-' . PHP_VERSION]],
    ['schema' => 'hello', 'name' => 'doctor probe', 'body' => ['runtime' => 'php-' . PHP_VERSION, 'probe' => true]],
];

foreach ($fixtures as $fixture) {
    echo json_encode($fixture, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
}

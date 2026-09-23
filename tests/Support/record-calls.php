<?php declare(strict_types=1);

// Child process for TrackingTest: records calls into the spool, then exits,
// so the test can check concurrent appends and the send at shutdown.
// argv: base URL, number of calls, mode (informational; the parent controls
// whether the shutdown send is due through the sent marker).
require __DIR__ . '/../../vendor/autoload.php';

use Mnfst\Config;
use Mnfst\HealApi;
use Mnfst\Tracking;

[, $url, $count, $mode] = $argv;
$config = Config::resolve('k', $url);
$tracking = new Tracking($config, new HealApi($config));
for ($i = 0; $i < (int) $count; $i++) {
    $tracking->record('get', 'https://api.example.com/items/' . $i . '?token=secret', 200, microtime(true));
}

<?php declare(strict_types=1);

namespace Mnfst;

/**
 * Tracked calls: one metadata record per call the SDK sees and does not send
 * to /v1/heal, whatever its status, shipped to POST /v1/requests in batches.
 *
 * PHP keeps nothing between web requests and has no background thread, so an
 * in-memory buffer would mean one POST per web request. Calls go instead to a
 * spool file in the temp directory, shared by every PHP worker on the server
 * (the same pattern as the handshake and backoff markers):
 *
 *  - record() appends one JSON line under an exclusive lock. No network, no
 *    response body, never throws. Past SPOOL_CAP_BYTES, calls are dropped.
 *  - At shutdown, run last, one process at a time claims the spool with an
 *    atomic rename() and sends it: when it holds about BATCH calls or the last
 *    send is MAX_WAIT_SECONDS old, and never within MIN_GAP_SECONDS of the
 *    last send. Under php-fpm it finishes the user's response first.
 *
 * See CONTRACT.md, "Tracked requests".
 */
final class Tracking
{
    public const BATCH = 500;
    public const SPOOL_CAP_BYTES = 2_097_152;
    /** About BATCH calls of typical size: past this the spool is sent without waiting. */
    public const READY_BYTES = 125_000;
    public const MIN_GAP_SECONDS = 1;
    public const MAX_WAIT_SECONDS = 5;
    public const BUDGET_SECONDS = 2.0;
    /** On Lambda the send runs inside the invocation (see flushIfDue), so it stays short. */
    public const LAMBDA_BUDGET_SECONDS = 0.5;
    /** A claimed spool this old belongs to a process that died mid-send. */
    public const STALE_CLAIM_SECONDS = 60;
    private const MAX_METHOD = 16;
    private const MAX_URL = 4096;

    private static bool $registered = false;

    public function __construct(
        private readonly Config $config,
        private readonly HealApi $api,
    ) {
    }

    public function spoolPath(): string
    {
        return sys_get_temp_dir() . '/mnfst-requests-' . $this->fingerprint() . '.jsonl';
    }

    public function sentPath(): string
    {
        return sys_get_temp_dir() . '/mnfst-requests-sent-' . $this->fingerprint();
    }

    /**
     * Record one call that is not being healed. A locked local append and
     * nothing else: it never reads a response, never reaches the network and
     * never throws into the caller.
     */
    public function record(string $method, string $url, int $status, float $started): void
    {
        try {
            if ($this->config->apiKey === null || HealApi::isInternalCall()) {
                return;
            }
            $call = self::call($method, $url, $status, $started);
            if ($call === null) {
                return;
            }
            $this->append(json_encode($call, Json::ENCODE_FLAGS | JSON_THROW_ON_ERROR) . "\n");
            if (!self::$registered) {
                self::$registered = true;
                register_shutdown_function(function (): void {
                    // Registered from inside a shutdown function, the sender runs
                    // after every shutdown function the app registered.
                    register_shutdown_function(fn () => $this->flushIfDue());
                });
            }
        } catch (\Throwable) {
            // Tracking must never affect the caller.
        }
    }

    /**
     * The wire record for a call, or null when the server would refuse it
     * (it rejects a whole batch over one out-of-range record).
     *
     * @return array{traceId: string, method: string, url: string, statusCode: int, responseTimeMs: int, occurredAt: string}|null
     */
    public static function call(string $method, string $url, int $status, float $started): ?array
    {
        $verb = strtoupper($method);
        $reported = Wire::trackedUrl($url);
        if ($reported === null || strlen($reported) > self::MAX_URL
            || $verb === '' || strlen($verb) > self::MAX_METHOD || $status < 100 || $status > 599) {
            return null;
        }
        $at = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $started));

        return [
            'traceId' => bin2hex(random_bytes(16)),
            'method' => $verb,
            'url' => $reported,
            'statusCode' => $status,
            'responseTimeMs' => max(0, (int) round((microtime(true) - $started) * 1000)),
            'occurredAt' => ($at ?: new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }

    /**
     * Send the spool if it is this process's turn. Cheap when it is not: a
     * couple of stat() calls. Runs last in the shutdown queue.
     */
    public function flushIfDue(): void
    {
        try {
            if (!$this->due()) {
                return;
            }
            $claimed = $this->claim();
            if ($claimed === null) {
                return;   // another process won the rename
            }
            $lambda = Config::env('AWS_LAMBDA_FUNCTION_NAME') !== null;
            // On Lambda, finishing the response freezes the container mid-send.
            if (!$lambda && function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            $this->send($claimed, $lambda ? self::LAMBDA_BUDGET_SECONDS : self::BUDGET_SECONDS);
        } catch (\Throwable) {
            // Tracking must never affect the app, not even at shutdown.
        }
    }

    /** Claim the spool and send it within `$budget` seconds, whatever the timing rules say. */
    public function flush(float $budget = self::BUDGET_SECONDS): void
    {
        $claimed = $this->claim();
        if ($claimed !== null) {
            $this->send($claimed, $budget);
        }
    }

    private function due(): bool
    {
        if (!$this->api->healingEnabled()) {
            return false;   // project disabled, key refused or server down: the same pause as healing
        }
        clearstatcache(true, $this->spoolPath());
        clearstatcache(true, $this->sentPath());
        $size = @filesize($this->spoolPath());
        if ($size === false || $size === 0) {
            return false;
        }
        $sinceLastSend = time() - (@filemtime($this->sentPath()) ?: 0);

        return $sinceLastSend >= self::MIN_GAP_SECONDS
            && ($size >= self::READY_BYTES || $sinceLastSend >= self::MAX_WAIT_SECONDS);
    }

    /** Take the spool for this process, or null when there is none or another process took it. */
    private function claim(): ?string
    {
        $this->removeStaleClaims();
        $claimed = $this->spoolPath() . '.' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.sending';
        if (!@rename($this->spoolPath(), $claimed)) {
            return null;
        }
        @touch($this->sentPath());

        return $claimed;
    }

    private function send(string $claimed, float $budget): void
    {
        $deadline = microtime(true) + $budget;
        try {
            $lines = @file($claimed, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $calls = [];
            foreach ($lines as $line) {
                $call = json_decode($line, true);
                if (is_array($call)) {
                    $calls[] = $call;
                }
            }
            foreach (array_chunk($calls, self::BATCH) as $batch) {
                for ($attempt = 0; $attempt < 2; $attempt++) {
                    $left = $deadline - microtime(true);
                    if ($left <= 0.05) {
                        return;   // out of budget: the rest is dropped
                    }
                    if ($this->api->sendRequests($batch, $left) !== HealApi::REQUESTS_RETRY) {
                        break;
                    }
                }
            }
        } finally {
            @unlink($claimed);
        }
    }

    private function append(string $line): void
    {
        $previous = umask(0077);   // the spool holds URLs: owner-only
        try {
            $handle = @fopen($this->spoolPath(), 'ab');
        } finally {
            umask($previous);
        }
        if ($handle === false) {
            return;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return;
            }
            $stat = fstat($handle);
            if (is_array($stat) && $stat['size'] + strlen($line) <= self::SPOOL_CAP_BYTES) {
                fwrite($handle, $line);
            }
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    private function removeStaleClaims(): void
    {
        foreach (glob($this->spoolPath() . '.*.sending') ?: [] as $stale) {
            $mtime = @filemtime($stale);
            if ($mtime !== false && time() - $mtime > self::STALE_CLAIM_SECONDS) {
                @unlink($stale);
            }
        }
    }

    /** The same base URL + key fingerprint as the handshake and backoff markers. */
    private function fingerprint(): string
    {
        return substr(hash('sha256', $this->config->baseUrl . '|' . ($this->config->apiKey ?? '')), 0, 16);
    }
}

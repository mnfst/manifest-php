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
     * stat() and a non-blocking lock. Runs last in the shutdown queue.
     */
    public function flushIfDue(): void
    {
        try {
            if (!$this->api->healingEnabled()) {
                return;   // project disabled, key refused or server down: the same pause as healing
            }
            $claimed = $this->claim(true);
            if ($claimed === null) {
                return;
            }
            $lambda = Config::env('AWS_LAMBDA_FUNCTION_NAME') !== null;
            // Native sessions are written, and their lock released, only after
            // every shutdown function: release it now, or the user's next
            // request waits for this send.
            if (session_status() === PHP_SESSION_ACTIVE) {
                @session_write_close();
            }
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
        $claimed = $this->claim(false);
        if ($claimed !== null) {
            $this->send($claimed, $budget);
        }
    }

    /**
     * Take the spool for this process, or null when it is not due, empty, or
     * another process is claiming. The whole check-and-claim runs under a
     * non-blocking lock on the sent marker, so two processes can never both
     * decide they are due; the marker holds the last send's microtime.
     */
    private function claim(bool $onlyIfDue): ?string
    {
        $marker = $this->sentPath();
        clearstatcache(true, $this->spoolPath());
        $size = @filesize($this->spoolPath());
        if ($size === false || $size === 0) {
            return null;   // the common case costs one stat()
        }
        $this->createPrivate($marker);
        $lock = @fopen($marker, 'r+');
        if ($lock === false) {
            return null;
        }
        try {
            if (!@flock($lock, LOCK_EX | LOCK_NB)) {
                return null;   // another process is claiming right now
            }
            $sinceLastSend = microtime(true) - (float) @stream_get_contents($lock);
            if ($onlyIfDue && ($sinceLastSend < self::MIN_GAP_SECONDS
                || ($size < self::READY_BYTES && $sinceLastSend < self::MAX_WAIT_SECONDS))) {
                return null;
            }
            $this->removeStaleClaims();
            $claimed = $this->spoolPath() . '.' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.sending';
            if (!@rename($this->spoolPath(), $claimed)) {
                return null;
            }
            @touch($claimed);   // rename keeps the old mtime; a live claim must not look stale
            @ftruncate($lock, 0);
            @rewind($lock);
            @fwrite($lock, sprintf('%.6F', microtime(true)));

            return $claimed;
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    /** Stream the claimed spool out in batches of BATCH within `$budget` seconds, then delete it. */
    private function send(string $claimed, float $budget): void
    {
        $deadline = microtime(true) + $budget;
        $handle = @fopen($claimed, 'rb');
        try {
            // Waits for a writer that opened the spool before the rename and
            // is still appending: its line lands before we read.
            if ($handle === false || !@flock($handle, LOCK_SH)) {
                return;
            }
            $batch = [];
            while (($line = fgets($handle)) !== false) {
                $call = json_decode($line, true);
                if (is_array($call)) {
                    $batch[] = $call;
                }
                if (count($batch) === self::BATCH) {
                    if (!$this->sendBatch($batch, $deadline)) {
                        return;
                    }
                    $batch = [];
                }
            }
            if ($batch !== []) {
                $this->sendBatch($batch, $deadline);
            }
        } finally {
            if (is_resource($handle)) {
                @fclose($handle);
            }
            @unlink($claimed);
        }
    }

    /** @return bool false once the budget is spent, so the rest is dropped */
    private function sendBatch(array $batch, float $deadline): bool
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $left = $deadline - microtime(true);
            if ($left <= 0.05) {
                return false;
            }
            if ($this->api->sendRequests($batch, $left) !== HealApi::REQUESTS_RETRY) {
                return true;
            }
        }

        return true;
    }

    /**
     * Append one line under an exclusive lock. After locking, the handle must
     * still be the spool: a sender may have renamed it away between fopen()
     * and flock(), and a line written there would be lost. Then reopen once.
     */
    private function append(string $line): void
    {
        $path = $this->spoolPath();
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->createPrivate($path);
            $handle = @fopen($path, 'ab');
            if ($handle === false) {
                return;
            }
            try {
                if (!@flock($handle, LOCK_EX)) {
                    return;
                }
                $opened = @fstat($handle);
                clearstatcache(true, $path);
                $current = @stat($path);
                if (!is_array($opened) || !is_array($current) || $opened['ino'] !== $current['ino']) {
                    continue;   // claimed meanwhile: reopen the new spool
                }
                if ($opened['size'] + strlen($line) <= self::SPOOL_CAP_BYTES) {
                    @fwrite($handle, $line);
                }

                return;
            } finally {
                @flock($handle, LOCK_UN);
                @fclose($handle);
            }
        }
    }

    /**
     * Create `$path` as an owner-only (0600) file if it does not exist, without
     * touching the process umask (process-wide, so unsafe under threaded PHP).
     * tempnam() creates 0600; link() publishes it only if nothing is there yet.
     */
    private function createPrivate(string $path): void
    {
        if (@is_file($path)) {
            return;
        }
        $tmp = @tempnam(dirname($path), 'mnfst-');
        if ($tmp === false) {
            return;
        }
        if (!@link($tmp, $path) && !@is_file($path)) {
            @rename($tmp, $path);   // no hard links on this filesystem
        }
        @unlink($tmp);
    }

    private function removeStaleClaims(): void
    {
        foreach (@glob($this->spoolPath() . '.*.sending') ?: [] as $stale) {
            $mtime = @filemtime($stale);
            if ($mtime !== false && time() - $mtime > self::STALE_CLAIM_SECONDS) {
                @unlink($stale);
            }
        }
    }

    /**
     * The base URL + key fingerprint of the handshake and backoff markers, plus
     * the effective user: php-fpm as www-data and a cron job as another user
     * cannot share an owner-only spool, so each user gets its own.
     */
    private function fingerprint(): string
    {
        $user = function_exists('posix_geteuid') ? (string) posix_geteuid() : '';

        return substr(hash('sha256', $this->config->baseUrl . '|' . ($this->config->apiKey ?? '') . '|' . $user), 0, 16);
    }
}

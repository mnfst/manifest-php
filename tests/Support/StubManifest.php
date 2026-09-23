<?php declare(strict_types=1);

namespace Mnfst\Tests\Support;

/**
 * A real socket the SDK can talk to. Its behaviour is the contract, so a
 * change here means the contract changed.
 */
class StubManifest
{
    /** @var resource|null */
    private $process = null;
    private string $stateFile = '';
    public string $url = '';

    public function start(): string
    {
        $this->stateFile = sys_get_temp_dir() . '/stub-' . bin2hex(random_bytes(6));
        file_put_contents($this->stateFile . '.state', json_encode(['disabled' => false, 'result' => null]));

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $port = random_int(9200, 9899);
            $token = bin2hex(random_bytes(8));
            $this->process = proc_open(
                sprintf('exec php -S 127.0.0.1:%d %s', $port, escapeshellarg($this->routerPath())),
                [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes,
                null,
                ['MNFST_STUB_STATE' => $this->stateFile, 'MNFST_STUB_TOKEN' => $token],
            );
            $url = "http://127.0.0.1:$port";
            if ($this->waitUntilReady($url, $token)) {
                return $this->url = $url;
            }
            $this->terminate();
        }

        throw new \RuntimeException('could not start the stub server');
    }

    protected function routerPath(): string
    {
        return __DIR__ . '/stub-router.php';
    }

    /** The heal answer, sent back byte for byte: `{}` and `10.0` must reach the SDK as written. */
    public function setResult(?array $result): void
    {
        $json = $result === null ? null : json_encode($result, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES);
        $this->writeState(['result' => $json]);
    }

    public function setDisabled(bool $disabled): void
    {
        $this->writeState(['disabled' => $disabled]);
    }

    /** Answer every call with the 401 a bad project key really gets. */
    public function setRejectKey(bool $reject): void
    {
        $this->writeState(['rejectKey' => $reject]);
    }

    /** @return array<int, array> the heal payloads received, oldest first */
    public function heals(): array
    {
        return $this->readLog('heals');
    }

    /** @return array<int, string> the heal payloads as the bytes they arrived in */
    public function rawHeals(): array
    {
        return array_column($this->readLog('heals_raw'), 'raw');
    }

    /** Answer POST /v1/requests with this status (202 records the calls). */
    public function setRequestsStatus(int $status): void
    {
        $this->writeState(['requestsStatus' => $status]);
    }

    /** @return array<int, array> the tracked calls received, oldest first */
    public function tracked(): array
    {
        return $this->readLog('tracked');
    }

    /** @return array<int, array{status: int, count: int}> every POST /v1/requests, oldest first */
    public function batches(): array
    {
        return $this->readLog('batches');
    }

    /** @return array<int, array> the handshake bodies received, oldest first */
    public function hellos(): array
    {
        return $this->readLog('hellos');
    }

    /** @return array<int, array{0: string, 1: array}> attempt id and outcome body */
    public function outcomes(): array
    {
        return $this->readLog('outcomes');
    }

    /** @return array<int, array{method: string, path: string}> every call, answered or refused, oldest first */
    public function requests(): array
    {
        return $this->readLog('requests');
    }

    public function stop(): void
    {
        $this->terminate();
        foreach (['state', 'heals', 'heals_raw', 'hellos', 'outcomes', 'requests', 'tracked', 'batches'] as $suffix) {
            @unlink($this->stateFile . '.' . $suffix);
        }
    }

    private function terminate(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        $this->process = null;
    }

    /**
     * Ready means OUR server answers on the port: another test's stub may
     * still be dying on it, and the wrong one would answer 404 to every
     * route. Deliberately NOT curl: a curl_exec here would happen before the
     * SDK's hooks are installed, and an internal function that has already
     * been called cannot be hooked afterwards (hook rule 7).
     */
    private function waitUntilReady(string $url, string $token): bool
    {
        $context = stream_context_create(['http' => ['timeout' => 0.5, 'ignore_errors' => true]]);
        for ($i = 0; $i < 100; $i++) {
            usleep(20000);
            $status = proc_get_status($this->process);
            if (!$status['running']) {
                return false;   // could not bind: the port is taken
            }
            $body = @file_get_contents($url . '/__ready', false, $context);
            if (is_string($body) && str_contains($body, $token)) {
                return true;
            }
        }

        return false;
    }

    private function writeState(array $patch): void
    {
        $state = json_decode((string) file_get_contents($this->stateFile . '.state'), true) ?: [];
        file_put_contents($this->stateFile . '.state', json_encode($patch + $state));
    }

    private function readLog(string $name): array
    {
        $raw = @file_get_contents($this->stateFile . '.' . $name);
        if ($raw === false) {
            return [];
        }

        return array_values(array_map(
            static fn (string $line): array => json_decode($line, true) ?: [],
            array_filter(explode("\n", trim($raw))),
        ));
    }
}

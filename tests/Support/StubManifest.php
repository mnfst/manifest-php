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
            $this->process = proc_open(
                sprintf('exec php -S 127.0.0.1:%d %s', $port, escapeshellarg($this->routerPath())),
                [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes,
                null,
                ['MNFST_STUB_STATE' => $this->stateFile],
            );
            $url = "http://127.0.0.1:$port";
            if ($this->waitUntilReady($url)) {
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

    public function setResult(?array $result): void
    {
        $this->writeState(['result' => $result]);
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

    public function stop(): void
    {
        $this->terminate();
        foreach (['state', 'heals', 'heals_raw', 'hellos', 'outcomes'] as $suffix) {
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
     * Deliberately NOT curl: a curl_exec here would happen before the SDK's
     * hooks are installed, and an internal function that has already been
     * called cannot be hooked afterwards (hook rule 7).
     */
    private function waitUntilReady(string $url): bool
    {
        $port = (int) parse_url($url, PHP_URL_PORT);
        for ($i = 0; $i < 100; $i++) {
            usleep(20000);
            $socket = @stream_socket_client("tcp://127.0.0.1:$port", $errno, $errstr, 0.2);
            if (is_resource($socket)) {
                fclose($socket);

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

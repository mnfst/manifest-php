<?php declare(strict_types=1);

namespace Mnfst\Tests\Support;

/**
 * A real socket the SDK can talk to. Its behaviour is the contract, so a
 * change here means the contract changed.
 */
final class StubManifest
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

    /** @return array<int, array> the heal payloads received, oldest first */
    public function heals(): array
    {
        return $this->readLog('heals');
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
        foreach (['state', 'heals', 'hellos', 'outcomes'] as $suffix) {
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

    private function waitUntilReady(string $url): bool
    {
        for ($i = 0; $i < 100; $i++) {
            usleep(20000);
            $ch = curl_init($url . '/__ready');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 1]);
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($status === 200 && is_string($raw) && str_contains($raw, 'ready')) {
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

<?php declare(strict_types=1);

namespace Mnfst\Tests\Integration;

use Mnfst\Config;
use Mnfst\Handshake;
use Mnfst\Tests\Support\StubManifest;
use PHPUnit\Framework\TestCase;

final class HandshakeTest extends TestCase
{
    private StubManifest $stub;

    protected function setUp(): void
    {
        $this->stub = new StubManifest();
        $this->stub->start();
        @unlink($this->handshake()->markerPath());
    }

    protected function tearDown(): void
    {
        @unlink($this->handshake()->markerPath());
        $this->stub->stop();
    }

    private function handshake(): Handshake
    {
        return new Handshake(Config::resolve('k', $this->stub->url));
    }

    public function testAnnouncesOnceAcrossManyProcessStarts(): void
    {
        // Ten fresh Handshake objects stand in for ten php-fpm requests.
        for ($i = 0; $i < 10; $i++) {
            $this->handshake()->announce();
        }
        self::assertCount(1, $this->stub->hellos());
    }

    public function testNothingIsAnnouncedWithoutAKey(): void
    {
        $silent = new Handshake(Config::resolve(null, $this->stub->url));
        @unlink($silent->markerPath());
        $silent->announce();

        self::assertSame([], $this->stub->hellos());
        self::assertFileDoesNotExist($silent->markerPath());
    }

    public function testSendsTheRuntime(): void
    {
        $this->handshake()->announce();
        [$hello] = $this->stub->hellos();
        self::assertStringStartsWith('php-', $hello['runtime']);
    }

    public function testAnInstallIsNotAProbe(): void
    {
        $this->handshake()->announce();
        [$hello] = $this->stub->hellos();
        self::assertArrayNotHasKey('probe', $hello);
    }

    public function testWithoutAKeyThereIsNoHandshake(): void
    {
        (new Handshake(Config::resolve(null, $this->stub->url)))->announce();
        self::assertSame([], $this->stub->hellos());
    }

    public function testAnnouncesAgainOnceTheMarkerIsStale(): void
    {
        $handshake = $this->handshake();
        $handshake->announce();
        touch($handshake->markerPath(), time() - Handshake::TTL_SECONDS - 1);
        $handshake->announce();
        self::assertCount(2, $this->stub->hellos());
    }

    public function testAFailedHandshakeNeverThrows(): void
    {
        (new Handshake(Config::resolve('k', 'http://127.0.0.1:9')))->announce();
        self::assertTrue(true, 'announce() must never throw');
    }
}

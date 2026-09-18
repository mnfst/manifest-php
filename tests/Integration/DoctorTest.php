<?php declare(strict_types=1);

namespace Mnfst\Tests\Integration;

use Mnfst\Config;
use Mnfst\Doctor;
use Mnfst\Tests\Support\StubManifest;
use PHPUnit\Framework\TestCase;

final class DoctorTest extends TestCase
{
    private StubManifest $stub;

    protected function setUp(): void
    {
        $this->stub = new StubManifest();
        $this->stub->start();
    }

    protected function tearDown(): void
    {
        $this->stub->stop();
    }

    /** @return array{0: int, 1: string} */
    private function doctor(?string $key): array
    {
        ob_start();
        $code = Doctor::run(['doctor'], Config::resolve($key, $this->stub->url));

        return [$code, (string) ob_get_clean()];
    }

    public function testReportsAValidKeyAndTheProjectName(): void
    {
        [$code, $output] = $this->doctor('mnfx_valid');
        self::assertSame(0, $code);
        self::assertStringContainsString('Stub', $output);
    }

    public function testMasksTheKey(): void
    {
        [, $output] = $this->doctor('mnfx_supersecretvalue');
        self::assertStringNotContainsString('supersecretvalue', $output);
    }

    public function testFailsWithoutAKey(): void
    {
        [$code, $output] = $this->doctor(null);
        self::assertSame(1, $code);
        self::assertStringContainsString('MNFST_KEY', $output);
    }

    public function testReportsTheCoverageLevel(): void
    {
        [, $output] = $this->doctor('mnfx_valid');
        self::assertMatchesRegularExpression('/full coverage|framework-only/', $output);
        self::assertStringNotContainsString('Mode A', $output);
        self::assertStringNotContainsString('Mode B', $output);
    }

    public function testReportsWhetherTheSdkLoadsFirst(): void
    {
        [, $output] = $this->doctor('mnfx_valid');
        self::assertStringContainsString('loading', $output);
    }

    public function testWarnsWhenTheServerIsUnreachable(): void
    {
        ob_start();
        $code = Doctor::run(['doctor'], Config::resolve('mnfx_valid', 'http://127.0.0.1:9'));
        $output = (string) ob_get_clean();

        self::assertSame(1, $code);
        self::assertStringContainsString('unreachable', $output);
    }
}

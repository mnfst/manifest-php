<?php declare(strict_types=1);

namespace Mnfst\Tests\Unit;

use Mnfst\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('MNFST_KEY');
        putenv('MNFST_URL');
    }

    public function testArgumentBeatsEnvironment(): void
    {
        putenv('MNFST_KEY=from-env');
        self::assertSame('from-arg', Config::resolve('from-arg')->apiKey);
    }

    public function testEnvironmentBeatsDefault(): void
    {
        putenv('MNFST_URL=https://example.test/');
        self::assertSame('https://example.test', Config::resolve()->baseUrl);
    }

    public function testDefaultsToHostedUrlAndNullKey(): void
    {
        $config = Config::resolve();
        self::assertSame(Config::HOSTED_URL, $config->baseUrl);
        self::assertNull($config->apiKey);
    }

    public function testTrailingSlashIsStripped(): void
    {
        self::assertSame('https://a.test', Config::resolve(null, 'https://a.test/')->baseUrl);
    }

    public function testTheOutcomeReportTimesOutSooner(): void
    {
        self::assertSame(5, Config::REPORT_TIMEOUT_SECONDS);
    }

    public function testHealTimeoutIsTenSeconds(): void
    {
        self::assertSame(10, Config::HEAL_TIMEOUT_SECONDS);
    }
}

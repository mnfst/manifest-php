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
        unset($_ENV['MNFST_KEY'], $_ENV['MNFST_URL'], $_SERVER['MNFST_KEY'], $_SERVER['MNFST_URL']);
    }

    public function testAKeyLoadedByAFrameworkDotenvIsFound(): void
    {
        // Laravel and Symfony fill $_ENV and $_SERVER from .env and never call putenv().
        $_ENV['MNFST_KEY'] = 'from-dotenv';
        self::assertSame('from-dotenv', Config::resolve()->apiKey);
        unset($_ENV['MNFST_KEY']);

        $_SERVER['MNFST_URL'] = 'https://server.test/';
        self::assertSame('https://server.test', Config::resolve()->baseUrl);
    }

    public function testAnEmptyValueCountsAsUnset(): void
    {
        $_ENV['MNFST_KEY'] = '';
        putenv('MNFST_KEY=');
        self::assertNull(Config::resolve()->apiKey);
        self::assertNull(Config::resolve('')->apiKey, 'config() hands over an empty string for a blanked variable');
        self::assertNull(Config::resolve(' ')->apiKey);
        self::assertSame(Config::HOSTED_URL, Config::resolve(null, '')->baseUrl);
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

    public function testHealTimeoutIsTenSeconds(): void
    {
        self::assertSame(10, Config::HEAL_TIMEOUT_SECONDS);
    }
}

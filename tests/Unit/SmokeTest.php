<?php declare(strict_types=1);

namespace Mnfst\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function testExtensionIsLoaded(): void
    {
        self::assertTrue(extension_loaded('opentelemetry'), 'the opentelemetry extension must be loaded');
        self::assertTrue(function_exists('OpenTelemetry\Instrumentation\hook'));
    }

    public function testPhpFloor(): void
    {
        self::assertTrue(PHP_VERSION_ID >= 80200);
    }
}

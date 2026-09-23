<?php declare(strict_types=1);

namespace Mnfst\Tests\Unit;

use Mnfst\Manifest;
use PHPUnit\Framework\TestCase;

/**
 * The SDK reports Manifest::VERSION in its User-Agent, and release-please owns
 * that line. v0.3.0 shipped still saying 0.2.0; this keeps the constant, the
 * release manifest and the source annotation in step.
 */
final class VersionTest extends TestCase
{
    public function testTheVersionConstantMatchesTheReleaseManifest(): void
    {
        $manifest = json_decode((string) file_get_contents(__DIR__ . '/../../.release-please-manifest.json'), true);

        self::assertSame($manifest['.'], Manifest::VERSION);
    }

    public function testReleasePleaseCanFindTheVersionLine(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Manifest.php');

        self::assertMatchesRegularExpression("~public const VERSION = '[0-9.]+'; // x-release-please-version~", $source);
    }
}

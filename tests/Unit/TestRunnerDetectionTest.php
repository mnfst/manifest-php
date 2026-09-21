<?php declare(strict_types=1);

namespace Mnfst\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class TestRunnerDetectionTest extends TestCase
{
    public function testPrependRecognizesTestCommandsBeforeTheirBootstrap(): void
    {
        foreach ([
            [['vendor/bin/phpunit'], true],
            [['tools/phpunit.phar'], true],
            [['vendor/bin/pest'], true],
            [['artisan', 'test'], true],
            [['artisan', 'serve'], false],
            [['worker.php'], false],
        ] as [$argv, $expected]) {
            $code = 'require '.var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true).';'
                .'$_SERVER["argv"] = '.var_export($argv, true).';'
                .'echo \Mnfst\Manifest::inTestRunner() ? "yes" : "no";';
            $output = [];
            exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($code), $output, $status);
            self::assertSame(0, $status);
            self::assertSame($expected ? ['yes'] : ['no'], $output);
        }
    }
}

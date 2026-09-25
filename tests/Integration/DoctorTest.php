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
    private function doctor(?string $key, array $files = []): array
    {
        $root = sys_get_temp_dir() . '/mnfst-project-' . bin2hex(random_bytes(4));
        foreach ($files as $path => $content) {
            @mkdir(dirname($root . '/' . $path), 0777, true);
            file_put_contents($root . '/' . $path, $content);
        }
        @mkdir($root, 0777, true);
        ob_start();
        $code = Doctor::run(['doctor'], Config::resolve($key, $this->stub->url), $root);

        return [$code, (string) ob_get_clean()];
    }

    private static function composer(array $require, array $extra = []): string
    {
        return json_encode(['require' => $require] + ($extra === [] ? [] : ['extra' => $extra]));
    }

    public function testReportsAValidKey(): void
    {
        [$code, $output] = $this->doctor('mnfx_valid');
        self::assertSame(0, $code);
        self::assertStringContainsString('accepted the key', $output);
    }

    public function testChecksTheKeyWithAProbeNotAnInstall(): void
    {
        $this->doctor('mnfx_valid');
        [$hello] = $this->stub->hellos();
        self::assertTrue($hello['probe'] ?? false, 'a laptop run must not mark the app as connected');
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

    public function testWarnsWhenTheServerIsUnreachable(): void
    {
        ob_start();
        $code = Doctor::run(['doctor'], Config::resolve('mnfx_valid', 'http://127.0.0.1:9'), sys_get_temp_dir());
        $output = (string) ob_get_clean();

        self::assertSame(1, $code);
        self::assertStringContainsString('unreachable', $output);
    }

    public function testAnInvalidKeyIsReportedAsRejectedNotUnreachable(): void
    {
        $this->stub->setRejectKey(true);
        [$code, $output] = $this->doctor('mnfx_wrong');

        self::assertSame(1, $code);
        self::assertStringContainsString('rejected', $output);
        self::assertStringNotContainsString('unreachable', $output);
    }

    public function testADisabledProjectIsNotReportedAsARejectedKey(): void
    {
        $this->stub->setDisabled(true);
        [$code, $output] = $this->doctor('mnfx_valid');

        self::assertSame(1, $code);
        self::assertStringContainsString('healing is disabled', $output);
        self::assertStringNotContainsString('rejected', $output);
    }

    public function testAProjectWithNoFrameworkPointsAtTheGuzzleMiddleware(): void
    {
        [$code, $output] = $this->doctor('mnfx_valid');
        self::assertSame(0, $code);
        self::assertStringContainsString('\Mnfst\Guzzle\middleware()', $output);
        self::assertStringContainsString('not seen', $output);
        self::assertStringNotContainsString('pecl', $output);
    }

    public function testLaravelIsWiredByPackageDiscovery(): void
    {
        [$code, $output] = $this->doctor('mnfx_valid', ['composer.json' => self::composer(['laravel/framework' => '^11.0'])]);
        self::assertSame(0, $code);
        self::assertMatchesRegularExpression('/laravel\s+wired/', $output);
    }

    public function testLaravelWithDiscoveryOffIsNotWired(): void
    {
        [$code, $output] = $this->doctor('mnfx_valid', ['composer.json' => self::composer(
            ['laravel/framework' => '^11.0'],
            ['laravel' => ['dont-discover' => ['mnfst/manifest-php']]],
        )]);
        self::assertSame(1, $code);
        self::assertStringContainsString('ManifestServiceProvider', $output);
    }

    public function testCakeNeedsThePlugin(): void
    {
        $composer = self::composer(['cakephp/cakephp' => '^5.1']);
        [$code, $output] = $this->doctor('mnfx_valid', ['composer.json' => $composer, 'src/Application.php' => '<?php // nothing']);
        self::assertSame(1, $code);
        self::assertStringContainsString('addPlugin', $output);

        [$code] = $this->doctor('mnfx_valid', ['composer.json' => $composer,
            'src/Application.php' => '<?php $this->addPlugin(\Mnfst\Cake\ManifestPlugin::class);']);
        self::assertSame(0, $code);
    }

    public function testCakeBelow51IsReportedAsUnsupported(): void
    {
        [$code, $output] = $this->doctor('mnfx_valid', [
            'composer.json' => self::composer(['cakephp/cakephp' => '^5.0']),
            'composer.lock' => json_encode(['packages' => [['name' => 'cakephp/cakephp', 'version' => '5.0.11']]]),
            'src/Application.php' => '<?php $this->addPlugin(\Mnfst\Cake\ManifestPlugin::class);',
        ]);
        self::assertSame(1, $code);
        self::assertStringContainsString('5.1', $output);
    }

    public function testSymfonyNeedsTheBundle(): void
    {
        $composer = self::composer(['symfony/framework-bundle' => '^7.0']);
        [$code, $output] = $this->doctor('mnfx_valid', ['composer.json' => $composer, 'config/bundles.php' => '<?php return [];']);
        self::assertSame(1, $code);
        self::assertStringContainsString('config/bundles.php', $output);

        [$code] = $this->doctor('mnfx_valid', ['composer.json' => $composer,
            'config/bundles.php' => "<?php return [Mnfst\\Symfony\\ManifestBundle::class => ['all' => true]];"]);
        self::assertSame(0, $code);
    }

    public function testWordPressNeedsTheMustUsePlugin(): void
    {
        [$code, $output] = $this->doctor('mnfx_valid', ['wp-config.php' => '<?php']);
        self::assertSame(1, $code);
        self::assertStringContainsString('mu-plugins', $output);

        [$code] = $this->doctor('mnfx_valid', ['wp-config.php' => '<?php',
            'wp-content/mu-plugins/manifest.php' => '<?php \Mnfst\WordPress\listen();']);
        self::assertSame(0, $code);
    }
}

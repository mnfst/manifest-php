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

    /** @param array<string, string> $files */
    private static function project(array $files): string
    {
        $root = sys_get_temp_dir() . '/mnfst-project-' . bin2hex(random_bytes(4));
        foreach ($files as $path => $content) {
            @mkdir(dirname($root . '/' . $path), 0777, true);
            file_put_contents($root . '/' . $path, $content);
        }
        @mkdir($root, 0777, true);

        return $root;
    }

    /** @return array{0: int, 1: string} */
    private function doctor(?string $key, array $files = []): array
    {
        $root = self::project($files);
        ob_start();
        $code = Doctor::run(['doctor'], Config::resolve($key, $this->stub->url), $root);

        return [$code, (string) ob_get_clean()];
    }

    /** @return array{0: int, 1: string} the doctor as bin/manifest runs it: configuration read from the project */
    private function doctorIn(array $files): array
    {
        $root = self::project($files);
        ob_start();
        $code = Doctor::run(['doctor'], Doctor::config($root), $root);

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

    public function testReadsTheKeyFromTheProjectDotEnv(): void
    {
        [$code, $output] = $this->doctorIn(['.env' => "APP_NAME=demo\nMNFST_KEY=mnfx_valid\nMNFST_URL={$this->stub->url}\n"]);
        self::assertSame(0, $code);
        self::assertStringContainsString('accepted the key', $output);
    }

    public function testEnvLocalWinsOverEnv(): void
    {
        [, $output] = $this->doctorIn([
            '.env' => "MNFST_KEY=mnfx_from_base\nMNFST_URL={$this->stub->url}\n",
            '.env.local' => "MNFST_KEY=mnfx_from_local\n",
        ]);
        self::assertStringContainsString('mnfx********al', $output, 'Symfony: .env.local overrides .env');
    }

    public function testReadsCakeConfigDotEnvWithExportAndQuotes(): void
    {
        [$code, $output] = $this->doctorIn(['config/.env' => "export MNFST_KEY=\"mnfx_valid\"\nexport MNFST_URL='{$this->stub->url}'\n"]);
        self::assertSame(0, $code);
        self::assertStringContainsString('accepted the key', $output);
    }

    public function testCommentsAreNotValues(): void
    {
        [$code, $output] = $this->doctorIn(['.env' => "# MNFST_KEY=mnfx_commented\nMNFST_KEY=mnfx_valid # the project key\nMNFST_URL={$this->stub->url}\n"]);
        self::assertSame(0, $code);
        self::assertStringContainsString('mnfx********id', $output);
    }

    public function testTheShellWinsOverTheProjectDotEnv(): void
    {
        putenv('MNFST_KEY=mnfx_shell_key');
        putenv('MNFST_URL=' . $this->stub->url);
        try {
            [$code, $output] = $this->doctorIn(['.env' => "MNFST_KEY=mnfx_file_key\nMNFST_URL=http://127.0.0.1:9\n"]);
        } finally {
            putenv('MNFST_KEY');
            putenv('MNFST_URL');
        }
        self::assertSame(0, $code, 'the URL from the shell was used, not the unreachable one in .env');
        self::assertStringContainsString('mnfx********ey', $output);
    }

    public function testAMissingKeyPointsAtBothPlaces(): void
    {
        [$code, $output] = $this->doctorIn([]);
        self::assertSame(1, $code);
        self::assertStringContainsString('.env', $output);
    }
}

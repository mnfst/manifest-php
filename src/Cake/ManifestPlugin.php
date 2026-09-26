<?php declare(strict_types=1);

namespace Mnfst\Cake;

use Cake\Core\BasePlugin;
use Cake\Core\Configure;
use Cake\Core\PluginApplicationInterface;
use Mnfst\Manifest;

/**
 * `$this->addPlugin(\Mnfst\Cake\ManifestPlugin::class);` in
 * Application::bootstrap(). bin/cake runs the same bootstrap, so console
 * commands are covered. The key comes from Configure 'Manifest.key' or
 * MNFST_KEY.
 */
final class ManifestPlugin extends BasePlugin
{
    protected bool $routesEnabled = false;

    protected bool $middlewareEnabled = false;

    protected bool $consoleEnabled = false;

    protected bool $servicesEnabled = false;

    public function bootstrap(PluginApplicationInterface $app): void
    {
        Listener::register();
        $key = Configure::read('Manifest.key');
        $url = Configure::read('Manifest.url');
        Manifest::start(is_string($key) ? $key : null, is_string($url) ? $url : null);
    }
}

<?php declare(strict_types=1);

namespace Mnfst\Symfony;

use Mnfst\Manifest;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * `Mnfst\Symfony\ManifestBundle::class => ['all' => true]` in
 * config/bundles.php. bin/console boots bundles too, so console commands are
 * covered. The key comes from MNFST_KEY (Symfony's .env is read into $_SERVER).
 */
final class ManifestBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new DecorateHttpClientPass());
    }

    public function boot(): void
    {
        Manifest::register('symfony');
        Manifest::start();
    }
}

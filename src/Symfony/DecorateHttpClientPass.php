<?php declare(strict_types=1);

namespace Mnfst\Symfony;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/** Decorate the http_client service; scoped clients are built on it, so they are covered too. */
final class DecorateHttpClientPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has('http_client')) {
            return;
        }
        $container->register('mnfst.http_client', HealingHttpClient::class)
            ->setDecoratedService('http_client')
            ->setArguments([new Reference('.inner')]);
    }
}

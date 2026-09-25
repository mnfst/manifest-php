<?php declare(strict_types=1);

namespace Mnfst\Symfony;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Decorate the transport that http_client and every scoped client
 * (framework.http_client.scoped_clients) are built on, so they are all
 * covered; falls back to decorating http_client itself when there is no
 * separate transport service.
 */
final class DecorateHttpClientPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $target = $container->has('http_client.transport') ? 'http_client.transport' : 'http_client';
        if (!$container->has($target)) {
            return;
        }
        $container->register('mnfst.http_client', HealingHttpClient::class)
            ->setDecoratedService($target)
            ->setArguments([new Reference('.inner')]);
    }
}

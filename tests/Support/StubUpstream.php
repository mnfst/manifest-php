<?php declare(strict_types=1);

namespace Mnfst\Tests\Support;

/**
 * The API the application is calling. POST /orders rejects limit > 100, so a
 * heal that sets limit to 100 turns a 400 into a 200.
 */
final class StubUpstream extends StubManifest
{
    protected function routerPath(): string
    {
        return __DIR__ . '/upstream-router.php';
    }
}

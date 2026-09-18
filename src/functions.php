<?php declare(strict_types=1);

namespace Mnfst;

function manifest(?string $apiKey = null, ?string $url = null, ?callable $onHeal = null): void
{
    Manifest::start($apiKey, $url, $onHeal);
}

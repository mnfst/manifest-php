<?php declare(strict_types=1);

/**
 * The auto_prepend_file entry point.
 *
 * A hook cannot attach to a function that has already been called in the
 * process, so the SDK must load before the application makes any HTTP call.
 * Point php.ini, a .user.ini, or the php-fpm pool config at this file:
 *
 *   auto_prepend_file = /path/to/vendor/mnfst/manifest-php/prepend.php
 *
 * It reads MNFST_KEY from the environment and never throws.
 */

foreach ([__DIR__ . '/../../autoload.php', __DIR__ . '/vendor/autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;
        break;
    }
}

if (function_exists('Mnfst\manifest')) {
    try {
        Mnfst\manifest();
    } catch (Throwable) {
        // the SDK must never break the application it loads into
    }
}

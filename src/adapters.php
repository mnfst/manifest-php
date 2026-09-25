<?php declare(strict_types=1);

namespace Mnfst\Guzzle {
    /** Guzzle handler middleware: `$stack->push(\Mnfst\Guzzle\middleware());` */
    function middleware(): callable
    {
        return Middleware::create();
    }
}

namespace Mnfst\Cake {
    /** Heal every Cake\Http\Client call; for apps that wire it in config/bootstrap.php instead of addPlugin(). */
    function listen(): void
    {
        Listener::register();
    }
}

namespace Mnfst\WordPress {
    /** Heal every wp_remote_* call; call it from a must-use plugin. */
    function listen(): void
    {
        Filters::register();
    }
}

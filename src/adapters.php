<?php declare(strict_types=1);

namespace Mnfst\Guzzle {
    /** Guzzle handler middleware: `$stack->push(\Mnfst\Guzzle\middleware());` */
    function middleware(): callable
    {
        return Middleware::create();
    }
}

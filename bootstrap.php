<?php

declare(strict_types=1);

if (is_file(__DIR__ . '/vendor-scoped/autoload.php')) {
    require_once __DIR__ . '/vendor-scoped/autoload.php';
    if (is_file(__DIR__ . '/functions.php')) {
        require_once __DIR__ . '/functions.php';
    }
    return;
}

if (is_file(__DIR__ . '/vendor/autoload.php')) {
    $loader = require_once __DIR__ . '/vendor/autoload.php';
    if ($loader instanceof \Composer\Autoload\ClassLoader) {
        $loader->unregister();
        $loader->register(false);
    }
    if (is_file(__DIR__ . '/functions.php')) {
        require_once __DIR__ . '/functions.php';
    }
} else {
    require_once __DIR__ . '/src/Autoload.php';
    if (is_file(__DIR__ . '/functions.php')) {
        require_once __DIR__ . '/functions.php';
    }
}

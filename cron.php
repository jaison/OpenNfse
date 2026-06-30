<?php

declare(strict_types=1);

use OpenNfse\Module;

if (!defined('WHMCS')) {
    if (PHP_SAPI !== 'cli') {
        exit('This file cannot be accessed directly');
    }
    $init = realpath(__DIR__ . '/../../../init.php');
    if ($init === false) {
        fwrite(STDERR, "init.php não encontrado.\n");
        exit(1);
    }
    require_once $init;
}

require_once __DIR__ . '/bootstrap.php';

Module::migrator()->up();
Module::cron()->run();

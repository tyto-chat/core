<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// Defensive: enforce test env even when invoked outside PHPUnit (where <server force="true"/> would set these).
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
$_SERVER['APP_DEBUG'] = $_ENV['APP_DEBUG'] = '1';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

umask(0000);

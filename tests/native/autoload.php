<?php declare(strict_types=1);

/*
 * Minimal autoload bootstrap for the native (PHPUnit-free) test and the demo.
 *
 * Only production dependencies are needed: monolog itself (from src/) and
 * psr/log. psr/log is loaded from, in order of preference:
 *   1. a composer autoloader (vendor/autoload.php, e.g. `composer install --no-dev`)
 *   2. the PSR_LOG_DIR environment variable pointing at the psr/log `src` dir
 */

$root = \dirname(__DIR__, 2);

spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'Monolog\\')) {
        $file = $root.'/src/Monolog/'.str_replace('\\', '/', substr($class, 8)).'.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

$composerAutoload = $root.'/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require $composerAutoload;
} elseif (($psrLogDir = getenv('PSR_LOG_DIR')) !== false && $psrLogDir !== '') {
    spl_autoload_register(static function (string $class) use ($psrLogDir): void {
        if (str_starts_with($class, 'Psr\\Log\\')) {
            $file = rtrim($psrLogDir, '/\\').'/'.str_replace('\\', '/', substr($class, 8)).'.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });
}

if (!interface_exists(\Psr\Log\LoggerInterface::class)) {
    fwrite(STDERR, "psr/log (the only production dependency) could not be loaded.\n");
    fwrite(STDERR, "Run `composer install --no-dev` or set PSR_LOG_DIR to the psr/log src directory.\n");
    exit(1);
}

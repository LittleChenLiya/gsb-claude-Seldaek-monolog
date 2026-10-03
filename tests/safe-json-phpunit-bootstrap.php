<?php declare(strict_types=1);
// Local PHPUnit bootstrap for running the SafeJsonFormatter tests without a
// full composer install. It prepends an autoloader for this checkout src/
// plus the production psr/log dependency, then loads any extra autoloader
// (e.g. a PHPUnit vendor autoload) passed via MONOLOG_TEST_EXTRA_AUTOLOAD.
spl_autoload_register(function (string $class): void {
    $map = [
        "Psr\Log\\" => __DIR__ . "/../vendor/psr/log/src/",
        "Monolog\\"  => __DIR__ . "/../src/Monolog/",
    ];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace("\\", "/", substr($class, strlen($prefix))) . ".php";
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
}, true, true);
$extra = getenv("MONOLOG_TEST_EXTRA_AUTOLOAD");
if (is_string($extra) && $extra !== "") {
    require $extra;
}
<?php

// Plugins are autoloaded at runtime by PluginServiceProvider; give PHPStan the same mapping.
spl_autoload_register(function (string $class): void {
    $prefix = 'Plugins\\Offers\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__.'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

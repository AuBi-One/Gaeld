<?php

// Plugins are autoloaded at runtime by PluginServiceProvider; give PHPStan the same mapping
// (this plugin and the offers plugin it requires).
spl_autoload_register(function (string $class): void {
    foreach (['Plugins\\DocumentLayouts\\' => __DIR__.'/src/', 'Plugins\\Offers\\' => __DIR__.'/../offers/src/'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
    }
});

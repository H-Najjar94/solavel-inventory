<?php

spl_autoload_register(static function (string $class): void {
    foreach (['App\\' => dirname(__DIR__).'/app/', 'Tests\\' => __DIR__.'/'] as $prefix => $root) {
        if (!str_starts_with($class, $prefix)) continue;
        $file=$root.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
        if (is_file($file)) require_once $file;
    }
}, true, true);

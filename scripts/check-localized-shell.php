<?php
// Compile and lint the Blade shell without booting an application or a database.
require __DIR__.'/../vendor/autoload.php';
$compiler = new Illuminate\View\Compilers\BladeCompiler(new Illuminate\Filesystem\Filesystem, sys_get_temp_dir());
$file = tempnam(sys_get_temp_dir(), 'stock-localized-shell-');
try {
    foreach (['solastock-app', 'errors/app-access'] as $view) {
    file_put_contents($file, $compiler->compileString(file_get_contents(__DIR__.'/../resources/views/'.$view.'.blade.php')));
    passthru(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file), $status);
    if ($status) exit($status);
    }
} finally {
    unlink($file);
}

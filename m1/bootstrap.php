<?php
declare(strict_types=1);

// The local core and M2 use PHP extensions directly. M0 retains its lockfile.
spl_autoload_register(static function (string $class): void {
    foreach (['M1' => __DIR__, 'M2' => dirname(__DIR__).'/m2', 'M3' => dirname(__DIR__).'/m3', 'M4' => dirname(__DIR__).'/m4'] as $namespace => $directory) {
        $prefix = 'Trademinator\\Client\\'.$namespace.'\\';
        if (!str_starts_with($class, $prefix)) { continue; }
        $name = substr($class, strlen($prefix));
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $name)) { return; }
        $file = $directory.'/'.$name.'.php';
        if (is_file($file)) { require_once $file; }
        return;
    }
});

<?php

/**
 * Child process for SourceFilesLoadTest: loads the PHP files given as arguments with require_once, as Doctrine's
 * annotation driver does with every file under the package's src/ when Concrete builds its entity metadata.
 *
 * Only autoloaders: Concrete's (as its bootstrap registers them) and then the package's, as the package controller
 * does. No boot, no database. A file that can't load is usually a fatal error (a missing parent class or interface),
 * which can't be caught, so the shutdown function reports the file being loaded at the time.
 *
 * Prints "LOADED <count>" when every file loaded, otherwise "FAILED <file>: <message>".
 */

declare(strict_types=1);

$siteDir = dirname(__DIR__, 4);
$packageDir = dirname(__DIR__, 2);
$files = array_slice($argv, 1);
$current = null;

register_shutdown_function(static function () use (&$current): void {
    $error = error_get_last();
    $fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR;
    if ($current !== null && $error !== null && ($error['type'] & $fatal) !== 0) {
        echo 'FAILED ' . $current . ': ' . $error['message'], PHP_EOL;
    }
});

define('DIR_BASE', str_replace(DIRECTORY_SEPARATOR, '/', $siteDir));
require $siteDir . '/concrete/bootstrap/configure.php';
require $siteDir . '/concrete/bootstrap/autoload.php';
require_once $packageDir . '/vendor/autoload.php';

foreach ($files as $file) {
    $current = $file;
    try {
        require_once $file;
    } catch (\Throwable $e) {
        $current = null;
        echo 'FAILED ' . $file . ': ' . $e->getMessage(), PHP_EOL;
        exit(1);
    }
}
$current = null;

echo 'LOADED ' . count($files), PHP_EOL;

<?php

declare(strict_types=1);

/*
 * Throwaway installation for the end-to-end tests: fresh config, database and
 * password in e2e/.run/ (gitignored). Started by playwright.config.js before the server.
 */

require __DIR__ . '/../src/bootstrap.php';

use App\App;
use App\Config;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$dir = __DIR__ . '/.run';
$port = (int) ($argv[1] ?? 8765);

// Start from scratch every run.
if (is_dir($dir)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
}
mkdir("$dir/data", 0700, true);

$values = [
    'env' => 'dev',
    'base_url' => "http://127.0.0.1:$port",
    'contact_email' => 'e2e@example.test',
    'timezone' => 'Europe/Berlin',
    'data_dir' => "$dir/data",
    'db_path' => "$dir/data/app.sqlite",
];
Config::write($values, "$dir/config.php");

$app = new App(Config::fromArray($values), installed: true);
$app->migrator()->migrate();
$app->auth()->setPassword('e2e-password-123');

echo "e2e install ready in $dir\n";

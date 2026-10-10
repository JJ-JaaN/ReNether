<?php
declare(strict_types=1);

/**
 * Embed Composer production dependencies (including path-repository symlinks)
 * into a DevTools-built PocketMine plugin PHAR. NetherGames plugins load
 * phar://<plugin>/vendor/autoload.php at runtime.
 *
 * This file contains independent build glue, not vendored plugin source.
 */
if ($argc !== 3) {
    fwrite(STDERR, "Usage: php ensure_phar_vendor.php <plugin-source-dir> <plugin.phar>\n");
    exit(2);
}
[, $source, $pharPath] = $argv;
$source = realpath($source);
if ($source === false || !is_file($source . '/vendor/autoload.php')) {
    fwrite(STDERR, "ERROR: Composer vendor/autoload.php is missing. Check Composer install output.\n");
    exit(1);
}
if (!is_file($pharPath)) {
    fwrite(STDERR, "ERROR: Plugin PHAR not found: $pharPath\n");
    exit(1);
}
$vendorPath = $source . '/vendor';
$phar = new Phar($pharPath);
if (!isset($phar['plugin.yml'])) {
    fwrite(STDERR, "ERROR: Invalid plugin PHAR: plugin.yml missing.\n");
    exit(1);
}
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
    $vendorPath, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS
));
$added = 0;
$phar->startBuffering();
foreach ($files as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $relative = substr($file->getPathname(), strlen($vendorPath) + 1);
    if (preg_match('~(?:^|/|\\\\)\\.git(?:/|\\\\|$)~', $relative)) {
        continue;
    }
    $member = 'vendor/' . str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    $phar->addFile($file->getPathname(), $member);
    ++$added;
}
$phar->stopBuffering();
if (!isset($phar['vendor/autoload.php'])) {
    fwrite(STDERR, "ERROR: PHAR still has no vendor/autoload.php.\n");
    exit(1);
}
echo "Packaged $added Composer vendor files into " . basename($pharPath) . "\n";

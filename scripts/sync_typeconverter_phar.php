<?php
declare(strict_types=1);

/**
 * Keep the TypeConverter compatibility source in a previously built Axolotl
 * PocketMine-MP PHAR in sync, without recompiling all third-party plugins.
 * The upstream checkout is not overwritten by this helper.
 */
if ($argc !== 3) {
    fwrite(STDERR, "Usage: php -dphar.readonly=0 sync_typeconverter_phar.php <patched-TypeConverter.php> <PocketMine-MP.phar>\n");
    exit(2);
}
[, $sourcePath, $pharPath] = $argv;
$source = @file_get_contents($sourcePath);
if ($source === false || !str_contains($source, 'private static array $compatCreationListeners')) {
    fwrite(STDERR, "ERROR: Patched TypeConverter source not found or missing compatibility methods.\n");
    exit(1);
}
if (!is_file($pharPath)) {
    fwrite(STDERR, "ERROR: PocketMine PHAR not found: $pharPath\n");
    exit(1);
}
$member = 'src/network/mcpe/convert/TypeConverter.php';
$phar = new Phar($pharPath);
if (!isset($phar[$member])) {
    fwrite(STDERR, "ERROR: $member missing from PocketMine PHAR.\n");
    exit(1);
}
if ($phar[$member]->getContent() === $source) {
    echo "[new-ng] Axolotl TypeConverter PHAR already up to date.\n";
    exit(0);
}
$phar->startBuffering();
$phar->addFromString($member, $source);
$phar->stopBuffering();
if ($phar[$member]->getContent() !== $source) {
    fwrite(STDERR, "ERROR: TypeConverter PHAR synchronization failed.\n");
    exit(1);
}
echo "[new-ng] Synchronized Axolotl TypeConverter compatibility APIs into PocketMine-MP.phar.\n";

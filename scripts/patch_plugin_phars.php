<?php
declare(strict_types=1);

/**
 * Temporary compatibility adjustment for NetherGames libVanilla tile classes.
 * Their two-argument override matches NetherGames PocketMine, but Axolotl-PM
 * 5.49.1 declares Spawnable::addAdditionalSpawnData(CompoundTag $nbt).
 * Both affected methods ignore the old TypeConverter argument, so dropping
 * that unused parameter preserves their behavior on Axolotl.
 *
 * Original upstream source is left unchanged; only locally built PHARs are edited.
 */
if ($argc < 2) {
    fwrite(STDERR, "Usage: php -dphar.readonly=0 patch_plugin_phars.php <plugin.phar>...\n");
    exit(2);
}

$classes = ['TileDecoratedPot', 'TileCopperGolemStatue'];
$oldSignature = 'addAdditionalSpawnData(CompoundTag $nbt, TypeConverter $typeConverter): void';
$newSignature = 'addAdditionalSpawnData(CompoundTag $nbt): void';

foreach (array_slice($argv, 1) as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "ERROR: Missing plugin PHAR: $path\n");
        exit(1);
    }
    $phar = new Phar($path);
    $updated = [];
    $found = 0;
    foreach ($classes as $class) {
        $member = "vendor/nethergamesmc/libvanilla/block/tile/$class.php";
        if (!isset($phar[$member])) {
            continue;
        }
        ++$found;
        $content = $phar[$member]->getContent();
        if (str_contains($content, $newSignature)) {
            echo "[new-ng] Already compatible: " . basename($path) . " / $class\n";
            continue;
        }
        if (substr_count($content, $oldSignature) !== 1) {
            fwrite(STDERR, "ERROR: Unexpected libVanilla signature for $member, refusing change.\n");
            exit(1);
        }
        $updated[$member] = str_replace($oldSignature, $newSignature, $content);
    }
    if (basename($path) === 'NGEssentials.phar' && $found !== count($classes)) {
        fwrite(STDERR, "ERROR: Expected both libVanilla tile classes in NGEssentials PHAR; found $found.\n");
        exit(1);
    }
    if ($updated !== []) {
        $phar->startBuffering();
        foreach ($updated as $member => $content) {
            $phar->addFromString($member, $content);
            echo "[new-ng] Patched Axolotl tile signature: " . basename($path) . " / " . basename($member) . "\n";
        }
        $phar->stopBuffering();
    }
}

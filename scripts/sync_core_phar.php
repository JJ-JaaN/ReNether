<?php
declare(strict_types=1);

/**
 * Synchronize known compatibility-patched Axolotl source files into a locally
 * built PocketMine-MP PHAR without forcing a full Composer rebuild.
 * The upstream source checkout is left intact.
 */
if ($argc !== 3) {
    fwrite(STDERR, "Usage: php -dphar.readonly=0 sync_core_phar.php <axolotl-source-root> <PocketMine-MP.phar>\n");
    exit(2);
}
[, $sourceRoot, $pharPath] = $argv;
$sourceRoot = realpath($sourceRoot);
if ($sourceRoot === false || !is_file($pharPath)) {
    fwrite(STDERR, "ERROR: Axolotl source root or PocketMine PHAR is missing.\n");
    exit(1);
}
$required = [
    'src/network/mcpe/convert/TypeConverter.php' => [
        'private static array $compatCreationListeners',
        'public static function getAll()',
    ],
    'src/Server.php' => [
        'private bool $hasForceShutdown = false;',
        'public function hasForceShutdown()',
        '$this->hasForceShutdown = true;',
    ],
    // Legacy NetherGames maps predate the 1.26.50 stair corner property.
    // Normalize only old stair palettes that lack this extra property.
    'src/data/bedrock/block/convert/BlockStateToObjectDeserializer.php' => [
        'new-ng: restore pre-1.26.50 stair palette states',
    ],
    // Keep the vanilla explosion ray-tracing algorithm intact while allowing
    // NetherGames BedWars' subclass to call the inherited exposure helper.
    'src/world/Explosion.php' => [
        'protected function getExposure(Vector3 $origin, Entity $entity) : float{',
    ],
    'src/item/ItemTypeIds.php' => [
        'public const CROSSBOW = 20326;',
        'public const ELYTRA = 20327;',
        'public const SHIELD = 20328;',
        'public const FIRST_UNUSED_ITEM_ID = 20329;',
    ],
];
$phar = new Phar($pharPath);
$updates = [];
foreach ($required as $member => $markers) {
    $source = @file_get_contents($sourceRoot . '/' . $member);
    if ($source === false || !isset($phar[$member])) {
        fwrite(STDERR, "ERROR: Missing source or PHAR member: $member\n");
        exit(1);
    }
    foreach ($markers as $marker) {
        if (!str_contains($source, $marker)) {
            fwrite(STDERR, "ERROR: Required compatibility patch missing in source: $member ($marker)\n");
            exit(1);
        }
    }
    if ($phar[$member]->getContent() !== $source) {
        $updates[$member] = $source;
    }
}
if ($updates !== []) {
    $phar->startBuffering();
    foreach ($updates as $member => $source) {
        $phar->addFromString($member, $source);
        echo "[ReNether] Synced core compatibility: $member\n";
    }
    $phar->stopBuffering();
} else {
    echo "[ReNether] Axolotl core compatibility already up to date.\n";
}

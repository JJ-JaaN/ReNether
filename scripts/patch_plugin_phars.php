<?php
declare(strict_types=1);

/**
 * Temporary compatibility adjustment for NetherGames libVanilla tile classes.
 * Their two-argument override matches NetherGames PocketMine, but Axolotl-PM
 * 5.49.1 declares Spawnable::addAdditionalSpawnData(CompoundTag $nbt).
 * Both affected methods ignore the old TypeConverter argument, so dropping
 * that unused parameter preserves their behavior on Axolotl.
 *
 * Also replaces the single libVanilla BlockBreakInfo::hoe() call with its
 * equivalent Axolotl-compatible BlockBreakInfo construction, and aligns
 * libReplay packet methods with Axolotl's single-protocol DataPacket ABI.
 * NGEssentials login protocol acceptance is restricted to the current protocol.
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
    // Axolotl-PM has BlockToolType::HOE, but lacks the BBI::hoe() factory.
    // BBI::hoe(0.1) uses the same defaults as new BBI(0.1, BlockToolType::HOE).
    $member = 'vendor/nethergamesmc/libvanilla/features/MissingVanillaBlocks.php';
    if (isset($phar[$member])) {
        $content = $phar[$member]->getContent();
        $before = 'BBI::hoe(0.1)';
        $after = 'new BBI(0.1, BlockToolType::HOE)';
        if (str_contains($content, $after)) {
            echo "[new-ng] Already compatible: " . basename($path) . " / BlockBreakInfo hoe()\n";
        } elseif (substr_count($content, $before) === 1) {
            $updated[$member] = str_replace($before, $after, $content);
        } else {
            fwrite(STDERR, "ERROR: Unexpected BlockBreakInfo hoe() usage in $member; refusing change.\n");
            exit(1);
        }
    } elseif (basename($path) === 'NGEssentials.phar') {
        fwrite(STDERR, "ERROR: Missing libVanilla MissingVanillaBlocks from NGEssentials PHAR.\n");
        exit(1);
    }
    // NetherGames BedrockProtocol passes an explicit protocol ID to
    // DataPacket::decodePayload()/encodePayload(). Axolotl's DataPacket
    // doesn't, and these two libReplay packet implementations do not use it.
    foreach (['BlockChangePacket', 'PlayerInformationPacket'] as $packet) {
        $member = "vendor/nethergamesmc/libreplay/protocol/$packet.php";
        if (!isset($phar[$member])) {
            continue;
        }
        $content = $phar[$member]->getContent();
        $pairs = [
            'decodePayload(ByteBufferReader $in, int $protocolId): void' =>
                'decodePayload(ByteBufferReader $in): void',
            'encodePayload(ByteBufferWriter $out, int $protocolId): void' =>
                'encodePayload(ByteBufferWriter $out): void',
        ];
        $newContent = $content;
        foreach ($pairs as $before => $after) {
            if (str_contains($newContent, $after)) {
                continue;
            }
            if (substr_count($newContent, $before) !== 1) {
                fwrite(STDERR, "ERROR: Unexpected $packet method signature, refusing change.\n");
                exit(1);
            }
            $newContent = str_replace($before, $after, $newContent);
        }
        if ($newContent !== $content) {
            $updated[$member] = $newContent;
        } else {
            echo "[new-ng] Already compatible: " . basename($path) . " / $packet\n";
        }
    }
    // NetherGames PocketMine supports multiple protocols and exposes
    // ProtocolInfo::ACCEPTED_PROTOCOL; Axolotl only supports CURRENT_PROTOCOL.
    // Keep strict protocol validation and avoid referencing the absent constant.
    $member = 'src/NetherGames/NGEssentials/EventListener.php';
    if (isset($phar[$member])) {
        $content = $phar[$member]->getContent();
        $before = 'ProtocolInfo::ACCEPTED_PROTOCOL';
        $after = '[ProtocolInfo::CURRENT_PROTOCOL]';
        if (str_contains($content, $before)) {
            if (substr_count($content, $before) !== 1) {
                fwrite(STDERR, "ERROR: Unexpected count of protocol acceptance checks in $member.\n");
                exit(1);
            }
            $updated[$member] = str_replace($before, $after, $content);
        } elseif (!str_contains($content, $after)) {
            fwrite(STDERR, "ERROR: Protocol validation signature unexpected in $member.\n");
            exit(1);
        } else {
            echo "[new-ng] Already compatible: " . basename($path) . " / LoginPacket protocol check\n";
        }
    } elseif (basename($path) === 'NGEssentials.phar') {
        fwrite(STDERR, "ERROR: Missing NGEssentials EventListener in PHAR.\n");
        exit(1);
    }
    if (basename($path) === 'NGEssentials.phar' && $found !== count($classes)) {
        fwrite(STDERR, "ERROR: Expected both libVanilla tile classes in NGEssentials PHAR; found $found.\n");
        exit(1);
    }
    if ($updated !== []) {
        $phar->startBuffering();
        foreach ($updated as $member => $content) {
            $phar->addFromString($member, $content);
            echo "[new-ng] Patched plugin PHAR: " . basename($path) . " / " . basename($member) . "\n";
        }
        $phar->stopBuffering();
    }
}

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
 * Duplicate player-creation callbacks are ignored to preserve the first error.
 * Historical protocol gates retain NetherGames' original numeric thresholds.
 * Fence blockstates and full-chunk serialization use Axolotl's APIs.
 * SkinData trusted flag and LevelChunkPacket signature match Bedrock 26.50.
 * AddPlayerPacket NPC abilities now pass AbilitiesData directly.
 * PlayerListPacket operations use typed entries and ::create().
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
    // PlayerData::loadPlayerData() may invoke its completion callback again
    // if the PromiseResolver's first onSuccess listener throws. Avoid turning
    // the original exception into an additional double-resolution crash.
    // This does NOT conceal or fix the original exception, which is logged
    // by PlayerData's error handler.
    $member = 'src/NetherGames/NGEssentials/EventListener.php';
    if (isset($phar[$member])) {
        $content = $updated[$member] ?? $phar[$member]->getContent();
        $before = <<<'PHP'
        $callback = function (bool $success) use ($resolver): void {
            $resolver->resolve(null);
        };
PHP;
        $after = <<<'PHP'
        $resolved = false;
        $callback = function (bool $success) use ($resolver, &$resolved): void {
            if ($resolved) {
                return;
            }
            $resolved = true;
            $resolver->resolve(null);
        };
PHP;
        if (str_contains($content, $after)) {
            echo "[new-ng] Already compatible: " . basename($path) . " / player creation promise guard\n";
        } elseif (substr_count($content, $before) === 1) {
            $updated[$member] = str_replace($before, $after, $content);
        } else {
            fwrite(STDERR, "ERROR: Unexpected player creation callback in $member; refusing change.\n");
            exit(1);
        }
    } elseif (basename($path) === 'NGEssentials.phar') {
        fwrite(STDERR, "ERROR: Missing NGEssentials EventListener in PHAR.\n");
        exit(1);
    }
    // NetherGames' BedrockProtocol defines historical PROTOCOL_1_* constants;
    // Axolotl's one-protocol BedrockProtocol doesn't. Preserve the actual
    // upstream protocol numeric thresholds rather than bypassing version gates.
    // This does not add multiversion compatibility.
    $historicalProtocols = [
        'ProtocolInfo::PROTOCOL_1_20_10' => '594',
        'ProtocolInfo::PROTOCOL_1_20_50' => '630',
        'ProtocolInfo::PROTOCOL_1_20_60' => '649',
        'ProtocolInfo::PROTOCOL_1_21_50' => '766',
        'ProtocolInfo::PROTOCOL_1_21_60' => '776',
        'ProtocolInfo::PROTOCOL_1_21_70' => '786',
        'ProtocolInfo::PROTOCOL_1_21_90' => '818',
    ];
    $protocolMembers = [
        'src/NetherGames/NGEssentials/EventListener.php',
        'src/NetherGames/NGEssentials/player/NGPlayer.php',
        'src/NetherGames/NGEssentials/item/component/IconComponent.php',
        'vendor/nethergamesmc/libvanilla/listener/ElytraListener.php',
        'vendor/nethergamesmc/libforms/CustomForm.php',
        'vendor/nethergamesmc/libreplay/session/replay/Replay.php',
    ];
    foreach ($protocolMembers as $member) {
        if (!isset($phar[$member])) {
            if (basename($path) === 'NGEssentials.phar' && str_starts_with($member, 'src/NetherGames/NGEssentials/')) {
                fwrite(STDERR, "ERROR: Missing NGEssentials protocol member: $member\n");
                exit(1);
            }
            continue;
        }
        $content = $updated[$member] ?? $phar[$member]->getContent();
        $newContent = str_replace(array_keys($historicalProtocols), array_values($historicalProtocols), $content);
        if (preg_match('/ProtocolInfo::PROTOCOL_[A-Z0-9_]+/', $newContent, $match)) {
            fwrite(STDERR, "ERROR: Unmapped protocol version $match[0] in $member.\n");
            exit(1);
        }
        if ($newContent !== $content) {
            $updated[$member] = $newContent;
        }
    }
    // libVanilla registers BambooFence (a pocketmine\block\Fence) using
    // mapSimple(), yielding an invalid empty-state blockstate. Modern Bedrock
    // palettes require the four horizontal connection flags. Register fences
    // with Axolotl's existing property model instead of masking missing IDs.
    $member = 'vendor/nethergamesmc/libvanilla/utils/BlockRegistrationHelper.php';
    if (isset($phar[$member])) {
        $content = $updated[$member] ?? $phar[$member]->getContent();
        $beforeImport = 'use pocketmine\block\FenceGate;';
        $afterImport = "use pocketmine\\block\\Fence;\n" . $beforeImport;
        $beforeMapping = <<<'PHP'
        } elseif ($block instanceof FenceGate) {
            $registrar->mapModel(Model::create($block, $id)->properties($commonProps->fenceGateProperties));
PHP;
        $afterMapping = <<<'PHP'
        } elseif ($block instanceof Fence) {
            $registrar->mapModel(Model::create($block, $id)->properties($commonProps->horizontalConnectionProperties));
        } elseif ($block instanceof FenceGate) {
            $registrar->mapModel(Model::create($block, $id)->properties($commonProps->fenceGateProperties));
PHP;
        if (str_contains($content, $afterMapping) && str_contains($content, $afterImport)) {
            echo "[new-ng] Already compatible: " . basename($path) . " / Fence blockstates\n";
        } elseif (substr_count($content, $beforeMapping) === 1 && substr_count($content, $beforeImport) === 1) {
            $content = str_replace($beforeImport, $afterImport, $content);
            $content = str_replace($beforeMapping, $afterMapping, $content);
            $updated[$member] = $content;
        } else {
            fwrite(STDERR, "ERROR: Unexpected libVanilla fence registration in $member; refusing change.\n");
            exit(1);
        }
    } elseif (basename($path) === 'NGEssentials.phar') {
        fwrite(STDERR, "ERROR: Missing libVanilla BlockRegistrationHelper in NGEssentials PHAR.\n");
        exit(1);
    }

    // Axolotl 5.49.1 ChunkSerializer expects BlockTranslator instead of
    // NetherGames' TypeConverter. The latter exposes the former directly.
    $member = 'src/NetherGames/NGEssentials/player/NGPlayer.php';
    if (isset($phar[$member])) {
        $content = $updated[$member] ?? $phar[$member]->getContent();
        $before = <<<'PHP'
$emptyChunk = ChunkSerializer::serializeFullChunk(
                $chunk = new Chunk([], true),
                $dimensionId,
                $session->getTypeConverter(),
PHP;
        $after = <<<'PHP'
$emptyChunk = ChunkSerializer::serializeFullChunk(
                $chunk = new Chunk([], true),
                $dimensionId,
                $session->getTypeConverter()->getBlockTranslator(),
PHP;
        if (str_contains($content, $after)) {
            echo "[new-ng] Already compatible: " . basename($path) . " / ChunkSerializer translator\n";
        } elseif (substr_count($content, $before) === 1) {
            $updated[$member] = str_replace($before, $after, $content);
        } else {
            fwrite(STDERR, "ERROR: Unexpected NGPlayer chunk serialization in $member; refusing change.\n");
            exit(1);
        }
    } elseif (basename($path) === 'NGEssentials.phar') {
        fwrite(STDERR, "ERROR: Missing NGPlayer in NGEssentials PHAR.\n");
        exit(1);
    }
    // NetherGames SkinData::setVerified() no longer exists in Axolotl's
    // BedrockProtocol. LegacySkinAdapter already constructs SkinData with
    // trustedSkinFlag=TRUSTED_SKIN_TRUE by default. Remove the obsolete setter.
    $member = 'src/NetherGames/NGEssentials/utils/skins/SkinValidatorAdapter.php';
    if (isset($phar[$member])) {
        $content = $updated[$member] ?? $phar[$member]->getContent();
        $before = <<<'PHP'
        $skinData = parent::toSkinData($skin);
        $skinData->setVerified(true);

        return $skinData;
PHP;
        $after = <<<'PHP'
        $skinData = parent::toSkinData($skin);

        return $skinData;
PHP;
        if (str_contains($content, $after) && !str_contains($content, '->setVerified(')) {
            echo "[new-ng] Already compatible: " . basename($path) . " / SkinData trusted flag\n";
        } elseif (substr_count($content, $before) === 1) {
            $updated[$member] = str_replace($before, $after, $content);
        } else {
            fwrite(STDERR, "ERROR: Unexpected SkinValidatorAdapter verification setter in $member; refusing change.\n");
            exit(1);
        }
    } elseif (basename($path) === 'NGEssentials.phar') {
        fwrite(STDERR, "ERROR: Missing SkinValidatorAdapter in NGEssentials PHAR.\n");
        exit(1);
    }

    // Bedrock 26.50 requires a nullable subchunk-request limit followed by
    // cacheEnabled and an array of blob hashes (seven arguments total).
    // The NetherGames empty-chunk packet used the previous six-argument ABI.
    $member = 'src/NetherGames/NGEssentials/player/NGPlayer.php';
    if (isset($phar[$member])) {
        $content = $updated[$member] ?? $phar[$member]->getContent();
        $before = <<<'PHP'
                    $session->sendDataPacket(LevelChunkPacket::create(
                        new ChunkPosition($X, $Z),
                        $dimensionId,
                        $subChunkCount,
                        false,
                        null,
                        $emptyChunk
                    ));
PHP;
        $after = <<<'PHP'
                    $session->sendDataPacket(LevelChunkPacket::create(
                        new ChunkPosition($X, $Z),
                        $dimensionId,
                        $subChunkCount,
                        null,
                        false,
                        [],
                        $emptyChunk
                    ));
PHP;
        if (str_contains($content, $after)) {
            echo "[new-ng] Already compatible: " . basename($path) . " / LevelChunkPacket arguments\n";
        } elseif (substr_count($content, $before) === 1) {
            $updated[$member] = str_replace($before, $after, $content);
        } else {
            fwrite(STDERR, "ERROR: Unexpected empty LevelChunkPacket in $member; refusing change.\n");
            exit(1);
        }
    } elseif (basename($path) === 'NGEssentials.phar') {
        fwrite(STDERR, "ERROR: Missing NGPlayer in NGEssentials PHAR.\n");
        exit(1);
    }
    // NetherGames' AddPlayerPacket::create() accepted UpdateAbilitiesPacket,
    // whereas Axolotl's Bedrock 26.50 API requires the enclosed AbilitiesData.
    // Both human NPC spawning paths use the same obsolete wrapper; unwrap
    // exactly the AddPlayerPacket argument without changing other packet uses.
    foreach ([
        'src/NetherGames/NGEssentials/entity/custom/CustomHuman.php',
        'src/NetherGames/NGEssentials/entity/CustomGeometryTrait.php',
    ] as $member) {
        if (!isset($phar[$member])) {
            if (basename($path) === 'NGEssentials.phar') {
                fwrite(STDERR, "ERROR: Missing human NPC compatibility member: $member\n");
                exit(1);
            }
            continue;
        }
        $content = $updated[$member] ?? $phar[$member]->getContent();
        $before = 'UpdateAbilitiesPacket::create(new AbilitiesData(';
        $after = 'new AbilitiesData(';
        $beforeClosing = <<<'PHP'
            ])),
PHP;
        $afterClosing = <<<'PHP'
            ]),
PHP;
        if (str_contains($content, $before)) {
            if (substr_count($content, $before) !== 1 || substr_count($content, $beforeClosing) !== 1) {
                fwrite(STDERR, "ERROR: Unexpected AddPlayerPacket abilities block in $member.\n");
                exit(1);
            }
            $content = str_replace($before, $after, $content);
            $content = str_replace($beforeClosing, $afterClosing, $content);
            $updated[$member] = $content;
        } elseif (str_contains($content, $after) && str_contains($content, $afterClosing)) {
            echo "[new-ng] Already compatible: " . basename($path) . " / NPC abilities " . basename($member) . "\n";
        } else {
            fwrite(STDERR, "ERROR: Missing expected NPC abilities block in $member.\n");
            exit(1);
        }
    }
    // Axolotl's PlayerListPacket (Bedrock 26.50) encodes mixed per-entry
    // add/remove types via ::create(array $entries). NetherGames' older
    // ::add()/::remove() wrappers no longer exist. Removal entries must
    // actually be TYPE_REMOVE, not reused TYPE_ADD records.
    $playerListPatches = [
        'src/NetherGames/NGEssentials/entity/EntityManager.php' => [
            'PlayerListPacket::add($entries)' =>
                'PlayerListPacket::create($entries)',
            'PlayerListPacket::remove($entries)' =>
                'PlayerListPacket::create(array_map(static fn(PlayerListEntry $entry): PlayerListEntry => PlayerListEntry::createRemovalEntry($entry->uuid), $entries))',
        ],
        'src/NetherGames/NGEssentials/entity/custom/CustomHuman.php' => [
            'PlayerListPacket::add([$entry = $this->getListEntry($networkSession->getTypeConverter())])' =>
                'PlayerListPacket::create([$entry = $this->getListEntry($networkSession->getTypeConverter())])',
            'PlayerListPacket::remove([$entry])' =>
                'PlayerListPacket::create([PlayerListEntry::createRemovalEntry($entry->uuid)])',
        ],
        'src/NetherGames/NGEssentials/entity/CustomGeometryTrait.php' => [
            'PlayerListPacket::add([' => 'PlayerListPacket::create([',
            'PlayerListPacket::remove([PlayerListEntry::createRemovalEntry(' =>
                'PlayerListPacket::create([PlayerListEntry::createRemovalEntry(',
        ],
        'vendor/nethergamesmc/libreplay/session/record/utils/Filmroll.php' => [
            'PlayerListPacket::add([' => 'PlayerListPacket::create([',
            'PlayerListPacket::remove([PlayerListEntry::createRemovalEntry(' =>
                'PlayerListPacket::create([PlayerListEntry::createRemovalEntry(',
        ],
    ];
    foreach ($playerListPatches as $member => $replacements) {
        if (!isset($phar[$member])) {
            if (basename($path) === 'NGEssentials.phar' && str_starts_with($member, 'src/NetherGames/NGEssentials/')) {
                fwrite(STDERR, "ERROR: Missing player-list compatibility member: $member\n");
                exit(1);
            }
            continue;
        }
        $content = $updated[$member] ?? $phar[$member]->getContent();
        $newContent = $content;
        foreach ($replacements as $before => $after) {
            if (str_contains($newContent, $before)) {
                if (substr_count($newContent, $before) !== 1) {
                    fwrite(STDERR, "ERROR: Unexpected player-list method count in $member: $before\n");
                    exit(1);
                }
                $newContent = str_replace($before, $after, $newContent);
            } elseif (!str_contains($newContent, $after)) {
                fwrite(STDERR, "ERROR: Unexpected player-list invocation in $member: $before\n");
                exit(1);
            }
        }
        if (preg_match('/PlayerListPacket::(?:add|remove)\s*\(/', $newContent)) {
            fwrite(STDERR, "ERROR: Obsolete PlayerListPacket method remains in $member.\n");
            exit(1);
        }
        if ($newContent !== $content) {
            $updated[$member] = $newContent;
        } else {
            echo "[new-ng] Already compatible: " . basename($path) . " / PlayerListPacket " . basename($member) . "\n";
        }
    }

    // Axolotl BedrockProtocol 26.50 uses per-entry scoreboard actions.
    // NetherGames NGEssentials still uses packet-level TYPE_REMOVE / TYPE_CHANGE.
    // Patch the PHAR in-place, preserving the old 'remove then change' behavior.
    $member = 'src/NetherGames/NGEssentials/utils/scoreboard/Scoreboard.php';
    if (isset($phar[$member])) {
        $content = $updated[$member] ?? $phar[$member]->getContent();
        $newSignature = 'public function getPacket(array $lines, ScorePacketEntryAction $type): ClientboundPacket';
        if (str_contains($content, $newSignature)) {
            if (!str_contains($content, 'use pocketmine\network\mcpe\protocol\types\ScorePacketEntryAction;') ||
                !str_contains($content, '$entry->action = $type;') ||
                !str_contains($content, 'return SetScorePacket::create($entries);') ||
                str_contains($content, 'SetScorePacket::TYPE_') ||
                str_contains($content, 'ScorePacketEntry::TYPE_FAKE_PLAYER')) {
                fwrite(STDERR, "ERROR: Incomplete Scoreboard API patch in $member.\n");
                exit(1);
            }
            echo "[new-ng] Already compatible: " . basename($path) . " / Scoreboard actions\n";
        } else {
            $replacements = [
                'use pocketmine\network\mcpe\protocol\types\ScorePacketEntry;' =>
                    "use pocketmine\\network\\mcpe\\protocol\\types\\ScorePacketEntry;\nuse pocketmine\\network\\mcpe\\protocol\\types\\ScorePacketEntryAction;",
                '$this->getPacket($lines, SetScorePacket::TYPE_REMOVE)' =>
                    '$this->getPacket($lines, ScorePacketEntryAction::REMOVE)',
                '$this->getPacket($lines, SetScorePacket::TYPE_CHANGE)' =>
                    '$this->getPacket($lines, ScorePacketEntryAction::CHANGE_FAKE_PLAYER)',
                'public function getPacket(array $lines, int $type): ClientboundPacket' =>
                    $newSignature,
                '            $entry->scoreboardId = $line;' =>
                    "            \$entry->scoreboardId = \$line;\n            \$entry->action = \$type;",
                'if ($type === SetScorePacket::TYPE_CHANGE) {' =>
                    'if ($type === ScorePacketEntryAction::CHANGE_FAKE_PLAYER) {',
                '                $entry->type = ScorePacketEntry::TYPE_FAKE_PLAYER;' . "\n" =>
                    '',
                "        return SetScorePacket::create(\n            \$type,\n            \$entries\n        );" =>
                    '        return SetScorePacket::create($entries);',
            ];
            foreach ($replacements as $before => $after) {
                if (substr_count($content, $before) !== 1) {
                    fwrite(STDERR, "ERROR: Unexpected Scoreboard API pattern in $member: " . $before . "\n");
                    exit(1);
                }
                $content = str_replace($before, $after, $content);
            }
            $updated[$member] = $content;
        }
    } elseif (basename($path) === 'NGEssentials.phar') {
        fwrite(STDERR, "ERROR: Missing scoreboard source in NGEssentials PHAR.\n");
        exit(1);
    }


    // Axolotl BedrockProtocol 26.50 PlaySoundPacket::create() requires
    // loopCount (int), bypassListenerRangeCheck (bool), serverSoundHandle (?int),
    // and playbackPositionSeconds (?float). The NetherGames plugin supplied
    // only a legacy nullable seventh argument. Use default, non-looping sound
    // settings without changing the original sound, location, volume or pitch.
    $member = 'src/NetherGames/NGEssentials/player/NGPlayer.php';
    if (isset($phar[$member])) {
        $content = $updated[$member] ?? $phar[$member]->getContent();
        $before = <<<'PHP'
        $this->getNetworkSession()->sendDataPacket(PlaySoundPacket::create(
            $sound,
            $location->x,
            $location->y,
            $location->z,
            $volume,
            $pitch,
            null
        ));
PHP;
        $after = <<<'PHP'
        $this->getNetworkSession()->sendDataPacket(PlaySoundPacket::create(
            $sound,
            $location->x,
            $location->y,
            $location->z,
            $volume,
            $pitch,
            0,
            false,
            null,
            null
        ));
PHP;
        if (str_contains($content, $after)) {
            echo "[new-ng] Already compatible: " . basename($path) . " / PlaySoundPacket arguments\n";
        } elseif (substr_count($content, $before) === 1) {
            $updated[$member] = str_replace($before, $after, $content);
        } else {
            fwrite(STDERR, "ERROR: Unexpected PlaySoundPacket call in $member; refusing change.\n");
            exit(1);
        }
    } elseif (basename($path) === 'NGEssentials.phar') {
        fwrite(STDERR, "ERROR: Missing NGPlayer source for PlaySoundPacket compatibility.\n");
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

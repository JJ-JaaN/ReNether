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
            echo "[ReNether] Already compatible: " . basename($path) . " / $class\n";
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
            echo "[ReNether] Already compatible: " . basename($path) . " / BlockBreakInfo hoe()\n";
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
            echo "[ReNether] Already compatible: " . basename($path) . " / $packet\n";
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
            echo "[ReNether] Already compatible: " . basename($path) . " / LoginPacket protocol check\n";
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
            echo "[ReNether] Already compatible: " . basename($path) . " / player creation promise guard\n";
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
            echo "[ReNether] Already compatible: " . basename($path) . " / Fence blockstates\n";
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
            echo "[ReNether] Already compatible: " . basename($path) . " / ChunkSerializer translator\n";
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
            echo "[ReNether] Already compatible: " . basename($path) . " / SkinData trusted flag\n";
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
            echo "[ReNether] Already compatible: " . basename($path) . " / LevelChunkPacket arguments\n";
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
            echo "[ReNether] Already compatible: " . basename($path) . " / NPC abilities " . basename($member) . "\n";
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
            echo "[ReNether] Already compatible: " . basename($path) . " / PlayerListPacket " . basename($member) . "\n";
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
            echo "[ReNether] Already compatible: " . basename($path) . " / Scoreboard actions\n";
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
            echo "[ReNether] Already compatible: " . basename($path) . " / PlaySoundPacket arguments\n";
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


    // NetherGames' InvMenu still references the removed historical
    // ProtocolInfo::PROTOCOL_1_20_10 constant. Its exact protocol ID is 594.
    // Keep the original threshold-dependent timestamp scaling (two cases)
    // instead of bypassing version checks. Axolotl accepts only current 2193.
    $member = 'vendor/nethergamesmc/invmenu/src/muqsit/invmenu/session/network/handler/PlayerNetworkHandlerRegistry.php';
    if (isset($phar[$member])) {
        $content = $updated[$member] ?? $phar[$member]->getContent();
        $before = 'ProtocolInfo::PROTOCOL_1_20_10';
        $after = '594';
        $count = substr_count($content, $before);
        if ($count === 2) {
            $patched = str_replace($before, $after, $content);
            if (substr_count($patched, '$protocolId >= 594') !== 2 ||
                str_contains($patched, $before)) {
                fwrite(STDERR, "ERROR: Unexpected InvMenu protocol threshold structure in $member.\n");
                exit(1);
            }
            $updated[$member] = $patched;
        } elseif ($count === 0 && substr_count($content, '$protocolId >= 594') === 2) {
            echo "[ReNether] Already compatible: " . basename($path) . " / InvMenu historical protocol threshold\n";
        } else {
            fwrite(STDERR, "ERROR: Unexpected InvMenu historical protocol constant count in $member: $count.\n");
            exit(1);
        }
    } elseif (basename($path) === 'xBedwars.phar') {
        fwrite(STDERR, "ERROR: Missing InvMenu player network registry in xBedwars PHAR.\n");
        exit(1);
    }


    // Axolotl 5.49.1 World::createBlockUpdatePackets() accepts only the
    // positions array. The legacy NetherGames InvMenu fork still passes
    // TypeConverter as its first argument when removing fake menu blocks.
    // Keep the block restoration packets; only remove the obsolete argument.
    $member = 'vendor/nethergamesmc/invmenu/src/muqsit/invmenu/type/graphic/BlockInvMenuGraphic.php';
    if (isset($phar[$member])) {
        $content = $updated[$member] ?? $phar[$member]->getContent();
        $before = '$player->getWorld()->createBlockUpdatePackets($network->getTypeConverter(), [$this->position])';
        $after = '$player->getWorld()->createBlockUpdatePackets([$this->position])';
        if (str_contains($content, $after) && !str_contains($content, $before)) {
            echo "[ReNether] Already compatible: " . basename($path) . " / InvMenu block restoration\n";
        } elseif (substr_count($content, $before) === 1) {
            $updated[$member] = str_replace($before, $after, $content);
        } else {
            fwrite(STDERR, "ERROR: Unexpected InvMenu createBlockUpdatePackets() invocation in $member.\n");
            exit(1);
        }
    } elseif (basename($path) === 'xBedwars.phar') {
        fwrite(STDERR, "ERROR: Missing InvMenu BlockInvMenuGraphic from xBedwars PHAR.\n");
        exit(1);
    }


    // Axolotl 5.49.1 requires either an EntityFactory save ID or the
    // NeverSavedWithChunkEntity marker BEFORE the entity constructor runs.
    // These BedWars match-only entities all disable chunk saving *after*
    // parent::__construct(), which is too late for World::addEntity().
    // They are never intended to be persisted. Mark them explicitly rather
    // than inventing save IDs or weakening the core factory safety check.
    $bedwarsTransientEntities = [
        'src/bedwars/utils/entity/mob/BaseMob.php' =>
            'abstract class BaseMob extends Monster',
        'src/bedwars/utils/entity/BridgeEgg.php' =>
            'class BridgeEgg extends Living',
        'src/bedwars/utils/entity/Landmine.php' =>
            'final class Landmine extends Projectile',
        'src/bedwars/utils/entity/PrimedTNT.php' =>
            'class PrimedTNT extends \pocketmine\entity\object\PrimedTNT',
        'src/bedwars/utils/entity/Snowball.php' =>
            'class Snowball extends \NetherGames\NGEssentials\entity\Snowball',
    ];
    foreach ($bedwarsTransientEntities as $member => $declaration) {
        if (!isset($phar[$member])) {
            if (basename($path) === 'xBedwars.phar') {
                fwrite(STDERR, "ERROR: Missing BedWars transient entity: $member.\n");
                exit(1);
            }
            continue;
        }
        $content = $updated[$member] ?? $phar[$member]->getContent();
        $markedDeclaration = $declaration . ' implements \pocketmine\entity\NeverSavedWithChunkEntity';
        if (str_contains($content, $markedDeclaration)) {
            if (!str_contains($content, '$this->setCanSaveWithChunk(false);')) {
                fwrite(STDERR, "ERROR: Transient entity does not disable chunk saving: $member.\n");
                exit(1);
            }
            echo "[ReNether] Already compatible: " . basename($path) . " / Non-saved entity " . basename($member) . "\n";
        } elseif (substr_count($content, $declaration) === 1 &&
                  str_contains($content, '$this->setCanSaveWithChunk(false);')) {
            $updated[$member] = str_replace($declaration, $markedDeclaration, $content);
        } else {
            fwrite(STDERR, "ERROR: Unexpected transient entity class declaration in $member.\n");
            exit(1);
        }
    }

    // Prevent a finish-time crash when the legacy procedure's OUT variables
    // remain NULL. Read the committed row instead of session OUT variables.
    // The bestChanged fallback handles NULL for first-ever streaks; an
    // unmatched historical best tie can result in a cosmetic announcement.
    $member = 'resources/mysql.sql';
    if (isset($phar[$member])) {
        $content = $updated[$member] ?? $phar[$member]->getContent();
        $before = 'SELECT @r_xuid as xuid, @r_gameKey as gameKey, @r_current as current, @r_best as best, @r_bestChanged as bestChanged;';
        $after = 'SELECT xuid, gameKey, current, best, COALESCE(@r_bestChanged, current = best) AS bestChanged FROM win_streaks WHERE xuid = :xuid AND gameKey = :gameKey;';
        if (substr_count($content, $after) === 1 && !str_contains($content, $before)) {
            echo "[ReNether] Already compatible: " . basename($path) . " / Streak SQL result\n";
        } elseif (substr_count($content, $before) === 1) {
            $updated[$member] = str_replace($before, $after, $content);
        } else {
            fwrite(STDERR, "ERROR: Unexpected streaks.increment query in $member.\n");
            exit(1);
        }
    } elseif (basename($path) === 'NGEssentials.phar') {
        fwrite(STDERR, "ERROR: NGEssentials PHAR missing resources/mysql.sql.\n");
        exit(1);
    }

    // Guard against invalid rows from malformed or incomplete SQL results.
    $member = 'vendor/nethergamesmc/libminigames/utils/Streaks.php';
    if (isset($phar[$member])) {
        $content = $updated[$member] ?? $phar[$member]->getContent();
        $before = '$onSelected(Streak::FromSQL($rows[0]));';
        $after = <<<'PHP'
$row = $rows[0];
            if (!is_string($row["xuid"] ?? null) || !is_string($row["gameKey"] ?? null) ||
                !is_numeric($row["current"] ?? null) || !is_numeric($row["best"] ?? null)) {
                NGEssentials::getInstance()->getLogger()->warning(
                    "Unexpected streaks.increment result for $xuid / $gameKey; skipping invalid response"
                );
                return;
            }
            $onSelected(Streak::FromSQL($row));
PHP;
        if (str_contains($content, $after) && !str_contains($content, $before)) {
            echo "[ReNether] Already compatible: " . basename($path) . " / Streak result validation\n";
        } elseif (substr_count($content, $before) === 1) {
            $updated[$member] = str_replace($before, $after, $content);
        } else {
            fwrite(STDERR, "ERROR: Unexpected streaks.increment handler in $member.\n");
            exit(1);
        }
    } elseif (basename($path) === 'NGEssentials.phar') {
        fwrite(STDERR, "ERROR: NGEssentials PHAR missing Streaks.php.\n");
        exit(1);
    }

    // Original Network Hub spawn differs from the dedicated BedWars waiting
    // area. Only switch to the Hub coordinates when the migration marker
    // confirms the original official Hub has replaced the legacy BW copy.
    // Normal BW installations without this marker retain their old spawn.
    $member = 'src/NetherGames/NGEssentials/ServerManager.php';
    if (isset($phar[$member])) {
        $content = $updated[$member] ?? $phar[$member]->getContent();
        $before = <<<'PHP'
            self::BW, self::MM => new Location(0.5, 65, 0.5, $defaultWorld, 0, 0),
PHP;
        $after = <<<'PHP'
            self::BW => is_file($this->getPlugin()->getServer()->getDataPath() . '.new-ng-original-hub')
                ? new Location(-26.5, 42, -22.5, $defaultWorld, 320, 0)
                : new Location(0.5, 65, 0.5, $defaultWorld, 0, 0),
            self::MM => new Location(0.5, 65, 0.5, $defaultWorld, 0, 0),
PHP;
        if (str_contains($content, $after) && !str_contains($content, $before)) {
            echo "[ReNether] Already compatible: " . basename($path) . " / original Hub spawn\n";
        } elseif (substr_count($content, $before) === 1) {
            $updated[$member] = str_replace($before, $after, $content);
        } else {
            fwrite(STDERR, "ERROR: Unexpected original Hub spawn pattern in $member.\n");
            exit(1);
        }
    } elseif (basename($path) === 'NGEssentials.phar') {
        fwrite(STDERR, "ERROR: Missing ServerManager for original Hub spawn.\n");
        exit(1);
    }

    // The match waiting lobby must keep its original 0,65,0 spawn even though
    // players joining the default world now arrive in the old Network Hub.
    $member = 'vendor/nethergamesmc/libminigames/Arena.php';
    if (isset($phar[$member])) {
        $content = $updated[$member] ?? $phar[$member]->getContent();
        $before = <<<'PHP'
    public function getWaitingLobbySpawn(): Location
    {
        $location = $this->getPlugin()->getEssentials()->getServerManager()->getSpawn();

        if ($this->world !== null) {
PHP;
        $after = <<<'PHP'
    public function getWaitingLobbySpawn(): Location
    {
        $location = $this->getPlugin()->getEssentials()->getServerManager()->getSpawn();
        if (is_file($this->getPlugin()->getServer()->getDataPath() . '.new-ng-original-hub') &&
            $this->getPlugin()->getMinigameTag() === \NetherGames\NGEssentials\ServerManager::BW) {
            $location = new Location(0.5, 65, 0.5, $location->getWorld(), 0, 0);
        }

        if ($this->world !== null) {
PHP;
        // SW uses the same separate WaitingLobby template coordinates;
        // the public Network Hub spawn (-26.5,42,-22.5) is in the void there.
        $swBefore = '$this->getPlugin()->getMinigameTag() === \\NetherGames\\NGEssentials\\ServerManager::BW';
        $swAfter = 'in_array($this->getPlugin()->getMinigameTag(), [\\NetherGames\\NGEssentials\\ServerManager::BW, \\NetherGames\\NGEssentials\\ServerManager::SW], true)';
        if (str_contains($content, $swBefore)) {
            $content = str_replace($swBefore, $swAfter, $content);
        } elseif (!str_contains($content, $swAfter)) {
            if (substr_count($content, $before) === 1) {
                $content = str_replace($before, $after, $content);
                $content = str_replace($swBefore, $swAfter, $content);
            } else {
                fwrite(STDERR, "ERROR: Unexpected SkyWars/BedWars waiting lobby spawn pattern in $member.\\n");
                exit(1);
            }
        }
        // ReNether SkyWars WaitingLobby's actual center was measured in-game
        // at X=128, Y=120, Z=128. Keep its world assignment below intact.
        $swOldLocation = <<<'PHP'
            $location = new Location(0.5, 65, 0.5, $location->getWorld(), 0, 0);
        }

        if ($this->world !== null) {
PHP;
        $swNewLocation = <<<'PHP'
            $location = new Location(0.5, 65, 0.5, $location->getWorld(), 0, 0);
        }
        if ($this->getPlugin()->getMinigameTag() === \NetherGames\NGEssentials\ServerManager::SW &&
            $this->getPlugin()->hasWaitingLobby()) {
            $location = new Location(128.5, 121, 128.5, $location->getWorld(), 0, 0);
        }

        if ($this->world !== null) {
PHP;
        if (str_contains($content, $swNewLocation)) {
            echo "[ReNether] Already compatible: measured SkyWars waiting spawn\n";
        } elseif (substr_count($content, $swOldLocation) === 1) {
            $content = str_replace($swOldLocation, $swNewLocation, $content);
        } else {
            fwrite(STDERR, "ERROR: Unexpected SkyWars WaitingLobby spawn insertion point.\n");
            exit(1);
        }
        if ($content !== ($updated[$member] ?? $phar[$member]->getContent())) {
            $updated[$member] = $content;
            echo "[ReNether] Patched waiting lobby spawn for BW + SW\\n";
        } else {
            echo "[ReNether] Already compatible: waiting lobby spawn for BW + SW\\n";
        }
    } elseif (basename($path) === 'NGEssentials.phar') {
        fwrite(STDERR, "ERROR: Missing libminigames Arena for BW waiting spawn.\n");
        exit(1);
    }

    // Old Network Hub NPCs were created by xLobby's startup, not stored
    // in LevelDB worlds. Since xLobby's entire gameplay layer conflicts
    // with a standalone BW server, create compatible skinned HumanNPCs
    // in the existing NGEssentials EntityManager after it is initialized.
    $member = 'src/NetherGames/NGEssentials/entity/EntityManager.php';
    if (isset($phar[$member])) {
        $content = $updated[$member] ?? $phar[$member]->getContent();
        $before = <<<'PHP'
        $this->addCustomEntity(new CustomActorList("Money Storage", "ng:mmo_npc_money_storage"));
PHP;
        $addition = <<<'PHP'
        // new-ng: reconstructed visible Network Hub NPCs. Official world
        // geometry does not contain entities created at plugin startup.
        // Avoid xLobby on a BedWars server: its join listener would install
        // the Lobby hotbar and interfere with BedWars-specific behavior.
        if (is_file($this->getPlugin()->getServer()->getDataPath() . '.new-ng-original-hub') &&
            $this->getPlugin()->getServerManager()->getServerType() === ServerManager::BW) {
            $hubNpcSpecs = [
                // Display name, NGEssentials skin PNG, original position, action.
                ['BedWars', 'bw.png', 15, 41, 7, 'bedwars'],
                ['SkyWars', 'sw.png', 16, 41, -1, 'offline'],
                ['Murder Mystery', 'mm.png', 15, 41, -5, 'offline'],
                ['Conquests', 'cq.png', 16, 41, 3, 'offline'],
                ['Duels', 'duels.png', 13, 41, -9, 'offline'],
                ['Survival Games', 'sg.png', 9, 41, -12, 'offline'],
                ['Creative', 'creative.png', -9, 41, 19, 'offline'],
                ['Factions', 'factions.png', -1, 41, 20, 'offline'],
                ['UHC', 'uhc.png', -5, 41, 20, 'offline'],
                ['SkyBlock', 'sb.png', 3, 41, 19, 'offline'],
                ['The Bridge', 'tb.png', -13, 41, 17, 'offline'],
                ['Arcade', 'ac.png', -16, 41, 13, 'arcade'],
                ['Coming Soon', 'soon.png', 5, 41, -14, 'offline'],
                ['Coming Soon', 'soon.png', -18, 41, 9, 'offline'],
                // Several original Hub characters near the spawn area.
                ['Adventurer', 'lobby/OldMan.png', -15.5, 41, -21.5, 'story'],
                ['Pirate', 'lobby/pirate.png', -26.5, 40, -98.5, 'story'],
                ['Scientist', 'lobby/Scientist.png', -76.5, 40, 55.5, 'story'],
                ['Camper', 'lobby/Camper.png', -185.5, 55, -70.5, 'story'],
                ['Minotaur', 'lobby/minotaur.png', 45.5, 49, -142.5, 'story']
            ];
            $spawned = 0;
            foreach ($hubNpcSpecs as [$label, $sprite, $x, $y, $z, $action]) {
                try {
                    $texture = \NetherGames\NGEssentials\utils\SkinUtils::getTextureFromString(
                        \NetherGames\NGEssentials\utils\Utils::getResourceContent('skins/npcs/' . $sprite)
                    );
                    $skin = new \pocketmine\entity\Skin('new-ng-hub-' . $sprite, $texture);
                    $text = '§e§l' . $label . '§r' . "\n" .
                        ($action === 'bedwars' ? '§aTap to play' :
                         ($action === 'offline' ? '§cNot yet available' : '§bTap to interact'));
                    $callback = static function (Player $player) use ($label, $action): void {
                        if ($action === 'bedwars') {
                            $bedwars = $player->getServer()->getPluginManager()->getPlugin('xBedwars');
                            if ($bedwars instanceof \bedwars\Bedwars && $bedwars->isEnabled()) {
                                $bedwars->joinArena($player);
                            } else {
                                $player->sendMessage('§cBedWars is not running.');
                            }
                        } elseif ($action === 'arcade') {
                            $manager = $player->getServer()->getWorldManager();
                            if (!$manager->isWorldLoaded('ArcadeLobby')) {
                                $manager->loadWorld('ArcadeLobby');
                            }
                            $arcade = $manager->getWorldByName('ArcadeLobby');
                            if ($arcade !== null) {
                                $player->teleport(new Location(176, 79, 1438, $arcade, 180, 0));
                            } else {
                                $player->sendMessage('§cArcade lobby world is not loaded.');
                            }
                        } elseif ($action === 'story') {
                            $player->sendMessage('§e' . $label . '§7: Welcome to ReNether!');
                        } else {
                            $player->sendMessage('§e' . $label . '§7 is not installed on this server yet.');
                        }
                    };
                    $npc = new \NetherGames\NGEssentials\entity\custom\HumanNPC(
                        new Location((float)$x, (float)$y, (float)$z, $defaultWorld, 90, 0),
                        $text,
                        $skin,
                        $callback
                    );
                    $npc->setScale(1.0);
                    $this->addEntity($npc);
                    ++$spawned;
                } catch (\Throwable $error) {
                    $this->getPlugin()->getLogger()->warning(
                        'Could not spawn Network Hub NPC ' . $label . ': ' . $error->getMessage()
                    );
                }
            }
            $this->getPlugin()->getLogger()->info('ReNether Network Hub: spawned ' . $spawned . ' NPCs');
        }
PHP;
        $after = $before . "\n" . $addition;
        if (str_contains($content, 'new-ng Network Hub: spawned') || str_contains($content, 'ReNether Network Hub: spawned')) {
            echo "[ReNether] Already compatible: " . basename($path) . " / Network Hub NPCs\n";
        } elseif (substr_count($content, $before) === 1) {
            $updated[$member] = str_replace($before, $after, $content);
        } else {
            fwrite(STDERR, "ERROR: Unexpected NGEssentials EntityManager NPC insertion point.\n");
            exit(1);
        }
    } elseif (basename($path) === 'NGEssentials.phar') {
        fwrite(STDERR, "ERROR: NGEssentials EntityManager missing for Hub NPCs.\n");
        exit(1);
    }

    // Multi-game Hub: Soccer must not inherit BW's shared serverType,
    // otherwise /sc conflicts with /bw and both modes name worlds Match-BW-*.
    $member = 'src/soccer/Soccer.php';
    if (isset($phar[$member])) {
        $content = $updated[$member] ?? $phar[$member]->getContent();
        $before = '    public function balanceQueuing(): bool';
        $insert = <<<'PHP'
    // new-ng main server Soccer tag: independent command and arena namespaces.
    public function getMinigameTag(): string
    {
        return \NetherGames\NGEssentials\ServerManager::SC;
    }

PHP;
        if (!str_contains($content, 'new-ng main server Soccer tag')) {
            if (substr_count($content, $before) !== 1) {
                fwrite(STDERR, "ERROR: Soccer tag insertion point missing in $member.\n");
                exit(1);
            }
            $content = str_replace($before, $insert . $before, $content);
        }
        $before = <<<'PHP'
    public function isStandAloneGame(): bool
    {
        return true;
    }
PHP;
        $after = <<<'PHP'
    public function isStandAloneGame(): bool
    {
        // new-ng: Soccer is part of the local Hub, not a proxy cluster.
        return false;
    }
PHP;
        if (str_contains($content, $after) && !str_contains($content, $before)) {
            echo "[ReNether] Already compatible: " . basename($path) . " / Hub Soccer gamemode\n";
        } elseif (substr_count($content, $before) === 1) {
            $content = str_replace($before, $after, $content);
        } else {
            fwrite(STDERR, "ERROR: Unexpected Soccer standalone setting in $member.\n");
            exit(1);
        }
        if ($content !== ($updated[$member] ?? $phar[$member]->getContent())) {
            $updated[$member] = $content;
        }
    } elseif (basename($path) === 'xSoccer.phar') {
        fwrite(STDERR, "ERROR: Soccer.php missing from xSoccer PHAR.\n");
        exit(1);
    }

    // Soccer's waiting area uses its own original map spawn, not the Hub
    // coordinate that NGEssentials returns for the BedWars serverType.
    $member = 'src/soccer/SCArena.php';
    if (isset($phar[$member])) {
        $content = $updated[$member] ?? $phar[$member]->getContent();
        $before = '    public function bootMinigame(): void';
        $insert = <<<'PHP'
    // new-ng: wait inside Soccer's map, never at the BedWars Hub spawn.
    public function getWaitingLobbySpawn(): Location
    {
        if ($this->world === null) {
            return parent::getWaitingLobbySpawn();
        }
        $world = $this->getWorld();
        return Location::fromObject($world->getSpawnLocation()->add(0.5, 0, 0.5), $world);
    }

PHP;
        if (str_contains($content, 'new-ng: wait inside Soccer')) {
            echo "[ReNether] Already compatible: " . basename($path) . " / Soccer waiting spawn\n";
        } elseif (substr_count($content, $before) === 1) {
            $updated[$member] = str_replace($before, $insert . $before, $content);
        } else {
            fwrite(STDERR, "ERROR: Soccer waiting spawn insertion point missing in $member.\n");
            exit(1);
        }
    } elseif (basename($path) === 'xSoccer.phar') {
        fwrite(STDERR, "ERROR: SCArena.php missing from xSoccer PHAR.\n");
        exit(1);
    }

    // Players must leave their current arena before joining another mode,
    // otherwise two independent arena listeners will control one inventory.
    $member = 'vendor/nethergamesmc/libminigames/Minigame.php';
    if (isset($phar[$member])) {
        $content = $updated[$member] ?? $phar[$member]->getContent();
        $before = <<<'PHP'
    final public function joinArena(Player $player, int $modeId = -1): bool
    {
        /** @var NGPlayer $player */
        $ess = $this->getEssentials();
PHP;
        $after = <<<'PHP'
    final public function joinArena(Player $player, int $modeId = -1): bool
    {
        // new-ng: block joining a second game while already in the other.
        foreach (['xBedwars', 'xSoccer'] as $otherName) {
            $otherGame = $player->getServer()->getPluginManager()->getPlugin($otherName);
            if ($otherGame instanceof self && $otherGame !== $this &&
                $otherGame->isEnabled() && $otherGame->getArena($player) !== null) {
                $player->sendMessage('§cLeave your current game before joining another.');
                return false;
            }
        }
        /** @var NGPlayer $player */
        $ess = $this->getEssentials();
PHP;
        if (str_contains($content, $after) && !str_contains($content, $before)) {
            echo "[ReNether] Already compatible: " . basename($path) . " / multi-game join guard\n";
        } elseif (substr_count($content, $before) === 1) {
            $updated[$member] = str_replace($before, $after, $content);
        } else {
            fwrite(STDERR, "ERROR: Unexpected Minigame::joinArena signature in $member.\n");
            exit(1);
        }
    } elseif (basename($path) === 'NGEssentials.phar') {
        fwrite(STDERR, "ERROR: libminigames Minigame missing from NGEssentials PHAR.\n");
        exit(1);
    }

    // Minigame::getInstance() is a single static value shared by both modes.
    // Join/quit events must report their actual arena plugin instead.
    $member = 'vendor/nethergamesmc/libminigames/events/MinigameEvent.php';
    if (isset($phar[$member])) {
        $content = $updated[$member] ?? $phar[$member]->getContent();
        $before = 'parent::__construct(Minigame::getInstance());';
        $after = 'parent::__construct($arena->getPlugin());';
        if (str_contains($content, $after) && !str_contains($content, $before)) {
            echo "[ReNether] Already compatible: " . basename($path) . " / multi-game event owner\n";
        } elseif (substr_count($content, $before) === 1) {
            $updated[$member] = str_replace($before, $after, $content);
        } else {
            fwrite(STDERR, "ERROR: Unexpected MinigameEvent plugin reference in $member.\n");
            exit(1);
        }
    } elseif (basename($path) === 'NGEssentials.phar') {
        fwrite(STDERR, "ERROR: MinigameEvent missing from NGEssentials PHAR.\n");
        exit(1);
    }

    // Unlike MinigameEvent, PlayerStatChangeEvent has no arena argument.
    // Use the player's active arena to select the correct plugin when both
    // BedWars and Soccer run in the same process.
    $member = 'vendor/nethergamesmc/libminigames/events/PlayerStatChangeEvent.php';
    if (isset($phar[$member])) {
        $content = $updated[$member] ?? $phar[$member]->getContent();
        $before = '        parent::__construct(Minigame::getInstance());';
        $after = <<<'PHP'
        // new-ng: attribute stats to whichever minigame owns this player.
        $owner = Minigame::getInstance();
        foreach (['xBedwars', 'xSoccer'] as $gameName) {
            $game = $player->getServer()->getPluginManager()->getPlugin($gameName);
            if ($game instanceof Minigame && $game->isEnabled() && $game->getArena($player) !== null) {
                $owner = $game;
                break;
            }
        }
        parent::__construct($owner);
PHP;
        if (str_contains($content, $after) && !str_contains($content, $before)) {
            echo "[ReNether] Already compatible: " . basename($path) . " / multi-game stats owner\n";
        } elseif (substr_count($content, $before) === 1) {
            $updated[$member] = str_replace($before, $after, $content);
        } else {
            fwrite(STDERR, "ERROR: Unexpected player stats event constructor in $member.\n");
            exit(1);
        }
    } elseif (basename($path) === 'NGEssentials.phar') {
        fwrite(STDERR, "ERROR: PlayerStatChangeEvent missing from NGEssentials PHAR.\n");
        exit(1);
    }

    // Bedrock server list query overwrites server.properties MOTD with the
    // original NGEssentials ServerManager::getMotd() every refresh.
    // Patch the real source of the on-wire Bedrock server name.
    $member = 'src/NetherGames/NGEssentials/ServerManager.php';
    if (isset($phar[$member])) {
        $content = $updated[$member] ?? $phar[$member]->getContent();
        $before = "        return '§k§b.§r §o§e§lN§6G§r§7: §bNG ' . self::getName(\$this->getServerType());";
        $after = "        return '§6§lReNether§r';";
        if (substr_count($content, $after) === 1 && !str_contains($content, $before)) {
            echo "[ReNether] Already compatible: " . basename($path) . " / actual Bedrock query MOTD\n";
        } elseif (substr_count($content, $before) === 1) {
            $updated[$member] = str_replace($before, $after, $content);
        } else {
            fwrite(STDERR, "ERROR: Unexpected NGEssentials ServerManager::getMotd() value in $member.\n");
            exit(1);
        }
    } elseif (basename($path) === 'NGEssentials.phar') {
        fwrite(STDERR, "ERROR: NGEssentials ServerManager missing; cannot set ReNether query MOTD.\n");
        exit(1);
    }

    // The original floating title/bossbar also calls NGEssentials::getLogo().
    $member = 'src/NetherGames/NGEssentials/NGEssentials.php';
    if (isset($phar[$member])) {
        $content = $updated[$member] ?? $phar[$member]->getContent();
        $before = "return TextFormat::BOLD . TextFormat::YELLOW . 'Nether' . TextFormat::GOLD . 'Games ';";
        $after = "return TextFormat::BOLD . TextFormat::GOLD . 'ReNether ';";
        if (substr_count($content, $after) === 1 && !str_contains($content, $before)) {
            echo "[ReNether] Already compatible: " . basename($path) . " / in-game server logo\n";
        } elseif (substr_count($content, $before) === 1) {
            $updated[$member] = str_replace($before, $after, $content);
        } else {
            fwrite(STDERR, "ERROR: Unexpected NGEssentials::getLogo() value in $member.\n");
            exit(1);
        }
    }

    // NGEssentials deliberately unregisters vanilla commands, including
    // op/deop/help/plugins. Retain those essential server administration tools
    // so console operators can debug games and grant OP.
    $member = 'src/NetherGames/NGEssentials/commands/BaseCommand.php';
    if (isset($phar[$member])) {
        $content = $updated[$member] ?? $phar[$member]->getContent();
        $before = <<<'PHP'
        foreach (self::UNREGISTERED_COMMANDS as $command) {
            $commandMap->unregister($commandMap->getCommand($command));
        }
PHP;
        $after = <<<'PHP'
        foreach (self::UNREGISTERED_COMMANDS as $command) {
            if (in_array($command, ['op', 'deop', 'help', 'plugins'], true)) {
                continue; // ReNether: preserve vanilla administration commands.
            }
            $commandMap->unregister($commandMap->getCommand($command));
        }
PHP;
        if (str_contains($content, $after) && !str_contains($content, $before)) {
            echo "[ReNether] Already compatible: admin console commands\n";
        } elseif (substr_count($content, $before) === 1) {
            $updated[$member] = str_replace($before, $after, $content);
        } else {
            fwrite(STDERR, "ERROR: Unexpected NGEssentials BaseCommand::registerCommands implementation.\n");
            exit(1);
        }
    } elseif (basename($path) === 'NGEssentials.phar') {
        fwrite(STDERR, "ERROR: NGEssentials BaseCommand missing; cannot restore console commands.\n");
        exit(1);
    }

    // When SW shares a process with BW, NGEssentials still reports the BW
    // serverType. PlayerManager normally initializes Store only for SW/lobby,
    // causing a fatal access to its uninitialized typed property at match start.
    $member = 'src/NetherGames/NGEssentials/player/PlayerManager.php';
    if (isset($phar[$member])) {
        $content = $updated[$member] ?? $phar[$member]->getContent();
        $before = <<<'PHP'
if (($enableLobbyHandling = $serverManager->enableLobbyHandling()) || in_array($plugin->getServerManager()->getServerType(), [ServerManager::SW, ServerManager::SETUP], true)) {
PHP;
        $after = <<<'PHP'
if (($enableLobbyHandling = $serverManager->enableLobbyHandling()) || in_array($plugin->getServerManager()->getServerType(), [ServerManager::SW, ServerManager::SETUP], true) || (getenv('NG_ENABLE_SKYWARS') === '1' && $serverManager->getServerType() === ServerManager::BW)) {
PHP;
        if (str_contains($content, $after) && !str_contains($content, $before)) {
            echo "[ReNether] Already compatible: shared BW+SW store initialized\n";
        } elseif (substr_count($content, $before) === 1) {
            $updated[$member] = str_replace($before, $after, $content);
        } else {
            fwrite(STDERR, "ERROR: Unexpected PlayerManager Store initialization guard.\n");
            exit(1);
        }
    } elseif (basename($path) === 'NGEssentials.phar') {
        fwrite(STDERR, "ERROR: Missing PlayerManager required for SkyWars Store.\n");
        exit(1);
    }

    // SkyWars was originally created as a visible but deliberately offline
    // Hub NPC. Wire its existing tap handler to xSkywars now that the game
    // can load alongside BedWars. Works on already-patched PHARs too.
    $member = 'src/NetherGames/NGEssentials/entity/EntityManager.php';
    if (isset($phar[$member])) {
        $content = $updated[$member] ?? $phar[$member]->getContent();
        $oldNpc = "['SkyWars', 'sw.png', 16, 41, -1, 'offline']";
        $newNpc = "['SkyWars', 'sw.png', 16, 41, -1, 'skywars']";
        if (str_contains($content, $oldNpc)) {
            $content = str_replace($oldNpc, $newNpc, $content);
        } elseif (!str_contains($content, $newNpc)) {
            fwrite(STDERR, "ERROR: SkyWars Hub NPC definition not found; refusing unsafe replacement.\n");
            exit(1);
        }
        $oldAction = <<<'PHP'
} elseif ($action === 'arcade') {
PHP;
        $newAction = <<<'PHP'
} elseif ($action === 'skywars') {
                            $skywars = $player->getServer()->getPluginManager()->getPlugin('xSkywars');
                            if ($skywars instanceof \skywars\Skywars && $skywars->isEnabled()) {
                                $skywars->joinArena($player);
                            } else {
                                $player->sendMessage('§cSkyWars is not running.');
                            }
                        } elseif ($action === 'arcade') {
PHP;
        if (!str_contains($content, $newAction)) {
            if (substr_count($content, $oldAction) !== 1) {
                fwrite(STDERR, "ERROR: SkyWars Hub NPC callback insertion point not found.\n");
                exit(1);
            }
            $content = str_replace($oldAction, $newAction, $content);
        }
        $oldText = <<<'PHP'
($action === 'bedwars' ? '§aTap to play' :
PHP;
        $newText = <<<'PHP'
($action === 'bedwars' || $action === 'skywars' ? '§aTap to play' :
PHP;
        if (str_contains($content, $oldText)) {
            $content = str_replace($oldText, $newText, $content);
        } elseif (!str_contains($content, $newText)) {
            fwrite(STDERR, "ERROR: SkyWars Hub NPC label insertion point not found.\n");
            exit(1);
        }
        if ($content !== ($updated[$member] ?? $phar[$member]->getContent())) {
            $updated[$member] = $content;
            echo "[ReNether] Patched SkyWars Hub NPC tap action\n";
        } else {
            echo "[ReNether] Already compatible: SkyWars Hub NPC tap action\n";
        }
    } elseif (basename($path) === 'NGEssentials.phar') {
        fwrite(STDERR, "ERROR: Missing EntityManager for SkyWars Hub NPC.\n");
        exit(1);
    }

    // ReNether no longer offers Soccer. Remove the NPC from PHARs patched by
    // previous versions, without affecting saved worlds or Hub characters.
    $member = 'src/NetherGames/NGEssentials/entity/EntityManager.php';
    if (isset($phar[$member])) {
        $content = $updated[$member] ?? $phar[$member]->getContent();
        $content = str_replace(
            "                ['Soccer', 'sc.png', -19, 41, 13, 'soccer'],\n",
            '',
            $content
        );
        $soccerAction = <<<'PHP'
} elseif ($action === 'soccer') {
                            $soccer = $player->getServer()->getPluginManager()->getPlugin('xSoccer');
                            if ($soccer instanceof \soccer\Soccer && $soccer->isEnabled()) {
                                $soccer->joinArena($player);
                            } else {
                                $player->sendMessage('§cSoccer is not available.');
                            }
                        } elseif ($action === 'arcade') {
PHP;
        $content = str_replace($soccerAction, "} elseif (\$action === 'arcade') {", $content);
        // Preserve the original Hub detection marker filename: it is on disk.
        $content = str_replace('new-ng Network Hub: spawned', 'ReNether Network Hub: spawned', $content);
        $content = str_replace('Welcome to the old NetherGames Hub!', 'Welcome to ReNether!', $content);
        if ($content !== ($updated[$member] ?? $phar[$member]->getContent())) {
            $updated[$member] = $content;
        }
    }

    // Visible rebranding only. Do not modify original PHP namespaces,
    // attribution, backend API URLs, or stored player data identifiers.
    $branding = [
        'src/NetherGames/NGEssentials/NGEssentials.php' => [
            "'§o§l§eN§6G§r§7: '" => "'§6§lReNether§r§7: '",
        ],
        'src/NetherGames/NGEssentials/player/PlayerManager.php' => [
            "CustomIcon::NETHERGAMES . TextFormat::GOLD . 'ngmc.co'" =>
                "TextFormat::GOLD . 'ReNether'",
        ],
        'vendor/nethergamesmc/libminigames/Arena.php' => [
            "CustomIcon::NETHERGAMES . TextFormat::GOLD . 'ngmc.co'" =>
                "TextFormat::GOLD . 'ReNether'",
        ],
        'src/bedwars/BWTeam.php' => [
            'CustomIcon::NETHERGAMES . TextFormat::GOLD . "ngmc.co"' =>
                'TextFormat::GOLD . "ReNether"',
        ],
        'src/NetherGames/NGEssentials/EventListener.php' => [
            '§o§l§eN§6G§r§7: §cLooks like §eNether§6Games §cis full,' =>
                '§6§lReNether§r§7: §cReNether is full,',
        ],
    ];
    // Public-facing scoreboards: point to the real ReNether GitHub project.
    // Do not rewrite ngmc.co API endpoints, CDN hosts or internal identifiers.
    $projectUrl = 'github.com/JJ-JaaN/ReNether';
    foreach ([
        'src/NetherGames/NGEssentials/player/PlayerManager.php',
        'vendor/nethergamesmc/libminigames/Arena.php',
        'src/bedwars/BWTeam.php',
        'src/skywars/handler/NormalHandler.php',
        'src/skywars/handler/DuelsHandler.php',
    ] as $member) {
        if (!isset($phar[$member])) {
            continue;
        }
        $content = $updated[$member] ?? $phar[$member]->getContent();
        $content = str_replace(
            ["CustomIcon::NETHERGAMES . TextFormat::GOLD . 'ngmc.co'",
             'CustomIcon::NETHERGAMES . TextFormat::GOLD . "ngmc.co"',
             "TextFormat::GOLD . 'ReNether'",
             'TextFormat::GOLD . "ReNether"'],
            ["TextFormat::GOLD . '" . $projectUrl . "'",
             'TextFormat::GOLD . "' . $projectUrl . '"',
             "TextFormat::GOLD . '" . $projectUrl . "'",
             'TextFormat::GOLD . "' . $projectUrl . '"'],
            $content
        );
        if ($content !== ($updated[$member] ?? $phar[$member]->getContent())) {
            $updated[$member] = $content;
            echo "[ReNether] Updated public GUI branding: $member\n";
        }
    }

    foreach ($branding as $member => $replacements) {
        if (!isset($phar[$member])) {
            continue;
        }
        $content = $updated[$member] ?? $phar[$member]->getContent();
        $branded = str_replace(array_keys($replacements), array_values($replacements), $content);
        if ($branded !== $content) {
            $updated[$member] = $branded;
        }
    }

    // SkyWars corpse is a short-lived Human spawned on player death.
    // Axolotl 5.49.1 requires NeverSavedWithChunkEntity at construction,
    // before SWPlayer can call setCanSaveWithChunk(false).
    $member = 'src/skywars/entities/SWPlayer.php';
    if (isset($phar[$member])) {
        $content = $updated[$member] ?? $phar[$member]->getContent();
        $before = 'class SWPlayer extends Human';
        $after = 'class SWPlayer extends Human implements \pocketmine\entity\NeverSavedWithChunkEntity';
        // Earlier ReNether patch incorrectly re-applied this declaration on
        // every startup because $before is a prefix of $after. Normalize
        // already-corrupted PHARs before checking for the marker.
        $duplicate = $after . ' implements \pocketmine\entity\NeverSavedWithChunkEntity';
        while (str_contains($content, $duplicate)) {
            $content = str_replace($duplicate, $after, $content);
        }
        if (substr_count($content, $after) === 1) {
            if ($content !== ($updated[$member] ?? $phar[$member]->getContent())) {
                $updated[$member] = $content;
                echo "[ReNether] Repaired duplicate SkyWars corpse implements\n";
            } else {
                echo "[ReNether] Already compatible: SkyWars corpse non-saved entity\n";
            }
        } elseif (substr_count($content, $before) === 1) {
            $updated[$member] = str_replace($before, $after, $content);
        } else {
            fwrite(STDERR, "ERROR: Unexpected SkyWars SWPlayer class declaration.\n");
            exit(1);
        }
    } elseif (basename($path) === 'xSkywars.phar') {
        fwrite(STDERR, "ERROR: SkyWars corpse entity missing from xSkywars PHAR.\n");
        exit(1);
    }

    // Soccer ball is a match-only Human entity. Like BedWars' custom entities,
    // it calls setCanSaveWithChunk(false) only after the parent constructor.
    // Axolotl requires a non-persistent marker during construction instead.
    $member = 'src/soccer/SCBall.php';
    if (isset($phar[$member])) {
        $content = $updated[$member] ?? $phar[$member]->getContent();
        $before = 'class SCBall extends Human';
        $after = 'class SCBall extends Human implements \pocketmine\entity\NeverSavedWithChunkEntity';
        if (substr_count($content, $after) === 1) {
            echo "[ReNether] Already compatible: " . basename($path) . " / Non-saved soccer ball\n";
        } elseif (substr_count($content, $before) === 1) {
            $updated[$member] = str_replace($before, $after, $content);
        } else {
            fwrite(STDERR, "ERROR: Unexpected Soccer ball class declaration in $member.\n");
            exit(1);
        }
    } elseif (basename($path) === 'xSoccer.phar') {
        fwrite(STDERR, "ERROR: Missing SCBall class in xSoccer PHAR.\n");
        exit(1);
    }

    // The original Map Selector uses the custom ZONES item. On clients
    // without the NetherGames item textures its name appears but its icon
    // is blank. Vanilla paper has a guaranteed Bedrock texture, while all
    // existing inventory and MinigameListener equality checks still call
    // Items::getMapSelectionPaper() and therefore keep working unchanged.
    $member = 'vendor/nethergamesmc/libminigames/utils/Items.php';
    if (isset($phar[$member])) {
        $content = $updated[$member] ?? $phar[$member]->getContent();
        $before = 'return CustomItemRegistry::ZONES()->setCustomName(TextFormat::RESET . TextFormat::GREEN . TextFormat::BOLD . "Map Selector");';
        $after = 'return VanillaItems::PAPER()->setCustomName(TextFormat::RESET . TextFormat::GREEN . TextFormat::BOLD . "Map Selector");';
        if (substr_count($content, $after) === 1 && !str_contains($content, $before)) {
            echo "[ReNether] Already compatible: " . basename($path) . " / Vanilla paper Map Selector\n";
        } elseif (substr_count($content, $before) === 1) {
            $updated[$member] = str_replace($before, $after, $content);
        } else {
            fwrite(STDERR, "ERROR: Unexpected Map Selector item declaration in $member.\n");
            exit(1);
        }
    } elseif (basename($path) === 'NGEssentials.phar') {
        fwrite(STDERR, "ERROR: NGEssentials PHAR missing libminigames Items.php.\n");
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
            echo "[ReNether] Patched plugin PHAR: " . basename($path) . " / " . basename($member) . "\n";
        }
        $phar->stopBuffering();
    }
}

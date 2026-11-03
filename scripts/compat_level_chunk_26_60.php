<?php
declare(strict_types=1);

/**
 * The archived NGPlayer::sendDimension() uses a synthetic empty-chunk packet.
 * NetherGames originally shipped a 6-argument call. Axolotl 26.50 needed
 * 7 arguments, whereas BedrockProtocol 64 (Bedrock 26.60) adds an eighth:
 * bool $clientBiomeUpdate. This is false for the synthetic empty chunk.
 *
 * Only rewrite the one known LevelChunkPacket call; never alter real
 * world chunk serialization or the player's existing saved world.
 */
function renetherPatchDimensionLevelChunk(string $source) : string{
    $legacy6 = <<<'PHP'
                    $session->sendDataPacket(LevelChunkPacket::create(
                        new ChunkPosition($X, $Z),
                        $dimensionId,
                        $subChunkCount,
                        false,
                        null,
                        $emptyChunk
                    ));
PHP;
    $legacy7 = <<<'PHP'
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
    $current8 = <<<'PHP'
                    $session->sendDataPacket(LevelChunkPacket::create(
                        new ChunkPosition($X, $Z),
                        $dimensionId,
                        $subChunkCount,
                        null,
                        false,
                        [],
                        $emptyChunk,
                        false
                    ));
PHP;

    if(substr_count($source, 'LevelChunkPacket::create(') !== 1){
        throw new RuntimeException('Expected exactly one NGPlayer dimension LevelChunkPacket call');
    }
    if(substr_count($source, $current8) === 1){
        return $source;
    }
    if(substr_count($source, $legacy7) === 1){
        return str_replace($legacy7, $current8, $source);
    }
    if(substr_count($source, $legacy6) === 1){
        return str_replace($legacy6, $current8, $source);
    }
    throw new RuntimeException('Unexpected NGPlayer dimension LevelChunkPacket signature; refusing patch');
}

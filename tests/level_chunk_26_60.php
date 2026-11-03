<?php
declare(strict_types=1);

require_once __DIR__ . '/../scripts/compat_level_chunk_26_60.php';

function assertDimensionChunk(bool $condition, string $label) : void{
    if(!$condition){
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
}

$legacy6 = <<<'PHP'
<?php
function sendDimension(): void {
                    $session->sendDataPacket(LevelChunkPacket::create(
                        new ChunkPosition($X, $Z),
                        $dimensionId,
                        $subChunkCount,
                        false,
                        null,
                        $emptyChunk
                    ));
}
PHP;

$legacy7 = <<<'PHP'
<?php
function sendDimension(): void {
                    $session->sendDataPacket(LevelChunkPacket::create(
                        new ChunkPosition($X, $Z),
                        $dimensionId,
                        $subChunkCount,
                        null,
                        false,
                        [],
                        $emptyChunk
                    ));
}
PHP;

$current8 = <<<'PHP'
<?php
function sendDimension(): void {
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
}
PHP;

assertDimensionChunk(renetherPatchDimensionLevelChunk($legacy6) === $current8, 'old six-argument source migrated to current eight');
assertDimensionChunk(renetherPatchDimensionLevelChunk($legacy7) === $current8, '26.50 seven-argument source migrated to current eight');
assertDimensionChunk(renetherPatchDimensionLevelChunk($current8) === $current8, 'current source unchanged');
assertDimensionChunk(renetherPatchDimensionLevelChunk(renetherPatchDimensionLevelChunk($legacy6)) === $current8, 'idempotent');
assertDimensionChunk(str_contains($current8, "        \$emptyChunk,\n                        false"), 'biome override remains disabled');

foreach([
    '<?php echo "no chunk packets";',
    str_replace('$emptyChunk', '$somethingElse', $legacy7),
    $legacy7 . "\n" . $legacy7
] as $invalid){
    try{
        renetherPatchDimensionLevelChunk($invalid);
        fwrite(STDERR, "FAIL: unknown chunk layout accepted\n");
        exit(1);
    }catch(RuntimeException $e){}
}

echo "Bedrock 26.60 empty-chunk transfer ABI tests passed\n";

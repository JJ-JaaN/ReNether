<?php
declare(strict_types=1);

/**
 * Patch the archived NetherGames libReplay ZstdCompressor for the
 * PocketMine/Axolotl Compressor::getNetworkType() interface.
 *
 * Upstream libReplay already implements this method (CompressionAlgorithm::ZLIB)
 * in newer sources. Preserve its choice here for compatibility with the
 * archived source used by existing ReNether plugin PHARs. Replays use their
 * separate ZSTD compression format; do not use this adapter to negotiate
 * actual ZSTD over Bedrock's ZLIB network channel.
 */
function renetherPatchLegacyReplayCompressor(string $source) : string{
    $signature = '/\bfinal\s+class\s+ZstdCompressor\s+implements\s+Compressor\s*\{/m';
    if(preg_match_all($signature, $source) !== 1 ||
       !str_contains($source, 'pocketmine\\network\\mcpe\\compression\\Compressor') ||
       !str_contains($source, 'function getCompressionThreshold(') ||
       !str_contains($source, 'zstd_compress(')){
        throw new RuntimeException('Unexpected libReplay ZstdCompressor source; refusing patch');
    }
    if(preg_match('/\bfunction\s+getNetworkType\s*\(/', $source)){
        return $source;
    }
    $addition = <<<'PHP'

    // ReNether: Axolotl 5.51.1 Compressor API compatibility.
    public function getNetworkType(): \pocketmine\network\mcpe\protocol\types\CompressionAlgorithm
    {
        return \pocketmine\network\mcpe\protocol\types\CompressionAlgorithm::ZLIB;
    }

PHP;
    $result = preg_replace_callback($signature, static fn(array $match): string =>
        $match[0] . $addition, $source, 1, $count);
    if($result === null || $count !== 1){
        throw new RuntimeException('Failed to insert libReplay Compressor::getNetworkType()');
    }
    return $result;
}

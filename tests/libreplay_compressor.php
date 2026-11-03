<?php
declare(strict_types=1);

require_once __DIR__ . '/../scripts/compat_libreplay.php';

function verify(bool $condition, string $message) : void{
    if(!$condition){
        fwrite(STDERR, "FAIL: " . $message . "\n");
        exit(1);
    }
}

$legacy = <<<'PHP'
<?php
namespace libReplay\session\utils;
use pocketmine\network\mcpe\compression\Compressor;
final class ZstdCompressor implements Compressor
{
    public function compress(string $payload): string {
        return zstd_compress($payload);
    }
    public function getCompressionThreshold(): int {return 0;}
}
PHP;

$patched = renetherPatchLegacyReplayCompressor($legacy);
verify(substr_count($patched, 'function getNetworkType(') === 1, 'network method inserted exactly once');
verify(
    str_contains($patched, 'return \pocketmine\network\mcpe\protocol\types\CompressionAlgorithm::ZLIB;'),
    'method returns the upstream compression enum'
);
verify(renetherPatchLegacyReplayCompressor($patched) === $patched, 'patch is idempotent');
verify(renetherPatchLegacyReplayCompressor($legacy) === $patched, 'deterministic output');
verify(str_contains($patched, 'zstd_compress($payload)'), 'replay ZSTD codec left unchanged');
$bad = str_replace('final class ZstdCompressor', 'final class OtherCompressor', $legacy);
try{
    renetherPatchLegacyReplayCompressor($bad);
    fwrite(STDERR, "FAIL: unexpected class should be rejected\n");
    exit(1);
}catch(RuntimeException $e){
    verify(str_contains($e->getMessage(), 'Unexpected'), 'unexpected class rejected');
}
echo "libReplay Compressor 26.60 compatibility tests passed\n";

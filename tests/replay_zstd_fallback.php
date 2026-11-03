<?php
declare(strict_types=1);

require_once __DIR__ . '/../scripts/compat_replay_zstd.php';

function assertReplayBootstrap(bool $ok, string $message) : void{
    if(!$ok){
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}

$source = <<<'PHP'
<?php
namespace NetherGames\NGEssentials\player\enforcement;

class Enforcement {
    public function __construct() {
        if ($serverManager->enableReplayServer()) {
            new Replays($plugin, !$replay, $replay);
        }
    }
}
PHP;

$patched = renetherPatchReplayBootstrap($source);
assertReplayBootstrap(substr_count($patched, 'new Replays(') === 1, 'preserves upstream replay initialization');
assertReplayBootstrap(str_contains($patched, "function_exists('zstd_compress')"), 'requires zstd compressor');
assertReplayBootstrap(str_contains($patched, "function_exists('zstd_uncompress')"), 'requires zstd decompressor');
assertReplayBootstrap(str_contains($patched, "defined('ZSTD_COMPRESS_LEVEL_DEFAULT')"), 'requires zstd level constant');
assertReplayBootstrap(str_contains($patched, 'BedWars continues without recording'), 'warns operator about missing replay feature');
assertReplayBootstrap(renetherPatchReplayBootstrap($patched) === $patched, 'idempotent');
assertReplayBootstrap(str_contains($patched, 'if ($serverManager->enableReplayServer())'), 'preserves original replay status gate');

try{
    renetherPatchReplayBootstrap(str_replace('enableReplayServer()', 'anotherMethod()', $source));
    fwrite(STDERR, "FAIL: unexpected bootstrap must be rejected\n");
    exit(1);
}catch(RuntimeException $e){
    assertReplayBootstrap(str_contains($e->getMessage(), 'unique'), 'unknown shape refused');
}

echo "Replay ZSTD runtime fallback tests passed\n";

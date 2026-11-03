<?php
declare(strict_types=1);
require_once __DIR__ . '/../scripts/compat_add_actor.php';

function checkAddActor(bool $ok, string $message) : void{
    if(!$ok){
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}

$oldBossBar = <<<'PHP'
<?php
function showTo($pos) {
    $packet = AddActorPacket::create(
        $this->getEntityId(),
        $this->getEntityId(),
        EntityIds::SLIME,
        $pos->add(0, -$pos->getFloorY() - 10, 0),
        null,
        0,
        0,
        0,
        0,
        [],
        $this->metadata->getAll(),
        new PropertySyncData([], []),
        []
    );
}
PHP;

$patched = renetherPatchAddActorPacket($oldBossBar);
checkAddActor(str_contains($patched, "new PropertySyncData([], []),\n        []\n    , null)"),
    'BossBar receives 14th argument without changing nested expressions');
checkAddActor(renetherPatchAddActorPacket($patched) === $patched, 'BossBar idempotent');
checkAddActor(substr_count($patched, 'null') === 2, 'only one new null after old motion value');

$modernBossBar = str_replace("        []\n    );", "        [],\n        null\n    );", $oldBossBar);
checkAddActor(renetherPatchAddActorPacket($modernBossBar) === $modernBossBar,
    'preserves already-compatible 14-argument upstream BossBar');

$withClosure = <<<'PHP'
<?php
AddActorPacket::create(
    1, 2, EntityIds::SLIME, $pos, null, 0, 0, 0, 0,
    array_map(static function ($a): array { return [$a, 12]; }, $attrs),
    $metadata,
    new PropertySyncData([], []),
    []
);
PHP;
$closurePatched = renetherPatchAddActorPacket($withClosure);
checkAddActor(renetherPatchAddActorPacket($closurePatched) === $closurePatched,
    'nested callback and arrays do not confuse argument counting');

$both = $oldBossBar . "\n" . $withClosure;
$bothPatched = renetherPatchAddActorPacket($both);
checkAddActor(substr_count($bothPatched, ', null)') === 2, 'patches both calls');
checkAddActor(renetherPatchAddActorPacket($bothPatched) === $bothPatched,
    'multiple calls idempotent');

$trailing = str_replace("        []\n    );", "        [],\n    );", $oldBossBar);
checkAddActor(str_contains(renetherPatchAddActorPacket($trailing), "        [],\n    null)"),
    'handles legacy trailing comma');

try {
    renetherPatchAddActorPacket(str_replace('new PropertySyncData([], []),', '', $oldBossBar));
    fwrite(STDERR, "FAIL: 12-argument actor call not rejected\n");
    exit(1);
}catch(RuntimeException $e){
    checkAddActor(str_contains($e->getMessage(), 'argument count'), 'rejects unexpected arity');
}

try {
    renetherPatchAddActorPacket('<?php echo "No actors here";');
    fwrite(STDERR, "FAIL: missing call not rejected\n");
    exit(1);
}catch(RuntimeException $e){
    checkAddActor(str_contains($e->getMessage(), 'Expected at least one'), 'requires target call');
}

echo "AddActorPacket 26.60 13-to-14 argument compatibility tests passed\n";

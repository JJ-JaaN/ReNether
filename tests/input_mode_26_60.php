<?php
declare(strict_types=1);

require_once __DIR__ . '/../scripts/compat_input_mode.php';

function assertInput(bool $ok, string $msg): void {
    if(!$ok){
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
}

$legacyPlayer = <<<'PHP'
<?php
class NGPlayer{
    private int $inputMode = 0;
    public function handlePlayerAuthInput($packet): void{
        if ($this->inputMode !== ($inputMode = $packet->getInputMode())) {
            $event = new PlayerInputChangeEvent($this, $this->inputMode, $inputMode);
            $event->call();
            $this->inputMode = $inputMode;
        }
    }
}
PHP;
$legacyEvent = <<<'PHP'
<?php
class PlayerInputChangeEvent{
    public function __construct(
        private int $lastInputMode,
        private int $newInputMode
    ){}
}
PHP;

$fixed = renetherPatchLegacyInputMode($legacyPlayer, $legacyEvent);
assertInput(str_contains($fixed, '$packet->getInputMode()->value'), 'legacy getter normalized to int');
assertInput(str_contains($fixed, '$this->inputMode !== ($inputMode ='), 'change detection still intact');
assertInput(renetherPatchLegacyInputMode($fixed, $legacyEvent) === $fixed, 'idempotent');
assertInput(substr_count($fixed, '->value') === 1, 'single enum conversion');

$enumPlayer = str_replace('private int $inputMode', 'private InputMode $inputMode', $legacyPlayer);
$enumEvent = str_replace('private int $', 'private InputMode $', $legacyEvent);
assertInput(renetherPatchLegacyInputMode($enumPlayer, $enumEvent) === $enumPlayer, 'modern typed enum plugin preserved');
try{
    renetherPatchLegacyInputMode($enumPlayer, $legacyEvent);
    fwrite(STDERR, "FAIL: type mismatch should be rejected\n");
    exit(1);
}catch(RuntimeException $e){
    assertInput(str_contains($e->getMessage(), 'do not match'), 'mismatched event types rejected');
}
try{
    renetherPatchLegacyInputMode(str_replace('getInputMode()', 'getAnotherMode()', $legacyPlayer), $legacyEvent);
    fwrite(STDERR, "FAIL: unknown handler should be rejected\n");
    exit(1);
}catch(RuntimeException $e){
    assertInput(str_contains($e->getMessage(), 'Unexpected legacy'), 'unknown handler rejected');
}
echo "InputMode enum/int NGEssentials compatibility tests passed\n";

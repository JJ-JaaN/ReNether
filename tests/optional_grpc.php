<?php
declare(strict_types=1);

require_once __DIR__ . '/../scripts/compat_optional_grpc.php';

function checkGrpc(bool $ok, string $description) : void {
    if(!$ok){
        fwrite(STDERR, "FAIL: $description\n");
        exit(1);
    }
}

$original = <<<'PHP'
<?php
use Grpc\Call;
class NGEssentials{
    public function onEnable(): void{
        /** @phpstan-ignore-next-line */
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(fn() => Call::drainCompletionQueue(PHP_INT_MIN)), 1);
    }
}
PHP;

$patched = renetherPatchOptionalGrpcDrain($original);
checkGrpc(str_contains($patched, "class_exists(\\Grpc\\Call::class)"), "checks native grpc availability");
checkGrpc(str_contains($patched, "method_exists(\\Grpc\\Call::class, 'drainCompletionQueue')"), "checks for method");
checkGrpc(substr_count($patched, 'Call::drainCompletionQueue(PHP_INT_MIN)') === 1, "keeps scheduler once");
checkGrpc(str_contains($patched, 'gRPC extension unavailable'), "logs explicit warning");
checkGrpc(renetherPatchOptionalGrpcDrain($patched) === $patched, "idempotent");

try{
    renetherPatchOptionalGrpcDrain(str_replace('drainCompletionQueue', 'changedMethod', $original));
    fwrite(STDERR, "FAIL: unexpected scheduler should be rejected\n");
    exit(1);
}catch(RuntimeException $e){
    checkGrpc(str_contains($e->getMessage(), 'Expected exactly one'), "refuses unknown upstream format");
}

echo "Optional gRPC scheduler compatibility tests passed\n";

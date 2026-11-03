<?php
declare(strict_types=1);

/**
 * The stock NGEssentials uses a repeating scheduler callback to drain
 * Grpc\Call's completion queue. That optional extension isn't part of
 * the Axolotl PM5 PHP 8.4 runtime. Keep the callback when available;
 * skip it otherwise, without suppressing errors in other plugin code.
 */
function renetherPatchOptionalGrpcDrain(string $source) : string{
    $old = <<<'PHP'
        /** @phpstan-ignore-next-line */
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(fn() => Call::drainCompletionQueue(PHP_INT_MIN)), 1);
PHP;
    $new = <<<'PHP'
        // ReNether: the Axolotl PHP runtime may not include grpc.
        // Only schedule the queue drain when its native implementation exists.
        if (\class_exists(\Grpc\Call::class) &&
            \method_exists(\Grpc\Call::class, 'drainCompletionQueue')) {
            $this->getScheduler()->scheduleRepeatingTask(
                new ClosureTask(fn() => Call::drainCompletionQueue(PHP_INT_MIN)), 1
            );
        } else {
            $this->getLogger()->warning(
                'ReNether: PHP gRPC extension unavailable; optional completion-queue drain disabled.'
            );
        }
PHP;
    if(str_contains($source, 'ReNether: PHP gRPC extension unavailable')){
        if(str_contains($source, $new)){
            return $source;
        }
        throw new RuntimeException('Unknown previously modified NGEssentials gRPC scheduler');
    }
    if(substr_count($source, $old) !== 1){
        throw new RuntimeException('Expected exactly one NGEssentials gRPC scheduler; refusing patch');
    }
    return str_replace($old, $new, $source);
}

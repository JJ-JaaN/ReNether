<?php
declare(strict_types=1);

/**
 * Disable libReplay recording only when this PHP runtime has no ext-zstd.
 * Do not emulate ZSTD data with zlib: replay recordings would become
 * incompatible or corrupted. When the extension exists, upstream logic
 * remains untouched.
 */
function renetherPatchReplayBootstrap(string $source) : string{
    $before = <<<'PHP'
        if ($serverManager->enableReplayServer()) {
            new Replays($plugin, !$replay, $replay);
        }
PHP;
    $after = <<<'PHP'
        // ReNether: avoid boot-time crashes on runtimes without ext-zstd.
        // Recording stays fully enabled when the native codec is available.
        if ($serverManager->enableReplayServer()) {
            if (\defined('ZSTD_COMPRESS_LEVEL_DEFAULT') &&
                \function_exists('zstd_compress') &&
                \function_exists('zstd_uncompress')) {
                new Replays($plugin, !$replay, $replay);
            } else {
                $plugin->getLogger()->warning(
                    'ReNether: replay recording unavailable (PHP ext-zstd missing). ' .
                    'BedWars continues without recording; no replay files are modified.'
                );
            }
        }
PHP;
    if(str_contains($source, 'ReNether: replay recording unavailable (PHP ext-zstd missing)')){
        if(str_contains($source, $after)){
            return $source;
        }
        throw new RuntimeException('Unexpected previously patched replay bootstrap');
    }
    if(substr_count($source, $before) !== 1){
        throw new RuntimeException('Could not locate a unique NGEssentials replay bootstrap; refusing change');
    }
    return str_replace($before, $after, $source);
}

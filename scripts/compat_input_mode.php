<?php
declare(strict_types=1);

/**
 * The BedrockProtocol 64 PlayerAuthInputPacket getter returns the backed
 * InputMode enum. Older NGEssentials PHARs hold inputMode as int and pass it
 * to PlayerInputChangeEvent(int, int). Normalize the packet enum to its
 * numeric value only for that legacy pair. Newer enum-typed plugins remain
 * unchanged.
 *
 * @return string The adjusted NGPlayer.php PHAR member source.
 */
function renetherPatchLegacyInputMode(string $playerSource, string $eventSource) : string{
    $eventHasInt = (bool) preg_match('/\\bprivate\\s+int\\s+\\$newInputMode\\b/', $eventSource);
    $eventHasEnum = (bool) preg_match('/\\bprivate\\s+InputMode\\s+\\$newInputMode\\b/', $eventSource);
    $playerHasInt = (bool) preg_match('/\\b(?:private|protected|public)\\s+int\\s+\\$inputMode\\b/', $playerSource);
    $playerHasEnum = (bool) preg_match('/\\b(?:private|protected|public)\\s+InputMode\\s+\\$inputMode\\b/', $playerSource);

    $legacy = 'if ($this->inputMode !== ($inputMode = $packet->getInputMode())) {';
    $compat = 'if ($this->inputMode !== ($inputMode = $packet->getInputMode()->value)) {';

    if($eventHasInt && $playerHasInt && !$eventHasEnum && !$playerHasEnum){
        if(substr_count($playerSource, $compat) === 1 && !str_contains($playerSource, $legacy)){
            return $playerSource;
        }
        if(substr_count($playerSource, $legacy) !== 1 || str_contains($playerSource, $compat)){
            throw new RuntimeException('Unexpected legacy NGPlayer::handlePlayerAuthInput; refusing conversion');
        }
        return str_replace($legacy, $compat, $playerSource);
    }

    if($eventHasEnum && $playerHasEnum && !$eventHasInt && !$playerHasInt){
        // The current code consistently uses backed InputMode objects.
        if(substr_count($playerSource, $legacy) !== 1 || str_contains($playerSource, $compat)){
            throw new RuntimeException('Unexpected enum-typed NGPlayer input handling');
        }
        return $playerSource;
    }
    throw new RuntimeException('NGEssentials NGPlayer and PlayerInputChangeEvent inputMode types do not match expected legacy or modern ABI');
}

<?php
declare(strict_types=1);

/**
 * BedrockProtocol 64 (Bedrock 26.60) changed AddPlayerPacket::create():
 * argument 16 is now ?PassengerOfBlockData, before deviceId/buildPlatform.
 * Older built NetherGames PHARs omit it, passing the device ID into slot 16.
 *
 * Patch only the exact trailing argument structure used by the two
 * NGEssentials human NPC paths. Never modify unrelated AddPlayer calls.
 */
function renetherPatchNpcAddPlayer(string $source) : string{
    if(preg_match_all('/\bAddPlayerPacket::create\s*\(/', $source) !== 1){
        throw new RuntimeException('Expected exactly one AddPlayerPacket::create() call');
    }

    // The NGEssentials CustomHuman uses [] for links; CustomGeometryTrait
    // passes array_values($this->links). Both use an empty device ID.
    $pattern = '/(?P<links>\[\]|array_values\(\$this->links\))(?P<sep>\s*,\s*)(?P<passenger>null\s*,\s*)?(?P<rest>""\s*,\s*DeviceOS::UNKNOWN)/';
    $count = preg_match_all($pattern, $source, $hits, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    if($count !== 1){
        throw new RuntimeException('Expected exactly one NPC AddPlayerPacket links/device tail');
    }
    $match = $hits[0];
    if(isset($match['passenger']) && $match['passenger'][1] >= 0 &&
       trim($match['passenger'][0]) !== ''){
        return $source;
    }
    $offset = $match['rest'][1];
    // Preserve the original indentation after the links argument.
    $sep = $match['sep'][0];
    $indent = preg_match('/\n([ \t]*)$/', $sep, $space) ? $space[1] : '';
    $insertion = 'null,' . ($indent !== '' ? "\n" . $indent : ' ');
    $patched = substr($source, 0, $offset) . $insertion . substr($source, $offset);
    if(substr_count($patched, 'AddPlayerPacket::create(') !== 1 ||
       !str_contains($patched, $insertion . '""')){
        throw new RuntimeException('NPC AddPlayerPacket tail patch validation failed');
    }
    return $patched;
}

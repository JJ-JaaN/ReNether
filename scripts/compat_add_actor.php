<?php
declare(strict_types=1);

/**
 * BedrockProtocol 64 (26.60) requires 14 arguments for
 * AddActorPacket::create(), ending with ?PassengerOfBlockData.
 * Archived NGEssentials PHARs may contain the older 13-argument form.
 *
 * Count top-level call arguments using PHP tokens (not a regex over nested
 * expressions), append null only to the legacy form, and reject unexpected
 * argument counts. This is idempotent for already-current sources.
 */
function renetherPatchAddActorPacket(string $source) : string{
    $tokens = token_get_all($source);
    $texts = [];
    $offsets = [];
    $offset = 0;
    foreach($tokens as $token){
        $part = is_array($token) ? $token[1] : $token;
        $texts[] = $part;
        $offsets[] = $offset;
        $offset += strlen($part);
    }

    $next = static function(int $i) use ($tokens, $texts) : int{
        $length = count($tokens);
        while($i < $length && is_array($tokens[$i]) &&
            in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)){
            ++$i;
        }
        return $i;
    };

    $insertions = [];
    $calls = 0;
    $count = count($tokens);
    for($i = 0; $i < $count; ++$i){
        if(!is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING ||
            $texts[$i] !== 'AddActorPacket'){
            continue;
        }
        $colon = $next($i + 1);
        $method = $next($colon + 1);
        $open = $next($method + 1);
        if(($texts[$colon] ?? null) !== '::' ||
           ($texts[$method] ?? null) !== 'create' ||
           ($texts[$open] ?? null) !== '('){
            continue;
        }
        ++$calls;

        $depth = 0;
        $commas = 0;
        $lastTopLevelWasComma = false;
        $sawArgument = false;
        $close = -1;
        for($j = $open + 1; $j < $count; ++$j){
            $value = $texts[$j];
            $isWhitespace = is_array($tokens[$j]) &&
                in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
            if($isWhitespace){
                continue;
            }
            if($depth === 0 && $value === ')'){
                $close = $j;
                break;
            }
            if($depth === 0 && $value === ','){
                ++$commas;
                $lastTopLevelWasComma = true;
                continue;
            }
            if(in_array($value, ['(', '[', '{'], true)){
                ++$depth;
                $sawArgument = true;
                if($depth === 1){
                    $lastTopLevelWasComma = false;
                }
            }elseif(in_array($value, [')', ']', '}'], true)){
                if($depth <= 0){
                    throw new RuntimeException('Unbalanced AddActorPacket call');
                }
                --$depth;
                if($depth === 0){
                    $lastTopLevelWasComma = false;
                }
            }elseif($depth === 0){
                $sawArgument = true;
                $lastTopLevelWasComma = false;
            }
        }
        if($close === -1 || !$sawArgument){
            throw new RuntimeException('Cannot parse AddActorPacket::create() argument list');
        }
        $args = $commas + ($lastTopLevelWasComma ? 0 : 1);
        if($args === 13){
            $insertions[] = [$offsets[$close], $lastTopLevelWasComma ? 'null' : ', null'];
        }elseif($args !== 14){
            throw new RuntimeException("Unexpected AddActorPacket::create() argument count: $args (expected 13 or 14)");
        }
        $i = $close;
    }
    if($calls === 0){
        throw new RuntimeException('Expected at least one AddActorPacket::create() call');
    }
    foreach(array_reverse($insertions) as [$position, $addition]){
        $source = substr_replace($source, $addition, $position, 0);
    }
    return $source;
}

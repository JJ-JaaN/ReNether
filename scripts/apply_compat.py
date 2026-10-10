#!/usr/bin/env python3
"""Experimental NetherGames API compatibility patch for Axolotl-PM 5.49.1.

NOT full multi-protocol support and NOT a verified NetherGames BedWars port.
Derived compatibility APIs retain upstream LGPL notices and attribution.
"""
from pathlib import Path
import sys

root = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else Path.cwd()
if not (root / "src/Server.php").is_file() or not (root / "composer.json").is_file():
    raise SystemExit("Expected Axolotl-PM source checkout")
if "axolotl-pm/pocketmine-mp" not in (root / "composer.json").read_text():
    raise SystemExit("Refusing non-Axolotl source checkout")
changes = []

def modify(name: str, anchor: str, replacement: str, guard: str):
    path = root / name
    source = path.read_text()
    if guard in source:
        print("Already patched", name, guard)
        return
    count = source.count(anchor)
    if count != 1:
        raise SystemExit(f"Refusing {name}: expected 1 anchor, got {count}: {anchor[:100]!r}")
    path.write_text(source.replace(anchor, replacement, 1))
    changes.append(name)

# NetherGames libVanilla reserves three vanilla item types not defined in
# Axolotl-PM 5.49.1. Keep upstream numeric IDs and move the dynamic allocator
# forward to prevent custom items from colliding with CROSSBOW/ELYTRA/SHIELD.
name = "src/item/ItemTypeIds.php"
anchor = "\tpublic const FIRST_UNUSED_ITEM_ID = 20326;"
modify(name, anchor, "\tpublic const CROSSBOW = 20326;\n"
                    "\tpublic const ELYTRA = 20327;\n"
                    "\tpublic const SHIELD = 20328;\n"
                    "\n\tpublic const FIRST_UNUSED_ITEM_ID = 20329;",
       "public const CROSSBOW = 20326;")

name = "src/network/mcpe/NetworkSession.php"
anchor = "\tpublic function getTypeConverter() : TypeConverter{ return $this->typeConverter; }"
modify(name, anchor,
    "\t/** Current Bedrock protocol only; compatibility API, not multi-protocol. */\n"
    "\tpublic function getProtocolId() : int{\n"
    "\t\treturn ProtocolInfo::CURRENT_PROTOCOL;\n"
    "\t}\n\n" + anchor,
    "Current Bedrock protocol only; compatibility API")

anchor = "\t\t\t$func();\n\t\t\t$this->disconnectGuard = false;"
modify(name, anchor,
    "\t\t\t$func();\n"
    "\t\t\t(new \\pocketmine\\event\\player\\SessionDisconnectEvent($this))->call();\n"
    "\t\t\t$this->disconnectGuard = false;",
    "new \\pocketmine\\event\\player\\SessionDisconnectEvent($this)")

name = "src/Server.php"
anchor = "\tpublic function getOnlinePlayers() : array{\n\t\treturn $this->playerList;\n\t}"
modify(name, anchor, anchor + r"""

	/** Find a currently connected player by Microsoft Xbox user ID. */
	public function getPlayerByXuid(string $xuid) : ?Player{
		foreach($this->getOnlinePlayers() as $player){
			if($player->getXuid() === $xuid){
				return $player;
			}
		}
		return null;
	}""", "Find a currently connected player by Microsoft Xbox user ID")

name = "src/network/mcpe/convert/TypeConverter.php"
anchor = "\tpublic function getItemTranslator() : ItemTranslator{ return $this->itemTranslator; }"
modify(name, anchor, anchor + r"""

	/**
	 * @param \pocketmine\player\Player[] $players
	 * @return array{array<int, self>, array<int, array<int, \pocketmine\player\Player>>}
	 */
	public static function sortByConverter(array $players) : array{
		$converters = [];
		$recipients = [];
		foreach($players as $player){
			$converter = $player->getNetworkSession()->getTypeConverter();
			$key = spl_object_id($converter);
			$converters[$key] = $converter;
			$recipients[$key][spl_object_id($player)] = $player;
		}
		return [$converters, $recipients];
	}

	/**
	 * @param \pocketmine\player\Player[] $players
	 * @phpstan-param \Closure(self) : \pocketmine\network\mcpe\protocol\ClientboundPacket[] $factory
	 */
	public static function broadcastByTypeConverter(array $players, \Closure $factory) : void{
		[$converters, $recipients] = self::sortByConverter($players);
		foreach($converters as $key => $converter){
			$packets = $factory($converter);
			if($packets !== []){
				\pocketmine\network\mcpe\NetworkBroadcastUtils::broadcastPackets($recipients[$key], $packets);
			}
		}
	}""", "public static function broadcastByTypeConverter(")

# Axolotl-PM is single-protocol; NetherGames plugins enumerate per-protocol
# converters and subscribe to their creation. Implement the compatible API
# using Axolotl's existing singleton, without pretending to support old clients.
name = "src/network/mcpe/convert/TypeConverter.php"
anchor = "\tuse SingletonTrait;"
modify(name, anchor, anchor + r"""

	/** @var list<\Closure(self): void> */
	private static array $compatCreationListeners = [];

	/** Only the current Bedrock protocol is supported by Axolotl-PM. */
	public static function getAll() : array{
		return [\pocketmine\network\mcpe\protocol\ProtocolInfo::CURRENT_PROTOCOL => self::getInstance()];
	}

	/** @param \Closure(self): void $listener */
	public static function addCreationListener(\Closure $listener) : void{
		self::$compatCreationListeners[] = $listener;
	}

	public function getProtocolId() : int{
		return \pocketmine\network\mcpe\protocol\ProtocolInfo::CURRENT_PROTOCOL;
	}
""", "private static array $compatCreationListeners")

anchor = "\t\t$this->skinAdapter = new LegacySkinAdapter();"
modify(name, anchor, anchor + r"""
		foreach(self::$compatCreationListeners as $listener){
			$listener($this);
		}""", "foreach(self::$compatCreationListeners as $listener)")

name = "src/event/player/PlayerCreationEvent.php"
anchor = "\tpublic function __construct(private NetworkSession $session){}"
modify(name, anchor, r"""	/** @var list<\pocketmine\promise\Promise> */
	private array $creationPromises = [];

	public function addPromise(\pocketmine\promise\Promise $promise) : void{
		$this->creationPromises[] = $promise;
	}

	/** @return list<\pocketmine\promise\Promise> */
	public function getCreationPromises() : array{
		return $this->creationPromises;
	}

""" + anchor, "public function getCreationPromises(")

name = "src/Server.php"
anchor = "\t\t$ev = new PlayerCreationEvent($session);\n\t\t$ev->call();\n\t\t$class = $ev->getPlayerClass();"
modify(name, anchor, anchor + "\n\t\t$creationPromises = $ev->getCreationPromises();",
       "$creationPromises = $ev->getCreationPromises();")

# Wait until the plugin's async pre-login initialization resolves.
begin = "\t\tif($playerPos === null){ //new player or no valid position due to world not being loaded"
end = "\n\t\treturn $playerPromiseResolver->getPromise();"
source = (root / name).read_text()
if "$beginSpawning = function()" not in source:
    if source.count(begin) != 1 or source.count(end) != 1:
        raise SystemExit("Refusing createPlayer patch: unmatched source anchors")
    start = source.index(begin)
    finish = source.index(end, start)
    replacement = r"""		$beginSpawning = function() use ($playerPos, $world, $createPlayer, $playerPromiseResolver, $session) : void{
			if(!$session->isConnected()){
				$playerPromiseResolver->reject();
				return;
			}
			if($playerPos === null){
				$world->requestSafeSpawn()->onCompletion(
					function(Position $spawn) use ($createPlayer, $playerPromiseResolver, $session, $world) : void{
						if(!$session->isConnected()){
							$playerPromiseResolver->reject();
							return;
						}
						$createPlayer(Location::fromObject($spawn, $world));
					},
					function() use ($playerPromiseResolver, $session) : void{
						if($session->isConnected()){
							$session->disconnectWithError(KnownTranslationFactory::pocketmine_disconnect_error_respawn());
						}
						$playerPromiseResolver->reject();
					}
				);
			}else{
				$createPlayer($playerPos);
			}
		};

		if($creationPromises !== []){
			Promise::all($creationPromises)->onCompletion(
				$beginSpawning,
				function() use ($playerPromiseResolver, $session) : void{
					if($session->isConnected()){
						$session->disconnectWithError("Failed to create player");
					}
					$playerPromiseResolver->reject();
				}
			);
		}else{
			$beginSpawning();
		}
"""
    (root / name).write_text(source[:start] + replacement + source[finish:])
    changes.append(name)
else:
    print("Already patched asynchronous player creation")

extra = {
    "src/event/player/SessionDisconnectEvent.php": r"""<?php
/* NetherGames-inspired PocketMine API compatibility, LGPL-3.0-or-later. */
declare(strict_types=1);
namespace pocketmine\event\player;
use pocketmine\event\Event;
use pocketmine\network\mcpe\NetworkSession;
class SessionDisconnectEvent extends Event{
	public function __construct(private NetworkSession $session){}
	public function getSession() : NetworkSession{ return $this->session; }
}
""",
    "src/world/particle/ProtocolParticle.php": r"""<?php
/* NetherGames-inspired PocketMine API compatibility, LGPL-3.0-or-later. */
declare(strict_types=1);
namespace pocketmine\world\particle;
abstract class ProtocolParticle implements Particle{
	protected int $protocolId;
	public function setProtocolId(int $protocolId) : void{ $this->protocolId = $protocolId; }
}
"""
}
for name, source in extra.items():
    path = root / name
    if path.exists():
        if path.read_text() != source:
            raise SystemExit(f"Refusing to replace existing {name}")
    else:
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(source)
        changes.append(name)
print("Patched:", ", ".join(sorted(set(changes))) if changes else "already patched")
print("WARNING: API shim is incomplete: old NetherGames protocol constants and more still need porting.")

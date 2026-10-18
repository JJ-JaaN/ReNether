<?php
declare(strict_types=1);

namespace renether\branding;

use pocketmine\block\BaseSign;
use pocketmine\block\utils\SignText;
use pocketmine\event\Listener;
use pocketmine\event\world\ChunkLoadEvent;
use pocketmine\plugin\PluginBase;
use pocketmine\world\format\Chunk;
use pocketmine\world\World;
use Throwable;

/**
 * Changes legacy visible sign text through the normal world API.
 * No world files are replaced. Other signs, geometry, and credits remain.
 */
final class Main extends PluginBase implements Listener{
    private int $changedSigns = 0;

    protected function onEnable(): void{
        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        $scanned = 0;
        foreach($this->getServer()->getWorldManager()->getWorlds() as $world){
            foreach($world->getLoadedChunks() as $chunk){
                $scanned += $this->rebrandChunk($world, $chunk);
            }
        }
        $this->getLogger()->info("ReNether world branding active: changed $scanned already-loaded signs. Future chunk signs will be checked automatically.");
    }

    public function onChunkLoad(ChunkLoadEvent $event): void{
        if(!$event->isNewChunk()){
            $this->rebrandChunk($event->getWorld(), $event->getChunk());
        }
    }

    private function rebrandChunk(World $world, Chunk $chunk): int{
        $changed = 0;
        foreach($chunk->getTiles() as $tile){
            if(!$tile instanceof \pocketmine\block\tile\Sign){
                continue;
            }
            try{
                $position = $tile->getPosition();
                $block = $world->getBlock($position);
                if(!$block instanceof BaseSign){
                    continue;
                }
                $dirty = false;
                foreach([true, false] as $frontFace){
                    $text = $block->getFaceText($frontFace);
                    $before = $text->getLines();
                    $after = array_map(self::renameLegacyText(...), $before);
                    if($before !== $after){
                        $block->setFaceText($frontFace, new SignText($after, $text->getBaseColor(), $text->isGlowing()));
                        $dirty = true;
                    }
                }
                if($dirty){
                    // Ensures persistence and updates the sign for players.
                    $world->setBlock($position, $block, false);
                    ++$changed;
                    ++$this->changedSigns;
                }
            }catch(Throwable $error){
                $this->getLogger()->warning("Sign update failed in " . $world->getFolderName() . ": " . $error->getMessage());
            }
        }
        if($changed > 0){
            $this->getLogger()->info("Updated $changed sign(s) in " . $world->getFolderName() . ". Total: $this->changedSigns");
        }
        return $changed;
    }

    private static function renameLegacyText(string $line): string{
        // Only replace legacy branding, not the word "Nether" in ordinary text.
        return preg_replace(
            [
                '/Nether(?:§[0-9a-fk-or])*[ ]*Games/iu',
                '/(?<![\p{L}\p{N}])ngmc\.co(?![\p{L}\p{N}])/iu',
                '/(?<![\p{L}\p{N}])N(?:§[0-9a-fk-or])*G(?![\p{L}\p{N}])/iu'
            ],
            ['ReNether', 'ReNether', 'ReNether'],
            $line
        ) ?? $line;
    }
}

<?php
declare(strict_types=1);

namespace renether\hubpvp;

use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\Listener;
use pocketmine\permission\DefaultPermissions;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;

/**
 * Restricts player-on-player damage to operators in the main Hub only.
 * All BedWars/SkyWars arenas and other lobby worlds keep their own rules.
 */
final class Main extends PluginBase implements Listener{
    private const MAIN_HUB_WORLD = "Hub";

    protected function onEnable(): void{
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getLogger()->info("Hub combat protection active: only operators can damage players in world " . self::MAIN_HUB_WORLD);
    }

    /**
     * Includes player-launched projectiles (EntityDamageByChildEntityEvent).
     * Never uncancel damage that another plugin already disallowed.
     *
     * @priority HIGHEST
     */
    public function onPlayerDamage(EntityDamageByEntityEvent $event): void{
        $victim = $event->getEntity();
        if(!$victim instanceof Player || $victim->getWorld()->getFolderName() !== self::MAIN_HUB_WORLD){
            return;
        }

        $attacker = $event->getDamager();
        if($attacker instanceof Player && !$attacker->hasPermission(DefaultPermissions::ROOT_OPERATOR)){
            $event->cancel();
        }
    }
}

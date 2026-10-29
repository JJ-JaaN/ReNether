<?php
declare(strict_types=1);

// Tiny isolated smoke test for the plugin listener; no server/world data needed.
namespace pocketmine\event {
    interface Listener {}
}
namespace pocketmine\plugin {
    class PluginBase {}
}
namespace pocketmine\permission {
    final class DefaultPermissions {
        public const ROOT_OPERATOR = "pocketmine.group.operator";
    }
}
namespace pocketmine\player {
    class Player {
        public function __construct(private readonly \TestWorld $world, private readonly bool $operator) {}
        public function getWorld(): \TestWorld { return $this->world; }
        public function hasPermission(string $permission): bool {
            return $permission === \pocketmine\permission\DefaultPermissions::ROOT_OPERATOR && $this->operator;
        }
    }
}
namespace pocketmine\event\entity {
    class EntityDamageByEntityEvent {
        private bool $cancelled = false;
        public function __construct(private readonly mixed $victim, private readonly mixed $damager) {}
        public function getEntity(): mixed { return $this->victim; }
        public function getDamager(): mixed { return $this->damager; }
        public function cancel(): void { $this->cancelled = true; }
        public function isCancelled(): bool { return $this->cancelled; }
    }
    class EntityDamageByChildEntityEvent extends EntityDamageByEntityEvent {}
}
namespace {
    class TestWorld {
        public function __construct(private readonly string $name) {}
        public function getFolderName(): string { return $this->name; }
    }

    require __DIR__ . "/../plugins/ReNetherHubPvP/src/renether/hubpvp/Main.php";

    $hub = new TestWorld("Hub");
    $arena = new TestWorld("Match-bw-0");
    $public = new \pocketmine\player\Player($hub, false);
    $staff = new \pocketmine\player\Player($hub, true);
    $arenaPlayer = new \pocketmine\player\Player($arena, false);
    $listener = new \renether\hubpvp\Main();

    $cases = [
        ["public attack in Hub", new \pocketmine\event\entity\EntityDamageByEntityEvent($public, $public), true],
        ["public attack on OP in Hub", new \pocketmine\event\entity\EntityDamageByEntityEvent($staff, $public), true],
        ["OP attack in Hub", new \pocketmine\event\entity\EntityDamageByEntityEvent($public, $staff), false],
        ["public attack in BedWars", new \pocketmine\event\entity\EntityDamageByEntityEvent($arenaPlayer, $public), false],
        ["non-player damage in Hub", new \pocketmine\event\entity\EntityDamageByEntityEvent($public, new \stdClass()), false],
        ["hitting NPC in Hub", new \pocketmine\event\entity\EntityDamageByEntityEvent(new \stdClass(), $public), false],
        ["public projectile in Hub", new \pocketmine\event\entity\EntityDamageByChildEntityEvent($public, $public), true],
        ["OP projectile in Hub", new \pocketmine\event\entity\EntityDamageByChildEntityEvent($public, $staff), false],
    ];

    foreach ($cases as [$name, $event, $shouldCancel]) {
        $listener->onPlayerDamage($event);
        if ($event->isCancelled() !== $shouldCancel) {
            throw new \RuntimeException("Failed: " . $name);
        }
        echo "PASS: " . $name . "\n";
    }

    echo "Hub PvP checks passed\n";
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/../scripts/compat_npc_addplayer.php';

function checkNpc(bool $ok, string $label) : void{
    if(!$ok){
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
}

$humanLegacy = <<<'PHP'
<?php
class CustomHuman {
    public function spawnPacket(){
        return AddPlayerPacket::create(
            $this->getUuid(),
            $this->getUsername(),
            $this->getId(),
            "",
            $location->asVector3(),
            null,
            $location->pitch, $location->yaw, $location->yaw,
            ItemStackWrapper::legacy(ItemStack::null()),
            ProtocolGameMode::SURVIVAL,
            $this->metadata->getAll(),
            new PropertySyncData([], []),
            new AbilitiesData(CommandPermissions::NORMAL, PlayerPermissions::VISITOR, $this->getId(), [
                new AbilitiesLayer(0, [], 0.0, 0.0, 0.0)
            ]),
            [],
            "",
            DeviceOS::UNKNOWN
        );
    }
}
PHP;

$humanPatched = renetherPatchNpcAddPlayer($humanLegacy);
checkNpc(str_contains($humanPatched, "[],\n            null,\n            \"\""), 'adds missing passengerBlockData for CustomHuman');
checkNpc(renetherPatchNpcAddPlayer($humanPatched) === $humanPatched, 'CustomHuman patch idempotent');
checkNpc(substr_count($humanPatched, 'null,') === 2, 'preserves old motion null and inserts one passenger null');

$geometryLegacy = <<<'PHP'
<?php
trait CustomGeometryTrait {
    public function spawn(){
        $session->sendDataPacket(AddPlayerPacket::create(
            $this->skinUuid, $name, $entityId,
            "", $location, $this->motion,
            $location->pitch, $location->yaw, $location->yaw,
            ItemStackWrapper::legacy(ItemStack::null()),
            ProtocolGameMode::SURVIVAL,
            $this->getAllNetworkData(),
            new PropertySyncData([], []),
            new AbilitiesData(1, 2, 3, [new AbilitiesLayer(0, [], 0, 0, 0)]),
            array_values($this->links),
            "", DeviceOS::UNKNOWN
        ));
    }
}
PHP;

$geometryPatched = renetherPatchNpcAddPlayer($geometryLegacy);
checkNpc(str_contains($geometryPatched, "array_values(\$this->links),\n            null,\n            \"\""), 'adds missing passengerBlockData for CustomGeometryTrait');
checkNpc(renetherPatchNpcAddPlayer($geometryPatched) === $geometryPatched, 'geometry patch idempotent');

$upstream = str_replace("            [],\n            \"\"", "            [],\n            null,\n            \"\"", $humanLegacy);
checkNpc(renetherPatchNpcAddPlayer($upstream) === $upstream, 'preserves upstream 18-argument calls');

try{
    renetherPatchNpcAddPlayer(str_replace('DeviceOS::UNKNOWN', 'DeviceOS::ANDROID', $humanLegacy));
    fwrite(STDERR, "FAIL: unknown add player packet tail should fail\n");
    exit(1);
}catch(RuntimeException $e){
    checkNpc(str_contains($e->getMessage(), 'Expected exactly one NPC'), 'rejects unknown format');
}
echo "NPC AddPlayerPacket 26.60 compatibility tests passed\n";

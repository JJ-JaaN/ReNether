<?php
declare(strict_types=1);

/**
 * Offline regression test: a generated PHAR with the pinned upstream
 * CheckRegistry/ResultsHandler anchors must be patched, remain lintable,
 * and retain active-match checks. No network or PocketMine server needed.
 */
if (($argv[1] ?? null) === '--verify') {
    // Use a fresh process: the process which CREATED the archive may have
    // cached its old manifest/offsets before the patcher changed them.
    $pharFile = $argv[2] ?? '';
    $regPath = 'src/ReinfyTeam/Zuri/check/CheckRegistry.php';
    $resPath = 'src/ReinfyTeam/Zuri/check/ResultsHandler.php';
    $phar = new Phar($pharFile);
    $reg = $phar[$regPath]->getContent();
    $res = $phar[$resPath]->getContent();

    if (substr_count($reg, 'function isReNetherWaitingWorld') !== 1 ||
        substr_count($reg, 'ReNether: never schedule Speed tests') !== 1 ||
        substr_count($res, 'ReNether: discard Speed results') !== 1 ||
        substr_count($res, 'ReNether: let OP/bypass players see their own detections.') !== 1 ||
        substr_count($res, '$monitorOnly ? "flag" : $check->getPunishment()') !== 1 ||
        str_contains($res, 'return; // if the player has bypass permission')) {
        throw new RuntimeException('Patcher is not idempotent or failed insertion');
    }
    if (strpos($res, 'ReNether: discard Speed results') >
        strpos($res, '$playerZuri = ZuriAC::getPlayerManager()')) {
        throw new RuntimeException('Queued flags were filtered too late');
    }
    unset($phar);

    require 'phar://' . $pharFile . '/' . $regPath;
    $cases = [
        'Hub' => true,
        'hub' => true,
        'Lobby' => true,
        'ArcadeLobby' => true,
        'WaitingLobby' => true,
        'BWSublobby2024' => true,
        'Waiting-bw-0' => true,
        'Waiting-BW-12' => true,
        'Waiting-sw-9' => true,
        'Match-bw-0' => false,
        'Match-sw-9' => false,
        'arena_bedwars' => false
    ];
    foreach ($cases as $world => $expected) {
        $actual = \ReinfyTeam\Zuri\check\CheckRegistry::isReNetherWaitingWorld($world);
        if ($actual !== $expected) {
            throw new RuntimeException("World '$world': expected " . (int) $expected . " got " . (int) $actual);
        }
        echo "PASS: $world " . ($actual ? 'excluded' : 'protected') . "\n";
    }
    // Runtime smoke test of the *patched* fixture: bypass players still
    // receive a flag-style outcome, ordinary players keep their punishment,
    // and a clean check or protected lobby doesn't produce a violation.
    class FakeWorld {
        public function __construct(private readonly string $folderName) {}
        public function getFolderName(): string { return $this->folderName; }
    }
    class FakePlayer {
        public function __construct(public bool $bypass, private string $worldName) {}
        public function getWorld(): FakeWorld { return new FakeWorld($this->worldName); }
    }
    class FakeCheck {
        public function __construct(private string $punishment, private string $name) {}
        public function getPunishment(): string { return $this->punishment; }
        public function getName(): string { return $this->name; }
    }
    class FakePlayerProfile {
        public function __construct(private bool $bypass) {}
        public function hasBypassPermission(): bool { return $this->bypass; }
    }
    class FakePlayerManager {
        public function get(FakePlayer $player): FakePlayerProfile {
            return new FakePlayerProfile($player->bypass);
        }
    }
    class FakeZuri {
        public static function getPlayerManager(): FakePlayerManager {
            return new FakePlayerManager();
        }
    }
    class FakeUtils {
        public static function readArray(mixed $value): array { return is_array($value) ? $value : []; }
        public static function readBool(mixed $value): bool { return $value === true; }
    }

    class_alias(FakePlayer::class, 'pocketmine\player\Player');
    class_alias(FakeCheck::class, 'ReinfyTeam\Zuri\check\Check');
    class_alias(FakeZuri::class, 'ReinfyTeam\Zuri\ZuriAC');
    class_alias(FakeUtils::class, 'ReinfyTeam\Zuri\utils\Utils');
    require 'phar://' . $pharFile . '/' . $resPath;

    $handler = \ReinfyTeam\Zuri\check\ResultsHandler::class;
    $cases = [
        ['operator with kick config', ['failed' => true, 'bypass' => true, 'punishment' => 'kick'], 'flag'],
        ['operator with ban config', ['failed' => true, 'bypass' => true, 'punishment' => 'ban'], 'flag'],
        ['operator with no detection', ['failed' => false, 'bypass' => true, 'punishment' => 'ban'], null],
        ['public player kick config', ['failed' => true, 'bypass' => false, 'punishment' => 'kick'], 'kick'],
        ['public player ban config', ['failed' => true, 'bypass' => false, 'punishment' => 'ban'], 'ban'],
        ['operator in Hub Speed', ['failed' => true, 'bypass' => true, 'punishment' => 'ban', 'checkName' => 'Speed', 'world' => 'Hub'], null],
        ['operator in match Speed', ['failed' => true, 'bypass' => true, 'punishment' => 'ban', 'checkName' => 'Speed', 'world' => 'Match-bw-0'], 'flag'],
    ];
    foreach ($cases as [$label, $input, $expected]) {
        $handler::$lastPunishment = null;
        $handler::handle($input);
        if ($handler::$lastPunishment !== $expected) {
            throw new RuntimeException("Failed $label: " . var_export($handler::$lastPunishment, true));
        }
        echo "PASS: $label\n";
    }
    echo "PASS: Zuri bypass players are monitored without kick/ban\n";
    echo "PASS: Zuri lobby Speed patch is idempotent and leaves match worlds protected\n";
    exit(0);
}

$pharFile = sys_get_temp_dir() . '/renether-zuri-test-' . getmypid() . '.phar';

try {
    $registrySource = <<<'PHP'
<?php
namespace ReinfyTeam\Zuri\check;
use pocketmine\player\Player;
use ReinfyTeam\Zuri\ZuriAC;
class CheckRegistry {
    private array $checks = [];

    public function spawnCheck(array $data, int $type): void {
        $checks = $this->getChecksByType($type);
		foreach ($checks as $check) {
			ZuriAC::getCheckQueue()->addCheck($data, $check);
		}
    }

    public function getChecksByType(int $type): array {
        return $this->checks;
    }
}
PHP;
    $resultsSource = <<<'PHP'
<?php
namespace ReinfyTeam\Zuri\check;
use pocketmine\player\Player;
use ReinfyTeam\Zuri\ZuriAC;
use ReinfyTeam\Zuri\utils\Utils;
class ResultsHandler {
    public static ?string $lastPunishment = null;

    public static function handle(array $result): void {
        $player = new Player((bool)($result["bypass"] ?? false), $result["world"] ?? "Match-bw-0");
        $checkInstance = new Check($result["punishment"] ?? "kick", $result["checkName"] ?? "Reach");
		$playerZuri = ZuriAC::getPlayerManager()->get($player);

		$externalData = Utils::readArray($result["externalData"] ?? null);
		if ($playerZuri->hasBypassPermission()) {
			return; // if the player has bypass permission, we do not apply any violations or punishments
		}

		if (Utils::readBool($result["failed"]) === true) {
			self::handlePunishment($player, $checkInstance);
		}
    }

	private static function handlePunishment(Player $player, Check $check) : void {
        $punishment = $check->getPunishment();
        self::$lastPunishment = $punishment;
    }
}
PHP;
    $registryMember = 'src/ReinfyTeam/Zuri/check/CheckRegistry.php';
    $resultsMember = 'src/ReinfyTeam/Zuri/check/ResultsHandler.php';

    $phar = new Phar($pharFile);
    $phar[$registryMember] = $registrySource;
    $phar[$resultsMember] = $resultsSource;
    unset($phar);

    $patchScript = __DIR__ . '/../scripts/patch_zuri_phar.php';
    for ($pass = 1; $pass <= 2; ++$pass) {
        $command = [
            PHP_BINARY,
            '-dphar.readonly=0',
            $patchScript,
            $pharFile
        ];
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to run patcher');
        }
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            throw new RuntimeException("Patch pass $pass failed: $output");
        }
    }

    $process = proc_open(
        [PHP_BINARY, __FILE__, '--verify', $pharFile],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start clean PHAR verifier process');
    }
    echo stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException("Zuri PHAR verification failed: $errors");
    }
} finally {
    @unlink($pharFile);
}

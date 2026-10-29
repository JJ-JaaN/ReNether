<?php
declare(strict_types=1);

/**
 * Offline regression test: a generated PHAR with the pinned upstream
 * CheckRegistry/ResultsHandler anchors must be patched, remain lintable,
 * and retain active-match checks. No network or PocketMine server needed.
 */
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
use ReinfyTeam\Zuri\ZuriAC;
use ReinfyTeam\Zuri\utils\Utils;
class ResultsHandler {
    public static function handle(array $result): void {
        $player = null;
        $checkInstance = null;
		$playerZuri = ZuriAC::getPlayerManager()->get($player);

		$externalData = Utils::readArray($result["externalData"] ?? null);
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

    $phar = new Phar($pharFile);
    $reg = $phar[$registryMember]->getContent();
    $res = $phar[$resultsMember]->getContent();

    if (substr_count($reg, 'function isReNetherWaitingWorld') !== 1 ||
        substr_count($reg, 'ReNether: never schedule Speed tests') !== 1 ||
        substr_count($res, 'ReNether: discard Speed results') !== 1) {
        throw new RuntimeException('Patcher was not idempotent or failed insertion: method=' .
            substr_count($reg, 'function isReNetherWaitingWorld') .
            ' schedule=' . substr_count($reg, 'ReNether: never schedule Speed tests') .
            ' results=' . substr_count($res, 'ReNether: discard Speed results') .
            ' registry_preview=' . substr($reg, strpos($reg, 'foreach ($checks as $check)'), 450) .
            ' results_preview=' . substr($res, strpos($res, '$playerZuri =') - 200, 400));
    }
    if (strpos($res, 'ReNether: discard Speed results') >
        strpos($res, '$playerZuri = ZuriAC::getPlayerManager()')) {
        throw new RuntimeException('Queued flags were filtered too late');
    }
    unset($phar);

    require 'phar://' . $pharFile . '/' . $registryMember;
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
    echo "PASS: Zuri lobby Speed patch is idempotent and leaves match worlds protected\n";
} finally {
    @unlink($pharFile);
}

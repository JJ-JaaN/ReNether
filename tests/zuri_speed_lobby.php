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
        substr_count($res, 'ReNether: discard Speed results') !== 1) {
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

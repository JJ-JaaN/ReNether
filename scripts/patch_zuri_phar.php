<?php
declare(strict_types=1);

/**
 * ReNether compatibility patch for ReinfyTeam/Zuri 1.4.2-BETA PHAR.
 *
 * NetherGames creates pre-match waiting worlds like Waiting-bw-0.
 * These worlds aren't active matches and must not accumulate Speed flags.
 * Patch both check scheduling and asynchronous result handling, since
 * a player may teleport while a worker result is still queued.
 *
 * Keep the upstream Zuri sources unchanged; patch the locally-built PHAR.
 */
if ($argc !== 2) {
    fwrite(STDERR, "Usage: php -dphar.readonly=0 scripts/patch_zuri_phar.php <Zuri.phar>\n");
    exit(2);
}

$path = $argv[1];
if (!is_file($path)) {
    fwrite(STDERR, "ReNether Zuri: PHAR not found: $path\n");
    exit(1);
}

$files = [
    'src/ReinfyTeam/Zuri/check/CheckRegistry.php',
    'src/ReinfyTeam/Zuri/check/ResultsHandler.php'
];

try {
    $phar = new Phar($path);
    foreach ($files as $file) {
        if (!isset($phar[$file])) {
            throw new RuntimeException("Missing Zuri PHAR member: $file");
        }
    }

    $registry = $phar[$files[0]]->getContent();
    $results = $phar[$files[1]]->getContent();

    // Exact names used by ReNether's migration and NetherGames' Arena API.
    // Waiting-<minigame>-<id> is generated for each pre-match lobby, while
    // Match-<minigame>-<id> remains protected by ordinary Speed checks.
    $method = <<<'PHP'
    /**
     * ReNether: static hubs and NetherGames pre-match waiting worlds.
     * Active Match-... worlds are deliberately NOT exempt.
     */
    public static function isReNetherWaitingWorld(string $worldName): bool {
        $name = \strtolower($worldName);
        return \in_array($name, [
            "hub",
            "lobby",
            "arcadelobby",
            "waitinglobby",
            "bwsublobby2024"
        ], true) || \str_starts_with($name, "waiting-");
    }

PHP;

    $scheduleBefore = <<<'PHP'
		foreach ($checks as $check) {
			ZuriAC::getCheckQueue()->addCheck($data, $check);
PHP;
    $scheduleAfter = <<<'PHP'
		foreach ($checks as $check) {
			// ReNether: never schedule Speed tests inside waiting lobbies.
			// Other module checks are unaffected.
			$player = $data["player"] ?? null;
			if ($check->getName() === "Speed" && $player instanceof Player &&
				self::isReNetherWaitingWorld($player->getWorld()->getFolderName())) {
				continue;
			}
			ZuriAC::getCheckQueue()->addCheck($data, $check);
PHP;

    $resultsBefore = <<<'PHP'
		$playerZuri = ZuriAC::getPlayerManager()->get($player);

		$externalData = Utils::readArray($result["externalData"] ?? null);
PHP;
    $resultsAfter = <<<'PHP'
		// ReNether: discard Speed results from the lobby, including any
		// queued before a world transition. Do this before debug and VL.
		if ($checkInstance->getName() === "Speed" &&
			CheckRegistry::isReNetherWaitingWorld($player->getWorld()->getFolderName())) {
			return;
		}

		$playerZuri = ZuriAC::getPlayerManager()->get($player);

		$externalData = Utils::readArray($result["externalData"] ?? null);
PHP;

    // Upstream Zuri returns before recording violations for anyone with
    // zuri.bypass (including OP players). Allow these players to see their
    // own detection/threshold messages, but *never* kick or ban them.
    $bypassBefore = <<<'PHP'
		if ($playerZuri->hasBypassPermission()) {
			return; // if the player has bypass permission, we do not apply any violations or punishments
		}

		if (Utils::readBool($result["failed"]) === true) {
			self::handlePunishment($player, $checkInstance);
		}
PHP;
    $bypassAfter = <<<'PHP'
		// ReNether: let OP/bypass players see their own detections.
		// The bypass still prevents punishments; it now means monitor-only.
		if (Utils::readBool($result["failed"]) === true) {
			self::handlePunishment($player, $checkInstance, $playerZuri->hasBypassPermission());
		}
PHP;

    $punishmentSignatureBefore = 'private static function handlePunishment(Player $player, Check $check) : void {';
    $punishmentSignatureAfter = 'private static function handlePunishment(Player $player, Check $check, bool $monitorOnly = false) : void {';
    $punishmentBefore = '$punishment = $check->getPunishment();';
    $punishmentAfter = '$punishment = $monitorOnly ? "flag" : $check->getPunishment();';

    $marker = 'public static function isReNetherWaitingWorld(string $worldName): bool';
    if (!str_contains($registry, $marker)) {
        $classAnchor = "class CheckRegistry {\n";
        if (substr_count($registry, $classAnchor) !== 1) {
            throw new RuntimeException('Unexpected Zuri CheckRegistry class structure');
        }
        $registry = str_replace($classAnchor, $classAnchor . $method, $registry);
    }
    if (!str_contains($registry, 'ReNether: never schedule Speed tests inside waiting lobbies.')) {
        if (substr_count($registry, $scheduleBefore) !== 1) {
            throw new RuntimeException('Unexpected Zuri CheckRegistry scheduling implementation');
        }
        $registry = str_replace($scheduleBefore, $scheduleAfter, $registry);
    }
    if (!str_contains($results, 'ReNether: discard Speed results from the lobby')) {
        if (substr_count($results, $resultsBefore) !== 1) {
            throw new RuntimeException('Unexpected Zuri ResultsHandler implementation');
        }
        $results = str_replace($resultsBefore, $resultsAfter, $results);
    }

    // Update both pristine and already-lobby-patched Zuri PHARs.
    // Exact upstream anchors prevent silently patching an incompatible fork.
    if (!str_contains($results, 'ReNether: let OP/bypass players see their own detections.')) {
        if (substr_count($results, $bypassBefore) !== 1 ||
            substr_count($results, $punishmentSignatureBefore) !== 1 ||
            substr_count($results, $punishmentBefore) !== 1) {
            throw new RuntimeException('Unexpected Zuri bypass / punishment implementation');
        }
        $results = str_replace($bypassBefore, $bypassAfter, $results);
        $results = str_replace($punishmentSignatureBefore, $punishmentSignatureAfter, $results);
        $results = str_replace($punishmentBefore, $punishmentAfter, $results);
    }

    // Avoid accepting a partially patched PHAR.
    if (!str_contains($registry, $marker) ||
        !str_contains($registry, 'ReNether: never schedule Speed tests inside waiting lobbies.') ||
        !str_contains($results, 'ReNether: discard Speed results from the lobby') ||
        !str_contains($results, 'ReNether: let OP/bypass players see their own detections.') ||
        !str_contains($results, $punishmentSignatureAfter) ||
        !str_contains($results, $punishmentAfter)) {
        throw new RuntimeException('Zuri lobby-Speed / OP self-alert patch verification failed');
    }

    $updates = [];
    if ($phar[$files[0]]->getContent() !== $registry) {
        $updates[$files[0]] = $registry;
    }
    if ($phar[$files[1]]->getContent() !== $results) {
        $updates[$files[1]] = $results;
    }

    foreach ($updates as $file => $content) {
        // Also lint the modified PHP before writing any PHAR members.
        $temp = tempnam(sys_get_temp_dir(), 'renether-zuri-lint-');
        if ($temp === false) {
            throw new RuntimeException('Unable to create PHP lint temp file');
        }
        try {
            if (file_put_contents($temp, $content) === false) {
                throw new RuntimeException("Unable to save temporary PHP for lint: $file");
            }
            $cmd = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($temp) . ' 2>&1';
            exec($cmd, $output, $exitCode);
            if ($exitCode !== 0) {
                throw new RuntimeException("$file failed PHP lint: " . implode("\n", $output));
            }
        } finally {
            unlink($temp);
        }
    }

    if ($updates !== []) {
        $phar->startBuffering();
        foreach ($updates as $file => $content) {
            $phar[$file] = $content;
        }
        $phar->stopBuffering();
        echo "[ReNether/Zuri] Excluded static and dynamic waiting lobbies from Speed checks\n";
    } else {
        echo "[ReNether/Zuri] Already patched: lobby Speed protection\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "[ReNether/Zuri] ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

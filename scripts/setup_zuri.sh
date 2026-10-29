#!/usr/bin/env bash
# ReNether: opt-in/opt-out Zuri anticheat installer. Called by start.sh.
# Builds the upstream PHP source at a pinned commit, not an untracked latest PHAR.
set -Eeuo pipefail

if [[ $# -ne 6 ]]; then
  echo "[ReNether] ERROR: setup_zuri.sh expects DATA SOURCES PHP COMPOSER_PHAR ENABLED REBUILD" >&2
  exit 2
fi
DATA="$1"
SOURCES="$2"
PHP="$3"
COMPOSER_PHAR="$4"
ENABLED="$5"
REBUILD="$6"
ZURI_REV="ed582c94695ffbeaf2559f1271ff9c664ee5619e"
ZURI_SOURCE="$SOURCES/zuri"
ZURI_PHAR="$DATA/plugins/Zuri.phar"
ZURI_CONFIG="$DATA/plugin_data/Zuri/config.yml"

log(){ printf '[ReNether/Zuri] %s\n' "$*"; }
die(){ printf '[ReNether/Zuri] ERROR: %s\n' "$*" >&2; exit 1; }
[[ "$ENABLED" == 0 || "$ENABLED" == 1 ]] || die "NG_ENABLE_ZURI must be 0 or 1"
[[ "$REBUILD" == 0 || "$REBUILD" == 1 ]] || die "NG_REBUILD must be 0 or 1"
mkdir -p "$DATA/plugins"

if [[ "$ENABLED" == 0 ]]; then
  if [[ -f "$ZURI_PHAR" ]]; then
    mv -- "$ZURI_PHAR" "$ZURI_PHAR.disabled"
    log "Zuri disabled (PHAR and config preserved)"
  fi
  exit 0
fi

if [[ ! -f "$ZURI_PHAR" && -s "$ZURI_PHAR.disabled" && "$REBUILD" != 1 ]]; then
  mv -- "$ZURI_PHAR.disabled" "$ZURI_PHAR"
  log "Re-enabled existing Zuri.phar"
fi

if [[ "$REBUILD" == 1 || ! -s "$ZURI_PHAR" ]]; then
  log "Building Zuri 1.4.2-BETA from pinned upstream commit $ZURI_REV"
  if [[ ! -d "$ZURI_SOURCE/.git" ]]; then
    mkdir -p "$ZURI_SOURCE"
    git -C "$ZURI_SOURCE" init -q
    git -C "$ZURI_SOURCE" remote add origin https://github.com/ReinfyTeam/Zuri.git
  fi
  if [[ "$(git -C "$ZURI_SOURCE" rev-parse HEAD 2>/dev/null || true)" != "$ZURI_REV" ]]; then
    git -C "$ZURI_SOURCE" fetch --depth 1 origin "$ZURI_REV"
    git -C "$ZURI_SOURCE" checkout --detach -f FETCH_HEAD
  fi
  [[ "$(git -C "$ZURI_SOURCE" rev-parse HEAD)" == "$ZURI_REV" ]] ||
    die "Upstream Zuri revision does not match pin"

  (
    cd "$ZURI_SOURCE"
    # Pharynx starts its own nested Composer process during packaging.
    # When ReNether is started as root, every nested Composer invocation
    # must see the explicit opt-in or it will stop to ask interactively.
    # Keep this scoped to the Zuri build subshell.
    export COMPOSER_ALLOW_SUPERUSER=1
    # Build tooling (pharynx) is a dev dependency, so do NOT use --no-dev.
    "$PHP" "$COMPOSER_PHAR" install \
      --prefer-dist --no-interaction --no-progress
    "$PHP" tools/prepare-build.php
    "$PHP" -dphar.readonly=0 vendor/bin/pharynx -i=. -c -p=./build/Zuri.phar
    "$PHP" tools/verify-build.php
  )
  [[ -s "$ZURI_SOURCE/build/Zuri.phar" ]] || die "Zuri PHAR build produced no output"
  "$PHP" -r '
    $p = new Phar($argv[1]);
    foreach (["plugin.yml", "src/ReinfyTeam/Zuri/ZuriAC.php", "resources/config.yml", "resources/constants.yml"] as $entry) {
      if (!isset($p[$entry])) {
        fwrite(STDERR, "Missing Zuri PHAR entry: $entry\n");
        exit(1);
      }
    }
  ' "$ZURI_SOURCE/build/Zuri.phar" || die "Zuri PHAR verification failed"
  temp_phar="$DATA/plugins/.Zuri.phar.build.$$"
  cp -- "$ZURI_SOURCE/build/Zuri.phar" "$temp_phar"
  mv -f -- "$temp_phar" "$ZURI_PHAR"
  log "Installed Zuri.phar"
fi

# Never overwrite administrator config. For first-time installs, start in
# flag-only mode to prevent false kicks/bans in BedWars movement/combat.
if [[ ! -e "$ZURI_CONFIG" ]]; then
  mkdir -p "$(dirname "$ZURI_CONFIG")"
  "$PHP" -r '
    $p = new Phar($argv[1]);
    if (!isset($p["resources/config.yml"])) {
      fwrite(STDERR, "Missing Zuri bundled config\n");
      exit(1);
    }
    $config = yaml_parse($p["resources/config.yml"]->getContent());
    if (!is_array($config) || !isset($config["zuri"]["checks"]) ||
        !is_array($config["zuri"]["checks"])) {
      fwrite(STDERR, "Invalid Zuri bundled config\n");
      exit(1);
    }
    foreach ($config["zuri"]["checks"] as &$check) {
      if (is_array($check)) {
        $check["punishment"] = "flag";
      }
    }
    unset($check);
    if (yaml_emit_file($argv[2], $config) !== true) {
      fwrite(STDERR, "Cannot write Zuri config\n");
      exit(1);
    }
  ' "$ZURI_PHAR" "$ZURI_CONFIG" || die "Cannot generate safe Zuri config"
  log "Created flag-only Zuri configuration (no automatic kicks or bans)"
else
  log "Keeping existing Zuri configuration unchanged"
fi

log "Zuri enabled; confirm startup with /plugins and review console alerts"

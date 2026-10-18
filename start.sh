#!/usr/bin/env bash
# ReNether: Minecraft Bedrock server bootstrap (experimental port; no Docker).
set -Eeuo pipefail
cd "$(dirname "$(realpath "$0")")"
ROOT="$PWD"
STATE="$ROOT/.runtime"
SOURCES="$ROOT/upstream"
CACHE="$ROOT/.cache"
GAME="${NG_GAME:-Bedwars}"
SOCCER_ENABLED="${NG_ENABLE_SOCCER:-0}"
[[ "$SOCCER_ENABLED" == 0 || "$SOCCER_ENABLED" == 1 ]] || { echo '[ReNether] ERROR: NG_ENABLE_SOCCER must be 0 or 1' >&2; exit 2; }
case "$GAME" in
  Bedwars)
    DATA="$STATE/data"
    MINIGAME_PHAR="xBedwars.phar"
    SERVER_TYPE="BW"
    DEFAULT_PORT=19133
    ;;
  Skywars)
    DATA="$STATE/skywars/data"
    MINIGAME_PHAR="xSkywars.phar"
    SERVER_TYPE="SW"
    DEFAULT_PORT=19134
    ;;
  Soccer)
    [[ "$SOCCER_ENABLED" == 1 ]] || { echo "[ReNether] Soccer is disabled. Explicit NG_ENABLE_SOCCER=1 required to run it." >&2; exit 2; }
    DATA="$STATE/soccer/data"
    MINIGAME_PHAR="xSoccer.phar"
    SERVER_TYPE="SC"
    DEFAULT_PORT=19134
    ;;
  *)
    echo "[ReNether] ERROR: Unsupported NG_GAME=$GAME (use Bedwars, Skywars, or opt into Soccer)" >&2
    exit 2
    ;;
esac
PHP_HOME="$STATE/php"
PHP="$PHP_HOME/bin/php7/bin/php"
COMPOSER_PHAR="$CACHE/composer.phar"
DEVTOOLS="$CACHE/DevTools.phar"
PORT="${NG_PORT:-$DEFAULT_PORT}"
mkdir -p "$STATE" "$CACHE" "$SOURCES" "$DATA/plugins" "$DATA/worlds" "$DATA/plugin_data/NGEssentials/credentials" "$PHP_HOME"
chmod 700 "$STATE"
log(){ printf '[ReNether] %s\n' "$*"; }
die(){ printf '[ReNether] ERROR: %s\n' "$*" >&2; exit 1; }
[[ "$PORT" =~ ^[0-9]+$ ]] && (( PORT>0 && PORT<65536 )) || die "Invalid NG_PORT: $PORT"

if [[ ! -f "$STATE/dependencies-installed" ]]; then
  if [[ $EUID -eq 0 ]]; then
    apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends ca-certificates curl git python3 openssl unzip tar mariadb-server mariadb-client libffi8
  elif sudo -n true 2>/dev/null; then
    sudo apt-get update
    sudo DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends ca-certificates curl git python3 openssl unzip tar mariadb-server mariadb-client libffi8
  else
    die "First run needs root or passwordless sudo for Ubuntu packages and MariaDB."
  fi
  touch "$STATE/dependencies-installed"
fi

if [[ ! -x "$PHP" ]]; then
  log "Downloading PHP 8.4 PM5 runtime"
  curl -fL --retry 3 -o "$CACHE/php.tar.gz" https://github.com/NetherGamesMC/php-build-scripts/releases/download/pm5-php-8.4-latest/PHP-8.4-Linux-x86_64-PM5.tar.gz
  tar -xzf "$CACHE/php.tar.gz" -C "$PHP_HOME"
fi
EXT_DIR="$(find "$PHP_HOME/bin/php7/lib/php/extensions" -mindepth 1 -maxdepth 1 -type d 2>/dev/null | head -n1)"
[[ -n "$EXT_DIR" ]] || die "PHP extensions directory not found"
find "$PHP_HOME/bin" -name '*.ini' -type f -exec sed -i -E "s|^[[:space:]]*extension_dir[[:space:]]*=.*|extension_dir=\"$EXT_DIR\"|" {} +
[[ -f "$EXT_DIR/opcache.so" ]] || find "$PHP_HOME/bin" -name '*.ini' -exec sed -i -E 's/^[[:space:]]*zend_extension.*opcache.*/;zend_extension=opcache.so/' {} +
[[ -f "$EXT_DIR/ext_quiche.so" ]] || find "$PHP_HOME/bin" -name '*.ini' -exec sed -i -E 's/^[[:space:]]*extension.*ext_quiche.*/;extension=ext_quiche.so/' {} +
"$PHP" -v

if [[ ! -d "$SOURCES/axolotl/.git" ]]; then
  log "Cloning Axolotl-PM 5.49.1"
  git clone --depth 1 --branch 5.49.1 https://github.com/axolotl-pm/PocketMine-MP.git "$SOURCES/axolotl"
fi
if [[ ! -d "$SOURCES/plugins/.git" ]]; then
  log "Cloning NetherGames plugin sources"
  git clone --depth 1 https://github.com/NetherGamesMC/plugins.git "$SOURCES/plugins"
fi
if [[ ! -d "$SOURCES/assets/.git" ]]; then
  log "Sparse-cloning original BedWars and Lobby assets"
  git clone --filter=blob:none --sparse https://github.com/NetherGamesMC/assets.git "$SOURCES/assets"
fi
# Existing installs originally checked out only Bedwars. Add Lobby even on
# previously cloned assets; keep both the Network Hub and BedWars waiting area.
if [[ "$SOCCER_ENABLED" == 1 ]]; then
  git -C "$SOURCES/assets" sparse-checkout set Bedwars Lobby Soccer Skywars
else
  git -C "$SOURCES/assets" sparse-checkout set Bedwars Lobby Skywars
fi
python3 "$ROOT/scripts/apply_compat.py" "$SOURCES/axolotl"
if [[ "$GAME" == Soccer ]]; then
  bash "$ROOT/scripts/migrate_soccer_assets.sh" "$SOURCES/assets" "$DATA"
elif [[ "$GAME" == Skywars ]]; then
  bash "$ROOT/scripts/migrate_skywars_assets.sh" "$SOURCES/assets" "$DATA"
else
  bash "$ROOT/scripts/migrate_assets.sh" "$SOURCES/assets" "$DATA"
fi

# Sign rebranding modifies saved Hub sign tiles. Keep a one-time world backup
# before the first ReNetherBranding boot. No forced remigration or DB changes.
if [[ "$GAME" == Bedwars && ! -f "$DATA/.renether-sign-backup-created" ]]; then
  BACKUP_DIR="$STATE/branding-backups"
  mkdir -p "$BACKUP_DIR"
  BACKUP="$(mktemp -d "$BACKUP_DIR/$(date -u +%Y%m%dT%H%M%SZ).XXXXXX")"
  for world in Hub ArcadeLobby; do
    if [[ -d "$DATA/worlds/$world" ]]; then
      cp -a -- "$DATA/worlds/$world" "$BACKUP/$world"
    fi
  done
  printf '%s\n' "$BACKUP" > "$DATA/.renether-sign-backup-created"
  log "Original Hub worlds backed up before sign rebranding: $BACKUP"
fi

if [[ ! -f "$COMPOSER_PHAR" ]]; then
  curl -fL --retry 3 -o "$COMPOSER_PHAR" https://getcomposer.org/composer-stable.phar
fi
if [[ ! -f "$DEVTOOLS" ]]; then
  curl -fL --retry 3 -o "$DEVTOOLS" https://github.com/pmmp/DevTools/releases/download/1.17.2/DevTools.phar
fi

php_composer(){
  local token="${GITHUB_TOKEN:-}"
  if [[ -z "$token" && -r /root/.config/bethergames/github-token ]]; then
    token="$(cat /root/.config/bethergames/github-token)"
  fi
  if [[ -n "$token" ]]; then
    export COMPOSER_AUTH="{\"github-oauth\":{\"github.com\":\"$token\"}}"
  fi
  "$PHP" "$COMPOSER_PHAR" "$@"
}

phar_has_vendor(){
  local path="$1"
  [[ -s "$path" ]] && "$PHP" -r 'try { $p = new Phar($argv[1]); exit(isset($p["vendor/autoload.php"]) ? 0 : 1); } catch (Throwable $e) { exit(1); }' "$path" >/dev/null 2>&1
}

# Reuse the verified compatibility-built core and Essentials from the BedWars
# profile when starting a separate Soccer instance for the first time.
# Never modify or write BedWars' existing runtime files here.
if [[ ( "$GAME" == Soccer || "$GAME" == Skywars ) && "${NG_REBUILD:-0}" != 1 ]]; then
  if [[ ! -s "$DATA/PocketMine-MP.phar" && -s "$STATE/data/PocketMine-MP.phar" ]]; then
    cp "$STATE/data/PocketMine-MP.phar" "$DATA/PocketMine-MP.phar"
  fi
  if [[ ! -s "$DATA/plugins/NGEssentials.phar" ]] && phar_has_vendor "$STATE/data/plugins/NGEssentials.phar"; then
    cp "$STATE/data/plugins/NGEssentials.phar" "$DATA/plugins/NGEssentials.phar"
  fi
fi

# Older starter builds succeeded despite producing plugin PHARs without Composer's autoloader.
# Rebuild automatically if we detect one, even when NG_REBUILD was not explicitly requested.
if [[ ! -s "$DATA/PocketMine-MP.phar" || ! -s "$DATA/plugins/NGEssentials.phar" || ! -s "$DATA/plugins/$MINIGAME_PHAR" || "${NG_REBUILD:-0}" == 1 ]] ||
   ! phar_has_vendor "$DATA/plugins/NGEssentials.phar" ||
   ! phar_has_vendor "$DATA/plugins/$MINIGAME_PHAR"; then
  log "Preparing ReNether Bedrock server plugins (experimental, may fail)"
  if [[ ! -s "$DATA/PocketMine-MP.phar" || "${NG_REBUILD:-0}" == 1 ]]; then
    (
      cd "$SOURCES/axolotl"
      php_composer install --prefer-source --no-dev --classmap-authoritative --no-interaction --no-progress
      "$PHP" -dphar.readonly=0 build/server-phar.php --git "$(git rev-parse HEAD)"
      cp -f PocketMine-MP.phar "$DATA/PocketMine-MP.phar"
    )
  fi
  for plugin in NGEssentials "$GAME"; do
    if [[ "$plugin" == NGEssentials ]]; then
      output="$DATA/plugins/NGEssentials.phar"
    else
      output="$DATA/plugins/$MINIGAME_PHAR"
    fi
    if [[ "${NG_REBUILD:-0}" != 1 ]] && phar_has_vendor "$output"; then
      log "Reusing existing plugin PHAR: $(basename "$output")"
      continue
    fi
    (
      cd "$SOURCES/plugins/$plugin"
      if [[ -f composer-build.json ]]; then manifest=composer-build.json; else manifest=composer.json; fi
      "$PHP" "$SOURCES/plugins/scripts/merge-composer.php" "$manifest" "$SOURCES/plugins/shared/repositories.json" composer.merged.json
      COMPOSER_ROOT_VERSION=dev-stable COMPOSER=composer.merged.json COMPOSER_MIRROR_PATH_REPOS=1 php_composer install --prefer-source --no-dev --no-interaction --no-progress
      [[ -f vendor/autoload.php ]] || die "Composer did not create $plugin/vendor/autoload.php"
      "$PHP" -dphar.readonly=0 -r "require 'phar://$DEVTOOLS/src/ConsoleScript.php';" -- --make . --relative . --out "$output"
      # Include Composer dependencies, including path-repository symlinks, which can be omitted by DevTools.
      "$PHP" -dphar.readonly=0 "$ROOT/scripts/ensure_phar_vendor.php" "$PWD" "$output"
    )
  done

fi

# Even disabled plugins are auto-discovered by PM if the PHAR stays in
# plugins/. Preserve the file by renaming it, rather than deleting it.
# Isolated SkyWars must not load plugins copied from other game modes.
if [[ "$GAME" == Skywars ]]; then
  for unwanted in xBedwars xSoccer; do
    if [[ -f "$DATA/plugins/$unwanted.phar" ]]; then
      mv "$DATA/plugins/$unwanted.phar" "$DATA/plugins/$unwanted.phar.disabled"
    fi
  done
fi

if [[ "$GAME" == Bedwars ]]; then
  if [[ "$SOCCER_ENABLED" == 0 && -f "$DATA/plugins/xSoccer.phar" ]]; then
    mv "$DATA/plugins/xSoccer.phar" "$DATA/plugins/xSoccer.phar.disabled"
    log "Soccer disabled (PHAR preserved; original data unchanged)"
  elif [[ "$SOCCER_ENABLED" == 1 && ! -f "$DATA/plugins/xSoccer.phar" &&
          -f "$DATA/plugins/xSoccer.phar.disabled" ]]; then
    mv "$DATA/plugins/xSoccer.phar.disabled" "$DATA/plugins/xSoccer.phar"
    log "Re-enabled existing Soccer plugin"
  fi
fi

# Install original Soccer in the main BedWars/Hub process. Keep a separate
# PHAR and plugin_data so worlds and match state cannot collide with BedWars.
if [[ "$GAME" == Bedwars && "$SOCCER_ENABLED" == 1 ]]; then
  SOCCER_PHAR="$DATA/plugins/xSoccer.phar"
  if ! phar_has_vendor "$SOCCER_PHAR" && [[ "${NG_REBUILD:-0}" != 1 ]] &&
     phar_has_vendor "$STATE/soccer/data/plugins/xSoccer.phar"; then
    log "Reusing previously built Soccer plugin"
    cp "$STATE/soccer/data/plugins/xSoccer.phar" "$SOCCER_PHAR"
  fi
  if [[ "${NG_REBUILD:-0}" == 1 ]] || ! phar_has_vendor "$SOCCER_PHAR"; then
    log "Compiling optional xSoccer for ReNether"
    (
      cd "$SOURCES/plugins/Soccer"
      if [[ -f composer-build.json ]]; then manifest=composer-build.json; else manifest=composer.json; fi
      "$PHP" "$SOURCES/plugins/scripts/merge-composer.php" "$manifest" "$SOURCES/plugins/shared/repositories.json" composer.merged.json
      COMPOSER_ROOT_VERSION=dev-stable COMPOSER=composer.merged.json COMPOSER_MIRROR_PATH_REPOS=1 php_composer install --prefer-source --no-dev --no-interaction --no-progress
      [[ -f vendor/autoload.php ]] || die "Composer did not create Soccer/vendor/autoload.php"
      "$PHP" -dphar.readonly=0 -r "require 'phar://$DEVTOOLS/src/ConsoleScript.php';" -- --make . --relative . --out "$SOCCER_PHAR"
      "$PHP" -dphar.readonly=0 "$ROOT/scripts/ensure_phar_vendor.php" "$PWD" "$SOCCER_PHAR"
    )
  fi
  phar_has_vendor "$SOCCER_PHAR" || die "Soccer PHAR is missing Composer autoload. Set NG_ENABLE_SOCCER=0 to start BedWars without Soccer."
fi

# Refresh compatibility files in already-built PHARs without rebuilding Composer plugins.
"$PHP" -dphar.readonly=0 "$ROOT/scripts/sync_core_phar.php" \
  "$SOURCES/axolotl" "$DATA/PocketMine-MP.phar"

# Retrofit already built PHARs too; this does not force a Composer reinstall.
# The patched methods do not use the former TypeConverter argument.
"$PHP" -dphar.readonly=0 "$ROOT/scripts/patch_plugin_phars.php" \
  "$DATA/plugins/NGEssentials.phar" "$DATA/plugins/$MINIGAME_PHAR" \
  ${SOCCER_PHAR:+"$SOCCER_PHAR"}

# Build the small first-party sign rebranding plugin on every main-server
# boot, so already existing worlds are updated as chunks are loaded.
if [[ "$GAME" == Bedwars ]]; then
  BRAND_SOURCE="$ROOT/plugins/ReNetherBranding"
  BRAND_PHAR="$DATA/plugins/ReNetherBranding.phar"
  [[ -f "$BRAND_SOURCE/plugin.yml" &&
     -f "$BRAND_SOURCE/src/renether/branding/Main.php" ]] ||
    die "ReNetherBranding source missing. Pull the renamed JJ-JaaN/ReNether repository."
  log "Building ReNetherBranding (persistent in-world sign updates)"
  (
    cd "$BRAND_SOURCE"
    "$PHP" -dphar.readonly=0 -r "require 'phar://$DEVTOOLS/src/ConsoleScript.php';" -- \
      --make . --relative . --out "$BRAND_PHAR"
  )
  [[ -s "$BRAND_PHAR" ]] || die "ReNetherBranding.phar build failed"

  # Fail fast if a partially patched or old PHAR would advertise NG again.
  "$PHP" -r '
    try {
      $ng = new Phar($argv[1]);
      $brand = new Phar($argv[2]);
      $checks = [
        ["src/NetherGames/NGEssentials/ServerManager.php", "§6§lReNether§r"],
        ["src/NetherGames/NGEssentials/NGEssentials.php", "ReNether "]
      ];
      foreach ($checks as [$file, $needle]) {
        if (!isset($ng[$file]) || !str_contains($ng[$file]->getContent(), $needle)) {
          fwrite(STDERR, "[ReNether] ERROR: branding missing from PHAR member " . $file . "\\n");
          exit(1);
        }
      }
      $brandMain = "src/renether/branding/Main.php";
      if (!isset($brand["plugin.yml"]) ||
          !isset($brand[$brandMain]) ||
          !str_contains($brand[$brandMain]->getContent(), "getNetwork()->setName")) {
        fwrite(STDERR, "[ReNether] ERROR: built branding plugin is incomplete\\n");
        exit(1);
      }
      echo "[ReNether] Verified MOTD/brand in built PHARs\\n";
    } catch (Throwable $e) {
      fwrite(STDERR, "[ReNether] ERROR verifying PHAR branding: " . $e->getMessage() . "\\n");
      exit(1);
    }
  ' "$DATA/plugins/NGEssentials.phar" "$BRAND_PHAR" ||
    die "Cannot start with old NG branding PHAR."
fi

if [[ $EUID -eq 0 ]]; then
  systemctl start mariadb
  DATABASE=(mariadb -uroot)
elif sudo -n true 2>/dev/null; then
  sudo systemctl start mariadb
  DATABASE=(sudo mariadb -uroot)
else
  die "MariaDB initialization requires root or passwordless sudo"
fi
if [[ ! -f "$STATE/mysql-password" ]]; then
  openssl rand -hex 24 > "$STATE/mysql-password"
  chmod 600 "$STATE/mysql-password"
fi
DB_PASS="$(cat "$STATE/mysql-password")"
"${DATABASE[@]}" <<SQL
CREATE DATABASE IF NOT EXISTS newng CHARACTER SET utf8mb4;
CREATE USER IF NOT EXISTS 'newng'@'localhost' IDENTIFIED BY '$DB_PASS';
CREATE USER IF NOT EXISTS 'newng'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON newng.* TO 'newng'@'localhost';
GRANT ALL PRIVILEGES ON newng.* TO 'newng'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
# Shared player database: isolated SkyWars/Soccer instances must never re-import
# the schema or overwrite records if BedWars initialized it already.
if [[ ! -f "$STATE/db-initialized" ]]; then
  TABLES="$("${DATABASE[@]}" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='newng';")"
  if [[ "$TABLES" == 0 ]]; then
    "${DATABASE[@]}" newng < "$SOURCES/plugins/NGEssentials/ngdata_players.sql"
    "${DATABASE[@]}" newng < "$SOURCES/plugins/NGEssentials/resources/stored_procedures.sql"
  else
    die "Database newng already contains tables but the shared initialization marker is missing; inspect it manually before continuing."
  fi
  touch "$STATE/db-initialized"
fi

# Seed the BedWars Solo streak key absent from the upstream schema-only dump.
# This foreign-key parent must exist before increment_win_streak can insert results.
# Run on every start to cover databases initialized before this fix.
# INSERT IGNORE leaves existing game keys and player statistics unchanged.
"${DATABASE[@]}" newng <<'SQL'
INSERT IGNORE INTO win_streak_game_keys (game_key, friendly_name)
VALUES ('bw_solo', 'BedWars Solo');
SQL

if [[ ! -f "$DATA/server.properties" ]]; then
  cp "$SOURCES/plugins/docker/server.properties" "$DATA/server.properties"
  sed -i -e "s/^server-port=.*/server-port=$PORT/" -e 's/^xbox-auth=.*/xbox-auth=true/' -e "s/^motd=.*/motd=ReNether/" "$DATA/server.properties"
fi
# The Bedrock name also comes from NGEssentials::getMotd() and Network::setName().
# Those paths are patched and the branding plugin enforces the RakNet name.
# A requested full asset remigration explicitly selects the old Network Hub
# as the main world, without touching other user server.properties settings.
if [[ "$GAME" == Bedwars && "${NG_REMIGRATE_ASSETS:-0}" == 1 ]]; then
  if grep -q '^level-name=' "$DATA/server.properties"; then
    sed -i 's/^level-name=.*/level-name=Hub/' "$DATA/server.properties"
  else
    printf '\nlevel-name=Hub\n' >> "$DATA/server.properties"
  fi
fi
# Keep the real listening port aligned with NG_PORT even on existing installs.
# Previously only first-time server.properties creation applied the port.
if grep -q '^server-port=' "$DATA/server.properties"; then
  sed -i "s/^server-port=.*/server-port=$PORT/" "$DATA/server.properties"
else
  printf 'server-port=%s\n' "$PORT" >> "$DATA/server.properties"
fi

# Always refresh the public-facing brand on existing instances as well as
# first-time installs. Preserve all other server.properties fields.
if grep -q '^motd=' "$DATA/server.properties"; then
  sed -i 's/^motd=.*/motd=ReNether/' "$DATA/server.properties"
else
  printf 'motd=ReNether\n' >> "$DATA/server.properties"
fi

log "Configured server.properties MOTD: $(grep '^motd=' "$DATA/server.properties" | tail -n1)"
log "Configured listening port: $(grep '^server-port=' "$DATA/server.properties" | tail -n1)"
log "Code revision: $(git -C "$ROOT" rev-parse --short HEAD)"
if [[ ! -f "$DATA/pocketmine.yml" ]]; then
  cp "$SOURCES/plugins/docker/Chunk_Ticking_pocketmine.yml" "$DATA/pocketmine.yml"
  sed -i -e 's/^  enable-encryption: false/  enable-encryption: true/' -e 's/^  enable-input: false/  enable-input: true/' "$DATA/pocketmine.yml"
fi
if [[ ! -f "$DATA/plugin_data/NGEssentials/config.yml" ]]; then
  cp "$SOURCES/plugins/docker/ngessentials-config.yml" "$DATA/plugin_data/NGEssentials/config.yml"
  sed -i -e "s/__SERVER_TYPE__/$SERVER_TYPE/" -e 's/^developmentMode:.*/developmentMode: true/' "$DATA/plugin_data/NGEssentials/config.yml"
  printf '\nkafkaEnabled: false\n' >> "$DATA/plugin_data/NGEssentials/config.yml"
fi

CREDENTIALS="$DATA/plugin_data/NGEssentials/credentials/credentials.yml"
cat > "$CREDENTIALS" <<YAML
database:
  host: "127.0.0.1"
  username: "newng"
  password: "$DB_PASS"
  schema: "newng"
vote_key: ""
kafka_ip: ""
YAML
chmod 600 "$CREDENTIALS"

if [[ "${NG_BUILD_ONLY:-0}" == 1 ]]; then
  log "Build prepared and PHAR Composer autoloaders checked. Runtime compatibility NOT verified."
  exit 0
fi
# SkyWars/Soccer run in independent data dirs; keep BedWars on its configured port.
# Minecraft might still be connecting to an old PocketMine on 19132.
if command -v ss >/dev/null 2>&1; then
  log "Existing UDP listeners on 19132/19133:"
  ss -lunp 2>/dev/null | grep -E ':(19132|19133)([[:space:]]|$)' || true
fi
log "Starting ReNether $GAME on UDP $PORT (NO Docker)"
cd "$DATA"
exec "$PHP" ./PocketMine-MP.phar --no-wizard --disable-ansi

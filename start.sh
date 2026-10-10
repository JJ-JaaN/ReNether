#!/usr/bin/env bash
# new-ng: standalone bootstrap and BedWars launcher (experimental port; no Docker).
set -Eeuo pipefail
cd "$(dirname "$(realpath "$0")")"
ROOT="$PWD"
STATE="$ROOT/.runtime"
SOURCES="$ROOT/upstream"
CACHE="$ROOT/.cache"
DATA="$STATE/data"
PHP_HOME="$STATE/php"
PHP="$PHP_HOME/bin/php7/bin/php"
COMPOSER_PHAR="$CACHE/composer.phar"
DEVTOOLS="$CACHE/DevTools.phar"
PORT="${NG_PORT:-19133}"
mkdir -p "$STATE" "$CACHE" "$SOURCES" "$DATA/plugins" "$DATA/worlds" "$DATA/plugin_data/NGEssentials/credentials" "$PHP_HOME"
chmod 700 "$STATE"
log(){ printf '[new-ng] %s\n' "$*"; }
die(){ printf '[new-ng] ERROR: %s\n' "$*" >&2; exit 1; }
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
  log "Sparse-cloning official BedWars assets"
  git clone --filter=blob:none --sparse https://github.com/NetherGamesMC/assets.git "$SOURCES/assets"
  git -C "$SOURCES/assets" sparse-checkout set Bedwars
fi
python3 "$ROOT/scripts/apply_compat.py" "$SOURCES/axolotl"
bash "$ROOT/scripts/migrate_assets.sh" "$SOURCES/assets" "$DATA"

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

# Older starter builds succeeded despite producing plugin PHARs without Composer's autoloader.
# Rebuild automatically if we detect one, even when NG_REBUILD was not explicitly requested.
if [[ ! -s "$DATA/PocketMine-MP.phar" || ! -s "$DATA/plugins/NGEssentials.phar" || ! -s "$DATA/plugins/xBedwars.phar" || "${NG_REBUILD:-0}" == 1 ]] ||
   ! phar_has_vendor "$DATA/plugins/NGEssentials.phar" ||
   ! phar_has_vendor "$DATA/plugins/xBedwars.phar"; then
  log "Compiling PocketMine and NetherGames plugins (experimental, may fail)"
  (
    cd "$SOURCES/axolotl"
    php_composer install --prefer-source --no-dev --classmap-authoritative --no-interaction --no-progress
    "$PHP" -dphar.readonly=0 build/server-phar.php --git "$(git rev-parse HEAD)"
    cp -f PocketMine-MP.phar "$DATA/PocketMine-MP.phar"
  )
  for plugin in NGEssentials Bedwars; do
    (
      cd "$SOURCES/plugins/$plugin"
      if [[ -f composer-build.json ]]; then manifest=composer-build.json; else manifest=composer.json; fi
      "$PHP" "$SOURCES/plugins/scripts/merge-composer.php" "$manifest" "$SOURCES/plugins/shared/repositories.json" composer.merged.json
      COMPOSER_ROOT_VERSION=dev-stable COMPOSER=composer.merged.json COMPOSER_MIRROR_PATH_REPOS=1 php_composer install --prefer-source --no-dev --no-interaction --no-progress
      if [[ "$plugin" == Bedwars ]]; then
        output="$DATA/plugins/xBedwars.phar"
      else
        output="$DATA/plugins/NGEssentials.phar"
      fi
      [[ -f vendor/autoload.php ]] || die "Composer did not create $plugin/vendor/autoload.php"
      "$PHP" -dphar.readonly=0 -r "require 'phar://$DEVTOOLS/src/ConsoleScript.php';" -- --make . --relative . --out "$output"
      # Include Composer dependencies, including path-repository symlinks, which can be omitted by DevTools.
      "$PHP" -dphar.readonly=0 "$ROOT/scripts/ensure_phar_vendor.php" "$PWD" "$output"
    )
  done

fi

# Refresh compatibility files in already-built PHARs without rebuilding Composer plugins.
"$PHP" -dphar.readonly=0 "$ROOT/scripts/sync_core_phar.php" \
  "$SOURCES/axolotl" "$DATA/PocketMine-MP.phar"

# Retrofit already built PHARs too; this does not force a Composer reinstall.
# The patched methods do not use the former TypeConverter argument.
"$PHP" -dphar.readonly=0 "$ROOT/scripts/patch_plugin_phars.php" \
  "$DATA/plugins/NGEssentials.phar" "$DATA/plugins/xBedwars.phar"

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
if [[ ! -f "$STATE/db-initialized" ]]; then
  TABLES="$("${DATABASE[@]}" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='newng';")"
  [[ "$TABLES" == 0 ]] || die "Database newng is not empty: refusing automatic schema import"
  "${DATABASE[@]}" newng < "$SOURCES/plugins/NGEssentials/ngdata_players.sql"
  "${DATABASE[@]}" newng < "$SOURCES/plugins/NGEssentials/resources/stored_procedures.sql"
  touch "$STATE/db-initialized"
fi

if [[ ! -f "$DATA/server.properties" ]]; then
  cp "$SOURCES/plugins/docker/server.properties" "$DATA/server.properties"
  sed -i -e "s/^server-port=.*/server-port=$PORT/" -e 's/^xbox-auth=.*/xbox-auth=true/' -e 's/^motd=.*/motd=new-ng BedWars/' "$DATA/server.properties"
fi
if [[ ! -f "$DATA/pocketmine.yml" ]]; then
  cp "$SOURCES/plugins/docker/Chunk_Ticking_pocketmine.yml" "$DATA/pocketmine.yml"
  sed -i -e 's/^  enable-encryption: false/  enable-encryption: true/' -e 's/^  enable-input: false/  enable-input: true/' "$DATA/pocketmine.yml"
fi
if [[ ! -f "$DATA/plugin_data/NGEssentials/config.yml" ]]; then
  cp "$SOURCES/plugins/docker/ngessentials-config.yml" "$DATA/plugin_data/NGEssentials/config.yml"
  sed -i -e 's/__SERVER_TYPE__/BW/' -e 's/^developmentMode:.*/developmentMode: true/' "$DATA/plugin_data/NGEssentials/config.yml"
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
log "Starting experimental new-ng on UDP $PORT (NO Docker)"
cd "$DATA"
exec "$PHP" ./PocketMine-MP.phar --no-wizard --disable-ansi

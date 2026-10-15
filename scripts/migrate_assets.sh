#!/usr/bin/env bash
# Original NetherGames world assets, CC BY 4.0 (see ATTRIBUTION.md).
set -Eeuo pipefail
[[ $# -eq 2 ]] || { echo "Usage: migrate_assets.sh <assets-checkout> <data-dir>" >&2; exit 2; }
ASSETS="$(realpath "$1")"
DATA="$(realpath -m "$2")"
FORCE="${NG_REMIGRATE_ASSETS:-0}"
[[ "$FORCE" == 0 || "$FORCE" == 1 ]] || { echo "NG_REMIGRATE_ASSETS must be 0 or 1" >&2; exit 2; }
die(){ echo "[new-ng] ERROR: $*" >&2; exit 1; }
[[ -d "$ASSETS/Bedwars/arenas" && -f "$ASSETS/Bedwars/arenas.yml" &&
   -d "$ASSETS/Bedwars/WaitingLobby" && -f "$ASSETS/Lobby/worlds/Hub/level.dat" &&
   -d "$ASSETS/Lobby/worlds/Hub/db" && -f "$ASSETS/Lobby/worlds/ArcadeLobby/level.dat" &&
   -d "$ASSETS/Lobby/worlds/ArcadeLobby/db" && -f "$ASSETS/LICENSE" ]] ||
   die "Missing original Lobby/Hub or BedWars assets. Sparse checkout must include Bedwars and Lobby."

DEST="$DATA/plugin_data/xBedwars"
mkdir -p "$DEST" "$DATA/worlds"
sources=(
  "$ASSETS/Bedwars/arenas"
  "$ASSETS/Bedwars/arenas.yml"
  "$ASSETS/Bedwars/WaitingLobby"
  "$ASSETS/Lobby/worlds/Hub"
  "$ASSETS/Lobby/worlds/ArcadeLobby"
  "$ASSETS/LICENSE"
)
targets=(
  "plugin_data/xBedwars/arenas"
  "plugin_data/xBedwars/arenas.yml"
  "plugin_data/xBedwars/WaitingLobby"
  "worlds/Hub"
  "worlds/ArcadeLobby"
  "plugin_data/xBedwars/NETHERGAMES_ASSETS_LICENSE.txt"
)

copied_official_hub=0
if [[ "$FORCE" == 1 ]]; then
  # server.lock is exclusively flocked by Axolotl while it is running.
  if [[ -f "$DATA/server.lock" ]]; then
    exec {lock_fd}<"$DATA/server.lock"
    flock -n -x "$lock_fd" || die "Axolotl is running. Stop the server before remigrating."
  fi
  state="$(dirname "$DATA")"
  mkdir -p "$state/asset-backups"
  backup="$(mktemp -d "$state/asset-backups/$(date -u +%Y%m%dT%H%M%SZ).XXXXXX")"
  stage="$(mktemp -d "$DATA/.ng-asset-stage.XXXXXXXX")"
  trap 'rm -rf -- "$stage"' EXIT

  # Stage everything before moving anything from the live data folder.
  for i in "${!sources[@]}"; do
    rel="${targets[$i]}"
    mkdir -p "$stage/$(dirname "$rel")"
    cp -a -- "${sources[$i]}" "$stage/$rel"
  done

  changed=()
  restore(){
    echo "[new-ng] Rolling back incomplete asset migration" >&2
    for ((j=${#changed[@]}-1; j>=0; j--)); do
      rel="${targets[${changed[$j]}]}"
      rm -rf -- "$DATA/$rel"
      if [[ -e "$backup/$rel" || -L "$backup/$rel" ]]; then
        mkdir -p "$DATA/$(dirname "$rel")"
        mv -- "$backup/$rel" "$DATA/$rel" || echo "RESTORE FAILED for $rel (backup: $backup)" >&2
      fi
    done
  }
  for i in "${!sources[@]}"; do
    rel="${targets[$i]}"
    mkdir -p "$DATA/$(dirname "$rel")" "$backup/$(dirname "$rel")"
    if [[ -e "$DATA/$rel" || -L "$DATA/$rel" ]]; then
      if ! mv -- "$DATA/$rel" "$backup/$rel"; then
        restore
        die "Failed to back up $rel"
      fi
    fi
    changed+=("$i")
    if ! mv -- "$stage/$rel" "$DATA/$rel"; then
      restore
      die "Failed to restore official asset $rel"
    fi
    echo "[new-ng] Remigrated: $rel"
  done
  copied_official_hub=1
  echo "[new-ng] Previous assets backed up: $backup"
else
  for i in "${!sources[@]}"; do
    rel="${targets[$i]}"
    if [[ -e "$DATA/$rel" || -L "$DATA/$rel" ]]; then
      echo "Preserving existing: $DATA/$rel"
    else
      mkdir -p "$DATA/$(dirname "$rel")"
      cp -a -- "${sources[$i]}" "$DATA/$rel"
      echo "Copied: $DATA/$rel"
      if [[ "$rel" == worlds/Hub ]]; then copied_official_hub=1; fi
    fi
  done
fi

# Allow the BedWars server to use original Network Hub spawn coordinates
# while keeping separate BedWars waiting-lobby spawn coordinates.
if [[ "$copied_official_hub" == 1 ]]; then
  printf 'Original NetherGames Network Hub world copied from Lobby/worlds/Hub.\n' > "$DATA/.new-ng-original-hub"
fi

# On the BedWars/Hub instance, Soccer is a second gamemode with its own
# plugin_data. Never replace the Hub with Soccer's original waiting template.
if [[ "${NG_ENABLE_SOCCER:-1}" == 1 ]]; then
  [[ -f "$ASSETS/Soccer/arenas/SC-1/level.dat" &&
     -f "$ASSETS/Soccer/WaitingLobby/level.dat" ]] ||
    die "Missing original Soccer SC-1 or WaitingLobby; sparse checkout must include Soccer."
  for pair in "arenas" "WaitingLobby"; do
    src="$ASSETS/Soccer/$pair"
    dst="$DATA/plugin_data/xSoccer/$pair"
    if [[ -e "$dst" || -L "$dst" ]]; then
      echo "[new-ng] Preserving existing Soccer asset: $dst"
    else
      mkdir -p "$(dirname "$dst")"
      cp -a -- "$src" "$dst"
      echo "[new-ng] Copied original Soccer asset: $dst"
    fi
  done
  soccer_credit="$DATA/plugin_data/xSoccer/NETHERGAMES_ASSETS_CREDIT.txt"
  if [[ ! -e "$soccer_credit" ]]; then
    cat > "$soccer_credit" <<'SOCCER_CREDIT'
Original NetherGames Soccer SC-1 arena and waiting lobby.
Source: https://github.com/NetherGamesMC/assets
License: CC BY 4.0 (https://creativecommons.org/licenses/by/4.0/).
Soccer arena world is loaded separately; main Network Hub is unchanged.
SOCCER_CREDIT
  fi
fi

cat > "$DEST/NETHERGAMES_ASSETS_CREDIT.txt" <<'TXT'
Original NetherGames Network Hub and ArcadeLobby worlds, plus BedWars arenas
and waiting lobby. Copyright (c) NetherGames.
Source: https://github.com/NetherGamesMC/assets
License: Creative Commons Attribution 4.0 (CC BY 4.0)
https://creativecommons.org/licenses/by/4.0/
Original main Hub: worlds/Hub; BedWars waiting template: xBedwars/WaitingLobby.
Independent project; not endorsed by NetherGames.
TXT
echo "[new-ng] Original lobby worlds and BedWars assets ready."

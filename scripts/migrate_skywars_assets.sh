#!/usr/bin/env bash
# Original NetherGames Skywars worlds (CC BY 4.0), separate from BedWars.
set -Eeuo pipefail
[[ $# -eq 2 ]] || { echo "Usage: migrate_skywars_assets.sh <assets-checkout> <data-dir>" >&2; exit 2; }
ASSETS="$(realpath "$1")"
DATA="$(realpath -m "$2")"
die(){ printf '[ReNether] ERROR: %s\n' "$*" >&2; exit 1; }

# Preserve every existing world and arena. No destructive remigration.
[[ "${NG_REMIGRATE_ASSETS:-0}" != 1 ]] ||
  die "Skywars profile does not support forced remigration."
[[ -f "$ASSETS/LICENSE" && -d "$ASSETS/Skywars/arenas" &&
   -f "$ASSETS/Skywars/arenas.yml" &&
   -f "$ASSETS/Skywars/WaitingLobby/level.dat" ]] ||
  die "Skywars arenas.yml, arenas, or WaitingLobby missing; check sparse checkout."

mkdir -p "$DATA/worlds" "$DATA/plugin_data/xSkywars"
sources=(
  "$ASSETS/Skywars/arenas"
  "$ASSETS/Skywars/arenas.yml"
  "$ASSETS/Skywars/WaitingLobby"
  "$ASSETS/Skywars/WaitingLobby"
  "$ASSETS/LICENSE"
)
targets=(
  "plugin_data/xSkywars/arenas"
  "plugin_data/xSkywars/arenas.yml"
  "plugin_data/xSkywars/WaitingLobby"
  "worlds/Hub"
  "plugin_data/xSkywars/NETHERGAMES_ASSETS_LICENSE.txt"
)
for i in "${!sources[@]}"; do
  rel="${targets[$i]}"
  if [[ -e "$DATA/$rel" || -L "$DATA/$rel" ]]; then
    echo "[ReNether] Preserving existing SkyWars asset: $rel"
  else
    mkdir -p "$DATA/$(dirname "$rel")"
    cp -a -- "${sources[$i]}" "$DATA/$rel"
    echo "[ReNether] Copied SkyWars asset: $rel"
  fi
done

credit="$DATA/plugin_data/xSkywars/NETHERGAMES_ASSETS_CREDIT.txt"
if [[ ! -e "$credit" ]]; then
  cat > "$credit" <<'TXT'
Original NetherGames Skywars arena maps, arena configuration, and WaitingLobby.
Source: https://github.com/NetherGamesMC/assets
License: Creative Commons Attribution 4.0 International (CC BY 4.0)
https://creativecommons.org/licenses/by/4.0/
Skywars runs in an isolated experimental instance of ReNether.
TXT
fi
echo "[ReNether] Original SkyWars maps and lobby are ready."

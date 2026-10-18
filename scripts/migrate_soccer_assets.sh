#!/usr/bin/env bash
# Original NetherGames Soccer worlds (CC BY 4.0) in an isolated Soccer profile.
set -Eeuo pipefail
[[ $# -eq 2 ]] || { echo "Usage: migrate_soccer_assets.sh <assets-checkout> <data-dir>" >&2; exit 2; }
ASSETS="$(realpath "$1")"
DATA="$(realpath -m "$2")"
die(){ printf '[ReNether] ERROR: %s\n' "$*" >&2; exit 1; }

# Keep destructive remigration out of the first Soccer experiment.
[[ "${NG_REMIGRATE_ASSETS:-0}" != 1 ]] ||
  die "Soccer profile does not support forced remigration. Existing worlds were not modified."
[[ -f "$ASSETS/LICENSE" && -f "$ASSETS/Soccer/arenas/SC-1/level.dat" &&
   -f "$ASSETS/Soccer/WaitingLobby/level.dat" ]] ||
  die "Soccer arena SC-1 or waiting lobby missing; check the Soccer sparse checkout."

mkdir -p "$DATA/worlds" "$DATA/plugin_data/xSoccer"
sources=(
  "$ASSETS/Soccer/arenas"
  "$ASSETS/Soccer/WaitingLobby"
  "$ASSETS/Soccer/WaitingLobby"
  "$ASSETS/LICENSE"
)
targets=(
  "plugin_data/xSoccer/arenas"
  "plugin_data/xSoccer/WaitingLobby"
  "worlds/Hub"
  "plugin_data/xSoccer/NETHERGAMES_ASSETS_LICENSE.txt"
)
for i in "${!sources[@]}"; do
  rel="${targets[$i]}"
  if [[ -e "$DATA/$rel" || -L "$DATA/$rel" ]]; then
    echo "[ReNether] Preserving existing Soccer asset: $rel"
  else
    mkdir -p "$DATA/$(dirname "$rel")"
    cp -a -- "${sources[$i]}" "$DATA/$rel"
    echo "[ReNether] Copied Soccer asset: $rel"
  fi
done

credit="$DATA/plugin_data/xSoccer/NETHERGAMES_ASSETS_CREDIT.txt"
if [[ ! -e "$credit" ]]; then
  cat > "$credit" <<'TXT'
NetherGames Soccer SC-1 arena and waiting lobby.
Source: https://github.com/NetherGamesMC/assets
License: Creative Commons Attribution 4.0 (CC BY 4.0)
https://creativecommons.org/licenses/by/4.0/
Soccer profile is a separate experimental instance from BedWars.
TXT
fi
echo "[ReNether] Soccer map and waiting lobby ready."

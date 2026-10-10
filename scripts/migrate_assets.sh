#!/usr/bin/env bash
# Credit: NetherGames CC BY 4.0 world assets, see ATTRIBUTION.md.
set -Eeuo pipefail
[[ $# -eq 2 ]] || { echo "Usage: migrate_assets.sh <assets-checkout> <data-dir>" >&2; exit 2; }
ASSETS="$(realpath "$1")"
DATA="$(realpath -m "$2")"
[[ -d "$ASSETS/Bedwars/arenas" && -f "$ASSETS/Bedwars/arenas.yml" && -d "$ASSETS/Bedwars/WaitingLobby" && -f "$ASSETS/LICENSE" ]] || {
  echo "Missing Bedwars/arenas, arenas.yml, WaitingLobby or LICENSE" >&2; exit 1;
}
DEST="$DATA/plugin_data/xBedwars"
mkdir -p "$DEST" "$DATA/worlds"
copy_once(){
  if [[ -e "$2" ]]; then echo "Preserving existing: $2"; else cp -a "$1" "$2"; echo "Copied: $2"; fi
}
copy_once "$ASSETS/Bedwars/arenas" "$DEST/arenas"
copy_once "$ASSETS/Bedwars/arenas.yml" "$DEST/arenas.yml"
copy_once "$ASSETS/Bedwars/WaitingLobby" "$DEST/WaitingLobby"
copy_once "$ASSETS/Bedwars/WaitingLobby" "$DATA/worlds/Hub"
copy_once "$ASSETS/LICENSE" "$DEST/NETHERGAMES_ASSETS_LICENSE.txt"
cat > "$DEST/NETHERGAMES_ASSETS_CREDIT.txt" <<'TXT'
Original NetherGames BedWars arenas, maps and waiting lobby.
Copyright (c) NetherGames
Source: https://github.com/NetherGamesMC/assets
License: Creative Commons Attribution 4.0 (CC BY 4.0)
https://creativecommons.org/licenses/by/4.0/
Worlds copied without intentional changes by new-ng setup (runtime copies may change).
Independent project; NetherGames has not endorsed it.
TXT
echo "BedWars maps prepared with CC BY 4.0 attribution."

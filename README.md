# ReNether (privatni razvojni projekt)

ReNether koristi originalni NetherGames BedWars i NGEssentials kod prilagođen na **Axolotl-PM 5.49.1**, protokol Bedrock **2193 / 26.50**, **bez Dockera**. Pokretanje koristi jedan ulazni skript: `./start.sh`. Originalni **NetherGames Network Hub** i **ArcadeLobby** (iz `Lobby/worlds/`) te sve BedWars arene i waiting lobby preuzimaju se iz [NetherGamesMC/assets](https://github.com/NetherGamesMC/assets). Zadani svijet `Hub` dolazi iz službenih Lobby mapa, a BedWars čuva vlastiti `WaitingLobby` predložak. Već postojeće datoteke ne prepisuju se bez eksplicitne remigracije.

**Status: eksperimentalno.** Originalni pluginovi i dalje mogu imati nekompatibilnosti s Axolotl-PM 5.49.1. Promjena imena ne mijenja unutarnje klase, izvorne licence ili API-je.

## Ubuntu 24.04+ (odvojeni testni server)

```bash
git clone git@github.com:JJ-JaaN/new-ng.git
cd new-ng
chmod +x start.sh
./start.sh
```

`start.sh` tijekom prvog pokretanja instalira potrebne Ubuntu pakete kada ima administratorske dozvole, priprema lokalnu MariaDB, preuzima PHP runtime, Composer i DevTools, klonira Axolotl, NetherGames plugin source i Lobby + BedWars world assete, pokušava složiti `.phar` datoteke te pokreće poslužitelj na **UDP 19133**. To je razvojni pokušaj i može stati zbog stvarnih API nekompatibilnosti; u tom slučaju ispisat će grešku umjesto lažne poruke o uspjehu.

Za korištenje porta 19132 nakon gašenja starog poslužitelja: `NG_PORT=19132 ./start.sh`. Za novi build: `NG_REBUILD=1 ./start.sh`. Za build bez pokretanja: `NG_BUILD_ONLY=1 ./start.sh`.

Podatci su u `.runtime/data/`, uključujući konfiguracije i bazu lokalne MariaDB, dok se upstream kodovi preuzimaju u `upstream/`. Git ignorira binarne buildove, podatke igrača i tajne. Nikad ne komitiraj `.runtime/` u Git.

**Nema Dockera, phpMyAdmina niti obaveznog proxyja.** Za test je uključen NGEssentials development mode. Autentifikacija Xboxa je omogućena u lokalnom `server.properties`. Baza je lokalna MariaDB. Ovo nije gotova produkcijska konfiguracija.


## Aktivni gamemodeovi

**BedWars je uključen, Soccer je isključen.** Zadan je samo glavni poslužitelj **ReNether** na UDP **19133**. Novi `start.sh` ne preuzima Soccer mape, ne gradi `xSoccer.phar` i ne stvara Soccer NPC-ja. Ako je taj plugin ranije postojao, pri sljedećem pokretanju se automatski premješta u `xSoccer.phar.disabled`; njegovi podaci ostaju spremljeni i može se ručno ponovno omogućiti u budućnosti.

Za prvi restart zaustavi postojeći server s `stop`, pa pokreni:

```bash
cd ~/new-ng
git pull --ff-only
NG_PORT=19133 bash ./start.sh 2>&1 | tee renether.log
```

Za izolirano testiranje Soccera u budućnosti možeš eksplicitno zadati `NG_ENABLE_SOCCER=1`, ali **ReNether ga po zadanim postavkama ne učitava**. Promjena imena ne resetira `newng` MariaDB bazu, `.runtime/data/`, `Hub` ili `BedWars` svjetove.

### Ime GitHub repozitorija i postojeće mape

U kodu i igri ime je `ReNether`. Izvorna GitHub adresa ostaje `JJ-JaaN/new-ng` dok vlasnik ne promijeni Repository name u **Settings → General**. Isto tako, postojeća lokalna putanja `~/new-ng` ostaje valjana. Nakon promjene na GitHubu možeš prilagoditi `git remote`; preimenovanje lokalne mape nije potrebno za rad.

## Ponovna migracija iz starog BedWars Hub-a

Ako je raniji `ReNether` postavio BedWars waiting mapu kao `worlds/Hub`,
**najprije ugasi server** naredbom `stop` u konzoli (ili kroz tmux).
Zatim:

```bash
cd ~/new-ng
git pull
NG_REMIGRATE_ASSETS=1 NG_PORT=19133 bash ./start.sh 2>&1 | tee remigrate.log
```

Ovo zamjenjuje originalima **samo** `worlds/Hub`, `worlds/ArcadeLobby`,
`plugin_data/xBedwars/arenas`, `arenas.yml`, `WaitingLobby` i kopiju
licence. Stare verzije sprema u `.runtime/asset-backups/<UTC-datum>.<oznaka>/`
prije zamjene, a baza igrača, PHP pluginovi i konfiguracija NGEssentials
ostaju netaknuti. Prije kopiranja provjerava da Axolotl nema aktivan
`server.lock`, tako da ne zamjenjuje učitane svjetove.

**Bez** `NG_REMIGRATE_ASSETS=1` starter uvijek čuva postojeće svjetove;
kopira samo one koji nedostaju. Za migraciju bez pokretanja poslužitelja
možeš dodati `NG_BUILD_ONLY=1`.

Migracija postavlja oznaku `.runtime/data/.new-ng-original-hub`. Kada je
ona prisutna, NGEssentials za **glavni** BedWars server koristi izvorni
Network Lobby spawn `(-26.5, 42, -22.5)`, dok odvojeni BedWars waiting
lobby i dalje koristi `(0.5, 65, 0.5)`. Ako ručno vraćaš **stari**
BedWars Hub iz backupa, ukloni i tu oznaku te restartaj server.

Originalni Hub svijet **ne sadrži NPC-jeve kao spremljene entitete**. Nakon migracije oznake `.new-ng-original-hub`, patcher automatski podiže **19 vidljivih skinniranih NPC-jeva** na koordinatama glavnih NetherGames NPC-jeva. `BedWars` NPC pokreće lokalnu `joinArena()` funkciju; `Arcade` NPC teleportira u originalni `ArcadeLobby`. NPC-jevi drugih gamemodeova daju jasnu poruku da igra nije instalirana. Nekoliko karaktera iz Huba govori osnovnu poruku.

Ovo **nije puni originalni `xLobby`**: za stare 3D modele umjesto njih služe skinnirani humanoidni NPC-jevi, a puni NPC dijalozi, svi side-questovi i ostali gamemodeovi ostaju neimplementirani. Izbjegnuto je učitavanje `xLobby` u samostalni BedWars proces kako njegove lobby funkcije ne bi prebrisale BedWars stanje igrača. Nije potreban ponovni build plugina za dodavanje NPC-jeva; patch na `NGEssentials.phar` aktivira se pri sljedećem pokretanju servera.

## Što je uključeno

- `scripts/apply_compat.py`: početni LGPL kompatibilnosni patch za Axolotl izvorni kod (samo jedan Bedrock protokol).
- `scripts/migrate_assets.sh`: originalni Network `Hub` i `ArcadeLobby`, sve BedWars arene, `arenas.yml` i zasebni `WaitingLobby`, uz CC BY 4.0 atribuciju; sigurna remigracija s backupom.
- `start.sh`: instalacija, build, baza, konfiguracija i pokretanje jednim pozivom.
- `ATTRIBUTION.md`: autori, izvori, licence i ograničenja.

## Blokatori prije javnog rada

- Moderni Axolotl BedrockProtocol ne sadrži NetherGamesove stare `PROTOCOL_1_20_*` i `PROTOCOL_1_21_*` konstante. Potrebno je portati NGEssentials/libVanilla i testirati login.
- Testirati učitavanje arena, generatore, blokove, shop, inventory i početak igre s 2 igrača.
- Zabilježiti Composer/PHP build i runtime logove; trenutno nema potvrde uspješnog builda.

Licence i originalni autor(i): [ATTRIBUTION.md](ATTRIBUTION.md).
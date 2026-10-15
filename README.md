# new-ng (privatni razvojni projekt)

Pokušaj migracije NetherGames BedWars + NGEssentials na **Axolotl-PM 5.49.1**, protokol Bedrock **2193 / 26.50**, **bez Dockera**. Pokretanje koristi jedan ulazni skript: `./start.sh`. Originalni **NetherGames Network Hub** i **ArcadeLobby** (iz `Lobby/worlds/`) te sve BedWars arene i waiting lobby preuzimaju se iz [NetherGamesMC/assets](https://github.com/NetherGamesMC/assets). Zadani svijet `Hub` dolazi iz službenih Lobby mapa, a BedWars čuva vlastiti `WaitingLobby` predložak. Već postojeće datoteke ne prepisuju se bez eksplicitne remigracije.

**Status: eksperimentalno. Nije potvrđena kompatibilnost niti uspješan rad BedWarsa.** Starter prenosi dio potrebnih API-ja, ali NetherGamesovi plugini očekuju starije `ProtocolInfo::PROTOCOL_*` konstante, različite klase te druge izmjene. Ne gasiti postojeći Docker BedWars dok novi build i testovi ne prođu.

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


## Soccer + BedWars na jednom serveru (UDP 19133)

Izvorni NetherGames `xSoccer` v2.0 igra se s najmanje **2 igrača** (1v1) i najviše 4 po ekipi. Za test je **automatski uključen u glavni server** zajedno s `xBedwars`. **Nema drugog porta ni proxyja.** Ne treba se spajati na stari odvojeni Soccer port 19134.

Za prvi test **ugasi samo glavni BedWars proces** naredbom `stop`, a zatim:

```bash
cd ~/new-ng
git pull --ff-only
NG_PORT=19133 bash ./start.sh 2>&1 | tee soccer-main.log
```

Oba igrača povezuju se na uobičajeni BedWars server, isti IP i **UDP 19133**, te pokreću `/sc join`. U originalnom Network Hubu nalazi se i novi Soccer NPC (blizu Arcade NPC-ja); BedWars NPC i `/bw join` i dalje rade. `/sc leave` vraća u Hub. Igrači ne mogu istovremeno biti u dva minigamea.

Soccer automatski priprema `plugin_data/xSoccer/arenas/SC-1` i `plugin_data/xSoccer/WaitingLobby` iz originalnih asseta, bez mijenjanja `worlds/Hub`, BedWars arena, podataka o igračima ili MariaDB baze. Komanda za ručno pokretanje `/sc start` dostupna je u development modu kad si u areni. Dvoje igrača trebaju biti u suprotnim ekipama.

Za hitan povratak na BedWars bez Soccera (bez brisanja podataka):

```bash
NG_ENABLE_SOCCER=0 NG_PORT=19133 bash ./start.sh
```

Ovo premješta Soccer PHAR u `.disabled` datoteku; sljedeće normalno pokretanje vratit će ga automatski.

**Status: neprovjereno na stvarnom VPS-u.** Prvi test neka bude `/sc join`, provjera lopte i petominutni 1v1. Ako se dogodi `CRITICAL`/`ERROR`, pošalji početni stack trace iz `soccer-main.log`. Stari način `NG_GAME=Soccer` na zasebnom portu ostaje dostupan samo za dodatne izolirane testove.

## Ponovna migracija iz starog BedWars Hub-a

Ako je raniji `new-ng` postavio BedWars waiting mapu kao `worlds/Hub`,
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
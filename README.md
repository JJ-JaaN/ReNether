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


## Soccer: zaseban testni server za 1v1

Izvorni NetherGames `xSoccer` v2.0 ima najmanje **2 igrača** i **4 igrača po ekipi**. Dakle, dva igrača mogu testirati 1v1 (crveni protiv plavih). Utakmica traje 5 minuta. Soccer još nije runtime-testiran s Axolotl-PM 5.49.1.

**Ne gasiti postojeći BedWars.** Na VPS-u u drugoj `tmux` sesiji:

```bash
cd ~/new-ng
git pull --ff-only
NG_GAME=Soccer bash ./start.sh 2>&1 | tee soccer.log
```

Soccer koristi **UDP 19134**, `.runtime/soccer/data/`, plugin `xSoccer.phar`, NGEssentials `serverType: SC` i originalnu mapu `SC-1`. BedWars ostaje na **UDP 19133** i u `.runtime/data/`. Oba profila imaju odvojene svjetove i konfiguracije, ali dijele razvojnu MariaDB bazu `newng`.

`start.sh` dohvaća i `assets/Soccer/arenas/SC-1` te `assets/Soccer/WaitingLobby`, a postojeće kopije čuva. Soccer ne podržava `NG_REMIGRATE_ASSETS=1` jer je sigurnije izbjeći slučajno brisanje mapa.

U Minecraft Bedrocku dodaj isti VPS/IP kao za BedWars, ali s portom **19134**. Zatim oba igrača upisuju:

```text
/sc join
```

Za ručni test u development modu postoji i `/sc start` kad si već u areni. Dva igrača trebaju biti u **različitim ekipama**, inače čekanje na uravnotežene timove može potrajati. Na VPS-u po potrebi dopusti `19134/udp` u firewallu i kod hosting providera.

Ako se nešto sruši, sačuvaj cijeli `soccer.log`, osobito prvi `CRITICAL` ili `ERROR` i stack trace. Pripremljena je kompatibilnost za Soccer loptu, ali fiziku, prikaz modela i cijeli meč treba potvrditi u igri.

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
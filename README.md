# ReNether

ReNether koristi originalni NetherGames BedWars i NGEssentials kod prilagođen na **Axolotl-PM 5.49.1**, protokol Bedrock **2193 / 26.50**, **bez Dockera**. Pokretanje koristi jedan ulazni skript: `./start.sh`. Originalni **NetherGames Network Hub** i **ArcadeLobby** (iz `Lobby/worlds/`) te sve BedWars arene i waiting lobby preuzimaju se iz [NetherGamesMC/assets](https://github.com/NetherGamesMC/assets). Zadani svijet `Hub` dolazi iz službenih Lobby mapa, a BedWars čuva vlastiti `WaitingLobby` predložak. Već postojeće datoteke ne prepisuju se bez eksplicitne remigracije.

**Licence i prava:** Ovaj [GitHub repozitorij](https://github.com/JJ-JaaN/ReNether) je **javan**. [ATTRIBUTION.md](ATTRIBUTION.md) navodi izvorne autore, a [LEGAL_COMPLIANCE.md](LEGAL_COMPLIANCE.md) dokumentira uvjete, obveze prema mrežnim korisnicima i još neriješeno neslaganje između AGPL licence i vlasničkih zaglavlja dijela NetherGames pluginova. Javnost repozitorija nije automatska potvrda da je dostupan sav odgovarajući izvorni kod pokrenute verzije. **Ne smatrati projekt potpuno pravno usklađenim dok se to ne razjasni.**

## ZuriAC (eksperimentalni anti-cheat)

ReNether sada podržava **Zuri 1.4.2-BETA** iz izvornog repozitorija
[ReinfyTeam/Zuri](https://github.com/ReinfyTeam/Zuri), zaključan na commit
`ed582c94695ffbeaf2559f1271ff9c664ee5619e`. Zuri se na prvom pokretanju
gradi iz izvora pomoću Composera i Pharynxa. Koristi se lokalni PHP runtime
koji već koristi Axolotl-PM 5.49.1. Ne preuzima se neprovjereni "latest" PHAR.

- Pokretanje testnog anti-cheata: `NG_ENABLE_ZURI=1 ./start.sh`. Starter izrađuje
  `.runtime/data/plugins/Zuri.phar` i prvi put postavlja
  `.runtime/data/plugin_data/Zuri/config.yml`.
- **Početni režim je samo detekcija**: sve konfigurirane kazne postavljene
  su na `flag` umjesto `kick` ili `ban`. Zuri zapisuje upozorenja,
  ali ne izbacuje igrače zbog detekcija u ovoj konfiguraciji.
- **Zadano je isključen**, dok se ne potvrdi kompatibilnost. `NG_ENABLE_ZURI=0 ./start.sh` isključuje Zuri premještanjem datoteke
  u `Zuri.phar.disabled` bez brisanja postojećih podataka.
- `NG_REBUILD=1 ./start.sh` ponovno gradi Zuri iz zaključanog commita.
  Postojeća administratorska konfiguracija **nikad se automatski ne prepisuje**.
- U konzoli provjeri poruke o učitavanju, naredbu `plugins` i
  `zuri help` (odgovarajuće ovlasti su potrebne).
  `NG_BUILD_ONLY=1` potvrđuje build, ali **ne** i rad tijekom meča.

**Važno:** podrška za Axolotl-PM i ponašanje u BedWarsu još nisu potvrđeni
testiranjem. Zuri koristi mrežne i gameplay evente; BedWars knockback,
fireball-jump, teleporti i posebne mehanike mogu davati lažne detekcije.
Konfiguracija nudi `world_bypass`, ali u zaključanom kodu nema potvrđene
primjene te postavke, pa je ne treba smatrati zaštitom od lažnih detekcija.
Nemoj uključivati automatske kazne prije stvarnih testova. Ako Zuri build
ne uspije, podigni postojeći server sa `NG_ENABLE_ZURI=0`.

## Glavni Hub: borba samo za OP igrače

Na glavnom BedWars/Hub serveru `start.sh` automatski gradi i učitava
`ReNetherHubPvP.phar` iz `plugins/ReNetherHubPvP/`.
U svijetu **`Hub`** samo igrači s PocketMine operatorskom dozvolom
`pocketmine.group.operator` mogu nanijeti štetu drugim igračima,
uključujući napade projektilima. Udarcima običnih igrača u `Hub`
poništava se damage event (nema štete ili knockbacka iz tog eventa).

Promjena ne utječe na svjetove BedWars/SkyWars arena, `ArcadeLobby`,
waiting lobbyje, NPC-jeve ni napade mobova. Postojeći event cancellation
drugih pluginova se ne prepisuje: OP može napadati samo ako napad
nije blokiran drugim pravilima ili postavkom poslužitelja.

**Ažuriranje:** zaustavi server naredbom `stop`, zatim `git pull --ff-only`
i pokreni standardnim `bash ./start.sh`, uz dosadašnje vrijednosti
`NG_PORT` i `NG_ENABLE_ZURI`. U konzoli provjeri `plugins` i poruku
`Hub combat protection active`. Ne treba `NG_REBUILD=1`.
Za provjeru iz repozitorija: `php tests/hub_pvp.php`.

## BedWars kompatibilnost: upgrades i countdown

Patcher u `scripts/patch_plugin_phars.php` pri pokretanju pokušava zamijeniti
NetherGamesove znakove za odbrojavanje (`5, 4, 3, 2, 1, GO!`) običnim
tekstom. Originalni znakovi zahtijevaju posebni resource-pack font i bez
njega mogu izgledati kao sivi ili prazni pravokutnici.

Za **UPGRADES & TRAPS** trenutačno se uvijek koristi izvorna Bedrock
forma umjesto inventarskog InvMenu prozora, neovisno o postavci
`Chest UI`. Time se zaobilazi nepouzdano ponašanje inventarskih
transakcija na novom protokolu. **ITEM SHOP** nije promijenjen.
Ovo je kompatibilnosni workaround, ne potvrda da su sve vrste nadogradnji
testirane. Ako kupnja i dalje ne radi, sačuvaj konzolni stack trace i
provjeri odlaze li dijamanti i mijenja li se stvarni efekt nadogradnje.

Odbrojavanje počinje tek kad postoji dovoljno igrača: Solo 2,
Doubles 4, Trios 6, Squads 8. U privatnoj igri konfiguracija može
dopustiti raniji početak. Za test nakon `git pull` zaustavi server
normalno s `stop`, ponovno ga pokreni standardnim `./start.sh`
i provjeri log oznake `resource-pack-free countdown` i
`native BedWars upgrade form` ili poruke o njihovoj primjeni.

**Status: eksperimentalno.** Originalni pluginovi i dalje mogu imati nekompatibilnosti s Axolotl-PM 5.49.1. Promjena imena ne mijenja unutarnje klase, izvorne licence ili API-je.

## Ubuntu 24.04+ (odvojeni testni server)

```bash
git clone git@github.com:JJ-JaaN/ReNether.git
cd ReNether
chmod +x start.sh
./start.sh
```

`start.sh` tijekom prvog pokretanja instalira potrebne Ubuntu pakete kada ima administratorske dozvole, priprema lokalnu MariaDB, preuzima PHP runtime, Composer i DevTools, klonira Axolotl, NetherGames plugin source i Lobby + BedWars world assete, pokušava složiti `.phar` datoteke te pokreće poslužitelj na **UDP 19133**. To je razvojni pokušaj i može stati zbog stvarnih API nekompatibilnosti; u tom slučaju ispisat će grešku umjesto lažne poruke o uspjehu.

Za korištenje porta 19132 nakon gašenja starog poslužitelja: `NG_PORT=19132 ./start.sh`. Za novi build: `NG_REBUILD=1 ./start.sh`. Za build bez pokretanja: `NG_BUILD_ONLY=1 ./start.sh`.

**Port pri restartu:** Ako `NG_PORT` nije zadan, `start.sh` sada zadržava postojeći `server-port` iz `.runtime/data/server.properties` (npr. 19132), umjesto da ga prepiše na zadani 19133. Za novu instalaciju zadano ostaje 19133. Ako je starija verzija startera već prepisala spremljeni port, jednom ponovno postavi željeni port pomoću `NG_PORT=19132 bash ./start.sh` nakon što ugasiš stari proces. ZuriAC ne mijenja Minecraft protokol ili port.

Podatci su u `.runtime/data/`, uključujući konfiguracije i bazu lokalne MariaDB, dok se upstream kodovi preuzimaju u `upstream/`. Git ignorira binarne buildove, podatke igrača i tajne. Nikad ne komitiraj `.runtime/` u Git.

**Nema Dockera, phpMyAdmina niti obaveznog proxyja.** Za test je uključen NGEssentials development mode. Autentifikacija Xboxa je omogućena u lokalnom `server.properties`. Baza je lokalna MariaDB. Ovo nije gotova produkcijska konfiguracija.


## BedWars + SkyWars na jednom portu (eksperimentalno)

Za zajednički **UDP 19132** i isti Hub koristi se **jedna** Axolotl-PM
instanca koja opcionalno učitava oba plugina. SkyWars podaci spremaju se
odvojeno u `plugin_data/xSkywars`, a BedWars ostaje u
`plugin_data/xBedwars`. Mape su originalne iz izvornog CC BY 4.0
repozitorija. Soccer ostaje isključen.

Najprije zaustavi glavni server (samo jednom) i provjeri da su svjetovi
sigurnosno kopirani, zatim:

```bash
cd ~/new-ng
git pull --ff-only
NG_ENABLE_SKYWARS=1 NG_ENABLE_SOCCER=0 NG_PORT=19132 bash ./start.sh 2>&1 | tee renether.log
```

**Ovo je probna integracija**, nije potvrđeno funkcionalan SkyWars.
Izvorni pluginovi dijele NGEssentials, static Minigame stanje, evente
i neke Composer ovisnosti; mogući su konflikti pri učitavanju ili
ulasku u meč. Provjeri poruke konzole i `/plugins`, zatim `/sw`.
SkyWars NPC u glavnom Hubu sada ima klik koji pokušava pozvati
`xSkywars->joinArena($player)` ako je plugin uključen. Tek na VPS-u
treba potvrditi da učitava mapu i uspješno pokreće meč. Ako bilo što ruši BedWars, vrati se na
`NG_ENABLE_SKYWARS=0 NG_PORT=19132 bash ./start.sh`;
originalni podaci SkyWarsa ostat će sačuvani.

**Pravna napomena:** `Skywars/composer.json` navodi `proprietary`;
za javno izvođenje i izmjene valja prethodno razjasniti dopuštenja
nositelja prava, bez obzira na dostupnost izvora.

## Eksperimentalni SkyWars (odvojena instanca)

SkyWars koristi originalni `NetherGamesMC/plugins/Skywars` (`xSkywars`)
te CC BY 4.0 karte iz `NetherGamesMC/assets/Skywars`. Prvi pokušaj
izoliran je od BedWarsa: zasebna mapa podataka
`.runtime/skywars/data/`, vlastiti svjetovi, konfiguracija i `xSkywars.phar`,
zadani **UDP 19134**. Postojeći BedWars ostaje na svojem portu
(primjerice 19132). Soccer ostaje isključen.

Prije pokretanja provjeri dopuštenja za uporabu izvornog SkyWars
plugina. `Skywars/composer.json` navodi `proprietary`, a dio
zaglavlja zabranjuje izmjene bez dopuštenja: vidi
[LEGAL_COMPLIANCE.md](LEGAL_COMPLIANCE.md). Ovaj tehnički profil
**ne predstavlja pravno odobrenje**.

Za probni start u zasebnoj `tmux` sesiji (ne gasiti BedWars):

```bash
cd ~/new-ng
git pull --ff-only
tmux new -s renether-sw
NG_GAME=Skywars NG_PORT=19134 bash ./start.sh 2>&1 | tee skywars.log
```

Kada je server pokrenut, odvoji tmux pomoću `Ctrl+B`, zatim `D`.
Nakon toga pregledaj `skywars.log`, osobito greške pri učitavanju
plugina i mapa. Nije potvrđeno da će originalni xSkywars raditi bez
dodatnih kompatibilnosnih popravaka na Axolotl-PM 5.49.1.

SkyWars **nije ugrađen kao NPC u glavni Hub**; zasad se spaja zasebno
na port 19134. Ne pokretati `NG_REMIGRATE_ASSETS=1` na tom profilu,
jer njegovo prisilno premještanje mapa nije podržano.

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

U kodu i igri ime je `ReNether`. GitHub repozitorij je već preimenovan u `JJ-JaaN/ReNether`; lokalna putanja `~/new-ng` može ostati ista. Za ažuriranje starog `origin` URL-a koristi `git remote set-url origin git@github.com:JJ-JaaN/ReNether.git`.

## ReNether prikaz: MOTD, logo, natpisi na pločama

Za razliku od stare verzije, sada se **dva odvojena izvora** naziva ažuriraju: `server.properties` (MOTD) i `NGEssentials.phar` `ServerManager::getMotd()` (odgovor na Bedrock query). `NGEssentials::getLogo()` prikazuje ReNether u igri. Glavni chat-prefiks i BedWars scoreboard također su preimenovani.

`ReNetherBranding.phar` gradi se automatski tijekom pokretanja. Registrira `ChunkLoadEvent`, prolazi postojeće učitane chunkove i mijenja **tekst stvarnih Minecraft ploča** koji sadrži `NetherGames`, `ngmc.co` ili samostalni `NG`; podržani su i kodovi boja. Promjene se spremaju normalnim World API-jem. Ploče iz učitanih arena obrađuju se kad se učitaju. To ne mijenja natpise fizički izgrađene od blokova, slikovne teksture ni licence u izvornom kodu.

Prije prvog ažuriranja ploča starter automatski sprema **jednokratni backup** `worlds/Hub` i `worlds/ArcadeLobby` u `.runtime/branding-backups/`. Izvorni BedWars podaci, arena-templateovi i MariaDB nisu preimenovani niti resetirani.

GitHub repozitorij se sada zove [JJ-JaaN/ReNether](https://github.com/JJ-JaaN/ReNether), i zato provjeri `origin` pa povuci novu verziju **nakon gašenja servera**:

```bash
cd ~/new-ng
git remote set-url origin git@github.com:JJ-JaaN/ReNether.git
git pull --ff-only
NG_ENABLE_SOCCER=0 NG_PORT=19133 bash ./start.sh 2>&1 | tee renether-branding.log
```

Provjeri da log sadrži `Configured server.properties MOTD: motd=ReNether`, `Configured listening port: server-port=19133`, `Verified MOTD/brand in built PHARs` i `Bedrock RakNet advertised name: ReNether`. Ako neki od tih zapisa nedostaje, novi proces možda nije uspješno pokrenut.

Provjeri **stvarni UDP ping odgovor** lokalno na VPS-u:

```bash
cd ~/new-ng
python3 scripts/check_bedrock_motd.py 19132 19133
sudo ss -lunp | grep -E ':(19132|19133)'
```

Očekivano: `UDP 19133: ADVERTISED='ReNether'` (može sadržavati Minecraft boje), dok 19132 ne odgovara ili prikazuje drugi server. `NG NG Bedwars` na 19132 znači da se Minecraft vjerojatno spaja na stari port. Ako ga prikazuje 19133, usporedi PID iz `ss` s novo pokrenutim procesom; ne briši `server.lock` ni svjetove. U igri se ploče ažuriraju kad igrač učita njihove chunkove. Za provjeru stvarnog sadržaja `server.properties`:

```bash
grep '^motd=' ~/new-ng/.runtime/data/server.properties
```

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

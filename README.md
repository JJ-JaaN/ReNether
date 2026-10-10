# new-ng (privatni razvojni projekt)

Pokušaj migracije NetherGames BedWars + NGEssentials na **Axolotl-PM 5.49.1**, protokol Bedrock **2193 / 26.50**, **bez Dockera**. Pokretanje koristi jedan ulazni skript: `./start.sh`. Mape se pri prvom pokretanju dohvaćaju iz [NetherGamesMC/assets](https://github.com/NetherGamesMC/assets) i kopiraju bez prepisivanja postojećih.

**Status: eksperimentalno. Nije potvrđena kompatibilnost niti uspješan rad BedWarsa.** Starter prenosi dio potrebnih API-ja, ali NetherGamesovi plugini očekuju starije `ProtocolInfo::PROTOCOL_*` konstante, različite klase te druge izmjene. Ne gasiti postojeći Docker BedWars dok novi build i testovi ne prođu.

## Ubuntu 24.04+ (odvojeni testni server)

```bash
git clone git@github.com:JJ-JaaN/new-ng.git
cd new-ng
chmod +x start.sh
./start.sh
```

`start.sh` tijekom prvog pokretanja instalira potrebne Ubuntu pakete kada ima administratorske dozvole, priprema lokalnu MariaDB, preuzima PHP runtime, Composer i DevTools, klonira Axolotl, NetherGames plugin source i samo BedWars world assete, pokušava složiti `.phar` datoteke te pokreće poslužitelj na **UDP 19133**. To je razvojni pokušaj i može stati zbog stvarnih API nekompatibilnosti; u tom slučaju ispisat će grešku umjesto lažne poruke o uspjehu.

Za korištenje porta 19132 nakon gašenja starog poslužitelja: `NG_PORT=19132 ./start.sh`. Za novi build: `NG_REBUILD=1 ./start.sh`. Za build bez pokretanja: `NG_BUILD_ONLY=1 ./start.sh`.

Podatci su u `.runtime/data/`, uključujući konfiguracije i bazu lokalne MariaDB, dok se upstream kodovi preuzimaju u `upstream/`. Git ignorira binarne buildove, podatke igrača i tajne. Nikad ne komitiraj `.runtime/` u Git.

**Nema Dockera, phpMyAdmina niti obaveznog proxyja.** Za test je uključen NGEssentials development mode. Autentifikacija Xboxa je omogućena u lokalnom `server.properties`. Baza je lokalna MariaDB. Ovo nije gotova produkcijska konfiguracija.

## Što je uključeno

- `scripts/apply_compat.py`: početni LGPL kompatibilnosni patch za Axolotl izvorni kod (samo jedan Bedrock protokol).
- `scripts/migrate_assets.sh`: prijenos originalnih BedWars mapa, `arenas.yml` i `WaitingLobby`, uz CC BY 4.0 atribuciju.
- `start.sh`: instalacija, build, baza, konfiguracija i pokretanje jednim pozivom.
- `ATTRIBUTION.md`: autori, izvori, licence i ograničenja.

## Blokatori prije javnog rada

- Moderni Axolotl BedrockProtocol ne sadrži NetherGamesove stare `PROTOCOL_1_20_*` i `PROTOCOL_1_21_*` konstante. Potrebno je portati NGEssentials/libVanilla i testirati login.
- Testirati učitavanje arena, generatore, blokove, shop, inventory i početak igre s 2 igrača.
- Zabilježiti Composer/PHP build i runtime logove; trenutno nema potvrde uspješnog builda.

Licence i originalni autor(i): [ATTRIBUTION.md](ATTRIBUTION.md).
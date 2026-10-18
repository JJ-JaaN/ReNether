# ReNether — pravne obavijesti i izvorni kod

ReNether je **neovisna, neslužbena prilagodba** starijih komponenti NetherGamesa.
Nije povezan s NetherGames Networkom, Mojangom, Microsoftom, PocketMine
ili projektom Axolotl-PM na način koji bi podrazumijevao njihovo odobrenje.

## Autori i uvjeti po komponenti

| Sastavnica | Izvor / autori | Uvjeti i status |
| --- | --- | --- |
| Axolotl-PM / PocketMine izvedenice | [Axolotl-PM](https://github.com/axolotl-pm/PocketMine-MP), PocketMine doprinositelji | LGPL-3.0, zadržati copyright i obavijesti te odgovarajući izvorni kod |
| Originalni PocketMine-MP | [PocketMine-MP](https://github.com/pmmp/PocketMine-MP) | LGPL-3.0-or-later prema izvoru |
| NetherGames PocketMine-MP izvedenica | [NetherGamesMC/PocketMine-MP](https://github.com/NetherGamesMC/PocketMine-MP) | Provjeriti vlastite licence i obavijesti pri distribuciji |
| NetherGames BedWars, NGEssentials i biblioteke | [NetherGamesMC/plugins](https://github.com/NetherGamesMC/plugins) | Korijenska licenca AGPL-3.0, **ali** pojedina zaglavlja navode restriktivna vlasnička prava; opseg ovlasti **nije razjašnjen** |
| Originalne mape, Hub, arene | [NetherGamesMC/assets](https://github.com/NetherGamesMC/assets) | CC BY 4.0, copyright © NetherGames; potrebna atribucija i oznaka izmjena |
| ReNether skripte i kompatibilnosne prilagodbe | Izvorni kod ovog repozitorija, te prethodni radovi navedeni iznad | Ne mijenjaju automatski licence izvornog koda i ne daju prava na tuđe žigove |

## Značajne izmjene

- Prilagodba Axolotl-PM 5.49.1 i povijesnih NetherGames pluginova za lokalni razvojni poslužitelj.
- Izmjena i ponovno pakiranje plugin PHAR-ova putem `scripts/patch_plugin_phars.php`, te prilagodbe jezgre putem `scripts/apply_compat.py` i `scripts/sync_core_phar.php`.
- Izrada neslužbenog BedWars Huba, kompatibilnosnih zakrpa, javnog prikaza naziva **ReNether** te zamjena povijesnih natpisa na pločama putem `ReNetherBranding`.
- Originalne mape se preuzimaju iz izvora te se kod lokalnih promjena čuvaju backupi. Atribucija uz podatke nalazi se u `plugin_data/xBedwars/NETHERGAMES_ASSETS_CREDIT.txt`.

## Pristup odgovarajućem izvornom kodu

Ako se koristi modificirani program koji stvarno potpada pod AGPL-3.0,
**korisnicima koji ga koriste preko mreže** može biti potrebno ponuditi
Corresponding Source aktualne, pokrenute verzije, bez naknade, kroz
prikladan javno dostupan mehanizam. Sama privatna GitHub adresa nije dostupna
igračima i nije dovoljan pristup.

Za pripremu izvora treba sačuvati:

1. Točne revizije svih korištenih repozitorija, uključujući izvore Composer ovisnosti.
2. ReNether skripte za dohvat, izgradnju i zakrpe, zajedno s promijenjenim izvornim datotekama ili reproducibilnim postupkom njihove izrade.
3. Obavijesti o autorima, punu primjenjivu licencu i upute za izgradnju i instalaciju.
4. Put za pristup izvoru iz samog servisa, primjerice /licenses i stvarno dostupan URL, **nakon** što su prava na redistribuciju razjašnjena.

**Ne uključivati** privatne tokene, lozinke, bazu igrača, `.runtime/`, privatne konfiguracije i druge osobne podatke.

## Blokatori prije javnog objavljivanja

- [ ] Pribaviti pisano pojašnjenje od nositelja prava o odnosu između
  AGPL-3.0 licence u korijenu `NetherGamesMC/plugins` i vlasničkih
  zabrana u pojedinim datotekama. Ne pretpostavljati da jedna oznaka
  automatski poništava drugu.
- [ ] Utvrditi dopušta li svaka uključena ovisnost izmjenu i ponovnu distribuciju.
- [ ] Za AGPL-sastavnice, gdje se zahtijeva, omogućiti stvarno dostupan
  odgovarajući izvorni kod verzije koju korisnici koriste.
- [ ] Za LGPL-sastavnice, sačuvati potrebne obavijesti, licence i izvorni kod
  izmjena; pri distribuciji poštovati i uvjete povezivanja i rekombinacije.
- [ ] Za CC BY 4.0 svjetove sačuvati zasluge autorima, poveznicu na licencu,
  podatke o izmjenama i ne implicirati odobrenje nositelja prava.
- [ ] Ne koristiti izvorni žig NetherGames kao da je ReNether službeni nastavak.

Ovaj dokument je inventar i plan usklađivanja, **ne potvrda pravne usklađenosti
i ne licenca za tuđe radove**. Dok se spor oko originalnih pluginova ne
razjasni, nemoj tvrditi da je javna distribucija ili javni poslužitelj
neosporno odobren.

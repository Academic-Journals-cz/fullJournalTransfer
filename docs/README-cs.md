# Full Journal Transfer (OJS 3.5) – česky

Plugin pro příkazovou řádku, který exportuje celý časopis z instalace OJS 3.5 do jednoho
archivu `tar.gz` a importuje ho do jiné instalace OJS 3.5 jako nový časopis: nastavení,
uživatelé a role, čísla, články včetně kompletního redakčního procesu (recenzní kola,
soubory a formuláře recenzentů, rozhodnutí, diskuse), log aktivity (event log a e-mailový
log), statistiky využití (metrics) a soubory.

Jde o přepis pluginu [Lepidus fullJournalTransfer](https://github.com/lepidus/fullJournalTransfer)
pro OJS 3.5, který udržuje [academic-journals-cz](https://github.com/academic-journals-cz).
Anglická verze dokumentace je v [`README.md`](../README.md).

## Kompatibilita

* OJS **3.5.0** (vyvíjeno a testováno na 3.5.0-5). Export i import musí běžet na **stejné
  verzi OJS** (archiv obsahuje surové řádky několika databázových tabulek).
* PHP 8.2+, `php-xml`, `php-mbstring`, `php-intl` (požadavky OJS 3.5).
* Archiv se zapisuje i čte v čistém PHP (rozšíření `zlib`); externí `tar` není potřeba,
  plugin tedy funguje i na instalacích na Windows.
* Všechny pluginy, které časopis používá (téma, registrační agentura DOI atd.), by měly být
  nainstalované i na cílovém webu: nastavení pluginů se přenáší beze změny, pluginy samotné ne.
* Jazyky časopisu musí být na cílovém webu nainstalované (Administrace > Nastavení webu >
  Jazyky). Jazyky, které na cílovém webu chybí, se z nastavení časopisu s varováním odeberou;
  primární jazyk časopisu je povinný.

## Instalace

1. Stáhněte balíček vydání (`fullJournalTransfer.tar.gz`).
2. Nahrajte ho v *Nastavení > Web > Pluginy > Nahrát nový plugin*, nebo ho rozbalte do
   `plugins/importexport/fullJournalTransfer` a spusťte
   `php lib/pkp/tools/installPluginVersion.php plugins/importexport/fullJournalTransfer/version.xml`.
3. Plugin si při instalaci zaregistruje své import/export filtry v databázi. Registraci
   navíc kontroluje a opravuje při každém spuštění z příkazové řádky, takže aktualizace
   pluginu (nové filtry) nevyžaduje žádný ruční krok.

Plugin nemá webové rozhraní; stránka pluginu zobrazuje jen upozornění. Vše se provádí
z příkazové řádky v kořenovém adresáři instalace OJS.

## Použití

### Export

```bash
php tools/importExport.php FullJournalImportExportPlugin export /cesta/k/casopis.tar.gz <cesta_casopisu> [volby]
```

`<cesta_casopisu>` je URL cesta časopisu (např. `ojs` v `https://example.org/index.php/ojs`).
Relativní cesta k archivu se vyhodnocuje vůči aktuálnímu adresáři: `public/ojs.tar.gz` je adresář
`public/` instalace, zatímco `/public/ojs.tar.gz` je adresář v kořeni souborového systému. Archiv
nezapisujte do adresáře dostupného z webu (`public/`): obsahuje celý časopis včetně údajů
uživatelů a hashů hesel. Adresář musí být zapisovatelný pro uživatele, pod kterým běží PHP.

### Import

```bash
php tools/importExport.php FullJournalImportExportPlugin import /cesta/k/casopis.tar.gz <uzivatelske_jmeno> [volby]
```

`<uzivatelske_jmeno>` je existující uživatel cílového webu (typicky administrátor webu);
je zaznamenán jako aktér importu v záznamech event logu, které import sám vytváří.
Časopis se vytvoří s cestou, kterou měl na zdrojovém webu, pokud není zadáno
`--journal-path`. Časopis se stejnou cestou na cílovém webu nesmí existovat.

Celý import běží v **jedné databázové transakci**: pokud cokoli selže, všechny změny
v databázi se vrátí zpět a soubory zapsané pro nový časopis se smažou. Varování (např.
nenalezený uživatel) se vypíší na konci a import nezastaví. Průběh se vypisuje po 100
uživatelích a po 25 příspěvcích; pro představu: časopis s 870 články, 3 000 soubory a 2 100
uživateli se na linuxovém serveru importuje 3–7 minut.

### Volby

| Volba | Účinek |
| --- | --- |
| `--no-metrics` | Neexportovat / neimportovat statistiky využití. |
| `--no-activity-log` | Neexportovat / neimportovat log aktivity (event log a e-mailový log) příspěvků. |
| `--no-public-files` | Neexportovat / neimportovat veřejné soubory časopisu (loga, favicon, styl, obálky čísel a článků, obrázky kategorií a highlightů). |
| `--journal-path <cesta>` (import) | Vytvořit časopis s touto URL cestou místo exportované. Funguje i `--journal-path=<cesta>`. |

Volby platí pro příkaz, u kterého jsou zadány: archiv exportovaný s `--no-metrics` prostě
statistiky neobsahuje; `--no-metrics` při importu ignoruje statistiky, které v archivu jsou.

### Tipy

* Velké časopisy potřebují hodně paměti: spouštějte příkazy jako `php -d memory_limit=-1 ...`.
* Před importem si udělejte zálohu databáze cílového webu (transakce chrání před
  částečným importem, ne před omylem).
* Po importu přebudujte vyhledávací index cílového webu:
  `php tools/rebuildSearchIndex.php` (index není součástí archivu).
* Import neposílá žádné e-maily a nikdy nemění hesla (viz *Uživatelé* níže).
* XML v archivu se před začátkem importu validuje proti schématu pluginu
  (`fullJournal.xsd`). Export svůj dokument validuje také; pokud validace selže, uloží se
  XML vedle archivu jako `<archiv>.invalid.xml` pro kontrolu.

## Struktura archivu

```
casopis.tar.gz
├── <cesta_casopisu>.xml     časopis (fullJournal.xsd: nativní XML OJS + rozšíření)
├── journals/<id>/           adresář souborů časopisu (soubory příspěvků, sazebnice čísel)
├── contexts/<id>/library/   soubory knihovny vydavatele
├── public/journals/<id>/    veřejné soubory (loga, obálky, ...)
└── metrics/<tabulka>.csv    statistiky využití, jedno CSV na tabulku metrics
```

Na soubory se z XML odkazuje cestou uvnitř archivu; do XML se nic nevkládá (base64),
takže XML zůstává i u velkých časopisů přiměřeně malé.

## Co se přenáší

Úroveň časopisu:

* nastavení časopisu (všechny řádky `journal_settings` včetně nastavení přidaných pluginy),
  nastavení pluginů časopisu (`plugin_settings`), aktuální číslo, vlastní pořadí čísel,
* uživatelské skupiny (role) se všemi vlastnostmi, nastaveními a přiřazením k fázím;
  automatická přiřazení editorů sekcí/kategorií,
* uživatelé: všichni uživatelé zapsaní v časopise a každý uživatel, na kterého odkazuje
  redakční proces jeho příspěvků (viz *Uživatelé* níže), s rolemi v časopise, profilem,
  recenzními zájmy a platností rolí,
* sekce, kategorie (včetně hierarchie a obrázků), recenzní formuláře a jejich prvky,
  žánry (typy souborů), typy oznámení a oznámení, highlighty na úvodní stránce,
  navigační menu a jejich položky, e-mailové šablony (vlastní a upravené),
* DOI včetně stavu registrace a nastavení (např. data registrační agentury),
* soubory knihovny vydavatele (úroveň časopisu i příspěvků),
* instituce (pro institucionální statistiky COUNTER) včetně IP rozsahů,
* veřejné soubory časopisu.

Čísla a články (přes nativní import/export OJS s rozšířeními):

* čísla se sazebnicemi, obálkami, vlastním pořadím sekcí, obsahem,
* články se všemi verzemi publikace (každá verze si zachová své číslo), metadaty,
  přispěvateli, klíčovými slovy, citacemi, sazebnicemi, kategoriemi publikace, obálkami, DOI,
* všechny soubory příspěvků se všemi revizemi (příspěvek, recenze, revize, redakční
  úpravy, produkce, závislé soubory, přílohy recenzentů, přílohy diskusí),
* redakční proces: přiřazení účastníků, recenzní kola a jejich stav, recenzní přiřazení
  se vším, co recenzent udělal (data, doporučení, odpovědi v recenzním formuláři,
  komentáře pro autory a editory, přílohy), rozhodnutí editorů, diskuse s účastníky,
  poznámkami a soubory, navržení recenzenti,
* log aktivity: event log každého příspěvku (včetně nastavení, např. názvu souboru)
  a e-mailový log včetně příjemců; záznamy, které import sám vygeneruje, se nahradí
  původními, takže historie vypadá jako na zdrojovém webu. Uživatelé v záznamech se
  mapují podle e-mailu; ID souborů, souborů příspěvků a recenzních přiřazení uvnitř
  záznamů se přemapují také,
* data příspěvků (datum odeslání včetně času, poslední změna, poslední aktivita), data
  čísel a časová razítka poslední změny,
* statistiky využití: všechny tabulky `metrics_*` OJS 3.4/3.5 (`metrics_context`,
  `metrics_issue`, `metrics_submission`, `metrics_submission_geo_daily/monthly`,
  `metrics_counter_submission_daily/monthly`, `metrics_counter_submission_institution_daily/monthly`)
  s přemapovanými ID čísel, sazebnic, příspěvků, souborů příspěvků a institucí.

### Uživatelé

Uživatelé jsou v OJS objekty celého webu, proto je import páruje podle **e-mailové adresy**:

* Pokud na cílovém webu existuje uživatel se stejným e-mailem, použije se tento účet:
  dostane role v časopise, jeho profil a heslo se nemění.
* Jinak se vytvoří nový účet s exportovanými údaji. Pokud je uživatelské jméno na cílovém
  webu obsazené, přidá se číselná přípona (`jsmith` → `jsmith1`). Hashe hesel se kopírují beze změny,
  takže se uživatelé přihlásí starým heslem. Výjimkou jsou účty se zastaralým hashem
  (OJS 2.x `md5`/`sha1`), který je svázaný s uživatelským jménem a algoritmem: pokud muselo
  být takové uživatelské jméno změněno (nebo má cílový web jiné nastavení `encryption`),
  musí uživatel jednou použít „Zapomněli jste heslo?“; tito uživatelé jsou na konci importu
  vypsáni jako varování.
* Všechny odkazy na uživatele (autoři, účastníci, recenzenti, účastníci diskusí,
  rozhodnutí, nahrávající soubory, záznamy logu, ...) se přemapují na účty cílového webu.
  Odkazy na uživatele, kteří na zdrojovém webu už neexistují, se s varováním přeskočí.

### Co se nepřenáší

* data úrovně webu: nastavení webu, pluginy webu a jejich nastavení, nastavení notifikací
  uživatelů pro časopis (`notification_subscription_settings`), čekající notifikace
  („úkoly“ editorů), pozvánky,
* předplatné a platby (`subscription_types`, `subscriptions`, `institutional_subscriptions`,
  `completed_payments`, `queued_payments`),
* vyhledávací index (po importu přebudovat), OAI tombstones smazaných objektů, logy
  událostí využití, které ještě nebyly zpracovány do tabulek metrics (`usageStats/`
  v adresáři souborů), logy naplánovaných úloh, dočasné soubory, sessions.

### ID a URL

Všechna databázová ID jsou na cílovém webu nová. Import zapíše mapování starých ID na nová
pro příspěvky, publikace, čísla, sazebnice, sekce, kategorie, uživatelské skupiny, recenzní
formuláře, položky navigačního menu, DOI, instituce a soubory knihovny do souboru
`journal_<nové id>_id_relation.txt` v adresáři souborů nového časopisu, aby bylo možné
přesměrovat externí odkazy (URL s ID článků, DOI se sufixy z ID, ...). URL cesty článků
a čísel (`url_path`) zůstávají zachovány.

## Řešení problémů

* Plugin staví na nativním import/export pluginu a na import/exportu uživatelů OJS.
  Pokud nejde nějaký článek exportovat, zkuste nejdřív nativní plugin
  (`php tools/importExport.php NativeImportExportPlugin export ...`).
* Příspěvky, které nelze v XML vyjádřit (nedokončený průvodce odesláním, chybějící název),
  se při exportu s hlášením přeskočí. Nepravidelná data přispěvatelů a sazebnic, běžná
  u časopisů migrovaných ze starších verzí OJS (přispěvatelé bez křestního jména nebo bez
  platné uživatelské skupiny, afiliace bez názvu, sazebnice bez popisku nebo s chybějícím
  souborem), se za běhu opraví a vypíší jako varování, export se na nich nezastaví.
* Pokud exportované XML neprojde validací, vypíše se každá chyba validace i s číslem nebo
  článkem, ke kterému patří, a XML se uloží jako `<archiv>.invalid.xml`.
* `Instantiation of the plugin ... has failed` při importu pochází z toho, že OJS načítá
  všechny pluginy, aby pro nový časopis nainstalovalo jejich výchozí nastavení; rozbitý
  nebo neúplně nainstalovaný plugin (např. chybějící adresář `vendor/`) toto vypíše a je
  přeskočen.
* Chyby filtrů typu `No filter found for "extended-article=>native-xml"` znamenají neúplnou
  registraci filtrů v databázi; spuštění libovolného příkazu export/import ji opraví,
  případně znovu spusťte
  `php lib/pkp/tools/installPluginVersion.php plugins/importexport/fullJournalTransfer/version.xml`.

## Změny oproti pluginu Lepidus (větev OJS 3.3 / 3.4)

* Přepsáno pro OJS 3.5 (PHP 8, modely Laravel/Eloquent, repozitáře, nové popisy typů
  filtrů, nový import/export uživatelů).
* Nově: log aktivity (event log a e-mailový log), statistiky využití OJS 3.4+, DOI se
  stavem registrace, kategorie, knihovna vydavatele, highlighty, instituce, uživatelské
  skupiny se všemi vlastnostmi, obecná nastavení časopisu a pluginů, veřejné soubory,
  články nezařazené do žádného čísla (odmítnuté, v redakčním procesu), více verzí
  publikace v různých číslech, revize souborů příspěvků, stav recenzních kol, navržení
  recenzenti, přiřazení editorů sekcí/kategorií.
* Uživatelům zůstávají hesla; existující účty na cílovém webu se použijí podle e-mailu.
* Import běží v transakci a po selhání po sobě uklidí.
* Archiv zachovává soubory v původní adresářové struktuře (žádné base64 v XML) a vytváří
  i rozbaluje se v čistém PHP (není potřeba `tar`, funguje i na Windows).
* Automatizované testy pluginu Lepidus (PHPUnit, Cypress) nebyly přeneseny.

## Licence

GNU General Public License v3.0

Copyright (c) 2014-2024 Lepidus Tecnologia
Copyright (c) 2025-2026 academic-journals-cz

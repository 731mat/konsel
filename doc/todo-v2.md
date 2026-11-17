# Konšel – implementační plán v2

> **Konšel** = historický název pro člena městské rady. Systém pro přípravu a vedení zasedání zastupitelstva a rady obce:
> **program → podklady pro zastupitele → pozvánka → průběh (docházka, hlasování, diskuse) → zápis → usnesení a výpisy → archiv.**
>
> Zdroj požadavků: [todo-v1.md](todo-v1.md) (rozbor konkurence, kap. 5, 6, 8, 15, 16).
> Pravidla kódu a slovníček: [../CLAUDE.md](../CLAUDE.md). **Každý task se řídí CLAUDE.md.**

---







## Jak s tímhle plánem pracovat

1. Tasky se dělají **po jednom a popořadě** (T00, T01, …). Každý je dimenzovaný na jednu session.
2. Spuštění v nové session: `Udělej task T07 podle doc/todo-v2.md`.
3. Task je hotový, až když:
   - jsou splněná všechna kritéria **Hotovo když**
   - `composer qa` je zelené (od T02)
   - `DemoSeeder` je rozšířený o data nové funkce (od T11)
   - v tomto souboru je zaškrtnuté `[x]` a do **Deníku** na konci přibyly 1–3 řádky (odchylky od plánu, rozhodnutí, dluhy)
   - je vytvořený git commit `T07: <popis>`
4. Když task narazí na rozpor s plánem, **nevymýšlí vlastní architekturu**. Zapíše rozpor do Deníku a zeptá se.
5. Tasky nezačínají práci na dalším tasku „když už jsou v tom“.

---

## Architektonická rozhodnutí (ADR)

| # | Rozhodnutí | Proč |
|---|---|---|
| A1 | **PHP 8.4, Nette 3.3 (application), Latte 3.1**, `declare(strict_types=1)` všude | Aktuální stabilní stack. |
| A2 | **Doctrine ORM 3** přes `nettrine/*` (ne Nette Database Explorer) | Opravdové entity s chováním, abstrakce DB platformy (SQLite → MySQL = změna konfigurace), migrace, optimistic locking. |
| A3 | **SQLite** (WAL, `foreign_keys=ON`, `busy_timeout`) pro vývoj i první provoz, později **MySQL/MariaDB** (T41) | Nulová instalace teď, jasná cesta dál. |
| A4 | Migrace dědí z `SchemaMigration` a píšou se přes `createTable()` + `TableBuilder` / `alterTable()` (nedeprekované DBAL editor API, SQL generuje platforma za běhu), ne raw SQL. Výjimka: FTS indexy (T32), větvené podle platformy. | Stejné migrace poběží na SQLite i MySQL. |
| A5 | Struktura **Model + moduly** (viz níže): `app/Model/<Doména>/` = entity, fasády, repozitáře a commandy pohromadě; `app/<Název>Module/<Presenter>/` = presenter, šablony a data formulářů v jedné složce (AdminModule, PublicModule, ApiModule, ErrorModule) | Zvyklost vlastníka projektu (změněno v T12a). Pravidla zůstávají: presentery jen přes fasády, entity bez závislosti na Nette. |
| A6 | Kód, třídy a DB **anglicky**, UI a dokumenty **česky**, vazby přes slovníček v CLAUDE.md | Konzistence a žádné „Zasedani“ vedle „Meeting“. |
| A7 | **Multi-tenant od začátku** (entita `Municipality` + Doctrine SQL filtr) | Teď to stojí málo, později by to byl přepis. Provozovat se dá i jen pro jednu obec. |
| A8 | Testy **Nette Tester**, statická analýza **PHPStan level max** (+ nette, doctrine, strict rules), coding standard **ECS** | Vstupní brána kvality pro každý task. |
| A9 | Frontend: server-side Latte + **Naja** (AJAX snippety) + **Bootstrap 5.3**, build přes **Vite**. Žádné SPA. | Jednoduché, robustní, nativní pro Nette. |
| A10 | Texty bodů a usnesení jako **prostý text s odstavci** (ne rich text) | Bezbolestný převod do DOCX. Rich text se dá přidat později. |
| A11 | **PDF přes mPDF** z Latte/HTML šablon v `resources/documents` (čisté PHP, písmo DejaVu Sans pro češtinu) za rozhraním `PdfRenderer`. DOCX (PhpWord) jen pokud ho obce budou chtít upravovat ve Wordu. | Změněno na přání vlastníka (dříve PhpWord + Gotenberg): žádný další kontejner, šablony se píšou jako HTML. |
| A12 | Externí služby (AI, přepis audia, ARES, mail, storage) vždy za **rozhraním v `Model/<Doména>`**, implementace vedle něj s prefixem technologie (`OpenAi…`, `Local…`) | Testovatelnost a možnost vyměnit dodavatele. |
| A13 | Zastupitelé se přihlašují přes **osobní token v odkazu** (per osoba × zasedání, v DB jen hash, expirace, revokace) | Pohodlí jako u konkurence, ale dohledatelné a odvolatelné. |
| A14 | Souběžná editace = **zámek bodu s TTL + heartbeat** a k tomu Doctrine `#[Version]` jako pojistka. Synchronizace přes polling snippetů (bez websocketů). | Jednoduché a spolehlivé. Mercure/SSE se dá doplnit později. |
| A15 | Čas jen přes `Psr\Clock\ClockInterface` (symfony/clock: `NativeClock`, v testech `MockClock`), vždy `DateTimeImmutable`, časová zóna Europe/Prague | Deterministické testy lhůt, zámků a tokenů. |
| A16 | **Vše běží v Dockeru** (docker compose): PHP + Apache, Node/Vite, Mailpit, Gotenberg, později MariaDB. Na hostiteli se nic neinstaluje, příkazy jdou přes `make` (wrapper nad `docker compose`). | Čistý Mac, stejné prostředí pro vývoj i produkci. |
| A17 | **AI = OpenAI GPT API** za rozhraním `AiClient`. API klíč zadává správce obce v Nastavení, v DB je šifrovaný (libsodium, klíč aplikace mimo repo). Bez klíče se AI funkce v UI nezobrazí. | Přání vlastníka (T12b). Každá obec platí vlastní spotřebu, klíč neleží v repu ani v čitelné podobě v DB. |

---

## Cílová struktura projektu

```
konsel/
├── app/
│   ├── Bootstrap.php
│   ├── @layout.latte                 # společný HTML layout (moduly ho najdou samy)
│   ├── Model/                        # vše doménové, po doménách – entity, enumy, VO, fasády, repozitáře, commandy
│   │   ├── Shared/                   # výjimky, IntegerId, TenantOwned, Timestampable, TransactionManager, ValueObject/
│   │   ├── Storage/                  # FileStorage (rozhraní), LocalFileStorage, UploadedFile
│   │   ├── Municipality/             # Municipality, MunicipalityFacade, DoctrineMunicipalityRepository, Command/
│   │   ├── User/                     # User, Role, UserFacade, LoginThrottle, PasswordPolicy, …
│   │   ├── People/                   # Person, Body, Term, Membership, PeopleFacade, …
│   │   ├── Meeting/                  # Meeting, MeetingStatus, MeetingFacade, (AgendaItem, Attendance, VotingRound, …)
│   │   ├── Ai/                       # AiClient (rozhraní), OpenAiClient, AiFacade (T12b)
│   │   ├── Resolution/  Comment/  Attachment/  Template/  Document/  Access/  Audit/  Search/  Ares/
│   │   └── <Doména>/Command/*.php    # readonly vstupní DTO fasád
│   ├── AdminModule/                  # administrace úřadu (/admin)
│   │   ├── @layout.latte
│   │   ├── SecuredPresenter.php      # předek admin presenterů: přihlášení, obec, #[Allowed]
│   │   ├── Dashboard/                # DashboardPresenter.php + default.latte
│   │   ├── Meeting/                  # MeetingPresenter.php, MeetingFormData.php, default.latte, detail.latte, …
│   │   └── Member/ Person/ User/ Settings/ Account/ …
│   ├── PublicModule/                 # veřejná část: Homepage/, Sign/ (přihlášení)
│   ├── PortalModule/                 # pohled zastupitele přes token (T18)
│   ├── ApiModule/                    # JSON API: Health/ (/api/health)
│   ├── ErrorModule/                  # Error4xx/, Error5xx/
│   ├── UI/                           # BasePresenter, Form/ (FormFactory, BootstrapFormRenderer, FormErrors), Menu/
│   ├── Core/                         # průřezová infrastruktura bez doménové logiky
│   │   ├── Routing/  Security/  Tenancy/  Latte/
│   │   └── Doctrine/                 # middleware, typy, subscribery, Migration/ (SchemaMigration, TableBuilder)
│   └── Console/                      # Symfony console příkazy, Demo/DemoSeeder
├── assets/                           # js, scss → Vite → www/assets
├── bin/console
├── config/                           # common.neon, services.neon, doctrine.neon, local.neon (gitignored)
├── migrations/
├── resources/                        # docx šablony, prompty AI, šablona migrace
├── tests/                            # Unit/, Integration/ (zrcadlí app/), Support/, fixtures/
├── var/                              # database.sqlite, storage/ – mimo www, gitignored
├── temp/  log/
├── www/index.php
└── doc/
```

**Vstupní body:** www/index.php (web), bin/console (CLI), tests/bootstrap.php (testy).

---

## Fáze 0 – Prostředí a kostra

### [x] T00 – Vývojové prostředí v Dockeru
**Cíl:** Celé prostředí běží v kontejnerech, na Macu se nic neinstaluje.
- docker/php/Dockerfile: `php:8.4-apache` + rozšíření `pdo_sqlite`, `intl`, `mbstring`, `zip`, `gd`, `opcache`, `fileinfo`. Composer z image `composer:2`, `mod_rewrite`, DocumentRoot `/app/www`, dev php.ini (limity uploadu, `display_errors`).
- compose.yaml: služba `app` (port 8080, bind mount projektu). Další služby (node, mailpit, gotenberg, mariadb) přidávají tasky, které je potřebují.
- Makefile: `make up`, `down`, `build`, `sh`, `composer ARGS=…`, `console ARGS=…`, `qa`, `test`, `logs`
- `git init`, .gitignore (`temp/*`, `log/*`, `var/*`, `vendor/`, `node_modules/`, `config/local.neon`, `www/assets/`), .editorconfig, .dockerignore
**Hotovo když:** `make up` nastartuje kontejner, `make sh` → `php -v` ≥ 8.4, `php -m` obsahuje uvedená rozšíření, `composer -V` funguje a repo má první commit.

### [x] T01 – Kostra Nette aplikace
**Cíl:** Běžící prázdná aplikace se strukturou podle plánu.
- composer.json ručně (ne create-project): `nette/application`, `nette/bootstrap`, `nette/di`, `nette/forms`, `nette/http`, `nette/security`, `nette/utils`, `nette/mail`, `latte/latte`, `tracy/tracy`. PSR-4 `App\\` → `app/`.
- `App\Bootstrap`, www/index.php, `RouterFactory`, `Presentation/Home` s `@layout.latte` a stránkou „Konšel“
- config rozdělit na `common.neon` (parametry, aplikace, latte), `services.neon`, `local.neon.dist`
- `Error/Error4xx` + `Error5xx` presentery
- adresáře `temp/`, `log/`, `var/` s `.gitkeep`
**Hotovo když:** http://localhost:8080 ukáže titulní stránku, Tracy bar je v dev režimu, 404 vrací vlastní stránku.

### [x] T02 – Brána kvality
**Cíl:** Automatická kontrola kvality od prvního řádku.
- dev závislosti: `nette/tester`, `phpstan/phpstan`, `phpstan/phpstan-nette`, `phpstan/phpstan-strict-rules`, `phpstan/extension-installer`, `symplify/easy-coding-standard`
- phpstan.neon (level max, paths app + tests), ecs.php (PSR-12 + `declare_strict_types`, `final` třídy, řazení importů)
- tests/bootstrap.php (Tester + kontejner pro integrační testy), ukázkový test
- composer scripts: `stan`, `cs`, `cs-fix`, `test`, `qa` (= cs + stan + test)
**Hotovo když:** `composer qa` projde. Úmyslně rozbitý typ ho shodí.

### [x] T03 – Doctrine, migrace, konzole
**Cíl:** Perzistence a CLI připravené.
- `nettrine/dbal`, `nettrine/orm`, `nettrine/migrations`, `nettrine/cache`, `contributte/console`, `phpstan/phpstan-doctrine`
- doctrine.neon: SQLite `%appDir%/../var/database.sqlite`, attribute mapping na `app/Domain`
- `SqlitePragmaMiddleware` (DBAL middleware: `foreign_keys=ON`, `journal_mode=WAL`, `busy_timeout=5000`), aktivní jen pro SQLite
- bin/console, příkazy `migrations:*`, `orm:*`
- composer script `db:reset` (smazat DB → migrate → seed)
- `qa` rozšířit o `orm:validate-schema` nad čerstvě migrovanou testovací DB
- testy: integrační testy používají samostatnou SQLite DB (soubor v temp/, nebo in-memory) s migracemi
**Hotovo když:** `bin/console migrations:migrate` vytvoří DB, `orm:validate-schema` je OK, PHPStan rozumí repozitářům.

### [x] T04 – Doménové základy a tenancy
**Cíl:** Společné stavební kameny pro všechny další entity.
- `Domain/Shared`: `DomainException` (hierarchie: `NotFound`, `InvalidState`, `AccessDenied`, `ValidationFailed`), trait `Timestampable` (createdAt/updatedAt přes lifecycle callbacks a Clock)
- `Core/Clock`: `SystemClock`, `FrozenClock` (+ registrace v DI)
- entita `Municipality` (name, ico, address, slug, createdAt)
- `CurrentMunicipality` služba (pro web z přihlášeného uživatele, pro CLI z parametru) + Doctrine SQL filtr `TenantFilter` pro entity s rozhraním `TenantOwned`
- vzor repozitáře: rozhraní v Domain, Doctrine implementace v Infrastructure
- příkaz `municipality:create`
- první migrace (Schema API)
**Hotovo když:** jde založit obec z CLI. Test ověří, že filtr skryje data cizí obce.

---

## Fáze 1 – Uživatelé, obec, orgány

### [x] T05 – Uživatelé a přihlášení
**Cíl:** Úředníci se přihlásí e-mailem a heslem.
- entita `User` (email unique, passwordHash, name, role, active, lastLoginAt, municipality nullable u superadmina)
- `Authenticator` (`Nette\Security\Passwords`, rehash při loginu), `SimpleIdentity` s id, rolí a municipalityId
- `Sign` presenter (in/out), „zapamatovat si mě“, omezení pokusů (5/15 min na e-mail+IP)
- `SecuredPresenter` (Admin modul jen pro přihlášené, přesměrování s návratem na původní URL)
- příkaz `user:create`
**Hotovo když:** login/logout funguje, špatné heslo hlásí chybu, test pokrývá authenticator i throttling.

### [x] T06 – Role a oprávnění (ACL)
**Cíl:** Princip nejmenšího privilegia.
- enum `Role`: `SuperAdmin` (správce systému), `Admin` (správce obce), `Editor` (úředník/zapisovatel), `Reader` (náhled)
- `Authorizator` (Nette Permission): zdroje `meeting`, `agenda`, `minutes`, `resolution`, `member`, `user`, `settings`, `template`, `document`; privilegia `view`, `edit`, `delete`, `manage`
- atribut `#[Allowed(resource, privilege)]` na `action*`/`handle*`/`render*`, kontrolovaný v `SecuredPresenter::checkRequirements()`
- matice oprávnění zdokumentovaná v doc/permissions.md
**Hotovo když:** testy ověří celou matici. Reader nesmí editovat (403).

### [x] T07 – Admin layout a UI kit
**Cíl:** Jednotný vzhled a nástroje pro všechny další obrazovky.
- služba `node` v compose.yaml (Vite dev server na portu 5173, `make npm ARGS=…`)
- Vite + Bootstrap 5.3 + Naja + netteForms.js, `ViteAssets` (čte manifest, v dev režimu dev server)
- layout: horní lišta (obec, uživatel, odhlášení), postranní menu (Zasedání, Usnesení, Úkoly, Hledat, Nastavení), přepínač orgánu (Zastupitelstvo / Rada, zatím jen UI), flash zprávy, breadcrumbs
- `FormFactory` (Bootstrap 5 renderer, CSRF, české chybové hlášky)
- `contributte/datagrid` pro seznamy (napojení na Doctrine QueryBuilder)
- `LatteExtension`: `czDate`, `czDateTime`, `fileSize`, `nl2p`
- prázdný Dashboard
**Hotovo když:** po přihlášení se zobrazí dashboard v layoutu, funguje na mobilu, `npm run build` projde.

### [x] T08 – Úložiště souborů
**Cíl:** Abstrakce pro přílohy, logo a generované dokumenty.
- rozhraní `FileStorage` (`store(stream, name): StoredFile`, `readStream`, `delete`, `exists`), `LocalFileStorage` do `var/storage/{municipalityId}/{yyyy}/{mm}/{uuid}`
- value object `StoredFile` (path, originalName, mime, size, sha256)
- servírování souborů **jen přes presenter** s kontrolou ACL (`FileResponse`), nikdy přímo z www
**Hotovo když:** testy uloží, přečtou a smažou soubor. Path traversal je ošetřený.

### [x] T09 – Nastavení obce
**Cíl:** Údaje obce pro hlavičky dokumentů.
- `Municipality` rozšířit: oficiální název („Obec Neplachovice“), IČO, adresa, e-mail, telefon, web, logo/znak (přes FileStorage), počet obyvatel
- Admin `Settings` presenter: formulář obce
**Hotovo když:** admin údaje upraví a uloží, editor do nastavení nemá přístup.

### [x] T10 – Osoby, orgány, volební období, členství
**Cíl:** Evidence, kdo je v jakém orgánu (podklad pro docházku, hlasování a rozesílku).
- `Person` (titul před/za, jméno, příjmení, e-mail, telefon, volitelná vazba na `User`)
- `Term` (volební období, např. 2026–2030, from/to)
- `Body` (type enum: `Council` zastupitelstvo, `Board` rada, `Committee` výbor; název; volební období; počet členů; maska číslování usnesení – zatím jen string)
- `Membership` (person, body, funkce enum: starosta, místostarosta, radní, člen, předseda, …; validFrom/validTo)
- doménová pravidla: osoba nemůže mít v jednom orgánu dvě překrývající se členství. Pokud má zastupitelstvo méně než 15 členů a obec zakládá radu, systém **varuje** (nezakazuje).
- Admin `Member` presenter: seznam osob, orgány s členy, přidání a ukončení členství
**Hotovo když:** jde založit zastupitelstvo Neplachovic s členy. Unit testy pokrývají pravidla členství.

### [x] T11 – Správa uživatelů úřadu a demo data
**Cíl:** Admin spravuje účty a vývoj má realistická data.
- Admin `User` presenter: seznam, založení, změna role, deaktivace, reset hesla (vygeneruje dočasné heslo a vynutí změnu)
- „Můj účet“: změna hesla
- `DemoSeeder` + příkaz `app:seed-demo`: obec, zastupitelstvo (9 členů), rada, uživatelé všech rolí. **Od teď ho každý task rozšiřuje.**
**Hotovo když:** `composer db:reset` vytvoří použitelné demo, deaktivovaný uživatel se nepřihlásí.

---

## Fáze 2 – Zasedání a program

### [x] T12 – Zasedání a jeho životní cyklus
**Cíl:** Jádro systému.
- `Meeting` (body, number = pořadí v období, type enum: řádné / mimořádné / ustavující, scheduledAt, place, status, chairperson, recorder = zapisovatel, verifiers = ověřovatelé (2× Person), startedAt/endedAt)
- `MeetingStatus` jako stavový automat **v entitě**: `Draft → Shared → InProgress → Closed → MinutesApproved`. Nepovolený přechod vyhodí `InvalidState`. Návrat z `Closed` povolený jen adminovi a zapíše se do auditu (T39).
- `MeetingFacade` (create, update, transition)
- Admin `Meeting`: seznam (datagrid, filtr orgán/rok/stav), detail se záložkami Program / Průběh / Usnesení / Dokumenty (zatím prázdné), formulář
- Dashboard: nejbližší a poslední zasedání
- kontrola lhůty: při sdílení méně než 7 dní před zasedáním varování (úřední deska)
**Hotovo když:** jde založit zasedání a projít celým cyklem. Unit testy pokrývají všechny přechody.

### [x] T12a – Struktura podle zvyklostí vlastníka (Model + moduly)
**Cíl:** Projekt má strukturu, na kterou je vlastník zvyklý (zadání mimo původní plán).
- `app/Model/<Doména>/` místo Domain/Application/Infrastructure, moduly `AdminModule`, `PublicModule`, `ApiModule`, `ErrorModule` přímo v `app/`, každý presenter ve vlastní složce s PHP i Latte
- `Home` → `PublicModule/Homepage`, přihlášení `Public:Sign`, `/api/health` jako první endpoint ApiModule
- CLAUDE.md, ADR A5/A12 a cílová struktura přepsané
**Hotovo když:** `make qa` je zelené a všechny stránky fungují na nových cestách.

### [x] T12b – Napojení na OpenAI (GPT), klíč v nastavení
**Cíl:** Základ pro AI funkce (T37, T38, T40).
- rozhraní `AiClient` (Model/Ai) + `OpenAiClient` (HTTP klient nad Chat Completions / Responses API), timeout, srozumitelné chyby
- Nastavení obce: API klíč (šifrovaný v DB, v UI jen poslední 4 znaky), model, tlačítko „Otestovat připojení“
- bez klíče se AI funkce nezobrazují. Testy s fake klientem, žádné volání ven v testech.
**Hotovo když:** správce uloží klíč, test připojení ukáže výsledek a klíč není v DB čitelný.

### [x] T13 – Body programu
**Cíl:** Sestavení programu.
- `AgendaItem` (meeting, position, title, description, proposedResolution, rapporteur = předkladatel, discussion – vyplní se až v T22)
- přidat, upravit, smazat, změnit pořadí (SortableJS + Naja handle, pozice se přečíslují v transakci)
- tlačítko „Vložit standardní body“ (Zahájení, Určení ověřovatelů a zapisovatele, Schválení programu, …, Diskuse, Závěr)
- program je editovatelný jen ve stavech `Draft`/`Shared` (`InvalidState` jinak)
**Hotovo když:** program jde sestavit a přeskládat a testy hlídají pozice a stavová omezení.

### [x] T14 – Knihovna šablon usnesení
**Cíl:** Opakující se usnesení na jedno kliknutí.
- `ResolutionTemplate` (title, category, text s proměnnými `{obec}`, `{datum}`, `{cislo_zasedani}`, …, municipality nullable = globální šablona)
- správa v Nastavení, vložení do bodu programu (modal s hledáním)
- seed ~15 běžných šablon: schválení programu, určení ověřovatelů, rozpočtové opatření, smlouva o dílo, kupní smlouva, darovací smlouva, inventarizace, rozpočet, závěrečný účet, …
**Hotovo když:** šablona se vloží do bodu a proměnné se nahradí. Test pokrývá substituci.

### [x] T15 – Šablony zasedání (vč. ustavujícího)
**Cíl:** Zasedání předvyplněné jedním klikem.
- `MeetingTemplate` (název, typ zasedání, typ orgánu, položky: title, description, proposedResolution)
- „Nové zasedání ze šablony“
- seed: Řádné zasedání, **Ustavující zasedání bez rady** (< 15 členů), **Ustavující zasedání s radou** (≥ 15 členů). Obsah podle metodického doporučení MV č. 6.4: slib, ověřovatelé, program, uvolněné funkce, volba starosty a místostarosty, finanční a kontrolní výbor, odměny, u varianty s radou počet a volba radních.
- texty ustavujících šablon označit `// TODO ověřit proti metodice MV` a v Deníku vyznačit, že je musí zkontrolovat člověk
**Hotovo když:** zasedání ze šablony má kompletní program.

### [~] T16 – Přílohy k bodům
**Cíl:** Podklady ke každému bodu.
- `Attachment` (agendaItem, StoredFile, uploadedBy, publicForMembers bool)
- upload více souborů najednou s progress barem. Velké soubory (video, projektová dokumentace) nahrávat **po částech** (chunked upload, např. Uppy + vlastní endpoint), limit konfigurovatelný.
- stažení přes presenter s ACL, náhled obrázků a PDF
**Hotovo když:** jde nahrát soubor > 500 MB bez úpravy `upload_max_filesize`. Testy pokrývají ACL u stahování.

---

## Fáze 3 – Sdílení se zastupiteli

### [x] T17 – Přístupové tokeny
**Cíl:** Bezpečný odkaz bez hesla.
- `MeetingAccessToken` (meeting, person, tokenHash = sha256, createdAt, expiresAt, revokedAt, lastUsedAt, useCount)
- generování: 32 bajtů z `random_bytes`, base64url, plaintext se ukáže jen jednou (v e-mailu)
- `AccessTokenService`: issue, verify (porovnání v konstantním čase, kontrola expirace a revokace), revoke, regenerate
- omezení počtu pokusů na IP
**Hotovo když:** testy pokrývají expiraci, revokaci, neplatný token a FrozenClock.

### [x] T18 – Portál zastupitele
**Cíl:** Zastupitel vidí program a podklady z mobilu.
- modul `Portal`, routa `/z/<token>` → ověření → session (pak čisté URL bez tokenu, ochrana proti úniku přes Referer)
- vlastní jednoduchý layout (bez admin menu), mobile-first
- program, texty bodů, návrhy usnesení, přílohy ke stažení (jen `publicForMembers`)
- `Referrer-Policy: no-referrer`, `noindex`
**Hotovo když:** zastupitel s platným odkazem vidí jen svoje zasedání. Neplatný nebo odvolaný odkaz vrátí srozumitelnou chybu.

### [x] T19 – Komentáře a připomínky
**Cíl:** Zpětná vazba od zastupitelů přímo u bodu.
- `Comment` (agendaItem, authorPerson | authorUser, text, createdAt, resolvedAt, resolvedBy)
- portál: přidání komentáře k bodu, zastupitel vidí i komentáře ostatních zastupitelů
- admin: u bodu počet nevyřešených komentářů, boční panel, „označit jako vyřešené“
**Hotovo když:** komentář ze portálu se zobrazí v editaci programu u správného bodu.

### [x] T20 – Rozeslání pozvánky e-mailem
**Cíl:** Jedním klikem pozvat všechny členy orgánu.
- `Mailer` rozhraní. Dev: SMTP do kontejneru **Mailpit** (webové UI na portu 8025). Prod: SMTP.
- Latte e-mailová šablona: pozvánka, osobní odkaz do portálu, termín, místo, program
- `MailLog` (meeting, person, sentAt, status, error)
- „Sdílet se zastupiteli“: vygeneruje tokeny, odešle e-maily, převede stav do `Shared`. Jde znovu poslat jednomu členovi.
**Hotovo když:** demo zasedání odešle 9 e-mailů s funkčními odkazy a log ukáže stav.

---

## Fáze 4 – Průběh zasedání

### [x] T21 – Docházka a usnášeníschopnost
**Cíl:** Rychlé zaklikání přítomnosti, měnitelné kdykoli během zasedání.
- `Attendance` (meeting, person, status enum: přítomen / omluven / neomluven / neurčeno, arrivedAt, leftAt, note)
- předvyplnění ze členství platných k datu zasedání
- UI: seznam s přepínači (AJAX), „všichni přítomni“
- výpočet **usnášeníschopnosti**: přítomna nadpoloviční většina všech členů (zákon o obcích § 92). Ukazatel v hlavičce průběhu.
**Hotovo když:** změna docházky se okamžitě promítne do ukazatele. Unit testy pokrývají hranice výpočtu.

### [x] T22 – Hlasování
**Cíl:** Hlasy zapsané jedním klikem a výsledek spočítaný automaticky.
- `VotingRound` (agendaItem, sequence, type enum: návrh / protinávrh / pozměňovací návrh / volba osoby, proposalText, candidate Person nullable, result enum: přijato / nepřijato)
- `Vote` (votingRound, person, choice enum: pro / proti / zdržel se / nehlasoval). Hlasovat mohou jen přítomní (podle docházky v okamžiku hlasování).
- výsledek: přijato, když hlasů pro je víc než polovina **všech** členů zastupitelstva (§ 87). Pravidlo je ve value objectu `VotingRule`, takže ho jde přepnout pro radu.
- UI: matice člen × volba, tlačítko „všichni přítomní pro“ a pak výjimky, souhrn pro/proti/zdržel se
**Hotovo když:** testy pokrývají výsledky (remíza, nepřítomní, protinávrh přijatý dřív než původní návrh).

### [x] T23 – Diskuse a zápis po bodech
**Cíl:** Celý průběh zasedání na jedné obrazovce.
- `AgendaItem.discussion`, údaje zápisu na `Meeting`: zahájení a ukončení, počet hostů, kdo řídil, schválení programu (vazba na hlasování), poznámky
- obrazovka „Průběh“: docházka nahoře, pod ní body pod sebou (diskuse, hlasování, výsledek)
- program se po zahájení zasedání (`InProgress`) zamkne. Změna programu se řeší hlasováním o doplnění bodu (přidání bodu s příznakem „doplněno na zasedání“).
**Hotovo když:** demo zasedání jde celé „odehrát“ od zahájení po ukončení.

### [ ] T24 – Souběžná práce a zamykání bodů
**Cíl:** Starosta i zapisovatel pracují současně a nepřepisují se.
- `AgendaItemLock` (agendaItem, user, acquiredAt, expiresAt). TTL 60 s, heartbeat přes Naja každých 20 s, uvolnění při uložení nebo odchodu (`navigator.sendBeacon`).
- `LockService` (acquire, refresh, release, isLockedBy, převzetí vypršelého zámku)
- UI: „Upravuje Jana Nováková“, bod pro ostatní jen ke čtení
- `#[Version]` na `AgendaItem` a `Meeting`. Při konfliktu srozumitelná hláška a znovunačtení.
- polling snippetů průběhu každých 5 s (jen když je karta viditelná)
- příkaz `locks:cleanup`
**Hotovo když:** dva prohlížeče: druhý vidí zámek, po vypršení ho převezme. Testy LockService s FrozenClock.

---

## Fáze 5 – Usnesení

### [ ] T25 – Usnesení a automatické číslování
**Cíl:** Číslo usnesení v libovolném formátu, bez duplicit.
- `Resolution` (meeting, agendaItem, votingRound, number string, sequence int, text, status enum: přijato / zamítnuto / odloženo / revokováno, adoptedAt)
- value object `NumberingMask` s tokeny `{n}`, `{n:3}` (doplnění nulami), `{yyyy}`, `{yy}`, `{meeting}`, `{item}`, `{body}`. Validace masky. Příklady: `{n}/{yyyy}`, `ZO/{meeting}/{n}/{yy}`, `R-{n:3}/{yyyy}`.
- `NumberingSequence` (body, scope enum: rok / volební období / zasedání, scopeKey, lastValue). Unique constraint, přidělení v transakci s pessimistic lockem (SQLite: `BEGIN IMMEDIATE`).
- číslo se přidělí při zapsání výsledku hlasování, které zakládá usnesení. Přečíslování je jen ruční akce admina.
- nastavení masky u orgánu (UI z T10 rozšířit) s živým náhledem
**Hotovo když:** rozsáhlé unit testy masky a integrační test, že dvě souběžná přidělení nedají stejné číslo.

### [ ] T26 – Přehled usnesení a úkoly z usnesení
**Cíl:** Hlídání usnesení. Úkoly navíc oproti konkurenci.
- Admin `Resolution`: seznam (datagrid: orgán, rok, stav, fulltext v textu), detail s vazbou na zasedání a hlasování
- `ResolutionTask` (resolution, assignee Person, dueDate, status enum: otevřený / splněný / zrušený, note, completedAt)
- úkoly: přehled „Moje / Všechny / Po termínu“, widget na dashboardu
**Hotovo když:** usnesení jde filtrovat a úkol po termínu se zvýrazní.

---

## Fáze 6 – Dokumenty DOCX/PDF

### [~] T27 – Dokumentový engine
**Cíl:** Jedna infrastruktura pro všechny výstupy.
- `DocumentRenderer` rozhraní (DocumentType + data → DOCX stream), `PhpWordTemplateRenderer` (`phpoffice/phpword`: proměnné, `cloneBlock`/`cloneRow` pro body, členy a hlasy, vložení loga)
- `PdfConverter` rozhraní + `GotenbergPdfConverter` (služba `gotenberg` v compose.yaml, endpoint `/forms/libreoffice/convert`, timeout)
- `DocumentTemplate` (type enum: pozvánka / program / zápis / výpis usnesení / výpis všech usnesení / prezenční listina; body nullable; StoredFile). Fallback na resources/docx/.
- `GeneratedDocument` (meeting, type, format, StoredFile, generatedBy, generatedAt). Archiv vygenerovaných verzí.
- `DocumentDataProvider` pro každý typ: sestaví pole dat z domény. Renderer o doméně nic neví.
- specifikace proměnných v doc/docx-placeholders.md
**Hotovo když:** test vyrenderuje testovací šablonu, rozbalí DOCX (zip → document.xml) a ověří hodnoty. PDF konverze má integrační test (přeskočí se, když Gotenberg neběží).

### [x] T28 – Pozvánka a program
- výchozí DOCX šablona (hlavička s logem a údaji obce, termín, místo, program, podpis starosty, poznámka o úřední desce)
- tlačítka na zasedání: Stáhnout pozvánku DOCX / PDF
**Hotovo když:** pozvánka demo zasedání se otevře ve Wordu a vypadá jako úřední dokument.

### [ ] T29 – Zápis ze zasedání
- obsah podle § 95 zákona o obcích: místo a čas, přítomní (a omluvení), ověřovatelé a zapisovatel, program, u každého bodu diskuse, hlasování (pro/proti/zdržel se, volitelně jmenovitě), usnesení s číslem. Na konci podpisy (starosta, ověřovatelé) a datum vyhotovení.
- upozornění na lhůtu: zápis do 10 dnů od zasedání (dashboard)
**Hotovo když:** zápis demo zasedání obsahuje všechny náležitosti a jde ho doupravit ve Wordu.

### [ ] T30 – Výpisy z usnesení a prezenční listina
- výpis jednoho usnesení, výpis všech usnesení ze zasedání
- prezenční listina (členové s místem pro podpis a řádky pro hosty)
**Hotovo když:** všechny 4 dokumenty jdou stáhnout v DOCX i PDF.

### [ ] T31 – Vlastní DOCX šablony obce
- Nastavení → Dokumenty: nahrání vlastní šablony pro typ a orgán, kontrola, že obsahuje povinné proměnné (chybějící vypíše), náhled s demo daty, návrat k výchozí šabloně
**Hotovo když:** nahraná šablona obce se použije místo výchozí.

---

## Fáze 7 – Vyhledávání, archiv, rada

### [ ] T32 – Fulltextové vyhledávání
- rozhraní `SearchIndex` (index, remove, search s filtry orgán/rok/typ)
- `SqliteFtsSearchIndex` (FTS5 virtuální tabulka s diakritikou: `unicode61 remove_diacritics 2`). Migrace větvená podle platformy (výjimka z A4).
- indexace přes doménové události nebo Doctrine `postFlush` listener: zasedání, body, usnesení, diskuse, šablony
- Admin `Search`: výsledky se zvýrazněním a odkazem na místo, kde se našly
- příkaz `search:reindex`
**Hotovo když:** hledání „smlouva o dílo“ i „smlouva o dilo“ najde příslušné usnesení.

### [ ] T33 – Archiv a neměnnost
- archiv podle let a orgánů
- po `MinutesApproved` je zasedání **read-only** (doménová kontrola v entitách, ne jen v UI). Znovuotevření smí jen admin, s důvodem a záznamem do auditu.
**Hotovo když:** testy ověří, že schválené zasedání nejde měnit přes fasády.

### [ ] T34 – Modul rady
**Cíl:** Ověřit a dotáhnout, že vše funguje i pro radu (jde o další `Body`).
- funkční přepínač orgánu v layoutu (uložený v session), všechny seznamy filtrované podle orgánu
- vlastní číselná řada a vlastní DOCX šablony rady
- rozesílka jen členům rady, `VotingRule` pro radu
- zápis z rady: počet přítomných a podpisy starosty s místostarostou nebo radním
**Hotovo když:** demo rada má vlastní zasedání, usnesení s vlastní řadou a vlastní dokumenty.

### [ ] T35 – Ustavující zasedání – specifika
- slib členů: u docházky stav „složil slib / odmítl slib“ (odmítnutí = zánik mandátu, upozornění)
- volba osob: `VotingRound` typu volba s kandidáty, postupné hlasování podle návrhů
- listina slibu jako dokument
**Hotovo když:** demo ustavující zasedání jde odehrát včetně volby starosty a místostarosty a zápisu.

---

## Fáze 8 – Integrace a AI

### [ ] T36 – ARES
- `AresClient` rozhraní + implementace nad REST API ARES (`/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/{ico}`), cache 24 h, timeout, ošetření chyb
- UI: v bodu programu „Najít v ARES podle IČO“ vloží název, sídlo a IČO. V nastavení obce předvyplnění podle IČO.
**Hotovo když:** test s mockem HTTP klienta funguje a ruční test s IČO obce projde.

### [ ] T37 – AI asistent textů
- staví na `AiClient` z T12b (OpenAI GPT, klíč a model z Nastavení obce)
- funkce: text bodu z názvu a kontextu, návrh usnesení, úprava stylu („úředně“, „zkrátit“)
- prompty v souborech (resources/prompts/), kontext obce, příklady z knihovny šablon
- návrh se vždy jen **vloží k revizi**, nikdy se neuloží sám. Log použití (`AiUsageLog`: kdo, kdy, tokeny).
- minimalizace dat: neposílat osobní údaje, které nejsou nutné
**Hotovo když:** tlačítko „Navrhnout text“ funguje. Testy s fake generátorem.

### [ ] T38 – AI analýza příloh
- extrakce textu z PDF (`smalot/pdfparser`) a DOCX (PhpWord reader)
- „Navrhnout bod z přílohy“: AI navrhne název, text a návrh usnesení
**Hotovo když:** z demo smlouvy vznikne smysluplný návrh bodu.

### [ ] T39 – Audit log
- `AuditEntry` (municipality, user/person, entity, entityId, action, changes JSON, ip, createdAt)
- Doctrine `onFlush` listener pro klíčové entity (Meeting, AgendaItem, Vote, Resolution, Attendance, Membership, User)
- Admin: historie změn u zasedání a usnesení
**Hotovo když:** změna hlasu je dohledatelná (kdo, kdy, z čeho na co).

### [ ] T40 – Přepis audio záznamu
- **před implementací vybrat poskytovatele STT** (preferovat zpracování v EU, diarizace, čeština) a zapsat rozhodnutí do ADR
- `Recording` (meeting, StoredFile, status, duration), `Transcript` + `TranscriptSegment` (speakerLabel, person nullable, start, end, text)
- asynchronní zpracování: tabulka `Job` + příkaz `jobs:work` (cron). Žádné dlouhé requesty.
- přiřazení „Řečník 1“ → osoba, AI souhrn k bodu, vložení do diskuse
**Hotovo když:** nahraný demo záznam projde frontou a souhrn jde vložit do diskuse bodu.

---

## Fáze 9 – Zveřejnění, MySQL, provoz

### [ ] T41 – Zveřejnění a GDPR
- veřejná varianta výpisů a zápisu s **anonymizací** (seznam jmen a údajů k začernění na úrovni zasedání + značky v textu)
- export pro úřední desku a web obce (PDF + volitelně JSON/RSS feed zveřejněných zasedání)
- export a výmaz dat obce (konec smlouvy)
**Hotovo když:** veřejný výpis neobsahuje označená jména.

### [ ] T42 – Přechod na MySQL/MariaDB
- služba `mariadb` v compose.yaml, konfigurace přepínatelná parametrem
- `MysqlFulltextSearchIndex`
- celá testovací sada poběží proti SQLite i MySQL (composer script `test:mysql`)
- příkaz `db:copy-sqlite-to-mysql` (přenos dat ve správném pořadí podle FK, kontrola počtů řádků)
**Hotovo když:** `composer qa` a `composer test:mysql` jsou zelené, demo data jsou přenesená.

### [ ] T43 – Nasazení a provoz
- cílová doména **konsel.matyho.cz** (portál zastupitelů na stejné doméně, odkazy v e-mailech z parametru `baseUrl`)
- produkční konfigurace, tajné údaje v env proměnných, `debugMode` vypnutý
- bezpečnost: HTTPS, HSTS, CSP, `SameSite`/`Secure` cookies, session timeout, kontrola uploadů (MIME podle obsahu)
- cron: `jobs:work`, `locks:cleanup`, zálohy (DB dump + `var/storage`, rotace, test obnovy)
- Tracy logování a e-mail při chybě
- produkční Docker image (multi-stage: composer install --no-dev + npm build → php:8.4-apache), compose.prod.yaml
- deploy skript (pull image → migrace → clear temp)
- doc/deploy.md
**Hotovo když:** aplikace běží na cílovém serveru a obnova ze zálohy je vyzkoušená.

---

## Fáze 10 – Úkolníček a občané (zadání vlastníka 2026-11-16)

### [x] T44 – Soubory v diskusi (drag & drop)
**Cíl:** K jakémukoli komentáři jde přiložit soubory přetažením myší.
- přílohy komentáře (stejná `AttachmentPolicy`, `FileStorage`, stahování přes ACL), drag & drop zóna ve formuláři diskuse + klasický výběr
- funguje v administraci, v portálu zastupitele i v úkolníčku
**Hotovo když:** soubor přetažený do diskuse se uloží ke komentáři a jde stáhnout jen oprávněným.

### [x] T45 – Úkolníček (dlouhodobé úkoly a záměry)
**Cíl:** Témata jako „Postavit školu“ v jednom vlákně přes více zasedání.
- `Project` (název, popis, fáze: námět → příprava → projekt → schváleno → realizace → hotovo / odloženo / zamítnuto, priorita, odpovědná osoba, odhad nákladů, veřejný ano/ne)
- diskuse a soubory u úkolu (sdílená diskuse z T19/T44)
- bod programu jde propojit s úkolem; stránka úkolu ukazuje **jedno vlákno**: komentáře, soubory a všechny body ze všech zasedání (s návrhy usnesení / usneseními) chronologicky
- úkolníček v administraci i v portálu zastupitele
**Hotovo když:** úkol propojený se dvěma body různých zasedání ukazuje oba v časové ose spolu s diskusí.

### [x] T46 – Veřejné podněty občanů
**Cíl:** Občané přidávají podněty a vidí, v jaké fázi jsou.
- veřejná stránka obce `/obec/<slug>/podnety`: seznam veřejných úkolů (fáze, priorita, veřejné komentáře), formulář podnětu (jméno, e-mail, text; honeypot + limit počtu odeslání; souhlas se zpracováním osobních údajů)
- podnět vznikne jako úkol ve fázi „nový podnět“, zveřejní se až po schválení úřadem
- u komentáře přepínač „viditelné pro občany“
**Hotovo když:** občan odešle podnět, úřad ho schválí, zastupitel okomentuje veřejně a občan vidí fázi, prioritu a komentář.

### [x] T47 – Rozcestník, barvy obce, formátované komentáře (zadání vlastníka 2026-11-17)
**Cíl:** Úvodní stránka jako rozcestník, vzhled v barvách obce a komentáře s formátováním.
- úvodní stránka „Zasedání zastupitelstva bez papírování“: rozcestník **Jsem občan** (zveřejněné úkoly obce, podnět) / **Jsem zastupitel** (přihlášení, osobní odkaz z pozvánky)
- Nastavení → Vzhled: hlavní a doplňková barva obce (hotové kombinace, živý náhled), platí v administraci, portálu i na stránce pro občany, světlý i tmavý režim
- všechny komentáře mají WYSIWYG editor (tučné, kurzíva, podtržení, přeškrtnutí, seznamy, citace, odkaz); HTML se na serveru pročistí
**Hotovo když:** občan z úvodní stránky dojde k úkolům obce, zastupitel se přihlásí, obec si nastaví barvy a komentář s formátováním se bezpečně zobrazí.

---

## MVP pro vlastní obec

**T00–T30 + T34** = použitelný systém pro zastupitelstvo i radu bez AI (program, sdílení, průběh, usnesení, Word/PDF).
Vše od T32 je rozšíření, pořadí se dá upravit podle potřeby.

---

## Deník

> Formát: `YYYY-MM-DD T## – co se odchýlilo / rozhodlo / zůstalo jako dluh`

- 2026-11-16 T00 – Místo lokální instalace je vše v Dockeru (A16): php:8.4-apache, www-data přemapovaný na UID/GID hostitele, wrapper Makefile. PDF → Gotenberg, mail → Mailpit (upraveno v T27/T20).
- 2026-11-16 T01 – Composer vybral nette/application 3.3 (plán počítal s 3.2). Debug režim jen přes env NETTE_DEBUG=1 (compose.yaml). Mapování `App\Presentation\*\**Presenter`, šablony vedle presenterů.
- 2026-11-16 T02 – ECS se sadou PER-CS + common + cleanCode, odsazení tabulátory (Nette konvence). PHPStan max + strict + deprecation rules, analyzuje i .phpt. Pravidlo `final` tříd zatím jen v CLAUDE.md, ne jako fixer (kvůli Doctrine proxy, rozhodne se v T03).
- 2026-11-16 T03 – nettrine/cache je opuštěný → metadata cache přes symfony/cache (PhpFilesAdapter). ORM s `lazyNativeObjects: true` (PHP 8.4), entity tedy mohou být `final`. Integrační testy: SQLite in-memory + migrace + SchemaValidator (nahrazuje orm:validate-schema v qa). Každé vlákno Testeru má vlastní temp/tests/N. Šablona migrací resources/migration.tpl.
- 2026-11-16 T04 – Clock = symfony/clock (NativeClock/MockClock) místo vlastních tříd. DBAL 4.6 deprekoval `$schema->createTable()/addColumn()` → vlastní `SchemaMigration` + `TableBuilder` (app/Infrastructure/Persistence/Migration), názvy indexů/FK generuje stejně jako ORM. Transakce přes `Application\Shared\TransactionManager`. Služby se registrují přes `search:` v services.neon (*Facade, *Repository, *Command). Integrační test TenantFilteru na reálné entitě doplnit v T10 (zatím jen unit test SQL podmínky).
- 2026-11-16 T05 – Tabulka uživatelů `app_user` ("user" je v některých DB rezervované). Pokusy o přihlášení v DB (`login_attempt`), limit 5/e-mail a 20/IP za 15 min, úspěch nuluje počítadlo e-mailu. Identita se obnovuje z DB při každém requestu (IdentityHandler). CSRF řeší Nette Forms 3.3 samo (`addProtection` je deprecated). Admin modul na /admin, přihlášení /prihlaseni. Superadmin bez obce zatím nemá výběr obce – doplnit v T10/T11. Testovací helper `Tests\Support\Fixtures`.
- 2026-11-16 T06 – Zdroje a privilegia jako enumy `Core\Security\Resource` / `Privilege` (+ `dashboard`, `audit`, `system` navíc proti plánu). `#[Allowed]` na třídě i metodě, test `AllowedCoverageTest` hlídá, že žádný vstupní bod Admin modulu nezůstane bez oprávnění.
- 2026-11-16 T07 – Node kontejner pouští `vite build --watch` (bez dev serveru/HMR) → ViteAssets vždy čte manifest, dev = prod. Bootstrap 5.3 (SCSS) + Bootstrap Icons + Naja + nette-forms. Menu (`AdminMenu`) ukazuje jen položky s oprávněním a existujícím presenterem. **contributte/datagrid odložen** – rozhodnout v T10 (alternativa: vlastní Latte tabulky + Paginator). Přepínač orgánu přesunut do T10/T34 (zatím nejsou orgány). Odkazy na presenter v šabloně komponenty: `$presenter->link()`.
- 2026-11-16 T08 – `FileStorage` v Application/Shared/Storage, `StoredFile` je Doctrine embeddable v Domain (validuje cestu regexem – žádné `..`). Úložiště `var/storage`, jmenný prostor volí volající (`m{id}/attachments`). `SecuredPresenter::sendStoredFile()` pro stahování.
- 2026-11-16 T09 – `StoredFile` místo embeddable jako vlastní DBAL typ `stored_file` (JSON, umí NULL). **Opraveny 3 systémové chyby:** (a) `SchemaAssetsFilter` skrýval `doctrine_migrations` i před migracemi → tabulku do ORM schématu přidává `MigrationsTableSchemaSubscriber`; (b) přestavba tabulky na SQLite smazala přes ON DELETE CASCADE podřízená data → `SchemaMigration` na SQLite vypíná FK a kontroluje `foreign_key_check` (regresní test); (c) `wrapInTransaction` zavíral EM při validační chybě → vlastní `DoctrineTransactionManager`. Doctrine metadata cache jen v produkci. `composer test` maže temp/tests (kontejner v testech se jinak nepřekompiluje). Nový `PresenterTester` + `MemoryUserStorage` pro testy presenterů.
- 2026-11-16 T10 – `Body` je agregát (členství jen přes `addMember/endMembership/removeMembership`), hlídá souběh členství, unikátní funkce (starosta), počet míst i období. Upozornění na složení (rada < 15 zastupitelů, sudý počet, neobsazená místa) vrací `PeopleFacade::getStructureWarnings()`. **Rozhodnutí: bez contributte/datagrid** – seznamy jsou prosté Latte tabulky (desítky záznamů); pro dlouhé seznamy (usnesení) přidat v T26 jednoduchý filtr + Paginator. Osoby mají vlastní presenter `Admin:Person`. Přidána druhá vrstva izolace obcí `SecuredPresenter::assertOwned()` (SQL filtr nepokrývá identity mapu). Přepínač orgánu v layoutu → T34. Mazání přes POST formulář + `#[Requires(methods: 'POST', sameOrigin: true)]`, potvrzení `data-confirm`.
- 2026-11-16 T11 – Správa uživatelů (`Admin:User`): dočasné heslo se zobrazí jednou (flash), ochrana posledního správce obce, nelze měnit vlastní roli ani se deaktivovat. `Admin:Account` (Můj účet) + vynucená změna dočasného hesla v `SecuredPresenter`. `DemoSeeder` (Console/Demo) + `app:seed-demo` (v produkci jen s --force), součást `composer db:reset`. Demo účty `spravce@ / editor@ / ctenar@ / superadmin@demo.konsel.test`, heslo `demo-heslo-123`. Test `DemoSeederTest` hlídá, že seeder projde na prázdné DB.
- 2026-11-16 T12 – `Meeting` se stavovým automatem v entitě (`MeetingTransition` má source/target, stav mění jen share/start/close/approveMinutes/reopen). Znovuotevření (Closed → InProgress) jen s `Privilege::Manage` (správce obce), zápis do auditu doplní T39. Navíc proti plánu: `correctTimes()` pro zpětné zadání skutečných časů (UI v T23), mazání jen zasedání v přípravě, předsedající a ověřovatelé musí být k datu zasedání členy orgánu, jediné ustavující zasedání na orgán a jen u zastupitelstva. Lhůta 7 dní (§ 93 odst. 1) jen pro zastupitelstvo a jen jako varování. Číslo zasedání = pořadí v orgánu (unikátní body + number), prázdné = další v pořadí. Dashboard: připravovaná (nedokončená) a poslední (proběhlá) zasedání. Smazání osoby, která je u zasedání v nějaké funkci, je zakázané. `PresenterTester` umí POST signály (vymění http.request).
- 2026-11-16 T12a – Na přání vlastníka přestavěná struktura (mimo původní plán, ADR A5/A12 upravené): `app/Model/<Doména>` (entity + fasády + rozhraní repozitářů + `Doctrine*Repository` + Command/), moduly `AdminModule` / `PublicModule` / `ApiModule` / `ErrorModule` přímo v `app/`, presenter a jeho šablony v jedné složce. Mapování `App\*Module\**Presenter`, layout `app/@layout.latte`, sdílené UI (BasePresenter, Form, Menu) v `app/UI`, migrační builder v `Core/Doctrine/Migration`. `Home` → `Public:Homepage`, `Sign` → `Public:Sign`. Nový endpoint `/api/health` (stav DB pro monitoring). Testy zrcadlí novou strukturu.
- 2026-11-16 T12b – Na přání vlastníka (mimo původní pořadí) AI přes OpenAI místo Claude API (ADR A17). `AiClient` + `OpenAiClient` (Chat Completions, ověření přes `GET /models/{model}` bez spotřeby tokenů, české chybové hlášky 401/404/429/5xx) nad symfony/http-client. Klíč per obec v `ai_settings`, šifrovaný `Core\Security\SecretBox` (libsodium secretbox, parametr `encryptionKey` v local.neon, příkaz `app:encryption-key`), v UI jen poslední 4 znaky. Nastavení → Umělá inteligence (jen správce): klíč, model (výchozí gpt-5-mini), zapnutí, test připojení, odebrání klíče. Testy s `FakeAiClient`, `MockHttpClient` – žádné volání ven. Demo data bez klíče (klíč nesmí být v repu). `TableBuilder::unique()` nahradí běžný index nad stejnými sloupci (jako DBAL). **Při nasazení:** vygenerovat `encryptionKey` do produkčního local.neon a zálohovat ho.
- 2026-11-16 T13 – `AgendaItem` je součást agregátu `Meeting` (add/update/remove/move/reorder hlídají pořadí 1..n a zámek programu po zahájení). Bez unikátního indexu (meeting, position) – přečíslování by kolidovalo uvnitř flush. Vlastní presenter `Admin:Agenda` (přidání, úprava, detail bodu – na detail navážou přílohy a komentáře). Program v detailu zasedání: přetažení myší (SortableJS → AJAX `reorder!`, překreslí snippet), šipky nahoru/dolů, „Vložit standardní body“ (`StandardAgenda`: zahájení s § 93, ověřovatelé a zapisovatel s doplněnými jmény, schválení programu, diskuse, závěr; duplicity přeskočí). Demo zasedání mají realistický program. **Opraveno:** `session.cookieSecure: auto` Nette bralo jako true (cookie jen přes HTTPS) → řízeno `http.cookieSecure` (výchozí auto).
- 2026-11-16 T16 (část) – `Model/Attachment`: `Attachment` k bodu programu (StoredFile, kdo nahrál, `publicForMembers` pro portál T18), `AttachmentPolicy` (whitelist přípon – žádné skripty/HTML/SVG/spustitelné, max. velikost parametr `attachmentMaxSize` = 64 MB jako php.ini). Nahrání více souborů najednou (MultiUpload), soubory se ukládají před transakcí a při chybě se uklidí; smazání přílohy, bodu i návrhu zasedání maže soubory až po commitu. Po ověření zápisu jsou přílohy zamčené. `Admin:Attachment` download/preview (náhled jen PDF a rastrové obrázky, `nosniff` + sandbox CSP). Počty příloh v programu. **Dluh (T16b):** nahrávání po částech s progress barem pro soubory > 64 MB (kritérium „> 500 MB bez úpravy upload_max_filesize“ zatím nesplněno).
- 2026-11-16 T19 (část, mimo pořadí na přání vlastníka) – `Model/Comment`: komentář k bodu s autorem uživatel úřadu **nebo** zastupitel (`Person`, pro portál T18), jméno autora jako snapshot, vlákna o jedné úrovni (komentář → odpovědi), měkké mazání (smazaný komentář s odpověďmi zůstane jako „Komentář byl smazán“). Mazat smí autor, cizí komentáře správce (`Agenda` + `Manage`). Komentovat smí každý uživatel s náhledem na bod (i čtenář). Po ověření zápisu je diskuse zavřená. Sdílená šablona `app/UI/Comment/@discussion.latte` (použije i portál), AJAX formulář a mazání (snippet), odpověď přes `data-reply-to`. Počty komentářů v programu. Zbývá: komentáře zastupitelů v portálu (T17/T18) a e-mailová upozornění.
- 2026-11-16 T27/T28 (mimo pořadí na přání vlastníka) – PDF přes **mPDF** místo PhpWord + Gotenberg (ADR A11 upravené). `Model/Document`: `PdfRenderer` → `MpdfRenderer` (temp/mpdf), `MeetingDocumentFacade` renderuje Latte šablony `resources/documents/*.latte` (společný `@layout.latte` s hlavičkou obce a logem). Hotové: **pozvánka / informace o zasedání** (§ 93 odst. 1, datum dlouze česky, místo, navržený program, podpis starosty k datu zasedání, řádek vyvěšeno/sejmuto; u rady neveřejná pozvánka) a **podklady k zasedání** (důvodové zprávy, návrhy usnesení, veřejné přílohy). Záložka Dokumenty v detailu zasedání: náhled a stažení. Dokumenty se zatím negenerují do úložiště (GeneratedDocument jen v paměti) – ukládání a verze v T27. Filtry `czDateLong`, `genitiveBody`.
- 2026-11-16 T17/T18 (mimo pořadí na přání vlastníka) – `Model/Access`: `MeetingAccessToken` (osoba × zasedání, v DB jen SHA-256, 43 znaků base64url = 256 bitů, platnost do zasedání + 60 dní, odvolání, počítadlo použití). Nový odkaz odvolá předchozí. `AccessFacade::issueForMembers/issueFor/revoke/authenticate/getValid`, vyhledání tokenu bez TenantFilteru (obec se zjistí až z tokenu). `PortalModule`: vstup `/odkaz/<token>` → session s ID odkazu (regenerace ID session, `Referrer-Policy: no-referrer`) → přesměrování na `/portal` (token nezůstává v URL); každý požadavek znovu ověří platnost a nastaví obec. Portál: přehled zasedání s programem a PDF, detail bodu (důvodová zpráva, návrh usnesení, **jen veřejné přílohy**, diskuse – komentuje jako `Person`, maže jen své). Admin `Meeting:links`: vytvoření odkazů pro členy / jednotlivce, odkaz se ukáže **jen jednou** (kopírování, `mailto:` s předvyplněným textem – do T20), přehled s odvoláním. Demo seeder vypíše odkaz pro Petra Novotného.
- 2026-11-16 T14/T15 – `Model/Template`: `ResolutionTemplate` (per obec, bez globálních šablon – TenantFilter by je skryl; výchozí knihovna 19 šablon se doplní tlačítkem / v demu), proměnné `{organ} {obec} {cislo_zasedani} {datum_zasedani} {rok}` (`TemplateVariables`). Vložení do bodu přes modal s hledáním (doplní název a návrh usnesení). `MeetingTemplate` (body v JSON), výchozí: řádné zasedání, ustavující bez rady, ustavující s radou – **texty ustavujících šablon musí zkontrolovat člověk proti aktuální metodice MV** (`// TODO ověřit proti metodice MV`, poznámka u šablony). Program ze šablony a „Uložit program jako šablonu“ v detailu zasedání. Menu Šablony. Úprava položek šablony programu v UI zatím jen přes uložení z programu.
- 2026-11-16 Plán – na přání vlastníka přidána Fáze 10: T44 soubory v diskusi (drag & drop), T45 úkolníček (projekty v jednom vlákně přes zasedání), T46 veřejné podněty občanů.
- 2026-11-16 T44 – `Attachment` patří k bodu **nebo** ke komentáři (`agenda_item_id` nepovinné, nový `comment_id`, továrny `forAgendaItem` / `forComment`, `getMeeting()` pro kontrolu přístupu). Komentář může nést soubory (pak není povinný text), stejná `AttachmentPolicy`; smazání komentáře, bodu i zasedání uklidí i soubory z komentářů (`findAllForAgendaItem/Meeting`). Sdílená diskuse má drop zónu (přetažení, „Přiložit soubor“, vložení ze schránky, seznam vybraných souborů s odebráním) – funguje v administraci i portálu, stejná zóna u příloh bodu. `TableBuilder::foreignKeyFor()` pro přidání FK v `alterTable`. Převod souborů z formuláře `App\UI\Form\FormUploads` (model nezávisí na Nette). Ověřeno i headless prohlížečem (`docker/screenshots/dropzone-check.cjs`).
- 2026-11-16 T45 – `Model/Project`: `Project` (fáze `ProjectStatus` vč. „nový podnět“ pro T46, priorita, odpovědná osoba, odhad nákladů, `public`), historie fází `ProjectStatusChange`. `Comment` a `Attachment` patří k bodu **nebo** k úkolu (`Comment::onAgendaItem/onProject`, `Attachment::forProject`), komentář k úkolu má `publicForCitizens` (nový NOT NULL sloupec ve 2 migracích). `AgendaItem::linkProject()` – propojení jde měnit i u proběhlého zasedání (není to obsah programu). `ProjectFacade::timeline()` skládá **jedno vlákno**: vznik, změny fáze, body všech zasedání (s návrhy usnesení a stavem zasedání), soubory a diskuse; `publicOnly` pro pohled občana. Nový ACL zdroj `project` (čtenář V, editor VE, správce vše). Admin `Úkolníček`: karty s filtrem fází, detail s ukazatelem postupu, vlákno s komentáři (AJAX, soubory, přepínač „zveřejnit občanům“), změna fáze s poznámkou, propojení bodu, nahrání souborů. Bod programu: výběr úkolu ve formuláři, štítek v detailu a programu. Portál zastupitele: menu Úkolníček (nevidí neposouzené podněty), komentuje jako zastupitel. Sdílená diskuse rozdělena na bloky `comment` a `commentForm` (parametry bloku předává presenter – lokální `{var}` blok nevidí). Demo: MŠ propojená se 2 zasedáními, oprava komunikace, LED osvětlení, podnět občana.
- 2026-11-16 T46 – Veřejná stránka `Public:Ideas` na `/obec/<slug>/podnety` (bez přihlášení, nastaví obec pro TenantFilter): zveřejněné úkoly s fází, prioritou a ukazatelem postupu, detail s veřejným vláknem (`timeline(publicOnly)`: fáze s poznámkou, projednání na zasedáních s návrhem usnesení, jen komentáře „vidí občané“; jména úředníků se nahrazují „Obecní úřad“, zastupitel je uveden jménem), soubory jen ze zveřejněných komentářů. Formulář podnětu: jméno, e-mail (jen pro úřad), návrh, popis, souhlas se zpracováním, past na roboty (skryté pole), limit `ProjectFacade::ProposalsPerHour` = 3 za hodinu z IP (`project.submitter_ip`). Podnět vznikne jako `Project::citizenProposal()` ve fázi „nový podnět“, zveřejnit ho lze až po posouzení. V administraci kontakt na občana a odkaz „Stránka pro občany“. **Dluh:** e-mailové upozornění úřadu na nový podnět (T20), mazání IP po čase (T41 GDPR), komentáře občanů. Občas „database is locked“ při `make db-reset` za běhu webu (SQLite WAL) – prověřit.
- 2026-11-17 T47 – **Rozcestník:** `Public:Homepage` (dědí `BasePresenter`) – „Jsem občan“ ukáže až 4 zveřejněné úkoly jediné obce (při více obcích výběr obce), „Jsem zastupitel“ přihlášení přímo na stránce + vysvětlení osobního odkazu, při otevřeném odkazu tlačítko do podkladů. Přihlašovací formulář vytažen do `App\UI\Sign\SignInFormFactory` (sdílí `/prihlaseni` i úvod). **Barvy obce:** `Municipality.primary_color/accent_color` (null = výchozí), VO `HexColor` (Shared) a `BrandColors` (hlavní barva musí mít kontrast ≥ 3 vůči bílé, jinak `ValidationException` na `primaryColor`). `App\UI\Theme\BrandStyle` z barev počítá CSS proměnné (Bootstrap tint/shade, barva textu na tlačítku podle kontrastu, tmavý režim), Latte funkce `brandStyle()` je vloží do layoutu podle `CurrentMunicipality`; komponenty se zkompilovanou barvou (btn, form-check, focus, nav-pills, progress, dropdown, list-group, pagination, logo) jsou v `main.scss` napojené na proměnné. Záložka Nastavení → Vzhled: `addColor`, 8 hotových kombinací, živý náhled (`assets/themePreview.js`), „Vrátit výchozí barvy“. Doplňková barva = proužek v horní liště, linka ve znaku, akcenty. **Formátované komentáře:** editor TipTap 3.32.2 (`assets/richEditor.js` + líně načítané `richEditorCore.js`, hlavní bundle zůstal ~155 kB) nad `<textarea data-rich-editor>` – formulář i AJAX beze změny, Ctrl+Enter odešle, soubory ze schránky bez `text/html` jdou do příloh (kopie z Wordu se vloží jako text). Na serveru `Model/Shared/RichText`: VO `RichText`, rozhraní `HtmlSanitizer` + `SymfonyHtmlSanitizer` (symfony/html-sanitizer; povolené p, br, strong/b, em/i, u, s/del, ul/ol/li, blockquote, code, a[href http/https/mailto] s vynuceným `rel`/`target`; obalové značky z Wordu jen odstraní, ostatní zahodí i s obsahem; vstup bez značek = prostý text). `Comment` ukládá pročištěné HTML, limit 5000 znaků textu + 50 000 znaků HTML; výpis filtrem `|richText` (pročistí znovu). Migrace převede dosavadní komentáře z prostého textu na odstavce. Ověřeno `docker/screenshots/editor-check.cjs` (formátování, Ctrl+Enter, příloha ze schránky, barvy, návrat). Nová závislost přitáhla polyfill `str_starts_with` → v `AiFacade` `strncmp`.
- 2026-11-17 T20 – `Model/Mail`: rozhraní `Mailer` + `NetteMailer` (nette/mail SMTP; parametry `smtp`, `mailFrom`, `appUrl` v common.neon, výchozí Mailpit), služba `mailpit` v compose (UI http://localhost:8025), v testech `Tests\Support\FakeMailer`. `MailLog` (typ, zasedání, osoba, příjemce, předmět, stav, chyba). `InvitationFacade::send()` jen u sdíleného zasedání: vydá nové osobní odkazy (AccessFacade), přiloží PDF pozvánky (vyrenderované jednou), pošle e-mail z `resources/mail/invitation.latte` (termín, místo, program, tlačítko do portálu v barvě obce, odesílatel = obec, odpověď na e-mail obce) a vrátí odeslané / bez e-mailu / selhané. Administrace: stránka „Pozvánky a odkazy“ – „Sdílet a rozeslat pozvánky“ (zasedání v přípravě se zároveň sdílí), znovu poslat jednomu členovi, neodeslané odkazy se ukážou hned (v DB je jen hash), přehled odeslaných e-mailů s chybami. Upozornění úřadu na nový podnět občana (`NotificationFacade`, na e-mail obce, odpověď jde občanovi; selhání jen zaznamená). Demo: sdílené zasedání rozešle 9 pozvánek do Mailpitu. Barvy v inline stylech e-mailů se vypisují `|noescape` (ověřený `HexColor`) – Latte v CSS kontextu escapuje `#`. **Odchylka:** samostatné tlačítko „Sdílet“ v detailu zůstává (sdílení bez e-mailů).
- 2026-11-17 T21 – `Attendance` je samostatná entita (meeting × person, unikátní), ne kolekce v `Meeting` (agregát je už velký); zapisovat lze od sdílení do ověření zápisu (`Attendance::canRecord`), jen pro členy orgánu k datu zasedání. Přítomnost zapsaná po zahájení = pozdní příchod (`arrivedAt`), „Odešel / Vrátil se“ (`leftAt`); `isPresent($at)` poslouží hlasování (T22). Řádky vznikají až zápisem – přehled `AttendanceOverview` doplní nezapsané členy (`AttendanceEntry`). `Quorum` VO: nadpoloviční většina **počtu míst orgánu** (`seatCount`), ne jen obsazených (§ 92). Nová obrazovka `Admin:Proceedings` (Průběh): ukazatel usnášeníschopnosti + docházka přes AJAX snippety, „Ostatní přítomni“, zahájení/ukončení; detail zasedání má v záložce Průběh souhrn a odkaz. Demo: proběhlé zasedání s 1 omluveným. Kontrola v prohlížeči `docker/screenshots/proceedings-check.cjs`. **Dluh:** opakované odchody/příchody jednoho člena se nepamatují (jen poslední).
- 2026-11-17 T22 – `VotingRound` (agregát s `Vote`): typ (návrh, protinávrh, pozměňovací návrh, volba osoby), znění / kandidát, `openedAt` = okamžik hlasování – hlasují jen přítomní v tu chvíli (`Attendance::isPresent($at)`, pozdě příchozí až v dalším hlasování). Zahájit lze jen u probíhajícího usnášeníschopného zasedání, u bodu jen jedno probíhající hlasování; po přijetí návrhu/protinávrhu se o dalším návrhu nehlasuje (pozměňovací návrhy a volby nejsou blokované). Ukončení doplní „nehlasoval“, uloží součty a výsledek z `VotingRule` (pro ≥ ⌊členů/2⌋+1 z **počtu míst orgánu**, stejně pro radu a výbory). Opravit (znovu otevřít) jde do ověření zápisu, smazat jen probíhající. Obrazovka Průběh: body s návrhem usnesení, „Hlasovat o návrhu usnesení“ (znění z bodu), „Jiné hlasování…“ (typ, kandidát, znění), matice přítomných s Pro/Proti/Zdržel se (opětovné kliknutí hlas zruší), „Všichni přítomní pro“, živý souhrn, jmenovitý výpis u ukončených. Demo: proběhlá zasedání mají odhlasované všechny návrhy (u smlouvy 1 proti, 1 zdržel). **Dluh:** časy hlasování v demu jsou „dnes“ (doména neumožňuje zpětné časy, `correctTimes` mění jen zasedání).
- 2026-11-17 T23 – `AgendaItem.discussion` (prostý text, ADR A10) a `addedDuringMeeting` (NOT NULL ve 2 migracích), `Meeting.guestCount` + `minutesNote`. Zapisovat lze během zasedání a opravovat po ukončení do ověření zápisu (`Meeting::canRecordProceedings`). Program je po zahájení zamčený; `Meeting::addAgendaItemDuringMeeting()` doplní bod s příznakem (o doplnění se hlasuje při schválení programu – samostatná vazba hlasování na doplnění není, **odchylka** od „vazba na hlasování“). Obrazovka Průběh: hlasování každého bodu je vlastní dynamický snippet (`snippetArea agenda` + `voting-{id}`), diskuse se ukládá sama při opuštění pole (`form[data-autosave]`, `naja.makeRequest` s `unique: false`, bez překreslení – rozepsaný text nezmizí), karta Údaje do zápisu (skutečné časy přes `correctTimes`, hosté, poznámka, funkce), „Doplnit bod do programu“. **Oprava napříč aplikací:** AJAXový POST nechával v historii adresu signálu (obnovení stránky → chyba), `BasePresenter` teď posílá `postGet` + `url`. Demo: diskuse u dvou bodů a 6 hostů. Ověřeno `proceedings-check.cjs` (docházka → zahájení → hlasování → diskuse → obnovení stránky).

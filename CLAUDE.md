# Konšel

Systém pro přípravu a vedení zasedání zastupitelstva a rady obce (program → podklady → pozvánka → průběh → zápis → usnesení → archiv).
Nette 3.3 + Doctrine ORM 3, SQLite (později MySQL). Produkce: konsel.matyho.cz (stack v Portaineru z `compose.prod.yaml`, image `docker/prod/`, nastavení přes proměnné `KONSEL_*` – návod doc/wiki/10-nasazeni-a-provoz.md).

- **Plán a stav práce:** doc/todo-v2.md. Tasky se dělají po jednom. Postup je v sekci „Jak s tímhle plánem pracovat“.
- **Architektonická rozhodnutí (ADR A1–A15) a cílová struktura:** tamtéž. Neodchyluj se od nich bez zápisu do Deníku a dotazu.
- **Doménové požadavky:** doc/todo-v1.md.
- **Wiki (pro vlastníka i nové vývojáře):** doc/wiki/*.md, v aplikaci menu Nápověda (`Admin:Help`, diagramy Mermaid). Nová funkce nebo změna toku aktualizuje i příslušnou kapitolu (moduly, životní cykly, plán). Kontrola diagramů: `docker/screenshots/wiki-check.cjs`.

## Příkazy

**Vše běží v Dockeru. Na hostiteli (Mac) nic neinstaluj: žádný brew, lokální PHP ani npm.** Chybějící nástroj patří do docker/ nebo compose.yaml.

```
make up / down / build    # kontejnery (app = PHP 8.4 + Apache na http://localhost:8080, Mailpit na http://localhost:8025)
make sh                   # shell v app kontejneru
make composer ARGS="…"    # composer v kontejneru
make console ARGS="…"     # bin/console (migrations:*, orm:*, app:seed-demo, user:create, …)
make qa                   # cs + phpstan (max) + testy + validace schématu – musí projít před koncem tasku
make test                 # Nette Tester
make cs-fix
make db-reset             # smazat DB → migrace → demo data
make backup               # záloha databáze a souborů do var/backups (Nastavení → Zálohy)
make screenshots          # screenshoty stránek (desktop + mobil) do temp/screenshots – kontrola vzhledu, PAGES="/admin,…"
```

## Pravidla kódu

- `declare(strict_types=1)`, třídy `final` (výjimky jen u Doctrine proxy, kde je to nutné), `readonly`, kde to jde. Nativní typy všude.
- **Struktura** (podrobně v doc/todo-v2.md, sekce Cílová struktura):
  ```
  app/Model/<Doména>/      entity, enumy, VO, rozhraní repozitáře + Doctrine*Repository, *Facade, Command/
  app/Model/Shared/        výjimky, IntegerId, TenantOwned, TransactionManager
  app/AdminModule/<Presenter>/   XxxPresenter.php + šablony *.latte + XxxFormData.php (vše v jedné složce)
  app/PublicModule/, app/ApiModule/, app/ErrorModule/   stejný princip
  app/UI/                  BasePresenter, Form/ (FormFactory, renderer), Menu/; layout je app/@layout.latte
  app/Core/                routing, ACL, tenancy, Doctrine (typy, migrace), Latte
  ```
  - Presentery a komponenty **nikdy** nepoužívají `EntityManager` ani repozitáře přímo, jen fasády (`App\Model\*\*Facade`).
  - Entity a doménová pravidla nezávisí na Nette (Doctrine mapovací atributy jsou jediná povolená výjimka). Rozhraní repozitáře je vedle entity, implementace `Doctrine*Repository` ve stejné složce.
  - Externí služby (mail, storage, AI, ARES, PDF, STT, search) jsou rozhraní v `Model/<Doména>` a implementace vedle něj s prefixem technologie (`LocalFileStorage`, `OpenAi…`).
  - Presenter `Admin:Meeting` = třída `App\AdminModule\Meeting\MeetingPresenter` (mapování `App\*Module\**Presenter`), odkazy mezi moduly `:Public:Sign:in`.
- **Entity mají chování, ne settery:** `$meeting->start($clock)`, ne `$meeting->setStatus(...)`. Invarianty hlídá entita a porušení vyhodí doménovou výjimku (`App\Model\Shared\Exception\*`).
- Stavy a číselníky = PHP backed enumy s metodou `label(): string` pro české UI.
- Jedna metoda fasády = jedna transakce přes `TransactionManager::run()` (flush + commit). Nepoužívej `EntityManager::wrapInTransaction()` – při doménové chybě zavře EntityManager. Vstupy jsou readonly Command DTO, nahrané soubory jako `UploadedFile` (ne Nette FileUpload).
- Validuj před změnou stavu: doménové metody vyhodí výjimku dřív, než změní pole.
- Čas jen přes `Psr\Clock\ClockInterface`, vždy `DateTimeImmutable`. Nikdy `new DateTime()` ani `time()` v doméně.
- Entity patřící obci implementují `TenantOwned` (asociace `municipality`). Izolaci hlídá `TenantFilter` a navíc v presenterech `assertOwned($entita)` u každé entity načtené podle ID z URL (filtr se neuplatní na entity už načtené v identity mapě).
- Migrace dědí z `SchemaMigration` a používají `createTable('x', fn (TableBuilder $t) => …)` / `alterTable()` / `dropTable()`. Žádné raw SQL ani deprekované `$schema->createTable()`. `make console ARGS="migrations:diff"` slouží jen jako předloha. Test `DatabaseSchemaTest` ověří, že migrace = mapping.
- Nové služby se registrují samy podle konvence (`search:` v config/services.neon): `*Facade` a `Doctrine*Repository` v Model, `*Command` v Console.
- Soubory se ukládají jen přes `FileStorage` a servírují přes presenter s kontrolou ACL. Nic uživatelského ve `www/`.
- Každá `action*`/`render*`/`handle*` v AdminModule má `#[Allowed(resource, privilege)]` (hlídá `AllowedCoverageTest`). Admin presentery dědí z `App\AdminModule\SecuredPresenter`.
- Formuláře přes `FormFactory`. Zpracování formuláře volá fasádu a doménové výjimky převádí na chyby formuláře.
- Testy: doménová pravidla = unit testy (povinné), fasády = integrační testy nad SQLite, presentery přes `Tests\Support\PresenterTester` (loginAs, render, submitForm). Data přes `Tests\Support\Fixtures`. Nová funkce rozšíří `DemoSeeder`.
- Migrace s daty: nový NOT NULL sloupec = (1) přidat s výchozí hodnotou + UPDATE, (2) v další migraci výchozí hodnotu odebrat. `alterTable()` nekombinuj s jinou změnou téže tabulky v jedné migraci.
- Doménové `ValidationException` nesou `field` = název pole formuláře. V presenteru je převede `FormErrors::apply()`.
- UI texty česky, vykání. Kód, DB a komentáře anglicky.
- Vzhled: Bootstrap 5.3 + téma v assets/main.scss (písmo Inter a ikony jsou v buildu, žádné CDN). Stav zasedání přes `@status.latte`, datum přes `@dateTile.latte`, prázdné stavy třídou `empty-state`. Musí fungovat i tmavý režim (barvy přes `var(--bs-…)`). Po změně vzhledu zkontroluj `make screenshots`.
- Barvy obce (Nastavení → Vzhled) přebíjejí téma za běhu: hlavní barvu nikdy natvrdo (`$primary`, `#1b5e8c`), vždy `var(--bs-primary)` / `var(--bs-primary-rgb)`, text na ní `var(--konsel-on-primary)`, doplňková `var(--konsel-accent)`. Nová komponenta Bootstrapu se zkompilovanou barvou se napojí v sekci „Barvy obce“ v main.scss.
- Po ověření zápisu (`Meeting::isArchived()`) je zasedání neměnné. Nová změna zasedání, bodu, přílohy, komentáře u bodu, docházky nebo hlasování to musí hlídat v entitě a přibýt do `ApprovedMeetingImmutabilityTest`. Znovuotevření jen `Meeting::reopen()` s důvodem.
- Volitelné moduly (Nastavení → Moduly): presenter modulu má `#[RequiresModule(Module::…)]`, položka menu `module:`, šablony `moduleEnabled(Module::…)`, portál a veřejná část `requireModule()`. Nová funkce patřící k modulu se musí při vypnutém modulu schovat všude.
- Šablony dokumentů (výchozí i vlastní šablony obce) se vykreslují v sandboxu Latte (`DocumentTemplateEngine`, `DocumentSandboxPolicy`): nový objekt v dokumentu přidat do `DocumentSandboxPolicy::Classes`, v šablonách žádné `array_map`/`array_filter` (filtry `|where`, `|names`, `|period`). `DocumentTemplateTest` musí projít.
- AI (OpenAI za `AiClient`): prompty jsou v `resources/prompts/*.md`, kontext skládá `AgendaWritingFacade` (pokyny a vzory obce, poslední usnesení). Návrh se nikdy neuloží sám, jen vloží do formuláře. Osobní údaje neposílat. Každé volání se zapíše do `AiUsageLog`. V testech `FakeAiClient` (`lastMessages`).
- Historie změn (audit) zapisuje `DoctrineAuditSubscriber` sám; nová sledovaná entita = `AuditDescriber::type()/label()`. Citlivá pole (heslo, token, klíč) se nezapisují. Autor z `AuditContext`.
- Fulltext: index `search_index` (SQLite FTS5) udržuje `DoctrineSearchIndexSubscriber` sám při flushi. Nová hledaná entita = doplnit `Model/Search/SearchDocumentFactory`. Po změně indexace `search:reindex`.
- Server MCP pro AI asistenty (`/mcp`, wiki 12): nástroj = `app/ApiModule/Mcp/Tool/*Tool.php` (`McpTool`), v `ToolDefinition` zdroj/privilegium ACL, modul a `writes`; jen fasády, entity podle ID přes `McpContext::owned()`, chyby pro AI `ToolException`. AI jedná za uživatele (role, obec, rozsah tokenu), audit „Jméno (AI: …)“. Hlasování, docházka, stavy zasedání, pozvánky, mazání a nastavení přes MCP nikdy. Tokeny jen jako hash (`Model/AiAccess`).
- Formátovaný text od uživatele (komentáře) = `RichText`: fasáda ho pročistí přes `HtmlSanitizer`, šablona vypisuje `|richText`, nikdy `|noescape`. Pole formuláře dostane `data-rich-editor`.

## Slovníček (UI ↔ kód)

| Česky | Kód |
|---|---|
| Obec | `Municipality` |
| Orgán (zastupitelstvo / rada / výbor) | `Body` (`BodyType::Council` / `Board` / `Committee`) |
| Volební období | `Term` |
| Osoba, zastupitel, radní | `Person` (+ `Membership` v `Body`) |
| Uživatel úřadu | `User` |
| Zasedání / schůze | `Meeting` |
| Bod programu | `AgendaItem` |
| Podbod (26.1, hlasuje se zvlášť) | `AgendaItem::$parent`, číslo `getNumber()` (ne `getPosition()`) |
| Předkladatel | `rapporteur` |
| Návrh usnesení | `proposedResolution` |
| Příloha | `Attachment` |
| Připomínka zastupitele | `Comment` |
| Odkaz pro zastupitele | `MeetingAccessToken` |
| Docházka | `Attendance` |
| Hlasování / hlas | `VotingRound` / `Vote` |
| Diskuse | `discussion` |
| Zapisovatel / ověřovatel / předsedající | `recorder` / `verifier` / `chairperson` |
| Usnesení | `Resolution` |
| Maska číslování | `NumberingMask` |
| Úkol z usnesení | `ResolutionTask` |
| Plán (dříve úkolníček), podnět občana | `Project` |
| Volitelný modul obce | `Module` (`ResolutionTasks`, `Plans`, `AiConnections`) |
| Připojení AI (osobní token / připojená aplikace) | `AiAccessToken` (`AccessTokenKind`, `AccessScope`), `OAuthClient` |
| Zápis | `Minutes` |
| Pozvánka | `Invitation` |
| Výpis z usnesení | `ResolutionExtract` |
| Prezenční listina | `AttendanceSheet` |
| Šablona zasedání / usnesení / dokumentu | `MeetingTemplate` / `ResolutionTemplate` / `DocumentTemplate` |
| Ustavující zasedání | `MeetingType::Constituent` |

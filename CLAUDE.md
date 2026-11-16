# Konšel

Systém pro přípravu a vedení zasedání zastupitelstva a rady obce (program → podklady → pozvánka → průběh → zápis → usnesení → archiv).
Nette 3.2 + Doctrine ORM 3, SQLite (později MySQL). Produkce: konsel.matyho.cz.

- **Plán a stav práce:** doc/todo-v2.md. Tasky se dělají po jednom. Postup je v sekci „Jak s tímhle plánem pracovat“.
- **Architektonická rozhodnutí (ADR A1–A15) a cílová struktura:** tamtéž. Neodchyluj se od nich bez zápisu do Deníku a dotazu.
- **Doménové požadavky:** doc/todo-v1.md.

## Příkazy

**Vše běží v Dockeru. Na hostiteli (Mac) nic neinstaluj: žádný brew, lokální PHP ani npm.** Chybějící nástroj patří do docker/ nebo compose.yaml.

```
make up / down / build    # kontejnery (app = PHP 8.4 + Apache na http://localhost:8080)
make sh                   # shell v app kontejneru
make composer ARGS="…"    # composer v kontejneru
make console ARGS="…"     # bin/console (migrations:*, orm:*, app:seed-demo, user:create, …)
make qa                   # cs + phpstan (max) + testy + validace schématu – musí projít před koncem tasku
make test                 # Nette Tester
make cs-fix
make db-reset             # smazat DB → migrace → demo data
```

## Pravidla kódu

- `declare(strict_types=1)`, třídy `final` (výjimky jen u Doctrine proxy, kde je to nutné), `readonly`, kde to jde. Nativní typy všude.
- **Vrstvy:** `Presentation → Application (fasády) → Domain ← Infrastructure`.
  - Presentery a komponenty **nikdy** nepoužívají `EntityManager` ani repozitáře přímo, jen fasády z `App\Application`.
  - `Domain` nezávisí na Nette ani na Infrastructure (Doctrine mapovací atributy jsou jediná povolená výjimka).
  - Externí služby (mail, storage, AI, ARES, PDF, STT, search) jsou rozhraní v Domain/Application, implementace v Infrastructure.
- **Entity mají chování, ne settery:** `$meeting->start($clock)`, ne `$meeting->setStatus(...)`. Invarianty hlídá entita a porušení vyhodí doménovou výjimku (`App\Domain\Shared\Exception\*`).
- Stavy a číselníky = PHP backed enumy s metodou `label(): string` pro české UI.
- Jedna metoda fasády = jedna transakce (`wrapInTransaction` / flush na konci). Vstupy jsou readonly Command DTO.
- Čas jen přes `Psr\Clock\ClockInterface`, vždy `DateTimeImmutable`. Nikdy `new DateTime()` ani `time()` v doméně.
- Entity patřící obci implementují `TenantOwned`. Izolaci hlídá `TenantFilter`, nespoléhej na ruční `WHERE`.
- Migrace přes Doctrine Schema API (platformově nezávislé). `migrations:diff` jen jako předloha.
- Soubory se ukládají jen přes `FileStorage` a servírují přes presenter s kontrolou ACL. Nic uživatelského ve `www/`.
- Každá `action*`/`handle*` v Admin modulu má `#[Allowed(resource, privilege)]`.
- Formuláře přes `FormFactory`. Zpracování formuláře volá fasádu a doménové výjimky převádí na chyby formuláře.
- Testy: doménová pravidla = unit testy (povinné), fasády = integrační testy nad SQLite. Nová funkce rozšíří `DemoSeeder`.
- UI texty česky, vykání. Kód, DB a komentáře anglicky.

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
| Zápis | `Minutes` |
| Pozvánka | `Invitation` |
| Výpis z usnesení | `ResolutionExtract` |
| Prezenční listina | `AttendanceSheet` |
| Šablona zasedání / usnesení / dokumentu | `MeetingTemplate` / `ResolutionTemplate` / `DocumentTemplate` |
| Ustavující zasedání | `MeetingType::Constituent` |

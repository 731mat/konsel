# Životní cykly

Každá důležitá věc v Konšelu má **stavy** a jasná pravidla, kdy se smí přejít do dalšího. Pravidla hlídá přímo entita v `app/Model` – nejde je obejít ani z jiné obrazovky.

## Zasedání (`Meeting`, `MeetingStatus`)

```mermaid
stateDiagram-v2
    [*] --> Draft: Nové zasedání
    Draft --> Shared: Sdílet / rozeslat pozvánky
    Shared --> InProgress: Zahájit zasedání
    InProgress --> Closed: Ukončit zasedání
    Closed --> MinutesApproved: Zápis je ověřen
    Closed --> InProgress: Znovu otevřít (jen správce)
    MinutesApproved --> [*]

    Draft: V přípravě
    Shared: Sdíleno
    InProgress: Probíhá
    Closed: Ukončeno
    MinutesApproved: Zápis ověřen
```

| Stav | Program | Přílohy, diskuse | Docházka | Hlasování | Diskuse do zápisu | Dokumenty |
|---|---|---|---|---|---|---|
| V přípravě | měnit | ano | – | – | – | pozvánka, podklady, prezenční listina |
| Sdíleno | měnit | ano | zapisovat | – | – | + pozvánky e-mailem |
| Probíhá | jen „doplnit bod“ | ano | zapisovat | **hlasovat** | zapisovat | + zápis a usnesení (koncept) |
| Ukončeno | – | ano | opravovat | opravovat | opravovat | zápis, usnesení, výpisy; běží lhůta 10 dnů |
| Zápis ověřen | – | – | – | – | – | vše jen ke čtení |

Smazat jde jen zasedání v přípravě. Sdílení zasedání méně než 7 dní předem zobrazí varování (§ 93 – vyvěšení na úřední desce).

## Hlasování (`VotingRound`)

```mermaid
stateDiagram-v2
    [*] --> Open: Hlasovat o návrhu<br/>(jen probíhající, usnášeníschopné zasedání)
    Open --> Open: hlasy Pro / Proti / Zdržel se,<br/>Všichni přítomní pro
    Open --> [*]: Zrušit hlasování (založené omylem)
    Open --> Closed: Ukončit hlasování
    Closed --> Open: Opravit hlasování<br/>(do ověření zápisu)

    state Closed {
        [*] --> Adopted: pro ≥ ⌊členů/2⌋ + 1
        [*] --> Rejected: jinak
    }
```

- Hlasují jen členové **přítomní v okamžiku zahájení** hlasování (podle docházky).
- Výsledek počítá `VotingRule`: přijato, když hlasů pro je víc než polovina **všech** členů orgánu (§ 87), ne jen přítomných. Zdržel se = fakticky ne.
- Po přijetí návrhu nebo protinávrhu se o dalším návrhu k bodu nehlasuje.

## Usnesení (`Resolution`)

```mermaid
stateDiagram-v2
    [*] --> Adopted: přijaté hlasování<br/>(návrh, protinávrh, volba)<br/>→ číslo podle masky
    Adopted --> Adopted: oprava hlasování – znovu přijato<br/>(číslo zůstává)
    Adopted --> [*]: oprava hlasování – nepřijato<br/>(usnesení zanikne, poslední číslo se vrátí)
    Adopted --> Revoked: Revokovat (jen správce)<br/>„zrušeno usnesením č. …“
```

Číslo přiděluje číselná řada orgánu (rok / volební období / zasedání) atomicky – dvě současná hlasování nikdy nedostanou stejné číslo.

## Úkol z usnesení (`ResolutionTask`)

```mermaid
stateDiagram-v2
    [*] --> Open: Přidat úkol u usnesení
    Open --> Done: Splněno
    Open --> Cancelled: Zrušit
    Done --> Open: Otevřít
    Cancelled --> Open: Otevřít
    note right of Open: po termínu = otevřený úkol<br/>s termínem před dneškem
```

## Úkol v úkolníčku (`Project`, `ProjectStatus`)

```mermaid
stateDiagram-v2
    direction LR
    [*] --> Submitted: podnět občana
    [*] --> Idea: úřad založí
    Submitted --> Idea: posouzeno
    Submitted --> Rejected
    Idea --> Preparation
    Preparation --> Design
    Design --> Approved
    Approved --> InProgress
    InProgress --> Done
    Idea --> Postponed
    Postponed --> Idea
    Done --> [*]

    Submitted: Nový podnět
    Idea: Námět
    Preparation: V přípravě
    Design: Projekt / studie
    Approved: Schváleno
    InProgress: Realizace
    Done: Hotovo
    Postponed: Odloženo
    Rejected: Zamítnuto
```

Fázi lze přepnout na libovolnou (s poznámkou) – každá změna se zapíše do vlákna. Občan vidí jen **zveřejněné** úkoly; nový podnět je neveřejný, dokud ho úřad nezveřejní.

## Docházka (`Attendance`)

```mermaid
stateDiagram-v2
    [*] --> Unknown
    Unknown --> Present: Přítomen<br/>(po zahájení = pozdní příchod s časem)
    Unknown --> Excused: Omluven
    Unknown --> Absent: Neomluven
    Present --> Left: Odešel (čas)
    Left --> Present: Vrátil se (čas)
    Present --> Excused
    Excused --> Present
```

Usnášeníschopnost (`Quorum`): přítomna nadpoloviční většina **počtu míst** orgánu – u 9členného zastupitelstva alespoň 5.

## Osobní odkaz zastupitele (`MeetingAccessToken`)

```mermaid
stateDiagram-v2
    [*] --> Valid: vytvoření / pozvánka e-mailem
    Valid --> Valid: otevření odkazu (počítá se použití)
    Valid --> Revoked: Odvolat / nový odkaz téže osobě
    Valid --> Expired: 60 dní po zasedání
```

V databázi je jen otisk (SHA-256) – samotný odkaz se ukáže jen při vytvoření nebo odejde e-mailem.

## Komentář (`Comment`)

```mermaid
stateDiagram-v2
    [*] --> Visible: napsán (úřad nebo zastupitel)
    Visible --> PublicForCitizens: Zveřejnit občanům<br/>(jen v úkolníčku)
    PublicForCitizens --> Visible: Skrýt před občany
    Visible --> Deleted: Smazat (autor, správce)
    PublicForCitizens --> Deleted
```

Smazaný komentář s odpověďmi zůstane jako „Komentář byl smazán“, aby vlákno dávalo smysl. Diskuse u bodu se uzavře ověřením zápisu.

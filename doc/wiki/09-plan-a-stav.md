# Plán a stav

Podrobný plán s architektonickými rozhodnutími (ADR) a Deníkem změn je v **`doc/todo-v2.md`**, doménové požadavky v `doc/todo-v1.md`. Tady je přehled.

## Co je hotové (stav 17. 11. 2026)

```mermaid
flowchart LR
    subgraph F0["Základ"]
        T00[T00–T04<br/>Docker, Nette,<br/>Doctrine, tenancy]
    end
    subgraph F1["Obec"]
        T05[T05–T11<br/>uživatelé, role,<br/>úložiště, nastavení,<br/>osoby a orgány]
    end
    subgraph F2["Příprava"]
        T12[T12–T16<br/>zasedání, program,<br/>šablony, přílohy]
    end
    subgraph F3["Sdílení"]
        T17[T17–T20<br/>odkazy, portál,<br/>diskuse, pozvánky e-mailem]
    end
    subgraph F4["Průběh"]
        T21[T21–T24<br/>docházka, hlasování,<br/>diskuse, souběžná práce]
    end
    subgraph F5["Usnesení"]
        T25[T25–T26<br/>čísla usnesení,<br/>úkoly z usnesení]
    end
    subgraph F6["Dokumenty"]
        T28[T27–T30<br/>pozvánka, zápis, výpisy,<br/>prezenční listina, archiv verzí]
    end
    subgraph F10["Navíc"]
        T44[T44–T47<br/>soubory v diskusi, úkolníček,<br/>podněty občanů, rozcestník,<br/>barvy obce, editor]
    end
    T34[T34 modul rady]
    F0 --> F1 --> F2 --> F3 --> F4 --> F5 --> F6
    F6 --> T34
    F3 -.-> F10

    classDef done fill:#d1f0dc,stroke:#1e7b4a,color:#0b3d22;
    class T00,T05,T12,T17,T21,T25,T28,T44,T34 done;
```

**MVP (T00–T30 + T34) je hotové**: použitelný systém pro zastupitelstvo i radu – program, sdílení, pozvánky, průběh, usnesení, zápis a dokumenty v PDF a pro Word, včetně archivu uložených verzí a podepsaných originálů.

## Co zbývá

| Task | Co | Poznámka |
|---|---|---|
| T16b | nahrávání souborů nad 64 MB po částech | zatím limit 64 MB |
| T31 | vlastní šablony dokumentů obce | šablony jsou v `resources/documents` |
| T32 | fulltextové vyhledávání (i bez diakritiky) | přehled usnesení už hledá v textu |
| T33 | archiv podle let, plná neměnnost po ověření zápisu, důvod znovuotevření | většina pravidel už v entitách je |
| T35 | ustavující zasedání: slib členů, listina slibu | volba osob už funguje |
| T36 | ARES – údaje obce podle IČO | |
| T37–T38 | AI: návrhy textů bodů a usnesení, analýza příloh | napojení na OpenAI je připravené |
| T39 | audit – kdo co změnil | |
| T40 | přepis zvukového záznamu | |
| T41–T43 | zveřejnění, GDPR, MySQL, nasazení | |

## Známé dluhy

- Texty šablon ustavujícího zasedání musí ověřit člověk proti metodice MV.
- Ruční přečíslování usnesení (akce správce) zatím není.
- Opakované odchody a příchody jednoho člena během zasedání se nepamatují (jen poslední).
- „Moje úkoly“ páruje e-mail uživatele s e-mailem osoby – chybí přímá vazba uživatel ↔ osoba.
- Demo Neplachovic nemá radu (obec s 9 zastupiteli ji podle § 99 nevolí); tok rady ověřuje test `BoardTest`.
- Komentáře občanů na veřejné stránce zatím nejsou; mazání IP adres podnětů po čase (GDPR).

# Frontend: Vite, Bootstrap, Naja

Konšel není SPA – stránky vykresluje server (Nette + Latte). JavaScript jen vylepšuje: AJAXové kliknutí bez načtení stránky, přetahování souborů, editor komentářů, živé náhledy.

## Co je Vite

**Vite** je nástroj, který vezme zdrojové soubory z `assets/` (moderní JavaScript s `import`, SCSS) a **sestaví z nich hotové soubory** pro prohlížeč do `www/assets/`. Je to podobné jako dřív „zkompilovat LESS/SASS a spojit JS“, jen rychlé a automatické.

```mermaid
flowchart LR
    subgraph SRC["assets/ (zdroj, edituje se)"]
        MJ["main.js<br/>vstupní bod"]
        SCSS["main.scss<br/>téma, Bootstrap"]
        RE["richEditor.js<br/>richEditorCore.js"]
        TP["themePreview.js"]
    end
    subgraph NPM["node_modules/ (balíčky z npm)"]
        BS[bootstrap]
        NJ[naja]
        TT[tiptap]
        MM[mermaid]
        SO[sortablejs]
    end
    VITE{{"Vite<br/>(kontejner node, režim watch)"}}
    subgraph OUT["www/assets/ (výstup, needitovat)"]
        MAIN["main-XXXX.js<br/>main-XXXX.css"]
        CH["richEditorCore-XXXX.js<br/>mermaid-XXXX.js<br/>(načítají se až když jsou potřeba)"]
        MAN[".vite/manifest.json<br/>která verze souboru platí"]
    end
    LATTE["@layout.latte<br/>{vite('assets/main.js')}"]

    MJ --> VITE
    SCSS --> VITE
    RE --> VITE
    TP --> VITE
    NPM --> VITE
    VITE --> MAIN
    VITE --> CH
    VITE --> MAN
    MAN --> LATTE
    LATTE -->|"&lt;script&gt; a &lt;link&gt; se správným názvem"| B[Prohlížeč]
```

- Kontejner **node** běží v režimu *watch*: uložíte `main.scss` → za vteřinu je nový `www/assets/main-…css`. Stačí obnovit stránku.
- Názvy souborů obsahují otisk (`main-C51zL2W6.js`), aby prohlížeč nepoužil starou verzi z mezipaměti. Latte funkce `{vite('assets/main.js')}` (třída `ViteAssets`) najde v `manifest.json` aktuální název.
- Velké knihovny (editor TipTap, diagramy Mermaid) jsou v **samostatných souborech** a stahují se až na stránce, která je potřebuje (`await import('./richEditorCore.js')`).
- Jednorázový build: `make assets`. Nový balíček: `make npm ARGS="install --save-exact balicek@verze"`.
- Nastavení je v `vite.config.js`.

## Styly: Bootstrap 5.3 a téma

`assets/main.scss` nastaví proměnné Bootstrapu (barvy, písmo Inter, zaoblení) a pak přidá vlastní komponenty (menu, docházka, hlasování, diskuse…). Pravidla:

- Barvy vždy přes CSS proměnné `var(--bs-primary)`, `var(--bs-body-color)` – funguje tak **tmavý režim** i **barvy obce**.
- **Barvy obce** (Nastavení → Vzhled) se vloží do stránky jako `<style>` s přepsanými proměnnými (`App\UI\Theme\BrandStyle`). Komponenty, které mají v Bootstrapu barvu natvrdo (tlačítka, zaškrtávátka…), jsou v sekci „Barvy obce“ napojené na proměnné.
- Ikony: Bootstrap Icons (`<i class="bi bi-calendar-event">`). Písmo i ikony jsou součástí buildu, žádné CDN.

## Naja: AJAX bez psaní JavaScriptu

**Naja** je knihovna pro Nette: formulář nebo odkaz s třídou `ajax` se odešle na pozadí a server pošle zpět jen změněné kusy stránky (**snippety**).

```mermaid
sequenceDiagram
    participant B as Prohlížeč
    participant N as Naja
    participant P as Presenter
    B->>N: klik na tlačítko ve formuláři class="ajax"
    N->>P: POST …?do=vote (X-Requested-With)
    P->>P: provede akci, $this->redrawControl('voting-12')
    P-->>N: JSON { snippets: { "snippet--voting-12": "<div>…</div>" } }
    N->>B: nahradí obsah elementu id="snippet--voting-12"
    N->>N: událost afterUpdate → znovu napojí JS (editory, přetahování)
```

V Latte se snippet označí `{snippet attendance}…{/snippet}` nebo `n:snippet="voting-{$id}"`. Pozor: Naja mění jen **obsah** snippetu, ne atributy jeho elementu.

## Co dělá který soubor v `assets/`

| Soubor | Co dělá |
|---|---|
| `main.js` | start: Bootstrap, Naja, Nette formuláře; přetahování pořadí bodů (SortableJS), přetahování souborů do diskuse, odpovědi v diskusi, kopírování odkazů, šablony usnesení, průběžné ukládání diskuse, zámky diskuse, obnovování obrazovky průběhu, náhled masky čísel, diagramy v nápovědě |
| `richEditor.js` | najde `textarea[data-rich-editor]` a líně načte editor |
| `richEditorCore.js` | editor komentářů TipTap (tučně, kurzíva, seznamy, odkaz…) |
| `themePreview.js` | živý náhled barev obce v Nastavení → Vzhled |
| `main.scss` | téma a všechny vlastní styly |

## Kontrola vzhledu

`make screenshots PAGES="/admin,/admin/meeting"` vyfotí stránky na desktopu i mobilu (Playwright v Dockeru) do `temp/screenshots/`. Ve stejné složce `docker/screenshots/` jsou i skripty, které aplikaci „proklikají“ v opravdovém prohlížeči:

| Skript | Co ověří |
|---|---|
| `dropzone-check.cjs` | přetažení souboru do diskuse |
| `editor-check.cjs` | editor komentářů, barvy obce |
| `proceedings-check.cjs` | docházka → zahájení → hlasování → diskuse → obnovení stránky |
| `concurrency-check.cjs` | dva prohlížeče: zámek diskuse a průběžná synchronizace |

Spuštění: `docker compose run --rm screenshots sh -c "npm install --silent --prefix /tmp/pw playwright@1.49.1 && node proceedings-check.cjs"`

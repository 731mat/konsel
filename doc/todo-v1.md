# konsel.cz – rešerše produktu, funkcí a UI/UX

> **Účel dokumentu:** podklad pro další AI. Shrnuje vše, co je veřejně dostupné o produktu konsel.cz (stav webu k 16. 11. 2026, údaje na webu „k červenci 2026“).
> **Značení spolehlivosti:**
> - **[FAKT]** = uvedeno přímo na webu výrobce
> - **[ODVOZENO]** = logický závěr z textů, názvů souborů nebo technických stop, NEOVĚŘENO
> - **[NEZNÁMO]** = na webu není, nutno ověřit (demo, obchodník)

---

## 0. Zdroje a omezení rešerše


**Omezení:**
- Aplikace (`app.`) i demo (`demo.`) jsou JavaScriptové SPA. Bez přihlášení a bez spuštění JS je vidět jen text „Načítání…“. **Skutečné obrazovky aplikace jsem neviděl.**
- Videa na webu (`/videos/zastupitelstva-hero.mp4`, `/videos/sunset.webm`) jsou soubory MP4/WebM hostované přímo na webu. **Jejich obsah nebyl analyzován.** Video „sunset“ je podle názvu nejspíš dekorativní pozadí hero sekce.
- Na webu ani ve vyhledávání jsem **nenašel žádné YouTube video** produktu.
- Screenshoty z webu (viz kap. 15) jsem neprohlížel, mám jen jejich URL a popisky (alt texty).

---

## 1. Shrnutí produktu (elevator pitch)

**konsel.cz** je webová SaaS aplikace pro malé a střední české obce, městyse a města. Pokrývá celý cyklus zasedání zastupitelstva a rady:

**program → sdílení podkladů se zastupiteli → pozvánka → průběh zasedání (docházka, hlasování, diskuse) → zápis → usnesení a výpisy → archiv s fulltextem.**

Hlavní prodejní argumenty [FAKT]:
1. **AI asistent** píše texty bodů a usnesení, přepisuje audio ze zasedání a dělá souhrny. Výrobce o sobě tvrdí, že byl první s AI pro zastupitelstva v ČR.
2. **Export do Wordu (.docx) i PDF.** Výrobce zdůrazňuje plně editovatelný zdrojový soubor, „na rozdíl od konkurence“.
3. **Dokumenty 1:1 podle zvyklostí obce:** vlastní šablony, číslování usnesení, hlavičky a patičky.
4. **Zastupitelé nepotřebují hesla.** Program a přílohy otevřou přes odkaz v pozvánce a mohou připomínkovat.
5. **Cena i pro mikro-obce** (od cca 40 obyvatel).
6. **Lidská podpora každý den do 22:00**, i o víkendech a během večerních zasedání. Bez ticketů.


---

## 3. Trh, cílová skupina, reference

### Cílová skupina [FAKT]
- Obce, městyse a města v ČR, „ideálně do cca 2 000 obyvatel“, ale i větší, až po středně velká města. Nejmenší zákazník má zhruba 40 obyvatel.
- **Uživatelé:** starosta, místostarosta, tajemník, referent/administrativní pracovnice OÚ, účetní, zapisovatel.
- **Pasivní účastníci:** zastupitelé a radní, kteří čtou podklady přes odkaz a připomínkují.
- Web má alternativní locale `sk_SK` a aplikace zobrazuje „Načítání / Načítava sa…“ → [ODVOZENO] připravuje se nebo existuje slovenská lokalizace.

### Čísla [FAKT, k 7/2026]
- 200+ obcí
- 1 000+ zasedání zastupitelstev v systému
- „24/7 dostupnost“ (myšleno systému; podpora je do 22:00)

### Partnerství
- **Sdružení místních samospráv ČR (SMS ČR):** členské obce mají slevu 15 %. Partnerství bylo oznámeno 2. 7. 2026.

### Reference (shrnutí motivů z ~20 citací starostů a úřednic)
Obce: Nosálov, Mačkov, Kočov, Mořinka, Heřmaň, Vrbice, Ždírec (s podepsanou PDF referencí), Hradec, Mistřice, Chotutice, Kladruby u Vlašimi, Hlízov, Nevězice, Dobšín, městys Štoky, Úžice, Synkov-Slemeno, Dobršín. Další loga: Přimda (město), Malešov (městys), Kamýk nad Vltavou, Rosice, Moravany a desítky dalších.

Opakující se motivy (co uživatelé oceňují):
- **Úspora času:** dřív týden tisku a kompletace podkladů a ručního přepisu, teď je zápis „hotový hned po jednání“.
- Vše na jednom místě a archiv s vyhledáváním.
- Intuitivní ovládání, rychlé zaučení dalších pracovnic.
- **Chyby jdou snadno opravit**, výstupy lze doupravit ve Wordu.
- Zastupitelé mohou komentovat program.
- AI pomáhá s usneseními a zápisy.
- **Odkazy na legislativu** v systému (Úžice) → [ODVOZENO] šablony nebo body obsahují odkazy na paragrafy.
- Podpora je rychlá a dostupná i večer, požadavky obcí se zapracovávají.
- Systém se používá i jako **podklad pro řízení schůze**: starosta vede zasedání podle aplikace (Synkov-Slemeno).
- Proces v Synkově-Slemeně: podklady se připomínkují předem, **na samotném zasedání se doplňuje jen diskuse a hlasování.**

---

## 4. Cena a obchodní model [FAKT]

- **Ceník není veřejný.** Cenu počítá kalkulačka a pošle ji e-mailem „do minuty“, bez registrace a nezávazně.
- **Vstupy kalkulačky:**
  1. název obce/města/městyse
  2. počet obyvatel (stačí přibližně)
  3. počet zastupitelů (všichni jsou v ceně)
  4. počet zasedání zastupitelstva ročně
  5. má obec radu? (Ne/Ano)
  6. kontakt: jméno, e-mail, telefon (lead generation)
- **Výstup:** roční cena bez DPH plus přehled, co je v ceně.
- **Nabídka** zahrnuje přístup na 1 rok. Nastavení na míru a zaškolení se řeší individuálně.
- **V ceně (marketingově):** celý proces od programu po zápis, nastavení podle obce (šablony, číslování), zaškolení a pomoc s prvním zasedáním, podpora i během zasedání každý den do 22:00.
- **Slevy:** SMS ČR −15 %. Vstřícnost k nejmenším obcím.
- **Model:** roční předplatné, cenotvorba podle velikosti obce a frekvence zasedání. Modul rad je placený doplněk, který se dá dodatečně aktivovat.
- **Prodejní trychtýř:** web → kalkulačka nebo formulář „Kontaktujte mě“ → bezplatná ukázka (online/telefon) → zjištění potřeb → nastavení na míru → zaškolení → podpora. Alternativa: self-service **demo** na `demo.konsel.cz` (bez instalace, neobsahuje všechny funkce).

---

## 5. Hlavní workflow (4 kroky) [FAKT]

### Krok 1 – Vytvoření programu
- Založení nového zasedání, volitelně **ze šablony** (např. ustavující zasedání).
- Přidávání **bodů programu**. Ke každému bodu:
  - název bodu
  - text (popis / důvodová zpráva) – AI asistent ho dopíše z názvu bodu, případně z doplňujících informací
  - **návrh usnesení** – z knihovny šablon (výrobce i vlastní) nebo přes AI
  - **přílohy** bez omezení velikosti (smlouvy, rozpočty, projektová dokumentace, i video)
- Tagy na webu: *Šablony · Inteligentní asistent · Přílohy*

### Krok 2 – Sdílení programu
- Program se sdílí se zastupiteli **odkazem, bez hesel a účtů**.
- Zastupitel vidí program a přílohy a může napsat **zpětnou vazbu nebo komentář**.
- Zpětná vazba se autorovi **zobrazí přímo při úpravě programu**.
- Stažení **pozvánky** ve Wordu/PDF, připravené pro web a úřední desku.
- Tagy: *Sdílení · Zpětná vazba · Stažení ve Wordu/PDF*

### Krok 3 – Zápis z jednání
- Body z programu se **automaticky přenesou** do zápisu.
- Během zasedání se doplňuje:
  - **docházka** (přítomen / omluven / neomluven, lze měnit kdykoli během zasedání)
  - **hlasování** u každého bodu (kdo jak hlasoval, zdržel se)
  - **diskuse** k bodu (nebo dopsat až ve vygenerovaném Wordu)
- **Souběžná práce více uživatelů.** Upravovaný bod se **automaticky zamkne**, aby se lidé nepřepsali. Data se mezi uživateli synchronizují v reálném čase (tajemník i starosta vidí totéž).
- Volitelně nahrání **audio záznamu**, který AI doslovně přepíše s rozlišením řečníků a umí udělat i souhrn.
- Na konci se vygeneruje **hotový zápis** ve Wordu/PDF.
- Tagy: *Zápis · Hlasování · Stažení ve Wordu/PDF · Diskuze · Docházka*

### Krok 4 – Výpis z usnesení
- Výpis **jednoho usnesení** nebo **všech usnesení** ze zasedání, ve Wordu/PDF.
- **Fulltextové vyhledávání** napříč všemi zasedáními (záznamy i návrhy usnesení).
- Tagy: *Výpis usnesení · Jedno usnesení · Více usnesení*

---

## 6. Kompletní seznam funkcí [FAKT]

| # | Funkce | Popis |
|---|---|---|
| 1 | Sdílení se zastupiteli | Program se sdílí odkazem. Zpětná vazba se zobrazuje při editaci programu. |
| 2 | Zastupitelé bez hesel | Přístup přes odkaz v pozvánce, bez správy účtů. |
| 3 | Fulltextové vyhledávání | Ve všech zasedáních a návrzích usnesení. |
| 4 | Přístup do ARES | Vyhledání v registru ARES přímo v aplikaci, bez přepínání oken. |
| 5 | Dokumenty na míru | Vzhled výstupů může být shodný s dosavadními dokumenty obce. |
| 6 | Šablony | Opakující se návrhy usnesení. Profesionální databáze šablon i vlastní šablony. |
| 7 | Docházkový systém | Přítomen / omluven / neomluven, editovatelné během zasedání. |
| 8 | Zápisy ze zasedání | Kolaborativní tvorba zápisu, zamykání bodů. |
| 9 | Výpisy z usnesení | Jedno nebo všechna usnesení, PDF i Word. |
| 10 | Automatický přepis záznamu | Upload audia, AI doslovný přepis. |
| 11 | Výstup ve Wordu a PDF | Všechny dokumenty jako editovatelné .docx. |
| 12 | Diskuse | Ke každému bodu zvlášť. |
| 13 | Hlasování | Rychlé „zaklikání“ hlasů. |
| 14 | Umělá inteligence | Pomoc s texty. |
| 15 | Přílohy | Ke každému bodu zvlášť. |
| 16 | Neomezená velikost příloh | Včetně videa a projektové dokumentace. |
| 17 | Prezenční listina | Generování dokumentu. |
| 18 | Automatické číslování usnesení | „Jakýkoli formát“ číslování. |
| 19 | Hlídání usnesení | Stavy usnesení: přijaté / odložené / zamítnuté, přehledně na jednom místě. |
| 20 | Modul rad | Samostatný modul pro schůze rady. |
| 21 | Šablony ustavujícího zasedání | Bez rady (<15 členů) a s radou (≥15 členů), podle metodiky MV. |
| 22 | Nahlásit chybu | Mailto odkaz ve footeru. |

**Generované dokumenty:** program, pozvánka, zápis, usnesení, výpis z usnesení (jedno i hromadně), prezenční listina. U ustavujícího zasedání je zmíněna i listina slibu, kterou obec přikládá.

---

## 7. AI funkce podrobně [FAKT + ODVOZENO]

| AI funkce | Co dělá |
|---|---|
| **AI asistent textů** | Z názvu bodu vygeneruje text bodu nebo návrh usnesení. Lze přidat kontext a výsledek je pak „skoro perfektní“. Pomáhá s texty programu, zápisu i usnesení. |
| **Přepis ze zvuku** | Upload zvukové nahrávky, doslovný přepis s **diarizací** (rozpoznání řečníků) a strukturováním diskuse. |
| **Souhrn přepisu** | Zkrácený souhrn dlouhého doslovného přepisu, použitelný jako diskuse v zápisu. |
| **Analýza dokumentů** | AI přečte přiložené dokumenty a **navrhne celý bod** (text i usnesení), jako osobní asistent. |

- Výrobce opakovaně uvádí, že **AI nenahrazuje rozhodování zastupitelstva**, slouží jen k rychlejší administrativě.
- GDPR: AI služby běží u subdodavatelů, možný **přenos do USA na základě SCC**. Data posílaná přes API **se nepoužívají k trénování modelů**.
- [NEZNÁMO] Který LLM nebo STT poskytovatel se používá, limity délky audia, podporované formáty, cena za minutu přepisu (pravděpodobně v ceně).
- [ODVOZENO] Diarizace řečníků spíš označuje „Řečník 1/2…“. Přiřazení ke jménům zastupitelů je nejasné.

---

## 8. Modul rad [FAKT]

- Samostatný modul pro schůze rady obce, městyse nebo města. **Stejné UI a postup jako u zastupitelstva**, takže se uživatel nemusí nic nového učit.
- Vlastní šablony pozvánky, zápisu, výpisů a prezenční listiny pro radu.
- **Odeslání podkladů jedním kliknutím jen členům rady** (příjemci se nevybírají ručně) → [ODVOZENO] systém eviduje členství osob v orgánech.
- **Vlastní číselná řada usnesení rady** v libovolném formátu, oddělená od usnesení zastupitelstva.
- Sdílené kontakty i nastavení mezi radou a zastupitelstvem.
- Přepis audia, souběžná práce a zamykání bodů jako u zastupitelstva.
- Fulltext napříč všemi schůzemi rady.
- Stávajícím zákazníkům se modul **aktivuje na požádání**, nastavení provede výrobce.

---

## 9. Ustavující zasedání (volby 2026) [FAKT]

- Při založení zasedání lze vybrat šablonu:
  - „Ustavující zasedání zastupitelstva – **bez rady**“ (zastupitelstvo < 15 členů)
  - „Ustavující zasedání zastupitelstva – **s radou**“ (≥ 15 členů)
- Předvyplněný program, návrhy usnesení i texty do zápisu podle **metodického doporučení MV č. 6.4 (květen 2026)**.
- Uživatel doplní jména, počty a částky.
- **Obě šablony obsahují:** slib členů, určení ověřovatelů a zapisovatele, schválení programu, určení uvolněných funkcí, volbu starosty a místostarosty, zřízení finančního a kontrolního výboru, odměny neuvolněných členů.
- **Šablona s radou navíc:** určení počtu členů rady a volbu dalších radních.
- Web k tomu nabízí SEO průvodce: lhůty, zasedání s radou, organizace zasedání (harmonogram, úřední deska, listiny v sále).
- [ODVOZENO] Šablony zasedání jsou obecný mechanismus. Lze tedy předpokládat i další šablony, například pro rozpočtové nebo závěrečné-účtové zasedání.

---

## 10. Role, přístupy, oprávnění

| Role | Přístup | Spolehlivost |
|---|---|---|
| Uživatel úřadu (starosta, tajemník, referent) | Přihlášení do `app.` heslem, plná editace | [FAKT] autentizace heslem podle GDPR stránky |
| Zastupitel / radní | Odkaz bez hesla, čtení programu a příloh, komentáře | [FAKT] |
| Více uživatelů úřadu současně | Kolaborace se zamykáním bodů | [FAKT] |
| Admin vs. editor, granularita práv | „Princip nejmenšího privilegia“ | [NEZNÁMO] konkrétní role |
| Veřejnost / občané | Žádný veřejný portál zmíněn, výstupy jdou na web nebo úřední desku ručně | [ODVOZENO] |

[NEZNÁMO] Jak je zabezpečený odkaz pro zastupitele (unikátní token na osobu? expirace?), jestli se komentáře přiřazují ke jménu a jestli se pozvánka rozesílá e-mailem přímo ze systému. Reference zmiňuje „rozesílání pozvánek zastupitelům“, takže spíš ano.

---

## 11. Bezpečnost a GDPR [FAKT]

- Role: obec je **správce**, ADVANT systems je **zpracovatel** (čl. 28 GDPR).
- Účel zpracování: transparentnost a dokumentace veřejných zasedání, transkripce, sumarizace, AI generování textů.
- Údaje: jméno, příjmení a e-mail uživatelů a zastupitelů. **Hlas a osobní údaje třetích osob na audio záznamech** (občané, hosté).
- Právní základ: čl. 6/1 e) veřejný zájem, čl. 6/1 b) smlouva.
- Opatření: šifrování při ukládání **AES-256**, pseudonymizace, HTTPS, autentizace heslem, nejmenší privilegium, průběžné zálohování, pravidelné testování.
- Servery primárně v **EU**. Možný přenos do **USA** (infrastruktura a AI) přes SCC 2021/914.
- Vyřízení žádostí subjektů do 5 pracovních dnů. Po skončení smlouvy výmaz nebo vrácení dat do 30 dnů.
- Poslední aktualizace zásad: březen 2026.
- [NEZNÁMO] ISO 27001, penetrační testy, 2FA, SSO, audit log, NIS2.

---

## 12. Onboarding a podpora [FAKT]

1. **Spojíme se:** bezplatná, nezávazná ukázka.
2. **Zjistíme potřeby:** zavedené postupy, formát dokumentů, specifika.
3. **Nastavíme na míru:** šablony, číslování usnesení, jednací řád.
4. **Zaškolíme** všechny uživatele, provedeme prvním zasedáním.
5. **Podpora:** telefon každý den včetně víkendů zhruba 8:00–22:00, „žádné tickety“, odpovídá skutečný člověk.

- Vývoj je řízený požadavky zákazníků, „mnoho funkcí vzniklo z podnětů obcí“.
- Na webu nejsou veřejné nápovědy, video tutoriály, changelog, status page ani API dokumentace.

---

## 13. Technologie [ODVOZENO z technických stop]

| Stopa | Závěr |
|---|---|
| `meta-next-head-count` v HTML webu | Marketingový web běží na **Next.js** (React). |
| `app.konsel.cz` vrací jen „Načítání…“ a Sentry meta tagy | Aplikace je **SPA**, monitoring chyb přes **Sentry** (environment=production, release = git SHA). |
| Avatary `/avatars/<28 znaků>.webp`, např. `bm21Xs68oSYfMeKrk9mfsvpptvl1` | Formát odpovídá **Firebase Auth UID** → [hypotéza] backend nebo auth na Firebase/Google Cloud. |
| „Načítání / Načítava sa…“ | i18n CZ/SK. |
| Souběžná editace se zamykáním bodů a realtime | Realtime databáze nebo websockety (Firestore listeners by tomu odpovídaly). |
| Neomezená velikost příloh | Objektové úložiště (GCS/S3) s přímým uploadem. |
| Generování .docx podle šablon obce | Šablonovací engine pro DOCX (např. docxtemplater) + konverze do PDF. |
| AI | Externí LLM a STT přes API, poskytovatel v USA. |
| Samostatná instance `demo.` | Oddělené demo prostředí s omezenými funkcemi. |

---

## 14. UI/UX analýza – marketingový web [FAKT + hodnocení]

### Informační architektura
- **Hlavní menu:** Funkce (kotva) · Rada · Reference (kotva) · FAQ · Podpora (kotva) · Kontakt. Vpravo: **Přihlásit se** a primární CTA **Spočítat cenu**.
- **Oznamovací pruh nad hero:** „Volby 2026 – Ustavující zasedání máte přímo v systému. Šablony a průvodce →“ (sezónní marketing).
- **Footer ve 3 sloupcích:** Společnost (O nás, Kontakt, Fakturační údaje, Kariéra, Ceník, SMS ČR) · Informace (Modul rad, Funkce, FAQ, Podpora, Přihlášení, Nahlásit chybu) · Průvodce (7 SEO článků).

### Struktura úvodní stránky (pořadí sekcí)
1. **Hero:** nadpis „Moderní nástroj pro správu Vaší obce“, podnadpis (program → hlasování → Word/PDF, „první AI systém v ČR“), CTA „Kontaktujte mě“ (zřejmě rozbalovací mini-formulář pro e-mail), sekundární odkaz „spočítejte cenu →“. Vpravo nebo na pozadí video `zastupitelstva-hero.mp4` (pravděpodobně ukázka aplikace) a `sunset.webm`. Dekorativní tečky (`dots.svg`).
2. **„Jak to vlastně funguje?“:** 4 kroky v **zig-zag layoutu** (text vlevo/obrázek vpravo a naopak, spojené křivkami `LineLeft.svg`/`LineRight.svg`), velké číslo kroku, odstavec, řada **pill tagů** s klíčovými funkcemi a screenshot (`workflow1–4`).
3. **Kontaktní formulář:** e-mail, jméno, telefon, zpráva (vše povinné) + kontakty.
4. **Reference:** počítadla (200+ obcí, 1000+ zastupitelstev, 24/7), partner SMS ČR, karusel nebo mřížka citací s **avatarem (znak obce), jménem, obcí a funkcí**, mřížka znaků dalších obcí a tlačítko „Zobrazit zbytek“.
5. **Kalkulačka ceny:** dvoukrokový formulář vlevo. Vpravo **náhled e-mailu** („Od: konsel.cz · Komu: Váš e-mail · Orientační cena – Vaše obec · 00 000 Kč – Uvidíte v e-mailu · za rok bez DPH“) a seznam „Co získáte“. Chytrý vzor: cena se na webu nezobrazí a sbírá se lead.
6. **„A co to všechno umí?“:** 20 **feature karet** (ikona/ilustrace, nadpis, krátký popis). V HTML se karty opakují dvakrát → [ODVOZENO] nekonečný **marquee/karusel**.
7. **AI sekce „Průkopníci v AI“:** 3 karty (AI asistent, Přepisy ze zvuku, Inteligentní analýza).
8. **„Dokumenty přesně jak jste zvyklí“:** 3 karty (Dokumenty 1:1, Respekt k procesům, Individuální přístup).
9. **Bezpečnost:** 4 karty (Data v EU, Šifrování, Řízení přístupu, Zálohování).
10. **Vyzkoušení zdarma:** CTA a telefon.
11. **Podpora do 22:00.**
12. **Spolupráce krok za krokem:** 5 kroků (01–05).
13. **Závěrečné CTA** a footer.

### Tón a copywriting
- Vykání s velkým „V“ (Vaší, Vám), úřednicky zdvořilé a vstřícné vůči starostům.
- Témata: úspora času, „nemusíte měnit, co funguje“, jednoduchost, lidská podpora, AI jako pomocník.
- Drobné překlepy na webu („repetetivní“, „zvášť“, „inforamce“) → [hodnocení] web je psaný rychle, bez korektury.

### Hodnocení UX webu
- **Plusy:** jasný 4krokový příběh, silný social proof (jména a znaky obcí, podepsaná PDF reference), nízkoprahové CTA (kalkulačka bez registrace), sezónní landing pro volby, SEO průvodce zákonnými povinnostmi.
- **Mínusy:** chybí veřejná cena, ukázkové výstupní dokumenty (vzor zápisu ke stažení), video tutoriály a odkaz na demo z hlavní stránky (demo je jen na /sms-cr/). Funkce se opakují na více místech. Kontaktní formulář má povinný telefon.

---

## 15. UI/UX aplikace – rekonstrukce [ODVOZENO, NEOVĚŘENO]

> Aplikaci jsem neviděl. Tohle je model sestavený z textů webu. Pro ověření projít demo nebo screenshoty:
> - https://konsel.cz/heroSectionScreenshot2.png (hlavní screenshot rozhraní, OG obrázek 1200×630)
> - https://konsel.cz/workflow1.jpg (vytvoření programu)
> - https://konsel.cz/workflow2.jpg (sdílení programu)
> - https://konsel.cz/workflow3Preview2.jpg (zápis z jednání)
> - https://konsel.cz/workflow4.jpg (výpis z usnesení)
> - https://konsel.cz/videos/zastupitelstva-hero.mp4 (video v hero sekci)

### Pravděpodobné obrazovky
1. **Přihlášení:** e-mail a heslo (CZ/SK).
2. **Přehled zasedání (dashboard):** seznam zasedání zastupitelstva a rady (přepínač orgánu), stav (příprava / sdíleno / probíhá / ukončeno), tlačítko „Nové zasedání“ s výběrem šablony.
3. **Detail zasedání – záložka Program:**
   - hlavička: číslo zasedání, datum, čas, místo
   - seznam bodů (drag & drop pořadí?), u bodu název, text, návrh usnesení, přílohy
   - tlačítko AI („Vygenerovat text“ z názvu a kontextu nebo z přílohy)
   - vložení šablony usnesení z knihovny
   - ARES lookup (např. smluvní strana podle IČO)
   - panel **komentářů zastupitelů** u bodu
   - akce: Sdílet se zastupiteli · Stáhnout pozvánku (DOCX/PDF)
4. **Pohled zastupitele (bez přihlášení):** read-only program, přílohy ke stažení, pole pro komentář.
5. **Detail zasedání – záložka Zápis / průběh:**
   - **Docházka:** seznam členů s přepínačem přítomen / omluven / neomluven
   - u každého bodu **hlasovací matice** (člen × pro / proti / zdržel se), součty a výsledek, stav usnesení (přijato / zamítnuto / odloženo)
   - pole Diskuse (ručně nebo vložení z AI přepisu nebo souhrnu)
   - indikátor zamčeného bodu („upravuje Jana Nováková“)
   - upload audio záznamu, přepis, souhrn
   - akce: Stáhnout zápis · Prezenční listina (DOCX/PDF)
6. **Usnesení / výpisy:** seznam usnesení s automatickým číslem, filtr stavů, export jednoho nebo všech.
7. **Vyhledávání:** fulltext přes všechna zasedání a usnesení.
8. **Nastavení obce:** členové orgánů (zastupitelstvo, rada), formát číslování usnesení (maska), DOCX šablony dokumentů (hlavička, patička, logo), knihovna šablon usnesení. Část nastavení dělá výrobce za klienta.

### Pravděpodobný datový model
- **Obec (tenant)** → **Orgán** (Zastupitelstvo | Rada) → **Člen** (jméno, e-mail, funkce, období)
- **Zasedání** (orgán, pořadové číslo, datum, místo, typ: řádné/ustavující, šablona, stav)
- **Bod programu** (pořadí, název, text, návrh usnesení, přílohy[], komentáře[], zámek: uživatel+čas, diskuse)
- **Docházka** (zasedání × člen → stav)
- **Hlasování** (bod × člen → pro / proti / zdržel se / nehlasoval)
- **Usnesení** (číslo dle masky, text, stav, vazba na bod)
- **Záznam audio** → **Přepis** (segmenty s řečníky) → **Souhrn**
- **Šablona** (usnesení / zasedání / dokument DOCX)
- **Vygenerovaný dokument** (typ: pozvánka, zápis, výpis, prezenční listina; formát DOCX/PDF)

---

## 16. Právní kontext (pro pochopení domény)

- **Zákon o obcích č. 128/2000 Sb., § 95:** zápis obsahuje minimálně místo a čas, přítomné členy, průběh a výsledek hlasování a přijatá usnesení. Schvaluje se na dalším zasedání. Podle obecně známé praxe se pořizuje do 10 dnů po zasedání.
- **Informace o zasedání** se zveřejňuje na úřední desce nejméně **7 dní předem**.
- **Usnesení:** číslo (např. 12/2026), datum, text, způsob a výsledek hlasování, podpis. **Výpis z usnesení** je zkrácená verze pro úřední desku.
- **Ustavující zasedání:** volba starosty a místostarosty, výbory (finanční a kontrolní, lichý počet, min. 3 členové), u obcí s ≥15 zastupiteli rada.
- **Zápis z rady:** počet přítomných, program, hlasování, usnesení. Podepisuje starosta s místostarostou nebo radním.

---

## 17. Silné stránky, slabiny, nejasnosti

**Silné stránky**
- Jednoduchý lineární proces: data se zadají jednou a „tečou“ do dalších dokumentů.
- Export do DOCX (editovatelný) je výrazný diferenciátor.
- Personalizace dokumentů 1:1 provedená výrobcem snižuje bariéru změny.
- AI přepis a souhrn řeší nejpracnější část (diskusi).
- Přístup zastupitelů bez hesel = nulové tření.
- Cenová dostupnost a podpora do 22:00 sedí na realitu malých obcí (večerní zasedání, starosta často neuvolněný).

**Slabiny a rizika**
- Přístup přes odkaz bez hesla je bezpečnostní kompromis (sdílení odkazu dál).
- Přenos dat do USA kvůli AI (audio s hlasy občanů) je citlivé.
- Malý tým = závislost na klíčových osobách.
- Chybí veřejná dokumentace, API a integrace se spisovou službou nebo webem obce (úřední deska) – alespoň na webu nejsou zmíněné.
- Bez veřejného ceníku je těžké srovnání.

**[NEZNÁMO] – ověřit v demu nebo u obchodníka**
- Publikace na web obce / úřední desku (integrace, RSS, API)?
- Elektronické hlasování zastupitelů nebo hlasovací zařízení? (Spíš ne, hlasy zapisuje zapisovatel.)
- Distanční účast, livestream?
- Anonymizace výpisů a zápisů pro zveřejnění (GDPR)?
- Elektronický podpis dokumentů, verze dokumentů, schvalování zápisu ověřovateli?
- Úkoly a termíny z usnesení (kdo co do kdy splní)? „Hlídání usnesení“ zmiňuje jen stavy.
- Import historických zasedání, export celého archivu?
- Mobilní nebo tabletové rozhraní během zasedání?
- Audit log a historie změn?

---

## 18. Mapa webu (URL)

- https://konsel.cz/ (úvodní stránka)
- https://konsel.cz/funkce/
- https://konsel.cz/rady/
- https://konsel.cz/cenik/
- https://konsel.cz/faq/
- https://konsel.cz/kontakt/ (tým, fakturační údaje)
- https://konsel.cz/sms-cr/ (sleva a odkaz na demo)
- https://konsel.cz/ustavujici-zasedani/
- https://konsel.cz/ustavujici-zasedani-zastupitelstva/
- https://konsel.cz/ustavujici-zasedani-zastupitelstva-s-radou/
- https://konsel.cz/jak-zorganizovat-ustavujici-zasedani/
- https://konsel.cz/zapis-ze-zasedani-zastupitelstva/
- https://konsel.cz/usneseni-zastupitelstva/
- https://konsel.cz/program-zasedani-zastupitelstva/
- https://konsel.cz/gdpr/
- https://konsel.cz/cookies/
- https://konsel.cz/kariera/
- https://app.konsel.cz/ (aplikace, login)
- https://demo.konsel.cz/?referral=sms (demo, omezené funkce)

---

## 19. Pokyny pro navazující AI

- Kapitoly 1–12 berte jako fakta z webu výrobce, tedy marketingová tvrzení bez nezávislého ověření.
- Kapitoly 13 a 15 jsou **hypotézy**. Nepovažujte je za ověřený popis UI.
- Pokud je cílem **navrhnout vlastní obdobný systém** (např. pro obec Neplachovice), jako minimální funkční rozsah (MVP) použijte kap. 5, 6, 8 a datový model z kap. 15. Konkurenční výhody ke zvážení: integrace s úřední deskou a webem obce, anonymizace pro zveřejnění, úkoly z usnesení, zpracování dat čistě v EU.
- Chybějící vizuální informace doplňte ze screenshotů v kap. 15 nebo z dema.
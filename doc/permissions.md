# Matice oprávnění

Zdroj pravdy v kódu: `App\Core\Security\AuthorizatorFactory`. Test `tests/Unit/Core/Security/AuthorizatorTest.phpt` hlídá, že kód odpovídá této tabulce. Při změně se upravují všechna tři místa.

Privilegia: **V** = view (náhled), **E** = edit (úpravy), **D** = delete (mazání), **M** = manage (správa nad rámec editace, např. znovuotevření schváleného zasedání nebo přečíslování usnesení).

| Zdroj | Čtenář | Editor | Správce obce | Správce systému |
|---|---|---|---|---|
| dashboard – přehled | V | V | VEDM | VEDM |
| meeting – zasedání | V | VE | VEDM | VEDM |
| agenda – body programu | V | VED | VEDM | VEDM |
| minutes – průběh a zápis | V | VE | VEDM | VEDM |
| resolution – usnesení | V | VE | VEDM | VEDM |
| attachment – přílohy | V | VED | VEDM | VEDM |
| document – generované dokumenty | V | VE | VEDM | VEDM |
| template – šablony | V | VE | VEDM | VEDM |
| member – osoby, orgány, členství | V | V | VEDM | VEDM |
| project – plány, podněty občanů | V | VE | VEDM | VEDM |
| user – uživatelé úřadu | – | – | VEDM | VEDM |
| settings – nastavení obce | – | – | VEDM | VEDM |
| audit – historie změn | – | – | VEDM | VEDM |
| system – správa obcí | – | – | – | VEDM |

## Použití v kódu

```php
#[Allowed(Resource::Meeting, Privilege::Edit)]
public function actionEdit(int $id): void { … }
```

- Atribut na třídě presenteru platí pro všechny jeho metody. Atribut na metodě se kontroluje navíc.
- Test `AllowedCoverageTest` selže, pokud v Admin modulu existuje `action*`/`render*`/`handle*` bez `#[Allowed]`.
- Oprávnění v UI (skrytí tlačítek) jen doplňuje kontrolu v presenteru, nikdy ji nenahrazuje.

## Připojení AI (server MCP)

- AI asistent připojený k účtu (osobní token nebo aplikace přes OAuth) má **stejnou roli** jako uživatel – nástroj MCP deklaruje zdroj a privilegium v `ToolDefinition` a server ho nabídne i provede jen při `isAllowed(role, zdroj, privilegium)`.
- Navíc rozsah připojení: **jen čtení**, nebo **čtení i změny** (nástroje s `writes: true`). Rozsah volí uživatel při souhlasu / vytvoření tokenu.
- Vlastní připojení vidí a odpojí každý uživatel; správce obce (`user` edit) vidí a odpojí připojení všech uživatelů obce.
- Správce systému (bez obce) si AI připojit nemůže – nástroje pracují vždy v kontextu jedné obce.

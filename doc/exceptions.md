# Výjimky a Error Handling

Framework Frasm využívá centralizovaný systém zpracování chyb a hierarchii typovaných výjimek. Cílem je poskytnout podrobné diagnostické informace během vývoje a zároveň zajistit bezpečný, nepropustný výstup v produkčním prostředí.


## Hierarchie výjimek

Všechny výjimky specifické pro framework dědí ze základní abstraktní třídy `Core\Exceptions\FrasmException`, která umožňuje k chybě připojit strukturovaná kontextová data:

```text
\Exception
 └── Core\Exceptions\FrasmException (abstraktní, nese kontextové pole $context)
      └── Core\Exceptions\CoreException (výchozí stavový kód 500)
           ├── Core\Exceptions\RouteNotFoundException (HTTP 404 - trasa nenalezena)
           ├── Core\Exceptions\MethodNotAllowedException (HTTP 405 - nepovolená HTTP metoda)
           ├── Core\Exceptions\AuthException (HTTP 401 / 403 - neautorizovaný přístup)
           └── Core\Exceptions\CsrfException (HTTP 403 - neplatný CSRF token)

```


## Připojení diagnostického kontextu

Výjimky umožňují předat asociativní pole s ladicími údaji (např. stav proměnných, parametry požadavku), které se v debug módu přehledně zobrazí v JSON/HTML výstupu:

```php
use Core\Exceptions\CoreException;

throw new CoreException(
    message: "Komponentu se nepodařilo synchronizovat.",
    code: 400,
    context: [
        'component' => $componentClass,
        'action'    => $action,
        'payload'   => $updates,
    ]
);

```


## Globální Error Handling v `public/index.php`

Vstupní bod aplikace obaluje celý proces směrování a běhu kontrolerů do bloku `try/catch (\Throwable $e)` a aplikuje následující pravidla:

### 1. Převod PHP chyb na výjimky

Nativní PHP varování, upozornění a chyby (`E_WARNING`, `E_NOTICE`, atd.) zachytává `set_error_handler` a okamžitě je převádí na `\ErrorException`. Tím je zaručeno, že žádná skrytá chyba neprojde bez povšimnutí.

### 2. Přepínání vývoj / produkce (`config/app.php`)

Chování se řídí hodnotou direktivy `app.debug`:

* **`'debug' => true` (Vývoj):**
Aktivují se direktivy `display_errors = 1` a `error_reporting = E_ALL`. Při vzniku chyby se vykreslí tmavá formátovaná obrazovka obsahující název výjimky, přesný soubor, řádek, kompletní call stack-trace a vypsaný diagnostický kontext.
* **`'debug' => false` (Produkce):**
Direktivy `display_errors = 0` a potlačení výpisu. Zobrazí se pouze minimalistická a bezpečná HTML stránka (např. *500 - Server Error* nebo *404 - Page Not Found*) bez úniku citlivých systémových cest a hesel.

### 3. Automatická detekce AJAX / JSON požadavků

Pokud klient očekává JSON odpověď:

* Hlavička `Accept: application/json`
* Hlavička `X-Requested-With: XMLHttpRequest` (automaticky odesílá `frasm-live.js` i běžné fetch/axios knihovny)

Framework namísto HTML stránky vrátí čistý JSON payload s odpovídajícím HTTP status kódem:

```json
{
  "status": 500,
  "message": "Cannot assign string to property App\\Components\\CalculatorComponent::$amount of type float",
  "exception": "TypeError",
  "file": "/var/www/frasm/core/View/Component.php",
  "line": 81,
  "trace": [ ... ],
  "context": { ... }
}

```

V produkčním režimu (`debug => false`) JSON výstup obsahuje pouze sanitizované položky `status` a obecnou `message`.



## Odkazy

* Předchozí: [Reaktivní Live Komponenty](live-components.md)
* Návrat na: [Úvodní rozcestník](index.md)


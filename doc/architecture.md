# Architektura a životní cyklus požadavku

Framework striktně odděluje systémové jádro (`core/`) od aplikační byznys logiky (`app/`). Veškerý uživatelský kód se nachází v `app/`, zatímco `core/` poskytuje znovupoužitelnou infrastrukturu.

---

## Adresářová struktura

```text
frasm/
├── app/                  # Aplikační vrstva (uživatelský kód)
│   ├── Components/       # Třídy reaktivních komponent (dědí od Core\View\Component)
│   ├── Controllers/      # Uživatelské HTTP kontrolery (dědí od BaseController)
│   ├── Models/           # Databázové entity a doménová logika
│   └── Views/            # Šablony rozhraní a komponent (.php)
├── config/               # Konfigurační soubory aplikace
│   ├── app.php           # Nastavení ladění, časové zóny a cest
│   └── routing.php       # Mapování namespace a cest ke kontrolerům
├── core/                 # Jádro frameworku (systémová infrastruktura)
│   ├── Autoload/         # PSR-4 Autoloader
│   ├── Config/           # Správce konfigurace
│   ├── Controller/       # BaseController a HTTP pomocníci
│   ├── Exceptions/       # Hierarchie výjimek a chybové stavy
│   ├── Routing/          # Router, Route matcher a PHP 8 atributy
│   └── View/             # Reaktivní komponenty a LiveComponentHandler
├── doc/                  # Projektová dokumentace
└── public/               # Jediný veřejně přístupný adresář (DocumentRoot)
    ├── index.php         # Front Controller
    └── js/
        └── frasm-live.js # Klientský ovladač pro reaktivní binding

```


## Životní cyklus požadavku (Request Lifecycle)

Každý příchozí HTTP požadavek prochází jednotným procesem v souboru `public/index.php`:

```text
[ HTTP Požadavek ]
       │
       ▼
[ public/index.php (Front Controller) ]
       │
       ├── 1. Registrace PSR-4 Autoloaderu pro Core\ a App\
       ├── 2. Načtení konfigurace (Config::load) a nastavení error handleru
       ├── 3. Spuštění session (session_start)
       ├── 4. Registrace tras:
       │      ├── Interní endpoint: Core\View\Live\LiveComponentHandler
       │      └── Uživatelské kontrolery z app/Controllers/ (reflexe atributů)
       │
       ▼
[ Core\Routing\Router::dispatch() ]
       │
       ├── Detekce a normalizace URL cesty (včetně běhu v podadresáři)
       ├── Porovnání metody a regex patternu trasy
       ├── Kontrola autorizačních rolí (#[Authorize])
       └── Spuštění handleru (metoda kontroleru nebo closure)
       │
       ▼
[ BaseController / Action ]
       │
       ├── Vykreslení šablony (view) s automatickým CSRF kontextem
       ├── Vrácení JSON odpovědi (json)
       └── Přesměrování (redirect)
       │
       ▼
[ Výstup / Globální odchycení výjimek ]
       ├── Úspěšný výstup (HTML nebo JSON)
       └── Odchycení Throwable:
              ├── JSON odpověď (pokud šlo o AJAX / Livewire sync)
              ├── Debug obrazovka se stack-trace (app.debug = true)
              └── Bezpečná chybová stránka 404/500 (app.debug = false)

```


## Odkazy

* Předchozí: [Úvodní rozcestník](index.md)
* Následující: [Routování a atributy](routing.md)

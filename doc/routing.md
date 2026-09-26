# Routování a PHP 8 atributy

Frasm využívá deklarativní registraci tras přímo v kódu kontrolerů pomocí nativních PHP 8 atributů. Není potřeba psát žádné konfigurační pole ani externí směrovací tabulky.


## Dostupné atributy

Všechny atributy se nacházejí v namespace `Core\Routing\Attributes`:

| Atribut | Cíl | Popis |
|---|---|---|
| `#[Route(path, method, name)]` | Třída / Metoda | Základní atribut pro určení cesty, metody nebo prefixu třídy. |
| `#[Get(path, name)]` | Metoda | Zkratka pro HTTP GET trasu. |
| `#[Post(path, name)]` | Metoda | Zkratka pro HTTP POST trasu. |
| `#[Put(path, name)]` | Metoda | Zkratka pro HTTP PUT trasu. |
| `#[Delete(path, name)]` | Metoda | Zkratka pro HTTP DELETE trasu. |
| `#[Authorize(roles)]` | Třída / Metoda | Omezení přístupu na základě uživatelských rolí v session. |



## Příklad použití v kontroleru

Atributy lze kombinovat jak na úrovni celé třídy (jako společný prefix a výchozí autorizaci), tak na úrovni jednotlivých veřejných metod:

```php
namespace App\Controllers;

use Core\Controller\BaseController;
use Core\Routing\Attributes\Route;
use Core\Routing\Attributes\Get;
use Core\Routing\Attributes\Post;
use Core\Routing\Attributes\Authorize;

#[Route('/articles')]
#[Authorize('editor')] // Výchozí role vyžadovaná pro všechny metody v tomto kontroleru
class ArticleController extends BaseController
{
    #[Get('/')] // Výsledná cesta: GET /articles
    public function index(): string
    {
        return $this->view('articles/index');
    }

    #[Get('/{id}')] // Výsledná cesta: GET /articles/42
    public function show(string $id): void
    {
        $this->json([
            'articleId' => (int)$id,
            'title' => 'Ukázkový článek',
        ]);
    }

    #[Post('/store')] // Výsledná cesta: POST /articles/store
    public function store(): never
    {
        $this->validateCsrf();
        // Zpracování vytvoření článku...
        $this->redirect('/articles');
    }

    #[Post('/{id}/publish')] // Přebití role pouze pro tuto akci:
    #[Authorize(['admin'])] 
    public function publish(string $id): void
    {
        // Publikovat může pouze admin
    }
}

```


## Dynamické parametry v URL

Parametry se v cestě ohraničují složenými závorkami `{nazev}`:

* Cesta `/users/{id}` matchne např. `/users/15`.
* Router automaticky extrahuje hodnoty a předá je metodě kontroleru jako argumenty podle pořadí výskytu v URL.


## Automatická registrace přes skener

V `public/index.php` se kontrolery registrují automaticky:

```php
$controllersPath = (string)Config::get('routing.controllers_path', FRASM_APP_DIR . '/Controllers');
$controllersNamespace = (string)Config::get('routing.controllers_namespace', 'App\\Controllers\\');

$router->registerControllersFromDirectory($controllersPath, $controllersNamespace);

```

### Pravidla pro skener:

1. Rekurzivně prochází všechny podsložky ve složce `app/Controllers/`.
2. Skenuje výhradně soubory končící příponou `Controller.php`.
3. Pokud podsložky existují (např. `app/Controllers/Admin/DashboardController.php`), router automaticky odvodí odpovídající sub-namespace: `App\Controllers\Admin\DashboardController`.
4. Pomocí reflexe najde všechny veřejné metody s atributem dědícím z `Route` a vytvoří instance třídy `Core\Routing\Route`.



## Odkazy

* Předchozí: [Architektura a životní cyklus](architecture.md)
* Následující: [Kontrolery a šablony](controllers-views.md)


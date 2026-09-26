# Kontrolery, šablony a CSRF ochrana

Aplikační kontrolery dědí od abstraktní třídy `Core\Controller\BaseController`. Ta poskytuje jednotné rozhraní pro čtení uživatelských vstupů, renderování HTML šablon, emitování JSON odpovědí a vestavěnou ochranu proti útokům typu Cross-Site Request Forgery (CSRF).


## Metody `BaseController`

### 1. Práce s odpovědí

* **`$this->view(string $template, array $data = []): string`**  
  Vykreslí PHP šablonu z adresáře `app/Views/` v izolovaném rozsahu proměnných.  
  Automaticky do šablony předává pomocné proměnné `$csrf_token` a `$csrf_field`.
* **`$this->json(mixed $data, int $status = 200, array $headers = []): never`**  
  Nastaví HTTP stavový kód, přidá hlavičku `Content-Type: application/json; charset=UTF-8`, serializuje data do JSON a okamžitě ukončí běh skriptu (`exit`).
* **`$this->redirect(string $url, int $status = 302): never`**  
  Odešle hlavičku `Location: $url` s příslušným kódem přesměrování a ukončí provádění.

### 2. Čtení vstupních dat

* **`$this->query(?string $key = null, mixed $default = null): mixed`**  
  Bezpečně získá parametr z URL dotazu (`$_GET`). Pokud je klíč `null`, vrátí celé pole.
* **`$this->input(?string $key = null, mixed $default = null): mixed`**  
  Získá hodnotu z odeslaného formuláře (`$_POST`). Pokud klíč neexistuje, vrátí `$default`.
* **`$this->jsonBody(bool $associative = true): mixed`**  
  Přečte a dekóduje surové tělo požadavku (`php://input`). Vhodné pro příchozí AJAXové JSON payloady.


## CSRF ochrana (Cross-Site Request Forgery)

Framework obsahuje kryptograficky bezpečný mechanismus ochrany proti podvržení požadavku bez závislosti na externích balíčcích.

### 1. Použití ve formuláři (`app/Views/...`)

Metoda `$this->view()` automaticky injektuje do rozsahu šablony dvě proměnné:
* `$csrf_field` – Vygenerovaný skrytý HTML input tag.
* `$csrf_token` – Samotná hodnota tokenu (64znakový hexadecimální řetězec).

```html
<form method="POST" action="/users/store">
    <!-- Vloží skrytý input: <input type="hidden" name="_csrf_token" value="..."> -->
    <?= $csrf_field ?>

    <label for="name">Jméno:</label>
    <input type="text" id="name" name="name" required>

    <button type="submit">Uložit</button>
</form>

```

### 2. Validace v kontroleru

Při zpracování stav měnících požadavků (POST, PUT, DELETE) zavolejte metodu `$this->validateCsrf()`:

```php
namespace App\Controllers;

use Core\Controller\BaseController;
use Core\Routing\Attributes\Post;

class UserController extends BaseController
{
    #[Post('/users/store')]
    public function store(): never
    {
        // Ověří shodu tokenu s hodnotou v session pomocí hash_equals()
        // V případě chyby vyhodí Core\Exceptions\CsrfException (HTTP 403)
        $this->validateCsrf(regenerate: true);

        $name = (string)$this->input('name');
        // Uložení do databáze...

        $this->redirect('/users');
    }
}

```

### 3. Použití přes JavaScript / AJAX

Token lze z šablony předat do hlavičky požadavku:

```javascript
fetch('/users/store', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': '<?= $csrf_token ?>'
    },
    body: JSON.stringify({ name: 'Martin' })
});

```

Metoda `validateCsrf()` automaticky kontroluje nejdříve tělo `$_POST['_csrf_token']` a následně hlavičku `$_SERVER['HTTP_X_CSRF_TOKEN']`.

---

## Odkazy

* Předchozí: [Routování a atributy](https://www.google.com/search?q=routing.md&utm_source=gemini)
* Následující: [Reaktivní Live Komponenty](https://www.google.com/search?q=live-components.md&utm_source=gemini)

```

---

Až bude soubor `doc/controllers-views.md` uložen, napiš a připravíme pátý díl: `doc/live-components.md`.

```
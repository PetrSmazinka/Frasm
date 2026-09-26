# Reaktivní Live Komponenty

Systém reaktivních komponent ve Frasmu umožňuje stavový obousměrný datový binding (Two-way data binding) mezi PHP třídou na backendu a DOM elementem v prohlížeči. Odpadá tak nutnost psát dedikované AJAXové handlery, serializovat odpovědi a ručně manipulovat s DOMem přes JavaScript.


## Architektura a tok dat

Při každé interakci probíhá synchronizační cyklus:

```text
[ Prohlížeč: input/change/click ]
       │
       ▼ (data-model / data-model.lazy / data-action)
[ public/js/frasm-live.js ]
       │  1. Zjistí stav a změny
       │  2. Uloží pozici kurzoru a ID aktivního elementu
       │
       ▼ POST /_frasm/live-component
[ Core\View\Live\LiveComponentHandler ]
       │  1. new $componentClass()
       │  2. hydrate($state, triggerHooks: false)  (obnova původního stavu)
       │  3. hydrate($updates, triggerHooks: true) (nastavení změn + type casting)
       │       └── Spuštění updated{Property}() a updated() hooků
       │  4. Spuštění akce ($component->{$action}())
       │  5. render() -> vygenerování nového HTML s atributem data-frasm-state
       ▼
[ Prohlížeč (frasm-live.js) ]
       │  1. Nahradí původní DOM element novým HTML
       └── 2. Obnoví focus a pozici textového kurzoru

```


## Vytvoření komponenty

Komponenta dědí od `Core\View\Component`. Každá **veřejná vlastnost** (`public`) je automaticky součástí synchronizovaného stavu, který se přenáší mezi klientem a serverem.

```php
namespace App\Components;

use Core\View\Component;

class CalculatorComponent extends Component
{
    public float $amount = 100.0;
    public float $taxRate = 21.0;
    public float $total = 121.0;

    /**
     * 1. Inicializační hook: Mount
     * Volá se pouze jednou při prvním vytvoření v kontroleru.
     */
    public function mount(float $initialAmount = 100.0): void
    {
        $this->amount = $initialAmount;
        $this->calculate();
    }

    /**
     * 2. Reaktivní hook pro konkrétní vlastnost: updated{PropertyName}
     * Zavolá se automaticky, jakmile klient pošle aktualizaci pole 'amount'.
     */
    public function updatedAmount(float $value): void
    {
        $this->amount = $value;
        $this->calculate();
    }

    public function updatedTaxRate(float $value): void
    {
        $this->taxRate = $value;
        $this->calculate();
    }

    /**
     * 3. Akce: volaná kliknutím na prvek s data-action="reset"
     */
    public function reset(): void
    {
        $this->amount = 0.0;
        $this->calculate();
    }

    protected function calculate(): void
    {
        $this->total = round($this->amount * (1 + ($this->taxRate / 100)), 2);
    }

    protected function template(): string
    {
        return FRASM_APP_DIR . '/Views/components/calculator.php';
    }
}

```


## Šablona komponenty (`app/Views/components/...`)

Šablona vykresluje vnitřek komponenty. Obalující `<div>` s metadaty (`data-frasm-component` a `data-frasm-state`) doplní framework automaticky v metodě `render()`.

### Podporované atributy pro vazbu dat:

1. **`data-model="property"` (Live binding):**
Reaguje na událost `input`. Odesílá data na server při každém stisku klávesy s automatickým debounce intervalem **250 ms**.
2. **`data-model.lazy="property"` (Lazy binding):**
Reaguje na událost `change`. Odešle data na backend až ve chvíli, kdy uživatel klikne mimo prvek (blur), stiskne Enter nebo vybere položku v `<select>`.
3. **`data-action="methodName"` (Akce):**
Reaguje na událost `click` na tlačítku nebo odkazu a přímo spustí odpovídající veřejnou metodu v PHP třídě.

```html
<div class="calculator-box">
    <!-- Live binding s udržením ID pro zachování kurzoru -->
    <label for="calc-amount">Základní částka:</label>
    <input 
        type="number" 
        id="calc-amount" 
        data-model="amount" 
        value="<?= htmlspecialchars((string)$amount, ENT_QUOTES, 'UTF-8') ?>"
    >

    <!-- Lazy binding pro výběrové pole -->
    <label for="calc-tax">Sazba DPH:</label>
    <select id="calc-tax" data-model.lazy="taxRate">
        <option value="0" <?= $taxRate === 0.0 ? 'selected' : '' ?>>0 %</option>
        <option value="12" <?= $taxRate === 12.0 ? 'selected' : '' ?>>12 %</option>
        <option value="21" <?= $taxRate === 21.0 ? 'selected' : '' ?>>21 %</option>
    </select>

    <!-- Akční tlačítko -->
    <button type="button" data-action="reset">Vynulovat</button>

    <p>Výsledná cena: <strong><?= $total ?> Kč</strong></p>
</div>

```


## Automatické přetypování (Type Casting)

HTML formuláře vracejí hodnoty vždy jako textové řetězce (`string`). Třída `Component::hydrate()` proto využívá PHP reflexi a hodnoty z JSON payloadu automaticky přetypuje na typ deklarovaný u dané vlastnosti:

* `int` $\rightarrow$ `(int)$value`
* `float` $\rightarrow$ `(float)$value`
* `bool` $\rightarrow$ `filter_var($value, FILTER_VALIDATE_BOOLEAN)` (ošetří checkboxy i stringy `"true"` / `"false"`)
* `string` $\rightarrow$ `(string)$value`
* `array` $\rightarrow$ `(array)$value`

Díky tomu nedochází k fatální chybě `TypeError: Cannot assign string to property ... of type float`.


## Vykreslení v kontroleru

V kontroleru stačí komponentu vytvořit, inicializovat přes `mount()` a předat její HTML do hlavní šablony:

```php
#[Get('/calc')]
public function calc(): string
{
    $calc = new \App\Components\CalculatorComponent();$calc->mount(500.0);

    return $this->view('home/index', [
        'pageTitle' => 'Kalkulačka DPH',
        'content'   => $calc->render(),
    ]);
}

```


## Odkazy

* Předchozí: [Kontrolery a šablony](controllers-views.md)
* Následující: [Výjimky a Error Handling](exceptions.md)

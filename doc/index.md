# Frasm Framework Documentation

**Frasm** je minimalistický, rychlý a moderní PHP framework postavený na standardu PSR-4 a možnostech PHP 8+. Nabízí nulové externí závislosti, deklarativní routování pomocí nativních PHP atributů a vestavěný systém reaktivních live-komponent s obousměrným datovým bindingem.

---

## 📚 Obsah dokumentace

1. [Architektura a životní cyklus](architecture.md)  
   Běh Front Controlleru, adresářová struktura, bootstrapping a konfigurace.

2. [Routování a atributy](routing.md)  
   Registrace tras, reflexe kontrolerů, parametry URL a autorizační role.

3. [Kontrolery a šablony](controllers-views.md)  
   Práce s `BaseController`, HTTP vstupy a vestavěná CSRF ochrana.

4. [Reaktivní Live Komponenty](live-components.md)  
   Obousměrný binding (`data-model`), lifecycle hooky (`mount`, `updated`), akce a synchronizační JS driver.

5. [Výjimky a Error Handling](exceptions.md)  
   Systém hierarchie výjimek, přepínání debug módu a JSON fallbacky.

---

## 🚀 Rychlý start

Spuštění vestavěného vývojového serveru z kořenového adresáře:

```bash
php -S localhost -t public/
```

Vstupním bodem je `public/index.php`. Všechny uživatelské třídy a šablony patří výhradně do složky `app/`.


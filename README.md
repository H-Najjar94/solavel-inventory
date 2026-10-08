# SolaStock — Solavel Inventory

Inventory management application, part of the Solavel suite. Built on Laravel.

## SolaStock demo (Phase 1 — fast preview)

The Claude Design "SolaStock" React demo is integrated as a fast in-browser
preview. It currently runs via CDN React + Babel (no build step).

- Route: `/solastock` → `Route::view('/solastock', 'solastock')`
- View: `resources/views/solastock.blade.php`
- Assets: `public/solastock/` (`.jsx` compiled in-browser by Babel standalone)

### Run it

```bash
php artisan serve
# then open http://127.0.0.1:8000/solastock
```

> Note: a project-root `server.php` router is included. It is required because
> the `/solastock` route URI collides with the `public/solastock/` assets
> directory, which the PHP built-in dev server would otherwise intercept.
> `php artisan serve` picks this up automatically.

## Phase 2 — Vite/React conversion

A plan to convert the CDN/Babel demo into a proper Laravel + Vite + React build
lives in [docs/PHASE2-VITE-REACT-PLAN.md](docs/PHASE2-VITE-REACT-PLAN.md).
Mock data is retained until the UI is fully converted and stable. No database
connection yet.

## Related Solavel apps

- solavel (central)
- solavel-finance
- solavel-hr
- solavel-projects
- solavel-fitness
- solavel-ebill
- solavel-system

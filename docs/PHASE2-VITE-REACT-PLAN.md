# Phase 2 — Convert SolaStock to Laravel + Vite + React

Phase 1 (done) runs the Claude Design demo in-browser via CDN React + Babel
under `public/solastock/`, served at the `/solastock` Blade route. Phase 2
converts it into a real Vite-built React app — no CDN, no in-browser Babel —
while keeping the same route, the same UI, every screen, and the mock data.

## Goals & guardrails

- Move JSX into `resources/js/solastock/`.
- Convert the global-script files into ES modules with explicit `import` / `export`.
- Replace CDN React/ReactDOM/Babel with Vite + `@vitejs/plugin-react`.
- Use `@viteReactRefresh` + `@vite` in the Blade view.
- Keep the Blade route `/solastock` exactly as-is.
- **Do not** connect to a database. **Do not** simplify the dashboard. **Do not**
  remove any screen. **Do not** change the UI design except where a load-order or
  module-resolution issue forces a fix.
- Keep the mock data layer (`DB`, `fmt`) until the UI is fully converted and
  stable. Database wiring is a later phase.

## Current architecture (what we're migrating from)

All files under `public/solastock/` are **plain scripts**, not modules. They rely
on three implicit-global mechanisms, all of which must be replaced:

1. **Global `React`** — every file reads `React`, `ReactDOM` from the CDN UMD
   globals (e.g. `const { useState } = React`). Babel-standalone compiles the JSX
   at runtime.
2. **Implicit global components** — each file declares top-level `function`/`const`
   that land on the shared script scope; later files reference them by bare name.
   Load order in the Blade `<script>` list is load-bearing.
3. **`window.DB` / `window.fmt`** — `data.jsx` wraps an IIFE and assigns the mock
   data + formatters onto `window`.

### Dependency / export map

This is the order the Blade currently loads them (top = first). In modules this
becomes an import graph instead of an ordered script list.

| File | Declares (to export) | Depends on |
|------|----------------------|------------|
| `data.jsx` | `DB`, `fmt` (currently `window.*`) | — |
| `icons.jsx` | `ICONS`, `Icon` | `React` |
| `charts.jsx` | `smoothPath`, `useId`, `AreaChart`, `BarChart`, `DonutChart`, `Gauge`, `Sparkline`, `FlowChart` | `React` |
| `viz.jsx` | `AnimatedNumber`, `MAP_NODES`, `MAP_ROUTES`, `curve`, `NetworkMap`, `CalendarHeatmap` | `React` |
| `components.jsx` | `NAV`, `Sidebar`, `DateFilter`, `TopBar`, `FabDock`, `Toast`, `CardHead`, `StatusBadge`, `Placeholder`, `Page` | `React`, `Icon`/`ICONS` |
| `screens-widgets.jsx` | `MetricWidget`, `ListRow`, `buildWidgets` | `React`, charts, `DB`, `fmt` |
| `screens-dashboard.jsx` | `DEFAULT_LAYOUT`, `Dashboard`, `WIDGET_DESC`, `WidgetGallery` | widgets, charts, `DB`, `fmt`, components |
| `screens-items.jsx` | `FilterSelect`, `ItemsList`, `ItemDetail` | `React`, components, charts, `DB`, `fmt` |
| `screens-warehouse.jsx` | `MiniMap`, `WarehouseOverview` | `React`, components, charts, `DB`, `fmt` |
| `floor.jsx` | `buildRacks`, `STATUS_COLOR`, `rackColor`, `Rack`, `StockFloor`, `ShelfDrawer` | `React`, components, `DB` |
| `screens-orders.jsx` | `POStatusBadge`, `PurchaseOrders`, `StockMovement` | `React`, components, charts, `DB`, `fmt` |
| `screens-misc.jsx` | `SalesOrders`, `PartnerGrid`, `TransfersPage`, `AdjustmentsPage`, `ReportsPage`, `SettingsPage` | `React`, components, charts, `DB`, `fmt` |
| `app.jsx` | `App` + `createRoot().render()` | all screens, `Sidebar`, `TopBar`, `FabDock`, `Toast` |

> `app.jsx` aliases hooks as `aUseState`, `aUseEffect` to dodge global
> collisions — those aliases can go away once each file imports its own hooks.

## Target structure

```
resources/js/solastock/
  data/
    mock.js            # was data.jsx — export const DB, fmt (drop the IIFE + window.*)
  lib/
    icons.jsx          # export { ICONS, Icon }
    charts.jsx         # export { AreaChart, BarChart, DonutChart, Gauge, Sparkline, FlowChart, smoothPath, useId }
    viz.jsx            # export { AnimatedNumber, NetworkMap, CalendarHeatmap, MAP_NODES, MAP_ROUTES, curve }
  components/
    index.jsx          # export { Sidebar, TopBar, DateFilter, FabDock, Toast, CardHead, StatusBadge, Placeholder, Page, NAV }
  screens/
    Dashboard.jsx      # Dashboard, WidgetGallery, DEFAULT_LAYOUT, WIDGET_DESC
    widgets.jsx        # MetricWidget, ListRow, buildWidgets
    Items.jsx          # ItemsList, ItemDetail, FilterSelect
    Warehouse.jsx      # WarehouseOverview, MiniMap
    Floor.jsx          # StockFloor, ShelfDrawer, Rack, ...
    Orders.jsx         # PurchaseOrders, StockMovement, POStatusBadge
    Misc.jsx           # SalesOrders, PartnerGrid, Transfers/Adjustments/Reports/Settings pages
  styles/
    styles.css         # moved from public/solastock/styles.css (imported by app)
    floor.css          # moved from public/solastock/floor.css
  app.jsx              # App shell + createRoot; imports everything; entry for Vite
```

`@vitejs/plugin-react` provides JSX + Fast Refresh, so files can keep the `.jsx`
extension and JSX syntax with no in-browser Babel.

## Step-by-step

### 1. Tooling
- `npm i -D @vitejs/plugin-react` and `npm i react react-dom` (pin to 18.3.1 to
  match the demo).
- In `vite.config.js`: add `react()` to plugins and register the entry in
  `laravel({ input: ['resources/js/solastock/app.jsx', ...] })`.

### 2. Data layer (`data.jsx` → `data/mock.js`)
- Remove the `(function(){ ... })()` IIFE wrapper and the `window.DB` / `window.fmt`
  assignments.
- `export const DB = { ... }` and `export const fmt = { ... }`.
- This file has **no React dependency** — keep it framework-free so it can later
  be swapped for API calls without touching components.

### 3. Convert each script to a module (bottom-up: data → lib → components → screens → app)
For every file:
- Add `import React, { useState, useEffect, useRef } from 'react';` (and drop the
  `const { useState } = React` / `aUseState` aliasing).
- Add `import { ICONS, Icon } from '../lib/icons.jsx';` etc. for whatever it used
  to read as a global (see the dependency map).
- Add `import { DB, fmt } from '../data/mock.js';` wherever `DB`/`fmt` were used.
- Replace each top-level `function X` / `const X` that other files consume with a
  named `export`.
- Do **not** rename components or change their props/markup — only add import/export.

### 4. App entry (`app.jsx`)
- Import all screen components, `Sidebar`, `TopBar`, `FabDock`, `Toast`, plus the
  two CSS files (`import './styles/styles.css'`).
- Keep the `App` component body verbatim (routing switch, theme, toast, FAB).
- Keep `ReactDOM.createRoot(document.getElementById('root')).render(<App />)` —
  but `import { createRoot } from 'react-dom/client'`.

### 5. Blade view
Replace the CDN/Babel block in `resources/views/solastock.blade.php`:
- **Remove** the three `unpkg.com` `<script>` tags and all `type="text/babel"`
  script tags.
- **Remove** the two `<link rel="stylesheet" href="{{ asset('solastock/*.css') }}">`
  (CSS now imported through Vite).
- **Keep** the inline `data-theme` boot script in `<head>` (prevents theme flash).
- **Keep** `<div id="root"></div>`.
- Add in `<head>`:
  ```blade
  @viteReactRefresh
  @vite('resources/js/solastock/app.jsx')
  ```

### 6. Route
No change. `Route::view('/solastock', 'solastock')->name('solastock.demo')` stays.

### 7. Retire Phase-1 artifacts (only after Phase 2 verifies green)
- Delete `public/solastock/` (CDN/Babel copies).
- Delete the project-root `server.php` router — it only existed to dodge the
  `/solastock` route vs `public/solastock/` directory collision under the PHP
  built-in dev server. With assets served by Vite there is no longer a colliding
  directory, so `php artisan serve` works unaided.

## Dev & build commands
- Dev: `npm run dev` (Vite HMR) + `php artisan serve`, open `/solastock`.
- Prod: `npm run build` (emits hashed assets to `public/build/`); `@vite` picks
  up the manifest automatically.

## Verification (same bar as Phase 1)
Re-run the headless browser check against `/solastock` and confirm:
- Dashboard mounts; sidebar nav (13 items) works; light/dark toggle flips
  `data-theme`; all widgets render; Items / Warehouses / Purchase Orders /
  Sales Orders / Stock Movements screens open; **zero** console errors; **zero**
  failed JS/CSS requests.
- Visual diff (light + dark screenshots) against the Phase 1 captures — pixel
  parity expected since no design changes are intended.

## Risks / watch-outs
- **Load-order assumptions**: the demo relied on script ordering; the import graph
  must mirror the dependency map above or you'll get `undefined`-component errors.
- **Duplicate helper names**: `useId` (charts) vs React 18's built-in `useId` —
  keep the local one under its module name; don't auto-import React's.
- **`window.*` leftovers**: grep for `window.DB` / `window.fmt` / bare global
  component refs after conversion; any remaining one means a missed import.
- **CSS specificity**: importing CSS via Vite changes injection order vs the old
  `<link>` tags — verify theme variables still cascade (check `data-theme`
  selectors in `styles.css`).

# CLAUDE.md

## Project Properties
- **Plugin Name:** Bricks Tools
- **Description:** A collection of Bricks Tools
- **Version:** 0.0.1
- **Minimum WordPress:** 6.5
- **Minimum PHP:** 8.0
- **PHP Namespace:** `AB\BricksTools`
- **Constants Prefix:** `ABBTL_`
- **Textdomain:** `ab-bricks-tools`
- **REST API Namespace:** `abbtl/v1`
- **Database Option / Table Prefix:** `abbtl_`
- **JS Global:** `ABBTL` (window.ABBTL)
- **CSS Prefix:** `.abbtl-`

## Naming — 5-Character Minimum on All Prefixes

To avoid collisions with sibling plugins in the `ab-bricks-*` family, every prefix / slug / namespace segment introduced by this plugin **must be at least 5 characters** of distinguishable identifier.

- `ABBTL_` (constants), `abbtl_` (DB/options), `abbtl/v1` (REST), `ABBTL` (JS global), `.abbtl-` (CSS) — all 5 chars.
- PHP namespace (`AB\BricksTools`) and textdomain (`ab-bricks-tools`) already exceed 5 chars and are unique.
- When adding any NEW prefix or hook tag, keep the 5-char minimum. Don't introduce 3- or 4-character prefixes — they're conflict-prone.

## Description

The **Bricks Tools** plugin is a collection of Bricks Tools. Specific features are added under separate PRs and documented in `CHANGELOG.md` as they ship.

## Autoloading — Composer PSR-4, `vendor/` Committed

PHP autoloading is **Composer PSR-4**, configured in `composer.json`:

```json
"autoload": { "psr-4": { "AB\\BricksTools\\": "src/" } }
```

- `vendor/` is **committed to the repo** so end users get a working plugin from a `git clone` or zip extract — no `composer install` required at install time.
- Developers must run `composer install` once after cloning, and `composer dump-autoload -o` after adding/renaming a class. Use `-o` (classmap optimization) for production builds.
- The plugin bootstrap (`ab-bricks-tools.php`) requires `vendor/autoload.php` and surfaces a clear admin notice if vendor is missing (rather than fataling).
- Never hand-roll a second `spl_autoload_register` for plugin classes — let Composer own autoloading.

## Modular Architecture

The plugin is a host for **modules**. Each module is a self-contained feature that the admin can enable or disable from a WP Admin page.

### Module Folder vs. Module Slug

There are TWO names for every module — keep them distinct:

| | Format | Used for |
|---|---|---|
| **Folder name** | PascalCase (e.g. `BricksFormManager`) | PSR-4 autoload — must match the namespace segment |
| **Slug** | kebab-case (e.g. `bricks-form-manager`) | Storage key in `abbtl_modules`, REST URL segment, CSS hooks, JS state keys |

The `Registrar` uses the folder name to build the FQCN, then instantiates the class and asks `getSlug()` for the canonical kebab-case slug. Module slugs MUST be ≥5 characters.

### Module Discovery — Auto-Discover Folders

Modules live in `src/Modules/{PascalCaseFolder}/Module.php`. The central `Registrar` (`src/Modules/Registrar.php`) auto-discovers them at boot — no manual registration call required. Drop a folder in, the module appears.

- The `Registrar` scans `src/Modules/*` for any subfolder containing `Module.php`.
- Each `Module.php` class implements `ModuleInterface` (`src/Modules/ModuleInterface.php`) exposing:
  - `getSlug(): string` — kebab-case unique identifier (≥5 chars)
  - `getName(): string` — human-readable display name
  - `getVersion(): string` — semver, **starting from `1.0.0`** for every new module (independent of plugin version)
  - `getDescription(): string` — one-line description shown in the admin UI
  - `boot(): void` — wires up the module's hooks/filters; only called when the module is enabled
- Modules MUST NOT register any WordPress hooks until `boot()` is invoked. Static side-effects at class-load time fire even for disabled modules and are banned.

### Enabled-State Storage — Single `abbtl_modules` Option

Module enabled-state lives in **one** `wp_options` row:

- Option key: `abbtl_modules`
- Value: `['module-slug' => true|false, ...]`
- Autoload: `true` (read on every admin pageload, but only one DB hit)
- Default for an unknown / newly-discovered slug: **disabled** (`false`). New modules must be explicitly turned on by the admin.

The `Registrar` is the only code that reads/writes this option. Modules ask `Registrar::isEnabled($slug)` rather than reading the option directly.

### Admin UI — AlpineJS, No Build Step

The admin settings page uses **AlpineJS** with PHP-rendered HTML. No npm / Vite / build pipeline for v1.

- AlpineJS is enqueued only on the plugin's admin page (gate by current screen / hook suffix). Never on `wp_enqueue_scripts` and never globally in admin.
- The Alpine library file lives at `assets/js/alpine.min.js` — drop the official Alpine 3.x build there.
- The admin page is a single PHP template that loops the discovered modules and renders a row per module with: name, version, description, and an enable/disable toggle bound to Alpine state.
- Toggling persists via a REST endpoint under `abbtl/v1` (`POST abbtl/v1/modules/{slug}/enabled`) — gated on `manage_options` capability + `X-WP-Nonce` (wp_rest nonce).

### Optional Module Admin Page — `HasAdminPage` Interface

Modules that need their own WP Admin submenu under "Bricks Tools" implement `\AB\BricksTools\Modules\HasAdminPage`:

```php
interface HasAdminPage {
    public function renderAdminPage(): void;
}
```

- The submenu is registered by `AdminPage::addMenu()` only when **both** conditions hold: the module is enabled in `abbtl_modules` AND the module class `instanceof HasAdminPage`.
- Submenu slug is `abbtl-modules-{module-slug}`.
- Submenu label uses `getName()`.
- Capability is `manage_options`. The wrapper render closure re-asserts `current_user_can('manage_options')` before invoking the module's `renderAdminPage()`.
- Disabling the module removes the submenu on the next pageload.

Modules that don't need their own page (utility/passive modules) just implement `ModuleInterface` and skip `HasAdminPage`.

### Scope for v1 — Just On/Off

Modules expose **only** name / version / description / enabled state for v1. No per-module settings panels yet. When a module legitimately needs settings, we'll design the settings-panel contract at that point — don't pre-build it.

## File Layout

```
ab-bricks-tools.php           # Plugin header + bootstrap (loads vendor/autoload.php, inits Plugin)
composer.json                 # PSR-4 autoload map
vendor/                       # Composer-generated, committed
src/
  Plugin.php                  # Top-level orchestrator (singleton)
  Admin/
    AdminPage.php             # Top-level "Bricks Tools" menu + asset enqueue
  REST/
    Controller.php            # abbtl/v1 routes
  Modules/
    ModuleInterface.php       # Contract every module implements
    Registrar.php             # Auto-discovery, enabled-state, boot orchestration
    {PascalCaseFolder}/
      Module.php              # Implements ModuleInterface
templates/
  admin-page.php              # Alpine-driven modules list
assets/
  js/alpine.min.js            # Alpine 3.x runtime (admin page only)
  css/admin.css               # Admin styles
```

# Bricks Tools

A modular WordPress plugin that hosts utilities for [Bricks Builder](https://bricksbuilder.io/). Each feature is a self-contained module that the site admin can enable or disable from a single WP Admin page — disabled modules load no code beyond their identifier, so the plugin's footprint scales with what you actually use.

> **Free.** Brought to you by **[BRXProd](https://brxprod.com/)** — the most advanced productivity suite for Bricks Builder.

---

## Requirements

- WordPress 6.5+
- PHP 8.0+
- Bricks Builder (theme installed; activation optional for some features)

## Installation

This repo ships a committed `vendor/` directory, so end users can install it like any other plugin (clone or zip → `wp-content/plugins/ab-bricks-tools/` → activate).

Developers should run `composer install` once after cloning if `vendor/` is ever absent:

```bash
composer install --no-dev -o
```

## Included modules

### Bricks Form Manager

Find every Bricks Core Form and BricksForge Pro Form across pages, posts, and Bricks templates. Inline-edit From, To, CC, Reply-To, Subject, Success Message and Error Message with double-click. Filter by form type, search by email address across To / From / Reply-To. Changes write straight back to the element's settings in the post's Bricks meta.

### Bricks Class & Variable Finder

Pick any Bricks Global Class or Global Variable and see exactly which elements on which pages use it. Filter the picker by kind (All / Classes / Variables) and search by name. Results paginated at 100 per page, with deep links into the Bricks Builder (`?bricks=run&brx_element=<id>`) that open in a new tab.

## How modules work

Modules live in `src/Modules/{PascalCaseFolder}/Module.php`. The host's `Registrar` auto-discovers them at boot — drop a folder in, the module appears in the admin list. Each module implements:

```php
interface ModuleInterface {
    public function getSlug(): string;        // kebab-case, ≥5 chars
    public function getName(): string;
    public function getVersion(): string;     // starts at 1.0.0 per module
    public function getDescription(): string;
    public function boot(): void;             // only invoked when enabled
}
```

Modules that need their own WP Admin submenu also implement the optional `HasAdminPage` interface (`renderAdminPage()`).

Enabled state is stored in a single `wp_options` row (`abbtl_modules`) keyed by slug.

## WP-CLI dispatch

Heavy scans (Form Manager, Class/Variable Finder) auto-route through WP-CLI when it's available, falling back to in-process PHP otherwise. The `WpCli` system class probes:

1. `wp` on PATH
2. Local by Flywheel's bundled `wp-cli.phar` + newest `lightning-services/php-*`, paired with the current site's rendered `php.ini` (carries the per-site MySQL port override)

The WP-CLI path runs `wp eval-file ... --skip-plugins --skip-themes`, which is faster than the in-process scan on large sites because Bricks/BricksForge never boot — only WordPress core and `$wpdb`. On small sites the spin-up cost dominates and PHP is faster; both paths produce identical results.

## Development

Code layout:

```
ab-bricks-tools.php              Plugin bootstrap — loads vendor/autoload.php
composer.json                    PSR-4 autoload map: AB\BricksTools\ → src/
vendor/                          Composer-generated, committed
src/
  Plugin.php                     Top-level orchestrator
  Admin/
    AdminPage.php                Top-level menu + per-module submenu wiring
    Layout.php                   Two-column admin shell (main + BRXProd advert)
  REST/
    Controller.php               abbtl/v1 base routes
  System/
    WpCli.php                    Runtime detection + buildCommand()
  Modules/
    ModuleInterface.php
    HasAdminPage.php             Optional — module owns a submenu page
    Registrar.php                Auto-discover + enable/disable
    BricksFormManager/
    BricksClassVariableFinder/
templates/admin-page.php
assets/{css,js,img}/
```

Conventions are documented in [CLAUDE.md](./CLAUDE.md) — naming prefixes (5-char minimum), folder vs slug split, modular architecture rules, etc.

## License

Proprietary. © Alan Blair.

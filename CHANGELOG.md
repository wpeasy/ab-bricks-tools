# Changelog

All notable changes to this project will be documented in this file.
The format is loosely based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [0.0.2] - 2026-05-30

### Added

- GitHub-based auto-update via `yahnis-elsts/plugin-update-checker` v5.7. The
  plugin queries this public repo for new releases and prefers the attached
  release-asset zip over GitHub's auto-generated source tarball.

## [0.0.1] - 2026-05-30

### Added

- Plugin scaffold with Composer PSR-4 autoloading and committed `vendor/`.
- Module Registrar: auto-discovers modules in `src/Modules/{PascalCaseFolder}/`,
  stores enable/disable state in a single `abbtl_modules` option.
- Optional `HasAdminPage` interface for modules that own a WP Admin submenu.
- Two-column admin shell with BRXProd advert panel using on-brand colors.
- WP-CLI runtime detection: probes system `wp` first, then Local-by-Flywheel's
  bundled `wp-cli.phar` + newest `lightning-services/php-*` paired with the
  current site's rendered `php.ini`.
- **Bricks Form Manager** module — finds every Bricks Core Form and BricksForge
  Pro Form across pages, posts, and Bricks templates; inline-edit From, To, CC,
  Reply-To, Subject, Success Message, and Error Message; filter by type, search
  by email address.
- **Bricks Class & Variable Finder** module — filterable picker over Bricks
  Global Classes and Variables; paginated usage table (100/page) with deep
  links into the Builder via `?bricks=run&brx_element=`.

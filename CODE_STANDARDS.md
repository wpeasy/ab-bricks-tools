# Code Standards

Project-wide coding standards for consistency and maintainability across all code.

---

## Related Documentation

- **CLAUDE.md** - Project properties, namespaces, prefixes
- **SVELTE5_IMPLEMENTATION.md** - Svelte 5 patterns and runes (required reading)
- **WORDPRESS.md** - Plugin header template
- **lib/wpeasy-admin-framework/CLAUDE.md** - UI framework and components

---

## General Principles

1. **Consistency over preference** - Match existing patterns in the codebase
2. **Explicit over implicit** - Clear, readable code over clever shortcuts
3. **DRY but not premature** - Extract only after 3+ repetitions
4. **Comments explain "why"** - Code should explain "what"
5. **Fail fast, fail loudly** - Validate early, throw meaningful errors

---

## File Organization

```
plugin-name/
├── plugin-name.php              # Main plugin file (header from WORDPRESS.md)
├── uninstall.php                # Cleanup on uninstall
├── composer.json                # PSR-4 autoloader
├── package.json                 # Vite + Svelte 5
├── vite.config.js
├── CLAUDE.md                    # Project properties (source of truth)
│
├── src/                         # PHP classes (namespace from CLAUDE.md)
│   ├── Plugin.php               # Bootstrap class
│   ├── Admin/                   # Admin-only functionality
│   ├── REST/                    # REST API controllers
│   ├── Models/                  # Database models
│   ├── Services/                # Business logic
│   └── Traits/                  # Shared traits
│
├── src-svelte/                  # Svelte 5 source
│   ├── admin-main.ts            # Admin entry point
│   ├── stores/                  # State management
│   ├── components/              # Reusable components
│   └── lib/                     # Utilities
│
├── lib/                         # Shared libraries
│   └── wpeasy-admin-framework/  # UI framework
│
├── assets/
│   └── dist/                    # Vite build output (gitignored)
│
├── templates/                   # PHP templates
│   ├── admin/
│   └── public/
│
└── languages/                   # Translation files
```

---

## Naming Conventions

### Files

| Type | Convention | Example |
|------|------------|---------|
| PHP Classes | PascalCase | `RulesController.php` |
| PHP Traits | PascalCase + Trait suffix | `SingletonTrait.php` |
| Svelte Components | PascalCase | `RuleEditor.svelte` |
| TypeScript | camelCase | `apiClient.ts` |
| CSS/SCSS | kebab-case | `admin-styles.css` |
| Config files | lowercase | `vite.config.js` |

### Code

| Language | Variables | Functions/Methods | Classes | Constants |
|----------|-----------|-------------------|---------|-----------|
| PHP | `$snake_case` | `snake_case()` | `PascalCase` | `UPPER_SNAKE` |
| TypeScript | `camelCase` | `camelCase()` | `PascalCase` | `UPPER_SNAKE` |
| CSS | `--kebab-case` | N/A | `.kebab-case` | N/A |

### Prefixes (from CLAUDE.md)

All project-specific identifiers must use the prefix defined in CLAUDE.md:

- **PHP Constants**: `{PREFIX}_PLUGIN_PATH`, `{PREFIX}_VERSION`
- **Database Tables**: `{wp_prefix}_{prefix}_tablename`
- **REST Routes**: `/{namespace}/v1/endpoint`
- **Options**: `{prefix}_settings`
- **Transients**: `{prefix}_cache_key`
- **Hooks**: `{prefix}_action_name`, `{prefix}_filter_name`

---

## PHP Standards

### WordPress Coding Standards

Follow [WordPress PHP Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/) with these specifics:

```php
<?php
/**
 * Class description.
 *
 * @package AB\PluginName
 * @since   1.0.0
 */

declare(strict_types=1);

namespace AB\PluginName;

defined('ABSPATH') || exit;

/**
 * Brief class description.
 *
 * Longer description if needed.
 */
final class ExampleClass {

    /**
     * Property description.
     *
     * @var string
     */
    private string $property;

    /**
     * Method description.
     *
     * @param string $param Parameter description.
     * @return bool Return value description.
     */
    public function example_method(string $param): bool {
        return true;
    }
}
```

### Requirements

1. **ABSPATH Check**: Every PHP file must start with `defined('ABSPATH') || exit;`
2. **Strict Types**: Use `declare(strict_types=1);` in all files
3. **Type Hints**: Use parameter and return type hints everywhere
4. **PSR-4 Autoloading**: All classes via Composer autoloader
5. **Final Classes**: WordPress hook integration classes should be `final`

### Class Structure Pattern

Use static methods for WordPress hook integration classes. This pattern is simpler, stateless, and appropriate for most WordPress plugin classes.

```php
final class ServiceClass {

    /**
     * Initialize hooks.
     */
    public static function init(): void {
        add_action('init', [self::class, 'on_init']);
        add_action('admin_menu', [self::class, 'register_menu']);
    }

    /**
     * Handle init action.
     */
    public static function on_init(): void {
        // Initialization logic
    }

    /**
     * Register admin menu.
     */
    public static function register_menu(): void {
        // Menu registration logic
    }
}
```

**When to use static methods:**
- Hook registration/bootstrap classes
- Controllers that wrap WordPress APIs
- Stateless utility classes

**When to consider alternatives:**
- Classes needing expensive cached computations (use static properties)
- Classes requiring unit test mocking (consider dependency injection)

### DocBlocks

Required for:
- All classes (with `@package` and `@since`)
- All public/protected methods
- Complex private methods
- Properties with non-obvious types

Not required for:
- Simple getters/setters with clear names
- Overridden methods (use `@inheritdoc`)

---

## JavaScript / TypeScript Standards

### General Rules

1. **TypeScript Required**: All new JavaScript must be TypeScript
2. **Svelte 5 Only**: Use runes, not Svelte 4 patterns (see SVELTE5_IMPLEMENTATION.md)
3. **ES Modules**: Use ESM format (requires WordPress 6.5+)
4. **No jQuery**: Never use jQuery unless required for legacy integration
5. **Strict Mode**: Enable strict TypeScript checks

### TypeScript Configuration

```json
{
  "compilerOptions": {
    "strict": true,
    "noImplicitAny": true,
    "noImplicitReturns": true,
    "noUnusedLocals": true,
    "noUnusedParameters": true
  }
}
```

### Svelte Components

See **SVELTE5_IMPLEMENTATION.md** for detailed patterns. Key rules:

1. **Reuse Components**: Check libraries first (WPEA → project → external)
2. **Props Interface**: Define TypeScript interfaces for complex props
3. **State Exposure**: Expose app state on `window.{ConstantsPrefix}` for debugging

```svelte
<script lang="ts">
  import type { Rule } from '../types';

  interface Props {
    rule: Rule;
    onSave: (rule: Rule) => void;
  }

  let { rule, onSave }: Props = $props();
  let editing = $state(false);
</script>
```

### UI State Persistence (Required)

UI preferences (theme, compact mode, collapsed sections) MUST be persisted using this two-tier approach:

| Storage | Purpose | When Used |
|---------|---------|-----------|
| `localStorage` | Instant load, device-level cache | Always (for speed) |
| WordPress user meta | Cross-device sync, source of truth | When user is logged in |

**Implementation pattern:**

1. **PHP loads from user meta** and passes settings via `{prefix}Data.settings`
2. **JS syncs to localStorage** on init (keeps localStorage in sync with user meta)
3. **On setting change**, JS updates both:
   - `localStorage` immediately (for instant persistence)
   - User meta via REST API (for cross-device sync)
4. **On page load**, PHP provides user meta settings, JS caches to localStorage

**PHP - Load from user meta:**
```php
private function get_user_settings(?int $user_id = null): array {
    $user_id  = $user_id ?? get_current_user_id();
    $defaults = $this->get_default_settings();

    if (!$user_id) {
        return $defaults;
    }

    $settings = get_user_meta($user_id, '{prefix}_settings', true);
    if (!is_array($settings)) {
        $settings = [];
    }

    return wp_parse_args($settings, $defaults);
}

private function save_user_settings(array $settings, ?int $user_id = null): bool {
    $user_id = $user_id ?? get_current_user_id();
    return $user_id ? (bool) update_user_meta($user_id, '{prefix}_settings', $settings) : false;
}
```

**JS - Dual persistence:**
```typescript
const STORAGE_KEY = '{prefix}_settings';

function saveSettingsToStorage(settings: Settings): void {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(settings));
  } catch { /* localStorage not available */ }
}

function loadSettingsFromStorage(): Settings | null {
  try {
    const stored = localStorage.getItem(STORAGE_KEY);
    return stored ? JSON.parse(stored) : null;
  } catch { return null; }
}

// On setting change:
function setSetting(key: string, value: unknown): void {
  settings[key] = value;
  saveSettingsToStorage(settings);        // Instant local persistence
  api.post('/settings', settings);         // Sync to user meta (debounced)
  events.emit('settings:changed', { key, value });
}
```

**Key principles:**
- User meta is the source of truth (syncs across devices)
- localStorage is a cache for speed (no flash on page load)
- PHP always provides settings from user meta on page load
- JS syncs localStorage with user meta on init
- Settings changes save to both localStorage AND user meta

---

## Modular Svelte Architecture

### Directory Structure

```
src-svelte/
├── apps/                    # Separate Svelte applications
│   ├── settings/            # Admin settings app
│   │   ├── main.ts          # Entry point
│   │   ├── App.svelte       # Root component
│   │   └── tabs/            # Tab components
│   ├── widget/              # Dashboard widget (example)
│   │   └── main.ts
│   └── frontend/            # Public-facing module (example)
│       └── main.ts
│
├── shared/                  # Common utilities (builds to window.{PREFIX})
│   ├── index.ts             # Main export → window.{PREFIX}
│   ├── api.ts               # REST API client
│   ├── events.ts            # Event bus for cross-module communication
│   └── utils.ts             # Common utilities
│
├── components/              # Shared Svelte components
│   └── ...
│
└── stores/                  # Shared Svelte stores
    └── ...

assets/dist/
├── shared.js                # IIFE → window.{PREFIX} (load first)
├── settings/
│   ├── main.js              # ES module
│   └── style.css
├── widget/
│   └── main.js
└── frontend/
    └── main.js
```

### Build Commands

| Command | Description |
|---------|-------------|
| `npm run build` | Build all modules (shared + all apps) |
| `npm run build:shared` | Build shared utilities only |
| `npm run build:settings` | Build settings app only |
| `npm run build:clean` | Clean dist and rebuild all |
| `npm run watch:settings` | Watch and rebuild settings app |

### Shared Module (window.{PREFIX})

The shared module is built as an IIFE and exposes utilities on `window.{PREFIX}`. It must be loaded before any app modules.

```typescript
// Available on window.{PREFIX} after shared.js loads
window.{PREFIX}.api.get('/settings');      // REST API client
window.{PREFIX}.api.post('/settings', data);

window.{PREFIX}.emit('settings:changed', { key, value }); // Emit event
window.{PREFIX}.on('theme:changed', (detail) => { ... }); // Subscribe

window.{PREFIX}.utils.debounce(fn, 500);   // Utility functions
window.{PREFIX}.utils.deepMerge(a, b);
```

### Event Bus

Use custom events for cross-module communication. Events are dispatched on `document` and prefixed with the plugin namespace.

```typescript
// Define event types in shared/events.ts
export interface EventMap {
  'settings:changed': { key: string; value: unknown };
  'settings:saved': { success: boolean };
  'theme:changed': { mode: 'light' | 'dark' | 'auto' };
  'notification:show': { type: 'success' | 'error'; message: string };
}

// Emit events
window.{PREFIX}.emit('settings:changed', { key: 'theme', value: 'dark' });

// Subscribe to events
const subscription = window.{PREFIX}.on('theme:changed', (detail) => {
  console.log('Theme changed to:', detail.mode);
});

// Unsubscribe when done
subscription.unsubscribe();
```

### Adding New Modules

1. Create directory: `src-svelte/apps/{module-name}/`
2. Create entry point: `src-svelte/apps/{module-name}/main.ts`
3. Create Vite config: `vite.config.{module-name}.ts`
4. Add build script to `package.json`
5. Update `build` script to include new module
6. Enqueue in PHP with shared.js as dependency

```typescript
// vite.config.{module-name}.ts
import { defineConfig } from 'vite';
import { svelte } from '@sveltejs/vite-plugin-svelte';

export default defineConfig({
  plugins: [svelte()],
  build: {
    outDir: 'assets/dist/{module-name}',
    emptyOutDir: true,
    cssCodeSplit: false,
    rollupOptions: {
      input: 'src-svelte/apps/{module-name}/main.ts',
      output: {
        entryFileNames: 'main.js',
        assetFileNames: '[name][extname]',
        format: 'es',
      },
    },
  },
});
```

### PHP Enqueue Pattern

Always load shared.js first, then app modules:

```php
// 1. Enqueue shared utilities (IIFE)
wp_enqueue_script(
    '{prefix}-shared',
    PLUGIN_URL . 'assets/dist/shared.js',
    [],
    VERSION,
    false // Load in head
);

// 2. Output initialization data (with inline theme to prevent flash)
add_action('admin_head', function() {
    // Get settings and apply theme INLINE to prevent flash of wrong theme
    $settings   = get_option('{prefix}_settings', []);
    $theme_mode = $settings['display']['themeMode'] ?? 'auto';

    $color_scheme = match ($theme_mode) {
        'light' => 'light only',
        'dark'  => 'dark only',
        default => 'light dark',
    };

    $styles = 'body { color-scheme: ' . esc_attr($color_scheme) . '; }';

    // Style WordPress admin elements outside Svelte apps for light/dark mode
    $styles .= ' .wrap { color: light-dark(#1e1e1e, #f0f0f0); }';
    $styles .= ' .wrap h1 { color: light-dark(#1e1e1e, #f0f0f0); }';

    echo '<style>' . $styles . '</style>';

    // Pass settings to JS so modules don't need to fetch on load
    $data = [
        'apiUrl'   => rest_url('{namespace}/v1'),
        'nonce'    => wp_create_nonce('wp_rest'),
        'version'  => VERSION,
        'settings' => $settings,
    ];
    echo '<script>window.{prefix}Data = ' . wp_json_encode($data) . ';</script>';
}, 5);

// 3. Enqueue app module (ES module)
wp_enqueue_script_module(
    '{prefix}-settings-app',
    PLUGIN_URL . 'assets/dist/settings/main.js',
    [],
    VERSION
);
```

### Shared Module Theme Management

The shared module (`window.{PREFIX}`) must provide centralized theme management:

```typescript
// Shared module exposes theme API
window.{PREFIX}.theme.get();        // Get current theme mode
window.{PREFIX}.theme.set(mode);    // Set theme + emit event + cache in localStorage
window.{PREFIX}.theme.apply(mode);  // Apply theme to document

window.{PREFIX}.settings;           // Current settings from PHP
```

**Theme flow:**
1. PHP outputs inline `<style>` with `color-scheme` before page renders (no flash)
2. PHP passes settings via `{prefix}Data.settings`
3. Shared module applies theme on init and caches in localStorage
4. Components use `window.{PREFIX}.theme.set()` to change theme
5. Theme persists across all modules via shared state

**Components should:**
- Initialize state from `window.{PREFIX}.settings` (not API call)
- Use `window.{PREFIX}.theme.set()` for theme changes
- Only call API to save settings, not to load them

### Shared Module Density Management (Compact Mode)

The shared module provides centralized density/compact mode management:

```typescript
// Shared module exposes density API
window.{PREFIX}.density.get();           // Get current compact mode state
window.{PREFIX}.density.set(compact);    // Set compact mode + emit event + apply to DOM
window.{PREFIX}.density.apply(compact);  // Apply density to .wpea containers
```

**Density flow:**
1. PHP outputs inline `<style>` with `.wpea { --wpea-density: compact; }` if enabled
2. PHP passes settings via `{prefix}Data.settings`
3. Shared module applies density on init
4. Components use `window.{PREFIX}.density.set()` to change compact mode
5. WPEA framework responds to `--wpea-density: compact` via container style queries

**PHP inline styles (prevents flash):**
```php
$compact_mode = $settings['display']['compactMode'] ?? false;
if ($compact_mode) {
    echo '<style>.wpea { --wpea-density: compact; }</style>';
}
```

**JavaScript density management:**
```typescript
function applyDensity(compact: boolean): void {
  const containers = document.querySelectorAll('.wpea');
  containers.forEach((el) => {
    if (compact) {
      (el as HTMLElement).style.setProperty('--wpea-density', 'compact');
    } else {
      (el as HTMLElement).style.removeProperty('--wpea-density');
    }
  });
}
```

**Components should:**
- Initialize state from `window.{PREFIX}.settings` (not API call)
- Use `window.{PREFIX}.density.set()` for compact mode changes
- Only call API to save settings, not to load them

---

## CSS Standards

### WPEasy Admin Framework

All admin UI must use the WPEasy Admin Framework:

1. **Root Container**: Apply `.wpea` class to root elements
2. **CSS Variables**: Use `--wpea-*` variables exclusively
3. **Components**: Use framework components before custom CSS

```css
/* Use framework variables */
.my-component {
  padding: var(--wpea-space-4);
  background: var(--wpea-surface-1);
  border-radius: var(--wpea-radius-m);
  color: var(--wpea-text-1);
}

/* Never hardcode colors - breaks dark mode */
.my-component {
  background: #ffffff;  /* WRONG */
}
```

### Always Target IDs or Classes, Never Bare HTML Elements

**NEVER style bare HTML elements (like `button`, `div`, `input`) without a class or ID selector.** Unscoped element selectors leak globally and can break third-party components like WordPress media modals, admin panels, and other plugins.

```css
/* WRONG: Bare element selector - affects ALL buttons on the page */
button {
  color: var(--wpea-surface--text-muted);
}

/* WRONG: Even with :global() in Svelte, this is dangerous */
:global(button) {
  color: #fff;
}

/* CORRECT: Always scope with a class */
.my-button {
  color: var(--wpea-surface--text-muted);
}

/* CORRECT: Use parent scoping with :global() in Svelte */
.my-container :global(.some-lib-button) {
  color: #fff;
}
```

**Why this matters:**
- WordPress loads many components (media modal, admin notices, etc.) that use native element styling
- Setting `color-scheme: dark` on body affects default colors for ALL buttons on the page
- Unscoped element selectors in compiled Svelte CSS affect the entire document
- This includes Svelte's `:global()` modifier when used on bare elements

### Never Use `!important`

**NEVER use `!important` in CSS.** It breaks the natural cascade and makes styles impossible to override without more `!important` declarations, leading to specificity wars.

```css
/* WRONG: Using !important */
.my-button {
  color: #fff !important;
}

/* CORRECT: Increase specificity if needed */
.structure-item .my-button {
  color: #fff;
}

/* Or use more specific selectors */
#bricks-panel .structure-item .my-button {
  color: #fff;
}
```

**If a style isn't applying:**
1. Check browser DevTools to see what's overriding it
2. Increase selector specificity by adding parent selectors
3. Ensure your CSS loads after the conflicting styles
4. Never reach for `!important` as a quick fix

### Spacing with Flex and Gap (Required)

**NEVER use margins on headings or child elements for spacing.** Instead, use `flex-direction: column` with `gap` on parent containers. This follows the WPEA framework pattern and provides consistent, maintainable spacing.

```css
/* CORRECT: Use flex column with gap */
.my-section {
  display: flex;
  flex-direction: column;
  gap: var(--wpea-space--md);
}

.my-section h3 {
  font-size: var(--wpea-text--lg);
  font-weight: 600;
  /* NO margin - spacing comes from parent gap */
}

.my-section p {
  line-height: 1.6;
  /* NO margin - spacing comes from parent gap */
}

/* WRONG: Using margins for spacing */
.my-section h3 {
  margin: 0 0 var(--wpea-space--md) 0;  /* WRONG */
}

.my-section p {
  margin-bottom: var(--wpea-space--md);  /* WRONG */
}
```

**Benefits of flex/gap over margins:**
- Avoids margin collapsing issues
- Easier responsive design
- Automatically scales with density changes
- Provides consistent visual rhythm
- Use WPEA utility classes: `.wpea-stack`, `.wpea-stack--sm`, `.wpea-stack--lg`

### BEM Naming (Required)

All CSS must use BEM (Block Element Modifier) naming convention:

```css
/* Block */
.rule-editor { }

/* Element (double underscore) */
.rule-editor__header { }
.rule-editor__body { }
.rule-editor__footer { }

/* Modifier (double dash) */
.rule-editor--expanded { }
.rule-editor__header--sticky { }
```

### CSS Variable Naming

CSS variables follow BEM modifier syntax with `--` separating purpose from variation:

```css
/* Pattern: --purpose--variation */
--color-primary--light
--color-primary--dark
--spacing-block--small
--spacing-block--large
--radius-button--rounded
--radius-button--pill

/* Examples in use */
:root {
  --color-surface--default: #ffffff;
  --color-surface--elevated: #f8f9fa;
  --color-surface--sunken: #e9ecef;

  --color-text--primary: #212529;
  --color-text--secondary: #6c757d;
  --color-text--muted: #adb5bd;

  --spacing-stack--xs: 0.25rem;
  --spacing-stack--sm: 0.5rem;
  --spacing-stack--md: 1rem;
  --spacing-stack--lg: 1.5rem;
}

.component {
  background: var(--color-surface--default);
  color: var(--color-text--primary);
  padding: var(--spacing-stack--md);
}
```

### Theme Support

Use CSS `light-dark()` function or framework variables:

```css
.element {
  /* Automatic light/dark switching */
  background: light-dark(var(--wpea-gray-100), var(--wpea-gray-900));

  /* Or use semantic variables that auto-switch */
  background: var(--wpea-surface-1);
}
```

---

## Security Standards

### Input Validation

```php
// Sanitize all input
$title = sanitize_text_field($_POST['title'] ?? '');
$html = wp_kses_post($_POST['content'] ?? '');
$email = sanitize_email($_POST['email'] ?? '');
$url = esc_url_raw($_POST['url'] ?? '');
$int = absint($_POST['count'] ?? 0);

// Validate after sanitizing
if (empty($title)) {
    return new WP_Error('invalid_title', 'Title is required');
}
```

### Output Escaping

```php
// Always escape output
echo esc_html($user_input);
echo esc_attr($attribute_value);
echo esc_url($url);
echo wp_kses_post($html_content);
```

### Nonce Verification

```php
// REST API - verify nonce header
public function create_item($request) {
    // WordPress REST API verifies X-WP-Nonce automatically
    // when registered with 'permission_callback'
}

// Admin forms
if (!wp_verify_nonce($_POST['_wpnonce'], 'my_action')) {
    wp_die('Security check failed');
}
```

### Capability Checks

```php
// Check capabilities before operations
if (!current_user_can('manage_options')) {
    return new WP_Error('forbidden', 'Insufficient permissions', ['status' => 403]);
}

// Custom capabilities (defined in CLAUDE.md)
if (!current_user_can('{prefix}_manage_settings')) {
    return new WP_Error('forbidden', 'Cannot manage settings', ['status' => 403]);
}
```

### SQL Safety

```php
// Always use $wpdb->prepare() for queries with variables
global $wpdb;

$results = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}my_table WHERE id = %d AND status = %s",
        $id,
        $status
    )
);
```

### REST IDOR Prevention (Required)

`permission_callback` runs before body params are reliably available, so a generic capability check there is NOT enough for handlers that accept a `post_id` / `attachment_id` / `comment_id` / `user_id` parameter. Re-check ownership inside the handler:

```php
// ✗ WRONG — generic capability, then operate on any post id
'permission_callback' => fn() => current_user_can('edit_posts'),
public function save_thing(WP_REST_Request $req): WP_REST_Response {
    $post_id = (int) $req->get_param('post_id');
    update_post_meta($post_id, 'my_thing', $value);
    // ↑ Contributor can write meta on admin posts.
}

// ✓ CORRECT — capability AND post-specific ownership
public function save_thing(WP_REST_Request $req): WP_REST_Response {
    $post_id = (int) $req->get_param('post_id');
    if (!$post_id || !get_post($post_id) || !current_user_can('edit_post', $post_id)) {
        return new WP_REST_Response(['success' => false, 'error' => 'Forbidden'], 403);
    }
    update_post_meta($post_id, 'my_thing', $value);
}
```

URL-path tokens like `(?P<post_id>\d+)` are the exception — REST resolves those before invoking `permission_callback`, so a permission_callback receiving the request can rely on the path arg. Body params and any future PUT/PATCH variant cannot.

**Capability scope:** site-wide writes (global options, settings affecting all users) require `manage_options`. Don't gate them on `publish_pages` or `edit_posts`.

### postMessage Handlers (Required)

Every `addEventListener('message', ...)` handler MUST validate `event.origin` AND `event.source`. `postMessage(payload, '*')` is BANNED — always specify the exact target origin.

```ts
// ✗ WRONG — accepts any sender; vulnerable to nested cross-origin frames
window.addEventListener('message', (event) => {
    if (event.data?.type === 'my:type') doThing(event.data.content);
});

// ✓ CORRECT — origin + source validation
const expectedSource = document.querySelector('#my-iframe')?.contentWindow;
window.addEventListener('message', (event) => {
    if (event.origin !== window.location.origin) return;
    if (event.source !== expectedSource && event.source !== window.parent) return;
    if (event.data?.type === 'my:type') doThing(event.data.content);
});

// ✓ CORRECT — producer specifies exact origin
otherWindow.postMessage(payload, window.location.origin);
```

### HTML Construction in JS (Required)

When building DOM via template literals or `innerHTML`, any value from external state (REST responses, clipboard, URL params, settings, framework state) MUST be escaped or set via `textContent`.

```ts
// ✗ WRONG — external state goes into innerHTML
container.innerHTML = `<span>${externalValue.label}</span>`;

// ✓ CORRECT — DOM construction with textContent
const span = document.createElement('span');
span.textContent = externalValue.label;
container.append(span);

// ✓ CORRECT — explicit escape if template literals are convenient
container.innerHTML = `<span>${escapeHtml(externalValue.label)}</span>`;
```

Centralize a single `escapeHtml` helper per project — never copy-paste the implementation between files (the duplicates drift).

### Sensitive Logging (Required)

Anything user-supplied (prompt text, API response bodies, license-server responses, user emails, IPs, file contents, raw request bodies) MUST be gated behind a debug flag — typically `WP_DEBUG`. The PHP error log and `wp-content/debug.log` are reachable over HTTP on misconfigured installs.

```php
// ✗ WRONG — unconditional logging of user content
error_log('[Plugin] Request: prompt: ' . substr($prompt, 0, 100));

// ✓ CORRECT — content gated; metadata always-on
if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log('[Plugin] Request: prompt: ' . substr($prompt, 0, 100));
} else {
    error_log('[Plugin] Request: provider=' . $provider . ' prompt_len=' . strlen($prompt));
}
```

Better: wrap in a `Logger::debug($msg)` helper that's a no-op when `WP_DEBUG` is false.

### API Key Transport (Required)

Provider API keys MUST travel in HTTP headers, NEVER in URL query strings:

```php
// ✗ WRONG — key in URL, gets logged by proxies, http_api_debug listeners,
//          and any WP_Error returned on connection failure
$url = 'https://api.example.com/v1/thing?key=' . $api_key;
$response = wp_remote_post($url, ['body' => $body]);

// ✓ CORRECT — key in Authorization header
$response = wp_remote_post('https://api.example.com/v1/thing', [
    'headers' => [
        'Authorization' => 'Bearer ' . $api_key,
        'Content-Type'  => 'application/json',
    ],
    'body' => $body,
]);
```

Use `Authorization: Bearer <key>` for OpenAI / Anthropic / generic; `x-goog-api-key: <key>` for Google.

### Dynamic Class Instantiation (Required)

`new $class()` / `call_user_func` / `call_user_func_array` whose target is a string from a filter, option, or setting MUST verify the resolved class implements the expected interface before instantiating:

```php
// ✗ WRONG — instantiate any class string
$class = apply_filters('my_providers', $defaults)[$provider];
return new $class($api_key);

// ✓ CORRECT — verify contract first
$class = apply_filters('my_providers', $defaults)[$provider];
if (!is_string($class) || !class_exists($class) ||
    !is_subclass_of($class, MyProviderInterface::class)) {
    throw new \InvalidArgumentException('Invalid class: ' . esc_html((string) $class));
}
return new $class($api_key);
```

A filter that lets any plugin substitute a class string + a constructor that takes a credential = a primitive for redirecting credentials into unrelated classes.

### Encryption Fallback (Required)

If a key-derivation source like `AUTH_KEY` is missing, NEVER fall back to publicly-knowable material like `site_url()` or `home_url()`. Anyone who later obtains the encrypted blob (DB backup, support tarball, log inclusion) can trivially decrypt.

```php
// ✗ WRONG — silent fallback to public-knowledge key
private function getEncryptionKey(): string {
    if (defined('AUTH_KEY') && AUTH_KEY !== '') {
        return hash('sha256', AUTH_KEY . 'my_salt', true);
    }
    return hash('sha256', 'my_default_' . get_site_url(), true);  // PUBLIC INFO
}

// ✓ CORRECT — refuse to encrypt without real key material
private function getEncryptionKey(): ?string {
    if (defined('AUTH_KEY') && AUTH_KEY !== '') {
        return hash('sha256', AUTH_KEY . 'my_salt', true);
    }
    return null;  // caller must check and either skip encryption + flag,
                  // or refuse the operation
}
```

Surface a clear admin notice telling the user to fix `wp-config.php` so they understand why secrets aren't being encrypted.

### Document Parser Hardening (Required when consuming uploads)

When using `phpoffice/phpword`, `smalot/pdfparser`, `league/html-to-markdown`, or any XML/ZIP-based parser on uploaded content:

- Pin a minimum version in `composer.json` that includes the relevant CVE patches
- Explicitly set `libxml_use_internal_errors(true)` and `LIBXML_NONET` even on PHP 8+ (PHP 8.0+ does NOT auto-load external entities by default, but pinning the flags makes the security posture explicit and survives a runtime change)
- If you ever extract ZIP entries manually, validate entry names with `realpath` against a fixed temp root (zip-slip)
- Cap input size before parsing (a `wp_check_filetype_and_ext` MIME check is not a size check)

### Static Helpers That Do File I/O (Required)

`public static` methods that take a `string $filepath` and read it MUST validate that the path comes from a trusted source. Today's caller might pass `$_FILES[...]['tmp_name']`; tomorrow's caller (a hook, a filter handler, another contributor's code) might pass anything.

```php
// ✗ WRONG — accepts any path
public static function processMarkdown(string $filepath): array {
    $content = file_get_contents($filepath);
    // ...
}

// ✓ CORRECT — assert the path came from a real upload
public static function processMarkdown(string $filepath): array {
    if (!is_uploaded_file($filepath)) {
        return ['success' => false, 'error' => 'Path is not an uploaded file'];
    }
    $content = file_get_contents($filepath);
    // ...
}
```

Even better: accept an `UploadedFile` value object that proves provenance, instead of a raw string.

---

## REST API Standards

### Endpoint Registration

```php
register_rest_route('{namespace}/v1', '/items', [
    [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [$this, 'get_items'],
        'permission_callback' => [$this, 'check_read_permission'],
    ],
    [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [$this, 'create_item'],
        'permission_callback' => [$this, 'check_write_permission'],
        'args'                => $this->get_create_args(),
    ],
]);
```

### Response Format

```php
// Success
return rest_ensure_response([
    'success' => true,
    'data'    => $item,
]);

// Error
return new WP_Error(
    'not_found',
    'Item not found',
    ['status' => 404]
);

// Collection with pagination
return rest_ensure_response([
    'items'      => $items,
    'total'      => $total,
    'page'       => $page,
    'per_page'   => $per_page,
    'total_pages' => ceil($total / $per_page),
]);
```

### Frontend Requests

```typescript
async function fetchItems(): Promise<Item[]> {
  const response = await fetch(`${apiUrl}/items`, {
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': nonce,
    },
  });

  if (!response.ok) {
    const error = await response.json();
    throw new Error(error.message || 'Request failed');
  }

  return response.json();
}
```

---

## Database Standards

### Table Naming

All tables use prefix from CLAUDE.md: `{wp_prefix}_{db_prefix}_tablename`

### Schema Definition

```php
public function create_tables(): void {
    global $wpdb;

    $charset = $wpdb->get_charset_collate();
    $table   = $wpdb->prefix . '{db_prefix}_items';

    $sql = "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'active',
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY status (status),
        KEY created_at (created_at)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}
```

### Versioning

Track schema versions for migrations:

```php
$current_version = get_option('{prefix}_db_version', '0');

if (version_compare($current_version, '1.1.0', '<')) {
    $this->migrate_to_1_1_0();
    update_option('{prefix}_db_version', '1.1.0');
}
```

---

## Error Handling

### PHP

```php
// Throw exceptions for unexpected errors
if (!$file_exists) {
    throw new \RuntimeException('Configuration file not found');
}

// Return WP_Error for expected failures
if (empty($name)) {
    return new WP_Error('validation_error', 'Name is required');
}

// Log errors appropriately
if (WP_DEBUG) {
    error_log("[{PREFIX}] Failed to process: " . $e->getMessage());
}
```

### TypeScript

```typescript
// Use try/catch with typed errors
try {
  const result = await saveItem(item);
} catch (error) {
  if (error instanceof ValidationError) {
    showToast({ type: 'error', message: error.message });
  } else {
    console.error('Unexpected error:', error);
    showToast({ type: 'error', message: 'An unexpected error occurred' });
  }
}
```

### User Feedback

Always provide user feedback for:
- Successful operations (toast/notification)
- Validation errors (inline messages)
- Server errors (toast with retry option)
- Loading states (spinners/skeletons)

---

## Performance

### PHP

1. **Cache expensive operations**: Use transients for external API calls
2. **Lazy load**: Only load classes when needed
3. **Database queries**: Use indexes, limit results, avoid N+1 queries

### JavaScript

1. **Code splitting**: Separate admin/public bundles
2. **Lazy components**: Load modals/dialogs on demand
3. **Debounce**: Debounce search inputs and autosave

### Assets

1. **Local assets only**: Never load from external CDNs
2. **Minification**: All production assets minified
3. **Cache busting**: Use version parameter for updates

### Avoiding Chrome Violation Warnings

Chrome DevTools reports violations when `setTimeout`, `setInterval`, or `requestIdleCallback` handlers take >50ms. These warnings can appear as bugs to users even though functionality works correctly.

**The Problem:**
```typescript
// This triggers: "[Violation] 'setTimeout' handler took 65ms"
setTimeout(() => {
    heavyDomWork();  // Takes 65ms
}, 100);
```

**The Solution - Use Microtasks:**
```typescript
// setTimeout handler returns instantly (<1ms), no violation
// Heavy work runs in microtask (Chrome doesn't warn about microtask duration)
setTimeout(() => {
    queueMicrotask(() => heavyDomWork());
}, 100);
```

**Why this works:**
- `queueMicrotask()` schedules work as a microtask (like `Promise.then()`)
- The setTimeout callback returns immediately after calling `queueMicrotask()`
- Chrome only measures the setTimeout/setInterval callback duration, not microtasks
- This is the same pattern Vue uses for its reactivity batching

**When to use this pattern:**
- Any setTimeout/setInterval callback that does heavy DOM work
- Polling loops that might occasionally do expensive operations
- Debounced functions that trigger intensive processing

**Example - Debounced Heavy Function:**
```typescript
function debouncedHeavyWork(): void {
    if (debounceTimeout) clearTimeout(debounceTimeout);
    debounceTimeout = setTimeout(() => {
        debounceTimeout = null;
        // Use microtask to avoid violation warning
        queueMicrotask(() => processAllItems());
    }, 150);
}
```

**Example - Polling Loop:**
```typescript
const scheduleCheck = (): void => {
    if ('requestIdleCallback' in window) {
        window.requestIdleCallback(() => {
            queueMicrotask(() => checkForChanges());
            setTimeout(scheduleCheck, 1500);
        }, { timeout: 3000 });
    } else {
        setTimeout(() => {
            queueMicrotask(() => checkForChanges());
            scheduleCheck();
        }, 1500);
    }
};
```

**Note:** The work still takes the same time - this just prevents the warning from appearing in the console. For actual performance improvements, optimize the work itself or break it into smaller chunks.

---

## Accessibility

### Requirements

1. **Keyboard navigation**: All interactive elements keyboard accessible
2. **ARIA labels**: Buttons/icons without text need `aria-label`
3. **Focus management**: Modals trap focus, return focus on close
4. **Color contrast**: Meet WCAG AA (4.5:1 for text)
5. **Screen readers**: Test with screen reader software

### Clickable Switch Labels (Required)

All Switch components with adjacent text labels MUST have clickable labels that toggle the switch. This improves usability by providing a larger click target and is standard form behavior.

**Required attributes for clickable labels:**
- `class="clickable"` - for styling
- `role="button"` - for screen reader identification
- `tabindex="0"` - for keyboard navigation
- `onclick` - toggle handler
- `onkeydown` - Enter key handler

**Important:** Only use `role="button"` on interactive elements like `<span>`. For semantic elements like `<h4>`, wrap the text in a `<span>` with the clickable attributes:

```svelte
<!-- For regular labels -->
<div class="switch-row">
    <Switch
        checked={enabled}
        onchange={(checked) => onUpdate('enabled', checked)}
    />
    <span
        class="label clickable"
        role="button"
        tabindex="0"
        onclick={() => onUpdate('enabled', !enabled)}
        onkeydown={(e) => e.key === 'Enter' && onUpdate('enabled', !enabled)}
    >Enable Feature</span>
</div>

<!-- For headings - wrap text in span -->
<h4><span
    class="clickable"
    role="button"
    tabindex="0"
    onclick={() => onUpdate('enabled', !enabled)}
    onkeydown={(e) => e.key === 'Enter' && onUpdate('enabled', !enabled)}
>Section Title</span></h4>

<style>
    .clickable {
        cursor: pointer;
    }

    .clickable:hover {
        color: var(--wpea-color--primary);
    }
</style>
```

**When to skip clickable labels:**
- Switch is inside an accordion header (title has different click behavior)
- Switch controls a row in a repeater (button next to it expands/collapses)
- No visible text label exists

### Examples

```svelte
<button
  aria-label="Delete rule"
  aria-describedby="delete-warning"
  onclick={handleDelete}
>
  <Icon name="trash" />
</button>

<span id="delete-warning" class="sr-only">
  This action cannot be undone
</span>
```

---

## Git Conventions

### Commit Messages

```
type(scope): short description

Longer description if needed.

🤖 Generated with Claude Code
```

**Types**: `feat`, `fix`, `docs`, `style`, `refactor`, `perf`, `test`, `chore`

### Branch Naming

- `feature/short-description`
- `fix/issue-description`
- `refactor/component-name`

---

## Code Review Checklist

Before submitting code:

- [ ] Follows naming conventions
- [ ] Has required DocBlocks
- [ ] Passes linting (PHP_CodeSniffer, ESLint)
- [ ] No security vulnerabilities
- [ ] Proper error handling
- [ ] Accessible (keyboard, ARIA)
- [ ] Works in light and dark mode
- [ ] No console errors/warnings
- [ ] Translations wrapped in `__()` / `_e()`

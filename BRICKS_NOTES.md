# Bricks Builder Integration Notes

Technical notes for integrating with Bricks Builder's internal state and APIs.

## Accessing Bricks Data

### Static Data (`window.bricksData`)

The `bricksData` object contains static configuration and page data:

```javascript
// Page elements (flat array with parent/children references)
bricksData.loadData.content

// Global CSS classes (id -> name mapping)
bricksData.loadData.globalClasses

// Page settings
bricksData.loadData.pageSettings

// Available keys in loadData:
// breakpoints, permissions, themeStyles, colorPalette, globalVariables,
// globalClasses, globalClassesCategories, pseudoClasses, globalSettings,
// content, templateType, elementsHtml, etc.
```

**Important:** `bricksData.elements` is NOT the page elements - it's element type definitions/schemas (e.g., `{container: {...}, section: {...}, heading: {...}}`).

### Vue State (Active Element)

**CRITICAL:** Always access `.brx-body` from the **main document**, NOT the iframe. The iframe has a disconnected Vue instance.

Bricks uses Vue.js. To access reactive state (like the currently selected element):

```javascript
// Get Vue state
const vueState = document.querySelector('.brx-body')
    .__vue_app__
    .config
    .globalProperties
    .$_state;

// Currently selected element
vueState.activeElement          // { id: "abc123", name: "block", ... }
vueState.activeElement.id       // Element ID
vueState.activeElement.cid      // Component ID (if element is inside a component)

// Multiple selected elements (bulk edit)
vueState.selectedElements       // Array of selected elements

// Other useful state
vueState.activePanel            // Current panel: "element", "class", etc.
vueState.activeClass            // Currently active global class
vueState.globalClasses          // All global classes
vueState.breakpointActive       // Current responsive breakpoint
vueState.pseudoClassActive      // Active pseudo-class (:hover, :focus, etc.)
```

### Vue Global Properties (Helper Methods)

```javascript
const vueGlobalProp = document.querySelector('.brx-body')
    .__vue_app__
    .config
    .globalProperties;

// Get element object by ID (with fallback for older Bricks versions)
// $_getElementObject exists in newer versions
// $_getDynamicElementById is the fallback for older versions
function getElementObject(id) {
    if (typeof vueGlobalProp.$_getElementObject === 'function') {
        return vueGlobalProp.$_getElementObject(id);
    }
    if (typeof vueGlobalProp.$_getDynamicElementById === 'function') {
        const el = vueGlobalProp.$_getDynamicElementById(id);
        // If element is in a component, get component element instead
        if (el?.cid) {
            return vueGlobalProp.$_getComponentElementById(el.cid);
        }
        return el;
    }
    return null;
}

// Get component element
vueGlobalProp.$_getComponentElementById(componentId)

// Get global class data
vueGlobalProp.$_getGlobalClass(classId)

// Generate unique ID
vueGlobalProp.$_generateId()

// Show UI message
vueGlobalProp.$_showMessage(message)

// Check if mobile-first mode
vueGlobalProp.$_isMobileFirst._value
```

## ⚠ `bricksData.loadData.content` is the INITIAL snapshot only

It is **never updated** when the user adds, removes, duplicates, or
pastes elements at runtime. Iterating it to find current elements will
silently miss every new insertion.

**Source of truth:** Vue state, accessed via globalProperties methods:

```js
const props = document.querySelector('.brx-body').__vue_app__.config.globalProperties;
const el = props.$_getDynamicElementById(elementId);  // reactive
```

To react to new elements, wrap the entry-point methods (e.g.
`$_addNewElement`, `$_pasteElements`, `$_cloneElement`). Do not poll
`bricksData.loadData.content` — by design it's a frozen initial load.

### Wrapping `$_addNewElement` — the id lives on the RETURN value

The arg you pass in (`{ element: { name, ... } }`) does NOT have an id
at call time — Bricks generates the id inside the function. The created
element is returned as `{ id, name, settings, ... }`. Wrap pattern:

```js
const orig = props.$_addNewElement;
props.$_addNewElement = function () {
    const arg0 = arguments[0];
    const isOurs = arg0?.element?.name === 'my-element-name';
    const result = orig.apply(this, arguments);
    if (isOurs && result?.id) {
        // result.id is the freshly-generated Vue-store key.
        // Defer one tick so Bricks finishes batched store mutations
        // before we read/write the reactive element.
        setTimeout(() => {
            const el = props.$_getDynamicElementById(result.id);
            if (el) {
                // mutate el.label / el.settings here — reactive
            }
        }, 0);
    }
    return result;
};
```

Paste / clone / undo go through `$_pasteElements`, `$_cloneElement`,
`$_cloneElements` respectively — wrap each one you need to react to.

## Element Structure

Elements in `bricksData.loadData.content` are stored as a flat array:

```javascript
{
    id: "abc123",           // Unique element ID
    name: "block",          // Element type (block, heading, text, etc.)
    parent: "xyz789",       // Parent element ID (0 for root)
    children: ["def456"],   // Array of child element IDs
    label: "Card",          // Custom label (optional)
    settings: {
        _cssGlobalClasses: ["classId1", "classId2"],  // Global class IDs (not names!)
        _cssId: "my-custom-id",                        // Custom CSS ID
        _cssCustom: "...",                             // Custom CSS (desktop)
        "_cssCustom:tablet_portrait": "...",            // Breakpoint CSS (tablet)
        "_cssCustom:mobile_portrait": "...",            // Breakpoint CSS (mobile)
        text: "...",                                   // Element content
        tag: "div",                                    // HTML tag
        // ... other element-specific settings
    }
}
```

## Global Classes

Global classes use ID references, not names directly:

```javascript
// Element has class IDs
element.settings._cssGlobalClasses = ["certko", "ywyfdl"]

// Map IDs to names via globalClasses
bricksData.loadData.globalClasses = [
    { id: "certko", name: "card", settings: {} },
    { id: "ywyfdl", name: "card__header", settings: {} },
    // ...
]
```

To resolve class names:

```javascript
function getClassNames(element) {
    const classIds = element.settings?._cssGlobalClasses || [];
    const globalClasses = bricksData.loadData.globalClasses || [];

    return classIds.map(id =>
        globalClasses.find(gc => gc.id === id)?.name
    ).filter(Boolean);
}
```

## Element Traversal

To traverse element hierarchy:

```javascript
function collectDescendants(elementId, elementMap) {
    const element = elementMap.get(elementId);
    if (!element?.children) return [];

    const descendants = [];
    for (const childId of element.children) {
        descendants.push(childId);
        descendants.push(...collectDescendants(childId, elementMap));
    }
    return descendants;
}

// Build element map for quick lookup
const elementMap = new Map();
for (const el of bricksData.loadData.content) {
    elementMap.set(el.id, el);
}
```

## Preview Iframe

The Bricks preview canvas is in an iframe:

```javascript
const iframe = document.querySelector('#bricks-builder-iframe');
const iframeDoc = iframe?.contentDocument;

// Find element in preview by ID
const previewElement = iframeDoc.querySelector(`[data-id="${elementId}"]`);
```

## Component Elements

When an element is inside a Bricks component, it has a `cid` property:

```javascript
if (vueState.activeElement?.cid) {
    // Element is inside a component
    const componentElement = vueGlobalProp.$_getComponentElementById(
        vueState.activeElement.cid
    );
}
```

## CSS Selectors

Bricks generates CSS selectors based on element configuration:

```javascript
// Default selector uses element ID
`.brxe-${element.id}`

// With custom CSS ID
`#${element.settings._cssId}`

// With global class
`.${globalClassName}`
```

## Useful Patterns

### Check if Element is Active

```javascript
function isElementActive() {
    const vueState = getBricksVueState();
    return typeof vueState?.activeElement === 'object'
        && vueState.activeElement?.id;
}
```

### Check if Class is Active (vs Element)

```javascript
function isClassActive() {
    const vueState = getBricksVueState();
    return vueState?.activePanel === 'class'
        && typeof vueState?.activeClass === 'object';
}
```

### Get Final Active Object (Element or Class)

```javascript
function getActiveObject() {
    const vueState = getBricksVueState();
    const vueGlobalProp = getVueGlobalProp();

    // Check if class is active
    if (isClassActive()) {
        return vueState.globalClasses.find(
            gc => gc.id === vueState.activeClass.id
        );
    }

    // Check if component element
    if (vueState.activeElement?.cid) {
        return vueGlobalProp.$_getComponentElementById(
            vueState.activeElement.cid
        );
    }

    // Regular element
    if (vueState.activeElement?.id) {
        return vueGlobalProp.$_getElementObject(
            vueState.activeElement.id
        );
    }

    return null;
}
```

## Element Creation & Clipboard Operations

### Vue Global Methods for Elements

```javascript
const props = document.querySelector('.brx-body')
    .__vue_app__
    .config
    .globalProperties;

// Create a new element object (does not add to page)
const element = props.$_createElement({ name: 'heading' });

// Add element to page (after currently selected element)
// shiftKey: true = add as child of selected, false = add as sibling
props.$_addNewElement(
    { element },
    { shiftKey: false },
    true  // flag (always true)
);

// Set active/selected element
props.$_setActiveElement(element);

// Delete an element - takes FULL element object, not just ID
// Signature: function(e) { vm.userHasPermission("delete_elements") && vm.deleteElement(e) }
props.$_deleteElement(elementObject);
```

### Clipboard Operations (Copy/Paste)

Bricks has its own internal clipboard separate from the system clipboard:

```javascript
// Write elements to Bricks internal clipboard
// source: the clipboard key (e.g., "bricksCopiedElements")
// content: array of BricksElement objects
await props.$_writeToClipboard('bricksCopiedElements', elementsArray);

// Read from Bricks internal clipboard
const data = await props.$_readFromClipboard('bricksCopiedElements');

// Paste from Bricks clipboard (inserts after selected element)
// This reads from internal clipboard, NOT system clipboard
props.$_pasteElements();

// Copy selected elements to clipboard
props.$_copyElements(['elementId1', 'elementId2']);
```

### Bricks Clipboard Format

When copying elements, Bricks uses this JSON structure:

```javascript
{
    content: [
        {
            id: "abcdef",
            name: "heading",
            parent: 0,           // 0 = root level, or parent element ID
            children: [],
            settings: { text: "Hello", tag: "h2" }
        },
        // ... more elements
    ],
    source: "bricksCopiedElements",
    sourceUrl: "https://example.com/page",
    version: "2.1.4"  // Bricks version
}
```

**Important:** When using `$_writeToClipboard`, pass only the `content` array - it builds the wrapper object internally:

```javascript
// CORRECT - pass key and content array separately
await props.$_writeToClipboard('bricksCopiedElements', clipboardData.content);

// WRONG - don't pass the full object
await props.$_writeToClipboard('bricksCopiedElements', fullClipboardObject);
```

### Element ID Generation

```javascript
// Bricks IDs are 6 random lowercase letters
function generateBricksId() {
    const chars = 'abcdefghijklmnopqrstuvwxyz';
    let id = '';
    for (let i = 0; i < 6; i++) {
        id += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    return id;
}
```

## Custom Tags (HTML Tag Override)

Bricks elements have a default HTML tag, but can be overridden with custom tags:

```javascript
// Default: element renders as its default tag (e.g., heading = h2, div = div)
element.settings = { text: "Title" };

// Override with standard tag options
element.settings = {
    text: "Title",
    tag: "h3"  // Use h3 instead of default h2
};

// Override with ANY custom tag (dl, dt, dd, article, etc.)
element.settings = {
    text: "Term",
    tag: "custom",           // Must be "custom" to enable customTag
    customTag: "dt"          // The actual HTML tag to use
};
```

This allows creating semantic HTML like definition lists:

```javascript
// <dl> container
{ name: "div", settings: { tag: "custom", customTag: "dl" } }

// <dt> term (as heading)
{ name: "heading", settings: { text: "Term", tag: "custom", customTag: "dt" } }

// <dd> description (as text-basic)
{ name: "text-basic", settings: { text: "Description", tag: "custom", customTag: "dd" } }
```

## Element Labels

Elements can have custom labels shown in the Structure panel. The label is a **top-level property** on the element object, NOT inside settings:

```javascript
// CORRECT - label at top level
element.label = "Hero Title";  // Shows "Hero Title" instead of "Heading"

// WRONG - settings._label does NOT work
element.settings._label = "Hero Title";  // This will be ignored
```

When generating HTML for Bricks conversion, use `data-el-label` attributes:

```html
<h2 class="brxe-heading" data-el-label="title">Title</h2>
<p class="brxe-text-basic" data-el-label="description">Description</p>
```

These labels are used for:
- Better organization in Structure panel
- BEM class naming (e.g., `block__title`, `block__description`)

## CSS Variables

The Bricks Builder editor uses CSS custom properties for theming. When styling custom UI elements inside the Bricks editor (not the preview iframe), use these variables to match the Bricks design:

### Background Colors

```css
--builder-bg-1           /* Darkest background */
--builder-bg-2           /* Secondary background */
--builder-bg-3           /* Tertiary background */
--builder-bg-4           /* Lightest background */
```

### Text Colors

```css
--builder-color          /* Primary text */
--builder-color-2        /* Secondary text (muted) */
--builder-color-dark     /* Dark text (for light backgrounds) */
--builder-color-light    /* Light text (for dark backgrounds) */
```

### Accent & State Colors

```css
--builder-color-accent      /* Primary accent color (orange) */
--builder-color-success     /* Success state */
--builder-color-warning     /* Warning state */
--builder-color-danger      /* Error/danger state */
--builder-color-info        /* Info state */
```

### Control Colors

```css
--builder-control-bg        /* Form control background */
--builder-control-color     /* Form control text color */
```

### Example Usage

```css
/* Custom button in Bricks editor */
.my-custom-button {
    background: var(--builder-bg-3);
    color: var(--builder-color);
    border: none;
    border-radius: 3px;
    padding: 6px 10px;
    cursor: pointer;
    transition: background 0.15s ease, color 0.15s ease;
}

.my-custom-button:hover {
    background: var(--builder-color-accent);
    color: white;
}

/* Error state */
.my-custom-button.error {
    background: var(--builder-color-danger);
    color: white;
}
```

## Creating and Applying Global Classes

### Creating a Global Class

```javascript
const vueState = getVueState();
const vueGlobalProp = getVueGlobalProp();

// Generate unique ID (6 lowercase letters)
const classId = vueGlobalProp.$_generateId();

// Add to reactive globalClasses array
vueState.globalClasses.push({
    id: classId,
    name: 'my-class-name',
    settings: {},
});
```

### ⚠ DB write is NOT enough — the live session needs the Vue push too

`bricks_global_classes` is loaded into Vue state via `wp_localize_script`
**ONCE at builder boot**. After that, Bricks's running session reads
`vueState.globalClasses` as the source of truth — for the right-panel
pill renderer, the AJAX element-render payload, the Style Manager class
list, the Save endpoint, all of it.

**Consequence:** if PHP writes a new class to the `bricks_global_classes`
option AFTER Vue was localized (snippet activated mid-session, programmatic
WP-CLI insert, third-party plugin write), the class is INVISIBLE to the
live session until the user fully reloads the builder. Symptoms:

- Element has the class in `_cssGlobalClasses` but the right panel shows
  no pill (or an "unresolved" pill).
- Bricks's Copy JSON has the class reference under `content[].settings`
  but its `globalClasses[]` array is empty (no definition to embed).
- The class's CSS never appears in the page's inline `<style>` block —
  the AJAX render asks Vue for the definition, doesn't get one, emits
  nothing.
- On save, Bricks writes the option from Vue state — clobbering the
  PHP-inserted class definition that wasn't in Vue.

**Fix:** anywhere PHP creates a class for the live session (snippets that
ship a Bricks Global Class are the canonical case — see
`assets/snippets/a11y-widget.php` and `assets/snippets/sliding-mobile-menu.php`),
emit the class CSS as an inline JS constant in the builder context and
push it into Vue state on mount, mirroring the DB. The push is idempotent
— check `existing.find(c => c.id === ours)` first.

```php
add_action('wp_footer', function () {
    if (!bricks_is_builder()) return;
    $css_json = wp_json_encode(my_class_css());
    ?>
    <script>
    (function () {
        var CSS = <?php echo $css_json; ?>;
        function tryPush() {
            var body = document.querySelector('.brx-body');
            var props = body && body.__vue_app__ && body.__vue_app__.config.globalProperties;
            if (!props || !props.$_state || !Array.isArray(props.$_state.globalClasses)) {
                setTimeout(tryPush, 250);
                return;
            }
            var existing = props.$_state.globalClasses;
            for (var i = 0; i < existing.length; i++) {
                if (existing[i] && existing[i].id === 'my-class-id') return;  // already there
            }
            existing.push({ id: 'my-class-id', name: 'my-class-name',
                            settings: { _cssCustom: CSS },
                            modified: Date.now() });
        }
        tryPush();
    })();
    </script>
    <?php
});
```

Discovered 2026-05-24 — a fresh InstaWP install activated the Accessibility
Widget snippet mid-session; the wp_loaded hook wrote `brxp-a11y` to the DB
option correctly, but Bricks's Vue state was already booted without it, so
dropped widgets had the class reference attached (via our
`$_addNewElement` wrapper) but no resolvable definition. Reloading the
builder fixed it; the Vue-push fix makes the reload unnecessary.

### Applying Global Class to Element

```javascript
// Get the element object (must be the reactive reference)
const element = vueGlobalProp.$_getDynamicElementById(elementId);

// AT pattern: Set as activeElement first, then modify
vueState.activeElement = element;

// Now modify activeElement.settings directly
if (!vueState.activeElement.settings._cssGlobalClasses) {
    vueState.activeElement.settings._cssGlobalClasses = [];
}
vueState.activeElement.settings._cssGlobalClasses.push(classId);

// Trigger UI re-render
vueState.rerenderControls = Date.now();
```

### Selecting/Reselecting an Element

```javascript
const vueGlobalProp = getVueGlobalProp();
const element = vueGlobalProp.$_getDynamicElementById(elementId);

// Set as active element (updates UI selection)
vueGlobalProp.$_setActiveElement(element);
```

### Check for Trashed Classes

Before creating a class, check if it exists in the trash:

```javascript
const isTrashed = vueState.globalClassesTrash?.some(
    gc => gc.name === 'my-class-name'
);

if (isTrashed) {
    // Class is in trash - user must restore or delete it first
    console.warn('Class exists in trash');
}
```

## Renaming Global Classes

Bricks' own rename flow (from minified source):

```javascript
// 1. Get the global class object
const gc = vueGlobalProp.$_getGlobalClass(classId, true, true);

// 2. Update _cssCustom selectors (replace .old-name with .new-name)
Object.entries(gc.settings).forEach(([key, value]) => {
    if (key.startsWith('_cssCustom')) {
        gc.settings[key] = vueGlobalProp.$_replaceCustomCssRoot('.' + gc.name, '.' + newName, value);
    }
});

// 3. Rename in place (do NOT create new object + delete old)
gc.name = newName;

// 4. Trigger iframe class name re-render (CRITICAL)
// rerenderClassNames makes iframe re-resolve class IDs → names on DOM elements
// Without this, CSS targets .new-name but elements still have class="old-name"
vueState.rerenderClassNames = Date.now();
```

**Key points:**
- Use `rerenderClassNames`, NOT `rerenderControls` — they trigger different watchers
- `$_renderCss()` alone is NOT sufficient — it updates CSS rules but not DOM class attributes
- Rename in-place (`gc.name = newName`) to avoid Vue proxy issues with `indexOf`/`splice`
- `globalClasses` array can have `null`/`undefined` holes after class deletion — always guard in `.find()` callbacks

## CodeMirror Editors

Bricks uses CodeMirror 5 for code editing (Custom CSS, Custom JS, Code element, etc.). Important behavior to note:

### DOM Recreation

**Bricks recreates CodeMirror DOM elements when the user switches between elements or panels.** This means:

- Element references captured during initialization become stale
- The original `.CodeMirror` DOM node is removed and a new one is created
- The new CodeMirror instance has a different `element.CodeMirror` object

### Finding the Current Editor

When adding UI that interacts with CodeMirror (e.g., toolbar buttons), **always traverse the DOM to find the current CodeMirror instance** rather than using cached references:

```javascript
// BAD - cached reference becomes stale
const editor = element.CodeMirror;  // May be empty/disconnected

// GOOD - traverse DOM from the button's context
btn.addEventListener('click', () => {
    const actionsBar = btn.closest('.actions');
    const controlCode = actionsBar?.closest('.control-code');
    const cmWrapper = controlCode?.querySelector('.CodeMirror');
    const editor = cmWrapper?.CodeMirror;  // Current active editor

    if (editor) {
        const content = editor.getValue();  // Now has actual content
    }
});
```

### CodeMirror Structure in Bricks

```
.control-code
├── .actions              ← Toolbar (copy, expand buttons)
└── .codemirror-wrapper
    └── .CodeMirror       ← The CodeMirror instance (element.CodeMirror)
```

### Accessing CodeMirror Instance

```javascript
const cmElement = document.querySelector('.CodeMirror');

// Method 1: Direct property
const editor = cmElement.CodeMirror;

// Method 2: Via Vue (less common)
const vueEl = cmElement.__vue__;
const editor = vueEl?.codemirror || vueEl?.cm || vueEl?.editor;
```

## Design Principles

### Prefer Vue State Over Custom Tracking

When detecting or reacting to changes in Bricks Builder, always prefer using Vue's reactive state as the source of truth rather than inventing custom tracking mechanisms.

**Why:** Bricks Builder uses Vue internally. Vue re-renders can cause race conditions with custom tracking (Maps, Sets, timeouts). Computing state from current values avoids these issues entirely.

**Example - BEM Rename Detection:**
```typescript
// ✗ BAD - Custom tracking with race conditions
let originalLabels: Map<string, string> = new Map();
let recentlyRenamed: Set<string> = new Set();
// Track when editing starts, compare later, add cooldowns...

// ✓ GOOD - Compute from current state
function findMismatchedBemClass(elementId: string): string | null {
    const currentLabel = getLabelFromDom(item);
    const classes = getElementClasses(elementId);
    // Return class that doesn't match current label
}
const showRename = findMismatchedBemClass(elementId) !== null;
```

**Benefits:**
- No race conditions during Vue re-renders
- No timing-based bugs (cooldowns, debounces)
- Simpler code with less state to manage
- Always reflects actual current state

## Watching Vue State for Reactivity

**Use `window.Vue.watch` instead of polling** for near-instant detection of builder changes:
```typescript
const VueGlobal = (window as unknown as Record<string, unknown>).Vue as
    | { watch?: (source: unknown, cb: () => void, opts: { deep: boolean }) => () => void }
    | undefined;

const state = props.$_state;
const stopWatcher = VueGlobal.watch(
    () => state,
    () => debouncedCompute(), // 150ms debounce to collapse rapid changes
    { deep: true },
);
// Call stopWatcher() to clean up
```

**Startup timing — structure panel may not be rendered yet:**
The structure panel DOM (`li.bricks-draggable-item`) renders asynchronously. On first load, retry until elements are found rather than assuming they exist immediately.

## Element Settings Keys (Verified)

| Feature | Element `name` | Settings Key |
|---------|---------------|-------------|
| Heading tag | `heading` | `settings.tag` (h1-h6), `settings.customTag` when `tag === 'custom'` |
| Image alt | `image` | `settings.altText` |
| Link URL | `text-link`, or any element with `tag: 'a'` | `settings.link.url` |

## Main Window ↔ Iframe Communication

**CRITICAL:** When the main window sends data to the iframe via `postMessage`, the iframe's modules may not be loaded yet. Messages sent before the listener is ready are silently lost.

**Solution — iframe "ready" signal:**
```typescript
// Iframe: after modules are loaded and started
window.parent.postMessage({ type: 'abp:contentDebug:iframeReady' }, '*');

// Main window: listen and send data when iframe is ready
window.addEventListener('message', (event) => {
    if (event.data?.type === 'abp:contentDebug:iframeReady') {
        sendOverlaySyncToIframe();
    }
});
```

**Also use promise-based module loading** — never boolean guards that return `null` for concurrent callers:
```typescript
// ✗ BAD - Concurrent callers get null, silently dropping their work
let loading = false;
async function loadModule() {
    if (loading) return null; // Caller's sync data is lost!
    loading = true;
    // ...
}

// ✓ GOOD - Concurrent callers await the same promise
let loadPromise: Promise<Module | null> | null = null;
async function loadModule() {
    if (loadPromise) return loadPromise; // Waits for the same load
    loadPromise = import('./module').then(mod => { cached = mod; return mod; })
        .finally(() => { loadPromise = null; });
    return loadPromise;
}
```

## Bricks UI Button Styling

When adding buttons to the Bricks Builder UI (e.g., above CodeMirror editors), use these standard styles:

```css
.abp-cm-*-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    padding: 4px 8px;
    cursor: pointer;
    background: var(--wpea-color--primary, #a402ba);
    color: #fff;
    border: none;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 500;
    transition: background 0.15s ease;
    margin-left: 4px;
}
.abp-cm-*-button:hover {
    background: var(--wpea-color--secondary, #32a8ac);
    color: #fff;
}
/* SVG icons use the standard Bricks class for sizing */
.abp-cm-*-button svg {
    /* Add class="bricks-svg" to SVG elements */
}
```

## Color Palette Variables — NOT in `globalVariables`

**IMPORTANT:** When Bricks processes a Color Palette (Settings → Colors), it creates CSS
custom properties for each color and its variants (lightened, darkened, transparent).
These variables are rendered as `:root { --brxp-primary: ...; --brxp-primary-l-1: ...; }` etc.
in a `<style>` tag on the page.

**They are NOT stored in `$_state.globalVariables`.** The `globalVariables` array only
contains variables created via the Bricks Variables panel (Settings → Variables).
Color palette variables live in `$_state.colorPalette` and are rendered as inline CSS,
not as entries in the variables system.

This means any feature that needs to enumerate ALL available CSS custom properties
(e.g., variable cycling, autocompletion) must also scan stylesheets or the
`colorPalette` state, not just `globalVariables`.

## Notes

- Element IDs and class IDs are 6 lowercase alphanumeric strings (e.g., "ztdadf")
- When adding elements programmatically, prefer `$_writeToClipboard` + `$_pasteElements` over `$_addNewElement` for proper Structure panel integration
- Focus the iframe before clipboard operations to avoid "Document not focused" errors

---

## Subclassing a Built-In Bricks Element (PHP)

When extending a built-in Bricks element class (e.g.
`\Bricks\Element_Nav_Menu`) with a different `$name`, the base class
makes name-driven assumptions that silently break. There is no error,
no log line — the element just renders without the right id attribute
or breakpoint CSS. Three overrides cover the known traps.

### Trap 1 — `generate_mobile_menu_inline_css()` branches on `$this->name`

`themes/bricks/includes/elements/base.php` checks
`if ($this->name === 'nav-menu')` before emitting the
`display: none` / `display: block` toggle rules. Subclasses with a
different name get back an empty `@media (...) { }` block. Pose as the
parent's canonical name:

```php
public function generate_mobile_menu_inline_css($settings = [], $breakpoint = '') {
    $original = $this->name;
    $this->name = 'nav-menu';
    $css = parent::generate_mobile_menu_inline_css($settings, $breakpoint);
    $this->name = $original;
    return $css;
}
```

### Trap 2 — `set_root_attributes()` calls `self::has_css_settings()` early-bound

PHP `self::` is **compile-time** resolved to the class where the method
is *defined*, not the runtime instance's class. So in
`Element::set_root_attributes()`, `self::has_css_settings($settings)`
calls the BASE class's `has_css_settings`, NOT the subclass's override
— even though `$this` is the subclass instance.

The check inside still reads `$this->name`, so the only reliable cover
is to pose as the parent name for the whole `set_root_attributes()`
window:

```php
public function set_root_attributes() {
    $original = $this->name;
    $this->name = 'nav-menu';
    parent::set_root_attributes();
    $this->name = $original;
}
```

Without this, the root element never gets `id="brxe-..."` on the
frontend, which means the breakpoint CSS selector (`#brxe-{id} .x`)
matches nothing. The bug is **masked in the builder** because
`bricks_is_builder_call()` short-circuits `has_css_settings()` to
`true` regardless of name — first appears on the live frontend.

### Trap 3 — `has_css_settings()` itself (for dynamic-dispatch callers)

Separate from Trap 2: any code path that calls
`$this->has_css_settings(...)` (dynamic dispatch) DOES hit the
subclass override. So override it too, using the same pose pattern, so
both dispatch styles produce the same answer:

```php
public function has_css_settings($settings) {
    $original = $this->name;
    $this->name = 'nav-menu';
    $result = parent::has_css_settings($settings);
    $this->name = $original;
    return $result;
}
```

### Trap 4 — `assets.php` checks `$element['name']`, NOT `$this->name`

`themes/bricks/includes/assets.php` (frontend CSS generation) does:

```php
if ($element['name'] === 'nav-menu' || $element['name'] === 'nav-nested') { ... }
```

That's the raw saved element data, not the instance — no PHP-level
override defeats it. Two workarounds:

1. **Mirror the CSS yourself** in the subclass's `render()` on
   frontend (`!bricks_is_builder() && !bricks_is_builder_call()`), call
   `$this->generate_mobile_menu_inline_css($this->settings, $breakpoint)`
   and echo a `<style>` after `parent::render()`. The cleanest fix —
   user sees identical CSS to native nav-menu.
2. A `bricks/element/render_attributes` filter that injects the missing
   classes. Usually too clever.

### Snippet-Execution Timing — `init` is too late from inside a snippet

If the snippet body runs from a code manager that executes snippets at the
`init` action (FluentSnippets, Code Snippets, WPCode), any
`add_action('init', ...)` registered inside the snippet body **never
fires** — by the time `add_action` is called, `init` has already
advanced past the priority you'd register for, or is already complete.

Two practical rules:

1. **Filters and actions that need to fire later (`template_redirect`,
   `wp_loaded`, `wp_head`, `wp_footer`, etc.) work fine** — register
   them with `add_action` / `add_filter` and they'll fire as expected.

2. **`init` itself is unreliable.** Use `wp_loaded` instead. It fires
   right after `init` and accepts callbacks added during `init`. The
   only behaviours you lose vs. true `init` are taxonomy/post-type
   registration and a few other early-bound things — none of which a
   snippet should be doing.

3. **For filter registration that must be queued ASAP** (so it's
   guaranteed-active before any later hook fires it), call `add_filter`
   at **file-load time** — outside any action callback. PHP resolves
   `['ClassName', 'method']` callables lazily on fire, so the class
   doesn't need to exist yet at `add_filter` time. Use `has_filter` to
   dedupe against accidental double-include.

Symptom of this trap: feature renders / element registers correctly
(because Bricks's element registry survives across requests once
loaded), but a filter you added inside the same `init` block silently
never runs.

### Rule of thumb when subclassing

Before assuming an override works, grep `themes/bricks/includes/`:

```bash
grep -rn "name === '<parent-name>'" themes/bricks/includes/
grep -rn "\$this->name === '<parent-name>'" themes/bricks/includes/
grep -rn "\$element\['name'\] === '<parent-name>'" themes/bricks/includes/
```

Every hit is either a `self::` trap (use the `set_root_attributes`
pose pattern) or a `$element['name']` trap (mirror the CSS yourself).
There is no generic "just call parent" solution.

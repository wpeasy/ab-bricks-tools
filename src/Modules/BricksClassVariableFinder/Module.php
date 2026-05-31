<?php
declare(strict_types=1);

namespace AB\BricksTools\Modules\BricksClassVariableFinder;

use AB\BricksTools\Modules\HasAdminPage;
use AB\BricksTools\Modules\ModuleInterface;
use AB\BricksTools\System\WpCli;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class Module implements ModuleInterface, HasAdminPage
{
    public const REST_ROUTE_TARGETS          = '/class-variable-finder/targets';
    public const REST_ROUTE_SCAN             = '/class-variable-finder/scan';
    public const REST_ROUTE_SAVE_LABEL       = '/class-variable-finder/element-label';
    public const REST_ROUTE_SAVE_ELEMENT_CLS = '/class-variable-finder/element-classes';
    public const REST_ROUTE_RENAME_CLASS     = '/class-variable-finder/rename-class';
    public const REST_ROUTE_REVISIONS_LIST    = '/class-variable-finder/revisions';
    public const REST_ROUTE_REVISIONS_RESTORE = '/class-variable-finder/revisions/restore';
    public const REST_ROUTE_REVISIONS_LATEST  = '/class-variable-finder/revisions/latest';

    public function getSlug(): string
    {
        return 'bricks-class-variable-finder';
    }

    public function getName(): string
    {
        return __('Bricks Class & Variable Finder', 'ab-bricks-tools');
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return __(
            'Find which pages and elements use a given Bricks Global Class or Global Variable.',
            'ab-bricks-tools'
        );
    }

    public function boot(): void
    {
        add_action('rest_api_init', [$this, 'registerRestRoutes']);

        // Snapshot every change to the source options so we can offer
        // restore. Captures Bricks Style Manager edits AND our own rename
        // endpoint.  $oldValue is the value BEFORE this update (i.e. the
        // state we'd want to restore to). The static `$restoring` flag
        // suppresses snapshots while a restore is in flight.
        add_action('update_option_bricks_global_classes', [$this, 'onClassesOptionUpdate'], 10, 2);
        add_action('update_option_bricks_global_variables', [$this, 'onVariablesOptionUpdate'], 10, 2);
    }

    /**
     * @param mixed $oldValue
     * @param mixed $newValue
     */
    public function onClassesOptionUpdate($oldValue, $newValue): void
    {
        if (serialize($oldValue) === serialize($newValue)) {
            return;
        }
        $summary = RevisionStore::summarize(RevisionStore::KIND_CLASSES, $oldValue, $newValue);
        RevisionStore::add(
            RevisionStore::KIND_CLASSES,
            is_array($oldValue) ? $oldValue : [],
            $summary
        );
    }

    /**
     * @param mixed $oldValue
     * @param mixed $newValue
     */
    public function onVariablesOptionUpdate($oldValue, $newValue): void
    {
        if (serialize($oldValue) === serialize($newValue)) {
            return;
        }
        $summary = RevisionStore::summarize(RevisionStore::KIND_VARIABLES, $oldValue, $newValue);
        RevisionStore::add(
            RevisionStore::KIND_VARIABLES,
            is_array($oldValue) ? $oldValue : [],
            $summary
        );
    }

    public function registerRestRoutes(): void
    {
        register_rest_route('abbtl/v1', self::REST_ROUTE_TARGETS, [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'restListTargets'],
            'permission_callback' => static fn () => current_user_can('manage_options'),
        ]);

        register_rest_route('abbtl/v1', self::REST_ROUTE_SCAN, [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'restScan'],
            'permission_callback' => static fn () => current_user_can('manage_options'),
            'args'                => [
                'kind' => ['required' => true, 'type' => 'string'],
                'id'   => ['required' => true, 'type' => 'string'],
                'name' => ['required' => true, 'type' => 'string'],
            ],
        ]);

        register_rest_route('abbtl/v1', self::REST_ROUTE_SAVE_LABEL, [
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => [$this, 'restSaveLabel'],
            'permission_callback' => static fn () => current_user_can('manage_options'),
            'args'                => [
                'postId'    => ['required' => true, 'type' => 'integer'],
                'metaKey'   => ['required' => true, 'type' => 'string'],
                'elementId' => ['required' => true, 'type' => 'string'],
                'label'     => ['required' => true, 'type' => 'string'],
            ],
        ]);

        register_rest_route('abbtl/v1', self::REST_ROUTE_SAVE_ELEMENT_CLS, [
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => [$this, 'restSaveElementClasses'],
            'permission_callback' => static fn () => current_user_can('manage_options'),
            'args'                => [
                'postId'    => ['required' => true, 'type' => 'integer'],
                'metaKey'   => ['required' => true, 'type' => 'string'],
                'elementId' => ['required' => true, 'type' => 'string'],
                'classIds'  => ['required' => true, 'type' => 'array'],
            ],
        ]);

        register_rest_route('abbtl/v1', self::REST_ROUTE_RENAME_CLASS, [
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => [$this, 'restRenameClass'],
            // Global rename mutates wp_options site-wide — site-admin only.
            'permission_callback' => static fn () => current_user_can('manage_options'),
            'args'                => [
                'classId'           => ['required' => true,  'type' => 'string'],
                'name'              => ['required' => true,  'type' => 'string'],
                'bemPropagateBlock' => ['required' => false, 'type' => 'boolean', 'default' => false],
                'bemRenameLabels'   => ['required' => false, 'type' => 'boolean', 'default' => false],
                'dryRun'            => ['required' => false, 'type' => 'boolean', 'default' => false],
            ],
        ]);

        register_rest_route('abbtl/v1', self::REST_ROUTE_REVISIONS_LIST, [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'restListRevisions'],
            'permission_callback' => static fn () => current_user_can('manage_options'),
            'args'                => [
                'kind' => ['required' => true, 'type' => 'string'],
            ],
        ]);

        register_rest_route('abbtl/v1', self::REST_ROUTE_REVISIONS_RESTORE, [
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => [$this, 'restRestoreRevision'],
            'permission_callback' => static fn () => current_user_can('manage_options'),
            'args'                => [
                'kind'       => ['required' => true, 'type' => 'string'],
                'revisionId' => ['required' => true, 'type' => 'string'],
            ],
        ]);

        register_rest_route('abbtl/v1', self::REST_ROUTE_REVISIONS_LATEST, [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'restLatestRevision'],
            'permission_callback' => static fn () => current_user_can('manage_options'),
        ]);
    }

    /**
     * Single revision lookup with optional cursor bounds:
     *  - no params       → absolute newest (used as the first Ctrl+Z hop)
     *  - ?before=<ts>    → newest with ts < before (next Ctrl+Z hop back)
     *  - ?after=<ts>     → oldest with ts > after  (next Ctrl+R hop forward)
     */
    public function restLatestRevision(WP_REST_Request $request): WP_REST_Response
    {
        $beforeRaw = $request->get_param('before');
        $afterRaw  = $request->get_param('after');
        $beforeTs  = is_numeric($beforeRaw) ? (int) $beforeRaw : null;
        $afterTs   = is_numeric($afterRaw)  ? (int) $afterRaw  : null;

        $latest = RevisionStore::latest($beforeTs, $afterTs);
        if ($latest === null) {
            return new WP_REST_Response([
                'success' => true,
                'kind'    => null,
                'id'      => null,
            ]);
        }
        $entry = $latest['entry'];
        return new WP_REST_Response([
            'success' => true,
            'kind'    => $latest['kind'],
            'id'      => (string) ($entry['id']      ?? ''),
            'ts'      => (int)    ($entry['ts']      ?? 0),
            'summary' => (string) ($entry['summary'] ?? ''),
        ]);
    }

    public function restListRevisions(WP_REST_Request $request): WP_REST_Response
    {
        $kind = (string) $request->get_param('kind');
        if (!RevisionStore::isValidKind($kind)) {
            return new WP_REST_Response(['success' => false, 'error' => 'Invalid kind'], 400);
        }
        $list   = RevisionStore::getAll($kind);
        $format = get_option('date_format', 'F j, Y') . ' ' . get_option('time_format', 'g:i a');

        $data = array_map(static function (array $entry) use ($format): array {
            $ts = (int) ($entry['ts'] ?? 0);
            return [
                'id'      => (string) ($entry['id'] ?? ''),
                'ts'      => $ts,
                'when'    => $ts > 0 ? wp_date($format, $ts) : '',
                'summary' => (string) ($entry['summary'] ?? ''),
            ];
        }, $list);

        return new WP_REST_Response(['revisions' => $data]);
    }

    public function restRestoreRevision(WP_REST_Request $request): WP_REST_Response
    {
        $kind       = (string) $request->get_param('kind');
        $revisionId = (string) $request->get_param('revisionId');

        if (!RevisionStore::isValidKind($kind)) {
            return new WP_REST_Response(['success' => false, 'error' => 'Invalid kind'], 400);
        }
        if ($revisionId === '') {
            return new WP_REST_Response(['success' => false, 'error' => 'Missing revisionId'], 400);
        }

        $ts = RevisionStore::restore($kind, $revisionId);
        if ($ts === null) {
            return new WP_REST_Response(['success' => false, 'error' => 'Revision not found'], 404);
        }

        return new WP_REST_Response([
            'success' => true,
            'kind'    => $kind,
            'id'      => $revisionId,
            'ts'      => $ts,
        ]);
    }

    /**
     * Replace the `settings._cssGlobalClasses` array on a specific element.
     * Order matters — the array order is preserved on save.
     */
    public function restSaveElementClasses(WP_REST_Request $request): WP_REST_Response
    {
        $postId    = (int) $request->get_param('postId');
        $metaKey   = (string) $request->get_param('metaKey');
        $elementId = (string) $request->get_param('elementId');
        $classIds  = $request->get_param('classIds');

        if ($postId <= 0 || !get_post($postId)) {
            return new WP_REST_Response(['success' => false, 'error' => 'Invalid post'], 400);
        }
        if (!current_user_can('edit_post', $postId)) {
            return new WP_REST_Response(['success' => false, 'error' => 'Forbidden'], 403);
        }
        if (!preg_match('/^_bricks_page_(content|header|footer)(?:_\d+)?$/', $metaKey)) {
            return new WP_REST_Response(['success' => false, 'error' => 'Invalid meta key'], 400);
        }
        if (!is_array($classIds)) {
            return new WP_REST_Response(['success' => false, 'error' => 'classIds must be an array'], 400);
        }

        // Only allow string IDs that exist in the global classes catalogue —
        // arbitrary strings would corrupt the element. Build a known-good set.
        $known       = [];
        $allClasses  = get_option('bricks_global_classes', []);
        if (is_array($allClasses)) {
            foreach ($allClasses as $c) {
                if (is_array($c) && isset($c['id']) && is_string($c['id'])) {
                    $known[$c['id']] = true;
                }
            }
        }

        $sanitized = [];
        foreach ($classIds as $cid) {
            if (is_string($cid) && $cid !== '' && isset($known[$cid])) {
                $sanitized[] = $cid;
            }
        }

        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
            $postId,
            $metaKey
        ));

        if ($raw === null) {
            return new WP_REST_Response(['success' => false, 'error' => 'Element storage not found'], 404);
        }

        $wasJsonString = self::looksLikeJsonContainer($raw);
        $elements      = maybe_unserialize($raw);
        if (is_string($elements)) {
            $decoded = json_decode($elements, true);
            if (is_array($decoded)) {
                $elements = $decoded;
            }
        }
        if (!is_array($elements)) {
            return new WP_REST_Response(['success' => false, 'error' => 'Unable to decode element storage'], 500);
        }

        $found = false;
        foreach ($elements as &$element) {
            if (!is_array($element)) {
                continue;
            }
            if (($element['id'] ?? null) !== $elementId) {
                continue;
            }
            $element['settings'] = is_array($element['settings'] ?? null) ? $element['settings'] : [];
            if ($sanitized === []) {
                unset($element['settings']['_cssGlobalClasses']);
            } else {
                $element['settings']['_cssGlobalClasses'] = array_values($sanitized);
            }
            $found = true;
            break;
        }
        unset($element);

        if (!$found) {
            return new WP_REST_Response(['success' => false, 'error' => 'Element not found in post'], 404);
        }

        if ($wasJsonString) {
            $encoded = wp_json_encode($elements, JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                return new WP_REST_Response(['success' => false, 'error' => 'JSON encode failed'], 500);
            }
            update_post_meta($postId, $metaKey, wp_slash($encoded));
        } else {
            update_post_meta($postId, $metaKey, wp_slash($elements));
        }

        return new WP_REST_Response([
            'success'  => true,
            'classIds' => $sanitized,
        ]);
    }

    /**
     * Rename a global class in `bricks_global_classes`, optionally
     * propagating to BEM siblings and rewriting matching element labels.
     */
    public function restRenameClass(WP_REST_Request $request): WP_REST_Response
    {
        $classId        = (string) $request->get_param('classId');
        $rawName        = (string) $request->get_param('name');
        $propagateBlock = (bool) $request->get_param('bemPropagateBlock');
        $renameLabels   = (bool) $request->get_param('bemRenameLabels');
        $dryRun         = (bool) $request->get_param('dryRun');

        $cleaned = sanitize_text_field($rawName);
        if ($cleaned === '') {
            return new WP_REST_Response(['success' => false, 'error' => 'Class name cannot be empty'], 400);
        }
        if (!self::isValidClassName($cleaned)) {
            return new WP_REST_Response(['success' => false, 'error' => 'Invalid class name format'], 400);
        }

        $classes = get_option('bricks_global_classes', []);
        if (!is_array($classes)) {
            return new WP_REST_Response(['success' => false, 'error' => 'No global classes found'], 404);
        }

        // Locate the source class.
        $oldName = null;
        foreach ($classes as $c) {
            if (is_array($c) && ($c['id'] ?? null) === $classId) {
                $oldName = (string) ($c['name'] ?? '');
                break;
            }
        }
        if ($oldName === null) {
            return new WP_REST_Response(['success' => false, 'error' => 'Class not found'], 404);
        }

        if ($oldName === $cleaned) {
            return new WP_REST_Response([
                'success'       => true,
                'dryRun'        => $dryRun,
                'classId'       => $classId,
                'name'          => $cleaned,
                'renamed'       => [['id' => $classId, 'oldName' => $oldName, 'newName' => $cleaned]],
                'labelChanges'  => [],
                'labelsUpdated' => 0,
            ]);
        }

        // Build the rename map: id => [oldName, newName]. The user's explicit
        // rename always wins for the focused class.
        $renameMap = [$classId => [$oldName, $cleaned]];

        // Propagation rule: if the BEM Block segment changed (regardless of
        // whether the focused class is element-only, block-only, or has a
        // modifier), rewrite the block prefix on every class in that family
        // and keep each sibling's own Element/Modifier portions intact.
        $oldParsed = Bem::parse($oldName);
        $newParsed = Bem::parse($cleaned);
        $shouldPropagate = $propagateBlock
            && $oldParsed !== null
            && $newParsed !== null
            && $oldParsed['block'] !== $newParsed['block'];

        if ($shouldPropagate) {
            $oldBlock = $oldParsed['block'];
            $newBlock = $newParsed['block'];

            foreach (Bem::findBlockFamilyClassIds($oldBlock, $classes) as $rid) {
                if ($rid === $classId) {
                    continue; // user's explicit rename already in the map
                }
                $relCls = null;
                foreach ($classes as $c) {
                    if (is_array($c) && ($c['id'] ?? null) === $rid) {
                        $relCls = $c;
                        break;
                    }
                }
                if ($relCls === null) {
                    continue;
                }
                $relOld = (string) ($relCls['name'] ?? '');
                if ($relOld === '' || strpos($relOld, $oldBlock) !== 0) {
                    continue;
                }
                // Replace just the leading block prefix with the new block.
                $relNew = $newBlock . substr($relOld, strlen($oldBlock));
                if (!self::isValidClassName($relNew)) {
                    return new WP_REST_Response([
                        'success' => false,
                        'error'   => 'Propagated rename would produce an invalid class name: ' . $relNew,
                    ], 400);
                }
                $renameMap[$rid] = [$relOld, $relNew];
            }
        }

        // Collision detection against any existing class NOT in the rename map.
        $newNamesByExistingId = [];
        foreach ($classes as $c) {
            if (!is_array($c) || !isset($c['id'], $c['name'])) {
                continue;
            }
            if (isset($renameMap[$c['id']])) {
                continue;
            }
            $newNamesByExistingId[(string) $c['name']] = (string) $c['id'];
        }
        $collisions = [];
        foreach ($renameMap as [$relOld, $relNew]) {
            if (isset($newNamesByExistingId[$relNew])) {
                $collisions[] = $relNew;
            }
        }
        if ($collisions !== []) {
            return new WP_REST_Response([
                'success'    => false,
                'error'      => 'A class with one of those names already exists: ' . implode(', ', array_unique($collisions)),
                'collisions' => array_values(array_unique($collisions)),
            ], 409);
        }

        $renamedList = [];
        foreach ($renameMap as $rid => [$ol, $nn]) {
            $renamedList[] = ['id' => $rid, 'oldName' => $ol, 'newName' => $nn];
        }

        // Dry-run: gather the label-change plan WITHOUT writing anything.
        if ($dryRun) {
            $labelPlan = $renameLabels ? $this->processElementLabels($renameMap, false) : [];
            return new WP_REST_Response([
                'success'       => true,
                'dryRun'        => true,
                'classId'       => $classId,
                'name'          => $cleaned,
                'renamed'       => $renamedList,
                'labelChanges'  => $labelPlan,
                'labelsUpdated' => 0,
            ]);
        }

        // Apply renames in a single update_option (single snapshot).
        $now = time();
        foreach ($classes as &$class) {
            if (!is_array($class)) {
                continue;
            }
            $cid = $class['id'] ?? null;
            if (!is_string($cid) || !isset($renameMap[$cid])) {
                continue;
            }
            $class['name']     = $renameMap[$cid][1];
            $class['modified'] = $now;
        }
        unset($class);

        update_option('bricks_global_classes', $classes);

        $labelChanges = $renameLabels ? $this->processElementLabels($renameMap, true) : [];

        return new WP_REST_Response([
            'success'       => true,
            'dryRun'        => false,
            'classId'       => $classId,
            'name'          => $cleaned,
            'renamed'       => $renamedList,
            'labelChanges'  => $labelChanges,
            'labelsUpdated' => count($labelChanges),
        ]);
    }

    private static function isValidClassName(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $name);
    }

    /**
     * Walk every Bricks postmeta entry (latest version per post + family),
     * find elements using any class in the rename map whose label matches
     * the OLD class-derived label, and either gather (plan) or apply the
     * label rewrites depending on $apply.
     *
     * @param array<string, array{0:string,1:string}> $renameMap classId => [oldName, newName]
     * @return list<array{postId:int, postTitle:string, elementId:string, elementLabel:?string, oldLabel:string, newLabel:string}>
     */
    private function processElementLabels(array $renameMap, bool $apply): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pm.post_id, pm.meta_key, pm.meta_value, p.post_title, p.post_type, p.post_status
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE (pm.meta_key LIKE %s OR pm.meta_key LIKE %s OR pm.meta_key LIKE %s)
               AND p.post_type != 'revision'
               AND p.post_status NOT IN ('trash', 'auto-draft')",
            $wpdb->esc_like('_bricks_page_content') . '%',
            $wpdb->esc_like('_bricks_page_header') . '%',
            $wpdb->esc_like('_bricks_page_footer') . '%'
        ));

        if (!$rows) {
            return [];
        }

        // Group by post + family, keep the highest-versioned key per group.
        $best = [];
        foreach ($rows as $row) {
            if (!preg_match('/^_bricks_page_(content|header|footer)(?:_(\d+))?$/', (string) $row->meta_key, $m)) {
                continue;
            }
            $family  = $m[1];
            $version = isset($m[2]) ? (int) $m[2] : 0;
            $key     = ((int) $row->post_id) . '|' . $family;
            if (!isset($best[$key]) || $best[$key]['version'] < $version) {
                $best[$key] = ['version' => $version, 'row' => $row];
            }
        }

        $changesList = [];

        foreach ($best as $entry) {
            $row = $entry['row'];
            $raw = $row->meta_value;

            $wasJsonString = self::looksLikeJsonContainer($raw);

            $elements = maybe_unserialize($raw);
            if (is_string($elements)) {
                $decoded = json_decode($elements, true);
                if (is_array($decoded)) {
                    $elements = $decoded;
                }
            }
            if (!is_array($elements)) {
                continue;
            }

            $postTitle = (string) ($row->post_title !== '' ? $row->post_title : '(no title)');
            $changed   = false;

            foreach ($elements as &$el) {
                if (!is_array($el)) {
                    continue;
                }
                $classIds = $el['settings']['_cssGlobalClasses'] ?? null;
                if (!is_array($classIds) || $classIds === []) {
                    continue;
                }
                $currentLabel = isset($el['label']) && is_string($el['label']) ? $el['label'] : '';
                if ($currentLabel === '') {
                    continue;
                }
                // First class on the element that's being renamed AND whose
                // derived label matches the current label wins.
                foreach ($classIds as $cid) {
                    if (!is_string($cid) || !isset($renameMap[$cid])) {
                        continue;
                    }
                    [$oldClass, $newClass] = $renameMap[$cid];
                    $oldDerived = Bem::labelFromClass($oldClass);
                    $newDerived = Bem::labelFromClass($newClass);
                    if ($oldDerived === $newDerived) {
                        continue;
                    }
                    $rewritten = Bem::rewriteLabel($currentLabel, $oldDerived, $newDerived);
                    if ($rewritten === $currentLabel) {
                        continue;
                    }
                    $changesList[] = [
                        'postId'       => (int) $row->post_id,
                        'postTitle'    => $postTitle,
                        'elementId'    => (string) ($el['id'] ?? ''),
                        'elementLabel' => $currentLabel,
                        'oldLabel'     => $currentLabel,
                        'newLabel'     => $rewritten,
                    ];
                    if ($apply) {
                        $el['label'] = $rewritten;
                        $changed     = true;
                    }
                    break; // one rename per element
                }
            }
            unset($el);

            if (!$apply || !$changed) {
                continue;
            }

            if ($wasJsonString) {
                $encoded = wp_json_encode($elements, JSON_UNESCAPED_SLASHES);
                if ($encoded === false) {
                    continue;
                }
                update_post_meta((int) $row->post_id, (string) $row->meta_key, wp_slash($encoded));
            } else {
                update_post_meta((int) $row->post_id, (string) $row->meta_key, wp_slash($elements));
            }
        }

        return $changesList;
    }

    /**
     * Save the top-level `label` on a Bricks element (NOT a `settings[...]`
     * field) — that's where the user-editable label lives in the meta tree.
     * Empty label removes the key, matching how Bricks omits empty labels.
     */
    public function restSaveLabel(WP_REST_Request $request): WP_REST_Response
    {
        $postId    = (int) $request->get_param('postId');
        $metaKey   = (string) $request->get_param('metaKey');
        $elementId = (string) $request->get_param('elementId');
        $rawLabel  = (string) $request->get_param('label');

        if ($postId <= 0 || !get_post($postId)) {
            return new WP_REST_Response(['success' => false, 'error' => 'Invalid post'], 400);
        }
        if (!current_user_can('edit_post', $postId)) {
            return new WP_REST_Response(['success' => false, 'error' => 'Forbidden'], 403);
        }
        if (!preg_match('/^_bricks_page_(content|header|footer)(?:_\d+)?$/', $metaKey)) {
            return new WP_REST_Response(['success' => false, 'error' => 'Invalid meta key'], 400);
        }

        $cleaned = sanitize_text_field($rawLabel);

        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
            $postId,
            $metaKey
        ));

        if ($raw === null) {
            return new WP_REST_Response(['success' => false, 'error' => 'Element storage not found'], 404);
        }

        $wasJsonString = self::looksLikeJsonContainer($raw);

        $elements = maybe_unserialize($raw);
        if (is_string($elements)) {
            $decoded = json_decode($elements, true);
            if (is_array($decoded)) {
                $elements = $decoded;
            }
        }
        if (!is_array($elements)) {
            return new WP_REST_Response(['success' => false, 'error' => 'Unable to decode element storage'], 500);
        }

        $found = false;
        foreach ($elements as &$element) {
            if (!is_array($element)) {
                continue;
            }
            if (($element['id'] ?? null) !== $elementId) {
                continue;
            }
            if ($cleaned === '') {
                unset($element['label']);
            } else {
                $element['label'] = $cleaned;
            }
            $found = true;
            break;
        }
        unset($element);

        if (!$found) {
            return new WP_REST_Response(['success' => false, 'error' => 'Element not found in post'], 404);
        }

        if ($wasJsonString) {
            $encoded = wp_json_encode($elements, JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                return new WP_REST_Response(['success' => false, 'error' => 'JSON encode failed'], 500);
            }
            update_post_meta($postId, $metaKey, wp_slash($encoded));
        } else {
            update_post_meta($postId, $metaKey, wp_slash($elements));
        }

        return new WP_REST_Response([
            'success' => true,
            'label'   => $cleaned,
        ]);
    }

    /** Duplicated intentionally from BricksFormManager\Module — keep modules self-contained. */
    private static function looksLikeJsonContainer(?string $raw): bool
    {
        if (!is_string($raw) || $raw === '') {
            return false;
        }
        $first = ltrim($raw)[0] ?? '';
        return $first === '{' || $first === '[';
    }

    public function restListTargets(): WP_REST_Response
    {
        return new WP_REST_Response([
            'targets' => TargetCatalog::all(),
        ]);
    }

    public function restScan(WP_REST_Request $request): WP_REST_Response
    {
        $kind = (string) $request->get_param('kind');
        $id   = (string) $request->get_param('id');
        $name = (string) $request->get_param('name');

        if ($kind !== TargetCatalog::KIND_CLASS && $kind !== TargetCatalog::KIND_VARIABLE) {
            return new WP_REST_Response(['success' => false, 'error' => 'Invalid kind'], 400);
        }
        if ($id === '' || $name === '') {
            return new WP_REST_Response(['success' => false, 'error' => 'Missing id or name'], 400);
        }

        $finder = new UsageFinder();
        $usages = $finder->find(['kind' => $kind, 'id' => $id, 'name' => $name]);

        // Per-post permalink cache to avoid N lookups on the same page.
        $permalinkCache = [];
        $data = array_map(static function (Usage $u) use (&$permalinkCache): array {
            if (!array_key_exists($u->postId, $permalinkCache)) {
                $perma = get_permalink($u->postId);
                $permalinkCache[$u->postId] = is_string($perma) ? $perma : '';
            }
            $perma = $permalinkCache[$u->postId];
            $builderUrl = $perma !== ''
                ? add_query_arg(['bricks' => 'run', 'brx_element' => $u->elementId], $perma)
                : (string) (get_edit_post_link($u->postId, 'raw') ?: '');

            return [
                'postId'       => $u->postId,
                'postTitle'    => $u->postTitle,
                'postType'     => $u->postType,
                'postStatus'   => $u->postStatus,
                'metaKey'      => $u->metaKey,
                'elementId'    => $u->elementId,
                'elementName'  => $u->elementName,
                'elementLabel' => $u->elementLabel,
                'classIds'     => $u->classIds,
                'builderUrl'   => $builderUrl,
            ];
        }, $usages);

        return new WP_REST_Response([
            'usages'      => $data,
            'engine'      => $finder->lastEngine,
            'engineError' => $finder->lastEngineError,
        ]);
    }

    public function renderAdminPage(): void
    {
        $wpcli = WpCli::status();
        ?>
        <div class="abbtl-cvf">
            <h1>
                <?php echo esc_html($this->getName()); ?>
                <span style="font-size:13px;color:#646970;font-weight:normal;margin-left:8px;">
                    v<?php echo esc_html($this->getVersion()); ?>
                </span>
            </h1>
            <p class="description"><?php echo esc_html($this->getDescription()); ?></p>

            <?php $this->renderWpCliNotice($wpcli); ?>

            <div
                x-data="abbtlCvfApp()"
                x-init="init()"
                @keydown.window="onGlobalKeydown($event)"
                style="margin-top:24px;"
            >

                <fieldset class="abbtl-cvf__bem">
                    <legend class="abbtl-cvf__bem-label"><?php esc_html_e('B.E.M Awareness:', 'ab-bricks-tools'); ?></legend>
                    <label class="abbtl-cvf__bem-toggle">
                        <input type="checkbox" x-model="bemAware.renameLabels" />
                        <span><?php esc_html_e('Rename matching element labels', 'ab-bricks-tools'); ?></span>
                    </label>
                    <label class="abbtl-cvf__bem-toggle">
                        <input type="checkbox" x-model="bemAware.propagateBlock" />
                        <span><?php esc_html_e('Rename related classes for BEM Elements', 'ab-bricks-tools'); ?></span>
                    </label>
                    <button
                        type="button"
                        class="button-link abbtl-cvf__bem-help-btn"
                        @click="openBemHelp()"
                    ><?php esc_html_e('How it works', 'ab-bricks-tools'); ?></button>
                </fieldset>

                <div class="abbtl-cvf__picker">
                    <div class="abbtl-cvf__picker-header">
                        <div class="abbtl-cvf__kind-filter" role="group" aria-label="<?php echo esc_attr__('Filter by kind', 'ab-bricks-tools'); ?>">
                            <button
                                type="button"
                                :class="{ 'is-active': targetKindFilter === 'all' }"
                                @click="targetKindFilter = 'all'"
                            ><?php esc_html_e('All', 'ab-bricks-tools'); ?></button>
                            <button
                                type="button"
                                :class="{ 'is-active': targetKindFilter === 'class' }"
                                @click="targetKindFilter = 'class'"
                            ><?php esc_html_e('Classes', 'ab-bricks-tools'); ?></button>
                            <button
                                type="button"
                                :class="{ 'is-active': targetKindFilter === 'variable' }"
                                @click="targetKindFilter = 'variable'"
                            ><?php esc_html_e('Variables', 'ab-bricks-tools'); ?></button>
                        </div>
                        <label class="abbtl-cvf__picker-search">
                            <span><?php esc_html_e('Filter:', 'ab-bricks-tools'); ?></span>
                            <input
                                type="search"
                                x-model.debounce.150ms="targetFilter"
                                placeholder="<?php echo esc_attr__('Class or variable name…', 'ab-bricks-tools'); ?>"
                            />
                        </label>
                        <span class="abbtl-cvf__picker-count" x-text="filteredTargets.length + ' / ' + targets.length"></span>
                    </div>

                    <div class="abbtl-cvf__target-list" x-show="!loading" x-cloak>
                        <template x-for="target in filteredTargets" :key="target.kind + ':' + target.id">
                            <div
                                role="button"
                                tabindex="0"
                                class="abbtl-cvf__target"
                                :class="{ 'is-selected': isSelected(target) }"
                                @click="onPickerRowClick(target)"
                                @dblclick="onPickerRowDblClick(target)"
                                @keydown.enter.prevent="selectTarget(target)"
                            >
                                <span class="abbtl-cvf__badge" :class="'abbtl-cvf__badge--' + target.kind" x-text="target.kind === 'class' ? 'Class' : 'Variable'"></span>
                                <code
                                    x-show="!isPickerRenaming(target.id)"
                                    x-text="target.kind === 'class' ? ('.' + target.name) : ('--' + target.name)"
                                    :title="target.kind === 'class' ? '<?php echo esc_attr__('Double-click to rename globally', 'ab-bricks-tools'); ?>' : ''"
                                ></code>
                                <template x-if="isPickerRenaming(target.id)">
                                    <input
                                        type="text"
                                        class="abbtl-cvf__target-rename"
                                        x-init="$el.focus(); $el.select();"
                                        x-model="inlineRename.value"
                                        :disabled="inlineRename.saving"
                                        @click.stop
                                        @blur="commitInlineRename()"
                                        @keydown.enter.prevent="commitInlineRename()"
                                        @keydown.escape.prevent="cancelInlineRename()"
                                    />
                                </template>
                                <small
                                    x-show="isPickerRenaming(target.id) && inlineRename.error"
                                    x-cloak
                                    class="abbtl-cvf__rename-error"
                                    x-text="inlineRename.error"
                                ></small>
                            </div>
                        </template>
                        <p
                            x-show="filteredTargets.length === 0"
                            x-cloak
                            class="abbtl-cvf__picker-empty"
                        >
                            <em><?php esc_html_e('No matches.', 'ab-bricks-tools'); ?></em>
                        </p>
                    </div>

                    <p x-show="loading" x-cloak><em><?php esc_html_e('Loading classes and variables…', 'ab-bricks-tools'); ?></em></p>
                </div>

                <div class="abbtl-cvf__selection">
                    <div class="abbtl-cvf__selection-meta" x-show="selectedTarget" x-cloak>
                        <span class="abbtl-cvf__selection-label"><?php esc_html_e('Scanning for:', 'ab-bricks-tools'); ?></span>
                        <code class="abbtl-cvf__selection-token" x-text="selectionToken()"></code>
                        <button
                            type="button"
                            class="button"
                            @click="scan()"
                            :disabled="scanning"
                        >
                            <span x-show="!scanning"><?php esc_html_e('Re-scan', 'ab-bricks-tools'); ?></span>
                            <span x-show="scanning" x-cloak><?php esc_html_e('Scanning…', 'ab-bricks-tools'); ?></span>
                        </button>
                        <span x-show="scanned && !error" x-cloak class="abbtl-cvf__count">
                            <span x-text="usages.length + ' <?php echo esc_attr__('usages', 'ab-bricks-tools'); ?>'"></span>
                            <small style="margin-left:8px;color:#646970;">
                                <?php esc_html_e('engine:', 'ab-bricks-tools'); ?>
                                <code x-text="engine || 'unknown'"></code>
                            </small>
                        </span>
                    </div>
                    <div class="abbtl-cvf__selection-actions">
                        <button
                            type="button"
                            class="button"
                            @click="openRevisionsModal()"
                        ><?php esc_html_e('Revisions', 'ab-bricks-tools'); ?></button>
                    </div>
                </div>

                <details
                    x-show="scanned && engineError"
                    x-cloak
                    style="margin:8px 0;padding:8px 12px;background:#fdf6e3;border-left:3px solid #dba617;border-radius:3px;font-size:12px;"
                >
                    <summary style="cursor:pointer;color:#a86b00;">
                        <?php esc_html_e('WP-CLI was available but the scan fell back to PHP — click to see why', 'ab-bricks-tools'); ?>
                    </summary>
                    <pre x-text="JSON.stringify(engineError, null, 2)" style="margin:8px 0 0;white-space:pre-wrap;word-break:break-word;color:#5a4500;"></pre>
                </details>

                <div x-show="error" x-cloak class="notice notice-error inline">
                    <p x-text="error"></p>
                </div>

                <div x-show="scanned && !error && usages.length > 0" x-cloak>
                    <table class="wp-list-table widefat striped abbtl-cvf__table">
                        <thead>
                            <tr>
                                <th scope="col"><?php esc_html_e('Page', 'ab-bricks-tools'); ?></th>
                                <th scope="col"><?php esc_html_e('Element Label', 'ab-bricks-tools'); ?></th>
                                <th scope="col"><?php esc_html_e('Element Type', 'ab-bricks-tools'); ?></th>
                                <th scope="col"><?php esc_html_e('Element ID', 'ab-bricks-tools'); ?></th>
                                <th scope="col"><?php esc_html_e('Classes', 'ab-bricks-tools'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="usage in pagedUsages" :key="usage.postId + '|' + usage.metaKey + '|' + usage.elementId">
                                <tr>
                                    <td>
                                        <strong x-text="usage.postTitle"></strong>
                                        <span class="abbtl-cvf__status" x-show="usage.postStatus !== 'publish'" x-cloak x-text="'(' + usage.postStatus + ')'"></span>
                                    </td>
                                    <td class="abbtl-cvf__label-cell">
                                        <span x-show="!isEditing(usage, 'elementLabel')" class="abbtl-cvf__label-display">
                                            <span
                                                class="abbtl-cvf__label-text"
                                                @dblclick="startEdit(usage, 'elementLabel')"
                                                x-text="usage.elementLabel || usage.elementName || '—'"
                                                title="<?php echo esc_attr__('Double-click to edit', 'ab-bricks-tools'); ?>"
                                            ></span>
                                            <a
                                                :href="usage.builderUrl"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="abbtl-cvf__open"
                                                title="<?php echo esc_attr__('Open element in Bricks Builder', 'ab-bricks-tools'); ?>"
                                                aria-label="<?php echo esc_attr__('Open element in Bricks Builder', 'ab-bricks-tools'); ?>"
                                            >↗</a>
                                        </span>
                                        <template x-if="isEditing(usage, 'elementLabel')">
                                            <span class="abbtl-cvf__cell-edit" :class="{ 'is-saving': editing.saving, 'is-error': editing.error }">
                                                <input
                                                    class="abbtl-cvf__edit-input"
                                                    type="text"
                                                    x-init="$el.focus(); $el.select();"
                                                    x-model="editing.value"
                                                    @blur="commitEdit()"
                                                    @keydown.enter.prevent="commitEdit()"
                                                    @keydown.escape.prevent="cancelEdit()"
                                                />
                                                <small class="abbtl-cvf__cell-status" x-show="editing.saving" x-cloak><?php esc_html_e('Saving…', 'ab-bricks-tools'); ?></small>
                                                <small class="abbtl-cvf__cell-status abbtl-cvf__cell-status--error" x-show="editing.error" x-cloak x-text="editing.error"></small>
                                            </span>
                                        </template>
                                    </td>
                                    <td><code x-text="usage.elementName || '—'"></code></td>
                                    <td><code x-text="usage.elementId"></code></td>
                                    <td class="abbtl-cvf__classes-cell">
                                        <ul class="abbtl-cvf__class-chips">
                                            <template x-for="cid in (usage.classIds || [])" :key="cid">
                                                <li
                                                    class="abbtl-cvf__class-chip"
                                                    :class="{ 'is-renaming': isChipRenaming(usageKey(usage), cid) }"
                                                    @dblclick="startChipRename(usage, cid)"
                                                    title="<?php echo esc_attr__('Double-click to rename globally', 'ab-bricks-tools'); ?>"
                                                >
                                                    <span
                                                        x-show="!isChipRenaming(usageKey(usage), cid)"
                                                        x-text="'.' + (classNameById(cid) || cid)"
                                                    ></span>
                                                    <template x-if="isChipRenaming(usageKey(usage), cid)">
                                                        <input
                                                            type="text"
                                                            class="abbtl-cvf__chip-rename"
                                                            x-init="$el.focus(); $el.select();"
                                                            x-model="inlineRename.value"
                                                            :disabled="inlineRename.saving"
                                                            @blur="commitInlineRename()"
                                                            @keydown.enter.prevent="commitInlineRename()"
                                                            @keydown.escape.prevent="cancelInlineRename()"
                                                        />
                                                    </template>
                                                </li>
                                            </template>
                                            <li x-show="!usage.classIds || usage.classIds.length === 0" class="abbtl-cvf__class-empty">—</li>
                                        </ul>
                                        <button
                                            type="button"
                                            class="button button-small abbtl-cvf__classes-edit"
                                            @click="openClassesModal(usage)"
                                        ><?php esc_html_e('Edit', 'ab-bricks-tools'); ?></button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>

                    <div class="abbtl-cvf__pagination" x-show="totalPages > 1" x-cloak>
                        <button
                            type="button"
                            class="button"
                            @click="prevPage()"
                            :disabled="page === 1"
                        >&larr; <?php esc_html_e('Previous', 'ab-bricks-tools'); ?></button>
                        <span class="abbtl-cvf__pagination-info">
                            <?php
                            /* translators: 1: current page, 2: total pages, 3: total usages */
                            printf(
                                esc_html__('Page %1$s of %2$s — showing %3$s of', 'ab-bricks-tools'),
                                '<span x-text="page"></span>',
                                '<span x-text="totalPages"></span>',
                                '<span x-text="pagedUsages.length"></span>'
                            );
                            ?>
                            <span x-text="usages.length"></span>
                            <?php esc_html_e('total', 'ab-bricks-tools'); ?>
                        </span>
                        <button
                            type="button"
                            class="button"
                            @click="nextPage()"
                            :disabled="page === totalPages"
                        ><?php esc_html_e('Next', 'ab-bricks-tools'); ?> &rarr;</button>
                    </div>
                </div>

                <p x-show="scanned && !error && usages.length === 0" x-cloak style="margin-top:12px;">
                    <em><?php esc_html_e('No usages found for this target.', 'ab-bricks-tools'); ?></em>
                </p>

                <div
                    x-show="classesModal.open"
                    x-cloak
                    class="abbtl-cvf__modal-overlay"
                    @click.self="closeClassesModal()"
                    @keydown.escape.window="if (classesModal.open) closeClassesModal()"
                >
                    <div class="abbtl-cvf__modal" role="dialog" aria-modal="true" aria-labelledby="abbtl-cvf-modal-title">
                        <header class="abbtl-cvf__modal-header">
                            <div>
                                <h2 id="abbtl-cvf-modal-title"><?php esc_html_e('Edit Classes', 'ab-bricks-tools'); ?></h2>
                                <p class="abbtl-cvf__modal-subtitle" x-text="classesModal.usage ? (classesModal.usage.postTitle + ' · ' + (classesModal.usage.elementLabel || classesModal.usage.elementName)) : ''"></p>
                            </div>
                            <button type="button" class="abbtl-cvf__modal-close" @click="closeClassesModal()" aria-label="<?php echo esc_attr__('Close', 'ab-bricks-tools'); ?>">&times;</button>
                        </header>

                        <section class="abbtl-cvf__modal-body">
                            <p class="abbtl-cvf__modal-warning">
                                <strong><?php esc_html_e('Heads up:', 'ab-bricks-tools'); ?></strong>
                                <?php esc_html_e('Renaming a class applies globally — every element using that class will be updated.', 'ab-bricks-tools'); ?>
                            </p>

                            <h3 class="abbtl-cvf__modal-section-heading"><?php esc_html_e('Classes on this element', 'ab-bricks-tools'); ?></h3>
                            <ol class="abbtl-cvf__modal-class-list">
                                <template x-for="(cls, idx) in classesModal.classes" :key="cls.id">
                                    <li
                                        class="abbtl-cvf__modal-class-row"
                                        :class="{ 'is-dragging': classesModal.dragIndex === idx }"
                                        draggable="true"
                                        @dragstart="onClassDragStart($event, idx)"
                                        @dragover.prevent
                                        @drop.prevent="onClassDrop($event, idx)"
                                        @dragend="onClassDragEnd"
                                    >
                                        <span class="abbtl-cvf__modal-drag-handle" aria-hidden="true" title="<?php echo esc_attr__('Drag to reorder', 'ab-bricks-tools'); ?>">⋮⋮</span>
                                        <span
                                            class="abbtl-cvf__modal-class-name"
                                            x-show="!isClassEditing(cls.id)"
                                            @dblclick="startClassRename(cls)"
                                            x-text="'.' + cls.name"
                                            title="<?php echo esc_attr__('Double-click to rename (global)', 'ab-bricks-tools'); ?>"
                                        ></span>
                                        <template x-if="isClassEditing(cls.id)">
                                            <input
                                                class="abbtl-cvf__modal-class-input"
                                                type="text"
                                                x-init="$el.focus(); $el.select();"
                                                x-model="classesModal.renameValue"
                                                @blur="commitClassRename()"
                                                @keydown.enter.prevent="commitClassRename()"
                                                @keydown.escape.prevent="cancelClassRename()"
                                            />
                                        </template>
                                        <button
                                            type="button"
                                            class="abbtl-cvf__modal-remove"
                                            @click="removeClassFromElement(idx)"
                                            aria-label="<?php echo esc_attr__('Remove class', 'ab-bricks-tools'); ?>"
                                            title="<?php echo esc_attr__('Remove from this element', 'ab-bricks-tools'); ?>"
                                        >&times;</button>
                                    </li>
                                </template>
                                <li x-show="classesModal.classes.length === 0" x-cloak class="abbtl-cvf__modal-empty">
                                    <em><?php esc_html_e('No classes on this element.', 'ab-bricks-tools'); ?></em>
                                </li>
                            </ol>

                            <h3 class="abbtl-cvf__modal-section-heading"><?php esc_html_e('Add a class', 'ab-bricks-tools'); ?></h3>
                            <div
                                class="abbtl-cvf__combobox"
                                @click.outside="classesModal.addOpen = false"
                            >
                                <input
                                    type="text"
                                    class="abbtl-cvf__combobox-input"
                                    x-model="classesModal.addFilter"
                                    @focus="onAddComboFocus()"
                                    @keydown="onAddComboKeydown($event)"
                                    placeholder="<?php echo esc_attr__('Type to filter classes…', 'ab-bricks-tools'); ?>"
                                    autocomplete="off"
                                    aria-autocomplete="list"
                                    role="combobox"
                                    :aria-expanded="classesModal.addOpen.toString()"
                                />
                                <ul
                                    x-show="classesModal.addOpen && filteredAvailableClasses.length > 0"
                                    x-cloak
                                    class="abbtl-cvf__combobox-list"
                                    role="listbox"
                                >
                                    <template x-for="(t, idx) in filteredAvailableClasses" :key="t.id">
                                        <li
                                            class="abbtl-cvf__combobox-option"
                                            :class="{ 'is-highlighted': idx === classesModal.addHighlight }"
                                            @mousedown.prevent="pickClassFromCombobox(t.id)"
                                            @mouseenter="classesModal.addHighlight = idx"
                                            role="option"
                                            :aria-selected="(idx === classesModal.addHighlight).toString()"
                                            x-text="'.' + t.name"
                                        ></li>
                                    </template>
                                </ul>
                                <p
                                    x-show="classesModal.addOpen && filteredAvailableClasses.length === 0 && availableClassesToAdd.length > 0"
                                    x-cloak
                                    class="abbtl-cvf__combobox-empty"
                                ><em><?php esc_html_e('No matching classes.', 'ab-bricks-tools'); ?></em></p>
                                <p
                                    x-show="availableClassesToAdd.length === 0"
                                    x-cloak
                                    class="abbtl-cvf__combobox-empty"
                                ><em><?php esc_html_e('All global classes are already on this element.', 'ab-bricks-tools'); ?></em></p>
                            </div>

                            <p x-show="classesModal.error" x-cloak class="abbtl-cvf__modal-error" x-text="classesModal.error"></p>
                        </section>

                        <footer class="abbtl-cvf__modal-footer">
                            <button type="button" class="button button-primary" @click="closeClassesModal()"><?php esc_html_e('Done', 'ab-bricks-tools'); ?></button>
                        </footer>
                    </div>
                </div>

                <div
                    x-show="revisionsModal.open"
                    x-cloak
                    class="abbtl-cvf__modal-overlay"
                    @click.self="closeRevisionsModal()"
                    @keydown.escape.window="if (revisionsModal.open) closeRevisionsModal()"
                >
                    <div class="abbtl-cvf__modal abbtl-cvf__modal--wide" role="dialog" aria-modal="true" aria-labelledby="abbtl-cvf-rev-title">
                        <header class="abbtl-cvf__modal-header">
                            <div>
                                <h2 id="abbtl-cvf-rev-title"><?php esc_html_e('Revisions', 'ab-bricks-tools'); ?></h2>
                                <p class="abbtl-cvf__modal-subtitle">
                                    <?php esc_html_e('Snapshots taken before each change to global classes and variables. Last 50 of each kind.', 'ab-bricks-tools'); ?>
                                </p>
                            </div>
                            <button type="button" class="abbtl-cvf__modal-close" @click="closeRevisionsModal()" aria-label="<?php echo esc_attr__('Close', 'ab-bricks-tools'); ?>">&times;</button>
                        </header>

                        <nav class="abbtl-cvf__rev-tabs" role="tablist">
                            <button
                                type="button"
                                class="abbtl-cvf__rev-tab"
                                :class="{ 'is-active': revisionsModal.kind === 'classes' }"
                                @click="switchRevKind('classes')"
                                role="tab"
                                :aria-selected="(revisionsModal.kind === 'classes').toString()"
                            ><?php esc_html_e('Classes', 'ab-bricks-tools'); ?></button>
                            <button
                                type="button"
                                class="abbtl-cvf__rev-tab"
                                :class="{ 'is-active': revisionsModal.kind === 'variables' }"
                                @click="switchRevKind('variables')"
                                role="tab"
                                :aria-selected="(revisionsModal.kind === 'variables').toString()"
                            ><?php esc_html_e('Variables', 'ab-bricks-tools'); ?></button>
                        </nav>

                        <section class="abbtl-cvf__modal-body">
                            <p x-show="revisionsModal.loading" x-cloak class="abbtl-cvf__rev-loading">
                                <em><?php esc_html_e('Loading revisions…', 'ab-bricks-tools'); ?></em>
                            </p>

                            <p x-show="!revisionsModal.loading && revisionsModal.list.length === 0" x-cloak class="abbtl-cvf__rev-empty">
                                <em><?php esc_html_e('No revisions yet. Snapshots are captured automatically when global classes or variables change.', 'ab-bricks-tools'); ?></em>
                            </p>

                            <table x-show="!revisionsModal.loading && revisionsModal.list.length > 0" x-cloak class="wp-list-table widefat striped abbtl-cvf__rev-table">
                                <thead>
                                    <tr>
                                        <th scope="col" style="width:200px;"><?php esc_html_e('When', 'ab-bricks-tools'); ?></th>
                                        <th scope="col"><?php esc_html_e('What changed', 'ab-bricks-tools'); ?></th>
                                        <th scope="col" style="width:200px;text-align:right;"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="rev in revisionsModal.list" :key="rev.id">
                                        <tr>
                                            <td x-text="rev.when"></td>
                                            <td x-text="rev.summary"></td>
                                            <td style="text-align:right;">
                                                <template x-if="revisionsModal.confirmingId !== rev.id">
                                                    <button
                                                        type="button"
                                                        class="button button-small"
                                                        @click="confirmRestore(rev.id)"
                                                    ><?php esc_html_e('Restore', 'ab-bricks-tools'); ?></button>
                                                </template>
                                                <template x-if="revisionsModal.confirmingId === rev.id">
                                                    <span class="abbtl-cvf__rev-confirm">
                                                        <button
                                                            type="button"
                                                            class="button button-small button-link-delete"
                                                            @click="doRestore(rev.id)"
                                                            :disabled="revisionsModal.restoring"
                                                        ><?php esc_html_e('Confirm', 'ab-bricks-tools'); ?></button>
                                                        <button
                                                            type="button"
                                                            class="button button-small"
                                                            @click="cancelRestoreConfirm()"
                                                        ><?php esc_html_e('Cancel', 'ab-bricks-tools'); ?></button>
                                                    </span>
                                                </template>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>

                            <p x-show="revisionsModal.error" x-cloak class="abbtl-cvf__modal-error" x-text="revisionsModal.error"></p>
                        </section>

                        <footer class="abbtl-cvf__modal-footer">
                            <button type="button" class="button button-primary" @click="closeRevisionsModal()"><?php esc_html_e('Done', 'ab-bricks-tools'); ?></button>
                        </footer>
                    </div>
                </div>

                <div
                    x-show="renamePreview.open"
                    x-cloak
                    class="abbtl-cvf__modal-overlay"
                    @click.self="cancelRenamePreview()"
                    @keydown.escape.window="if (renamePreview.open) cancelRenamePreview()"
                >
                    <div class="abbtl-cvf__modal abbtl-cvf__modal--wide" role="dialog" aria-modal="true" aria-labelledby="abbtl-cvf-rename-preview-title">
                        <header class="abbtl-cvf__modal-header">
                            <div>
                                <h2 id="abbtl-cvf-rename-preview-title"><?php esc_html_e('Confirm BEM-aware rename', 'ab-bricks-tools'); ?></h2>
                                <p class="abbtl-cvf__modal-subtitle">
                                    <?php esc_html_e('These cascading changes will be applied. Review before confirming.', 'ab-bricks-tools'); ?>
                                </p>
                            </div>
                            <button type="button" class="abbtl-cvf__modal-close" @click="cancelRenamePreview()" aria-label="<?php echo esc_attr__('Close', 'ab-bricks-tools'); ?>">&times;</button>
                        </header>

                        <section class="abbtl-cvf__modal-body">
                            <h3 class="abbtl-cvf__modal-section-heading">
                                <?php esc_html_e('Classes that will be renamed', 'ab-bricks-tools'); ?>
                                <span class="abbtl-cvf__preview-count" x-text="'(' + renamePreview.classRenames.length + ')'"></span>
                            </h3>
                            <ul class="abbtl-cvf__preview-class-list">
                                <template x-for="r in renamePreview.classRenames" :key="r.id">
                                    <li class="abbtl-cvf__preview-row">
                                        <code class="abbtl-cvf__preview-old" x-text="'.' + r.oldName"></code>
                                        <span class="abbtl-cvf__preview-arrow" aria-hidden="true">→</span>
                                        <code class="abbtl-cvf__preview-new" x-text="'.' + r.newName"></code>
                                    </li>
                                </template>
                            </ul>

                            <template x-if="renamePreview.labelChanges.length > 0">
                                <div>
                                    <h3 class="abbtl-cvf__modal-section-heading">
                                        <?php esc_html_e('Element labels that will change', 'ab-bricks-tools'); ?>
                                        <span class="abbtl-cvf__preview-count" x-text="'(' + renamePreview.labelChanges.length + ')'"></span>
                                    </h3>
                                    <div class="abbtl-cvf__preview-labels-wrap">
                                        <table class="wp-list-table widefat striped abbtl-cvf__preview-labels">
                                            <thead>
                                                <tr>
                                                    <th scope="col"><?php esc_html_e('Page', 'ab-bricks-tools'); ?></th>
                                                    <th scope="col"><?php esc_html_e('Old label', 'ab-bricks-tools'); ?></th>
                                                    <th scope="col"><?php esc_html_e('New label', 'ab-bricks-tools'); ?></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <template x-for="c in renamePreview.labelChanges" :key="c.postId + '|' + c.elementId">
                                                    <tr>
                                                        <td x-text="c.postTitle"></td>
                                                        <td x-text="c.oldLabel"></td>
                                                        <td x-text="c.newLabel"></td>
                                                    </tr>
                                                </template>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </template>

                            <template x-if="renamePreview.labelChanges.length === 0 && renamePreview.classRenames.length > 1">
                                <p class="abbtl-cvf__preview-note">
                                    <em><?php esc_html_e('No element labels match the rename pattern — only classes will change.', 'ab-bricks-tools'); ?></em>
                                </p>
                            </template>

                            <p x-show="renamePreview.error" x-cloak class="abbtl-cvf__modal-error" x-text="renamePreview.error"></p>
                        </section>

                        <footer class="abbtl-cvf__modal-footer">
                            <button
                                type="button"
                                class="button"
                                @click="cancelRenamePreview()"
                                :disabled="renamePreview.saving"
                            ><?php esc_html_e('Cancel', 'ab-bricks-tools'); ?></button>
                            <button
                                type="button"
                                class="button button-primary"
                                @click="applyRenamePreview()"
                                :disabled="renamePreview.saving"
                            >
                                <span x-show="!renamePreview.saving"><?php esc_html_e('Apply', 'ab-bricks-tools'); ?></span>
                                <span x-show="renamePreview.saving" x-cloak><?php esc_html_e('Applying…', 'ab-bricks-tools'); ?></span>
                            </button>
                        </footer>
                    </div>
                </div>

                <div
                    x-show="bemHelp.open"
                    x-cloak
                    class="abbtl-cvf__modal-overlay"
                    @click.self="closeBemHelp()"
                    @keydown.escape.window="if (bemHelp.open) closeBemHelp()"
                >
                    <div class="abbtl-cvf__modal abbtl-cvf__modal--wide" role="dialog" aria-modal="true" aria-labelledby="abbtl-cvf-bem-help-title">
                        <header class="abbtl-cvf__modal-header">
                            <div>
                                <h2 id="abbtl-cvf-bem-help-title"><?php esc_html_e('B.E.M Awareness — How it works', 'ab-bricks-tools'); ?></h2>
                                <p class="abbtl-cvf__modal-subtitle">
                                    <?php esc_html_e('How the two toggles affect class renames and element labels.', 'ab-bricks-tools'); ?>
                                </p>
                            </div>
                            <button type="button" class="abbtl-cvf__modal-close" @click="closeBemHelp()" aria-label="<?php echo esc_attr__('Close', 'ab-bricks-tools'); ?>">&times;</button>
                        </header>

                        <section class="abbtl-cvf__modal-body abbtl-cvf__bem-help">
                            <h3><?php esc_html_e('BEM segments', 'ab-bricks-tools'); ?></h3>
                            <p>
                                <?php
                                echo wp_kses(
                                    __('Bricks Tools recognizes class names that follow the standard <strong>Block / Element / Modifier</strong> convention. <code>__</code> separates the block from the element. <code>--</code> separates the modifier from whatever comes before it. Words within a single segment use <code>-</code>. Classes that don\'t match this shape are treated as a single Block.', 'ab-bricks-tools'),
                                    ['strong' => [], 'code' => []]
                                );
                                ?>
                            </p>
                            <table class="abbtl-cvf__help-table">
                                <thead><tr><th><?php esc_html_e('Class', 'ab-bricks-tools'); ?></th><th><?php esc_html_e('Parsed as', 'ab-bricks-tools'); ?></th></tr></thead>
                                <tbody>
                                    <tr><td><code>.card</code></td><td><?php esc_html_e('Block only', 'ab-bricks-tools'); ?></td></tr>
                                    <tr><td><code>.card__title</code></td><td><?php esc_html_e('Block + Element', 'ab-bricks-tools'); ?></td></tr>
                                    <tr><td><code>.card--featured</code></td><td><?php esc_html_e('Block + Modifier', 'ab-bricks-tools'); ?></td></tr>
                                    <tr><td><code>.card__title--big</code></td><td><?php esc_html_e('Block + Element + Modifier', 'ab-bricks-tools'); ?></td></tr>
                                </tbody>
                            </table>

                            <h3><?php esc_html_e('Class propagation', 'ab-bricks-tools'); ?></h3>
                            <p><strong><?php esc_html_e('"Rename related classes for BEM Elements"', 'ab-bricks-tools'); ?></strong></p>
                            <p>
                                <?php esc_html_e('When you rename a class, the tool compares the BEM segments of the OLD and NEW names:', 'ab-bricks-tools'); ?>
                            </p>
                            <ul>
                                <li>
                                    <?php
                                    echo wp_kses(
                                        __('<strong>Block segment changed</strong> → propagation fires. Every class in the same block family (the block-only class itself plus every <code>block__*</code> and <code>block--*</code> sibling) gets its leading block prefix swapped to the new block. Each sibling\'s own Element / Modifier portions are preserved.', 'ab-bricks-tools'),
                                        ['strong' => [], 'code' => []]
                                    );
                                    ?>
                                </li>
                                <li>
                                    <?php
                                    echo wp_kses(
                                        __('<strong>Block unchanged</strong> (only the Element or Modifier portion was edited) → only the one class renames. No siblings are touched.', 'ab-bricks-tools'),
                                        ['strong' => []]
                                    );
                                    ?>
                                </li>
                            </ul>
                            <p>
                                <?php
                                echo wp_kses(
                                    __('Example: renaming <code>.card-04__title</code> to <code>.card__title</code> changes the Block (<code>card-04</code> → <code>card</code>). The tool also renames <code>.card-04</code>, <code>.card-04__image</code>, <code>.card-04--featured</code>, <code>.card-04__title--big</code>, etc.', 'ab-bricks-tools'),
                                    ['code' => []]
                                );
                                ?>
                            </p>
                            <p>
                                <?php esc_html_e('If any propagated rename would collide with an existing class, the whole transaction is rejected with a list of conflicting names — fix the conflicts (delete or rename them) and try again.', 'ab-bricks-tools'); ?>
                            </p>
                            <p>
                                <?php esc_html_e('Before applying, a confirmation modal lists every class rename so you can verify or cancel.', 'ab-bricks-tools'); ?>
                            </p>

                            <h3><?php esc_html_e('Element label rewriting', 'ab-bricks-tools'); ?></h3>
                            <p><strong><?php esc_html_e('"Rename matching element labels"', 'ab-bricks-tools'); ?></strong></p>
                            <p>
                                <?php esc_html_e('After classes are renamed, the tool can also rewrite element labels site-wide so they stay in sync. A label is rewritten only when ALL of these are true:', 'ab-bricks-tools'); ?>
                            </p>
                            <ol>
                                <li><?php esc_html_e('The element uses one of the renamed classes (it appears in `settings._cssGlobalClasses`).', 'ab-bricks-tools'); ?></li>
                                <li><?php esc_html_e('The element has a non-empty label.', 'ab-bricks-tools'); ?></li>
                                <li><?php esc_html_e('The element\'s label normalize-matches the label derived from the OLD class name.', 'ab-bricks-tools'); ?></li>
                            </ol>
                            <p>
                                <?php
                                echo wp_kses(
                                    __('"Normalize" means: strip any text enclosed in <code>(…)</code>, <code>[…]</code> or <code>{…}</code>, trim whitespace, lowercase, then join words with <code>-</code>. So <code>"Brand Card (left)"</code> normalizes to <code>brand-card</code>.', 'ab-bricks-tools'),
                                    ['code' => []]
                                );
                                ?>
                            </p>

                            <h4><?php esc_html_e('How a label is derived from a class', 'ab-bricks-tools'); ?></h4>
                            <table class="abbtl-cvf__help-table">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e('Class shape', 'ab-bricks-tools'); ?></th>
                                        <th><?php esc_html_e('Label uses', 'ab-bricks-tools'); ?></th>
                                        <th><?php esc_html_e('Example', 'ab-bricks-tools'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td><code>.card</code></td><td><?php esc_html_e('Block segment', 'ab-bricks-tools'); ?></td><td><code>Card</code></td></tr>
                                    <tr><td><code>.card__title</code></td><td><?php esc_html_e('Element segment', 'ab-bricks-tools'); ?></td><td><code>Title</code></td></tr>
                                    <tr><td><code>.card__title--big</code></td><td><?php esc_html_e('Element segment (M ignored)', 'ab-bricks-tools'); ?></td><td><code>Title</code></td></tr>
                                    <tr><td><code>.card--featured</code></td><td><?php esc_html_e('Block segment (M ignored)', 'ab-bricks-tools'); ?></td><td><code>Card</code></td></tr>
                                </tbody>
                            </table>
                            <p>
                                <?php
                                echo wp_kses(
                                    __('Conversion: dashes within a segment become spaces; sentence case (first letter upper, rest lower). So <code>brand-card</code> → <code>Brand card</code>.', 'ab-bricks-tools'),
                                    ['code' => []]
                                );
                                ?>
                            </p>

                            <h3><?php esc_html_e('Comments in labels are preserved', 'ab-bricks-tools'); ?></h3>
                            <p>
                                <?php
                                echo wp_kses(
                                    __('Anything enclosed in <code>(…)</code>, <code>[…]</code>, or <code>{…}</code> is treated as a label comment. Comments are stripped <em>before matching</em>, then re-inserted in their original position when the label is rewritten.', 'ab-bricks-tools'),
                                    ['code' => [], 'em' => []]
                                );
                                ?>
                            </p>
                            <table class="abbtl-cvf__help-table">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e('Before', 'ab-bricks-tools'); ?></th>
                                        <th><?php esc_html_e('After', 'ab-bricks-tools'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td><code>Brand Card</code></td><td><code>Tile card</code></td></tr>
                                    <tr><td><code>Brand Card (left)</code></td><td><code>Tile card (left)</code></td></tr>
                                    <tr><td><code>Brand Card [hero]</code></td><td><code>Tile card [hero]</code></td></tr>
                                    <tr><td><code>Brand Card {variant 2}</code></td><td><code>Tile card {variant 2}</code></td></tr>
                                </tbody>
                            </table>
                            <p class="abbtl-cvf__help-caption">
                                <?php
                                echo wp_kses(
                                    __('All four cases come from renaming <code>.brand-card</code> to <code>.tile-card</code>.', 'ab-bricks-tools'),
                                    ['code' => []]
                                );
                                ?>
                            </p>

                            <h3><?php esc_html_e('What is skipped', 'ab-bricks-tools'); ?></h3>
                            <ul>
                                <li><?php esc_html_e('Elements whose label was customized to something that doesn\'t normalize-match the OLD class\'s derived label. The tool assumes a custom label is intentional and leaves it alone.', 'ab-bricks-tools'); ?></li>
                                <li><?php esc_html_e('Elements without a label at all (nothing to rewrite).', 'ab-bricks-tools'); ?></li>
                                <li><?php esc_html_e('Post revisions, posts in the Trash, and auto-drafts (the scan ignores them).', 'ab-bricks-tools'); ?></li>
                                <li><?php esc_html_e('Global Variables — variable renames have no UI yet, so BEM only applies to class renames.', 'ab-bricks-tools'); ?></li>
                                <li><?php
                                    echo wp_kses(
                                        __('Multiple matches on one element: when several renamed classes appear on the same element, only the <strong>first</strong> match in <code>_cssGlobalClasses</code> order produces a label rewrite. One rewrite per element.', 'ab-bricks-tools'),
                                        ['strong' => [], 'code' => []]
                                    );
                                ?></li>
                                <li><?php esc_html_e('If both toggles are OFF, no propagation and no label rewrites happen — only the single class you targeted is renamed.', 'ab-bricks-tools'); ?></li>
                            </ul>

                            <h3><?php esc_html_e('Atomic + revertible', 'ab-bricks-tools'); ?></h3>
                            <p>
                                <?php esc_html_e('A BEM rename writes all affected classes in one transaction, producing exactly one entry in the Revisions list. Press Ctrl/Cmd+Z (or click Restore in the Revisions modal) to undo the whole batch.', 'ab-bricks-tools'); ?>
                            </p>
                        </section>

                        <footer class="abbtl-cvf__modal-footer">
                            <button type="button" class="button button-primary" @click="closeBemHelp()"><?php esc_html_e('Got it', 'ab-bricks-tools'); ?></button>
                        </footer>
                    </div>
                </div>
            </div>

            <script>
                function abbtlCvfApp() {
                    return {
                        targets: [],
                        targetFilter: '',
                        targetKindFilter: 'all',
                        selectedTarget: null,
                        loading: false,

                        usages: [],
                        engine: '',
                        engineError: null,
                        scanning: false,
                        scanned: false,
                        error: '',

                        page: 1,
                        perPage: 100,

                        editing: null,

                        bemAware: {
                            renameLabels: true,
                            propagateBlock: true,
                        },

                        renamePreview: {
                            open: false,
                            classRenames: [],
                            labelChanges: [],
                            pendingClassId: null,
                            pendingName: null,
                            saving: false,
                            error: '',
                            _resolver: null,
                        },

                        bemHelp: { open: false },

                        openBemHelp() {
                            this.bemHelp.open = true;
                        },

                        closeBemHelp() {
                            this.bemHelp.open = false;
                        },

                        init() {
                            this.loadBemAwarePrefs();
                            // Persist BEM toggle changes to localStorage on every flip.
                            this.$watch('bemAware', () => this.saveBemAwarePrefs(), { deep: true });
                            return this.loadTargets();
                        },

                        loadBemAwarePrefs() {
                            try {
                                const stored = localStorage.getItem('abbtl_cvf_bem_aware');
                                if (!stored) return;
                                const parsed = JSON.parse(stored);
                                if (parsed && typeof parsed === 'object') {
                                    if (typeof parsed.renameLabels === 'boolean') this.bemAware.renameLabels = parsed.renameLabels;
                                    if (typeof parsed.propagateBlock === 'boolean') this.bemAware.propagateBlock = parsed.propagateBlock;
                                }
                            } catch (e) {
                                // localStorage may be disabled / quota exceeded — silent.
                            }
                        },

                        saveBemAwarePrefs() {
                            try {
                                localStorage.setItem('abbtl_cvf_bem_aware', JSON.stringify({
                                    renameLabels: !!this.bemAware.renameLabels,
                                    propagateBlock: !!this.bemAware.propagateBlock,
                                }));
                            } catch (e) {
                                // ignore
                            }
                        },

                        revisionsModal: {
                            open: false,
                            kind: 'classes',
                            list: [],
                            loading: false,
                            confirmingId: null,
                            restoring: false,
                            error: '',
                        },

                        async openRevisionsModal() {
                            this.revisionsModal.open = true;
                            this.revisionsModal.kind = 'classes';
                            this.revisionsModal.confirmingId = null;
                            this.revisionsModal.error = '';
                            await this.loadRevisions();
                        },

                        closeRevisionsModal() {
                            this.revisionsModal.open = false;
                            this.revisionsModal.confirmingId = null;
                        },

                        async switchRevKind(kind) {
                            if (kind !== 'classes' && kind !== 'variables') return;
                            if (this.revisionsModal.kind === kind) return;
                            this.revisionsModal.kind = kind;
                            this.revisionsModal.confirmingId = null;
                            await this.loadRevisions();
                        },

                        async loadRevisions() {
                            this.revisionsModal.loading = true;
                            this.revisionsModal.error = '';
                            try {
                                const url = ABBTL.restUrl + '<?php echo esc_js(self::REST_ROUTE_REVISIONS_LIST); ?>?kind=' + encodeURIComponent(this.revisionsModal.kind);
                                const r = await fetch(url, {
                                    method: 'GET',
                                    headers: { 'X-WP-Nonce': ABBTL.nonce },
                                });
                                const data = await r.json();
                                if (!r.ok) throw new Error(data.message || data.error || 'Load failed');
                                this.revisionsModal.list = Array.isArray(data.revisions) ? data.revisions : [];
                            } catch (e) {
                                console.error('[ABBTL CVF] loadRevisions failed:', e);
                                this.revisionsModal.error = e.message || 'Failed to load revisions';
                                this.revisionsModal.list = [];
                            } finally {
                                this.revisionsModal.loading = false;
                            }
                        },

                        confirmRestore(revisionId) {
                            this.revisionsModal.confirmingId = revisionId;
                        },

                        cancelRestoreConfirm() {
                            this.revisionsModal.confirmingId = null;
                        },

                        /**
                         * Keyboard navigation through the revisions timeline:
                         *   Ctrl/Cmd + Z                = undo (step back)
                         *   Ctrl/Cmd + Shift + Z        = redo (step forward)
                         *   Ctrl + Y                    = redo (Windows alt)
                         *
                         * Cursor `_lastUndoneTs`:
                         *   - null  → "we're at the head of the timeline"
                         *             (next undo grabs absolute newest)
                         *   - <ts>  → "the snapshot at ts is what's live now"
                         *             (undo seeks before ts; redo seeks after)
                         *
                         * Cursor is reset to null whenever the user makes a
                         * NEW change via our endpoints (the timeline grew at
                         * the head — no more redo history beyond it).
                         */
                        _lastUndoneTs: null,
                        _navigating: false,

                        onGlobalKeydown(event) {
                            if (event.altKey) return;
                            if (!(event.ctrlKey || event.metaKey)) return;

                            const key = (event.key || '').toLowerCase();
                            let isUndo = false;
                            let isRedo = false;
                            if (key === 'z') {
                                if (event.shiftKey) isRedo = true;
                                else isUndo = true;
                            } else if (key === 'y' && !event.shiftKey) {
                                isRedo = true;
                            }
                            if (!isUndo && !isRedo) return;

                            // Don't hijack from inputs / textareas / contenteditable.
                            const t = event.target;
                            if (t) {
                                const tag = (t.tagName || '').toLowerCase();
                                if (tag === 'input' || tag === 'textarea' || t.isContentEditable) return;
                            }

                            // Only act on the CVF tab.
                            const activeTab = new URLSearchParams(location.search).get('tab') || 'modules';
                            if (activeTab !== '<?php echo esc_js($this->getSlug()); ?>') return;

                            // Don't fire when any modal is open or we're mid-flight.
                            if (this.classesModal.open || this.revisionsModal.open || this.renamePreview.open || this.bemHelp.open) return;
                            if (this._navigating) return;

                            event.preventDefault();
                            if (isRedo) {
                                this.redoStepForward();
                            } else {
                                this.undoStepBack();
                            }
                        },

                        async undoStepBack() {
                            const params = this._lastUndoneTs !== null
                                ? '?before=' + encodeURIComponent(this._lastUndoneTs)
                                : '';
                            await this._navigateRevision(params);
                        },

                        async redoStepForward() {
                            if (this._lastUndoneTs === null) return;
                            const params = '?after=' + encodeURIComponent(this._lastUndoneTs);
                            await this._navigateRevision(params);
                        },

                        async _navigateRevision(queryString) {
                            this._navigating = true;
                            try {
                                const r = await fetch(
                                    ABBTL.restUrl + '<?php echo esc_js(self::REST_ROUTE_REVISIONS_LATEST); ?>' + queryString,
                                    { method: 'GET', headers: { 'X-WP-Nonce': ABBTL.nonce } }
                                );
                                const data = await r.json();
                                if (!r.ok || !data.success || !data.kind || !data.id) return;

                                const restoreR = await fetch(
                                    ABBTL.restUrl + '<?php echo esc_js(self::REST_ROUTE_REVISIONS_RESTORE); ?>',
                                    {
                                        method: 'POST',
                                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ABBTL.nonce },
                                        body: JSON.stringify({ kind: data.kind, revisionId: data.id }),
                                    }
                                );
                                const restoreData = await restoreR.json();
                                if (!restoreR.ok || !restoreData.success) {
                                    console.error('[ABBTL CVF] navigate failed:', restoreData);
                                    return;
                                }
                                this._lastUndoneTs = restoreData.ts;

                                await this.loadTargets();
                                if (this.selectedTarget && this.scanned) {
                                    await this.scan();
                                }
                                if (this.revisionsModal.open) {
                                    await this.loadRevisions();
                                }
                            } finally {
                                this._navigating = false;
                            }
                        },

                        async doRestore(revisionId) {
                            this.revisionsModal.restoring = true;
                            this.revisionsModal.error = '';
                            try {
                                const r = await fetch(
                                    ABBTL.restUrl + '<?php echo esc_js(self::REST_ROUTE_REVISIONS_RESTORE); ?>',
                                    {
                                        method: 'POST',
                                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ABBTL.nonce },
                                        body: JSON.stringify({ kind: this.revisionsModal.kind, revisionId: revisionId }),
                                    }
                                );
                                const data = await r.json();
                                if (!r.ok || !data.success) throw new Error(data.error || 'Restore failed');
                                this.revisionsModal.confirmingId = null;
                                // Update the undo cursor so a subsequent Ctrl+Z steps
                                // back from THIS restore point (not from the head).
                                this._lastUndoneTs = (typeof data.ts === 'number') ? data.ts : null;
                                // Reload the list AND the target catalog since names
                                // may have changed across the table.
                                await this.loadRevisions();
                                await this.loadTargets();
                                // Re-scan to pick up renamed names in usage rows, if a target is active.
                                if (this.selectedTarget && this.scanned) {
                                    await this.scan();
                                }
                            } catch (e) {
                                console.error('[ABBTL CVF] doRestore failed:', e);
                                this.revisionsModal.error = e.message || 'Restore failed';
                            } finally {
                                this.revisionsModal.restoring = false;
                            }
                        },

                        classesModal: {
                            open: false,
                            usage: null,
                            classes: [],
                            dragIndex: -1,
                            // Filterable combobox state (the old <select> replacement)
                            addFilter: '',
                            addOpen: false,
                            addHighlight: 0,
                            renameClassId: null,
                            renameValue: '',
                            error: '',
                        },

                        classNameById(id) {
                            const t = (this.targets || []).find(x => x.kind === 'class' && x.id === id);
                            return t ? t.name : null;
                        },

                        classById(id) {
                            return (this.targets || []).find(x => x.kind === 'class' && x.id === id) || null;
                        },

                        get availableClassesToAdd() {
                            const onElement = new Set((this.classesModal.classes || []).map(c => c.id));
                            return (this.targets || []).filter(t => t.kind === 'class' && !onElement.has(t.id));
                        },

                        openClassesModal(usage) {
                            this.classesModal.usage = usage;
                            this.classesModal.classes = (usage.classIds || [])
                                .map(id => this.classById(id))
                                .filter(Boolean)
                                .map(c => ({ id: c.id, name: c.name }));
                            this.classesModal.dragIndex = -1;
                            this.classesModal.addFilter = '';
                            this.classesModal.addOpen = false;
                            this.classesModal.addHighlight = 0;
                            this.classesModal.renameClassId = null;
                            this.classesModal.renameValue = '';
                            this.classesModal.error = '';
                            this.classesModal.open = true;
                        },

                        get filteredAvailableClasses() {
                            const list = this.availableClassesToAdd;
                            const needle = (this.classesModal.addFilter || '').trim().toLowerCase();
                            if (!needle) return list;
                            return list.filter(t => t.name.toLowerCase().includes(needle));
                        },

                        onAddComboFocus() {
                            this.classesModal.addOpen = true;
                            // Reset highlight to first match every time the dropdown opens.
                            this.classesModal.addHighlight = 0;
                        },

                        onAddComboKeydown(event) {
                            const list = this.filteredAvailableClasses;
                            if (event.key === 'ArrowDown') {
                                event.preventDefault();
                                this.classesModal.addOpen = true;
                                if (list.length > 0) {
                                    this.classesModal.addHighlight = Math.min(
                                        this.classesModal.addHighlight + 1,
                                        list.length - 1
                                    );
                                }
                            } else if (event.key === 'ArrowUp') {
                                event.preventDefault();
                                this.classesModal.addHighlight = Math.max(this.classesModal.addHighlight - 1, 0);
                            } else if (event.key === 'Enter') {
                                event.preventDefault();
                                const sel = list[this.classesModal.addHighlight];
                                if (sel) this.pickClassFromCombobox(sel.id);
                            } else if (event.key === 'Escape') {
                                event.preventDefault();
                                this.classesModal.addOpen = false;
                                this.classesModal.addFilter = '';
                                this.classesModal.addHighlight = 0;
                            } else {
                                // Any printable key keeps the dropdown open and resets highlight to top.
                                this.classesModal.addOpen = true;
                                this.classesModal.addHighlight = 0;
                            }
                        },

                        async pickClassFromCombobox(classId) {
                            this.classesModal.addFilter = '';
                            this.classesModal.addOpen = false;
                            this.classesModal.addHighlight = 0;
                            await this.addClassToElement(classId);
                        },

                        closeClassesModal() {
                            this.classesModal.open = false;
                            this.classesModal.usage = null;
                        },

                        async persistElementClasses() {
                            const usage = this.classesModal.usage;
                            if (!usage) return;
                            const classIds = this.classesModal.classes.map(c => c.id);
                            try {
                                const r = await fetch(
                                    ABBTL.restUrl + '<?php echo esc_js(self::REST_ROUTE_SAVE_ELEMENT_CLS); ?>',
                                    {
                                        method: 'POST',
                                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ABBTL.nonce },
                                        body: JSON.stringify({
                                            postId: usage.postId,
                                            metaKey: usage.metaKey,
                                            elementId: usage.elementId,
                                            classIds: classIds,
                                        }),
                                    }
                                );
                                const data = await r.json();
                                if (!r.ok || !data.success) throw new Error(data.error || 'Save failed');
                                // Mutate the original usage object so table chips re-render.
                                usage.classIds = Array.isArray(data.classIds) ? data.classIds : classIds;
                                this.classesModal.error = '';
                            } catch (e) {
                                console.error('[ABBTL CVF] persistElementClasses failed:', e);
                                this.classesModal.error = e.message || 'Save failed';
                            }
                        },

                        async addClassToElement(classId) {
                            if (!classId) return;
                            const cls = this.classById(classId);
                            if (!cls) return;
                            if (this.classesModal.classes.some(c => c.id === cls.id)) return;
                            this.classesModal.classes.push({ id: cls.id, name: cls.name });
                            await this.persistElementClasses();
                        },

                        async removeClassFromElement(idx) {
                            this.classesModal.classes.splice(idx, 1);
                            await this.persistElementClasses();
                        },

                        onClassDragStart(event, idx) {
                            this.classesModal.dragIndex = idx;
                            event.dataTransfer.effectAllowed = 'move';
                            event.dataTransfer.setData('text/plain', String(idx));
                        },

                        async onClassDrop(event, dropIdx) {
                            const from = this.classesModal.dragIndex;
                            this.classesModal.dragIndex = -1;
                            if (from < 0 || from === dropIdx) return;
                            const items = [...this.classesModal.classes];
                            const [moved] = items.splice(from, 1);
                            items.splice(dropIdx, 0, moved);
                            this.classesModal.classes = items;
                            await this.persistElementClasses();
                        },

                        onClassDragEnd() {
                            this.classesModal.dragIndex = -1;
                        },

                        isClassEditing(classId) {
                            return this.classesModal.renameClassId === classId;
                        },

                        startClassRename(cls) {
                            this.classesModal.renameClassId = cls.id;
                            this.classesModal.renameValue = cls.name;
                            this.classesModal.error = '';
                        },

                        cancelClassRename() {
                            this.classesModal.renameClassId = null;
                            this.classesModal.renameValue = '';
                        },

                        async commitClassRename() {
                            const id = this.classesModal.renameClassId;
                            if (!id) return;
                            const newName = (this.classesModal.renameValue || '').trim();
                            const current = this.classesModal.classes.find(c => c.id === id);
                            if (!current || newName === '' || newName === current.name) {
                                this.cancelClassRename();
                                return;
                            }
                            const result = await this._renameClassById(id, newName);
                            if (!result.success) {
                                if (!result.cancelled) {
                                    this.classesModal.error = result.error || 'Rename failed';
                                }
                                this.cancelClassRename();
                                return;
                            }
                            // Sync the modal's local copy of the class row.
                            current.name = result.name;
                            this.classesModal.error = '';
                            this.cancelClassRename();
                        },

                        /**
                         * Shared rename — POSTs to the rename-class endpoint and updates the
                         * targets catalogue. Returns {success, name?, error?}. Used by the
                         * Edit Classes modal, the picker, and the per-row chips so all three
                         * surfaces stay in sync after a rename.
                         */
                        /**
                         * Public rename entry point. Behaves like a single rename for
                         * callers, but transparently runs a server dry-run first; if the
                         * rename would propagate to other classes or rewrite labels, the
                         * confirmation modal opens and the returned promise resolves with
                         * the eventual apply (or {cancelled:true}).
                         */
                        async _renameClassById(classId, newName) {
                            const preview = await this._fetchRename(classId, newName, true);
                            if (!preview.success) return preview;

                            const needsConfirm =
                                (Array.isArray(preview.renamed) && preview.renamed.length > 1)
                                || (Array.isArray(preview.labelChanges) && preview.labelChanges.length > 0);

                            if (!needsConfirm) {
                                return await this._fetchRename(classId, newName, false);
                            }

                            // Open the preview modal and wait for user decision.
                            return new Promise((resolve) => {
                                this.renamePreview.open = true;
                                this.renamePreview.classRenames = preview.renamed || [];
                                this.renamePreview.labelChanges = preview.labelChanges || [];
                                this.renamePreview.pendingClassId = classId;
                                this.renamePreview.pendingName = newName;
                                this.renamePreview.error = '';
                                this.renamePreview.saving = false;
                                this.renamePreview._resolver = resolve;
                            });
                        },

                        async _fetchRename(classId, newName, dryRun) {
                            try {
                                const r = await fetch(
                                    ABBTL.restUrl + '<?php echo esc_js(self::REST_ROUTE_RENAME_CLASS); ?>',
                                    {
                                        method: 'POST',
                                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ABBTL.nonce },
                                        body: JSON.stringify({
                                            classId,
                                            name: newName,
                                            bemPropagateBlock: !!this.bemAware.propagateBlock,
                                            bemRenameLabels: !!this.bemAware.renameLabels,
                                            dryRun: !!dryRun,
                                        }),
                                    }
                                );
                                const data = await r.json();
                                if (!r.ok || !data.success) throw new Error(data.error || 'Rename failed');

                                // Commit path: update the catalog for every renamed class so
                                // every row's chips re-render with the new names.
                                if (!dryRun && Array.isArray(data.renamed)) {
                                    for (const item of data.renamed) {
                                        const entry = this.targets.find(t => t.kind === 'class' && t.id === item.id);
                                        if (entry) entry.name = item.newName;
                                    }
                                    // New change → invalidate any undo cursor so the next
                                    // Ctrl+Z grabs the absolute newest snapshot.
                                    this._lastUndoneTs = null;
                                }
                                return {
                                    success: true,
                                    name: data.name,
                                    renamed: data.renamed || [],
                                    labelChanges: data.labelChanges || [],
                                    labelsUpdated: data.labelsUpdated || 0,
                                };
                            } catch (e) {
                                console.error('[ABBTL CVF] rename failed:', e);
                                return { success: false, error: e.message || 'Rename failed' };
                            }
                        },

                        async applyRenamePreview() {
                            if (!this.renamePreview.pendingClassId) return;
                            this.renamePreview.saving = true;
                            this.renamePreview.error = '';
                            const result = await this._fetchRename(
                                this.renamePreview.pendingClassId,
                                this.renamePreview.pendingName,
                                false
                            );
                            this.renamePreview.saving = false;
                            if (!result.success) {
                                this.renamePreview.error = result.error || 'Rename failed';
                                return;
                            }
                            this._resolveRenamePreview(result);
                            this.closeRenamePreview();
                        },

                        cancelRenamePreview() {
                            if (this.renamePreview.saving) return;
                            this._resolveRenamePreview({ success: false, cancelled: true });
                            this.closeRenamePreview();
                        },

                        closeRenamePreview() {
                            this.renamePreview.open = false;
                            this.renamePreview.classRenames = [];
                            this.renamePreview.labelChanges = [];
                            this.renamePreview.pendingClassId = null;
                            this.renamePreview.pendingName = null;
                            this.renamePreview.error = '';
                            this.renamePreview.saving = false;
                        },

                        _resolveRenamePreview(result) {
                            if (this.renamePreview._resolver) {
                                this.renamePreview._resolver(result);
                                this.renamePreview._resolver = null;
                            }
                        },

                        /** Inline rename state — shared by the picker row and the per-usage chip. */
                        inlineRename: {
                            where: null,    // 'picker' | 'chip' | null
                            rowKey: null,   // chip: usageKey, picker: null
                            classId: null,
                            value: '',
                            saving: false,
                            error: '',
                        },

                        isPickerRenaming(classId) {
                            return this.inlineRename.where === 'picker'
                                && this.inlineRename.classId === classId;
                        },

                        isChipRenaming(usageKey, classId) {
                            return this.inlineRename.where === 'chip'
                                && this.inlineRename.rowKey === usageKey
                                && this.inlineRename.classId === classId;
                        },

                        startPickerRename(target) {
                            if (!target || target.kind !== 'class') return;
                            this.inlineRename.where = 'picker';
                            this.inlineRename.rowKey = null;
                            this.inlineRename.classId = target.id;
                            this.inlineRename.value = target.name;
                            this.inlineRename.saving = false;
                            this.inlineRename.error = '';
                        },

                        startChipRename(usage, classId) {
                            const cls = this.classById(classId);
                            if (!cls) return;
                            this.inlineRename.where = 'chip';
                            this.inlineRename.rowKey = this.usageKey(usage);
                            this.inlineRename.classId = classId;
                            this.inlineRename.value = cls.name;
                            this.inlineRename.saving = false;
                            this.inlineRename.error = '';
                        },

                        cancelInlineRename() {
                            this.inlineRename.where = null;
                            this.inlineRename.rowKey = null;
                            this.inlineRename.classId = null;
                            this.inlineRename.value = '';
                            this.inlineRename.saving = false;
                            this.inlineRename.error = '';
                        },

                        async commitInlineRename() {
                            const id = this.inlineRename.classId;
                            if (!id) return;
                            const newName = (this.inlineRename.value || '').trim();
                            const current = this.classById(id);
                            if (!current || newName === '' || newName === current.name) {
                                this.cancelInlineRename();
                                return;
                            }
                            this.inlineRename.saving = true;
                            const result = await this._renameClassById(id, newName);
                            this.inlineRename.saving = false;
                            if (!result.success) {
                                if (!result.cancelled) {
                                    this.inlineRename.error = result.error || 'Rename failed';
                                    // Leave the input open so the user can fix and retry.
                                    return;
                                }
                            }
                            this.cancelInlineRename();
                        },

                        /**
                         * Click-defer for the picker row — a single click selects the
                         * target (and triggers a scan), but dblclick should fire rename
                         * without first dispatching the select. We defer the select by
                         * ~220ms; if dblclick lands inside that window, we cancel.
                         */
                        _pickerClickTimer: null,

                        onPickerRowClick(target) {
                            // If a rename is active on this row, swallow the click — the
                            // input handles its own events.
                            if (this.isPickerRenaming(target.id)) return;
                            if (this._pickerClickTimer) clearTimeout(this._pickerClickTimer);
                            this._pickerClickTimer = setTimeout(() => {
                                this._pickerClickTimer = null;
                                this.selectTarget(target);
                            }, 220);
                        },

                        onPickerRowDblClick(target) {
                            if (this._pickerClickTimer) {
                                clearTimeout(this._pickerClickTimer);
                                this._pickerClickTimer = null;
                            }
                            this.startPickerRename(target);
                        },

                        usageKey(usage) {
                            return usage.postId + '|' + usage.metaKey + '|' + usage.elementId;
                        },

                        isEditing(usage, field) {
                            return this.editing
                                && this.editing.key === this.usageKey(usage)
                                && this.editing.field === field;
                        },

                        startEdit(usage, field) {
                            if (this.editing) return;
                            this.editing = {
                                key: this.usageKey(usage),
                                usage: usage,
                                field: field,
                                value: usage[field] == null ? '' : String(usage[field]),
                                saving: false,
                                error: '',
                            };
                        },

                        cancelEdit() {
                            this.editing = null;
                        },

                        async commitEdit() {
                            if (!this.editing) return;
                            const ctx = this.editing;
                            const original = ctx.usage[ctx.field] == null ? '' : String(ctx.usage[ctx.field]);
                            if (ctx.value === original) {
                                this.editing = null;
                                return;
                            }
                            ctx.saving = true;
                            try {
                                const response = await fetch(
                                    ABBTL.restUrl + '<?php echo esc_js(self::REST_ROUTE_SAVE_LABEL); ?>',
                                    {
                                        method: 'POST',
                                        headers: {
                                            'Content-Type': 'application/json',
                                            'X-WP-Nonce': ABBTL.nonce,
                                        },
                                        body: JSON.stringify({
                                            postId: ctx.usage.postId,
                                            metaKey: ctx.usage.metaKey,
                                            elementId: ctx.usage.elementId,
                                            label: ctx.value,
                                        }),
                                    }
                                );
                                const data = await response.json();
                                if (!response.ok || !data.success) {
                                    throw new Error(data.error || 'Save failed');
                                }
                                // Empty label → null so the display falls back to elementName.
                                ctx.usage.elementLabel = (data.label === '' || data.label == null) ? null : data.label;
                                this.editing = null;
                            } catch (e) {
                                console.error('[ABBTL CVF] Save failed:', e);
                                ctx.error = e.message || 'Unknown error';
                                ctx.saving = false;
                            }
                        },

                        async loadTargets() {
                            this.loading = true;
                            this.error = '';
                            try {
                                const response = await fetch(
                                    ABBTL.restUrl + '<?php echo esc_js(self::REST_ROUTE_TARGETS); ?>',
                                    { method: 'GET', headers: { 'X-WP-Nonce': ABBTL.nonce } }
                                );
                                const data = await response.json();
                                if (!response.ok) throw new Error(data.message || 'Failed to load targets');
                                this.targets = Array.isArray(data.targets) ? data.targets : [];
                            } catch (e) {
                                console.error('[ABBTL CVF] loadTargets failed:', e);
                                this.error = e.message || 'Unknown error';
                            } finally {
                                this.loading = false;
                            }
                        },

                        get filteredTargets() {
                            const needle = (this.targetFilter || '').trim().toLowerCase();
                            return this.targets.filter(t => {
                                if (this.targetKindFilter !== 'all' && t.kind !== this.targetKindFilter) return false;
                                if (!needle) return true;
                                return t.name.toLowerCase().includes(needle)
                                    || (t.value || '').toLowerCase().includes(needle);
                            });
                        },

                        isSelected(target) {
                            return this.selectedTarget
                                && this.selectedTarget.kind === target.kind
                                && this.selectedTarget.id === target.id;
                        },

                        selectionToken() {
                            if (!this.selectedTarget) return '';
                            return this.selectedTarget.kind === 'class'
                                ? '.' + this.selectedTarget.name
                                : 'var(--' + this.selectedTarget.name + ')';
                        },

                        selectTarget(target) {
                            this.selectedTarget = target;
                            this.scan();
                        },

                        async scan() {
                            if (!this.selectedTarget) return;
                            this.scanning = true;
                            this.error = '';
                            this.page = 1;
                            try {
                                const response = await fetch(
                                    ABBTL.restUrl + '<?php echo esc_js(self::REST_ROUTE_SCAN); ?>',
                                    {
                                        method: 'POST',
                                        headers: {
                                            'Content-Type': 'application/json',
                                            'X-WP-Nonce': ABBTL.nonce,
                                        },
                                        body: JSON.stringify({
                                            kind: this.selectedTarget.kind,
                                            id: this.selectedTarget.id,
                                            name: this.selectedTarget.name,
                                        }),
                                    }
                                );
                                const data = await response.json();
                                if (!response.ok) throw new Error(data.message || data.error || 'Scan failed');
                                this.usages = Array.isArray(data.usages) ? data.usages : [];
                                this.engine = data.engine || '';
                                this.engineError = data.engineError || null;
                                this.scanned = true;
                            } catch (e) {
                                console.error('[ABBTL CVF] Scan failed:', e);
                                this.error = e.message || 'Unknown error';
                            } finally {
                                this.scanning = false;
                            }
                        },

                        get totalPages() {
                            return Math.max(1, Math.ceil(this.usages.length / this.perPage));
                        },

                        get pagedUsages() {
                            const start = (this.page - 1) * this.perPage;
                            return this.usages.slice(start, start + this.perPage);
                        },

                        nextPage() { if (this.page < this.totalPages) this.page++; },
                        prevPage() { if (this.page > 1) this.page--; },
                    };
                }
            </script>
        </div>
        <?php
    }

    /**
     * @param array{available: bool, version: ?string, reason: ?string} $wpcli
     */
    private function renderWpCliNotice(array $wpcli): void
    {
        if ($wpcli['available']) {
            $versionSuffix = !empty($wpcli['version']) ? ' (' . $wpcli['version'] . ')' : '';
            ?>
            <div class="notice notice-success inline" style="margin-top:16px;">
                <p>
                    <strong><?php esc_html_e('WP-CLI access confirmed', 'ab-bricks-tools'); ?><?php echo esc_html($versionSuffix); ?></strong>
                    — <?php esc_html_e('where possible this will be used (Fastest).', 'ab-bricks-tools'); ?>
                </p>
            </div>
            <?php
            return;
        }
        ?>
        <div class="notice notice-warning inline" style="margin-top:16px;">
            <p>
                <strong><?php esc_html_e('WP-CLI is not available', 'ab-bricks-tools'); ?></strong>
                — <?php esc_html_e('all operations will be performed with PHP (Slower).', 'ab-bricks-tools'); ?>
                <?php if (!empty($wpcli['reason'])) : ?>
                    <br>
                    <small style="color:#646970;"><?php echo esc_html($wpcli['reason']); ?></small>
                <?php endif; ?>
            </p>
        </div>
        <?php
    }
}

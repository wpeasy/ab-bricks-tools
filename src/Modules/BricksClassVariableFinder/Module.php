<?php
declare(strict_types=1);

namespace AB\BricksTools\Modules\BricksClassVariableFinder;

use AB\BricksTools\Admin\Layout;
use AB\BricksTools\Modules\HasAdminPage;
use AB\BricksTools\Modules\ModuleInterface;
use AB\BricksTools\System\WpCli;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class Module implements ModuleInterface, HasAdminPage
{
    public const REST_ROUTE_TARGETS = '/class-variable-finder/targets';
    public const REST_ROUTE_SCAN    = '/class-variable-finder/scan';

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
                'builderUrl'   => $builderUrl,
            ];
        }, $usages);

        return new WP_REST_Response([
            'usages' => $data,
            'engine' => $finder->lastEngine,
        ]);
    }

    public function renderAdminPage(): void
    {
        $wpcli = WpCli::status();
        Layout::open();
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

            <div x-data="abbtlCvfApp()" x-init="loadTargets()" style="margin-top:24px;">

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
                            <button
                                type="button"
                                class="abbtl-cvf__target"
                                :class="{ 'is-selected': isSelected(target) }"
                                @click="selectTarget(target)"
                            >
                                <span class="abbtl-cvf__badge" :class="'abbtl-cvf__badge--' + target.kind" x-text="target.kind === 'class' ? 'Class' : 'Variable'"></span>
                                <code x-text="target.kind === 'class' ? ('.' + target.name) : ('--' + target.name)"></code>
                            </button>
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

                <div class="abbtl-cvf__selection" x-show="selectedTarget" x-cloak>
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
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="usage in pagedUsages" :key="usage.postId + '|' + usage.metaKey + '|' + usage.elementId">
                                <tr>
                                    <td>
                                        <strong x-text="usage.postTitle"></strong>
                                        <span class="abbtl-cvf__status" x-show="usage.postStatus !== 'publish'" x-cloak x-text="'(' + usage.postStatus + ')'"></span>
                                    </td>
                                    <td>
                                        <a
                                            :href="usage.builderUrl"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            x-text="usage.elementLabel || usage.elementName || '—'"
                                        ></a>
                                    </td>
                                    <td><code x-text="usage.elementName || '—'"></code></td>
                                    <td><code x-text="usage.elementId"></code></td>
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
                        scanning: false,
                        scanned: false,
                        error: '',

                        page: 1,
                        perPage: 100,

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
        Layout::close();
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

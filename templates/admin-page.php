<?php
/**
 * @var \AB\BricksTools\Modules\Registrar $registrar
 */
defined('ABSPATH') || exit;

$modules      = $registrar->getAll();
$initialState = [];
$hasAdminPage = [];
foreach ($modules as $slug => $module) {
    $initialState[$slug] = $registrar->isEnabled($slug);
    $hasAdminPage[$slug] = $module instanceof \AB\BricksTools\Modules\HasAdminPage;
}

$alpineConfig = [
    'enabled'      => $initialState,
    'hasAdminPage' => $hasAdminPage,
];
?>
<?php \AB\BricksTools\Admin\Layout::open(); ?>
<div class="abbtl-admin">
    <h1><?php esc_html_e('Bricks Tools — Modules', 'ab-bricks-tools'); ?></h1>
    <p class="description">
        <?php esc_html_e('Enable or disable modules. Each module is self-contained — disabling a module unloads it on the next pageload.', 'ab-bricks-tools'); ?>
    </p>

    <div
        class="abbtl-modules"
        x-data='abbtlModulesApp(<?php echo wp_json_encode($alpineConfig); ?>)'
    >
        <?php if (empty($modules)) : ?>
            <p><em><?php esc_html_e('No modules discovered.', 'ab-bricks-tools'); ?></em></p>
        <?php else : ?>
            <ul class="abbtl-modules__list">
                <?php foreach ($modules as $slug => $module) : ?>
                    <li class="abbtl-modules__item">
                        <label class="abbtl-modules__toggle" :aria-label="enabled['<?php echo esc_attr($slug); ?>'] ? '<?php echo esc_attr__('Disable module', 'ab-bricks-tools'); ?>' : '<?php echo esc_attr__('Enable module', 'ab-bricks-tools'); ?>'">
                            <input
                                class="abbtl-modules__switch-input"
                                type="checkbox"
                                :checked="enabled['<?php echo esc_attr($slug); ?>']"
                                @change="toggle('<?php echo esc_attr($slug); ?>', $event.target.checked)"
                                :disabled="pending['<?php echo esc_attr($slug); ?>']"
                            />
                            <span class="abbtl-modules__switch" aria-hidden="true"></span>
                        </label>
                        <div class="abbtl-modules__meta">
                            <h2 class="abbtl-modules__name">
                                <?php echo esc_html($module->getName()); ?>
                                <span class="abbtl-modules__version">v<?php echo esc_html($module->getVersion()); ?></span>
                            </h2>
                            <p class="abbtl-modules__description"><?php echo esc_html($module->getDescription()); ?></p>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <script>
        function abbtlModulesApp(config) {
            return {
                enabled: config.enabled,
                hasAdminPage: config.hasAdminPage,
                pending: {},
                async toggle(slug, value) {
                    this.pending[slug] = true;
                    try {
                        const response = await fetch(
                            ABBTL.restUrl + '/modules/' + encodeURIComponent(slug) + '/enabled',
                            {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-WP-Nonce': ABBTL.nonce,
                                },
                                body: JSON.stringify({ enabled: value }),
                            }
                        );
                        const data = await response.json();
                        if (!response.ok || !data.success) {
                            throw new Error(data.error || 'Request failed');
                        }
                        this.enabled[slug] = value;
                        // Reload so the module's submenu (re)appears or disappears.
                        if (this.hasAdminPage[slug]) {
                            window.location.reload();
                            return;
                        }
                    } catch (e) {
                        console.error('[ABBTL] Toggle failed:', e);
                        this.enabled[slug] = !value;
                    } finally {
                        this.pending[slug] = false;
                    }
                },
            };
        }
    </script>
</div>
<?php \AB\BricksTools\Admin\Layout::close(); ?>

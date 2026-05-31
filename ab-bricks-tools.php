<?php
/**
 * Plugin Name: Bricks Tools
 * Description: A collection of Bricks Tools.
 * Version: 0.0.5
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Author: AB
 * Text Domain: ab-bricks-tools
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('ABBTL_VERSION', '0.0.5');
define('ABBTL_PLUGIN_FILE', __FILE__);
define('ABBTL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ABBTL_PLUGIN_URL', plugin_dir_url(__FILE__));

$abbtlAutoload = ABBTL_PLUGIN_DIR . 'vendor/autoload.php';
if (!is_file($abbtlAutoload)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p><strong>Bricks Tools:</strong> '
            . esc_html__('Composer dependencies are missing. Run "composer install" inside the plugin directory.', 'ab-bricks-tools')
            . '</p></div>';
    });
    return;
}
require $abbtlAutoload;

// Theme guard — this plugin only makes sense when Bricks is the active theme.
// Self-deactivate (with an admin notice) on any other theme so we don't sit
// dormant accruing tech debt or showing UI that points at non-existent data.
add_action('admin_init', static function (): void {
    $theme = wp_get_theme();
    if (in_array('bricks', [$theme->get_stylesheet(), $theme->get_template()], true)) {
        return;
    }
    if (!function_exists('deactivate_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    deactivate_plugins(plugin_basename(ABBTL_PLUGIN_FILE));
    add_action('admin_notices', static function (): void {
        ?>
        <div class="notice notice-error">
            <p>
                <strong><?php esc_html_e('Bricks Tools deactivated.', 'ab-bricks-tools'); ?></strong>
                <?php esc_html_e('This plugin requires the Bricks Builder theme (or a Bricks child theme). Activate Bricks and try again.', 'ab-bricks-tools'); ?>
            </p>
        </div>
        <?php
    });
    // Suppress WP's "Plugin activated." notice from the same request.
    if (isset($_GET['activate'])) {
        unset($_GET['activate']);
    }
});

// GitHub-based plugin updater — public repo, no token required.
// Reads releases at github.com/wpeasy/ab-bricks-tools; prefers the attached
// release-asset zip over GitHub's auto-generated source tarball.
if (class_exists(\YahnisElsts\PluginUpdateChecker\v5\PucFactory::class)) {
    $abbtlUpdateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/wpeasy/ab-bricks-tools/',
        __FILE__,
        'ab-bricks-tools'
    );
    $abbtlUpdateChecker->getVcsApi()->enableReleaseAssets();
}

add_action('plugins_loaded', static function (): void {
    \AB\BricksTools\Plugin::instance()->init();
});

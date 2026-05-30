<?php
/**
 * Plugin Name: Bricks Tools
 * Description: A collection of Bricks Tools.
 * Version: 0.0.2
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Author: AB
 * Text Domain: ab-bricks-tools
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('ABBTL_VERSION', '0.0.2');
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

<?php
declare(strict_types=1);

namespace AB\BricksTools\Admin;

use AB\BricksTools\Modules\HasAdminPage;
use AB\BricksTools\Modules\Registrar;

final class AdminPage
{
    public const MENU_SLUG = 'abbtl-modules';

    private ?string $hookSuffix = null;

    /** @var array<string, string> Map of module slug => submenu hook suffix. */
    private array $moduleHookSuffixes = [];

    public function __construct(private Registrar $registrar)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function addMenu(): void
    {
        $this->hookSuffix = add_menu_page(
            __('Bricks Tools', 'ab-bricks-tools'),
            __('Bricks Tools', 'ab-bricks-tools'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render'],
            'dashicons-admin-tools',
            80
        );

        // Rename the auto-created first submenu from "Bricks Tools" to "Modules".
        add_submenu_page(
            self::MENU_SLUG,
            __('Modules', 'ab-bricks-tools'),
            __('Modules', 'ab-bricks-tools'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render']
        );

        // One submenu per enabled module that implements HasAdminPage.
        foreach ($this->registrar->getAll() as $slug => $module) {
            if (!$this->registrar->isEnabled($slug)) {
                continue;
            }
            if (!$module instanceof HasAdminPage) {
                continue;
            }

            $submenuSlug = self::MENU_SLUG . '-' . $slug;
            $hookSuffix  = add_submenu_page(
                self::MENU_SLUG,
                $module->getName(),
                $module->getName(),
                'manage_options',
                $submenuSlug,
                function () use ($module): void {
                    if (!current_user_can('manage_options')) {
                        wp_die(esc_html__('You do not have permission to view this page.', 'ab-bricks-tools'));
                    }
                    $module->renderAdminPage();
                }
            );

            if (is_string($hookSuffix)) {
                $this->moduleHookSuffixes[$slug] = $hookSuffix;
            }
        }
    }

    public function enqueueAssets(string $hookSuffix): void
    {
        $isMainModulesPage = ($hookSuffix === $this->hookSuffix);
        $isModuleSubmenu   = in_array($hookSuffix, $this->moduleHookSuffixes, true);

        if (!$isMainModulesPage && !$isModuleSubmenu) {
            return;
        }

        wp_enqueue_script(
            'abbtl-alpine',
            ABBTL_PLUGIN_URL . 'assets/js/alpine.min.js',
            [],
            '3.x',
            ['strategy' => 'defer', 'in_footer' => true]
        );

        wp_enqueue_style(
            'abbtl-admin',
            ABBTL_PLUGIN_URL . 'assets/css/admin.css',
            [],
            ABBTL_VERSION
        );

        wp_localize_script('abbtl-alpine', 'ABBTL', [
            'restUrl' => esc_url_raw(rest_url('abbtl/v1')),
            'nonce'   => wp_create_nonce('wp_rest'),
        ]);
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to view this page.', 'ab-bricks-tools'));
        }

        $registrar = $this->registrar;
        require ABBTL_PLUGIN_DIR . 'templates/admin-page.php';
    }
}

<?php
declare(strict_types=1);

namespace AB\BricksTools\Admin;

/**
 * Two-column admin shell. Wraps every plugin admin screen so the right column
 * can carry the BRXProd advert without each render method having to know
 * about it.
 *
 * Usage in any admin render method:
 *
 *     \AB\BricksTools\Admin\Layout::open();
 *     // ... your screen content ...
 *     \AB\BricksTools\Admin\Layout::close();
 *
 * `open()` emits `<div class="wrap abbtl-shell"><div class="abbtl-shell__main">`
 * — so screens no longer need their own `.wrap` wrapper.
 */
final class Layout
{
    public const ADVERT_URL = 'https://brxprod.com/?utm_source=ab-bricks-tools&utm_medium=plugin-admin&utm_campaign=advert';

    /** Local logo asset — relative to plugin URL. */
    private const LOGO_PATH = 'assets/img/brxprod-logo.webp';

    public static function open(): void
    {
        echo '<div class="wrap abbtl-shell">';
        echo '<div class="abbtl-shell__main">';
    }

    public static function close(): void
    {
        echo '</div>'; // .abbtl-shell__main
        self::renderAside();
        echo '</div>'; // .wrap.abbtl-shell
    }

    private static function renderAside(): void
    {
        $url  = esc_url(self::ADVERT_URL);
        $logo = esc_url(ABBTL_PLUGIN_URL . self::LOGO_PATH);
        ?>
        <aside class="abbtl-shell__aside" aria-label="<?php echo esc_attr__('About this plugin', 'ab-bricks-tools'); ?>">
            <div class="abbtl-advert">
                <a class="abbtl-advert__logo" href="<?php echo $url; ?>" target="_blank" rel="noopener noreferrer">
                    <img src="<?php echo $logo; ?>" alt="BRXProd" loading="lazy" />
                </a>
                <p class="abbtl-advert__eyebrow"><?php esc_html_e('A free gift from', 'ab-bricks-tools'); ?></p>
                <h2 class="abbtl-advert__headline">BRXProd</h2>
                <p class="abbtl-advert__tagline"><?php esc_html_e('Supercharge Your Workflow', 'ab-bricks-tools'); ?></p>
                <p class="abbtl-advert__body">
                    <?php
                    echo wp_kses(
                        sprintf(
                            /* translators: %s wraps the BRXProd name in <strong> */
                            __('This free plugin is brought to you by %s — the most advanced productivity suite for Bricks Builder. Never build without it.', 'ab-bricks-tools'),
                            '<strong>BRXProd</strong>'
                        ),
                        ['strong' => []]
                    );
                    ?>
                </p>
                <a class="abbtl-advert__cta" href="<?php echo $url; ?>" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e('Explore BRXProd', 'ab-bricks-tools'); ?>
                    <span aria-hidden="true">→</span>
                </a>
            </div>
        </aside>
        <?php
    }
}

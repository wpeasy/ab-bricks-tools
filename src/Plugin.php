<?php
declare(strict_types=1);

namespace AB\BricksTools;

use AB\BricksTools\Admin\AdminPage;
use AB\BricksTools\Modules\Registrar;
use AB\BricksTools\REST\Controller;

final class Plugin
{
    private static ?Plugin $instance = null;

    private Registrar $registrar;

    public static function instance(): Plugin
    {
        return self::$instance ??= new self();
    }

    public function init(): void
    {
        load_plugin_textdomain(
            'ab-bricks-tools',
            false,
            dirname(plugin_basename(ABBTL_PLUGIN_FILE)) . '/languages'
        );

        $this->registrar = new Registrar();
        $this->registrar->discover();
        $this->registrar->bootEnabledModules();

        (new AdminPage($this->registrar))->register();
        (new Controller($this->registrar))->register();
    }

    public function registrar(): Registrar
    {
        return $this->registrar;
    }
}

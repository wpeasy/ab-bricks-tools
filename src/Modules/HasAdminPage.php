<?php
declare(strict_types=1);

namespace AB\BricksTools\Modules;

/**
 * Optional interface — modules that need their own WP Admin submenu under
 * "Bricks Tools" implement this. Submenu is only registered when the module
 * is enabled in abbtl_modules.
 */
interface HasAdminPage
{
    public function renderAdminPage(): void;
}

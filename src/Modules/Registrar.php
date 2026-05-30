<?php
declare(strict_types=1);

namespace AB\BricksTools\Modules;

final class Registrar
{
    private const OPTION_KEY = 'abbtl_modules';

    /** @var array<string, ModuleInterface> */
    private array $modules = [];

    public function discover(): void
    {
        $base = ABBTL_PLUGIN_DIR . 'src/Modules/';
        $dirs = glob($base . '*', GLOB_ONLYDIR) ?: [];

        foreach ($dirs as $dir) {
            $file = $dir . '/Module.php';
            if (!is_file($file)) {
                continue;
            }

            $folder = basename($dir);
            $class  = 'AB\\BricksTools\\Modules\\' . $folder . '\\Module';

            if (!class_exists($class)) {
                continue;
            }
            if (!is_subclass_of($class, ModuleInterface::class)) {
                continue;
            }

            /** @var ModuleInterface $module */
            $module = new $class();
            $this->modules[$module->getSlug()] = $module;
        }
    }

    /** @return array<string, ModuleInterface> */
    public function getAll(): array
    {
        return $this->modules;
    }

    public function get(string $slug): ?ModuleInterface
    {
        return $this->modules[$slug] ?? null;
    }

    public function isEnabled(string $slug): bool
    {
        $states = get_option(self::OPTION_KEY, []);
        if (!is_array($states)) {
            return false;
        }
        return !empty($states[$slug]);
    }

    public function setEnabled(string $slug, bool $enabled): void
    {
        $states = get_option(self::OPTION_KEY, []);
        if (!is_array($states)) {
            $states = [];
        }
        $states[$slug] = $enabled;
        update_option(self::OPTION_KEY, $states, true);
    }

    public function bootEnabledModules(): void
    {
        foreach ($this->modules as $slug => $module) {
            if ($this->isEnabled($slug)) {
                $module->boot();
            }
        }
    }
}

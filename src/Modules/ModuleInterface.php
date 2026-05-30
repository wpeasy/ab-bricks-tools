<?php
declare(strict_types=1);

namespace AB\BricksTools\Modules;

interface ModuleInterface
{
    public function getSlug(): string;

    public function getName(): string;

    public function getVersion(): string;

    public function getDescription(): string;

    public function boot(): void;
}

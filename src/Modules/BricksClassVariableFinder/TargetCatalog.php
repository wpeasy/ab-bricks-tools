<?php
declare(strict_types=1);

namespace AB\BricksTools\Modules\BricksClassVariableFinder;

/**
 * Loads and normalizes Bricks Global Classes and Global Variables from
 * wp_options so the admin picker has a single, uniform list to render.
 *
 * Classes live in `bricks_global_classes`, variables in
 * `bricks_global_variables`. Both are arrays of records with at least
 * `id` and `name`. We discard malformed entries.
 */
final class TargetCatalog
{
    public const KIND_CLASS    = 'class';
    public const KIND_VARIABLE = 'variable';

    /**
     * @return array<int, array{kind:string,id:string,name:string,category:?string,value:?string}>
     */
    public static function all(): array
    {
        $targets = array_merge(
            self::normalize(get_option('bricks_global_classes', []), self::KIND_CLASS),
            self::normalize(get_option('bricks_global_variables', []), self::KIND_VARIABLE)
        );

        // Sort by kind (classes first), then alphabetical by name.
        usort($targets, static function (array $a, array $b): int {
            if ($a['kind'] !== $b['kind']) {
                return $a['kind'] === self::KIND_CLASS ? -1 : 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });

        return $targets;
    }

    /**
     * @param mixed $raw
     * @return array<int, array{kind:string,id:string,name:string,category:?string,value:?string}>
     */
    private static function normalize($raw, string $kind): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id   = isset($item['id']) ? (string) $item['id'] : '';
            $name = isset($item['name']) ? (string) $item['name'] : '';
            if ($id === '' || $name === '') {
                continue;
            }
            $out[] = [
                'kind'     => $kind,
                'id'       => $id,
                'name'     => $name,
                'category' => isset($item['category']) ? (string) $item['category'] : null,
                'value'    => isset($item['value']) ? (string) $item['value'] : null,
            ];
        }
        return $out;
    }
}

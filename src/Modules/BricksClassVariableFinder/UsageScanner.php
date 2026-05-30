<?php
declare(strict_types=1);

namespace AB\BricksTools\Modules\BricksClassVariableFinder;

/**
 * Pure usage scanner — takes $wpdb + a target descriptor, returns raw arrays.
 *
 * Used by both the in-process PHP path (UsageFinder fallback) and the
 * out-of-process WP-CLI path (wpcli-scan.php under `wp eval-file`). Has no
 * plugin-autoload dependency so it can be required directly.
 *
 * Mirrors FormScanner: same family prefixes, same latest-version-per-post
 * grouping, same revision/auto-draft/trash exclusion.
 */
final class UsageScanner
{
    public const FAMILY_PREFIXES = [
        'content' => '_bricks_page_content',
        'header'  => '_bricks_page_header',
        'footer'  => '_bricks_page_footer',
    ];

    public const KIND_CLASS    = 'class';
    public const KIND_VARIABLE = 'variable';

    /**
     * @param array{kind:string, id:string, name:string} $target
     * @return array<int, array<string, mixed>>
     */
    public static function scanFromWpdb(\wpdb $wpdb, array $target): array
    {
        $kind = (string) ($target['kind'] ?? '');
        $id   = (string) ($target['id'] ?? '');
        $name = (string) ($target['name'] ?? '');

        if ($kind !== self::KIND_CLASS && $kind !== self::KIND_VARIABLE) {
            return [];
        }
        if ($id === '' || $name === '') {
            return [];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pm.post_id, pm.meta_key, pm.meta_value, p.post_title, p.post_type, p.post_status
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE (pm.meta_key LIKE %s OR pm.meta_key LIKE %s OR pm.meta_key LIKE %s)
               AND p.post_type != 'revision'
               AND p.post_status NOT IN ('trash', 'auto-draft')",
            $wpdb->esc_like(self::FAMILY_PREFIXES['content']) . '%',
            $wpdb->esc_like(self::FAMILY_PREFIXES['header']) . '%',
            $wpdb->esc_like(self::FAMILY_PREFIXES['footer']) . '%'
        ));

        if (!$rows) {
            return [];
        }

        $latestRows = self::pickLatestPerFamily($rows);

        $variableNeedle = 'var(--' . $name . ')';
        $usages         = [];

        foreach ($latestRows as $row) {
            $elements = self::decode($row->meta_value);
            if (!is_array($elements)) {
                continue;
            }

            foreach ($elements as $element) {
                if (!is_array($element)) {
                    continue;
                }
                if (!self::elementUses($element, $kind, $id, $variableNeedle)) {
                    continue;
                }

                $usages[] = [
                    'postId'       => (int) $row->post_id,
                    'postTitle'    => (string) ($row->post_title !== '' ? $row->post_title : '(no title)'),
                    'postType'     => (string) $row->post_type,
                    'postStatus'   => (string) $row->post_status,
                    'metaKey'      => (string) $row->meta_key,
                    'elementId'    => (string) ($element['id'] ?? ''),
                    'elementName'  => (string) ($element['name'] ?? ''),
                    'elementLabel' => isset($element['label']) && is_string($element['label']) && $element['label'] !== ''
                        ? $element['label']
                        : null,
                ];
            }
        }

        usort($usages, static function (array $a, array $b): int {
            $byTitle = strcasecmp((string) $a['postTitle'], (string) $b['postTitle']);
            if ($byTitle !== 0) {
                return $byTitle;
            }
            return strcmp((string) $a['elementId'], (string) $b['elementId']);
        });

        return $usages;
    }

    /**
     * @param array<string, mixed> $element
     */
    private static function elementUses(array $element, string $kind, string $id, string $variableNeedle): bool
    {
        if ($kind === self::KIND_CLASS) {
            $classes = $element['settings']['_cssGlobalClasses'] ?? null;
            if (!is_array($classes)) {
                return false;
            }
            foreach ($classes as $classId) {
                if (is_string($classId) && $classId === $id) {
                    return true;
                }
            }
            return false;
        }

        // Variable: two-pronged detection.
        $settings = $element['settings'] ?? null;
        if (!is_array($settings)) {
            return false;
        }

        // (1) Literal `var(--name)` substring anywhere in the JSON-serialized settings.
        $json = json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($json) && strpos($json, $variableNeedle) !== false) {
            return true;
        }

        // (2) Scalar leaf equal to the variable ID (Bricks-typed picker references).
        return self::treeContainsScalar($settings, $id);
    }

    /**
     * @param mixed $node
     */
    private static function treeContainsScalar($node, string $needle): bool
    {
        if (is_string($node)) {
            return $node === $needle;
        }
        if (!is_array($node)) {
            return false;
        }
        foreach ($node as $child) {
            if (self::treeContainsScalar($child, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int, object> $rows
     * @return array<int, object>
     */
    private static function pickLatestPerFamily(array $rows): array
    {
        $best = [];
        foreach ($rows as $row) {
            $parsed = self::parseFamilyAndVersion((string) $row->meta_key);
            if ($parsed === null) {
                continue;
            }
            [$family, $version] = $parsed;
            $postId = (int) $row->post_id;
            $current = $best[$postId][$family] ?? null;
            if ($current === null || $current['version'] < $version) {
                $best[$postId][$family] = ['version' => $version, 'row' => $row];
            }
        }
        $out = [];
        foreach ($best as $byFamily) {
            foreach ($byFamily as $entry) {
                $out[] = $entry['row'];
            }
        }
        return $out;
    }

    /**
     * @return array{0:string,1:int}|null
     */
    private static function parseFamilyAndVersion(string $metaKey): ?array
    {
        if (!preg_match('/^_bricks_page_(content|header|footer)(?:_(\d+))?$/', $metaKey, $m)) {
            return null;
        }
        return [$m[1], isset($m[2]) ? (int) $m[2] : 0];
    }

    /**
     * @param mixed $raw
     * @return mixed
     */
    private static function decode($raw)
    {
        $value = maybe_unserialize($raw);
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return $value;
    }
}

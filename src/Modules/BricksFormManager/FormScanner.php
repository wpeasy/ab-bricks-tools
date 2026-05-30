<?php
declare(strict_types=1);

namespace AB\BricksTools\Modules\BricksFormManager;

/**
 * Pure form scanner — takes a $wpdb, returns raw arrays.
 *
 * Used by BOTH the in-process PHP path (FormFinder fallback) and the
 * out-of-process WP-CLI path (wpcli-scan.php under `wp eval-file`). No
 * plugin-autoload dependency so it can be required directly when the
 * autoloader isn't available (e.g. `wp eval-file --skip-plugins`).
 */
final class FormScanner
{
    public const FAMILY_PREFIXES = [
        'content' => '_bricks_page_content',
        'header'  => '_bricks_page_header',
        'footer'  => '_bricks_page_footer',
    ];

    public const FORM_ELEMENT_NAMES = [
        'form'          => 'bricks',
        'brf-pro-forms' => 'brf-pro',
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function scanFromWpdb(\wpdb $wpdb): array
    {
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

        $forms = [];
        foreach ($latestRows as $row) {
            $elements = self::decode($row->meta_value);
            if (!is_array($elements)) {
                continue;
            }

            foreach ($elements as $element) {
                if (!is_array($element)) {
                    continue;
                }
                $name = $element['name'] ?? null;
                if (!is_string($name) || !isset(self::FORM_ELEMENT_NAMES[$name])) {
                    continue;
                }

                $settings = is_array($element['settings'] ?? null) ? $element['settings'] : [];

                $forms[] = [
                    'postId'            => (int) $row->post_id,
                    'postTitle'         => (string) ($row->post_title !== '' ? $row->post_title : '(no title)'),
                    'postType'          => (string) $row->post_type,
                    'postStatus'        => (string) $row->post_status,
                    'metaKey'           => (string) $row->meta_key,
                    'elementId'         => (string) ($element['id'] ?? ''),
                    'formType'          => self::FORM_ELEMENT_NAMES[$name],
                    'fromName'          => self::scalarToString($settings['fromName'] ?? null),
                    'fromEmail'         => self::scalarToString($settings['fromEmail'] ?? null),
                    'replyToEmail'      => self::scalarToString($settings['replyToEmail'] ?? null),
                    'emailTo'           => self::scalarToString($settings['emailTo'] ?? null),
                    'emailCc'           => self::scalarToString($settings['emailCc'] ?? null),
                    'emailSubject'      => self::scalarToString($settings['emailSubject'] ?? null),
                    'successMessage'    => self::scalarToString($settings['successMessage'] ?? null),
                    'emailErrorMessage' => self::scalarToString($settings['emailErrorMessage'] ?? null),
                ];
            }
        }

        usort($forms, static function (array $a, array $b): int {
            $byTitle = strcasecmp((string) $a['postTitle'], (string) $b['postTitle']);
            return $byTitle !== 0 ? $byTitle : strcmp((string) $a['elementId'], (string) $b['elementId']);
        });

        return $forms;
    }

    /**
     * For each (post_id, family) keep only the row with the highest version
     * suffix — `_bricks_page_content_3` wins over `_bricks_page_content_2` and
     * the unversioned `_bricks_page_content` (treated as version 0).
     *
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
     * @return array{0:string,1:int}|null  [family, version] or null if the key is not a Bricks key
     */
    private static function parseFamilyAndVersion(string $metaKey): ?array
    {
        if (!preg_match('/^_bricks_page_(content|header|footer)(?:_(\d+))?$/', $metaKey, $m)) {
            return null;
        }
        $family  = $m[1];
        $version = isset($m[2]) ? (int) $m[2] : 0;
        return [$family, $version];
    }

    /**
     * Bricks stores element trees as serialized PHP arrays (older) or
     * JSON-encoded strings (newer). Handle both.
     *
     * @return mixed
     */
    private static function decode(mixed $raw)
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

    private static function scalarToString(mixed $val): ?string
    {
        if ($val === null || $val === '') {
            return null;
        }
        if (is_array($val)) {
            $flat = array_filter(array_map(static fn ($v) => is_scalar($v) ? (string) $v : null, $val));
            return $flat === [] ? null : implode(', ', $flat);
        }
        return is_scalar($val) ? (string) $val : null;
    }
}

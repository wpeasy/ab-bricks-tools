<?php
declare(strict_types=1);

namespace AB\BricksTools\Modules\BricksClassVariableFinder;

/**
 * Tracks revisions of `bricks_global_classes` and `bricks_global_variables`.
 *
 * - Snapshots are taken from `update_option_<source>` hooks (see
 *   `Module::on*OptionUpdate`), capturing the OLD value plus a short diff
 *   summary so a user can later see what changed.
 * - Capped at 50 per kind. Newest first.
 * - Restore: applies the snapshot back to the source option AND deletes every
 *   revision NEWER than the restored one ("strict truncation").
 * - `update_option(...)` calls performed during a restore must NOT trigger a
 *   fresh snapshot — that's what the static `$restoring` flag is for.
 */
final class RevisionStore
{
    public const KIND_CLASSES   = 'classes';
    public const KIND_VARIABLES = 'variables';

    public const MAX_REVISIONS = 50;

    private const OPTION_CLASSES_REVISIONS   = 'abbtl_classes_revisions';
    private const OPTION_VARIABLES_REVISIONS = 'abbtl_variables_revisions';

    private const SOURCE_OPTION_CLASSES   = 'bricks_global_classes';
    private const SOURCE_OPTION_VARIABLES = 'bricks_global_variables';

    /** Re-entrancy guard for restore(). */
    public static bool $restoring = false;

    public static function isValidKind(string $kind): bool
    {
        return $kind === self::KIND_CLASSES || $kind === self::KIND_VARIABLES;
    }

    public static function revisionsOptionKey(string $kind): ?string
    {
        return match ($kind) {
            self::KIND_CLASSES   => self::OPTION_CLASSES_REVISIONS,
            self::KIND_VARIABLES => self::OPTION_VARIABLES_REVISIONS,
            default              => null,
        };
    }

    public static function sourceOptionKey(string $kind): ?string
    {
        return match ($kind) {
            self::KIND_CLASSES   => self::SOURCE_OPTION_CLASSES,
            self::KIND_VARIABLES => self::SOURCE_OPTION_VARIABLES,
            default              => null,
        };
    }

    /**
     * @return array<int, array{id:string, ts:int, summary:string, snapshot:array}>
     */
    public static function getAll(string $kind): array
    {
        $key = self::revisionsOptionKey($kind);
        if ($key === null) {
            return [];
        }
        $value = get_option($key, []);
        return is_array($value) ? $value : [];
    }

    /**
     * Push a new revision (newest first), capping the list at MAX_REVISIONS.
     *
     * @param array<int|string, mixed> $oldSnapshot
     */
    public static function add(string $kind, array $oldSnapshot, string $summary): void
    {
        if (self::$restoring) {
            return;
        }
        $key = self::revisionsOptionKey($kind);
        if ($key === null) {
            return;
        }

        $list  = self::getAll($kind);
        $entry = [
            'id'       => uniqid('rev_', true),
            'ts'       => time(),
            'summary'  => $summary,
            'snapshot' => $oldSnapshot,
        ];

        array_unshift($list, $entry);
        if (count($list) > self::MAX_REVISIONS) {
            $list = array_slice($list, 0, self::MAX_REVISIONS);
        }
        update_option($key, $list, false);
    }

    /**
     * Restore a revision by id: write its snapshot to the source option.
     * Non-destructive — the revisions list is NOT modified, so users can
     * navigate forward and backward through the timeline freely. The list
     * is only pruned by add()'s MAX_REVISIONS cap when a 51st snapshot
     * arrives at the head.
     *
     * Returns the ts of the restored entry (for client-side cursor
     * tracking), or null when no revision matches the given id.
     */
    public static function restore(string $kind, string $revisionId): ?int
    {
        $sourceKey = self::sourceOptionKey($kind);
        if ($sourceKey === null) {
            return null;
        }

        $found = null;
        foreach (self::getAll($kind) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (($entry['id'] ?? null) === $revisionId) {
                $found = $entry;
                break;
            }
        }
        if ($found === null) {
            return null;
        }

        // Apply snapshot back to source — guarded so our update_option hook
        // doesn't snapshot the about-to-be-replaced state as a new revision.
        self::$restoring = true;
        try {
            update_option($sourceKey, $found['snapshot']);
        } finally {
            self::$restoring = false;
        }

        return (int) ($found['ts'] ?? 0);
    }

    /**
     * Pick one revision across both kinds (classes + variables):
     *
     *   - both null  → absolute newest (largest ts)
     *   - $beforeTs  → newest with ts < $beforeTs (for Ctrl+Z step-back)
     *   - $afterTs   → oldest with ts > $afterTs  (for Ctrl+R step-forward)
     *
     * @return array{kind:string, entry:array}|null
     */
    public static function latest(?int $beforeTs = null, ?int $afterTs = null): ?array
    {
        $candidates = [];
        foreach ([self::KIND_CLASSES, self::KIND_VARIABLES] as $kind) {
            foreach (self::getAll($kind) as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $ts = (int) ($entry['ts'] ?? 0);
                if ($beforeTs !== null && $ts >= $beforeTs) {
                    continue;
                }
                if ($afterTs !== null && $ts <= $afterTs) {
                    continue;
                }
                $candidates[] = ['kind' => $kind, 'entry' => $entry, 'ts' => $ts];
            }
        }
        if ($candidates === []) {
            return null;
        }

        // For "before" or absolute newest, pick largest ts. For "after", pick smallest ts.
        $pick = $candidates[0];
        if ($afterTs !== null) {
            foreach ($candidates as $c) {
                if ($c['ts'] < $pick['ts']) {
                    $pick = $c;
                }
            }
        } else {
            foreach ($candidates as $c) {
                if ($c['ts'] > $pick['ts']) {
                    $pick = $c;
                }
            }
        }
        return ['kind' => $pick['kind'], 'entry' => $pick['entry']];
    }

    /**
     * Diff old vs new and produce a short human-readable summary.
     *
     * @param mixed $oldValue
     * @param mixed $newValue
     */
    public static function summarize(string $kind, $oldValue, $newValue): string
    {
        if (!is_array($oldValue)) {
            $oldValue = [];
        }
        if (!is_array($newValue)) {
            $newValue = [];
        }

        $oldById = [];
        foreach ($oldValue as $item) {
            if (is_array($item) && isset($item['id']) && is_string($item['id'])) {
                $oldById[$item['id']] = $item;
            }
        }
        $newById = [];
        foreach ($newValue as $item) {
            if (is_array($item) && isset($item['id']) && is_string($item['id'])) {
                $newById[$item['id']] = $item;
            }
        }

        $renamed = [];
        foreach ($newById as $id => $entry) {
            if (!isset($oldById[$id])) {
                continue;
            }
            $oldName = (string) ($oldById[$id]['name'] ?? '');
            $newName = (string) ($entry['name'] ?? '');
            if ($oldName !== '' && $newName !== '' && $oldName !== $newName) {
                $renamed[] = ['old' => $oldName, 'new' => $newName];
            }
        }
        $added   = array_diff_key($newById, $oldById);
        $removed = array_diff_key($oldById, $newById);

        $prefix = $kind === self::KIND_CLASSES ? '.' : '--';
        $parts  = [];

        if (count($renamed) === 1) {
            $r        = $renamed[0];
            $parts[]  = sprintf('Renamed %s%s → %s%s', $prefix, $r['old'], $prefix, $r['new']);
        } elseif (count($renamed) > 1) {
            $parts[] = sprintf('Renamed %d %s', count($renamed), $kind === self::KIND_CLASSES ? 'classes' : 'variables');
        }

        if (count($added) === 1) {
            $first   = reset($added);
            $name    = (string) ($first['name'] ?? '?');
            $parts[] = sprintf('Added %s%s', $prefix, $name);
        } elseif (count($added) > 1) {
            $parts[] = sprintf('Added %d %s', count($added), $kind === self::KIND_CLASSES ? 'classes' : 'variables');
        }

        if (count($removed) === 1) {
            $first   = reset($removed);
            $name    = (string) ($first['name'] ?? '?');
            $parts[] = sprintf('Removed %s%s', $prefix, $name);
        } elseif (count($removed) > 1) {
            $parts[] = sprintf('Removed %d %s', count($removed), $kind === self::KIND_CLASSES ? 'classes' : 'variables');
        }

        return $parts === [] ? 'Settings updated' : implode('; ', $parts);
    }
}

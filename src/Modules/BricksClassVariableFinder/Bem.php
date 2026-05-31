<?php
declare(strict_types=1);

namespace AB\BricksTools\Modules\BricksClassVariableFinder;

/**
 * BEM-aware class-name + label utilities.
 *
 * Convention assumed: `block__element--modifier`. Words within a segment
 * use `-` (kebab). Anything that doesn't match is treated as a block-only
 * class (and produces a sensible label).
 *
 * Helpers here are pure — no WordPress dependency, easy to unit-test.
 */
final class Bem
{
    /**
     * Parse a class name into BEM segments.
     *
     * @return array{block:string, element:?string, modifier:?string}|null
     *         null when the name doesn't start with a letter or underscore.
     */
    public static function parse(string $name): ?array
    {
        if ($name === '') {
            return null;
        }
        if (!preg_match('/^([A-Za-z_][\w-]*?)(?:__([A-Za-z_][\w-]*?))?(?:--([A-Za-z_][\w-]*?))?$/', $name, $m)) {
            return null;
        }
        return [
            'block'    => $m[1],
            'element'  => isset($m[2]) && $m[2] !== '' ? $m[2] : null,
            'modifier' => isset($m[3]) && $m[3] !== '' ? $m[3] : null,
        ];
    }

    /**
     * "Block-only" = neither `__` nor `--` appears. Per spec, this is the
     * only case where renames propagate to sibling classes.
     */
    public static function isBlockOnly(string $name): bool
    {
        return strpos($name, '__') === false && strpos($name, '--') === false;
    }

    /**
     * Return the IDs of every class in the given block's family: the
     * block-only class itself (if it exists) plus every class whose name
     * starts with `<block>__` or `<block>--`.
     *
     * @param array<int, mixed> $classes The bricks_global_classes option.
     * @return string[] Class IDs.
     */
    public static function findBlockFamilyClassIds(string $block, array $classes): array
    {
        $ids        = [];
        $elemPrefix = $block . '__';
        $modPrefix  = $block . '--';

        foreach ($classes as $cls) {
            if (!is_array($cls)) {
                continue;
            }
            $name = $cls['name'] ?? null;
            $id   = $cls['id']   ?? null;
            if (!is_string($name) || !is_string($id)) {
                continue;
            }
            if ($name === $block
                || strpos($name, $elemPrefix) === 0
                || strpos($name, $modPrefix) === 0
            ) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Compute the label derived from a class name:
     *  - B only           → B segment, sentence-cased
     *  - B + E            → E segment, sentence-cased
     *  - B + E + M        → E segment, sentence-cased (M ignored)
     *  - B + M (no E)     → B segment, sentence-cased (M ignored)
     */
    public static function labelFromClass(string $className): string
    {
        $parsed = self::parse($className);
        if ($parsed === null) {
            return self::segmentToSentence($className);
        }
        $segment = $parsed['element'] !== null ? $parsed['element'] : $parsed['block'];
        return self::segmentToSentence($segment);
    }

    /**
     * "brand-card" → "Brand card"
     */
    public static function segmentToSentence(string $segment): string
    {
        $spaced = str_replace('-', ' ', $segment);
        if ($spaced === '') {
            return '';
        }
        return mb_strtoupper(mb_substr($spaced, 0, 1)) . mb_strtolower(mb_substr($spaced, 1));
    }

    /**
     * Normalize a label string for matching: strip parenthesised /
     * bracketed / braced regions and their contents, trim, lowercase,
     * collapse internal whitespace to a single dash. So `"Brand Card
     * (left)"` → `"brand-card"`.
     */
    public static function normalizeLabelForMatch(string $label): string
    {
        $stripped = preg_replace('/\s*[\(\[\{][^\(\[\{\)\]\}]*[\)\]\}]\s*/u', ' ', $label) ?? '';
        $stripped = trim($stripped);
        $stripped = mb_strtolower($stripped);
        $stripped = (string) preg_replace('/\s+/', '-', $stripped);
        return $stripped;
    }

    /**
     * Rewrite a label, replacing the first non-bracketed segment that
     * normalize-matches `$oldDerived` with `$newDerived`. Preserves all
     * bracketed comments and their original positions / whitespace.
     *
     * Returns the input unchanged if no segment matched.
     */
    public static function rewriteLabel(string $label, string $oldDerived, string $newDerived): string
    {
        if ($label === '') {
            return $label;
        }
        $parts = preg_split('/([\(\[\{][^\(\[\{\)\]\}]*[\)\]\}])/u', $label, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            return $label;
        }

        $target = self::normalizeLabelForMatch($oldDerived);

        $changed = false;
        foreach ($parts as $i => $part) {
            // PREG_SPLIT_DELIM_CAPTURE: even indices = non-delimiter, odd = bracket match.
            if ($i % 2 === 1) {
                continue;
            }
            $trimmed = trim($part);
            if ($trimmed === '') {
                continue;
            }
            if (self::normalizeLabelForMatch($trimmed) !== $target) {
                continue;
            }
            // Preserve leading/trailing whitespace surrounding this segment.
            preg_match('/^\s*/u', $part, $leadMatch);
            preg_match('/\s*$/u', $part, $tailMatch);
            $parts[$i] = ($leadMatch[0] ?? '') . $newDerived . ($tailMatch[0] ?? '');
            $changed   = true;
            break;
        }

        return $changed ? implode('', $parts) : $label;
    }
}

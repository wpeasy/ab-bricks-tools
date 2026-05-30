<?php
/**
 * WP-CLI scanner for the Class/Variable Finder.
 *
 * Invoked by UsageFinder via `wp eval-file <thisfile> --skip-plugins
 * --skip-themes`. Reads a JSON target descriptor from STDIN, calls the pure
 * UsageScanner, writes a JSON array of usages to STDOUT.
 *
 * Self-contained — the plugin autoloader is NOT available with --skip-plugins.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    fwrite(STDERR, "wpcli-scan.php must be executed via `wp eval-file`.\n");
    exit(1);
}

require_once __DIR__ . '/UsageScanner.php';

use AB\BricksTools\Modules\BricksClassVariableFinder\UsageScanner;

$input = stream_get_contents(STDIN);
if (!is_string($input) || trim($input) === '') {
    fwrite(STDERR, "[abbtl cvf wpcli-scan] empty STDIN — expected JSON target\n");
    exit(1);
}

$target = json_decode($input, true);
if (!is_array($target)) {
    fwrite(STDERR, "[abbtl cvf wpcli-scan] STDIN was not a JSON object\n");
    exit(1);
}

global $wpdb;

try {
    $usages = UsageScanner::scanFromWpdb($wpdb, $target);
    echo json_encode($usages, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    fwrite(STDERR, '[abbtl cvf wpcli-scan] ' . $e->getMessage() . "\n");
    exit(1);
}

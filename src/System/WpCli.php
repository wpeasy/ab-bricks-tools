<?php
declare(strict_types=1);

namespace AB\BricksTools\System;

/**
 * Detects WP-CLI and produces fully-formed shell commands that work across
 * environments:
 *
 *  - System install (`wp` on $PATH) — used as-is.
 *  - Local by Flywheel — Local bundles WP-CLI inside its app install
 *    (`C:\Program Files (x86)\Local\resources\extraResources\bin\wp-cli\...`)
 *    and does NOT put `wp` on the PHP-FPM PATH, so a naive `wp --version`
 *    always reports "not available" even when WP-CLI is fully functional.
 *    For Local we probe the bundled php.exe + wp-cli.phar pair, and resolve
 *    the SITE's rendered php.ini so DB-touching commands inherit the right
 *    `mysqli.default_port` (Local binds MySQL to a per-site loopback port).
 *
 * Result (including the runtime descriptor) is cached in a transient.
 * Re-detection requires WpCli::refresh().
 */
final class WpCli
{
    public const TRANSIENT_KEY = 'abbtl_wpcli_status';
    public const CACHE_TTL     = HOUR_IN_SECONDS;

    /** Cache schema version — bump when the cached array shape changes. */
    private const CACHE_SCHEMA = 2;

    public const RUNTIME_SYSTEM = 'system';
    public const RUNTIME_LOCAL  = 'local';

    /**
     * @return array{
     *   available: bool,
     *   version: ?string,
     *   reason: ?string,
     *   runtime: ?array{type:string, php?:string, phar?:string, ini?:?string}
     * }
     */
    public static function status(): array
    {
        $cached = get_transient(self::TRANSIENT_KEY);
        if (is_array($cached)
            && ($cached['_schema'] ?? null) === self::CACHE_SCHEMA
            && array_key_exists('available', $cached)
        ) {
            return $cached;
        }
        return self::refresh();
    }

    /**
     * @return array{available: bool, version: ?string, reason: ?string, runtime: ?array}
     */
    public static function refresh(): array
    {
        $status            = self::detect();
        $status['_schema'] = self::CACHE_SCHEMA;
        set_transient(self::TRANSIENT_KEY, $status, self::CACHE_TTL);
        return $status;
    }

    /**
     * Build a shell command for `wp <args>` using whichever runtime was
     * detected. Returns null if WP-CLI isn't available.
     */
    public static function buildCommand(string $args): ?string
    {
        $status  = self::status();
        if (!$status['available']) {
            return null;
        }
        $runtime = $status['runtime'] ?? null;
        if (!is_array($runtime)) {
            return null;
        }

        $type = (string) ($runtime['type'] ?? '');

        if ($type === self::RUNTIME_SYSTEM) {
            return 'wp ' . $args;
        }

        if ($type === self::RUNTIME_LOCAL) {
            $php  = (string) ($runtime['php']  ?? '');
            $phar = (string) ($runtime['phar'] ?? '');
            $ini  = isset($runtime['ini']) && is_string($runtime['ini']) ? $runtime['ini'] : '';
            if ($php === '' || $phar === '') {
                return null;
            }
            $parts = [escapeshellarg($php)];
            if ($ini !== '' && is_file($ini)) {
                $parts[] = '-c';
                $parts[] = escapeshellarg($ini);
            }
            $parts[] = escapeshellarg($phar);
            $parts[] = $args;
            return implode(' ', $parts);
        }

        return null;
    }

    /**
     * @return array{available: bool, version: ?string, reason: ?string, runtime: ?array}
     */
    private static function detect(): array
    {
        if (!function_exists('proc_open') || !function_exists('proc_close')) {
            return [
                'available' => false,
                'version'   => null,
                'reason'    => __('proc_open() is disabled on this host.', 'ab-bricks-tools'),
                'runtime'   => null,
            ];
        }

        // 1. System wp on PATH.
        $sys = self::probeSystem();
        if ($sys !== null) {
            return [
                'available' => true,
                'version'   => $sys['version'],
                'reason'    => null,
                'runtime'   => $sys['runtime'],
            ];
        }

        // 2. Local by Flywheel bundled WP-CLI.
        $local = self::probeLocal();
        if ($local !== null) {
            return [
                'available' => true,
                'version'   => $local['version'],
                'reason'    => null,
                'runtime'   => $local['runtime'],
            ];
        }

        return [
            'available' => false,
            'version'   => null,
            'reason'    => __('No `wp` on PATH and no Local-bundled WP-CLI found.', 'ab-bricks-tools'),
            'runtime'   => null,
        ];
    }

    /**
     * @return array{version: ?string, runtime: array{type: string}}|null
     */
    private static function probeSystem(): ?array
    {
        $result = self::tryCommand('wp --version');
        if (!$result['ok']) {
            return null;
        }
        return [
            'version' => self::parseVersion($result['stdout']),
            'runtime' => ['type' => self::RUNTIME_SYSTEM],
        ];
    }

    /**
     * @return array{version: ?string, runtime: array{type:string, php:string, phar:string, ini:?string}}|null
     */
    private static function probeLocal(): ?array
    {
        foreach (self::findLocalRuntimePairs() as $pair) {
            [$php, $phar] = $pair;
            $cmd    = escapeshellarg($php) . ' ' . escapeshellarg($phar) . ' --version';
            $result = self::tryCommand($cmd);
            if (!$result['ok']) {
                continue;
            }
            return [
                'version' => self::parseVersion($result['stdout']),
                'runtime' => [
                    'type' => self::RUNTIME_LOCAL,
                    'php'  => $php,
                    'phar' => $phar,
                    'ini'  => self::resolveLocalSiteIni(),
                ],
            ];
        }
        return null;
    }

    /**
     * Probe known Local install roots on Windows. Returns [php, phar] pairs.
     *
     * @return array<int, array{0:string, 1:string}>
     */
    private static function findLocalRuntimePairs(): array
    {
        $roots = [
            'C:\\Program Files (x86)\\Local\\resources\\extraResources',
            'C:\\Program Files\\Local\\resources\\extraResources',
        ];

        $pairs = [];
        foreach ($roots as $root) {
            $phar = $root . '\\bin\\wp-cli\\wp-cli.phar';
            if (!is_file($phar)) {
                continue;
            }

            $phps = glob($root . '\\lightning-services\\php-*\\bin\\win64\\php.exe') ?: [];
            if (!$phps) {
                continue;
            }

            // Newest version first (descending).
            usort($phps, static function (string $a, string $b): int {
                preg_match('/php-([0-9.]+)/', $a, $am);
                preg_match('/php-([0-9.]+)/', $b, $bm);
                return -version_compare((string) ($am[1] ?? '0'), (string) ($bm[1] ?? '0'));
            });

            $pairs[] = [$phps[0], $phar];
        }
        return $pairs;
    }

    /**
     * Resolve the current Local site's rendered php.ini, which carries the
     * per-site `mysqli.default_port` override needed for DB-touching commands.
     */
    private static function resolveLocalSiteIni(): ?string
    {
        if (!defined('ABSPATH')) {
            return null;
        }
        $abs = self::normalizePath(ABSPATH);
        if (!preg_match('|/Local Sites/([^/]+)/app/public|', $abs, $m)) {
            return null;
        }
        $siteFolder = $m[1];

        $home = self::userHome();
        if ($home === null) {
            return null;
        }

        $sitesJson = $home . '/AppData/Roaming/Local/sites.json';
        if (!is_file($sitesJson)) {
            return null;
        }

        $raw = @file_get_contents($sitesJson);
        if ($raw === false) {
            return null;
        }

        $sites = json_decode($raw, true);
        if (!is_array($sites)) {
            return null;
        }

        $needle = '/' . $siteFolder;
        foreach ($sites as $siteId => $site) {
            if (!is_array($site)) {
                continue;
            }
            $sitePath = self::normalizePath((string) ($site['path'] ?? ''));
            if ($sitePath === '') {
                continue;
            }
            if (!str_ends_with($sitePath, $needle)) {
                continue;
            }
            $iniPath = $home . '/AppData/Roaming/Local/run/' . $siteId . '/conf/php/php.ini';
            if (is_file($iniPath)) {
                return $iniPath;
            }
        }
        return null;
    }

    private static function userHome(): ?string
    {
        $home = $_SERVER['USERPROFILE'] ?? getenv('USERPROFILE');
        if (!$home) {
            $drive = $_SERVER['HOMEDRIVE'] ?? getenv('HOMEDRIVE');
            $path  = $_SERVER['HOMEPATH']  ?? getenv('HOMEPATH');
            if ($drive && $path) {
                $home = $drive . $path;
            }
        }
        if (!is_string($home) || $home === '') {
            return null;
        }
        return self::normalizePath($home);
    }

    private static function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private static function parseVersion(string $stdout): ?string
    {
        if (preg_match('/WP-CLI\s+(\S+)/i', $stdout, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * @return array{ok:bool, stdout:string, stderr:string, exit:int}
     */
    private static function tryCommand(string $cmd): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            return ['ok' => false, 'stdout' => '', 'stderr' => 'process spawn failed', 'exit' => -1];
        }

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return [
            'ok'     => $exit === 0,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exit'   => $exit,
        ];
    }
}

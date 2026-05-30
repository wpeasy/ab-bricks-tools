<?php
declare(strict_types=1);

namespace AB\BricksTools\Modules\BricksFormManager;

use AB\BricksTools\System\WpCli;

/**
 * Dispatcher — chooses the scan engine.
 *
 * - WP-CLI if available (out-of-process, --skip-plugins/--skip-themes, fast)
 * - PHP fallback (in-process via FormScanner)
 *
 * The engine that produced the result is exposed via $lastEngine.
 */
final class FormFinder
{
    public const ENGINE_WPCLI = 'wp-cli';
    public const ENGINE_PHP   = 'php';

    public string $lastEngine = '';

    /**
     * @return Form[]
     */
    public function findAll(): array
    {
        $raw = $this->dispatch();
        return array_map([$this, 'hydrate'], $raw);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function dispatch(): array
    {
        if (WpCli::status()['available']) {
            $rows = $this->scanViaWpCli();
            if ($rows !== null) {
                $this->lastEngine = self::ENGINE_WPCLI;
                return $rows;
            }
            // Runtime failure (proc_open denied, wp errored, non-JSON output);
            // silently fall through to the PHP path.
        }

        global $wpdb;
        $this->lastEngine = self::ENGINE_PHP;
        return FormScanner::scanFromWpdb($wpdb);
    }

    /**
     * @return array<int, array<string, mixed>>|null  null on any failure
     */
    private function scanViaWpCli(): ?array
    {
        if (!function_exists('proc_open') || !function_exists('proc_close')) {
            return null;
        }

        $script = ABBTL_PLUGIN_DIR . 'src/Modules/BricksFormManager/wpcli-scan.php';
        if (!is_file($script)) {
            return null;
        }

        $args = sprintf(
            'eval-file %s --skip-plugins --skip-themes --path=%s',
            escapeshellarg($script),
            escapeshellarg(ABSPATH)
        );
        $cmd = WpCli::buildCommand($args);
        if ($cmd === null) {
            return null;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        // Drain stderr so the child can't block on a full pipe; we don't surface it here.
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            return null;
        }

        $data = json_decode($stdout, true);
        if (!is_array($data)) {
            return null;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $r
     */
    private function hydrate(array $r): Form
    {
        $str = static fn (string $k): ?string => isset($r[$k]) && is_string($r[$k]) ? $r[$k] : null;
        return new Form(
            postId:            (int) ($r['postId'] ?? 0),
            postTitle:         (string) ($r['postTitle'] ?? ''),
            postType:          (string) ($r['postType'] ?? ''),
            postStatus:        (string) ($r['postStatus'] ?? ''),
            metaKey:           (string) ($r['metaKey'] ?? ''),
            elementId:         (string) ($r['elementId'] ?? ''),
            formType:          (string) ($r['formType'] ?? ''),
            fromName:          $str('fromName'),
            fromEmail:         $str('fromEmail'),
            replyToEmail:      $str('replyToEmail'),
            emailTo:           $str('emailTo'),
            emailCc:           $str('emailCc'),
            emailSubject:      $str('emailSubject'),
            successMessage:    $str('successMessage'),
            emailErrorMessage: $str('emailErrorMessage'),
        );
    }
}

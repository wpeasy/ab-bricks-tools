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

    /** @var array{stage:string, exitCode?:int, stderr?:string, cmd?:string}|null */
    public ?array $lastEngineError = null;

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
        $this->lastEngineError = null;

        if (!function_exists('proc_open') || !function_exists('proc_close')) {
            $this->lastEngineError = ['stage' => 'proc_open disabled'];
            return null;
        }

        $script = ABBTL_PLUGIN_DIR . 'src/Modules/BricksFormManager/wpcli-scan.php';
        if (!is_file($script)) {
            $this->lastEngineError = ['stage' => 'script missing'];
            return null;
        }

        $args = sprintf(
            'eval-file %s --skip-plugins --skip-themes --path=%s',
            escapeshellarg($script),
            escapeshellarg(ABSPATH)
        );
        $cmd = WpCli::buildCommand($args);
        if ($cmd === null) {
            $this->lastEngineError = ['stage' => 'buildCommand returned null'];
            return null;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // Bypass cmd.exe on Windows — the multi-quoted-arg command (php + ini +
        // phar + script + ABSPATH, several containing parens) is fragile through
        // `cmd /S /C "..."`. CreateProcess parses it cleanly.
        $options = [];
        if (defined('PHP_WINDOWS_VERSION_MAJOR')) {
            $options['bypass_shell'] = true;
        }

        $process = @proc_open($cmd, $descriptors, $pipes, null, null, $options);
        if (!is_resource($process)) {
            $this->lastEngineError = ['stage' => 'proc_open spawn failed', 'cmd' => $cmd];
            return null;
        }

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $this->lastEngineError = [
                'stage'    => 'non-zero exit',
                'exitCode' => $exitCode,
                'stderr'   => mb_substr(trim($stderr), 0, 800),
                'cmd'      => (defined('WP_DEBUG') && WP_DEBUG) ? $cmd : null,
            ];
            return null;
        }

        $data = json_decode($stdout, true);
        if (!is_array($data)) {
            $this->lastEngineError = [
                'stage'    => 'invalid JSON from stdout',
                'stderr'   => mb_substr(trim($stderr), 0, 400),
                'stdout'   => mb_substr(trim($stdout), 0, 400),
                'cmd'      => (defined('WP_DEBUG') && WP_DEBUG) ? $cmd : null,
            ];
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
            emailSubject:             $str('emailSubject'),
            confirmationFromName:     $str('confirmationFromName'),
            confirmationFromEmail:    $str('confirmationFromEmail'),
            confirmationReplyToEmail: $str('confirmationReplyToEmail'),
            confirmationEmailTo:      $str('confirmationEmailTo'),
            confirmationEmailSubject: $str('confirmationEmailSubject'),
            successMessage:           $str('successMessage'),
            emailErrorMessage:        $str('emailErrorMessage'),
            hasRedirectAction:        !empty($r['hasRedirectAction']),
            redirect:                 $str('redirect'),
        );
    }
}

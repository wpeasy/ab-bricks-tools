<?php
declare(strict_types=1);

namespace AB\BricksTools\Modules\BricksClassVariableFinder;

use AB\BricksTools\System\WpCli;

final class UsageFinder
{
    public const ENGINE_WPCLI = 'wp-cli';
    public const ENGINE_PHP   = 'php';

    public string $lastEngine = '';

    /**
     * @param array{kind:string,id:string,name:string} $target
     * @return Usage[]
     */
    public function find(array $target): array
    {
        $raw = $this->dispatch($target);
        return array_map([$this, 'hydrate'], $raw);
    }

    /**
     * @param array{kind:string,id:string,name:string} $target
     * @return array<int, array<string, mixed>>
     */
    private function dispatch(array $target): array
    {
        if (WpCli::status()['available']) {
            $rows = $this->scanViaWpCli($target);
            if ($rows !== null) {
                $this->lastEngine = self::ENGINE_WPCLI;
                return $rows;
            }
        }
        global $wpdb;
        $this->lastEngine = self::ENGINE_PHP;
        return UsageScanner::scanFromWpdb($wpdb, $target);
    }

    /**
     * @param array{kind:string,id:string,name:string} $target
     * @return array<int, array<string, mixed>>|null
     */
    private function scanViaWpCli(array $target): ?array
    {
        if (!function_exists('proc_open') || !function_exists('proc_close')) {
            return null;
        }

        $script = ABBTL_PLUGIN_DIR . 'src/Modules/BricksClassVariableFinder/wpcli-scan.php';
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

        $payload = json_encode($target);
        if ($payload === false) {
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            return null;
        }

        fwrite($pipes[0], $payload);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]); // drain
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            return null;
        }

        $data = json_decode($stdout, true);
        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed> $r
     */
    private function hydrate(array $r): Usage
    {
        return new Usage(
            postId:       (int) ($r['postId'] ?? 0),
            postTitle:    (string) ($r['postTitle'] ?? ''),
            postType:     (string) ($r['postType'] ?? ''),
            postStatus:   (string) ($r['postStatus'] ?? ''),
            metaKey:      (string) ($r['metaKey'] ?? ''),
            elementId:    (string) ($r['elementId'] ?? ''),
            elementName:  (string) ($r['elementName'] ?? ''),
            elementLabel: isset($r['elementLabel']) && is_string($r['elementLabel']) ? $r['elementLabel'] : null,
        );
    }
}

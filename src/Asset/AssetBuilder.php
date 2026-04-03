<?php

declare(strict_types=1);

namespace DbCommander\Asset;

/**
 * Builds versioned static assets from resources/ into public/assets/.
 *
 * Dev mode:  rebuilds if source files are newer than existing assets.
 * Prod mode: builds once, never overwrites existing assets.
 *
 * Returns an array with the public URLs of the built assets:
 *   ['css' => '/assets/app.{mtime}.css', 'js' => '/assets/app.{mtime}.js']
 */
final class AssetBuilder
{
    private string $resourcesDir;
    private string $assetsDir;
    private string $assetsUrl;

    public function __construct(
        private readonly string $appEnv = 'prod',
        string $resourcesDir = '',
        string $assetsDir    = '',
        string $assetsUrl    = '/assets',
    ) {
        $this->resourcesDir = $resourcesDir ?: dirname(__DIR__, 2) . '/resources';
        $this->assetsDir    = $assetsDir    ?: dirname(__DIR__, 2) . '/public/assets';
        $this->assetsUrl    = rtrim($assetsUrl, '/');
    }

    /**
     * @return array{css: string, js: string}
     */
    public function build(): array
    {
        $jsSrc  = $this->resourcesDir . '/app.js';
        $cssSrc = $this->resourcesDir . '/app.css';

        $jsMtime  = filemtime($jsSrc);
        $cssMtime = filemtime($cssSrc);

        $jsOut  = $this->assetsDir . '/app.' . $jsMtime  . '.js';
        $cssOut = $this->assetsDir . '/app.' . $cssMtime . '.css';

        if (!is_dir($this->assetsDir)) {
            mkdir($this->assetsDir, 0755, true);
        }

        if ($this->shouldBuild($jsOut)) {
            $this->cleanOld('app.*.js');
            copy($jsSrc, $jsOut);
        }

        if ($this->shouldBuild($cssOut)) {
            $this->cleanOld('app.*.css');
            copy($cssSrc, $cssOut);
        }

        return [
            'css' => $this->assetsUrl . '/app.' . $cssMtime . '.css',
            'js'  => $this->assetsUrl . '/app.' . $jsMtime  . '.js',
        ];
    }

    // ── private ──────────────────────────────────────────────

    private function shouldBuild(string $outputPath): bool
    {
        if (!file_exists($outputPath)) {
            return true;
        }

        // Dev: rebuild if source is newer
        if ($this->appEnv === 'dev') {
            return true;
        }

        // Prod: never rebuild if file exists
        return false;
    }

    private function cleanOld(string $pattern): void
    {
        foreach (glob($this->assetsDir . '/' . $pattern) ?: [] as $file) {
            unlink($file);
        }
    }
}

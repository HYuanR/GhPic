<?php
/**
 * GhPic 图片兜底代理
 *
 * @package GhPic
 */

namespace TypechoPlugin\GhPic;

use Utils\Helper;

class Img extends \Typecho\Widget
{
    public function execute()
    {
    }

    public function render()
    {
        $opt = Plugin::currentConfig();
        if (!$opt) {
            $this->fail(404, 'not configured');
        }

        $path = ltrim(str_replace('\\', '/', (string) $this->request->get('p')), '/');
        if (!Plugin::validPath($path)) {
            $this->fail(400, 'bad path');
        }
        if (!Plugin::refererAllowed($opt)) {
            $this->fail(403, 'referer not allowed');
        }
        if (!Plugin::rateAllowed($opt)) {
            $this->fail(429, 'too many requests');
        }

        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = Plugin::mime($ext);
        $ttl  = max(3600, ((int) ($opt->cache_ttl ?? 7)) * 86400);

        $cacheDir  = __TYPECHO_ROOT_DIR__ . '/usr/uploads/.ghpic-cache';
        $cacheFile = $cacheDir . '/' . md5($path) . '.' . $ext;

        if (!is_dir($cacheDir) && is_dir(__TYPECHO_ROOT_DIR__ . '/usr/uploads')) {
            @mkdir($cacheDir, 0755, true);
        }

        if (is_file($cacheFile) && (time() - filemtime($cacheFile) < $ttl)) {
            $data = @file_get_contents($cacheFile);
            if (false !== $data && '' !== $data) {
                $this->send($data, $mime, 'cache', $ttl);
            }
        }

        $sources = [Plugin::cdnBase($opt) . '/' . $path];

        [$owner, $repo] = Plugin::repoOf($opt);
        if ('' !== $owner && '' !== $repo) {
            $branch = trim((string) ($opt->branch ?? 'main')) ?: 'main';
            $sources[] = 'https://raw.githubusercontent.com/' . $owner . '/' . $repo . '/'
                . $branch . '/' . implode('/', array_map('rawurlencode', explode('/', $path)));
        }

        foreach ($sources as $url) {
            if ('' === trim($url, '/')) {
                continue;
            }
            $data = Plugin::fetchBinary($url, 8);
            if (null === $data || '' === $data) {
                continue;
            }
            if (!$this->looksLikeImage($data, $ext)) {
                continue;
            }
            if (is_dir($cacheDir)) {
                @file_put_contents($cacheFile, $data, LOCK_EX);
            }
            $this->send($data, $mime, 'origin', $ttl);
        }

        $this->fail(404, 'upstream unavailable');
    }

    private function looksLikeImage(string $data, string $ext): bool
    {
        if ('svg' === $ext) {
            return false !== stripos(substr($data, 0, 512), '<svg');
        }

        return false !== @getimagesizefromstring($data);
    }

    private function send(string $data, string $mime, string $source, int $ttl)
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        if (!headers_sent()) {
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . strlen($data));
            header('Cache-Control: public, max-age=' . $ttl);
            header('X-GhPic: ' . $source);
        }

        echo $data;
        exit;
    }

    private function fail(int $code, string $msg)
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
        }

        echo $msg;
        exit;
    }
}

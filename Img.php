<?php
/**
 * GhPic — 图片兜底代理
 *
 * 当前端判定 CDN 图片超时或加载失败时，把请求转到这个地址，
 * 由服务端回源（先 CDN，再 GitHub raw）取图并输出，本地带缓存。
 *
 * 访问方式：/ghpicimg?p=uploads/2026/11/xxx.webp
 *
 * @package GhPic
 */

namespace TypechoPlugin\GhPic;

use Utils\Helper;

class Img extends \Typecho\Widget
{
    /** 默认缓存有效期（秒），实际以配置项 cache_ttl 为准 */
    const CACHE_TTL = 604800;

    public function execute()
    {
    }

    public function render()
    {
        $opt = null;
        try {
            $opt = Helper::options()->plugin('GhPic');
        } catch (\Throwable $e) {
        }
        if (!$opt) {
            $this->fail(404, 'GhPic not configured');
        }

        $path = (string) $this->request->get('p');
        $path = ltrim(str_replace('\\', '/', $path), '/');

        if (!Plugin::validPath($path)) {
            $this->fail(400, 'bad path');
        }

        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = Plugin::mime($ext);

        /* 缓存时长：配置按天，最小 1 小时 */
        $ttl = max(3600, ((int) ($opt->cache_ttl ?? 7)) * 86400);

        /* 1. 本地缓存 */
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

        /* 2. 回源：CDN → GitHub raw */
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
            $data = $this->fetch($url, 8);
            if (null === $data) {
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

    /** 请求远端，成功返回二进制，失败返回 null */
    private function fetch(string $url, int $timeout)
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
                CURLOPT_USERAGENT      => 'GhPic/' . Plugin::VERSION,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return (200 === $code && is_string($body) && '' !== $body) ? $body : null;
        }

        $ctx  = stream_context_create(['http' => [
            'timeout' => $timeout,
            'header'  => "User-Agent: GhPic/" . Plugin::VERSION . "\r\n",
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        if (false === $body || '' === $body) {
            return null;
        }
        if (isset($http_response_header) && preg_match('~HTTP/\S+\s+(\d{3})~', $http_response_header[0], $m)) {
            return 200 === (int) $m[1] ? $body : null;
        }

        return $body;
    }

    /** 简单校验确实是图片，避免把错误页当图输出 */
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

<?php
/**
 * 传图自动进 GitHub，CDN 加速访问，零成本不占主机空间。
 *
 * @package GhPic
 * @author  麦狗 <3037924279@qq.com>
 * @version 3.8.0
 * @link    https://mdog.cn/
 */

namespace TypechoPlugin\GhPic;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Hidden;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Textarea;
use Utils\Helper;

class Plugin implements PluginInterface
{
    const VERSION = '3.8.0';
    const API     = 'https://api.github.com';

    /** API 基地址，便于自测时替换 */
    public static $api = self::API;

    /** 允许接管的图片扩展名 */
    private static $allow = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'ico'];

    /* ================================================================== */
    /*  生命周期                                                           */
    /* ================================================================== */

    public static function activate()
    {
        \Typecho\Plugin::factory('Widget\Upload')->uploadHandle        = [self::class, 'uploadHandle'];
        \Typecho\Plugin::factory('Widget\Base\Contents')->contentEx    = [self::class, 'contentEx'];
        \Typecho\Plugin::factory('Widget\Base\Contents')->excerptEx    = [self::class, 'excerptEx'];
        \Typecho\Plugin::factory('Widget\Upload')->attachmentHandle    = [self::class, 'attachmentHandle'];
        \Typecho\Plugin::factory('Widget\Upload')->attachmentDataHandle = [self::class, 'attachmentDataHandle'];
        \Typecho\Plugin::factory('admin/menu.php')->navBar             = [self::class, 'navBar'];
        \Typecho\Plugin::factory('Widget\Archive')->footer             = [self::class, 'footer'];

        Helper::addRoute('ghpic-img', '/ghpicimg', 'TypechoPlugin\GhPic\Img', 'render');

        /* 标签表：失败不影响启用，面板会提示 */
        try {
            $prefix = \Typecho\Db::get()->getPrefix();
            \Typecho\Db::get()->query(
                "CREATE TABLE IF NOT EXISTS {$prefix}ghpic_tags ("
                . "path VARCHAR(191) NOT NULL PRIMARY KEY, "
                . "tags VARCHAR(255) NOT NULL DEFAULT '', "
                . "updated INT NOT NULL DEFAULT 0)",
                \Typecho\Db::WRITE
            );
        } catch (\Throwable $e) {
        }

        /* 此刻 Typecho 还没写入默认设置，不能调用 plugin()，否则抛异常会导致启用失败 */
        return _t('GhPic 已启用。请到设置中填写仓库地址与 Access Token，保存时自动校验连接。');
    }

    /**
     * 保存设置时的校验回调（1.3 特性：返回值直接显示在后台提示中）
     */
    public static function configCheck(array $settings)
    {
        $token = trim((string) ($settings['token'] ?? ''));
        $repo  = trim((string) ($settings['repo_url'] ?? ''));

        if ('' === $token || '' === $repo) {
            return _t('设置已保存。仓库地址或 Token 尚未填写，补齐后再次保存即可自动校验。');
        }

        return ' ' . self::selfCheck(new \Typecho\Config($settings));
    }

    /**
     * 安全读取插件设置：未启用或尚未保存过设置时返回 null，而不是抛异常
     */
    private static function settings()
    {
        $backup = self::backup();
        if ($backup) {
            return new \Typecho\Config($backup);
        }

        try {
            return Helper::options()->plugin('GhPic');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** 供面板/测试读取当前配置 */
    public static function currentConfig()
    {
        return self::settings();
    }

    /**
     * 自定义保存：Typecho 禁用插件时会删掉 plugin:GhPic 这一行，
     * 这里额外写一份 ghpic_config，重新启用时回填，避免丢配置。
     */
    public static function configHandle(array $settings, bool $isInit)
    {
        $saved = self::backup();

        if ($isInit && $saved) {
            self::seedPluginOption($saved);
            return;
        }

        self::seedPluginOption($settings);
        self::saveBackup($settings);
    }

    /** 读取备份配置（直接查库，避开 Options 缓存） */
    private static function backup(): array
    {
        try {
            $db  = \Typecho\Db::get();
            $row = $db->fetchRow(
                $db->select('value')->from('table.options')->where('name = ?', 'ghpic_config')
            );

            if (!empty($row['value'])) {
                $arr = json_decode((string) $row['value'], true);
                if (is_array($arr)) {
                    return $arr;
                }
            }
        } catch (\Throwable $e) {
        }

        return [];
    }

    /** 写入备份配置 */
    private static function saveBackup(array $settings): void
    {
        try {
            $db    = \Typecho\Db::get();
            $value = json_encode($settings);
            $exist = $db->fetchRow(
                $db->select('name')->from('table.options')->where('name = ?', 'ghpic_config')
            );

            if ($exist) {
                $db->query($db->update('table.options')->rows(['value' => $value])->where('name = ?', 'ghpic_config'));
            } else {
                $db->query($db->insert('table.options')->rows([
                    'name' => 'ghpic_config', 'user' => 0, 'value' => $value,
                ]));
            }
        } catch (\Throwable $e) {
        }
    }

    /** 写回 Typecho 原生 plugin:GhPic 行，配置页靠它回显 */
    private static function seedPluginOption(array $settings): void
    {
        try {
            if (class_exists('Widget\\Plugins\\Edit')
                && method_exists('Widget\\Plugins\\Edit', 'configPlugin')) {
                \Widget\Plugins\Edit::configPlugin('GhPic', $settings);
            }
        } catch (\Throwable $e) {
        }
    }

    public static function deactivate()
    {
        Helper::removeRoute('ghpic-img');

        return _t('GhPic 已禁用，图片与文章不受影响。');
    }

    /** 后台左侧菜单入口 */
    public static function navBar()
    {
        echo '<li><a href="' . Helper::options()->adminUrl . 'extending.php?panel=GhPic/panel.php">'
            . _t('图床迁移') . '</a></li>';
    }

    /**
     * 前台页脚注入：CDN 图片加载失败 / 超时自动回源到本站代理
     */
    public static function footer()
    {
        $opt = self::settings();
        if (null === $opt || !self::ready($opt)) {
            return;
        }
        if ('0' === (string) ($opt->proxy ?? '1')) {
            return;
        }

        $cdn  = self::cdnBase($opt);
        if ('' === $cdn) {
            return;
        }

        $wait = max(300, (int) ($opt->proxy_timeout ?? 1500));

        $base = rtrim(Helper::options()->siteUrl, '/') . '/';
        if (empty(Helper::options()->rewrite)) {
            $base .= 'index.php/';
        }

        $cfg = json_encode([
            'cdn'   => $cdn,
            'proxy' => $base . 'ghpicimg?p=',
            'wait'  => $wait,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        echo <<<HTML

<script>
(function () {
    var G = {$cfg};
    function fix(img) {
        if (!img || img.getAttribute('data-ghpic')) { return; }
        var src = img.getAttribute('src') || '';
        if (src.indexOf(G.cdn) !== 0) { return; }
        img.setAttribute('data-ghpic', '1');

        var done = false;
        function fallback() {
            if (done) { return; }
            done = true;
            var rel = src.slice(G.cdn.length + 1);
            img.setAttribute('src', G.proxy + encodeURIComponent(rel));
        }
        img.addEventListener('error', fallback);
        if (img.complete && img.naturalWidth === 0) { fallback(); return; }
        setTimeout(function () {
            if (!img.complete || img.naturalWidth === 0) { fallback(); }
        }, G.wait);
    }
    function scan(root) {
        var list = (root || document).querySelectorAll('img:not([data-ghpic])');
        for (var i = 0; i < list.length; i++) { fix(list[i]); }
    }
    function boot() {
        scan(document);
        if (!window.MutationObserver) { return; }
        new MutationObserver(function (ms) {
            for (var i = 0; i < ms.length; i++) {
                var nodes = ms[i].addedNodes;
                for (var j = 0; j < nodes.length; j++) {
                    var n = nodes[j];
                    if (n.nodeType !== 1) { continue; }
                    if (n.tagName === 'IMG') { fix(n); } else if (n.querySelectorAll) { scan(n); }
                }
            }
        }).observe(document.body, { childList: true, subtree: true });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else { boot(); }
})();
</script>

HTML;
    }

    /* ================================================================== */
    /*  配置                                                               */
    /* ================================================================== */

    public static function config(Form $form)
    {
        $groups = self::fieldGroups();
        $tab    = (int) ($_GET['tab'] ?? 0);
        $tab    = ($tab < 0 || $tab >= count($groups)) ? 0 : $tab;
        $saved  = self::backup();

        echo self::tabBar($tab);

        foreach ($groups as $index => $group) {
            if ($index !== $tab) {
                /* 非当前分组用隐藏域保底，保证保存时不会丢字段 */
                foreach ($group['fields'] as $name => $def) {
                    $hidden = new Hidden($name, null, $saved[$name] ?? ($def['default'] ?? ''));
                    $hidden->setAttribute('style', 'display:none');
                    $form->addInput($hidden);
                }
                continue;
            }

            foreach ($group['fields'] as $name => $def) {
                if (isset($saved[$name])) {
                    $def['default'] = $saved[$name];
                }
                $form->addInput(self::buildField($name, $def));
            }
        }
    }

    /** 19 个字段的分组定义 */
    private static function fieldGroups(): array
    {
        $ghNew   = '<a href="https://github.com/new" target="_blank" rel="noopener">github.com/new</a>';
        $ghToken = '<a href="https://github.com/settings/tokens/new?scopes=repo" target="_blank" rel="noopener">Token 生成页</a>';
        $ghRepo  = '<a href="https://github.com/settings/tokens" target="_blank" rel="noopener">Token 管理页</a>';

        return [
            [
                'title' => '连接仓库',
                'fields' => [
                    'repo_url' => [
                        'type' => 'textarea', 'label' => '仓库地址', 'default' => '',
                        'desc' => '① 打开 ' . $ghNew . ' 新建仓库，必须选 Public；'
                            . '② 复制仓库地址粘到这里，如 <code>用户名/image-repo</code>',
                    ],
                    'token' => [
                        'type' => 'textarea', 'label' => 'Access Token', 'default' => '',
                        'desc' => '① 打开 ' . $ghToken . '，repo 权限已预勾；'
                            . '② 底部点 Generate token；③ 复制 <code>ghp_</code> 开头的串粘到这里（只显示一次）。'
                            . '换 Token 在 ' . $ghRepo,
                    ],
                    'branch' => [
                        'type' => 'text', 'label' => '分支', 'default' => 'main',
                        'desc' => '仓库的默认分支。建仓库时勾了 README 一般是 <code>main</code>，空仓库可能是 <code>master</code>',
                    ],
                ],
            ],
            [
                'title' => '存储与 CDN',
                'fields' => [
                    'dir' => [
                        'type' => 'text', 'label' => '仓库内目录', 'default' => 'uploads/{year}/{month}',
                        'desc' => '图片在仓库里存哪个目录。<code>{year}</code> <code>{month}</code> 会替换成 <code>2026/11</code>；留空放仓库根目录',
                    ],
                    'cdn_mode' => [
                        'type' => 'radio', 'label' => 'CDN 方式', 'default' => 'jsdelivr',
                        'options' => ['jsdelivr' => 'jsDelivr（零配置，推荐）', 'custom' => '自定义前缀（Cloudflare Worker 等）'],
                        'desc' => 'jsDelivr 不需要任何配置，启用即可用；有自己的域名才需要选右边',
                    ],
                    'cdn_prefix' => [
                        'type' => 'text', 'label' => '自定义 CDN 前缀', 'default' => '',
                        'desc' => '如 <code>https://img.mdog.cn</code>，需先在 Cloudflare 建好 Worker 并绑定这个域名',
                    ],
                    'keep_local' => [
                        'type' => 'radio', 'label' => '本地副本', 'default' => '0',
                        'options' => ['0' => '不保留', '1' => '同时存一份到本地'],
                        'desc' => '在本机 <code>usr/uploads</code> 再留一份。主机空间充足就开，跑 CDN 兜底时也更稳',
                    ],
                ],
            ],
            [
                'title' => '图片压缩',
                'fields' => [
                    'optimize' => [
                        'type' => 'radio', 'label' => '图片处理', 'default' => 'auto',
                        'options' => ['auto' => '自动（有 WebP 就转，没有则同格式压缩）', 'on' => '强制转 WebP', 'off' => '不处理，原图直传'],
                        'desc' => '自动档最省心：能转 WebP 就转，不能就同格式压缩，都不行就原图上传，不会失败',
                    ],
                    'max_width' => [
                        'type' => 'text', 'label' => '最大宽度（px）', 'default' => '1600',
                        'desc' => '超过这个宽度会等比缩小，<code>0</code> 表示不缩放。正文配图 1600 足够',
                    ],
                    'quality' => [
                        'type' => 'text', 'label' => '压缩质量（1-100）', 'default' => '82',
                        'desc' => '82 肉眼基本无损；文字多的截图建议 <code>88</code>，照片可以 <code>78</code>',
                    ],
                    'max_size' => [
                        'type' => 'text', 'label' => '单文件上限（MB）', 'default' => '10',
                        'desc' => '超过就走本地存储、不传云端，避免上传卡死。GitHub 单文件上限是 100MB',
                    ],
                    'rename' => [
                        'type' => 'radio', 'label' => '上传自动重命名', 'default' => '1',
                        'options' => ['1' => '开启', '0' => '关闭'],
                        'desc' => '关闭后保留原始文件名，但仍会加 4 位哈希，防止同名文件互相覆盖',
                    ],
                    'name_rule' => [
                        'type' => 'radio', 'label' => '命名规则', 'default' => 'date-md5',
                        'options' => ['date-md5' => '日期-内容哈希（推荐）', 'timestamp' => '时间戳-哈希', 'original' => '原文件名-哈希'],
                        'desc' => '效果：<code>20261106-1a2b3c4d.webp</code> / <code>1699234567-1a2b3c4d.webp</code> / <code>photo-1a2b.webp</code>',
                    ],
                    'name_prefix' => [
                        'type' => 'text', 'label' => '文件名前缀', 'default' => '',
                        'desc' => '统一加个前缀方便在仓库里区分，如 <code>blog-</code>',
                    ],
                ],
            ],
            [
                'title' => '文章显示',
                'fields' => [
                    'rewrite' => [
                        'type' => 'radio', 'label' => '改写正文图片地址', 'default' => '0',
                        'options' => ['0' => '关闭（推荐）', '1' => '开启'],
                        'desc' => '输出时把本地图片地址换成 CDN。仅当仓库路径与本地完全一致时才开；'
                            . '老图请用左侧「图床管理」批量迁移',
                    ],
                    'local_base' => [
                        'type' => 'text', 'label' => '本地图片前缀', 'default' => '',
                        'desc' => '留空自动取「站点地址 + <code>/usr/uploads</code>」。只有换过域名或目录才需要手填',
                    ],
                ],
            ],
            [
                'title' => '故障兜底',
                'fields' => [
                    'proxy' => [
                        'type' => 'radio', 'label' => 'CDN 失败自动回源', 'default' => '1',
                        'options' => ['1' => '开启', '0' => '关闭'],
                        'desc' => 'CDN 超时或加载失败时，前端自动改用本站代理 <code>/ghpicimg</code> 回源取图',
                    ],
                    'proxy_timeout' => [
                        'type' => 'text', 'label' => '超时阈值（毫秒）', 'default' => '1500',
                        'desc' => '超过这个时间还没加载出来就切换。网络慢可调到 <code>2500</code>，最小 300',
                    ],
                    'cache_ttl' => [
                        'type' => 'text', 'label' => '兜底缓存天数', 'default' => '7',
                        'desc' => '代理取回的图片在本地缓存几天，<code>0</code> 表示不缓存',
                    ],
                ],
            ],
        ];
    }

    /** 分段按钮（链接形式，无 JS） */
    private static function tabBar(int $active): string
    {
        $base = Helper::options()->adminUrl . 'options-plugin.php?config=GhPic';
        $html = '<div class="ghpic-tabs" style="display:inline-flex;border:1px solid #d8dde3;'
            . 'border-radius:4px;overflow:hidden;margin:4px 0 22px;background:#f2f4f6">';

        foreach (self::fieldGroups() as $i => $group) {
            $on = ($i === $active);
            $html .= '<a href="' . $base . '&tab=' . $i . '" style="padding:8px 20px;'
                . 'border-right:1px solid #d8dde3;text-decoration:none;font-size:14px;'
                . 'background:' . ($on ? '#fff' : 'transparent') . ';'
                . 'color:' . ($on ? '#24292f' : '#57606a') . ';'
                . 'font-weight:' . ($on ? '600' : 'normal') . ';'
                . ($i === 4 ? 'border-right:0;' : '')
                . '">' . $group['title'] . '</a>';
        }

        $html .= '</div>';

        return $html;
    }

    /** 按定义生成表单元素 */
    private static function buildField(string $name, array $def)
    {
        $label = _t($def['label'] ?? $name);
        $desc  = $def['desc'] ?? '';
        $desc  = '' !== $desc ? _t($desc) : null;

        switch ($def['type'] ?? 'text') {
            case 'textarea':
                return new Textarea($name, null, $def['default'] ?? '', $label, $desc);
            case 'radio':
                return new Radio($name, $def['options'] ?? [], $def['default'] ?? '', $label, $desc);
            default:
                return new Text($name, null, $def['default'] ?? '', $label, $desc);
        }
    }

    public static function personalConfig(Form $form)
    {
    }

    /* ================================================================== */
    /*  上传                                                               */
    /* ================================================================== */

    public static function uploadHandle(array $file)
    {
        if (empty($file['tmp_name']) || !is_file($file['tmp_name'])) {
            return false;
        }

        $opt = self::settings();
        if (null === $opt || !self::ready($opt)) {
            return false;
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::$allow, true)) {
            return false;
        }

        $maxMb = max(1, (int) ($opt->max_size ?? 10));
        if ((int) $file['size'] > $maxMb * 1024 * 1024) {
            return false; // 超限回退本地存储，保证文件不丢
        }

        $data = @file_get_contents($file['tmp_name']);
        if (false === $data || '' === $data) {
            return false;
        }

        [$data, $ext] = self::optimize($data, $ext, $opt);

        $res = self::pushUnique($opt, $data, $ext, (string) $file['name'], 'upload');
        if (!$res['ok']) {
            return false; // 推送失败回退本地存储
        }
        $remote = $res['remote'];

        if ('1' === (string) ($opt->keep_local ?? '0')) {
            $local = __TYPECHO_ROOT_DIR__ . '/usr/uploads/' . $remote;
            @mkdir(dirname($local), 0755, true);
            @file_put_contents($local, $data);
        }

        return [
            'name' => basename($remote),
            'path' => self::cdnBase($opt) . '/' . $remote,
            'size' => strlen($data),
            'type' => 'image',
            'mime' => self::mime($ext, $file['type'] ?? ''),
        ];
    }

    /* ================================================================== */
    /*  附件地址（修复 Typecho 1.3 会强行拼接 siteUrl 的问题）                 */
    /* ================================================================== */

    public static function attachmentHandle($attachment): string
    {
        $path = (string) ($attachment->path ?? '');

        if (preg_match('~^(https?:)?//~i', $path)) {
            return $path;
        }

        return \Typecho\Common::url($path, Helper::options()->siteUrl);
    }

    public static function attachmentDataHandle(array $content): string
    {
        $path = (string) ($content['attachment']->path ?? '');

        if (preg_match('~^https?://~i', $path)) {
            $data = self::fetchBinary($path, 10);
            return null === $data ? '' : $data;
        }

        return (string) @file_get_contents(\Typecho\Common::url($path, __TYPECHO_ROOT_DIR__));
    }

    /** 拉取远端二进制 */
    public static function fetchBinary(string $url, int $timeout)
    {
        if (!function_exists('curl_init')) {
            $ctx = stream_context_create(['http' => ['timeout' => $timeout]]);
            $body = @file_get_contents($url, false, $ctx);

            return (false === $body || '' === $body) ? null : $body;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_USERAGENT      => 'GhPic/' . self::VERSION,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return (200 === $code && is_string($body) && '' !== $body) ? $body : null;
    }

    /* ================================================================== */
    /*  正文改写                                                           */
    /* ================================================================== */

    public static function contentEx($content, $widget = null)
    {
        return self::rewrite($content);
    }

    public static function excerptEx($content, $widget = null)
    {
        return self::rewrite($content);
    }

    private static function rewrite($content)
    {
        if (!is_string($content) || '' === $content) {
            return $content;
        }

        $opt = self::settings();
        if (null === $opt || !self::ready($opt)) {
            return $content;
        }

        /* 修复历史遗留的坏地址：https://site/https:/cdn... → https://cdn... */
        $content = self::repairUrl($content);

        if ('0' === (string) ($opt->rewrite ?? '1')) {
            return $content;
        }

        $cdn = self::cdnBase($opt);
        if ('' === $cdn) {
            return $content;
        }

        $local = self::localBase($opt);
        if (false === strpos($content, $local)) {
            return $content;
        }

        return str_replace(self::urlVariants($local), $cdn, $content);
    }

    /** 修复被 Common::url 二次拼接的图片地址 */
    public static function repairUrl(string $content): string
    {
        $site = rtrim(Helper::options()->siteUrl, '/');
        if ('' === $site) {
            return $content;
        }

        $pattern = '~' . preg_quote($site, '~') . '/(https?):/~i';

        return preg_replace($pattern, '$1://', $content);
    }

    /* ================================================================== */
    /*  公共工具                                                           */
    /* ================================================================== */

    public static function ready($opt)
    {
        if (empty($opt->token)) {
            return false;
        }
        [$owner, $repo] = self::repoOf($opt);

        return '' !== $owner && '' !== $repo;
    }

    /** 解析仓库地址，返回 [owner, repo] */
    public static function repoOf($opt)
    {
        $raw = trim((string) ($opt->repo_url ?? ''));

        if (preg_match('~github\.com[/:]([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)~i', $raw, $m)) {
            return [$m[1], preg_replace('~\.git$~i', '', $m[2])];
        }
        if (preg_match('~^([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)$~', $raw, $m)) {
            return [$m[1], preg_replace('~\.git$~i', '', $m[2])];
        }

        return ['', ''];
    }

    /** 读取全部标签，返回 [path => 'a,b'] */
    public static function allTags(): array
    {
        $map = [];
        try {
            $rows = \Typecho\Db::get()->fetchAll(
                \Typecho\Db::get()->select('path', 'tags')->from('table.ghpic_tags')
            );
            foreach ($rows as $row) {
                if ('' !== trim((string) $row['tags'])) {
                    $map[$row['path']] = (string) $row['tags'];
                }
            }
        } catch (\Throwable $e) {
        }

        return $map;
    }

    /** 覆盖写入某张图片的标签 */
    public static function saveTags(string $path, string $tags): bool
    {
        $db  = \Typecho\Db::get();
        $now = time();

        try {
            $exists = $db->fetchRow($db->select('path')->from('table.ghpic_tags')->where('path = ?', $path));
            if ($exists) {
                $db->query($db->update('table.ghpic_tags')
                    ->rows(['tags' => $tags, 'updated' => $now])
                    ->where('path = ?', $path));
            } else {
                $db->query($db->insert('table.ghpic_tags')
                    ->rows(['path' => $path, 'tags' => $tags, 'updated' => $now]));
            }

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** 规范化标签串：去空、去重、限长 */
    public static function normalizeTags(string $raw): string
    {
        $parts = preg_split('~[,，\s]+~u', $raw);
        $out   = [];
        foreach ((array) $parts as $t) {
            $t = trim(preg_replace('~[^\p{L}\p{N}_-]~u', '', (string) $t));
            if ('' !== $t && !in_array($t, $out, true)) {
                $out[] = $t;
            }
            if (count($out) >= 8) {
                break;
            }
        }

        return implode(',', $out);
    }

    /** 代理路径白名单：只允许仓库内的图片相对路径 */
    public static function validPath(string $path): bool
    {
        if ('' === $path || strlen($path) > 300) {
            return false;
        }
        if (false !== strpos($path, '..')) {
            return false;
        }

        return (bool) preg_match('~^[A-Za-z0-9_\-./]+\.(?:jpg|jpeg|png|gif|webp|bmp|svg|ico)$~i', $path);
    }

    public static function cdnBase($opt)
    {
        if ('custom' === ($opt->cdn_mode ?? 'jsdelivr')) {
            return rtrim(trim((string) ($opt->cdn_prefix ?? '')), '/');
        }

        [$owner, $repo] = self::repoOf($opt);
        $branch = trim((string) ($opt->branch ?? 'main'));

        return 'https://cdn.jsdelivr.net/gh/' . $owner . '/' . $repo . '@' . ($branch ?: 'main');
    }

    public static function localBase($opt)
    {
        $local = trim((string) ($opt->local_base ?? ''));
        if ('' === $local) {
            $local = rtrim(Helper::options()->siteUrl, '/') . '/usr/uploads';
        }

        return rtrim($local, '/');
    }

    /** 同一地址的三种写法，兼容 http / https / 协议相对 */
    public static function urlVariants($url)
    {
        return array_unique([
            $url,
            preg_replace('~^https?://~i', '//', $url),
            str_replace('https://', 'http://', $url),
        ]);
    }

    public static function remotePath($opt, $data, $ext, string $origName = ''): string
    {
        $dir = str_replace(
            ['{year}', '{month}'],
            [date('Y'), date('m')],
            (string) ($opt->dir ?? '')
        );
        $dir = trim(preg_replace('~/+~', '/', $dir), '/');

        $prefix = preg_replace('~[^A-Za-z0-9._-]~', '', (string) ($opt->name_prefix ?? ''));
        $hash   = substr(md5($data), 0, 8);
        $rename = '0' !== (string) ($opt->rename ?? '1');
        $rule   = $rename ? (string) ($opt->name_rule ?? 'date-md5') : 'original';

        if ('timestamp' === $rule) {
            $base = time() . '-' . $hash;
        } elseif ('original' === $rule) {
            $base = preg_replace('~[^A-Za-z0-9_-]~', '', pathinfo($origName, PATHINFO_FILENAME));
            $base = substr($base !== '' ? $base : 'image', 0, 60) . '-' . substr($hash, 0, 4);
        } else {
            $base = date('Ymd') . '-' . $hash;
        }

        return ($dir !== '' ? $dir . '/' : '') . $prefix . $base . '.' . $ext;
    }

    public static function mime($ext, $fallback = '')
    {
        $map = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
            'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
        ];

        return $map[$ext] ?? ($fallback ?: 'application/octet-stream');
    }

    /* ================================================================== */
    /*  图片处理：WebP 优先，逐级降级                                        */
    /* ================================================================== */

    public static function webpReady(): bool
    {
        if (!function_exists('imagewebp')) {
            return false;
        }
        if (function_exists('imagetypes') && !(imagetypes() & IMG_WEBP)) {
            return false;
        }

        return true;
    }

    /**
     * @return array [二进制内容, 扩展名]
     */
    public static function optimize(string $data, string $ext, $opt): array
    {
        if ('off' === ($opt->optimize ?? 'auto')) {
            return [$data, $ext];
        }

        /* 动图、矢量、图标一律不动 */
        if (in_array($ext, ['gif', 'svg', 'ico'], true)) {
            return [$data, $ext];
        }
        if (!function_exists('imagecreatefromstring')) {
            return [$data, $ext];
        }

        $img = @imagecreatefromstring($data);
        if (!$img) {
            return [$data, $ext];
        }

        if (function_exists('imagepalettetotruecolor')) {
            @imagepalettetotruecolor($img);
        }
        @imagealphablending($img, true);
        @imagesavealpha($img, true);

        $maxW = (int) ($opt->max_width ?? 1600);
        $w    = imagesx($img);
        $h    = imagesy($img);

        if ($maxW > 0 && $w > $maxW) {
            $nh  = max(1, (int) round($h * $maxW / $w));
            $dst = imagecreatetruecolor($maxW, $nh);
            @imagealphablending($dst, false);
            @imagesavealpha($dst, true);
            imagecopyresampled($dst, $img, 0, 0, 0, 0, $maxW, $nh, $w, $h);
            imagedestroy($img);
            $img = $dst;
        }

        $q   = min(100, max(1, (int) ($opt->quality ?? 82)));
        $new = $ext;

        ob_start();
        if (self::webpReady()) {
            $ok  = @imagewebp($img, null, $q);
            $new = 'webp';
        } elseif (in_array($ext, ['jpg', 'jpeg'], true)) {
            $ok  = @imagejpeg($img, null, $q);
            $new = 'jpg';
        } elseif ('png' === $ext) {
            $ok  = @imagepng($img, null, 8);
            $new = 'png';
        } else {
            $ok = false;
        }
        $out = ob_get_clean();
        imagedestroy($img);

        if (!$ok || '' === $out || strlen($out) >= strlen($data)) {
            return [$data, $ext];
        }

        return [$out, $new];
    }

    /* ================================================================== */
    /*  GitHub API                                                         */
    /* ================================================================== */

    /**
     * @return array ['ok' => bool, 'code' => int, 'data' => array|null]
     */
    public static function push($opt, string $remote, string $data, string $message): array
    {
        [$owner, $repo] = self::repoOf($opt);
        if ('' === $owner || '' === $repo) {
            return ['ok' => false, 'code' => 0, 'data' => null];
        }

        $path = implode('/', array_map('rawurlencode', explode('/', $remote)));
        $api  = self::$api . '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/contents/' . $path;

        $payload = json_encode([
            'message' => $message,
            'content' => base64_encode($data),
            'branch'  => trim((string) ($opt->branch ?? 'main')) ?: 'main',
        ], JSON_UNESCAPED_SLASHES);

        $res = self::request('PUT', $api, $payload, $opt);
        if ($res['ok']) {
            return $res;
        }

        /* GitHub 对已存在的文件返回 422，需要带 sha 覆盖。
           若已有文件内容与本次完全一致（git blob sha 相同），直接视为成功复用。 */
        if (422 === (int) $res['code']) {
            $get = self::request(
                'GET',
                $api . '?ref=' . rawurlencode(trim((string) ($opt->branch ?? 'main')) ?: 'main'),
                null,
                $opt
            );

            if ($get['ok'] && isset($get['data']['sha']) && $get['data']['sha'] === self::blobSha($data)) {
                return ['ok' => true, 'code' => 200, 'data' => $get['data'], 'reused' => true];
            }
        }

        return $res;
    }

    /** git blob sha，用于判断远端同名文件内容是否与本地一致 */
    public static function blobSha(string $data): string
    {
        return sha1('blob ' . strlen($data) . "\0" . $data);
    }

    /**
     * 推送并按需换名重试
     *
     * @return array ['ok' => bool, 'code' => int, 'remote' => string]
     */
    public static function pushUnique($opt, string $data, string $ext, string $origName, string $label): array
    {
        $remote = self::remotePath($opt, $data, $ext, $origName);
        $res    = self::push($opt, $remote, $data, $label . ' ' . basename($remote));

        /* 命中 422 且内容不同 → 换一个名字重试一次 */
        if (!$res['ok'] && 422 === (int) $res['code']) {
            $remote = self::remotePath(
                $opt,
                $data . '|' . microtime(true) . '|' . mt_rand(),
                $ext,
                $origName
            );
            $res = self::push($opt, $remote, $data, $label . ' ' . basename($remote));
        }

        return ['ok' => $res['ok'], 'code' => (int) $res['code'], 'remote' => $remote];
    }

    public static function request(string $method, string $url, ?string $payload = null, $opt = null): array
    {
        $token = trim((string) ($opt->token ?? ''));
        $head  = [
            'Authorization: token ' . $token,
            'Accept: application/vnd.github+json',
            'Content-Type: application/json',
            'User-Agent: GhPic-Typecho/' . self::VERSION,
        ];

        $err = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_HTTPHEADER     => $head,
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = (string) curl_error($ch);
            curl_close($ch);
        } else {
            $ctx = stream_context_create(['http' => [
                'method'        => $method,
                'header'        => implode("\r\n", $head) . "\r\n",
                'content'       => $payload,
                'timeout'       => 60,
                'ignore_errors' => true,
            ]]);
            $body = @file_get_contents($url, false, $ctx);
            $code = 0;
            $err  = $body === false ? 'stream request failed' : '';
            if (isset($http_response_header) && preg_match('~HTTP/\S+\s+(\d{3})~', $http_response_header[0], $m)) {
                $code = (int) $m[1];
            }
        }

        $json = is_string($body) ? json_decode($body, true) : null;

        return [
            'ok'   => ($code >= 200 && $code < 300),
            'code' => $code,
            'data' => is_array($json) ? $json : null,
            'err'  => $err,
        ];
    }

    /** 连接自检，返回一句人话 */
    private static function selfCheck($opt): string
    {
        if (!self::ready($opt)) {
            return _t('仓库地址或 Token 为空，暂不校验。');
        }

        [$owner, $repo] = self::repoOf($opt);

        $me = self::request('GET', self::$api . '/user', null, $opt);
        if (!$me['ok']) {
            return ' ' . self::explain($me, _t('Token 校验失败'));
        }

        $rp = self::request(
            'GET',
            self::$api . '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo),
            null,
            $opt
        );
        if (!$rp['ok']) {
            return ' ' . self::explain($rp, _t('仓库读取失败'));
        }
        if (!empty($rp['data']['private'])) {
            return _t(' 警告：仓库是 Private，jsDelivr 无法读取，请改为 Public。');
        }

        $login = $me['data']['login'] ?? '?';
        $warn  = self::webpReady() ? _t(' WebP 可用。') : _t(' 环境无 WebP，已降级为同格式压缩。');

        return _t(' 已连接账号 ') . $login . '，仓库 ' . $owner . '/' . $repo . ' 正常。' . $warn;
    }

    /** 把 HTTP 结果翻译成人话 */
    private static function explain(array $res, string $prefix): string
    {
        $code = (int) $res['code'];

        if (0 === $code) {
            return $prefix . _t('：无法连接 api.github.com（网络不通或主机禁止外联）。')
                . ' ' . ($res['err'] ?? '');
        }
        if (401 === $code) {
            return $prefix . _t('：Token 无效或已过期（HTTP 401），请重新生成。');
        }
        if (403 === $code) {
            return $prefix . _t('：被 GitHub 拒绝（HTTP 403），可能是限流或 Token 权限不足。');
        }
        if (404 === $code) {
            return $prefix . _t('：仓库不存在或 Token 无权访问（HTTP 404）。');
        }

        return $prefix . _t('（HTTP ') . $code . '）。';
    }
}

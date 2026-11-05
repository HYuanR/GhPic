<?php
/**
 * GhPic — 图床管理面板（迁移 / 打标签 / 分页浏览）
 *
 * 通过 admin/extending.php?panel=GhPic/panel.php 加载，复用后台登录态。
 *
 * @package GhPic
 */

if (!defined('__TYPECHO_ADMIN__')) {
    exit('Access denied');
}

if (!\Widget\User::alloc()->pass('administrator', true)) {
    exit('Access denied');
}


$ghpicOpt = null;
try {
    $ghpicOpt = \Utils\Helper::options()->plugin('GhPic');
} catch (\Typecho\Plugin\Exception $e) {
}

$ghpicReady = $ghpicOpt ? \TypechoPlugin\GhPic\Plugin::ready($ghpicOpt) : false;
$ghpicToken = \Typecho\Common::hash('GhPic-panel');
$ghpicCdn   = $ghpicOpt ? \TypechoPlugin\GhPic\Plugin::cdnBase($ghpicOpt) : '';

$ghpicRoot = realpath(__TYPECHO_ROOT_DIR__ . '/usr/uploads');
$ghpicRoot = $ghpicRoot ? rtrim(str_replace('\\', '/', $ghpicRoot), '/') : '';

$perPage = 50;
$page    = max(1, (int) ($_GET['page'] ?? 1));
$tagSel  = trim((string) ($_GET['tag'] ?? ''));
$keyword = trim((string) ($_GET['q'] ?? ''));
$result  = [];

/* ------------------------------------------------------------------ */
/*  扫描                                                            */
/* ------------------------------------------------------------------ */
if (!function_exists('ghpic_scan_files')) {
    function ghpic_scan_files($dir, $root, &$out, $depth = 0)
    {
        if ($depth > 5 || !is_dir($dir)) {
            return;
        }
        $dh = @opendir($dir);
        if (!$dh) {
            return;
        }
        $allow = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'ico'];

        while (false !== ($f = readdir($dh))) {
            if ('.' === $f || '..' === $f) {
                continue;
            }
            $abs = str_replace('\\', '/', $dir . '/' . $f);
            if (is_dir($abs)) {
                if ('.ghpic-cache' === $f) {
                    continue;
                }
                ghpic_scan_files($abs, $root, $out, $depth + 1);
                continue;
            }
            if (!in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $allow, true)) {
                continue;
            }
            $out[] = [
                'rel'  => ltrim(substr($abs, strlen($root)), '/'),
                'size' => (int) @filesize($abs),
                'time' => (int) @filemtime($abs),
            ];
        }
        closedir($dh);
    }
}

$files = [];
if ($ghpicRoot && is_dir($ghpicRoot)) {
    ghpic_scan_files($ghpicRoot, $ghpicRoot, $files, 0);
}
usort($files, function ($a, $b) {
    return $b['time'] - $a['time'];
});

$tagMap = \TypechoPlugin\GhPic\Plugin::allTags();
$allTagSet = [];
foreach ($tagMap as $tags) {
    foreach (explode(',', $tags) as $t) {
        if ('' !== $t) {
            $allTagSet[$t] = true;
        }
    }
}
$allTags = array_keys($allTagSet);
sort($allTags);

/* ------------------------------------------------------------------ */
/*  提交处理                                                        */
/* ------------------------------------------------------------------ */
if ('POST' === strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET')) {
    $do = (string) ($_POST['do'] ?? '');

    if (!isset($_POST['_']) || !is_string($_POST['_']) || !hash_equals($ghpicToken, $_POST['_'])) {
        $result[] = ['err', '安全令牌校验失败，请刷新页面重试。'];
    } else {
        $picked = isset($_POST['files']) && is_array($_POST['files']) ? $_POST['files'] : [];
        $picked = array_values(array_filter($picked, 'is_string'));

        if (!$picked) {
            $result[] = ['err', '没有选中任何文件。'];
        } elseif ('tag' === $do || 'untag' === $do) {
            $tags = 'tag' === $do ? \TypechoPlugin\GhPic\Plugin::normalizeTags((string) ($_POST['tags'] ?? '')) : '';
            $n    = 0;
            foreach ($picked as $rel) {
                $rel = str_replace('\\', '/', ltrim($rel, '/'));
                if (!preg_match('~^[A-Za-z0-9_./-]+$~', $rel) || false !== strpos($rel, '..')) {
                    continue;
                }
                if (\TypechoPlugin\GhPic\Plugin::saveTags($rel, $tags)) {
                    $n++;
                }
            }
            $result[] = $n ? ['ok', "已为 {$n} 张图片" . ('tag' === $do ? "设置标签：{$tags}" : '清除标签')] : ['err', '标签写入失败，请检查数据库权限。'];
        } elseif ('migrate' !== $do) {
            $result[] = ['err', '未知操作。'];
        } elseif (!$ghpicReady) {
            $result[] = ['err', '插件尚未配置仓库或 Token，请先到插件设置完成配置。'];
        } elseif ($ghpicRoot) {
            $picked = array_slice($picked, 0, 15);
            $del    = isset($_POST['delete_local']) && '1' === $_POST['delete_local'];
            $db     = \Typecho\Db::get();
            $local  = \TypechoPlugin\GhPic\Plugin::localBase($ghpicOpt);

            foreach ($picked as $rel) {
                $rel = str_replace('\\', '/', ltrim((string) $rel, '/'));
                if (false !== strpos($rel, '..') || !preg_match('~^[A-Za-z0-9_./-]+$~', $rel)) {
                    $result[] = ['err', '非法路径：' . htmlspecialchars($rel, ENT_QUOTES, 'UTF-8')];
                    continue;
                }

                $abs  = realpath($ghpicRoot . '/' . $rel);
                $real = $abs ? str_replace('\\', '/', $abs) : '';
                if ('' === $real || 0 !== strpos($real, $ghpicRoot . '/')) {
                    $result[] = ['err', '路径越界，已跳过：' . htmlspecialchars($rel, ENT_QUOTES, 'UTF-8')];
                    continue;
                }

                $data = @file_get_contents($real);
                if (false === $data || '' === $data) {
                    $result[] = ['err', '读取失败：' . htmlspecialchars($rel, ENT_QUOTES, 'UTF-8')];
                    continue;
                }

                $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
                [$data, $ext] = \TypechoPlugin\GhPic\Plugin::optimize($data, $ext, $ghpicOpt);

                $res = \TypechoPlugin\GhPic\Plugin::pushUnique($ghpicOpt, $data, $ext, basename($real), 'migrate');
                if (!$res['ok']) {
                    $result[] = ['err', '推送失败（HTTP ' . (int) $res['code'] . '）：'
                        . htmlspecialchars($rel, ENT_QUOTES, 'UTF-8')];
                    continue;
                }
                $remote = $res['remote'];

                $oldUrls = \TypechoPlugin\GhPic\Plugin::urlVariants($local . '/' . $rel);
                $newUrl  = $ghpicCdn . '/' . $remote;
                $hit     = 0;

                $rows = $db->fetchAll(
                    $db->select('cid', 'text')->from('table.contents')->where('text LIKE ?', '%usr/uploads%')
                );
                foreach ($rows as $row) {
                    $text = str_replace($oldUrls, $newUrl, (string) $row['text']);
                    if ($text !== $row['text']) {
                        $db->query(
                            $db->update('table.contents')->rows(['text' => $text])->where('cid = ?', $row['cid'])
                        );
                        $hit++;
                    }
                }

                if ($del) {
                    @unlink($real);
                }

                $result[] = ['ok', htmlspecialchars($rel, ENT_QUOTES, 'UTF-8')
                    . ' → ' . htmlspecialchars($remote, ENT_QUOTES, 'UTF-8')
                    . "（更新 {$hit} 处引用）"];
            }
        }
    }

    /* 刷新标签缓存 */
    $tagMap = \TypechoPlugin\GhPic\Plugin::allTags();
}

/* ------------------------------------------------------------------ */
/*  过滤 + 分页                                                      */
/* ------------------------------------------------------------------ */
$filtered = [];
foreach ($files as $f) {
    if ('' !== $keyword && false === stripos($f['rel'], $keyword)) {
        continue;
    }
    if ('' !== $tagSel) {
        $tags = isset($tagMap[$f['rel']]) ? explode(',', $tagMap[$f['rel']]) : [];
        if ('__none__' === $tagSel) {
            if ($tags && '' !== trim(implode('', $tags))) {
                continue;
            }
        } elseif (!in_array($tagSel, $tags, true)) {
            continue;
        }
    }
    $filtered[] = $f;
}

$total  = count($filtered);
$pages  = max(1, (int) ceil($total / $perPage));
$page   = min($page, $pages);
$offset = ($page - 1) * $perPage;
$slice  = array_slice($filtered, $offset, $perPage);

$bytesAll = 0;
foreach ($files as $f) {
    $bytesAll += $f['size'];
}

function ghpic_url(array $override = []): string
{
    $qs = array_merge([
        'panel' => 'GhPic/panel.php',
        'page'  => $_GET['page'] ?? 1,
        'tag'   => $_GET['tag'] ?? '',
        'q'     => $_GET['q'] ?? '',
    ], $override);

    return 'extending.php?' . http_build_query(array_filter($qs, function ($v) {
        return '' !== $v && null !== $v;
    }));
}
?>

<div class="typecho-page-main" role="main">
    <div class="typecho-page-title">
        <h2>图床管理</h2>
    </div>

    <?php if (!$ghpicReady): ?>
        <div class="message notice" style="margin:10px 0;padding:10px 12px;background:#fff8e6;border-left:3px solid #f0a020;">
            插件尚未配置。<a href="<?php echo \Utils\Helper::options()->adminUrl; ?>options-plugin.php?config=GhPic">前往设置</a>
        </div>
    <?php endif; ?>

    <?php if ($result): ?>
        <ul style="margin:10px 0;padding:10px 12px;background:#f6f8fa;border-radius:4px;list-style:none;font-size:13px;">
            <?php foreach ($result as $r): ?>
                <li style="color:<?php echo 'ok' === $r[0] ? '#1a7f37' : '#cf222e'; ?>;line-height:1.9;">
                    <?php echo ('ok' === $r[0] ? '✔ ' : '✘ ') . $r[1]; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <p style="margin:12px 0;color:#57606a;font-size:13px;">
        本地共 <b><?php echo count($files); ?></b> 张，合计 <b><?php echo number_format($bytesAll / 1048576, 2); ?> MB</b>；
        当前筛选 <b><?php echo $total; ?></b> 张。
        <?php echo \TypechoPlugin\GhPic\Plugin::webpReady() ? '环境支持 WebP。' : '环境不支持 WebP，按原格式压缩。'; ?>
    </p>

    <form method="get" action="extending.php" style="margin:10px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <input type="hidden" name="panel" value="GhPic/panel.php">
        <input type="text" name="q" value="<?php echo htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8'); ?>"
               placeholder="搜索文件名" style="padding:4px 8px;">
        <select name="tag" style="padding:4px 8px;">
            <option value="">全部标签</option>
            <option value="__none__" <?php echo '__none__' === $tagSel ? 'selected' : ''; ?>>未打标签</option>
            <?php foreach ($allTags as $t): ?>
                <option value="<?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?>"
                    <?php echo $t === $tagSel ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn">筛选</button>
        <a href="extending.php?panel=GhPic/panel.php" class="btn">重置</a>
    </form>

    <form method="post" action="">
        <input type="hidden" name="_" value="<?php echo htmlspecialchars($ghpicToken, ENT_QUOTES, 'UTF-8'); ?>">

        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:10px 0;font-size:13px;">
            <input type="text" name="tags" placeholder="标签，逗号分隔，如 教程,截图"
                   style="padding:4px 8px;width:260px;">
            <button type="submit" name="do" value="tag" class="btn">给选中项打标签</button>
            <button type="submit" name="do" value="untag" class="btn">清除选中项标签</button>
            <label style="margin-left:auto;">
                <input type="checkbox" name="delete_local" value="1"> 迁移后删除本地文件
            </label>
        </div>

        <table style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead>
            <tr style="background:#f6f8fa;text-align:left;">
                <th style="padding:6px;width:34px;"><input type="checkbox" id="ghpic-all"></th>
                <th style="padding:6px;">文件</th>
                <th style="padding:6px;width:80px;">大小</th>
                <th style="padding:6px;width:130px;">时间</th>
                <th style="padding:6px;width:180px;">标签</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$total): ?>
                <tr><td colspan="5" style="padding:14px;color:#8b949e;">没有匹配的图片。</td></tr>
            <?php endif; ?>
            <?php foreach ($slice as $f): ?>
                <tr style="border-top:1px solid #eaeef2;">
                    <td style="padding:6px;">
                        <input type="checkbox" name="files[]" value="<?php echo htmlspecialchars($f['rel'], ENT_QUOTES, 'UTF-8'); ?>">
                    </td>
                    <td style="padding:6px;word-break:break-all;"><?php echo htmlspecialchars($f['rel'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td style="padding:6px;"><?php echo number_format($f['size'] / 1024, 1); ?> KB</td>
                    <td style="padding:6px;"><?php echo date('Y-m-d H:i', $f['time']); ?></td>
                    <td style="padding:6px;color:#57606a;">
                        <?php
                        $rowTags = isset($tagMap[$f['rel']]) ? explode(',', $tagMap[$f['rel']]) : [];
                        foreach ($rowTags as $t) {
                            if ('' === $t) {
                                continue;
                            }
                            echo '<a href="' . htmlspecialchars(ghpic_url(['tag' => $t, 'page' => 1]), ENT_QUOTES, 'UTF-8') . '"'
                                . ' style="display:inline-block;padding:1px 6px;margin:1px;background:#eef2f6;'
                                . 'border-radius:3px;text-decoration:none;color:#24292f;">'
                                . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '</a>';
                        }
                        ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <p style="margin:14px 0;">
            <button type="submit" name="do" value="migrate" class="btn primary">迁移选中项（单次 15 张）</button>
        </p>
    </form>

    <?php if ($pages > 1): ?>
        <div style="margin:16px 0;display:flex;gap:6px;align-items:center;font-size:13px;">
            <?php if ($page > 1): ?>
                <a class="btn" href="<?php echo htmlspecialchars(ghpic_url(['page' => $page - 1]), ENT_QUOTES, 'UTF-8'); ?>">上一页</a>
            <?php endif; ?>
            <span style="color:#57606a;">第 <?php echo $page; ?> / <?php echo $pages; ?> 页</span>
            <?php if ($page < $pages): ?>
                <a class="btn" href="<?php echo htmlspecialchars(ghpic_url(['page' => $page + 1]), ENT_QUOTES, 'UTF-8'); ?>">下一页</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script>
(function () {
    var all = document.getElementById('ghpic-all');
    if (!all) { return; }
    all.addEventListener('change', function () {
        var boxes = document.querySelectorAll('input[name="files[]"]');
        for (var i = 0; i < boxes.length; i++) { boxes[i].checked = all.checked; }
    });
})();
</script>

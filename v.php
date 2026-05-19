<?php
/**
 * CmdCode Source Viewer — 纯源代码阅读器
 * 
 * 用法：v.php?f=proxy.php
 * 安全：只允许读取 /www/source/ 目录下的预定义白名单文件
 * 不会执行 PHP/HTML，直接显示原始源代码
 */

// ── 白名单（只允许列出这些文件） ──
$WHITELIST = [
    'ui.html',
    'proxy.php',
    'config.enc.php',
    'htaccess-example',
    'long-task-cron-worker.sh',
    'cron.d-long-task-worker',
    'long-task-worker-check.sh',
    'v.php',
];

$file = $_GET['f'] ?? '';
$file = basename($file); // 防路径穿越（第一层防御）

if (!in_array($file, $WHITELIST)) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>文件不存在</title>';
    echo '<style>body{font-family:sans-serif;background:#0d1117;color:#c9d1d9;padding:40px;text-align:center}h1{color:#ff6b6b}a{color:#58a6ff}</style>';
    echo '</head><body><h1>❌ 文件不存在或不在白名单中</h1>';
    echo '<p>允许的文件: ' . implode(', ', $WHITELIST) . '</p>';
    echo '<p><a href="index.html">← 返回首页</a></p>';
    echo '</body></html>';
    exit;
}

// ── 读取原始源代码（不执行！） ──
// 第二层防御：realpath() 确保解析后的路径在 __DIR__ 内
$path = __DIR__ . '/' . $file;
$realPath = realpath($path);
$baseDir = realpath(__DIR__);
if ($realPath === false || strncmp($realPath, $baseDir, strlen($baseDir)) !== 0) {
    http_response_code(403);
    echo '文件路径无效';
    exit;
}
$source = file_get_contents($realPath);
if ($source === false) {
    http_response_code(500);
    echo '读取文件失败';
    exit;
}

// 获取文件信息
$lines = substr_count($source, "\n") + 1;
$bytes = strlen($source);
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

// 语言标签
$langMap = [
    'html' => 'HTML',
    'php'  => 'PHP',
    'sh'   => 'Bash',
    'conf' => 'Config',
];
$lang = $langMap[$ext] ?? 'Text';

// ── 输出页面 ──
header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>源代码: <?= htmlspecialchars($file) ?> — CmdCode 开源</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#0d1117;color:#c9d1d9;line-height:1.6}
.header{background:#161b22;border-bottom:1px solid #30363d;padding:14px 20px;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.header h1{font-size:1.1em;color:#f0f6fc}
.header .meta{color:#8b949e;font-size:0.82em}
.header .lang{display:inline-block;padding:1px 8px;border-radius:6px;font-size:0.75em;font-weight:600}
.lang-html{background:#1f2937;color:#58a6ff;border:1px solid #30363d}
.lang-php{background:#1c2128;color:#7ee787;border:1px solid #30363d}
.lang-bash{background:#1c2128;color:#f5a623;border:1px solid #30363d}
.lang-config{background:#1c2128;color:#ff6b6b;border:1px solid #30363d}
.header a{color:#58a6ff;text-decoration:none;font-size:0.85em}
.header a:hover{text-decoration:underline}
.code-wrap{display:flex;min-height:calc(100vh - 52px)}
.line-nums{padding:14px 0;background:#0d1117;border-right:1px solid #21262d;min-width:52px;text-align:right;user-select:none;flex-shrink:0}
.line-nums span{display:block;padding:0 12px;font-size:0.78em;line-height:1.55;color:#484f58;font-family:"SF Mono","Fira Code",Consolas,monospace}
.code-body{flex:1;overflow-x:auto;padding:14px 0}
.code-body pre{margin:0;padding:0 20px;font-size:0.78em;line-height:1.55;font-family:"SF Mono","Fira Code",Consolas,monospace;white-space:pre;tab-size:4;color:#c9d1d9}
.code-body pre .kw{color:#ff7b72}
.code-body pre .fn{color:#d2a8ff}
.code-body pre .str{color:#a5d6ff}
.code-body pre .cm{color:#8b949e;font-style:italic}
.code-body pre .num{color:#79c0ff}
.code-body pre .tag{color:#7ee787}
.code-body pre .attr{color:#79c0ff}
.code-body pre .val{color:#a5d6ff}
::-webkit-scrollbar{width:6px;height:6px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:#30363d;border-radius:3px}
@media(max-width:600px){
  .header{padding:10px 14px;flex-direction:column;align-items:flex-start;gap:6px}
  .code-wrap{flex-direction:column}
  .line-nums{display:none}
  .code-body pre{padding:0 14px;font-size:0.72em}
}
</style>
</head>
<body>
<div class="header">
  <h1>📄 <?= htmlspecialchars($file) ?></h1>
  <span class="lang lang-<?= $ext ?>"><?= $lang ?></span>
  <span class="meta"><?= $lines ?> 行 · <?= number_format($bytes) ?> bytes</span>
  <a href="index.html">← 返回首页</a>
</div>
<div class="code-wrap">
  <div class="line-nums">
    <?php for ($i = 1; $i <= $lines; $i++): ?>
    <span><?= $i ?></span>
    <?php endfor; ?>
  </div>
  <div class="code-body">
    <pre><code><?= htmlspecialchars($source) ?></code></pre>
  </div>
</div>
</body>
</html>

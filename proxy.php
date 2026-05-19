<?php

// ═══════════════════════════════════════
// MEMORY CORE FUNCTIONS (merged)
// ═══════════════════════════════════════
/**
 * memory_functions.php — CmdCode Memory System 核心函数库
 * 
 * 提供：加密/解密、存储、检索、配额检查等基础功能
 * 适配 XinCache 共享主机环境
 * 
 * 依赖：PHP 7.4+ (openssl, pdo_mysql, mbstring)
 */

// ── MySQL 连接（懒加载） ──
function getMemoryDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=__YOUR_MYSQL_HOST_HK__;dbname=__YOUR_DB_NAME__;charset=utf8mb4',
            '__YOUR_MYSQL_USER__',
            '__YOUR_MYSQL_PASSWORD__',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5,
            ]
        );
    }
    return $pdo;
}

// ── 主密钥派生（基于 config.enc.php 的加密口令） ──
function getMemoryMasterKey(): string {
    // 使用与 config.enc.php 相同的加密口令派生记忆系统主密钥
    $passphrase = '__YOUR_ENCRYPTION_PASSPHRASE__';
    return hash('sha256', $passphrase . ':memory:master', true);
}

// ── 用户级密钥派生 ──
function deriveUserMemoryKeys(string $userId): array {
    $masterKey = getMemoryMasterKey();
    return [
        'encrypt' => hash_hmac('sha256', $userId . ':memory:encrypt', $masterKey, true),
        'hmac'    => hash_hmac('sha256', $userId . ':memory:hmac', $masterKey, true),
    ];
}

// ── 事实加密 ──
function encryptFact(string $plaintext, string $userId): array {
    $keys = deriveUserMemoryKeys($userId);
    $iv = openssl_random_pseudo_bytes(16);
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-cbc', $keys['encrypt'], OPENSSL_RAW_DATA, $iv);
    $mac = hash_hmac('sha256', $iv . $ciphertext, $keys['hmac']);
    return [
        'iv'   => base64_encode($iv),
        'data' => base64_encode($ciphertext),
        'mac'  => $mac,
    ];
}

// ── 事实解密 ──
function decryptFact(array $encrypted, string $userId): string {
    $keys = deriveUserMemoryKeys($userId);
    $iv = base64_decode($encrypted['iv']);
    $ciphertext = base64_decode($encrypted['data']);
    $calculatedMac = hash_hmac('sha256', $iv . $ciphertext, $keys['hmac']);
    if (!hash_equals($calculatedMac, $encrypted['mac'])) {
        throw new Exception('Memory integrity check failed');
    }
    $plaintext = openssl_decrypt($ciphertext, 'aes-256-cbc', $keys['encrypt'], OPENSSL_RAW_DATA, $iv);
    if ($plaintext === false) {
        throw new Exception('Memory decryption failed');
    }
    return $plaintext;
}

// ── 原子文件写入 ──
function atomicFileWrite(string $filePath, string $content): bool {
    $tmpPath = $filePath . '.tmp.' . getmypid();
    if (file_put_contents($tmpPath, $content, LOCK_EX) === false) return false;
    if (!rename($tmpPath, $filePath)) { @unlink($tmpPath); return false; }
    return true;
}

// ── 安全追加JSONL ──
function safeAppendJSONL(string $filePath, array $record): bool {
    $line = json_encode($record, JSON_UNESCAPED_UNICODE) . "\n";
    if (file_put_contents($filePath, $line, FILE_APPEND | LOCK_EX) === false) {
        return false;
    }
    return true;
}

// ── 目录大小计算 ──
function dirSize(string $dir): int {
    $size = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) $size += $file->getSize();
    }
    return $size;
}

// ── 获取用户记忆目录（基于现有用户目录结构） ──
function getMemoryDir(string $userId): string {
    $baseDir = __DIR__ . '/users/' . preg_replace('/[^a-zA-Z0-9_]/', '_', $userId);
    $memoryDir = $baseDir . '/memory';
    $oldDir = $baseDir . '/Memory';
    // 迁移旧版大写 Memory → 新版小写 memory
    if (is_dir($oldDir)) {
        if (!is_dir($memoryDir)) {
            @rename($oldDir, $memoryDir);
        } else {
            // 两者都存在：将旧目录内容递归移入新目录
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($oldDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $item) {
                $rel = substr($item->getPathname(), strlen($oldDir) + 1);
                $target = $memoryDir . '/' . $rel;
                if ($item->isDir()) {
                    if (!is_dir($target)) @mkdir($target, 0700, true);
                } else {
                    if (!file_exists($target)) @copy($item->getPathname(), $target);
                }
            }
            // 递归删除旧目录
            $dIt = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($oldDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($dIt as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($oldDir);
        }
    }
    if (!is_dir($memoryDir)) {
        @mkdir($memoryDir, 0700, true);
        @mkdir($memoryDir . '/L2_scenes', 0700, true);
    }
    return $memoryDir;
}

// ── 获取已认证用户ID ──
function getAuthenticatedUserId(): ?string {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    return $_SESSION['user'] ?? null;
}

// ── 记忆配额检查（100MB/用户） ──
function checkMemoryQuota(string $userId, int $incomingBytes): bool {
    $memoryDir = getMemoryDir($userId);
    $current = 0;
    if (is_dir($memoryDir)) $current += dirSize($memoryDir);
    return ($current + $incomingBytes) <= (100 * 1024 * 1024);
}

// ── 获取用户总用量 ──
function getUserTotalMemoryUsage(string $userId): int {
    $memoryDir = getMemoryDir($userId);
    $size = 0;
    if (is_dir($memoryDir)) $size += dirSize($memoryDir);
    return $size;
}

// ── 调用LLM API提取事实（简化版，使用proxy.php的curl逻辑） ──
function callMemoryLLM(array $messages): string {
    $apiUrl = 'https://opencode.ai/zen/go/v1/chat/completions';
    global $PROVIDERS;
    if (!isset($PROVIDERS) || !is_array($PROVIDERS)) return '{}';
    $keys = $PROVIDERS['opencode-go']['keys'] ?? [];
    if (empty($keys) || empty($keys[0])) return '{}';
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $keys[0],
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'deepseek-v4-flash',
            'messages' => $messages,
            'temperature' => 0.3,
            'max_tokens' => 4096,
        ]),
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) return '{}';
    $data = json_decode($response, true);
    return $data['choices'][0]['message']['content'] ?? '{}';
}

/**
 * CmdCode Multi-Provider API Proxy
 * 
 * 多供应商 API 代理 — 解决浏览器 CORS 问题
 * 支持 MiniMax（三密钥轮换容灾）和 OpenCode Go 等供应商
 * 所有密钥加密存储在 config.enc.php 中，永不暴露到前端
 * 
 * 🔒 安全防护：
 *   ① CORS 域名白名单（仅允许 cmdcode.cn / qqcmd.com）
 *   ② 前端访问令牌验证（_token 参数）
 *   ③ config.enc.php 由 .htaccess 禁止直访
 * 
 * 用法（POST）：
 *   { "_token": "xxx", "_provider": "minimax", "_path": "/chat/completions", ...请求体 }
 *   { "_token": "xxx", "_provider": "opencode-go", ... }
 * 
 * 如果不传 _provider，默认使用 minimax（向后兼容）
 */

// ═══════════════════════════════════════
// ① CORS 域名白名单
// ═══════════════════════════════════════
$CORS_ORIGINS = [
    'https://appleclaw.cc',
    'https://appleclaw.chat',
    'https://appleclaw.cloud',
    'https://appleclaw.live',
    'https://appleclaw.net',
    'https://appleclaw.online',
    'https://appleclaw.shop',
    'https://appleclaw.space',
    'https://appleclaw.studio',
    'https://appleclaw.top',
    'https://appleclaw.video',
    'https://appleclaw.vip',
    'https://appleclaw.work',
    'https://cmdbot.cn',
    'https://cmdclaw.net',
    'https://cmdcode.cn',
    'https://dnmclaw.cn',
    'https://dnmclaw.com',
    'https://dnmclaw.online',
    'https://dnmclaw.shop',
    'https://qqclaw.club',
    'https://qqclaw.shop',
    'https://qqclaw.site',
    'https://qqclaw.space',
    'https://qqclaw.vip',
    'https://qqcmd.cn',
    'https://qqcmd.com',
    'https://qqcmd.net',
    'https://qqcmd.online',
    'https://qqcmd.shop',
    'https://qqqclaw.cn',
    'https://www.cmdcode.cn',
    'https://www.qqcmd.cn',
    'https://www.qqcmd.com',
    'https://yyclaw.net',
    'https://yyyclaw.com',
    'https://yyyclaw.fun',
    'https://yyyclaw.net',
    'https://yyyclaw.online',
    'https://yyyclaw.shop',
    // ─── HTTP 来源（40个） ───
    'http://appleclaw.cc',
    'http://appleclaw.chat',
    'http://appleclaw.cloud',
    'http://appleclaw.live',
    'http://appleclaw.net',
    'http://appleclaw.online',
    'http://appleclaw.shop',
    'http://appleclaw.space',
    'http://appleclaw.studio',
    'http://appleclaw.top',
    'http://appleclaw.video',
    'http://appleclaw.vip',
    'http://appleclaw.work',
    'http://cmdbot.cn',
    'http://cmdclaw.net',
    'http://cmdcode.cn',
    'http://dnmclaw.cn',
    'http://dnmclaw.com',
    'http://dnmclaw.online',
    'http://dnmclaw.shop',
    'http://qqclaw.club',
    'http://qqclaw.shop',
    'http://qqclaw.site',
    'http://qqclaw.space',
    'http://qqclaw.vip',
    'http://qqcmd.cn',
    'http://qqcmd.com',
    'http://qqcmd.net',
    'http://qqcmd.online',
    'http://qqcmd.shop',
    'http://qqqclaw.cn',
    'http://www.cmdcode.cn',
    'http://www.qqcmd.cn',
    'http://www.qqcmd.com',
    'http://yyclaw.net',
    'http://yyyclaw.com',
    'http://yyyclaw.fun',
    'http://yyyclaw.net',
    'http://yyyclaw.online',
    'http://yyyclaw.shop',
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allow_all = false; // 不允许通配

if ($origin) {
    $parsed = parse_url($origin, PHP_URL_HOST) ?: '';
    $allowed = false;
    foreach ($CORS_ORIGINS as $o) {
        $oh = parse_url($o, PHP_URL_HOST);
        if ($parsed === $oh) { $allowed = true; break; }
    }
    if ($allowed) {
        header('Access-Control-Allow-Origin: ' . $origin);
    } else {
        // 非白名单来源，拒绝 CORS
        header('Access-Control-Allow-Origin: https://cmdcode.cn');
    }
} else {
    // 无 Origin 头（如 curl 直接调用）→ 允许但限制方法
    header('Access-Control-Allow-Origin: https://cmdcode.cn');
}

header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-XSS-Protection: 1; mode=block');

// 处理预检请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ═══════════════════════════════════════
// ③ 解析 JSON 请求体
// ═══════════════════════════════════════
$input = json_decode(file_get_contents('php://input'), true) ?: [];

// ═══════════════════════════════════════
// ④ 用户文件系统（可选 — 仅当 _action 参数存在时触发）
// ═══════════════════════════════════════

// 用户目录配置
define('USERS_DIR', __DIR__ . '/users');
if (!is_dir(USERS_DIR)) mkdir(USERS_DIR, 0755, true);
$usersFile = USERS_DIR . '/.htusers.json';

function loadUsers() {
    global $usersFile;
    if (!file_exists($usersFile)) return [];
    return json_decode(file_get_contents($usersFile), true) ?: [];
}
function saveUsers($users) {
    global $usersFile;
    file_put_contents($usersFile, json_encode($users));
}
function getUserDir($username) {
    $dir = USERS_DIR . '/' . preg_replace('/[^a-zA-Z0-9_]/', '_', $username);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $md = $dir . '/memory';
    if (!is_dir($md)) @mkdir($md, 0700, true);
    $td = $dir . '/tmp';
    if (!is_dir($td)) @mkdir($td, 0755, true);
    return $dir;
}
function getUserUsage($username) {
    $dir = getUserDir($username);
    $total = 0;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->isFile()) $total += $file->getSize();
    }
    return $total;
}
define('QUOTA_BYTES', 1024 * 1024 * 1024); // 1GB (admin)
define('REGULAR_QUOTA_BYTES', 100 * 1024 * 1024); // 100MB (普通用户)
define('ACCESS_TOKEN', '__YOUR_PROXY_ACCESS_TOKEN__'); // 前端访问令牌（与 cron worker 一致）
define('GUEST_QUOTA_BYTES', 1 * 1024 * 1024 * 1024); // 1GB shared for all guests

// 安全辅助函数：获取当前有效用户目录（登录用户→个人文件夹，访客→共享 guest/ 文件夹）
function getUserDirSafe() {
    if (isset($_SESSION['user'])) return getUserDir($_SESSION['user']);
    $dir = USERS_DIR . '/guest';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    foreach (['images','videos','music','voice','files','memory','tmp'] as $sub) {
        $sd = $dir . '/' . $sub;
        if (!is_dir($sd)) @mkdir($sd, 0755, true);
    }
    return $dir;
}
function getUserQuotaSafe() {
    if (!isset($_SESSION['user'])) return GUEST_QUOTA_BYTES;
    return $_SESSION['user'] === 'admin' ? QUOTA_BYTES : REGULAR_QUOTA_BYTES;
}
function getUserUsageSafe() {
    $dir = getUserDirSafe();
    $total = 0;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->isFile()) $total += $file->getSize();
    }
    return $total;
}

// 用户系统动作路由（优先级高于 API 代理）
$action = $input['_action'] ?? $_GET['_action'] ?? '';
if (in_array($action, ['register','login','logout','session','get_proxy_token','quota','file_read','file_write','file_edit','file_delete','list_files','file_rename','file_save_from_url','file_download','generate_share_link','web_fetch','bash','memory','image_proxy'])) {
    // 安全 session 配置
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_only_cookies', 1);
    session_start();
    // ─── 全域 Token 认证（排除无需 token 的动作） ───
    $exemptActions = ['register','login','session','get_proxy_token'];
    $requiresToken = !in_array($action, $exemptActions);
    // file_download + share_token 路径也无需 token
    if ($action === 'file_download' && !empty($input['share_token'] ?? $_GET['share_token'] ?? '')) {
        $requiresToken = false;
    }
    if ($requiresToken) {
        // CSRF Token 验证（只对非文件下载的 POST 请求）
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($action, ['file_download','generate_share_link'])) {
            $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
            if (strlen($csrfHeader) !== 64) { http_response_code(403); echo json_encode(['error'=>'CSRF token invalid']); exit; }
        }
        $sentToken = $input['_token'] ?? $_GET['_token'] ?? '';
        if ($sentToken !== ACCESS_TOKEN) {
            http_response_code(403);
            echo json_encode(['error' => 'token_invalid', 'message' => 'Access token is invalid or missing']);
            exit;
        }
    }
    switch ($action) {
        case 'register':
            $username = trim($input['username'] ?? '');
            $password = $input['password'] ?? '';
            if (strlen($username) < 2 || strlen($username) > 30) { echo json_encode(['error'=>'用户名长度2-30']); exit; }
            if (strlen($password) < 4) { echo json_encode(['error'=>'密码至少4位']); exit; }
            $users = loadUsers();
            if (isset($users[$username])) { echo json_encode(['error'=>'用户名已存在']); exit; }
            $users[$username] = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            saveUsers($users);
            getUserDir($username);
            echo json_encode(['success'=>true,'message'=>'注册成功']);
            exit;

        case 'login':
            // 登录速率限制（基于 IP）
            $loginIP = $_SERVER['REMOTE_ADDR'];
            $loginAttempts = @file_get_contents("/tmp/login_attempts_".md5($loginIP));
            $loginAttempts = $loginAttempts ? (int)$loginAttempts : 0;
            if ($loginAttempts > 5) { echo json_encode(['error'=>'登录尝试过多，请15分钟后重试']); exit; }
            $username = trim($input['username'] ?? '');
            $password = $input['password'] ?? '';
            $users = loadUsers();
            if (!isset($users[$username]) || !password_verify($password, $users[$username])) {
                @file_put_contents("/tmp/login_attempts_".md5($loginIP), $loginAttempts + 1);
                echo json_encode(['error'=>'用户名或密码错误']); exit;
            }
            $_SESSION['user'] = $username;
            session_regenerate_id(true);
            @unlink("/tmp/login_attempts_".md5($loginIP));
            echo json_encode(['success'=>true,'username'=>$username]);
            exit;

        case 'logout':
            session_destroy();
            echo json_encode(['success'=>true]);
            exit;

        case 'session':
            echo json_encode(['loggedIn'=>isset($_SESSION['user']),'username'=>$_SESSION['user']??null]);
            exit;

        case 'get_proxy_token':
            echo json_encode(['token' => ACCESS_TOKEN]);
            exit;

        case 'quota':
            $used = getUserUsageSafe();
            $quota = getUserQuotaSafe();
            echo json_encode(['usedBytes'=>$used,'usedMB'=>round($used/(1024*1024),1),'quotaMB'=>$quota/(1024*1024),'percent'=>round(($used/$quota)*100,1)]);
            exit;

        case 'file_read':
            $fullPath = getUserDirSafe() . '/' . ltrim($input['file_path'], '/');
            if (strpos($fullPath, '..') !== false) { echo json_encode(['error'=>'路径不合法']); exit; }
            if (!file_exists($fullPath)) { echo json_encode(['error'=>'文件未找到']); exit; }
            $content = file_get_contents($fullPath);
            $offset = (int)($input['offset'] ?? 0);
            $limit = $input['limit'] ?? null;
            if ($offset || $limit) {
                $lines = explode("\n", $content);
                $sliced = array_slice($lines, $offset, $limit);
                $content = implode("\n", $sliced);
            }
            echo json_encode(['content'=>$content]);
            exit;

        case 'file_write':
            $fullPath = getUserDirSafe() . '/' . ltrim($input['file_path'], '/');
            if (strpos($fullPath, '..') !== false) { echo json_encode(['error'=>'路径不合法']); exit; }
            $content = !empty($input['_binary']) ? base64_decode($input['content'], true) : $input['content'] ?? '';
            if (!empty($input['_binary']) && $content === false) { echo json_encode(['error'=>'二进制数据解码失败']); exit; }
            $used = getUserUsageSafe();
            $quota = getUserQuotaSafe();
            $newSize = strlen($content);
            if ($used + $newSize > $quota) { echo json_encode(['error'=>'超出存储配额']); exit; }
            $dir = dirname($fullPath);
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            file_put_contents($fullPath, $content);
            echo json_encode(['success'=>true,'message'=>'文件已写入: '.$input['file_path']]);
            exit;

        case 'file_edit':
            $fullPath = getUserDirSafe() . '/' . ltrim($input['file_path'], '/');
            if (strpos($fullPath, '..') !== false) { echo json_encode(['error'=>'路径不合法']); exit; }
            if (!file_exists($fullPath)) { echo json_encode(['error'=>'文件未找到']); exit; }
            $oldContent = file_get_contents($fullPath);
            $oldStr = $input['old_string'];
            $newStr = $input['new_string'];
            if (strpos($oldContent, $oldStr) === false) { echo json_encode(['error'=>'未找到匹配字符串']); exit; }
            $newContent = !empty($input['replace_all']) ? str_replace($oldStr, $newStr, $oldContent) : str_replace($oldStr, $newStr, $oldContent);
            $diff = strlen($newContent) - strlen($oldContent);
            if (getUserUsageSafe() + $diff > getUserQuotaSafe()) { echo json_encode(['error'=>'超出存储配额']); exit; }
            file_put_contents($fullPath, $newContent);
            echo json_encode(['success'=>true,'message'=>'文件已编辑: '.$input['file_path']]);
            exit;

        case 'file_delete':
            $fullPath = getUserDirSafe() . '/' . ltrim($input['file_path'], '/');
            if (strpos($fullPath, '..') !== false) { echo json_encode(['error'=>'路径不合法']); exit; }
            if (file_exists($fullPath)) { unlink($fullPath); echo json_encode(['success'=>true,'message'=>'文件已删除']); }
            else { echo json_encode(['error'=>'文件不存在']); }
            exit;

        case 'list_files':
            $base = getUserDirSafe();
            $subPath = trim($input['path'] ?? '', '/');
            $targetDir = $subPath ? $base . '/' . $subPath : $base;
            if (strpos(realpath($targetDir) ?: $targetDir, realpath($base) ?: $base) !== 0) { echo json_encode(['error'=>'路径不合法']); exit; }
            if (!is_dir($targetDir)) { echo json_encode(['error'=>'目录不存在']); exit; }
            $files = []; $totalSize = 0;
            foreach (new DirectoryIterator($targetDir) as $f) {
                if ($f->isDot()) continue;
                $relPath = $subPath ? $subPath . '/' . $f->getFilename() : $f->getFilename();
                $entry = ['name'=>$f->getFilename(), 'path'=>$relPath, 'size'=>$f->getSize(), 'mtime'=>date('Y-m-d H:i:s', $f->getMTime()), 'is_dir'=>$f->isDir()];
                if ($f->isDir()) {
                    $entry['size'] = 0;
                    $entry['file_count'] = iterator_count(new FilesystemIterator($f->getPathname(), FilesystemIterator::SKIP_DOTS));
                } else {
                    $totalSize += $f->getSize();
                }
                $files[] = $entry;
            }
            usort($files, function($a, $b) { return $b['is_dir'] <=> $a['is_dir'] ?: strcasecmp($a['name'], $b['name']); });
            echo json_encode(['files'=>$files, 'currentPath'=>$subPath, 'totalSize'=>$totalSize, 'quotaMB'=>getUserQuotaSafe()/(1024*1024)]);
            exit;

        case 'file_rename':
            $base = getUserDirSafe();
            $oldPath = $input['old_path'] ?? '';
            $newPath = $input['new_path'] ?? '';
            if (!$oldPath || !$newPath) { echo json_encode(['error'=>'参数不完整']); exit; }
            $fullOld = $base . '/' . ltrim($oldPath, '/');
            $fullNew = $base . '/' . ltrim($newPath, '/');
            if (strpos($fullOld, $base) !== 0 || strpos($fullNew, $base) !== 0) { echo json_encode(['error'=>'路径不合法']); exit; }
            if (strpos($fullOld, '..') !== false || strpos($fullNew, '..') !== false) { echo json_encode(['error'=>'路径不合法']); exit; }
            if (!file_exists($fullOld)) { echo json_encode(['error'=>'文件不存在']); exit; }
            $dir = dirname($fullNew);
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            if (!rename($fullOld, $fullNew)) { echo json_encode(['error'=>'重命名失败']); exit; }
            echo json_encode(['success'=>true, 'message'=>'已重命名为: '.$newPath]);
            exit;

        case 'file_save_from_url':
            $url = $input['url'] ?? '';
            $folder = trim($input['folder'] ?? '', '/');
            if (!$url) { echo json_encode(['error'=>'URL不能为空']); exit; }
            $content = @file_get_contents($url);
            if ($content === false) { echo json_encode(['error'=>'下载失败']); exit; }
            $used = getUserUsageSafe();
            $quota = getUserQuotaSafe();
            if ($used + strlen($content) > $quota) { echo json_encode(['error'=>'超出存储配额']); exit; }
            $ext = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
            if (!$ext) $ext = 'bin';
            $fname = ($folder ? $folder . '/' : '') . time() . '.' . $ext;
            $fullPath = getUserDirSafe() . '/' . $fname;
            $dir = dirname($fullPath);
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            file_put_contents($fullPath, $content);
            echo json_encode(['success'=>true, 'file'=>$fname, 'size'=>strlen($content)]);
            exit;

        case 'image_proxy':
            $url = $input['url'] ?? '';
            if (!$url) { echo json_encode(['error'=>'URL不能为空']); exit; }
            $content = @file_get_contents($url);
            if ($content === false) {
                // 尝试用 curl 下载（支持更多协议/hosts）
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 5,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_USERAGENT => 'Mozilla/5.0',
                ]);
                $content = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                curl_close($ch);
                if ($content === false || $httpCode >= 400) {
                    echo json_encode(['error'=>'图片下载失败: '.($error?:("HTTP ".$httpCode))]);
                    exit;
                }
            }
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo ? finfo_buffer($finfo, $content) : 'image/jpeg';
            finfo_close($finfo);
            $b64 = base64_encode($content);
            echo json_encode(['success'=>true, 'data_url'=>'data:'.$mime.';base64,'.$b64, 'size'=>strlen($content), 'mime'=>$mime]);
            exit;

        case 'file_download':
            // 临时分享令牌（无需登录）
            $shareToken = $input['share_token'] ?? $_GET['share_token'] ?? '';
            if ($shareToken) {
                $tokenClean = preg_replace('/[^a-f0-9]/', '', $shareToken);
                $shareFile = USERS_DIR . '/shares/' . $tokenClean . '.json';
                if (file_exists($shareFile)) {
                    $shareData = json_decode(file_get_contents($shareFile), true);
                    if ($shareData && $shareData['expires'] > time()) {
                        $fullPath = getUserDir($shareData['username']) . '/' . ltrim($shareData['path'], '/');
                        if (file_exists($fullPath)) {
                            $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
                            $mimeMap = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','bmp'=>'image/bmp','svg'=>'image/svg+xml','mp3'=>'audio/mpeg','mp4'=>'video/mp4','pdf'=>'application/pdf','txt'=>'text/plain','html'=>'text/html','css'=>'text/css','js'=>'application/javascript','json'=>'application/json','md'=>'text/markdown','csv'=>'text/csv','zip'=>'application/zip'];
                            header('Content-Type: ' . ($mimeMap[$ext] ?? 'application/octet-stream'));
                            header('Content-Disposition: inline; filename="' . basename($fullPath) . '"');
                            readfile($fullPath);
                            exit;
                        }
                    }
                }
                http_response_code(403);
                echo json_encode(['error'=>'分享链接无效或已过期']);
                exit;
            }
            $fullPath = getUserDirSafe() . '/' . ltrim($input['file_path'] ?? $_GET['file_path'] ?? '', '/');
            if (strpos($fullPath, '..') !== false) { http_response_code(403); echo json_encode(['error'=>'路径不合法']); exit; }
            if (!file_exists($fullPath)) { http_response_code(404); echo json_encode(['error'=>'文件未找到']); exit; }
            $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            $mimeMap = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','bmp'=>'image/bmp','svg'=>'image/svg+xml','mp3'=>'audio/mpeg','mp4'=>'video/mp4','pdf'=>'application/pdf','txt'=>'text/plain','html'=>'text/html','css'=>'text/css','js'=>'application/javascript','json'=>'application/json','md'=>'text/markdown','csv'=>'text/csv','zip'=>'application/zip'];
            header('Content-Type: ' . ($mimeMap[$ext] ?? 'application/octet-stream'));
            header('Content-Disposition: inline; filename="' . basename($fullPath) . '"');
            readfile($fullPath);
            exit;
        case 'generate_share_link':
            $filePath = $input['file_path'] ?? '';
            if (!$filePath) { echo json_encode(['error'=>'file_path不能为空']); exit; }
            $fullPath = getUserDirSafe() . '/' . ltrim($filePath, '/');
            if (strpos($fullPath, '..') !== false || !file_exists($fullPath)) { echo json_encode(['error'=>'文件不存在']); exit; }
            $token = bin2hex(random_bytes(16));
            $expires = time() + 3600; // 1 hour
            $shareDir = USERS_DIR . '/shares';
            if (!is_dir($shareDir)) @mkdir($shareDir, 0755, true);
            $effectiveUser = $_SESSION['user'] ?? 'guest';
            file_put_contents("$shareDir/$token.json", json_encode([
                'path' => $filePath,
                'username' => $effectiveUser,
                'expires' => $expires,
            ]));
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $baseUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'cmdcode.cn') . dirname($_SERVER['SCRIPT_NAME']);
            echo json_encode([
                'share_url' => $baseUrl . '/proxy.php?_action=file_download&share_token=' . $token,
                'expires' => $expires,
                'expires_in' => '1小时',
            ]);
            exit;
        case 'web_fetch':
            $url = $input['url'] ?? '';
            if (!$url) { echo json_encode(['error'=>'URL不能为空']); exit; }
            if (!filter_var($url, FILTER_VALIDATE_URL)) { echo json_encode(['error'=>'URL格式不合法']); exit; }
            $maxChars = (int)($input['max_chars'] ?? 50000);
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
                CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8','Accept-Language: zh-CN,zh;q=0.9,en;q=0.8'],
            ]);
            $body = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            if ($body === false) { echo json_encode(['error'=>'请求失败: '.$error]); exit; }
            if (strlen($body) > $maxChars) $body = substr($body, 0, $maxChars) . "\n\n... (已截断)";
            echo json_encode(['success'=>true,'url'=>$url,'httpCode'=>$httpCode,'content'=>$body,'length'=>strlen($body)]);
            exit;
        case 'bash':
            $cmd = $input['command'] ?? '';
            if (!$cmd) { echo json_encode(['error'=>'命令不能为空']); exit; }
            $timeout = (int)($input['timeout'] ?? 30);
            if ($timeout < 1) $timeout = 5;
            if ($timeout > 60) $timeout = 60;
            $dangerous = ['rm -rf /', 'mkfs', 'dd if=', ':(){', '> /dev/sda', 'chmod 777 /', 'wget -O /', 'curl .* -o /etc', 'mv .* /etc', 'sudo ', 'su -'];
            foreach ($dangerous as $pattern) {
                if (stripos($cmd, $pattern) !== false) {
                    echo json_encode(['error'=>'该命令已被安全策略拦截']); exit;
                }
            }
            $escaped = escapeshellcmd($cmd);
            $result = null;
            @set_time_limit($timeout + 5);
            // 尝试多种执行方式（proc_open→exec→shell_exec）
            if ($result === null && function_exists('proc_open') && !in_array('proc_open', explode(',', ini_get('disable_functions')))) {
                $descriptorspec = [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
                $process = @proc_open($escaped, $descriptorspec, $pipes, null, null);
                if (is_resource($process)) {
                    fclose($pipes[0]);
                    $stdout = stream_get_contents($pipes[1]);
                    $stderr = stream_get_contents($pipes[2]);
                    fclose($pipes[1]); fclose($pipes[2]);
                    $exitCode = proc_close($process);
                    $result = ['stdout'=>$stdout, 'stderr'=>$stderr, 'exitCode'=>$exitCode];
                }
            }
            if ($result === null && function_exists('exec') && !in_array('exec', explode(',', ini_get('disable_functions')))) {
                $output = []; $exitCode = -1;
                $lastLine = @exec($escaped . ' 2>/tmp/exec_stderr.tmp', $output, $exitCode);
                $stderr = @file_get_contents('/tmp/exec_stderr.tmp');
                @unlink('/tmp/exec_stderr.tmp');
                $result = ['stdout'=>implode("\n", $output), 'stderr'=>$stderr ?: '', 'exitCode'=>$exitCode];
            }
            if ($result === null && function_exists('shell_exec') && !in_array('shell_exec', explode(',', ini_get('disable_functions')))) {
                $stdout = @shell_exec($escaped);
                $result = ['stdout'=>$stdout ?: '', 'stderr'=>'', 'exitCode'=>0];
            }
            if ($result === null) {
                echo json_encode(['error'=>'所有命令执行函数皆不可用（proc_open/exec/shell_exec均被禁用）']); exit;
            }
            $maxOutput = 50000;
            if (strlen($result['stdout']) > $maxOutput) $result['stdout'] = substr($result['stdout'],0,$maxOutput)."\n\n... (输出已截断)";
            if (strlen($result['stderr']) > $maxOutput) $result['stderr'] = substr($result['stderr'],0,$maxOutput)."\n\n... (输出已截断)";
            echo json_encode(['success'=>true,'command'=>$cmd,'stdout'=>$result['stdout'],'stderr'=>$result['stderr'],'exitCode'=>$result['exitCode']]);
            exit;
        case 'memory':
            // ═══════════════════════════════════════
            // 记忆系统 — 多级记忆存储/检索
            // ═══════════════════════════════════════
            header('Content-Type: application/json');
            
            $userId = getAuthenticatedUserId();
            if (!$userId) {
                // 访客模式：使用 Session 级别访客标识（同一浏览器会话内唯一且稳定）
                $userId = 'guest_' . session_id();
            }
            
            $memoryDir = getMemoryDir($userId);
            
            $sub = $_GET['sub_action'] ?? $input['sub_action'] ?? 'search';
            $data = $input;
            
            switch ($sub) {
                case 'enqueue_extract':
                    // 入队记忆提取任务
                    if (empty($data['messages'])) {
                        echo json_encode(['error' => 'Missing messages']);
                        break;
                    }
                    $estimatedSize = strlen(json_encode($data['messages'])) * 0.1;
                    if (!checkMemoryQuota($userId, (int)$estimatedSize)) {
                        http_response_code(507);
                        echo json_encode(['error' => 'Storage quota exceeded']);
                        break;
                    }
                    $pdo = getMemoryDB();
                    $stmt = $pdo->prepare("INSERT INTO memory_tasks (user_id, task_type, payload) VALUES (?, 'extract_facts', ?)");
                    $stmt->execute([$userId, json_encode([
                        'messages' => $data['messages'],
                        'scene_id' => $data['scene_id'] ?? 'scene_default'
                    ])]);
                    echo json_encode(['status' => 'queued', 'task_id' => $pdo->lastInsertId()]);
                    break;
                    
                case 'search':
                    $query = $_GET['query'] ?? $data['query'] ?? '';
                    $sceneId = $_GET['scene_id'] ?? $data['scene_id'] ?? '';
                    $limit = min((int)($_GET['limit'] ?? $data['limit'] ?? 10), 50);
                    $pdo = getMemoryDB();
                    
                    // 构建查询
                    $factsFilePath = $memoryDir . '/L1_facts.jsonl';
                    $results = [];
                    
                    if ($query) {
                        // 先尝试 LIKE 模糊搜索整句
                        $likeQ = '%' . $query . '%';
                        $stmt = $pdo->prepare(
                            "SELECT id, fact_id, fact_preview, category, importance 
                             FROM memory_index 
                             WHERE user_id=:uid AND fact_preview LIKE :like_q 
                             ORDER BY importance DESC, access_count DESC 
                             LIMIT :lim"
                        );
                        $stmt->bindValue('uid', $userId, PDO::PARAM_STR);
                        $stmt->bindValue('like_q', $likeQ, PDO::PARAM_STR);
                        $stmt->bindValue('lim', (int)$limit, PDO::PARAM_INT);
                        $stmt->execute();
                        $candidates = $stmt->fetchAll();
                        
                        // 如果整句搜索无结果，用 n-gram 分字搜索（中文无空格，2字词分拆）
                        if (empty($candidates)) {
                            $ngramResults = [];
                            $len = mb_strlen($query);
                            // 提取2-4字符的 n-gram 片段，只保留含实义字的
                            $meaningful = [];
                            for ($i = 0; $i < $len; $i++) {
                                for ($j = 2; $j <= 4; $j++) {
                                    if ($i + $j > $len) break;
                                    $gram = mb_substr($query, $i, $j);
                                    // 跳过纯标点或纯数字
                                    if (preg_match('/^[\d\s]+$/u', $gram)) continue;
                                    $meaningful[$gram] = true;
                                }
                            }
                            // 先搜索2字词，再搜索3-4字词
                            foreach ($meaningful as $gram => $_) {
                                $lenG = mb_strlen($gram);
                                if ($lenG < 2 || $lenG > 4) continue;
                                // 跳过常见虚词
                                $skipWords = ['你好','请问','帮我','看看','之前','哪些','什么','怎么','如何','这个','那个','我的','你的','我们','他们','可以','能够','知道','告诉','谢谢','感谢','一个','一下','一直','一些','不是','就是','但是','因为','所以','而且','或者','如果','虽然','然后','之后','没有','还有','还是'];
                                if (mb_strlen($gram) == 2 && in_array($gram, $skipWords)) continue;
                                
                                $likeG = '%' . $gram . '%';
                                $stmt = $pdo->prepare(
                                    "SELECT id, fact_id, fact_preview, category, importance 
                                     FROM memory_index 
                                     WHERE user_id=:uid AND fact_preview LIKE :gram_q 
                                     ORDER BY importance DESC, access_count DESC 
                                     LIMIT 3"
                                );
                                $stmt->bindValue('uid', $userId, PDO::PARAM_STR);
                                $stmt->bindValue('gram_q', $likeG, PDO::PARAM_STR);
                                $stmt->execute();
                                $rows = $stmt->fetchAll();
                                foreach ($rows as $row) {
                                    $ngramResults[$row['id']] = $row;
                                }
                            }
                            if (!empty($ngramResults)) {
                                $candidates = array_values($ngramResults);
                                usort($candidates, function($a, $b) {
                                    return ($b['importance'] ?? 0) - ($a['importance'] ?? 0);
                                });
                                $candidates = array_slice($candidates, 0, $limit);
                            }
                        }
                    } elseif ($sceneId) {
                        // 按场景筛选
                        $stmt = $pdo->prepare(
                            "SELECT id, fact_id, fact_preview, category, importance 
                             FROM memory_index 
                             WHERE user_id=:uid AND l2_scene_id=:scene_id 
                             ORDER BY importance DESC, created_at DESC 
                             LIMIT :lim"
                        );
                        $stmt->bindValue('uid', $userId, PDO::PARAM_STR);
                        $stmt->bindValue('scene_id', $sceneId, PDO::PARAM_STR);
                        $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
                        $stmt->execute();
                        $candidates = $stmt->fetchAll();
                    } else {
                        // 最近记忆
                        $stmt = $pdo->prepare(
                            "SELECT id, fact_id, fact_preview, category, importance 
                             FROM memory_index 
                             WHERE user_id=:uid 
                             ORDER BY last_accessed_at DESC, importance DESC 
                             LIMIT :lim"
                        );
                        $stmt->bindValue('uid', $userId, PDO::PARAM_STR);
                        $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
                        $stmt->execute();
                        $candidates = $stmt->fetchAll();
                    }
                    
                    // 从 JSONL 解密获取完整内容
                    if (file_exists($factsFilePath)) {
                        $lines = file($factsFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                        $factMap = [];
                        foreach ($lines as $line) {
                            $rec = json_decode($line, true);
                            if ($rec) $factMap[$rec['id']] = $rec;
                        }
                        foreach ($candidates as $c) {
                            $fid = $c['fact_id'];
                            if (isset($factMap[$fid])) {
                                try {
                                    $decrypted = decryptFact($factMap[$fid]['encrypted'], $userId);
                                    // 访客模式：不返回敏感类别记忆
                                    if (strpos($userId, 'guest_') === 0 && $c['category'] === 'credential') continue;
                                    $results[] = [
                                        'id' => $fid,
                                        'fact' => $decrypted,
                                        'category' => $c['category'],
                                        'importance' => $c['importance'],
                                        'preview' => $c['fact_preview'],
                                        'scene_id' => $factMap[$fid]['l2_scene_id'] ?? null,
                                    ];
                                } catch (Exception $e) { error_log('proxy.php: '.$e->getMessage()); }
                            }
                        }
                    }
                    
                    // 更新访问计数
                    if (!empty($candidates)) {
                        $ids = array_column($candidates, 'id');
                        $ph = implode(',', array_fill(0, count($ids), '?'));
                        $pdo->prepare("UPDATE memory_index SET access_count = access_count + 1, last_accessed_at = NOW() WHERE id IN ($ph)")->execute($ids);
                    }
                    
                    echo json_encode(['facts' => $results, 'count' => count($results)]);
                    break;
                    
                case 'get_persona':
                    // 访客模式：不返回画像（可能包含跨用户敏感信息）
                    if (strpos($userId, 'guest_') === 0) {
                        echo json_encode(['traits' => '', 'message' => '访客模式：不保存用户画像，敏感信息不会记录']);
                        break;
                    }
                    $personaFile = $memoryDir . '/L3_persona.json';
                    if (file_exists($personaFile)) {
                        readfile($personaFile);
                    } else {
                        echo json_encode(['traits' => '', 'message' => 'No persona yet']);
                    }
                    break;
                    
                case 'get_scene':
                    $sceneId = $_GET['scene_id'] ?? $data['scene_id'] ?? 'scene_default';
                    $sceneFile = $memoryDir . "/L2_scenes/{$sceneId}.json";
                    if (file_exists($sceneFile)) {
                        readfile($sceneFile);
                    } else {
                        echo json_encode(['error' => 'Scene not found']);
                    }
                    break;
                    
                case 'switch_scene':
                    $sceneName = $data['name'] ?? 'Default';
                    $sceneIndexPath = $memoryDir . '/L2_scenes/scene_index.json';
                    $sceneIndex = file_exists($sceneIndexPath) ? json_decode(file_get_contents($sceneIndexPath), true) : ['active_scene_id' => 'scene_default', 'scenes' => []];
                    $existing = null;
                    foreach ($sceneIndex['scenes'] as $sc) {
                        if ($sc['name'] === $sceneName) { $existing = $sc; break; }
                    }
                    if ($existing && ($data['switch_to_existing'] ?? true)) {
                        $sceneIndex['active_scene_id'] = $existing['id'];
                        file_put_contents($sceneIndexPath, json_encode($sceneIndex));
                        echo json_encode(['scene_id' => $existing['id'], 'name' => $sceneName, 'is_new' => false]);
                    } else {
                        $sceneId = 'scene_' . time();
                        $sceneData = ['id' => $sceneId, 'name' => $sceneName, 'summary' => '', 'context' => '', 'memory_ids' => [], 'memory_count' => 0, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')];
                        file_put_contents($memoryDir . "/L2_scenes/{$sceneId}.json", json_encode($sceneData));
                        $sceneIndex['active_scene_id'] = $sceneId;
                        $sceneIndex['scenes'][] = ['id' => $sceneId, 'name' => $sceneName, 'memory_count' => 0, 'last_active' => date('Y-m-d H:i:s')];
                        file_put_contents($sceneIndexPath, json_encode($sceneIndex));
                        echo json_encode(['scene_id' => $sceneId, 'name' => $sceneName, 'is_new' => true]);
                    }
                    break;
                    
                case 'update_scene_summary':
                    $sceneId = $data['scene_id'] ?? 'scene_default';
                    $summary = $data['summary'] ?? '';
                    $sceneFile = $memoryDir . "/L2_scenes/{$sceneId}.json";
                    if (file_exists($sceneFile)) {
                        $sceneData = json_decode(file_get_contents($sceneFile), true);
                        $sceneData['summary'] = $summary;
                        $sceneData['updated_at'] = date('Y-m-d H:i:s');
                        file_put_contents($sceneFile, json_encode($sceneData));
                        echo json_encode(['status' => 'updated']);
                    } else {
                        echo json_encode(['error' => 'Scene not found']);
                    }
                    break;
                    
                case 'get_all_scenes':
                    $sceneIndexPath = $memoryDir . '/L2_scenes/scene_index.json';
                    if (file_exists($sceneIndexPath)) {
                        readfile($sceneIndexPath);
                    } else {
                        echo json_encode([]);
                    }
                    break;
                    
                default:
                    echo json_encode(['error' => 'Unknown sub_action']);
            }
            exit;
    }
    exit;
}

// ═══════════════════════════════════════
// ④ 前端访问令牌验证（API 代理需要）
// ═══════════════════════════════════════

$token = $input['_token'] ?? $_GET['_token'] ?? '';
unset($input['_token']);

if ($token !== ACCESS_TOKEN) {
    http_response_code(403);
    echo json_encode([
        'error' => 'token_invalid',
        'message' => 'Access token is invalid or missing',
    ]);
    exit;
}

// ═══════════════════════════════════════
// ⑤ 加载加密配置
// ═══════════════════════════════════════
$PROVIDERS = include __DIR__ . '/config.enc.php';
if (!is_array($PROVIDERS)) {
    http_response_code(500);
    echo json_encode(['error' => 'config_load_failed', 'message' => '加密配置加载失败']);
    exit;
}

// ── 解析请求 ──
$method = $_SERVER['REQUEST_METHOD'];
$provider_name = $input['_provider'] ?? $_GET['_provider'] ?? 'minimax';
$api_path = $input['_path'] ?? $_GET['_path'] ?? '';
unset($input['_provider']);
unset($input['_path']);

// 检查供应商是否存在
if (!isset($PROVIDERS[$provider_name])) {
    http_response_code(400);
    echo json_encode([
        'error' => 'unknown_provider',
        'message' => "未知供应商: $provider_name",
        'available' => array_keys($PROVIDERS),
    ]);
    exit;
}

$provider = $PROVIDERS[$provider_name];
$api_keys = $provider['keys'];
$base_url = rtrim($provider['base_url'], '/');

// 如果没传 _path，默认使用 /chat/completions
if (!$api_path) {
    $api_path = '/chat/completions';
}

$target_url = $base_url . $api_path;

// ═══════════════════════════════════════
// ⑥ 异步音乐生成处理
// ═══════════════════════════════════════
// MiniMax 音乐生成耗时 60-90s，但 XinCache nginx proxy_read_timeout 约 60s → 504
// 方案：
//   ① proxy.php 立即返回 task_id（fastcgi_finish_request）
//   ② PHP 继续在后台执行 curl（IO 等待不计入 max_execution_time=30s）
//   ③ 前端轮询 /music_poll 拿结果
// ⚠️ 所有进程执行函数（exec/proc_open/shell_exec）均已禁用，无法启动独立 worker
//     必须用 fastcgi_finish_request 在同一进程里"去前台后处理"

if ($api_path === '/music_generation') {
    $taskId = bin2hex(random_bytes(8)); // 16 字节 hex
    $taskDir = '/vhost/tmp/music_tasks';
    if (!is_dir($taskDir)) {
        @mkdir($taskDir, 0755, true);
    }

    // 保存请求参数（序列化）— 后台由 Hermes Cron 拉取处理，不依赖 PHP-FPM 长进程
    file_put_contents("$taskDir/$taskId.params", serialize($input));

    // 立即返回 task_id，前端轮询
    header('Content-Type: application/json');
    echo json_encode([
        'task_id' => $taskId,
        'status' => 'processing',
        'message' => '音乐生成已提交',
    ]);
    exit;
}

// 音乐生成结果查询
if ($api_path === '/music_poll') {
    $taskId = $input['task_id'] ?? $_GET['task_id'] ?? '';
    if (!$taskId || !preg_match('/^[a-f0-9]{16}$/', $taskId)) {
        echo json_encode(['error' => 'invalid_task_id', 'message' => '无效的 task_id']);
        exit;
    }

    $taskDir = '/vhost/tmp/music_tasks';
    $resultFile = "$taskDir/$taskId.result";

    header('Content-Type: application/json');
    if (file_exists($resultFile)) {
        $content = file_get_contents($resultFile);
        // 清理任务文件
        @unlink($resultFile);
        @unlink("$taskDir/$taskId.params");
        echo $content;
    } else {
        echo json_encode(['status' => 'pending']);
    }
    exit;
}

// ═══════════════════════════════════════
// ⑦ Hermes Cron 驱动的后台任务处理
// ═══════════════════════════════════════
// 不再依赖 fastcgi_finish_request（PHP-FPM 会杀死后台 worker）
// 改为：proxy.php 只保存任务参数 → Hermes Cron 定期调用 /music_process 拉取处理
// Cron 从 Hermes 服务器调用 /music_process，不受 XinCache PHP-FPM 超时影响

// 列出所有待处理任务（返回 task_id 数组）
if ($api_path === '/music_pending') {
    header('Content-Type: application/json');
    $taskDir = '/vhost/tmp/music_tasks';
    $pending = [];
    if (is_dir($taskDir)) {
        foreach (glob("$taskDir/*.params") as $paramFile) {
            $id = basename($paramFile, '.params');
            if (!preg_match('/^[a-f0-9]{16}$/', $id)) continue;
            if (!file_exists("$taskDir/$id.result")) {
                // 检查是否超时（超过 30 分钟未处理则忽略）
                $age = time() - filemtime($paramFile);
                if ($age < 1800) {
                    $pending[] = $id;
                }
            }
        }
    }
    echo json_encode(['pending' => $pending, 'count' => count($pending)]);
    exit;
}

// 处理一个待处理任务（由 Hermes Cron 调用）
if ($api_path === '/music_process') {
    header('Content-Type: application/json');
    $taskId = $_GET['task_id'] ?? $input['task_id'] ?? '';
    if (!$taskId || !preg_match('/^[a-f0-9]{16}$/', $taskId)) {
        echo json_encode(['error' => 'invalid_task_id']);
        exit;
    }

    $taskDir = '/vhost/tmp/music_tasks';
    $paramFile = "$taskDir/$taskId.params";

    if (!file_exists($paramFile)) {
        echo json_encode(['error' => 'task_not_found']);
        exit;
    }

    // 已有结果，跳过
    if (file_exists("$taskDir/$taskId.result")) {
        $content = file_get_contents("$taskDir/$taskId.result");
        echo $content;
        exit;
    }

    // 读取原始请求参数
    $originalInput = unserialize(file_get_contents($paramFile));
    if (!$originalInput || !is_array($originalInput)) {
        file_put_contents("$taskDir/$taskId.result", json_encode([
            'error' => 'invalid_params',
            'message' => '任务参数损坏',
        ]));
        echo json_encode(['status' => 'failed', 'error' => 'invalid_params']);
        exit;
    }

    // 调用 MiniMax API（同步，长超时 180s）
    $music_url = $base_url . '/music_generation';
    $last_error = '';
    $result = null;

    foreach ($api_keys as $idx => $key) {
        if (empty($key)) continue;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $music_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $key,
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($originalInput),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_TCP_KEEPIDLE => 30,
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) { $last_error = $error; continue; }
        if ($http_code === 429) { $last_error = "Key $idx rate limited"; continue; }

        $result = ['http_code' => $http_code, 'body' => $response];
        break;
    }

    if ($result) {
        $bodyData = json_decode($result['body'], true);
        $output = $bodyData ?: ['raw' => $result['body']];
        $output['_http_code'] = $result['http_code'];
        file_put_contents("$taskDir/$taskId.result", json_encode($output));
        @unlink($paramFile);
        echo json_encode(['status' => 'completed']);
    } else {
        file_put_contents("$taskDir/$taskId.result", json_encode([
            'error' => 'proxy_all_keys_exhausted',
            'message' => '所有 API Key 均已耗尽: ' . $last_error,
        ]));
        echo json_encode(['status' => 'failed', 'error' => $last_error]);
    }
    exit;
}

// 读取任务参数（供 Hermes Cron 从外部服务器直接调用 MiniMax API）
if ($api_path === '/music_read_params') {
    header('Content-Type: application/json');
    $taskId = $_GET['task_id'] ?? $input['task_id'] ?? '';
    if (!$taskId || !preg_match('/^[a-f0-9]{16}$/', $taskId)) {
        echo json_encode(['error' => 'invalid_task_id']);
        exit;
    }
    $paramFile = "/vhost/tmp/music_tasks/$taskId.params";
    if (!file_exists($paramFile)) {
        echo json_encode(['error' => 'task_not_found']);
        exit;
    }
    $originalInput = unserialize(file_get_contents($paramFile));
    echo json_encode([
        'task_id' => $taskId,
        'params' => $originalInput,
        'provider' => $provider_name,
        'api_path' => '/music_generation',
    ]);
    exit;
}

// 写入任务结果（供 Hermes Cron 在外部调用 MiniMax 后回写结果）
if ($api_path === '/music_write_result') {
    header('Content-Type: application/json');
    $taskId = $input['task_id'] ?? $_GET['task_id'] ?? '';
    if (!$taskId || !preg_match('/^[a-f0-9]{16}$/', $taskId)) {
        echo json_encode(['error' => 'invalid_task_id']);
        exit;
    }
    $resultData = $input['result'] ?? [];
    if (empty($resultData)) {
        echo json_encode(['error' => 'missing_result']);
        exit;
    }
    $taskDir = '/vhost/tmp/music_tasks';
    file_put_contents("$taskDir/$taskId.result", json_encode($resultData));
    @unlink("$taskDir/$taskId.params");
    echo json_encode(['status' => 'saved']);
    exit;
}

// 获取供应商配置（仅供系统 crontab 从本机服务器调用，不暴露给浏览器）
if ($api_path === '/music_get_provider') {
    header('Content-Type: application/json');
    // IP 限制：只允许本机 Hermes 服务器调用
    $allowedIPs = ['__YOUR_SERVER_IP__'];
    $remoteIP = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($remoteIP, $allowedIPs)) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden', 'message' => '仅允许内部服务器调用']);
        exit;
    }
    if (!isset($PROVIDERS['minimax'])) {
        echo json_encode(['error' => 'provider_not_found']);
        exit;
    }
    $provider = $PROVIDERS['minimax'];
    echo json_encode([
        'base_url' => $provider['base_url'],
        'keys' => $provider['keys'],
    ]);
    exit;
}

// ═══════════════════════════════════════
// ⑧ 异步视频生成处理（与音乐生成同模式，独立任务目录）
// ═══════════════════════════════════════
// MiniMax 视频生成（Hailuo 2.3）耗时 60-120s，同样需绕过 PHP-FPM 30s 超时
// 方案：proxy.php 只保存任务参数 → Hermes Cron 轮询拉取处理

$VIDEO_TASK_DIR = '/vhost/tmp/video_tasks';

// 视频生成提交
if ($api_path === '/video_submit') {
    $taskId = bin2hex(random_bytes(8));
    if (!is_dir($VIDEO_TASK_DIR)) {
        @mkdir($VIDEO_TASK_DIR, 0755, true);
    }
    file_put_contents("$VIDEO_TASK_DIR/$taskId.params", serialize($input));
    header('Content-Type: application/json');
    echo json_encode([
        'task_id' => $taskId,
        'status' => 'processing',
        'message' => '视频生成已提交',
    ]);
    exit;
}

// 视频生成结果查询
if ($api_path === '/video_poll') {
    $taskId = $input['task_id'] ?? $_GET['task_id'] ?? '';
    if (!$taskId || !preg_match('/^[a-f0-9]{16}$/', $taskId)) {
        echo json_encode(['error' => 'invalid_task_id', 'message' => '无效的 task_id']);
        exit;
    }
    $resultFile = "$VIDEO_TASK_DIR/$taskId.result";
    header('Content-Type: application/json');
    if (file_exists($resultFile)) {
        $content = file_get_contents($resultFile);
        @unlink($resultFile);
        @unlink("$VIDEO_TASK_DIR/$taskId.params");
        echo $content;
    } else {
        echo json_encode(['status' => 'pending']);
    }
    exit;
}

// 列出所有待处理视频任务
if ($api_path === '/video_pending') {
    header('Content-Type: application/json');
    $pending = [];
    if (is_dir($VIDEO_TASK_DIR)) {
        foreach (glob("$VIDEO_TASK_DIR/*.params") as $paramFile) {
            $id = basename($paramFile, '.params');
            if (!preg_match('/^[a-f0-9]{16}$/', $id)) continue;
            if (!file_exists("$VIDEO_TASK_DIR/$id.result")) {
                $age = time() - filemtime($paramFile);
                if ($age < 1800) {
                    $pending[] = $id;
                }
            }
        }
    }
    echo json_encode(['pending' => $pending, 'count' => count($pending)]);
    exit;
}

// 处理一个待处理视频任务
if ($api_path === '/video_process') {
    header('Content-Type: application/json');
    $taskId = $_GET['task_id'] ?? $input['task_id'] ?? '';
    if (!$taskId || !preg_match('/^[a-f0-9]{16}$/', $taskId)) {
        echo json_encode(['error' => 'invalid_task_id']);
        exit;
    }
    $paramFile = "$VIDEO_TASK_DIR/$taskId.params";
    if (!file_exists($paramFile)) {
        echo json_encode(['error' => 'task_not_found']);
        exit;
    }
    if (file_exists("$VIDEO_TASK_DIR/$taskId.result")) {
        $content = file_get_contents("$VIDEO_TASK_DIR/$taskId.result");
        echo $content;
        exit;
    }
    $originalInput = unserialize(file_get_contents($paramFile));
    if (!$originalInput || !is_array($originalInput)) {
        file_put_contents("$VIDEO_TASK_DIR/$taskId.result", json_encode([
            'error' => 'invalid_params', 'message' => '任务参数损坏',
        ]));
        echo json_encode(['status' => 'failed', 'error' => 'invalid_params']);
        exit;
    }
    $video_url = $base_url . '/video_generation';
    $last_error = '';
    $result = null;
    foreach ($api_keys as $idx => $key) {
        if (empty($key)) continue;
        // 过滤：仅保留 MiniMax API 支持的字段（model/prompt/first_frame_image/last_frame_image/subject_reference）
        $cleanInput = array_intersect_key($originalInput, array_flip(['model','prompt','first_frame_image','last_frame_image','subject_reference']));
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $video_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $key,
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($cleanInput),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_TCP_KEEPIDLE => 30,
        ]);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($error) { $last_error = $error; continue; }
        if ($http_code === 429) { $last_error = "Key $idx rate limited"; continue; }
        $result = ['http_code' => $http_code, 'body' => $response];
        break;
    }
    if ($result) {
        $bodyData = json_decode($result['body'], true);
        $output = $bodyData ?: ['raw' => $result['body']];
        $output['_http_code'] = $result['http_code'];
        file_put_contents("$VIDEO_TASK_DIR/$taskId.result", json_encode($output));
        @unlink($paramFile);
        echo json_encode(['status' => 'completed']);
    } else {
        file_put_contents("$VIDEO_TASK_DIR/$taskId.result", json_encode([
            'error' => 'proxy_all_keys_exhausted',
            'message' => '所有 API Key 均已耗尽: ' . $last_error,
        ]));
        echo json_encode(['status' => 'failed', 'error' => $last_error]);
    }
    exit;
}

// 读取视频任务参数
if ($api_path === '/video_read_params') {
    header('Content-Type: application/json');
    $taskId = $_GET['task_id'] ?? $input['task_id'] ?? '';
    if (!$taskId || !preg_match('/^[a-f0-9]{16}$/', $taskId)) {
        echo json_encode(['error' => 'invalid_task_id']);
        exit;
    }
    $paramFile = "$VIDEO_TASK_DIR/$taskId.params";
    if (!file_exists($paramFile)) {
        echo json_encode(['error' => 'task_not_found']);
        exit;
    }
    $originalInput = unserialize(file_get_contents($paramFile));
    echo json_encode([
        'task_id' => $taskId,
        'params' => $originalInput,
        'provider' => $provider_name,
        'api_path' => '/video_generation',
    ]);
    exit;
}

// 写入视频任务结果
if ($api_path === '/video_write_result') {
    header('Content-Type: application/json');
    $taskId = $input['task_id'] ?? $_GET['task_id'] ?? '';
    if (!$taskId || !preg_match('/^[a-f0-9]{16}$/', $taskId)) {
        echo json_encode(['error' => 'invalid_task_id']);
        exit;
    }
    $resultData = $input['result'] ?? [];
    if (empty($resultData)) {
        echo json_encode(['error' => 'missing_result']);
        exit;
    }
    file_put_contents("$VIDEO_TASK_DIR/$taskId.result", json_encode($resultData));
    @unlink("$VIDEO_TASK_DIR/$taskId.params");
    echo json_encode(['status' => 'saved']);
    exit;
}

// 获取视频供应商配置
if ($api_path === '/video_get_provider') {
    header('Content-Type: application/json');
    $allowedIPs = ['__YOUR_SERVER_IP__'];
    $remoteIP = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($remoteIP, $allowedIPs)) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden', 'message' => '仅允许内部服务器调用']);
        exit;
    }
    if (!isset($PROVIDERS['minimax'])) {
        echo json_encode(['error' => 'provider_not_found']);
        exit;
    }
    $provider = $PROVIDERS['minimax'];
    echo json_encode([
        'base_url' => $provider['base_url'],
        'keys' => $provider['keys'],
    ]);
    exit;
}

// ═══════════════════════════════════════
// MEMORY WORKER FUNCTIONS (merged)
// ═══════════════════════════════════════
function processExtractFacts(string $userId, array $payload, string $memoryDir, PDO $pdo): void {
    $messages = $payload['messages'] ?? [];
    $sceneId = $payload['scene_id'] ?? 'scene_default';
    if (empty($messages)) return;
    
    // 只取前5条消息（避免长对话超时）
    $sampleMessages = array_slice($messages, 0, 5);
    
    // 构造提取 prompt
    $prompt = "从以下对话中提取原子级事实（即客观的、独立的知识点）。";
    $prompt .= "请以 JSON 格式输出，key 为 'facts'，值为对象数组，每个对象包含 'fact'(字符串)、'category'(字符串, 可选: credential/decision/constraint/preference/event/knowledge/contact)、'importance'(1-10整数)。";
    $prompt .= "\n\n对话内容:\n" . json_encode($sampleMessages, JSON_UNESCAPED_UNICODE);
    
    $response = callMemoryLLM([
        ['role' => 'user', 'content' => $prompt]
    ]);
    
    $result = json_decode($response, true);
    $facts = $result['facts'] ?? [];
    if (empty($facts)) {
        // 如果LLM返回为空，尝试从非JSON格式提取
        return;
    }
    
    $factsFilePath = $memoryDir . '/L1_facts.jsonl';
    $stored = 0;
    
    foreach ($facts as $fact) {
        $factText = is_array($fact) ? ($fact['fact'] ?? '') : $fact;
        if (empty($factText) || strlen($factText) < 5) continue;
        
        $hash = md5($factText);
        $stmt = $pdo->prepare("SELECT id FROM memory_index WHERE user_id=? AND fact_hash=?");
        $stmt->execute([$userId, $hash]);
        if ($stmt->fetch()) continue; // 去重
        
        $encrypted = encryptFact($factText, $userId);
        $factId = 'fact_' . date('Ymd') . '_' . str_pad(++$stored, 3, '0', STR_PAD_LEFT);
        $category = is_array($fact) ? ($fact['category'] ?? 'knowledge') : 'knowledge';
        // 访客模式：不存储敏感类别记忆（密码/账户/API Key 等凭据）
        if (strpos($userId, 'guest_') === 0 && $category === 'credential') {
            continue;
        }
        $importance = is_array($fact) ? (int)($fact['importance'] ?? 5) : 5;
        
        $record = [
            'id' => $factId,
            'hash' => $hash,
            'category' => $category,
            'importance' => $importance,
            'l2_scene_id' => $sceneId,
            'encrypted' => $encrypted,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        
        if (!safeAppendJSONL($factsFilePath, $record)) continue;
        
        $preview = mb_substr($factText, 0, 255);
        $stmt = $pdo->prepare(
            "INSERT INTO memory_index (user_id, fact_id, fact_hash, fact_preview, category, l2_scene_id, importance) 
             VALUES (?,?,?,?,?,?,?)"
        );
        $stmt->execute([$userId, $factId, $hash, $preview, $category, $sceneId, $importance]);
    }
    
    // 更新场景记忆计数
    $sceneFile = $memoryDir . "/L2_scenes/{$sceneId}.json";
    if (file_exists($sceneFile)) {
        $sceneData = json_decode(file_get_contents($sceneFile), true);
        $sceneData['memory_count'] = ($sceneData['memory_count'] ?? 0) + $stored;
        $sceneData['updated_at'] = date('Y-m-d H:i:s');
        file_put_contents($sceneFile, json_encode($sceneData));
    }
    
    // 检查是否需要触发画像更新（每30条新事实更新一次）
    $stmt = $pdo->query("SELECT COUNT(*) FROM memory_index WHERE user_id='" . $pdo->quote($userId) . "'");
    $total = (int)$stmt->fetchColumn();
    $personaFile = $memoryDir . '/L3_persona.json';
    $currentCount = 0;
    if (file_exists($personaFile)) {
        $pdata = json_decode(file_get_contents($personaFile), true);
        $currentCount = (int)($pdata['fact_count'] ?? 0);
    }
    if ($total - $currentCount >= 30) {
        $stmt = $pdo->prepare("INSERT INTO memory_tasks (user_id, task_type) VALUES (?, 'update_persona')");
        $stmt->execute([$userId]);
    }
}

/**
 * 更新用户画像
 */
function processUpdatePersona(string $userId, string $memoryDir, PDO $pdo): void {
    $factsFile = $memoryDir . '/L1_facts.jsonl';
    if (!file_exists($factsFile)) return;
    
    $lines = file($factsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $recentFacts = [];
    for ($i = count($lines) - 1; $i >= 0 && count($recentFacts) < 100; $i--) {
        $rec = json_decode($lines[$i], true);
        if ($rec) {
            try {
                $recentFacts[] = decryptFact($rec['encrypted'], $userId);
            } catch (Exception $e) { error_log('proxy.php: '.$e->getMessage()); }
        }
    }
    
    $personaFile = $memoryDir . '/L3_persona.json';
    $existingTraits = '';
    if (file_exists($personaFile)) {
        $existing = json_decode(file_get_contents($personaFile), true);
        $existingTraits = $existing['traits'] ?? '';
    }
    
    $prompt = "基于以下用户回忆生成/更新用户画像。";
    $prompt .= "输出 JSON，包含 'traits'（性格特征描述）和 'structured'（结构化标签数组）。";
    $prompt .= "\n\n现有画像: {$existingTraits}";
    $prompt .= "\n\n近期事实:\n" . implode("\n", $recentFacts);
    
    $response = callMemoryLLM([
        ['role' => 'user', 'content' => $prompt]
    ]);
    
    $persona = json_decode($response, true);
    $personaData = [
        'user_id' => $userId,
        'traits' => $persona['traits'] ?? $existingTraits,
        'structured' => $persona['structured'] ?? [],
        'last_scene_id' => 'scene_default',
        'fact_count' => count($lines),
        'updated_at' => date('Y-m-d H:i:s'),
    ];
    file_put_contents($personaFile, json_encode($personaData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

/**
 * 搜索记忆（可直接调用）
 */
function searchMemories(string $userId, string $query, PDO $pdo, string $memoryDir, int $topN = 10): array {
    if (empty($query)) return [];
    
    $k = 60;
    $stmt = $pdo->prepare(
        "SELECT id, fact_id, fact_preview, category, importance, access_count, 
         UNIX_TIMESTAMP(created_at) as created_ts,
         MATCH(fact_preview) AGAINST(:q IN NATURAL LANGUAGE MODE) as text_score 
         FROM memory_index 
         WHERE user_id=:uid AND MATCH(fact_preview) AGAINST(:q IN NATURAL LANGUAGE MODE) > 0 
         ORDER BY text_score DESC 
         LIMIT 100"
    );
    $stmt->execute(['q' => $query, 'uid' => $userId]);
    $candidates = $stmt->fetchAll();
    if (empty($candidates)) return [];
    
    // RRF 排序
    $textRank = [];
    foreach ($candidates as $i => $c) $textRank[$c['id']] = $i + 1;
    
    $lambda = log(2) / (7 * 86400);
    $now = time();
    $timeScores = [];
    foreach ($candidates as $c) $timeScores[$c['id']] = exp(-$lambda * max($now - $c['created_ts'], 0));
    arsort($timeScores);
    $timeRank = [];
    $pos = 1;
    foreach (array_keys($timeScores) as $id) $timeRank[$id] = $pos++;
    
    usort($candidates, fn($a, $b) => $b['access_count'] <=> $a['access_count']);
    $popRank = [];
    foreach ($candidates as $i => $c) $popRank[$c['id']] = $i + 1;
    
    $weights = ['credential' => 0.8, 'decision' => 1.2, 'constraint' => 1.3, 'preference' => 1.0, 'event' => 0.8, 'knowledge' => 0.7, 'contact' => 1.1];
    
    $rrfScores = [];
    foreach ($candidates as $c) {
        $id = $c['id'];
        $score = 0;
        if (isset($textRank[$id])) $score += 1 / ($k + $textRank[$id]);
        if (isset($timeRank[$id])) $score += 1 / ($k + $timeRank[$id]);
        if (isset($popRank[$id])) $score += 1 / ($k + $popRank[$id]);
        $catWeight = $weights[$c['category']] ?? 1.0;
        $score *= $catWeight;
        $rrfScores[$id] = ['score' => $score, 'fact_id' => $c['fact_id'], 'category' => $c['category'], 'importance' => $c['importance']];
    }
    uasort($rrfScores, fn($a, $b) => $b['score'] <=> $a['score']);
    $top = array_slice($rrfScores, 0, $topN, true);
    
    // 从 JSONL 解密获取完整内容
    $factsFilePath = $memoryDir . '/L1_facts.jsonl';
    $results = [];
    if (file_exists($factsFilePath)) {
        $lines = file($factsFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $factMap = [];
        foreach ($lines as $line) {
            $rec = json_decode($line, true);
            if ($rec) $factMap[$rec['id']] = $rec;
        }
        foreach ($top as $indexId => $rd) {
            $fid = $rd['fact_id'];
            if (isset($factMap[$fid])) {
                try {
                    $decrypted = decryptFact($factMap[$fid]['encrypted'], $userId);
                    $results[] = [
                        'id' => $fid,
                        'fact' => $decrypted,
                        'category' => $rd['category'],
                        'importance' => $rd['importance'],
                        'rrf_score' => round($rd['score'], 4),
                        'scene_id' => $factMap[$fid]['l2_scene_id'] ?? null,
                    ];
                } catch (Exception $e) { error_log('proxy.php: '.$e->getMessage()); }
            }
        }
    }
    
    // 更新访问计数
    if (!empty($top)) {
        $ids = array_keys($top);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE memory_index SET access_count = access_count + 1, last_accessed_at = NOW() WHERE id IN ($placeholders)")->execute($ids);
    }
    
    return $results;
}
// ═══════════════════════════════════════
// ⑧ 记忆系统后台任务处理（Hermes Cron 驱动）
// ═══════════════════════════════════════

// 列出待处理记忆任务（仅限内部服务器调用 + 有效 token）
if ($api_path === '/memory_pending') {
    header('Content-Type: application/json');
    // 支持两种认证方式：内部IP白名单 或 有效AccessToken
    $remoteIP = $_SERVER['REMOTE_ADDR'] ?? '';
    $allowedIPs = ['__YOUR_SERVER_IP__'];
    if (!in_array($remoteIP, $allowedIPs) && $token !== ACCESS_TOKEN) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden']);
        exit;
    }
    
    $pdo = getMemoryDB();
    $stmt = $pdo->query("SELECT id FROM memory_tasks WHERE status='pending' ORDER BY created_at ASC LIMIT 10");
    $pending = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode(['pending' => $pending, 'count' => count($pending)]);
    exit;
}

// 处理一个记忆任务（仅限内部服务器调用 + 有效 token）
if ($api_path === '/memory_process') {
    header('Content-Type: application/json');
    $remoteIP = $_SERVER['REMOTE_ADDR'] ?? '';
    $allowedIPs = ['__YOUR_SERVER_IP__'];
    if (!in_array($remoteIP, $allowedIPs) && $token !== ACCESS_TOKEN) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden']);
        exit;
    }
    $taskId = $input['task_id'] ?? $_GET['task_id'] ?? '';
    if (!$taskId || !is_numeric($taskId)) {
        echo json_encode(['error' => 'invalid_task_id']);
        exit;
    }
    
    
    $pdo = getMemoryDB();
    
    $stmt = $pdo->prepare("SELECT * FROM memory_tasks WHERE id=? AND status='pending'");
    $stmt->execute([$taskId]);
    $task = $stmt->fetch();
    if (!$task) {
        echo json_encode(['error' => 'task_not_found_or_not_pending']);
        exit;
    }
    
    // 标记为处理中
    $pdo->prepare("UPDATE memory_tasks SET status='processing', updated_at=NOW() WHERE id=?")->execute([$taskId]);
    
    try {
        $payload = json_decode($task['payload'], true) ?: [];
        $memoryDir = getMemoryDir($task['user_id']);
        
        if ($task['task_type'] === 'extract_facts') {
            processExtractFacts($task['user_id'], $payload, $memoryDir, $pdo);
        } elseif ($task['task_type'] === 'update_persona') {
            processUpdatePersona($task['user_id'], $memoryDir, $pdo);
        } else {
            throw new Exception('Unknown task type: ' . $task['task_type']);
        }
        
        $pdo->prepare("UPDATE memory_tasks SET status='done', updated_at=NOW() WHERE id=?")->execute([$taskId]);
        echo json_encode(['status' => 'completed', 'task_id' => $taskId]);
    } catch (Exception $e) {
        $retryCount = $task['retry_count'] + 1;
        if ($retryCount >= 3) {
            $pdo->prepare("UPDATE memory_tasks SET status='failed', retry_count=?, error_message=? WHERE id=?")
                ->execute([$retryCount, $e->getMessage(), $taskId]);
            echo json_encode(['status' => 'failed', 'error' => $e->getMessage()]);
        } else {
            $pdo->prepare("UPDATE memory_tasks SET status='pending', retry_count=?, error_message=? WHERE id=?")
                ->execute([$retryCount, 'Retry: ' . $e->getMessage(), $taskId]);
            echo json_encode(['status' => 'retry', 'retry_count' => $retryCount, 'error' => $e->getMessage()]);
        }
    }
    exit;
}

// 获取 LLM Provider 配置（供 cron worker 本地调用 LLM）
if ($api_path === '/memory_get_provider') {
    header('Content-Type: application/json');
    $remoteIP = $_SERVER['REMOTE_ADDR'] ?? '';
    $allowedIPs = ['__YOUR_SERVER_IP__'];
    if (!in_array($remoteIP, $allowedIPs) && $token !== ACCESS_TOKEN) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden']);
        exit;
    }
    $keys = isset($PROVIDERS['opencode-go']['keys']) ? $PROVIDERS['opencode-go']['keys'] : [];
    echo json_encode([
        'base_url' => 'https://opencode.ai/zen/go/v1',
        'keys' => $keys,
    ]);
    exit;
}

// 读取记忆任务的参数（payload 中的 messages）
if ($api_path === '/memory_read_params') {
    header('Content-Type: application/json');
    $remoteIP = $_SERVER['REMOTE_ADDR'] ?? '';
    $allowedIPs = ['__YOUR_SERVER_IP__'];
    if (!in_array($remoteIP, $allowedIPs) && $token !== ACCESS_TOKEN) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden']);
        exit;
    }
    $taskId = $input['task_id'] ?? $_GET['task_id'] ?? '';
    if (!$taskId || !is_numeric($taskId)) {
        echo json_encode(['error' => 'invalid_task_id']);
        exit;
    }
    $pdo = getMemoryDB();
    $stmt = $pdo->prepare("SELECT user_id, payload FROM memory_tasks WHERE id=?");
    $stmt->execute([$taskId]);
    $task = $stmt->fetch();
    if (!$task) {
        echo json_encode(['error' => 'task_not_found']);
        exit;
    }
    $payload = json_decode($task['payload'], true) ?: [];
    echo json_encode([
        'user_id' => $task['user_id'],
        'payload' => $payload,
    ]);
    exit;
}

// 写入记忆提取结果
if ($api_path === '/memory_write_result') {
    header('Content-Type: application/json');
    $remoteIP = $_SERVER['REMOTE_ADDR'] ?? '';
    $allowedIPs = ['__YOUR_SERVER_IP__'];
    if (!in_array($remoteIP, $allowedIPs) && $token !== ACCESS_TOKEN) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden']);
        exit;
    }
    $taskId = $input['task_id'] ?? '';
    $userId = $input['user_id'] ?? '';
    $incomingFacts = $input['facts'] ?? [];
    $sceneId = $input['scene_id'] ?? 'scene_default';
    if (empty($taskId) || empty($userId) || !is_array($incomingFacts)) {
        echo json_encode(['error' => 'missing_params']);
        exit;
    }
    $memoryDir = getMemoryDir($userId);
    if (!is_dir($memoryDir)) {
        echo json_encode(['error' => 'user_dir_not_found']);
        exit;
    }
    $pdo = getMemoryDB();
    $factsFilePath = $memoryDir . '/L1_facts.jsonl';
    $stored = 0;
    foreach ($incomingFacts as $fact) {
        $factText = is_array($fact) ? ($fact['fact'] ?? '') : $fact;
        if (empty($factText) || strlen($factText) < 5) continue;
        $hash = md5($factText);
        $stmt = $pdo->prepare("SELECT id FROM memory_index WHERE user_id=? AND fact_hash=?");
        $stmt->execute([$userId, $hash]);
        if ($stmt->fetch()) continue;
        $encrypted = encryptFact($factText, $userId);
        $factId = 'fact_' . date('Ymd') . '_' . str_pad(++$stored, 3, '0', STR_PAD_LEFT);
        $category = is_array($fact) ? ($fact['category'] ?? 'knowledge') : 'knowledge';
        if (strpos($userId, 'guest_') === 0 && $category === 'credential') continue;
        $importance = is_array($fact) ? (int)($fact['importance'] ?? 5) : 5;
        $record = [
            'id' => $factId, 'hash' => $hash, 'category' => $category,
            'importance' => $importance, 'l2_scene_id' => $sceneId,
            'encrypted' => $encrypted, 'created_at' => date('Y-m-d H:i:s'),
        ];
        if (!safeAppendJSONL($factsFilePath, $record)) continue;
        $preview = mb_substr($factText, 0, 255);
        $stmt = $pdo->prepare(
            "INSERT INTO memory_index (user_id, fact_id, fact_hash, fact_preview, category, l2_scene_id, importance) 
             VALUES (?,?,?,?,?,?,?)"
        );
        $stmt->execute([$userId, $factId, $hash, $preview, $category, $sceneId, $importance]);
    }
    // 更新场景计数
    $sceneFile = $memoryDir . "/L2_scenes/{$sceneId}.json";
    if ($stored > 0 && file_exists($sceneFile)) {
        $sceneData = json_decode(file_get_contents($sceneFile), true);
        $sceneData['memory_count'] = ($sceneData['memory_count'] ?? 0) + $stored;
        file_put_contents($sceneFile, json_encode($sceneData));
    }
    // 标记任务完成
    $pdo->prepare("UPDATE memory_tasks SET status='done', updated_at=NOW() WHERE id=?")
        ->execute([$taskId]);
    echo json_encode(['status' => 'completed', 'facts_stored' => $stored, 'task_id' => $taskId]);
    exit;
}

// ── 轮询尝试各密钥 ──
$last_error = '';
foreach ($api_keys as $idx => $key) {
    if (empty($key)) {
        $last_error = "Key " . ($idx + 1) . " is empty (placeholder)";
        continue;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $target_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
        ],
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($input));
    } elseif ($method === 'GET') {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        $last_error = $error;
        continue;
    }

    if ($http_code === 429) {
        $last_error = "Key " . ($idx + 1) . " rate limited";
        continue;
    }

    // 成功
    http_response_code($http_code);
    echo $response;
    exit;
}

// ── 所有密钥都失败 ──
http_response_code(503);
echo json_encode([
    'error' => 'proxy_all_keys_exhausted',
    'message' => '所有 API Key 均已耗尽或代理请求失败: ' . $last_error,
    'available_keys' => count($api_keys),
    'provider' => $provider_name,
]);

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
    // 使用轮换状态获取当前活跃密钥
    $keys = [];
    if (defined('ROTATION_GROUPS')) {
        $rotGroups = @unserialize(ROTATION_GROUPS);
        if (is_array($rotGroups) && isset($rotGroups['opencode-go'])) {
            $startIdx = getRotationStartIndex('opencode-go');
            $expanded = expandRotationKeys($rotGroups['opencode-go'], $startIdx, $PROVIDERS);
            if (!empty($expanded)) $keys = $expanded;
        }
    }
    // 回退：硬编码链
    if (empty($keys)) {
        $keys = $PROVIDERS['opencode-go']['keys'] ?? [];
        if (empty($keys) || empty($keys[0])) $keys = $PROVIDERS['opencode-go1']['keys'] ?? [];
        if (empty($keys) || empty($keys[0])) $keys = $PROVIDERS['opencode-go2']['keys'] ?? [];
        if (empty($keys) || empty($keys[0])) $keys = $PROVIDERS['opencode-go3']['keys'] ?? [];
        if (empty($keys) || empty($keys[0])) $keys = $PROVIDERS['opencode-go4']['keys'] ?? [];
    }
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

// ═══════════════════════════════════════
// Provider 密钥轮换状态管理
// ═══════════════════════════════════════

/**
 * 读取密钥轮换状态
 */
function getRotationState(): array {
    $default = [];
    $file = defined('ROTATION_STATE_FILE') ? ROTATION_STATE_FILE : '/vhost/tmp/provider_rotation.json';
    if (!file_exists($file)) return $default;
    $data = @json_decode(@file_get_contents($file), true);
    return is_array($data) ? $data : $default;
}

/**
 * 保存密钥轮换状态
 */
function saveRotationState(array $state): void {
    $file = defined('ROTATION_STATE_FILE') ? ROTATION_STATE_FILE : '/vhost/tmp/provider_rotation.json';
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
}

/**
 * 获取轮换组的起始索引（从状态文件中读取，不存在则返回 0）
 */
function getRotationStartIndex(string $groupName): int {
    $state = getRotationState();
    return isset($state[$groupName]['current']) ? (int)$state[$groupName]['current'] : 0;
}

/**
 * 将轮换组内所有 provider 的密钥扁平化为一个数组，
 * 从起始索引开始，循环遍历所有成员
 */
function expandRotationKeys(array $group, int $startIdx, array &$PROVIDERS): array {
    $expanded = [];
    $total = count($group);
    for ($i = 0; $i < $total; $i++) {
        $memberName = $group[($startIdx + $i) % $total];
        $memberKeys = isset($PROVIDERS[$memberName]) ? $PROVIDERS[$memberName]['keys'] : [];
        foreach ($memberKeys as $mk) {
            if (!empty($mk)) $expanded[] = $mk;
        }
    }
    return $expanded;
}

// ═══════════════════════════════════════
// API Key 429 限流冷却系统（防止齐发429假性耗尽）
// ═══════════════════════════════════════
define('COOLDOWN_FILE', '/vhost/tmp/api_key_cooldown.json');

/**
 * 获取所有 key 的冷却状态
 */
function getKeyCooldowns(): array {
    if (!file_exists(COOLDOWN_FILE)) return [];
    $data = @file_get_contents(COOLDOWN_FILE);
    $cooldowns = @json_decode($data, true);
    return is_array($cooldowns) ? $cooldowns : [];
}

/**
 * 保存 key 冷却状态
 */
function saveKeyCooldowns(array $cooldowns): void {
    $dir = dirname(COOLDOWN_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    file_put_contents(COOLDOWN_FILE, json_encode($cooldowns), LOCK_EX);
}

/**
 * 检查 key 是否在冷却期内（429 后 30 秒跳过，防止假性全部耗尽）
 * 冷却过期后自动清理
 */
function isKeyInCooldown(string $key): bool {
    $hash = md5($key);
    $cooldowns = getKeyCooldowns();
    if (!isset($cooldowns[$hash])) return false;
    if (time() >= $cooldowns[$hash]) {
        unset($cooldowns[$hash]);
        saveKeyCooldowns($cooldowns);
        return false;
    }
    return true;
}

/**
 * 将 key 标记为冷却状态（429 后在此冷却期内跳过，不再重复请求）
 */
function markKeyCooldown(string $key, int $seconds = 30): void {
    $hash = md5($key);
    $cooldowns = getKeyCooldowns();
    $cooldowns[$hash] = time() + $seconds;
    saveKeyCooldowns($cooldowns);
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
            // 跳过已有 .result（已完成）或 .processing（正在处理中）的任务
            if (file_exists("$taskDir/$id.result")) continue;
            if (file_exists("$taskDir/$id.processing")) {
                // 检查 processing 文件是否超时（超过 5 分钟视为 worker 已崩溃）
                $age = time() - filemtime("$taskDir/$id.processing");
                if ($age < 300) continue;
                // 超时 → 删除 stale processing 锁，允许重新处理
                @unlink("$taskDir/$id.processing");
            }
            // 检查是否超时（超过 30 分钟未处理则忽略）
            $age = time() - filemtime($paramFile);
            if ($age < 1800) {
                $pending[] = $id;
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

        // 检查 429 冷却期
        if (isKeyInCooldown($key)) {
            $last_error = "Key $idx in cooldown (429 backoff 30s)";
            continue;
        }

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
        if ($http_code === 429) {
            markKeyCooldown($key, 30);
            $last_error = "Key $idx rate limited (cooldown 30s)";
            continue;
        }

        $result = ['http_code' => $http_code, 'body' => $response];
        break;
    }

    if ($result) {
        $bodyData = json_decode($result['body'], true);
        $output = $bodyData ?: ['raw' => $result['body']];
        $output['_http_code'] = $result['http_code'];
        file_put_contents("$taskDir/$taskId.result", json_encode($output));
        @unlink($paramFile);
        @unlink("$taskDir/$taskId.processing");
        echo json_encode(['status' => 'completed']);
    } else {
        file_put_contents("$taskDir/$taskId.result", json_encode([
            'error' => 'proxy_all_keys_exhausted',
            'message' => '所有 API Key 均已耗尽: ' . $last_error,
        ]));
        @unlink("$taskDir/$taskId.processing");
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
    $taskDir = '/vhost/tmp/music_tasks';
    $paramFile = "$taskDir/$taskId.params";
    if (!file_exists($paramFile)) {
        echo json_encode(['error' => 'task_not_found']);
        exit;
    }
    // 原子预留：创建 .processing 文件（标记任务已被 Worker 认领）
    $processingFile = "$taskDir/$taskId.processing";
    $processingFh = @fopen($processingFile, 'x'); // 'x' = 创建并独占写入，文件已存在则失败
    if ($processingFh === false) {
        // .processing 已存在 → 检查是否超时
        if (file_exists($processingFile)) {
            $age = time() - filemtime($processingFile);
            if ($age < 300) {
                echo json_encode(['error' => 'task_already_processing']);
                exit;
            }
            // 超时 → 覆盖旧锁
        }
        $processingFh = fopen($processingFile, 'w');
    }
    fwrite($processingFh, (string)getmypid());
    fclose($processingFh);

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
    @unlink("$taskDir/$taskId.processing"); // 释放原子预留锁
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
    $minimaxKeys = $provider['keys'];
    // 应用每日轮换起点（7:00 轮换后优先使用下一个 key）
    $minimaxIdx = getRotationStartIndex('minimax');
    if ($minimaxIdx > 0 && count($minimaxKeys) > 1) {
        $reordered = [];
        $total = count($minimaxKeys);
        for ($i = 0; $i < $total; $i++) {
            $reordered[] = $minimaxKeys[($minimaxIdx + $i) % $total];
        }
        $minimaxKeys = $reordered;
    }
    echo json_encode([
        'base_url' => $provider['base_url'],
        'keys' => $minimaxKeys,
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
            // 跳过已有 .result（已完成）或 .processing（正在处理中）的任务
            if (file_exists("$VIDEO_TASK_DIR/$id.result")) continue;
            if (file_exists("$VIDEO_TASK_DIR/$id.processing")) {
                // 检查 processing 文件是否超时（超过 5 分钟视为 worker 已崩溃）
                $age = time() - filemtime("$VIDEO_TASK_DIR/$id.processing");
                if ($age < 300) continue;
                // 超时 → 删除 stale processing 锁，允许重新处理
                @unlink("$VIDEO_TASK_DIR/$id.processing");
            }
            $age = time() - filemtime($paramFile);
            if ($age < 1800) {
                $pending[] = $id;
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

        // 检查 429 冷却期
        if (isKeyInCooldown($key)) {
            $last_error = "Key $idx in cooldown (429 backoff 30s)";
            continue;
        }

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
        if ($http_code === 429) {
            markKeyCooldown($key, 30);
            $last_error = "Key $idx rate limited (cooldown 30s)";
            continue;
        }
        $result = ['http_code' => $http_code, 'body' => $response];
        break;
    }
    if ($result) {
        $bodyData = json_decode($result['body'], true);
        $output = $bodyData ?: ['raw' => $result['body']];
        $output['_http_code'] = $result['http_code'];
        file_put_contents("$VIDEO_TASK_DIR/$taskId.result", json_encode($output));
        @unlink($paramFile);
        @unlink("$VIDEO_TASK_DIR/$taskId.processing");
        echo json_encode(['status' => 'completed']);
    } else {
        file_put_contents("$VIDEO_TASK_DIR/$taskId.result", json_encode([
            'error' => 'proxy_all_keys_exhausted',
            'message' => '所有 API Key 均已耗尽: ' . $last_error,
        ]));
        @unlink("$VIDEO_TASK_DIR/$taskId.processing");
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
    // 原子预留：创建 .processing 文件（标记任务已被 Worker 认领）
    $processingFile = "$VIDEO_TASK_DIR/$taskId.processing";
    $processingFh = @fopen($processingFile, 'x'); // 'x' = 创建并独占写入，文件已存在则失败
    if ($processingFh === false) {
        // .processing 已存在 → 检查是否超时
        if (file_exists($processingFile)) {
            $age = time() - filemtime($processingFile);
            if ($age < 300) {
                echo json_encode(['error' => 'task_already_processing']);
                exit;
            }
            // 超时 → 覆盖旧锁
        }
        $processingFh = fopen($processingFile, 'w');
    }
    fwrite($processingFh, (string)getmypid());
    fclose($processingFh);

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
    @unlink("$VIDEO_TASK_DIR/$taskId.processing"); // 释放原子预留锁
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
    $minimaxKeys = $provider['keys'];
    // 应用每日轮换起点（7:00 轮换后优先使用下一个 key）
    $minimaxIdx = getRotationStartIndex('minimax');
    if ($minimaxIdx > 0 && count($minimaxKeys) > 1) {
        $reordered = [];
        $total = count($minimaxKeys);
        for ($i = 0; $i < $total; $i++) {
            $reordered[] = $minimaxKeys[($minimaxIdx + $i) % $total];
        }
        $minimaxKeys = $reordered;
    }
    echo json_encode([
        'base_url' => $provider['base_url'],
        'keys' => $minimaxKeys,
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
    // 先恢复超时的 processing 任务（worker 已崩溃超过 5 分钟）
    $pdo->exec("UPDATE memory_tasks SET status='pending', updated_at=NOW() WHERE status='processing' AND updated_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
    // 列出待处理任务
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
    // 使用轮换状态确定当前活跃的 opencode-go provider
    $activeKeys = [];
    if (defined('ROTATION_GROUPS')) {
        $rotGroups = @unserialize(ROTATION_GROUPS);
        if (is_array($rotGroups) && isset($rotGroups['opencode-go'])) {
            $startIdx = getRotationStartIndex('opencode-go');
            $expanded = expandRotationKeys($rotGroups['opencode-go'], $startIdx, $PROVIDERS);
            if (!empty($expanded)) {
                $activeKeys = $expanded;
            }
        }
    }
    // 回退：硬编码链（兜底）
    if (empty($activeKeys)) {
        $activeKeys = isset($PROVIDERS['opencode-go']['keys']) ? $PROVIDERS['opencode-go']['keys'] : [];
        if (empty($activeKeys)) $activeKeys = isset($PROVIDERS['opencode-go1']['keys']) ? $PROVIDERS['opencode-go1']['keys'] : [];
        if (empty($activeKeys)) $activeKeys = isset($PROVIDERS['opencode-go2']['keys']) ? $PROVIDERS['opencode-go2']['keys'] : [];
        if (empty($activeKeys)) $activeKeys = isset($PROVIDERS['opencode-go3']['keys']) ? $PROVIDERS['opencode-go3']['keys'] : [];
        if (empty($activeKeys)) $activeKeys = isset($PROVIDERS['opencode-go4']['keys']) ? $PROVIDERS['opencode-go4']['keys'] : [];
    }
    echo json_encode([
        'base_url' => 'https://opencode.ai/zen/go/v1',
        'keys' => $activeKeys,
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
    // 原子抢锁：只认领 status='pending' 的任务，避免重复处理
    $stmt = $pdo->prepare("UPDATE memory_tasks SET status='processing', updated_at=NOW() WHERE id=? AND status='pending'");
    $stmt->execute([$taskId]);
    if ($stmt->rowCount() === 0) {
        echo json_encode(['error' => 'task_not_pending_or_already_processing']);
        exit;
    }
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

// ═══════════════════════════════════════
// ⑨ MUD 游戏对话历史记录
// ═══════════════════════════════════════
function updateMudHistory(string $userMsg, string $assistantMsg): void {
    if (session_status() === PHP_SESSION_NONE) @session_start();
    $userId = $_SESSION['user'] ?? 'guest';
    $safeId = preg_replace('/[^a-zA-Z0-9_]/', '_', $userId);
    $dir = __DIR__ . '/users/' . $safeId . '/mud/穿越当宰相/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $histFile = $dir . 'chat_history.jsonl';
    $entry = json_encode([
        'user' => $userMsg,
        'assistant' => $assistantMsg,
        'time' => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE);
    @file_put_contents($histFile, $entry . "\n", FILE_APPEND | LOCK_EX);
}

// ═══════════════════════════════════════
// ⑩ Provider 密钥轮换端点（供 cron 每日 7:00 调用）
// ═══════════════════════════════════════

/**
 * /rotate_provider — 手动触发或 cron 触发密钥轮换
 * 
 * 请求: POST {"_token":"xxx","_path":"/rotate_provider","group":"opencode-go"}
 *       group 可选：opencode-go | minimax | all
 * 
 * 效果：将指定轮换组的 current 索引 +1（循环）
 */
if ($api_path === '/rotate_provider') {
    header('Content-Type: application/json');
    $groupName = $input['group'] ?? 'all';
    
    if (!defined('ROTATION_GROUPS')) {
        echo json_encode(['error' => 'rotation_not_configured']);
        exit;
    }
    
    $groups = unserialize(ROTATION_GROUPS);
    if (!is_array($groups)) {
        echo json_encode(['error' => 'invalid_rotation_config']);
        exit;
    }
    
    $state = getRotationState();
    $rotated = [];
    
    if ($groupName === 'all') {
        // 轮换所有组
        foreach ($groups as $gName => $gMembers) {
            $current = isset($state[$gName]['current']) ? (int)$state[$gName]['current'] : 0;
            $newIdx = ($current + 1) % count($gMembers);
            $state[$gName] = ['current' => $newIdx, 'rotated_at' => date('Y-m-d H:i:s')];
            $rotated[] = ['group' => $gName, 'from' => $current, 'to' => $newIdx];
        }
    } elseif (isset($groups[$groupName])) {
        $current = isset($state[$groupName]['current']) ? (int)$state[$groupName]['current'] : 0;
        $newIdx = ($current + 1) % count($groups[$groupName]);
        $state[$groupName] = ['current' => $newIdx, 'rotated_at' => date('Y-m-d H:i:s')];
        $rotated[] = ['group' => $groupName, 'from' => $current, 'to' => $newIdx];
    } else {
        echo json_encode(['error' => 'unknown_group', 'available' => array_keys($groups)]);
        exit;
    }
    
    saveRotationState($state);
    echo json_encode(['success' => true, 'rotated' => $rotated]);
    exit;
}

/**
 * /rotation_status — 查看当前轮换状态
 */
if ($api_path === '/rotation_status') {
    header('Content-Type: application/json');
    $state = getRotationState();
    
    if (!defined('ROTATION_GROUPS')) {
        echo json_encode(['state' => $state, 'groups' => []]);
        exit;
    }
    
    $groups = unserialize(ROTATION_GROUPS);
    $status = [];
    foreach ($groups as $gName => $gMembers) {
        $idx = isset($state[$gName]['current']) ? (int)$state[$gName]['current'] : 0;
        $activeProvider = $gMembers[$idx % count($gMembers)];
        $status[] = [
            'group' => $gName,
            'current_index' => $idx,
            'active_provider' => $activeProvider,
            'total_members' => count($gMembers),
            'members' => $gMembers,
            'rotated_at' => $state[$gName]['rotated_at'] ?? '',
        ];
    }
    echo json_encode(['success' => true, 'groups' => $status]);
    exit;
}

// ═══════════════════════════════════════
// ── 轮询尝试各密钥（支持 Provider 轮换组扩展） ──

// 检查当前 provider 是否属于某个轮换组，如果是则展开组内全部密钥
if (defined('ROTATION_GROUPS')) {
    $rotGroups = unserialize(ROTATION_GROUPS);
    if (is_array($rotGroups) && isset($rotGroups[$provider_name])) {
        $group = $rotGroups[$provider_name];
        // 跳过同名组（如 minimax 三次重复仅用于追踪起点索引，不展开）
        if (count(array_unique($group)) > 1) {
            $startIdx = getRotationStartIndex($provider_name);
            $expanded = expandRotationKeys($group, $startIdx, $PROVIDERS);
            if (count($expanded) > 0) {
                $api_keys = $expanded;
            }
        }
    }
}

// MiniMax 内部 key 起点轮换（每日 7:00 轮换哪个 key 优先使用）
if ($provider_name === 'minimax' && count($api_keys) > 1) {
    $minimaxIdx = getRotationStartIndex('minimax');
    if ($minimaxIdx > 0) {
        $reordered = [];
        $total = count($api_keys);
        for ($i = 0; $i < $total; $i++) {
            $reordered[] = $api_keys[($minimaxIdx + $i) % $total];
        }
        $api_keys = $reordered;
    }
}

// ── MUD 模式命令拦截：检测到 MUD 命令时跳过 AI API，直接由 GameEngine 处理 ──
if ($api_path === '/chat/completions' && isset($input['messages']) && is_array($input['messages'])) {
    $lastUserMsg = '';
    foreach (array_reverse($input['messages']) as $m) {
        if (($m['role'] ?? '') === 'user') {
            $lastUserMsg = $m['content'] ?? '';
            break;
        }
    }
    // MUD 命令前缀检测（支持纯命令如 /backlog 和带参数如 /tutorial 2-3）
    // 也检测中文自然语言命令和激活短语
    $mudPatterns = [
        // 斜杠命令（中英文双语：/status 和 /状态 都拦截）
        // 注意：方向斜杠（/向北 /向南）也在此拦截，防止漏到AI叙事
        '/^\\/(backlog|tutorial|status|inventory|map|quests|save|load|help|talk|skill|状态|背包|地图|任务|存档|读档|帮助|对话|技能|返回|重新开始|拾取|捡起|拿|使用|pickup|attack|攻击|打|战斗|use|equip|装备|向北|向南|向西|向东)/u',
        // 单词命令（独立词）
        '/^(观察|查看|看|look|去|前往|move|移动|对话|talk|攻击|attack|使用|use|equip|装备|状态|属性|技能|skill|向北|向南|向西|向东|北|南|西|东|东北|西北|东南|西南|上|下|里|外|n|s|e|w|ne|nw|se|sw|north|south|east|west|northeast|northwest|southeast|southwest|拾取|捡起|拿|pickup|使用物品|查技能)$/iu',
        // 词组命令（前缀匹配，包括带参数的命令）
        '/^(观察|查看|看|look|去|前往|move|移动|走到|移动到|对话|talk|攻击|attack|使用|use|equip|装备|状态|属性|技能|skill|询问|问|找|向北|向南|向西|向东|向东北|向西北|向东南|向西南|拾取|捡起|拿|拿取|pickup|使用物品|查技能|攻击.+|attack.+|equip.+)/iu',
        // 英文移动命令
        '/^go\s+\w+/i',
        '/^move\s+\w+/i',
        '/^开始(泥巴游戏|MUD|mud)$/u',
    ];
    $isMudCommand = false;
    foreach ($mudPatterns as $pat) {
        if (preg_match($pat, trim($lastUserMsg))) { $isMudCommand = true; break; }
    }
    if ($isMudCommand) {
        // 特殊处理：激活短语不走 GameEngine，直接返回激活确认
        if (preg_match('/^\/开始(泥巴游戏|MUD|mud)$/u', trim($lastUserMsg)) ||
            preg_match('/^开始(泥巴游戏|MUD|mud)$/u', trim($lastUserMsg))) {
            $content = "🎮 MUD游戏已激活！\n输入 /help 查看可用命令，或直接说\"观察四周\"开始探索。";
        } else {
            // 初始化并调用 GameEngine
            // 去掉开头的 / 以支持 /talk、/skill 等命令
            $gameInput = preg_replace('/^\/+/', '', trim($lastUserMsg));
            $game = GameEngine::getInstance();
            $content = $game->processInput($gameInput);
            // 保存对话历史（与正常流程一致）
            updateMudHistory($lastUserMsg, $content);
        }
        // 返回标准 OpenAI Chat Completion 格式
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => $content,
                ],
                'finish_reason' => 'stop',
            ]],
            'model' => $input['model'] ?? 'game-engine',
            'object' => 'chat.completion',
        ]);
        exit;
    }
}

$last_error = '';
foreach ($api_keys as $idx => $key) {
    if (empty($key)) {
        $last_error = "Key " . ($idx + 1) . " is empty (placeholder)";
        continue;
    }

    // 检查 429 冷却期（30秒内不再重复请求该 Key）
    if (isKeyInCooldown($key)) {
        $last_error = "Key " . ($idx + 1) . " in cooldown (429 backoff 30s)";
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
        markKeyCooldown($key, 30);
        $last_error = "Key " . ($idx + 1) . " rate limited (cooldown 30s)";
        continue;
    }

    // 成功
    http_response_code($http_code);

    // ── MUD 模式：保存对话历史 ──
    if ($api_path === '/chat/completions' && isset($input['messages'])) {
        $responseData = json_decode($response, true);
        $assistantContent = $responseData['choices'][0]['message']['content'] ?? '';
        if ($assistantContent) {
            $lastUser = '';
            foreach (array_reverse($input['messages']) as $m) {
                if (($m['role'] ?? '') === 'user') {
                    $lastUser = $m['content'] ?? '';
                    break;
                }
            }
            if ($lastUser) updateMudHistory($lastUser, $assistantContent);
        }
    }

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

// ═══════════════════════════════════════
//   MUD 游戏引擎（GameEngine）
// ═══════════════════════════════════════

/**
 * 游戏状态管理类
 * 负责加载配置、解析命令、更新状态、持久化
 */

class GameEngine {
    private static $instance = null;
    private $gameData = [];
    private $gameDocs = [];   // MD参考文档
    private $state = null;
    private $dataDir = '';

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->dataDir = $this->getGameDataDir();
        $this->loadGameConfigs();
        $this->loadGameDocs();
        $this->loadGameState();
    }

    private function getGameDataDir() {
        if (session_status() === PHP_SESSION_NONE) @session_start();
        $userId = $_SESSION['user'] ?? 'guest';
        $safeId = preg_replace('/[^a-zA-Z0-9_]/', '_', $userId);
        $base = __DIR__ . '/users/' . $safeId . '/mud/穿越当宰相/';
        if (!is_dir($base)) mkdir($base, 0755, true);
        return $base;
    }

    private function loadGameConfigs() {
        $files = [
            '角色数据' => '角色数据.json',
            '技能大全' => '技能大全.json',
            '物品图鉴' => '物品图鉴.json',
            '任务系统' => '任务系统.json',
            '战斗系统' => '战斗系统.json',
            '地图数据' => '地图数据.json',
            'NPC好感度系统' => 'NPC好感度系统.json',
            '结局系统' => '结局系统.json',
            '游戏配置' => '游戏配置.json',
        ];
        // 备用路径：访客目录（游戏文件模板）
        $guestDir = __DIR__ . '/users/guest/mud/穿越当宰相/';
        foreach ($files as $key => $file) {
            // 优先从用户目录加载（个性化存档）
            $path = $this->dataDir . $file;
            if (!file_exists($path)) {
                // 回退到访客目录（游戏配置模板）
                $path = $guestDir . $file;
            }
            if (file_exists($path)) {
                $content = file_get_contents($path);
                $this->gameData[$key] = json_decode($content, true);
            }
        }
        // 默认空数组避免错误
        foreach ($files as $key => $_) {
            if (!isset($this->gameData[$key])) $this->gameData[$key] = [];
        }
    }

    // 加载MD参考文档（用于AI叙事增强）
    private function loadGameDocs() {
        $mdFiles = [
            'GM系统提示词', '世界观设定', '剧情进度', '开场白',
            '场景设定集', '对话剧本', '角色数据', '技能大全',
            '物品图鉴', '战斗系统', '游戏存档设定', '结局文案',
            '地图数据', '图生提示词', '音效规范',
        ];
        $guestDir = __DIR__ . '/users/guest/mud/穿越当宰相/';
        foreach ($mdFiles as $file) {
            $path = $this->dataDir . $file . '.md';
            if (!file_exists($path)) $path = $guestDir . $file . '.md';
            if (file_exists($path)) {
                $this->gameDocs[$file] = file_get_contents($path);
            }
        }
    }

    private function loadGameState() {
        $saveFile = $this->dataDir . 'save.json';
        if (file_exists($saveFile)) {
            $raw = file_get_contents($saveFile);
            $data = json_decode($raw, true);
            if (is_array($data)) {
                $this->state = $data;
            } else {
                // save.json 损坏，自动重置
                $this->state = null;
            }
        }
        if (!$this->state) $this->initNewGame();
    }

    private function initNewGame() {
        $config = $this->gameData['游戏配置'] ?? [];
        $difficulty = $config['difficulty']['default'] ?? 'normal';
        $playerData = $this->gameData['角色数据']['徐功'] ?? [];
        $this->state = [
            'meta' => ['version' => $config['game']['version'] ?? '1.0.0', 'difficulty' => $difficulty],
            'player' => [
                'name' => '徐功',
                'title' => '穿越生·未授职', // 从八品·军器监主簿等由叙事推进更新
                'level' => 1,
                'hp' => 100,
                'hp_max' => 100,
                'mp' => 50,
                'mp_max' => 50,
                'exp' => 0,
                'exp_next' => 100,
                'attributes' => $playerData['属性'] ?? ['力量'=>50,'敏捷'=>50,'体质'=>50,'智力'=>70,'感知'=>60,'魅力'=>60],
                'skills' => array_values(array_unique(array_merge(
                    ['口才', '察言观色', '说服', '权谋', '威逼利诱'], // 预置叙事技能
                    $playerData['技能'] ?? [] // 数据文件中的战斗/职业技能
                ))),
                'inventory' => [],
                'equipment' => [],
                'location' => '徐府正房',
                'quests' => [],
                'completed_quests' => [],
                'reputation' => [],
                'affection' => [],
                'main_progress' => 0,
                'chapter' => 0,
                'flags' => [],
            ],
            'timestamp' => ['game_days' => 0, 'in_game_time' => 'day', 'season' => 'spring'],
            'combat' => null, // 战斗临时状态
        ];
        $this->saveGameState();
    }

    private function saveGameState() {
        file_put_contents($this->dataDir . 'save.json', json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 公共入口
    // ──────────────────────────────────────────────────────────────────────────
    public function processInput($userMessage) {
        $cmd = trim($userMessage);
        // 支持多行命令：按换行符拆分，逐条执行
        $lines = explode("\n", $cmd);
        if (count($lines) > 1) {
            $results = [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') continue;
                $results[] = $this->processInput($line);
            }
            return implode("\n", $results);
        }
        $parts = explode(' ', $cmd, 2);
        $action = strtolower($parts[0]);
        $arg = $parts[1] ?? '';

        // 为所有命令生成状态块（包含当前HP/MP/位置/背包）
        $stateBlock = $this->getStateBlock();

        $result = $this->dispatchCommand($action, $arg);
        return $stateBlock . $result;
    }

    private function dispatchCommand($action, $arg) {

        switch ($action) {
            case 'status': case '状态': return $this->cmdStatus();
            case 'inventory': case '背包': return $this->cmdInventory();
            case 'map': case '地图': return $this->cmdMap();
            case 'quests': case '任务': return $this->cmdQuests();
            case 'save': case '存档': $this->saveGameState(); return "✅ 游戏已保存。\n";
            case 'load': case '读档': $this->loadGameState(); return "🔄 游戏已加载。\n";
            case 'help': case '帮助': return $this->cmdHelp();
            case 'talk': case '对话': return $this->cmdTalk($arg);
            case 'skill': case '技能': return $this->cmdSkill($arg);
            // 中文方向精确匹配（裸词 + 向前缀）：直接转 cmdMove，避免漏到 aiNarrative
            case '北': case '南': case '西': case '东':
            case '东北': case '西北': case '东南': case '西南':
            case '上': case '下': case '里': case '外':
                return $this->cmdMove($this->parseDirection($action));
            case '向北': return $this->cmdMove($this->parseDirection('北'));
            case '向南': return $this->cmdMove($this->parseDirection('南'));
            case '向西': return $this->cmdMove($this->parseDirection('西'));
            case '向东': return $this->cmdMove($this->parseDirection('东'));
            // 英文方向裸词（n/s/e/w/north/south 等）：直接转 cmdMove，绕过 mapNaturalLanguage
            case 'n': case 's': case 'e': case 'w':
            case 'ne': case 'nw': case 'se': case 'sw':
                return $this->cmdMove($this->parseDirectionEnglish($action));
            case 'north': case 'south': case 'east': case 'west':
            case 'northeast': case 'northwest': case 'southeast': case 'southwest':
                return $this->cmdMove($this->parseDirectionEnglish($action));
        }

        $mapped = $this->mapNaturalLanguage($cmd);
        if ($mapped) { $action = $mapped['action']; $arg = $mapped['arg']; }

        switch ($action) {
            case 'go': case 'move': case '移动': return $this->cmdMove($arg);
            case 'look': case '观察': case '查看': case '看': return $this->cmdLook($arg);
            case 'talk': case '对话': return $this->cmdTalk($arg);
            case 'attack': case '攻击': case '打': case '战斗': return $this->cmdAttack($arg);
            case 'use': case '使用': case '使用物品': case 'equip': case '装备': return $this->cmdUse($arg);
            case 'pickup': case '拾取': case '捡起': case '拿': return $this->cmdPickup($arg);
            case 'skill': case '技能': case '查技能': return $this->cmdSkill($arg);
            case 'status': case '状态': case '属性': return $this->cmdStatus();
            case 'inventory': case '背包': return $this->cmdInventory();
            case 'map': case '地图': return $this->cmdMap();
            case 'quests': case '任务': return $this->cmdQuests();
            case 'save': case '存档': $this->saveGameState(); return "✅ 游戏已保存。\n";
            case 'load': case '读档': $this->loadGameState(); return "🔄 游戏已加载。\n";
            case 'help': case '帮助': return $this->cmdHelp();
            default: {
                $result = $this->aiNarrative($cmd);
                // aiNarrative 返回 ['narrative'=>..., 'delta_applied'=>bool]
                if (is_array($result)) {
                    $narrative = $result['narrative'] ?? '';
                    // 如果 delta 已应用（saveGameState 在 aiNarrative 内部调用），无需重复保存
                    return $narrative;
                }
                return $result;
            }
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 移动与查看
    // ──────────────────────────────────────────────────────────────────────────
    private function cmdMove($direction) {
        $direction = $this->parseDirection($direction) ?? $direction;
        $current = $this->state['player']['location'];
        $map = $this->gameData['地图数据'] ?? [];
        if (!isset($map[$current]['exits'][$direction])) return "❌ 这个方向走不通。\n";
        $new = $map[$current]['exits'][$direction];
        $this->state['player']['location'] = $new;
        $this->saveGameState();
        return $this->cmdLook('');
    }

    private function cmdLook($target) {
        $current = $this->state['player']['location'];
        $map = $this->gameData['地图数据'] ?? [];
        $scene = $map[$current] ?? null;
        if (!$scene) return "你环顾四周，不知身在何处。\n";
        $desc = $scene['description'] ?? "一个普通的房间。";
        $exits = $scene['exits'] ?? [];
        $exitText = !empty($exits) ? "可前往：" . implode('、', array_keys($exits)) : "没有明显的出口。";
        $npcs = isset($scene['npcs']) ? "这里有：" . implode('、', $scene['npcs']) : "";
        $items = isset($scene['items']) ? "物品：" . implode('、', $scene['items']) : "";
        return "📍 {$current}\n{$desc}\n{$exitText}\n{$npcs}\n{$items}\n";
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 对话系统（接入好感度）
    // ──────────────────────────────────────────────────────────────────────────
    private function cmdTalk($npcName) {
        $npcData = $this->gameData['角色数据'][$npcName] ?? null;
        if (!$npcData) {
            // NPC不在角色数据中？检查当前场景是否有此NPC
            $current = $this->state['player']['location'];
            $map = $this->gameData['地图数据'] ?? [];
            $sceneNpcs = $map[$current]['npcs'] ?? [];
            if (in_array($npcName, $sceneNpcs)) {
                // 场景NPC自动生成通用对话
                $genericResponses = [
                    '路人' => ['唉，这世道不太平啊。', '你找我有事？', '路过路过。'],
                    '小贩' => ['新鲜的水果！要不要来点？', '客官看看这个，上好的丝绸！', '便宜卖了便宜卖了！'],
                    '乞丐' => ['行行好，给口饭吃吧……', '好人一生平安……', '三天没吃东西了……'],
                    '商贩' => ['要买什么？价格好商量。', '这批货刚到，成色很好。', '诚心要的话给你算便宜点。'],
                ];
                $responses = $genericResponses[$npcName] ?? ["嗯？", "你是在跟我说话吗？", "什么事？"];
                $reply = $responses[array_rand($responses)];
                return "【{$npcName}】{$reply}\n";
            }
            return "这里没有叫“{$npcName}”的人。\n";
        }
        $affectionSys = $this->gameData['NPC好感度系统'] ?? [];
        $affection = $this->state['player']['affection'][$npcName] ?? ($affectionSys[$npcName]['初始好感'] ?? 50);
        $dialogues = $npcData['对话'] ?? [];
        if ($affection >= 90 && isset($dialogues['高好感'])) $reply = $dialogues['高好感'];
        elseif ($affection <= 30 && isset($dialogues['低好感'])) $reply = $dialogues['低好感'];
        else $reply = $dialogues['日常'] ?? $dialogues['初次见面'] ?? "你好。";
        return "【{$npcName}】{$reply}\n";
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 战斗系统（接入技能倍率、冷却、连携）
    // ──────────────────────────────────────────────────────────────────────────
    private function cmdAttack($target) {
        $enemyData = $this->gameData['角色数据'][$target] ?? null;
        // NPC不在角色数据中？检查当前场景NPC列表
        if (!$enemyData) {
            $current = $this->state['player']['location'];
            $map = $this->gameData['地图数据'] ?? [];
            $sceneNpcs = $map[$current]['npcs'] ?? [];
            if (in_array($target, $sceneNpcs)) {
                // 场景NPC自动生成默认战斗数据
                $enemyData = [
                    '属性' => ['力量'=>40,'体质'=>30,'敏捷'=>35],
                    '生命值' => 50,
                    '对话' => [],
                ];
            } else {
                return "这里没有可以攻击的目标。\n";
            }
        }

        // 获取玩家可用技能（默认普通攻击）
        $skills = $this->gameData['技能大全'] ?? [];
        $playerSkills = $this->state['player']['skills'];
        $defaultSkill = $playerSkills[0] ?? '普通攻击';
        // 角色数据.json 的 skills 是列表（['赵家枪法',...]），array_keys 返回 [0,1,2,3]
        // 所以 playerSkills[0] 可能是整数0，需从 技能大全.json 查找实际技能名
        if (!is_string($defaultSkill) || $defaultSkill === '0') {
            $defaultSkill = '普通攻击';
        }
        $skill = $skills[$defaultSkill] ?? ['倍率'=>1.0, '消耗'=>0, '冷却'=>0, '范围'=>'单体'];

        // 检查MP和冷却（简化：战斗状态暂存在 $this->state['combat']）
        $combatState = $this->state['combat'] ?? [];
        $cooldownKey = $defaultSkill . '_cd';
        if (isset($combatState[$cooldownKey]) && $combatState[$cooldownKey] > 0) {
            return "技能 {$defaultSkill} 还需冷却 {$combatState[$cooldownKey]} 回合。\n";
        }
        $mpCost = $skill['消耗'] ?? 0;
        if ($this->state['player']['mp'] < $mpCost) return "内力不足。\n";

        // 计算伤害（根据战斗系统.json的公式）
        $playerAtk = $this->state['player']['attributes']['力量'];
        $enemyDef = $enemyData['属性']['体质'] ?? 30;
        $baseDamage = $playerAtk * ($skill['倍率'] ?? 1.0) - $enemyDef * 0.5;
        $damage = max(1, round($baseDamage * (0.9 + mt_rand(0,20)/100)));
        if (mt_rand(1,100) <= 5) $damage *= 2; // 暴击

        // 扣除MP和设置冷却
        $this->state['player']['mp'] -= $mpCost;
        if (($skill['冷却'] ?? 0) > 0) {
            $combatState[$cooldownKey] = $skill['冷却'];
        }

        // 敌人生命值（临时，战斗结束后恢复）
        $enemyHp = $combatState[$target.'_hp'] ?? $enemyData['生命值'] ?? 100;
        $enemyHp -= $damage;
        $combatState[$target.'_hp'] = $enemyHp;

        $msg = "你对 {$target} 使用 {$defaultSkill}，造成 {$damage} 点伤害。\n";
        if ($enemyHp <= 0) {
            unset($combatState[$target.'_hp']);
            $msg .= "你击败了 {$target}！获得经验值。\n";
            $this->state['player']['exp'] += 50;
            $this->state['combat'] = $combatState;
            $this->saveGameState();
            return $msg;
        }

        // 敌人反击（简易）
        $enemyAtk = $enemyData['属性']['力量'] ?? 40;
        $playerDef = $this->state['player']['attributes']['体质'];
        $enemyDamage = max(1, round($enemyAtk - $playerDef * 0.5));
        $this->state['player']['hp'] -= $enemyDamage;
        $msg .= "{$target} 反击造成 {$enemyDamage} 点伤害，你剩余 {$this->state['player']['hp']} HP。\n";

        if ($this->state['player']['hp'] <= 0) {
            $msg .= "你战败了……\n";
            $this->state['player']['hp'] = 0;
        }

        // 减少冷却计数
        foreach ($combatState as $k => $v) {
            if (substr($k, -3) === '_cd' && $v > 0) $combatState[$k]--;
        }
        $this->state['combat'] = $combatState;
        $this->saveGameState();
        return $msg;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 物品拾取（将房间物品添加到背包）
    // ──────────────────────────────────────────────────────────────────────────
    private function cmdPickup($itemName) {
        $current = $this->state['player']['location'];
        $map = $this->gameData['地图数据'] ?? [];
        $scene = $map[$current] ?? null;
        if (!$scene) return "当前位置没有可拾取的东西。\n";
        $roomItems = $scene['items'] ?? [];
        $itemIdx = false;
        foreach ($roomItems as $idx => $item) {
            if ($item === $itemName || (is_array($item) && ($item['name'] ?? '') === $itemName)) {
                $itemIdx = $idx;
                break;
            }
        }
        if ($itemIdx === false) return "该物品不在当前房间中，无法拾取。\n";
        if (in_array($itemName, $this->state['player']['inventory'])) {
            return "你已经有 {$itemName} 了。\n";
        }
        $this->state['player']['inventory'][] = $itemName;
        // 从房间物品中移除（如果是数组则直接unset）
        if (is_array($roomItems)) {
            unset($map[$current]['items'][$itemIdx]);
            $map[$current]['items'] = array_values($map[$current]['items']);
            $this->gameData['地图数据'] = $map;
        }
        $this->saveGameState();
        return "你拾取了 {$itemName}，已放入背包。\n";
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 物品使用（接入物品图鉴）
    // ──────────────────────────────────────────────────────────────────────────
    private function cmdUse($itemName) {
        $items = $this->gameData['物品图鉴'] ?? [];
        $item = $items[$itemName] ?? null;
        if (!$item) {
            // 检查玩家背包是否有此物品（物品可能不在字典中）
            if (!in_array($itemName, $this->state['player']['inventory'])) {
                // 检查当前房间是否有此物品
                $current = $this->state['player']['location'];
                $map = $this->gameData['地图数据'] ?? [];
                $roomItems = $map[$current]['items'] ?? [];
                $roomItemNames = [];
                foreach ($roomItems as $it) {
                    $roomItemNames[] = is_array($it) ? ($it['name'] ?? '') : $it;
                }
                if (in_array($itemName, $roomItemNames)) {
                    // 自动拾取再使用
                    $this->state['player']['inventory'][] = $itemName;
                    return "你从地上捡起「{$itemName}」→ " . $this->cmdUse($itemName);
                }
                return "没有找到物品「{$itemName}」（你背包里也没有）。\n";
            }
            // 在背包但不在字典：给出使用失败提示而非报错
            return "「{$itemName}」的具体使用方法未知，请尝试其他方式。\n";
        }
        // 物品在字典中但需要检查背包是否持有
        if (!in_array($itemName, $this->state['player']['inventory'])) {
            // 检查当前房间是否有此物品，给友好提示
            $current = $this->state['player']['location'];
            $map = $this->gameData['地图数据'] ?? [];
            $roomItems = $map[$current]['items'] ?? [];
            $roomItemNames = [];
            foreach ($roomItems as $it) {
                $roomItemNames[] = is_array($it) ? ($it['name'] ?? '') : $it;
            }
            if (in_array($itemName, $roomItemNames)) {
                return "「{$itemName}」就在当前房间里，试试「捡起 {$itemName}」先拿到手上。\n";
            }
            return "你没有「{$itemName}」。先去找到它再使用吧。\n";
        }
        $type = $item['类型'];
        if ($type === '丹药') {
            $effect = $item['效果'];
            if (preg_match('/回复(\d+)HP/', $effect, $m)) {
                $heal = $m[1];
                $this->state['player']['hp'] = min($this->state['player']['hp_max'], $this->state['player']['hp'] + $heal);
                $this->removeFromInventory($itemName);
                $this->saveGameState();
                return "你使用了 {$itemName}，恢复了 {$heal} 点生命值。\n";
            }
        } elseif ($type === '食物') {
            // 食物：解析回复XHP并消耗物品
            $effect = $item['效果'];
            if (preg_match('/回复(\d+)HP/', $effect, $m)) {
                $heal = $m[1];
                $this->state['player']['hp'] = min($this->state['player']['hp_max'], $this->state['player']['hp'] + $heal);
                $this->removeFromInventory($itemName);
                $this->saveGameState();
                return "你食用了 {$itemName}，恢复了 {$heal} 点生命值。\n";
            }
            // 无HP效果的普通食物
            $this->removeFromInventory($itemName);
            $this->saveGameState();
            return "你使用了 {$itemName}。\n{$effect}\n";
        }
        return "你使用了 {$itemName}，但不知道效果。\n";
    }

    private function removeFromInventory($itemName) {
        $inv = &$this->state['player']['inventory'];
        $idx = array_search($itemName, $inv);
        if ($idx !== false) array_splice($inv, $idx, 1);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 其他命令
    // ──────────────────────────────────────────────────────────────────────────
    private function cmdSkill($skillName) {
        $skills = $this->gameData['技能大全'] ?? [];
        $playerSkills = $this->state['player']['skills'] ?? [];
        if (empty($skillName)) {
            // 列出玩家已学会的所有技能详情
            if (empty($playerSkills)) return "你尚未学会任何技能。\n";
            $lines = ["📖 你已学会的技能（共 " . count($playerSkills) . " 个）："];
            foreach ($playerSkills as $sk) {
                $skData = $skills[$sk] ?? null;
                if ($skData) {
                    $desc = mb_substr($skData['描述'] ?? '', 0, 30);
                    $lines[] = "◆ {$sk} [{$skData['类型']}] 消耗:{$skData['消耗']}  倍率:{$skData['倍率']}  {$desc}";
                } else {
                    $lines[] = "◆ {$sk}（无详细数据）";
                }
            }
            return implode("\n", $lines) . "\n";
        }
        // 查找技能（优先玩家已学会的，其次全局字典）
        $found = null;
        foreach ($playerSkills as $sk) {
            if (mb_stripos($sk, $skillName) !== false || mb_stripos($skillName, $sk) !== false) { $found = $sk; break; }
        }
        if (!$found && isset($skills[$skillName])) $found = $skillName;
        if (!$found) {
            // 模糊搜索：技能名包含关键词
            foreach ($skills as $sk => $data) {
                if (mb_stripos($sk, $skillName) !== false) { $found = $sk; break; }
            }
        }
        if (!$found) {
            // 检查是否是NPC技能（剧情向），给予友好提示而非报错
            $npcSkills = ['口才', '威逼利诱', '察言观色', '权谋', '说服'];
            if (in_array($skillName, $npcSkills)) {
                return "「{$skillName}」是剧情向能力，无需主动释放。在合适的对话场景中自然触发即可。\n";
            }
            return "未知技能「{$skillName}」。你尚未学会此技能，请先通过剧情或师傅传授获得。\n";
        }
        $skill = $skills[$found] ?? $skills[$skillName];
        return "【{$found}】类型:{$skill['类型']}, 消耗:{$skill['消耗']}, 倍率:{$skill['倍率']}, 冷却:{$skill['冷却']}\n{$skill['动画']}\n";
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 状态块生成（P0-2：持久化状态，前端状态条）
    // ──────────────────────────────────────────────────────────────────────────
    private function getStateBlock() {
        $p = $this->state['player'];
        $inventory = $p['inventory'] ?? [];
        $invCount = count($inventory);
        // 物品列表截取前5个，超出用"等"表示
        $invPreview = $invCount > 0
            ? implode('、', array_slice($inventory, 0, 5)) . ($invCount > 5 ? "等{$invCount}件" : '')
            : '无';
        $block = [
            'hp' => $p['hp'],
            'hp_max' => $p['hp_max'],
            'mp' => $p['mp'],
            'mp_max' => $p['mp_max'],
            'location' => $p['location'],
            'level' => $p['level'],
            'inv_preview' => $invPreview,
            'inv_count' => $invCount,
        ];
        return "[STATE:" . json_encode($block, JSON_UNESCAPED_UNICODE) . "]\n";
    }

    private function cmdStatus() {
        $p = $this->state['player'];
        $attr = $p['attributes'];
        $title = $p['title'] ?? '';
        $titleStr = $title ? "【{$p['name']}】{$title}\n" : "【{$p['name']}】\n";
        return $titleStr .
               "Lv.{$p['level']}  {$p['hp']}/{$p['hp_max']} HP  {$p['mp']}/{$p['mp_max']} MP\n" .
               "力量:{$attr['力量']} 敏捷:{$attr['敏捷']} 体质:{$attr['体质']} 智力:{$attr['智力']} 感知:{$attr['感知']} 魅力:{$attr['魅力']}\n" .
               "经验:{$p['exp']}/{$p['exp_next']}  位置:{$p['location']}\n" .
               "技能：".(empty($p['skills'])?'无':implode('、',$p['skills']))."\n";
    }

    private function cmdInventory() {
        $items = $this->state['player']['inventory'];
        if (empty($items)) return "背包空空如也。\n";
        return "📦 物品：" . implode('、', $items) . "\n";
    }

    private function cmdMap() {
        $current = $this->state['player']['location'];
        $map = $this->gameData['地图数据'] ?? [];
        $scene = $map[$current] ?? null;
        if (!$scene) return "🗺️ 你在一处不知名的地方。\n";
        $exits = $scene['exits'] ?? [];
        $exitLines = [];
        foreach ($exits as $dir => $dest) {
            $exitLines[] = "  {$dir} → {$dest}";
        }
        $exitStr = !empty($exitLines) ? "📌 当前位置：{$current}\n可前往：\n" . implode("\n", $exitLines) : "📌 当前位置：{$current}\n没有出口。";
        // 显示所有可访问区域概览
        $allLocs = array_keys($map);
        return "🗺️ 区域地图\n─────────────────\n{$exitStr}\n\n已探索区域（" . count($allLocs) . " 处）：" . implode('、', $allLocs) . "\n";
    }

    private function cmdQuests() {
        $quests = $this->state['player']['quests'];
        if (empty($quests)) return "当前没有进行中的任务。\n";
        return "📜 任务：" . implode('、', $quests) . "\n";
    }

    private function cmdHelp() {
        return "📖 可用命令（/ 和中文均可）\n" .
               "─────────────────────────────────\n" .
               "系统：/status /状态  /inventory /背包  /map /地图  /quests /任务  /save /存档  /load /读档  /help /帮助\n" .
               "移动：go <方向>  move <方向>  /向北 /向南 /向西 /向东 /地图  n/s/e/w（缩写）\n" .
               "观察：look /查看 /观察 /看  [目标]\n" .
               "对话：/talk /对话 [NPC名]  询问<NPC>  问<NPC>  向<NPC>打听  找<NPC>\n" .
               "攻击：/attack /攻击 /打 /战斗 <敌人>\n" .
               "拾取：/拾取 <物品名>  /捡起 <物品名>  /拿 <物品名>\n" .
               "使用：/use /使用 <物品名>  /使用物品 <物品名>\n" .
               "技能：/skill /技能 <技能名>  /查技能（查看已学会技能）\n" .
               "─────────────────────────────────\n" .
               "提示：命令不区分大小写，中英文混用也可\n";
    }

    // ──────────────────────────────────────────────────────────────────────────
    // AI 增强叙事（调用 LLM）
    // ──────────────────────────────────────────────────────────────────────────
    // AI 叙事 + 状态迁移（LLM 自主模式）
    // 返回格式: ['narrative' => string, 'delta_applied' => bool]
    private function aiNarrative($userMsg) {
        global $PROVIDERS;
        $apiUrl = 'https://opencode.ai/zen/go/v1/chat/completions';
        $keys = $PROVIDERS['opencode-go']['keys'] ?? [];
        if (empty($keys)) return ['narrative' => "empty_keys_error\n", 'delta_applied' => false];

        // 构建完整system prompt（已含 JSON State Delta 指令）
        $systemPrompt = $this->buildSystemPrompt();

        foreach ($keys as $key) {
            $response = $this->callLLM($apiUrl, $key, $systemPrompt, $userMsg);
            if (!$response) continue;

            // 提取叙事文本和 JSON delta
            $narrative = $response;
            $deltaApplied = false;

            // 提取叙事文本（去除所有 JSON 状态块）
            $narrative = preg_replace('/\[STATE_DELTA\]\s*\{.*?\}\s*\[\/STATE_DELTA\]/s', '', $response);
            $narrative = preg_replace('/\[STATE:\s*\{.*?\}\]/s', '', $narrative);
            $narrative = trim($narrative);

            // === 强制修正角色名：LLM有时生成偏离的玩家角色名，替换为「徐功」===
            // 已知偏离角色名：沈清源、赵明远、沈安（LLM自创的非玩家名，非游戏NPC）
            // 游戏NPC（赵氏、完颜宗翰等）不受影响
            $narrative = preg_replace('/沈清源|赵明远|沈安/u', '徐功', $narrative);

            // 优先解析 [STATE_DELTA]...[/STATE_DELTA] 块
            if (preg_match('/\[STATE_DELTA\]\s*(\{.*?\})\s*\[\/STATE_DELTA\]/s', $response, $m)) {
                $jsonStr = $m[1];
                $delta = json_decode($jsonStr, true);
                if (is_array($delta)) {
                    $validationError = $this->validateAndApplyDelta($delta);
                    if ($validationError === true) {
                        $this->saveGameState();
                        $deltaApplied = true;
                    }
                }
            } elseif (preg_match('/\[STATE:\s*(\{.*?)\}]\s*$/s', $response, $m)) {
                // Fallback：LLM 生成了 [STATE:...] 格式（全量快照），尝试从中提取 location 并应用
                $jsonStr = $m[1] . '}';
                $fullState = json_decode($jsonStr, true);
                if (is_array($fullState) && isset($fullState['location'])) {
                    $newLoc = $fullState['location'];
                    $map = $this->gameData['地图数据'] ?? [];
                    if (isset($map[$newLoc]) && $newLoc !== $this->state['player']['location']) {
                        $this->state['player']['location'] = $newLoc;
                        $this->saveGameState();
                        $deltaApplied = true;
                    }
                }
            }

            // 如果叙事内容为空或只有 no_response_error，使用 cmdLook() 生成 fallback 叙事
            if (empty($narrative) || $narrative === 'no_response_error') {
                $narrative = $this->cmdLook('');
            }

            return ['narrative' => $narrative . "\n", 'delta_applied' => $deltaApplied];
        }
        // LLM timeout fallback: use cmdLook instead of no_response_error
        return ['narrative' => $this->cmdLook('') . "\n", 'delta_applied' => false];
    }

    // 校验并应用 LLM 返回的 state delta
    // 返回 true = 成功, string = 错误信息
    private function validateAndApplyDelta(array $delta) {
        $map = $this->gameData['地图数据'] ?? [];
        $items = $this->gameData['物品图鉴'] ?? [];
        $npcData = $this->gameData['角色数据'] ?? [];
        $player = &$this->state['player'];

        // ── location 迁移（仅允许相邻房间的移动）──
        if (isset($delta['location'])) {
            $newLoc = $delta['location'];
            if (isset($map[$newLoc])) {
                // 检查是否与当前位置相邻（有出口连接）
                $currentLoc = $player['location'];
                $exits = $map[$currentLoc]['exits'] ?? [];
                $reverse = array_search($newLoc, $exits);
                if ($reverse !== false) {
                    // 相邻：正常移动
                    $player['location'] = $newLoc;
                } elseif ($currentLoc !== $newLoc && empty($exits)) {
                    // 当前位置无出口但地图合法：允许（场景未完整配置时放宽限制）
                    $player['location'] = $newLoc;
                }
                // 不满足相邻条件：忽略本次 location 变更，防止LLM瞎跳
            }
        }

        // ── HP 变化 ──
        if (isset($delta['hp_delta'])) {
            $deltaVal = intval($delta['hp_delta']);
            $player['hp'] = max(0, min($player['hp_max'], $player['hp'] + $deltaVal));
        }

        // ── MP 变化 ──
        if (isset($delta['mp_delta'])) {
            $deltaVal = intval($delta['mp_delta']);
            $player['mp'] = max(0, min($player['mp_max'], $player['mp'] + $deltaVal));
        }

        // ── 经验值变化 ──
        if (isset($delta['exp_delta'])) {
            $deltaVal = intval($delta['exp_delta']);
            $player['exp'] = max(0, $player['exp'] + $deltaVal);
            while ($player['exp'] >= $player['exp_next']) {
                $player['exp'] -= $player['exp_next'];
                $player['level']++;
                $player['exp_next'] = intval($player['exp_next'] * 1.5);
                $player['hp_max'] += 10;
                $player['hp'] = $player['hp_max'];
                $player['mp_max'] += 5;
                $player['mp'] = $player['mp_max'];
            }
        }

        // ── 添加物品 ──
        if (isset($delta['add_item'])) {
            $itemName = trim($delta['add_item']);
            if ($itemName && !in_array($itemName, $player['inventory'])) {
                $player['inventory'][] = $itemName;
            }
        }

        // ── 移除物品 ──
        if (isset($delta['remove_item'])) {
            $itemName = trim($delta['remove_item']);
            $idx = array_search($itemName, $player['inventory']);
            if ($idx !== false) array_splice($player['inventory'], $idx, 1);
        }

        // ── NPC 好感度变化 ──
        if (isset($delta['npc']) && isset($delta['affection_delta'])) {
            $npc = trim($delta['npc']);
            $deltaVal = intval($delta['affection_delta']);
            if (!isset($player['affection'][$npc])) $player['affection'][$npc] = 0;
            $player['affection'][$npc] = max(-100, min(100, $player['affection'][$npc] + $deltaVal));
        }

        // ── 剧情标记 ──
        if (isset($delta['set_flag'])) {
            $flag = trim($delta['set_flag']);
            if ($flag && !in_array($flag, $player['flags'])) {
                $player['flags'][] = $flag;
            }
        }

        return true;
    }

    // 构建AI叙事用system prompt（从MD文档动态加载）
    private function buildSystemPrompt() {
        $parts = [];

        // 核心GM规则（精简版，~500字，完整版见GM系统提示词.md）
        $gmFile = $this->gameDocs['GM系统提示词'] ?? null;
        if ($gmFile) {
            $parts[] = mb_substr($gmFile, 0, 500); // 只取前500字
        } else {
            $parts[] = "你是一个文字MUD游戏《穿越当宰相》的GM。玩家扮演徐功，南宋穿越者。请根据当前场景和玩家输入，生成2-3句叙事。";
        }

        // === JSON State Delta 指令 ===
        $parts[] = "【状态迁移规则】每次响应后附一行JSON：[STATE_DELTA]{\"location\":\"地点\",\"hp_delta\":数值,...}[/STATE_DELTA]
字段：location(地点), hp_delta/mp_delta/exp_delta(数值变化), add_item/remove_item(物品), npc+affection_delta(好感), set_flag(标记)
约束：
1. location必须是地图数据中的已知地点（从【当前状态】读取），禁止凭空生成新地点
2. HP/MP不超上下限；物品必须合理获得（先有房间描述，才能添加物品到背包）
3. 战斗请用 /attack，禁止在叙事中编造伤害数值
4. 禁止在叙事中描述角色等级/HP/MP变化（由状态系统管理，不由叙事决定）
5. 只描述当前location的实际情况，不要说去了别的地方（location由移动命令决定）
6. 严禁提及或创造【当前状态】中不存在的NPC/角色
7. 严禁编造物品获得（物品只能通过明确的拾取动作获得，不能叙事暗示）
8. 玩家角色名永远是【徐功】，禁止在叙事中使用其他角色名（如沈清源、赵明远等）
9. 叙事中提及玩家时只能称呼「你」或「徐功」，不得另起别名
10. 禁止在叙事开头或末尾添加「📍」「【位置】」等位置标记——位置信息由系统自动展示，AI只需描述场景本身";

        // 世界观背景（截断到前2000字符）
        if (!empty($this->gameDocs['世界观设定'])) {
            $parts[] = "【世界观设定】\n" . mb_substr($this->gameDocs['世界观设定'], 0, 2000);
        }

        // 当前场景（从地图数据获取）
        $current = $this->state['player']['location'] ?? '徐府正房';
        $sceneFile = $this->gameDocs['场景设定集'] ?? null;
        if ($sceneFile) {
            $parts[] = "【场景设定】\n" . mb_substr($sceneFile, 0, 800);
        }

        // 当前房间可拾取物品（让AI知道当前场景有什么东西可交互）
        $map = $this->gameData['地图数据'] ?? [];
        $sceneData = $map[$current] ?? null;
        if ($sceneData) {
            $roomItems = $sceneData['items'] ?? [];
            $roomNpcs = $sceneData['npcs'] ?? [];
            $itemList = [];
            foreach ($roomItems as $it) {
                $itemList[] = is_array($it) ? ($it['name'] ?? '物品') : $it;
            }
            $itemStr = empty($itemList) ? '无' : implode('、', $itemList);
            $npcStr = empty($roomNpcs) ? '无' : implode('、', $roomNpcs);
            $parts[] = "【当前房间：{$current}】\n可见物品：{$itemStr}\n在场NPC：{$npcStr}";
        }

        // 剧情进度
        if (!empty($this->gameDocs['剧情进度'])) {
            $parts[] = "【剧情进度】\n" . mb_substr($this->gameDocs['剧情进度'], 0, 1000);
        }

        // 角色状态（必须完整，AI叙事不得与以下数据矛盾）
        $p = $this->state['player'] ?? [];
        $officialRank = $this->gameData['角色数据']['徐功']['官职'] ?? '白身';
        $skills = $p['skills'] ?? [];
        $skillList = empty($skills) ? '暂无' : implode('、', $skills);
        $status = "【当前状态】\n"
                . "角色：{$p['name']}（{$officialRank}）\n"
                . "位置：{$current}\n"
                . "HP：{$p['hp']}/{$p['hp_max']}  MP：{$p['mp']}/{$p['mp_max']}\n"
                . "等级：{$p['level']}  经验：{$p['exp']}/{$p['exp_next']}\n"
                . "背包：" . (empty($p['inventory']) ? '(空)' : implode('、', $p['inventory'])) . "\n"
                . "已学会技能：{$skillList}";
        $parts[] = $status;

        return implode("\n\n---\n\n", $parts);
    }


    private function callLLM($url, $apiKey, $system, $user) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'model' => 'deepseek-v4-flash',
                'messages' => [['role'=>'system','content'=>$system], ['role'=>'user','content'=>$user]],
                'temperature' => 0.7,
                'max_tokens' => 600,
            ]),
        ]);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http === 200) {
            $data = json_decode($resp, true);
            return $data['choices'][0]['message']['content'] ?? null;
        }
        return null;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 自然语言映射（保持不变）
    // ──────────────────────────────────────────────────────────────────────────
    private function mapNaturalLanguage($text) {
        $text = trim($text);
        if (preg_match('/^(去|前往|走到|往)(.*?)(方向)?$/u', $text, $m)) {
            $dir = $this->parseDirection($m[2]);
            if ($dir) return ['action'=>'go', 'arg'=>$dir];
        }
        // 英文 go/move north/south 等
        if (preg_match('/^go\s+(\w+)$/i', $text, $m) || preg_match('/^move\s+(\w+)$/i', $text, $m)) {
            $dir = $this->parseDirectionEnglish($m[1]);
            if ($dir) return ['action'=>'go', 'arg'=>$dir];
        }
        if (preg_match('/^(北|南|西|东|东北|西北|东南|西南|上|下|里|外)$/u', $text, $m)) return ['action'=>'go', 'arg'=>$m[1]];
        // 裸英文方向词：n/s/e/w/ne/nw/se/sw
        if (preg_match('/^(north|south|east|west|northeast|northwest|southeast|southwest|up|down|inside|outside|n|s|e|w|ne|nw|se|sw)$/i', $text, $m)) {
            $en = $this->parseDirectionEnglish($m[1]);
            if ($en) return ['action'=>'go', 'arg'=>$en];
        }
        // 向北/向南 等方向前缀
        if (preg_match('/^向(北|南|西|东|东北|西北|东南|西南)$/u', $text, $m)) return ['action'=>'go', 'arg'=>$m[1]];
        // 向南走 / 向北去 / 向东来 / 向西跑 等「向+方向+动词」格式
        if (preg_match('/^向(北|南|西|东|东北|西北|东南|西南)(走|去|来|跑)?$/u', $text, $m)) {
            $dir = $this->parseDirection($m[1]);
            if ($dir) return ['action'=>'go', 'arg'=>$dir];
        }
        // 南走 / 东去 / 北来 等「方向+动词」格式（无向前缀）
        if (preg_match('/^(北|南|西|东|东北|西北|东南|西南|上|下|里|外)(走|去|来|行|跑)$/u', $text, $m)) {
            $dir = $this->parseDirection($m[1]);
            if ($dir) return ['action'=>'go', 'arg'=>$dir];
        }
        if (preg_match('/^(查看|观察|看)(.*)$/u', $text, $m)) return ['action'=>'look', 'arg'=>trim($m[2])];
        if (preg_match('/^(和|与|跟)(.*)(说话|对话|聊天)$/u', $text, $m)) return ['action'=>'talk', 'arg'=>trim($m[2])];
        if (preg_match('/^(对话|说话)(.*)$/u', $text, $m)) return ['action'=>'talk', 'arg'=>trim($m[2])];
        // ── 询问/问/向X → 直接搜索NPC名（不用复杂正则，避免.{1,10}贪婪陷阱）──
        $npc_list = ['老张','管家','赵氏','二娘','三娘','王监丞','周大使',
                      '工匠','小贩','路人','乞丐','商贩','守城','士兵',
                      '商队','残兵','难民','伤兵','游客','船夫','掌柜',
                      '小二','老板','爵士','大人','老爷','公子','姑娘','小姐'];
        foreach (['询问','问','向','找'] as $pfx) {
            if (mb_strpos($text, $pfx) === 0) {
                $rest = mb_substr($text, mb_strlen($pfx));
                foreach ($npc_list as $npc) {
                    if (mb_strpos($rest, $npc) !== false) {
                        return ['action'=>'talk', 'arg'=>$npc];
                    }
                }
            }
        }
        // 和X说话/对话/聊天
        if (preg_match('/^(和|与|跟)(.*)(说话|对话|聊天)$/u', $text, $m)) return ['action'=>'talk', 'arg'=>trim($m[2])];
        if (preg_match('/^(对话|说话)(.*)$/u', $text, $m)) return ['action'=>'talk', 'arg'=>trim($m[2])];
        if (preg_match('/^(状态|属性)$/u', $text)) return ['action'=>'status', 'arg'=>''];
        if (preg_match('/^(背包|背包列表)$/u', $text)) return ['action'=>'inventory', 'arg'=>''];
        if (preg_match('/^(装备|穿戴)$/u', $text)) return ['action'=>'use', 'arg'=>''];
        if (preg_match('/^equip\s+(.+)$/i', $text, $m)) return ['action'=>'use', 'arg'=>trim($m[1])];
        // 拾取 X / 捡起 X / 拿 X
        if (preg_match('/^(拾取|捡起|拿|拿取|pickup)\\s+(.+)$/u', $text, $m)) return ['action'=>'pickup', 'arg'=>trim($m[2])];
        if (preg_match('/^(使用|使用物品)\\s+(.+)$/u', $text, $m)) return ['action'=>'use', 'arg'=>trim($m[2])];
        // 查技能 / 查看技能
        if (preg_match('/^(查技能|查看技能|技能列表)$/u', $text)) return ['action'=>'skill', 'arg'=>''];
        return false;
    }

    private function parseDirection($dir) {
        $map = ['北'=>'north','南'=>'south','西'=>'west','东'=>'east','东北'=>'northeast','西北'=>'northwest','东南'=>'southeast','西南'=>'southwest','上'=>'up','下'=>'down','里'=>'inside','外'=>'outside'];
        foreach ($map as $cn => $en) if (strpos($dir, $cn) !== false) return $en;
        return null;
    }

    private function parseDirectionEnglish($dir) {
        $map = ['north'=>'north','south'=>'south','east'=>'east','west'=>'west','northeast'=>'northeast','northwest'=>'northwest','southeast'=>'southeast','southwest'=>'southwest','up'=>'up','down'=>'down','inside'=>'inside','outside'=>'outside','n'=>'north','s'=>'south','e'=>'east','w'=>'west','ne'=>'northeast','nw'=>'northwest','se'=>'southeast','sw'=>'southwest'];
        return $map[$dir] ?? null;
    }
}

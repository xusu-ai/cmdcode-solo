<?php

// 延长 PHP 执行时间（避免 chat/completions 等长请求超时）
// DeepSeek V4 Flash 支持 100 万 tokens 上下文，需要更长的处理时间
@ini_set('max_execution_time', 300);
@set_time_limit(300);

// ═══════════════════════════════════════
// 加载加密配置（所有敏感凭据集中管理）
// ═══════════════════════════════════════
$_config = __DIR__ . '/config.enc.php';
if (!file_exists($_config)) {
    http_response_code(500);
    echo json_encode(['error' => 'config_missing', 'message' => 'config.enc.php not found']);
    exit;
}
$PROVIDERS = include $_config;
if (!is_array($PROVIDERS)) {
    http_response_code(500);
    echo json_encode(['error' => 'config_load_failed', 'message' => '加密配置加载失败']);
    exit;
}


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
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5,
            ]
        );
        // 首次连接时确保 music_tasks / video_tasks 表存在
        initTaskStatsSchema($pdo);
    }
    return $pdo;
}

// ── 主密钥派生（基于 config.enc.php 的加密口令） ──
function getMemoryMasterKey(): string {
    // 使用与 config.enc.php 相同的加密口令派生记忆系统主密钥
    $passphrase = ENC_PASSPHRASE;
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

// ═══════════════════════════════════════
// TASK STATS SCHEMA（音乐/视频任务历史持久化）
// ═══════════════════════════════════════
// worker.sh 通过 cron 触发时 stdout 被丢弃，P1 已修复日志
// 但要做月度统计需要 DB 持久化 — 此模块为音乐/视频任务建独立表
function initTaskStatsSchema(PDO $pdo): void {
    static $initialized = false;
    if ($initialized) return;
    $initialized = true;

    // music_tasks 表：每条音乐生成一条记录
    $pdo->exec("CREATE TABLE IF NOT EXISTS music_tasks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        task_id CHAR(16) NOT NULL,
        user_id VARCHAR(64) NOT NULL DEFAULT 'guest',
        status VARCHAR(16) NOT NULL DEFAULT 'pending',
        params JSON DEFAULT NULL,
        http_code SMALLINT DEFAULT NULL,
        file_id VARCHAR(128) DEFAULT NULL,
        duration_ms INT DEFAULT NULL,
        error_message TEXT,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        completed_at TIMESTAMP NULL DEFAULT NULL,
        UNIQUE KEY uk_task_id (task_id),
        KEY idx_user_created (user_id, created_at),
        KEY idx_status_created (status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // video_tasks 表：每条视频生成一条记录（含 video_template 子类型）
    $pdo->exec("CREATE TABLE IF NOT EXISTS video_tasks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        task_id CHAR(16) NOT NULL,
        user_id VARCHAR(64) NOT NULL DEFAULT 'guest',
        status VARCHAR(16) NOT NULL DEFAULT 'pending',
        params JSON DEFAULT NULL,
        http_code SMALLINT DEFAULT NULL,
        file_id VARCHAR(128) DEFAULT NULL,
        duration_ms INT DEFAULT NULL,
        error_message TEXT,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        completed_at TIMESTAMP NULL DEFAULT NULL,
        UNIQUE KEY uk_task_id (task_id),
        KEY idx_user_created (user_id, created_at),
        KEY idx_status_created (status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // image_tasks 表：每条图片生成一条记录（image_generation/image_to_image）
    $pdo->exec("CREATE TABLE IF NOT EXISTS image_tasks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        task_id CHAR(16) NOT NULL,
        user_id VARCHAR(64) NOT NULL DEFAULT 'guest',
        status VARCHAR(16) NOT NULL DEFAULT 'pending',
        params JSON DEFAULT NULL,
        http_code SMALLINT DEFAULT NULL,
        file_id VARCHAR(128) DEFAULT NULL,
        duration_ms INT DEFAULT NULL,
        error_message TEXT,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        completed_at TIMESTAMP NULL DEFAULT NULL,
        UNIQUE KEY uk_task_id (task_id),
        KEY idx_user_created (user_id, created_at),
        KEY idx_status_created (status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // tts_tasks 表：每条 TTS 语音合成一条记录（text_to_speech）
    $pdo->exec("CREATE TABLE IF NOT EXISTS tts_tasks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        task_id CHAR(16) NOT NULL,
        user_id VARCHAR(64) NOT NULL DEFAULT 'guest',
        status VARCHAR(16) NOT NULL DEFAULT 'pending',
        params JSON DEFAULT NULL,
        http_code SMALLINT DEFAULT NULL,
        file_id VARCHAR(128) DEFAULT NULL,
        text_chars INT DEFAULT NULL,
        duration_ms INT DEFAULT NULL,
        error_message TEXT,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        completed_at TIMESTAMP NULL DEFAULT NULL,
        UNIQUE KEY uk_task_id (task_id),
        KEY idx_user_created (user_id, created_at),
        KEY idx_status_created (status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 兼容旧表：如果表已存在但缺 params 列，动态补齐（MySQL 5.7 无 IF NOT EXISTS 语法）
    foreach (['music_tasks', 'video_tasks', 'image_tasks', 'tts_tasks'] as $tbl) {
        try {
            $colCheck = $pdo->query("SHOW COLUMNS FROM {$tbl} LIKE 'params'")->fetchAll();
            if (empty($colCheck)) {
                $pdo->exec("ALTER TABLE {$tbl} ADD COLUMN params JSON DEFAULT NULL AFTER status");
                error_log("[initTaskStatsSchema] Added params column to {$tbl}");
            }
        } catch (Throwable $e) {
            error_log("[initTaskStatsSchema] ALTER {$tbl} params failed: " . $e->getMessage());
        }
    }
}

// 记录任务开始（INSERT pending）
function recordTaskStart(PDO $pdo, string $type, string $taskId, string $userId, array $params): void {
    try {
        $tableMap = [
            'video' => 'video_tasks',
            'music' => 'music_tasks',
            'image' => 'image_tasks',
            'tts'   => 'tts_tasks',
        ];
        $table = $tableMap[$type] ?? 'music_tasks'; // 未知 type 回退到 music_tasks
        $paramsJson = json_encode($params, JSON_UNESCAPED_UNICODE);
        if ($paramsJson === false) {
            throw new RuntimeException('json_encode failed: ' . json_last_error_msg());
        }
        $stmt = $pdo->prepare("INSERT INTO {$table} (task_id, user_id, status, params) VALUES (?, ?, 'pending', ?) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), params=VALUES(params)");
        $stmt->execute([$taskId, $userId, $paramsJson]);
        error_log("[recordTaskStart] OK type={$type} table={$table} task_id={$taskId} user={$userId} rows={$stmt->rowCount()}");
    } catch (Throwable $e) {
        error_log("[recordTaskStart FAIL] type={$type} task_id={$taskId} error=" . $e->getMessage() . " params_type=" . gettype($params));
        throw $e; // 重新抛出，让调用方决定是否吞
    }
}

// 记录任务结束（UPDATE status + completed_at + result 摘要）
function recordTaskFinish(PDO $pdo, string $type, string $taskId, array $result, ?int $httpCode = null, ?int $durationMs = null): void {
    try {
        $tableMap = [
            'video' => 'video_tasks',
            'music' => 'music_tasks',
            'image' => 'image_tasks',
            'tts'   => 'tts_tasks',
        ];
        $table = $tableMap[$type] ?? 'music_tasks';
        // ── 状态判定：兼容 3 种错误格式 ──
        //   1) 顶层 'error' 字段（proxy 内部错误 / worker 回写错误）
        //   2) MiniMax base_resp.status_code 非 0（MiniMax API 业务错误）
        //   3) HTTP 状态码 4xx/5xx（curl 层错误）
        $hasTopError = isset($result['error']);
        $baseRespCode = $result['base_resp']['status_code'] ?? null;
        $hasApiError  = is_int($baseRespCode) && $baseRespCode !== 0;
        $hasHttpError = is_int($httpCode) && $httpCode >= 400;
        $isFailed = $hasTopError || $hasApiError || $hasHttpError;
        $status = $isFailed ? 'failed' : 'completed';
        // 错误信息归一化
        $fileId = $result['file_id'] ?? $result['fileID'] ?? null;
        $errMsg = $result['error']
            ?? ($result['message'] ?? null)
            ?? ($result['base_resp']['status_msg'] ?? null)
            ?? ($isFailed ? "HTTP {$httpCode}" : null);
        $stmt = $pdo->prepare("UPDATE {$table} SET status=?, http_code=?, file_id=?, error_message=?, completed_at=NOW(), duration_ms=COALESCE(?, duration_ms) WHERE task_id=?");
        $stmt->execute([
            $status,
            $httpCode,
            $fileId,
            $errMsg ? mb_substr((string)$errMsg, 0, 500) : null,
            $durationMs,
            $taskId,
        ]);
        error_log("[recordTaskFinish] type={$type} task_id={$taskId} status={$status} http={$httpCode} base_resp_code=" . var_export($baseRespCode, true));
    } catch (Throwable $e) {
        error_log("[recordTaskFinish FAIL] type={$type} task_id={$taskId} error=" . $e->getMessage());
        throw $e;
    }
}

// ── 记忆配额检查（100MB/用户） ──
function checkMemoryQuota(string $userId, int $incomingBytes): bool {
    $memoryDir = getMemoryDir($userId);
    $current = 0;
    if (is_dir($memoryDir)) $current += dirSize($memoryDir);
    return ($current + $incomingBytes) <= (100 * 1024 * 1024);
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
    // 过滤空密钥
    $keys = array_values(array_filter($keys, function($k) { return !empty($k); }));
    if (empty($keys)) return '{}';
    
    $lastError = '';
    foreach ($keys as $key) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $key,
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'model' => 'deepseek-v4-flash',
                'messages' => $messages,
                'temperature' => 0.3,
                'max_tokens' => 16384,
            ]),
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($error) { $lastError = $error; continue; }
        if ($httpCode === 429) { $lastError = 'rate_limited'; continue; }
        if ($httpCode >= 200 && $httpCode < 300) {
            $data = json_decode($response, true);
            return $data['choices'][0]['message']['content'] ?? '{}';
        }
        $lastError = "http_{$httpCode}";
        continue;
    }
    return '{}';
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
header('Access-Control-Allow-Headers: Content-Type');

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
define('QUOTA_BYTES', 1024 * 1024 * 1024); // 1GB (admin)
define('REGULAR_QUOTA_BYTES', 100 * 1024 * 1024); // 100MB (普通用户)
// ACCESS_TOKEN loaded from config.enc.php // 前端访问令牌（与 cron worker 一致）
define('GUEST_QUOTA_BYTES', 1 * 1024 * 1024 * 1024); // 1GB shared for all guests
$MIME_MAP = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','bmp'=>'image/bmp','svg'=>'image/svg+xml','mp3'=>'audio/mpeg','mp4'=>'video/mp4','pdf'=>'application/pdf','txt'=>'text/plain','html'=>'text/html','css'=>'text/css','js'=>'application/javascript','json'=>'application/json','md'=>'text/markdown','csv'=>'text/csv','zip'=>'application/zip'];

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

// ═══════════════════════════════════════
// MUD 游戏引擎（无预设剧本，LLM驱动一切叙事）
// ═══════════════════════════════════════
class GameEngine {
    private $savePath;
    private $state;
    const DEFAULT_STATE = [
        'game_started' => false,
        'image_mode' => false,
        'voice_mode' => false,
        'vision_mode' => false,
    ];

    public function __construct(string $userDir) {
        $this->savePath = $userDir . '/mud_save.json';
        $this->state = self::DEFAULT_STATE;
        if (file_exists($this->savePath)) {
            $saved = json_decode(file_get_contents($this->savePath), true);
            if (is_array($saved)) $this->state = array_merge(self::DEFAULT_STATE, $saved);
        }
    }

    private function save(): void {
        file_put_contents($this->savePath, json_encode($this->state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    public function saveFull(array $messages, string $scenario): void {
        $this->state['messages'] = $messages;
        $this->state['scenario'] = $scenario;
        $this->state['savedAt'] = date('c');
        $this->save();
    }

    public function initNewGame(): array {
        $this->state = self::DEFAULT_STATE;
        $this->state['game_started'] = true;
        // 重置时删除旧存档，确保无残留
        if (file_exists($this->savePath)) {
            @unlink($this->savePath);
        }
        $this->save();
        return ['game_output'=>'[GAME_INITIALIZED]', 'state'=>$this->getStateBlock(), 'needs_narration'=>true];
    }

    public function updateState(array $updates): array {
        // 无状态设计：LLM 可以设置任意字段，后端不预设剧本和参数
        // _added 后缀追加模式，其余整体替换
        $changed = [];
        foreach ($updates as $key => $val) {
            $lk = strtolower($key);
            if (str_ends_with($lk, '_added')) {
                // 追加模式：inventory_added 追加到 inventory
                $baseKey = substr($lk, 0, -6);
                $old = $this->state[$baseKey] ?? [];
                $add = is_array($val) ? $val : [$val];
                $this->state[$baseKey] = array_values(array_unique(array_merge($old, $add)));
                $changed[] = $baseKey . '_added';
            } else {
                // 整体替换模式：LLM 返回什么就存什么
                $this->state[$lk] = $val;
                $changed[] = $lk;
            }
        }
        if (!empty($changed)) $this->save();
        return ['game_output'=>'[STATE_UPDATED]', 'state'=>$this->getStateBlock(), 'needs_narration'=>true, 'updated_fields'=>$changed];
    }

    public function dispatchCommand(string $cmd, string $originalMsg, array $messages = [], string $scenario = ''): array {
        if (!$this->state['game_started']) return $this->initNewGame();
        $cmd = trim($cmd);
        if (preg_match('/^(状态|属性|\/status|status)$/iu', $cmd)) {
            return $this->handleStatus();
        }
        if (preg_match('/^(存档|\/save|save)$/iu', $cmd)) {
            return $this->handleSave($messages, $scenario);
        }
        if (preg_match('/^(读档|\/load|load)$/iu', $cmd)) {
            return $this->handleLoad();
        }
        if (preg_match('/^(开图|开启配图|开启图片|image_on)$/iu', $cmd)) {
            $this->state['image_mode'] = true; $this->save();
            return ['game_output'=>'[IMAGE_MODE_ON]', 'state'=>$this->getStateBlock(), 'needs_narration'=>false];
        }
        if (preg_match('/^(关图|关闭配图|关闭图片|image_off)$/iu', $cmd)) {
            $this->state['image_mode'] = false; $this->save();
            return ['game_output'=>'[IMAGE_MODE_OFF]', 'state'=>$this->getStateBlock(), 'needs_narration'=>false];
        }
        if (preg_match('/^(开声|开启语音|voice_on)$/iu', $cmd)) {
            $this->state['voice_mode'] = true; $this->save();
            return ['game_output'=>'[VOICE_MODE_ON]', 'state'=>$this->getStateBlock(), 'needs_narration'=>false];
        }
        if (preg_match('/^(关声|关闭语音|voice_off)$/iu', $cmd)) {
            $this->state['voice_mode'] = false; $this->save();
            return ['game_output'=>'[VOICE_MODE_OFF]', 'state'=>$this->getStateBlock(), 'needs_narration'=>false];
        }
        if (preg_match('/^(开识|开启识图|vision_on)$/iu', $cmd)) {
            $this->state['vision_mode'] = true; $this->save();
            return ['game_output'=>'[VISION_MODE_ON]', 'state'=>$this->getStateBlock(), 'needs_narration'=>false];
        }
        if (preg_match('/^(关识|关闭识图|vision_off)$/iu', $cmd)) {
            $this->state['vision_mode'] = false; $this->save();
            return ['game_output'=>'[VISION_MODE_OFF]', 'state'=>$this->getStateBlock(), 'needs_narration'=>false];
        }
        if (preg_match('/^(返回|重新开始|重启|restart)$/iu', $cmd)) {
            return $this->initNewGame();
        }
        return $this->handleAction($cmd, $originalMsg);
    }

    private function handleAction(string $cmd, string $originalMsg): array {
        $ctx = ($originalMsg && $originalMsg !== $cmd) ? $originalMsg : $cmd;
        return ['game_output'=>'[ACTION]', 'action'=>$ctx, 'state'=>$this->getStateBlock(), 'needs_narration'=>true];
    }

    private function handleStatus(): array {
        return ['game_output'=>'[STATUS]', 'state'=>$this->getStateBlock(), 'needs_narration'=>true];
    }

    private function handleSave(array $messages = [], string $scenario = ''): array {
        if (!empty($messages)) {
            $this->saveFull($messages, $scenario);
        } else {
            $this->save();
        }
        return ['game_output'=>'[SAVED]', 'state'=>$this->getStateBlock(), 'needs_narration'=>false];
    }

    private function handleLoad(): array {
        if (file_exists($this->savePath)) {
            $saved = json_decode(file_get_contents($this->savePath), true);
            if (is_array($saved)) {
                $this->state = array_merge(self::DEFAULT_STATE, $saved);
                return ['game_output'=>'[LOADED]', 'state'=>$this->getStateBlock(), 'needs_narration'=>false];
            }
        }
        return ['game_output'=>'[NO_SAVE]', 'state'=>$this->getStateBlock(), 'needs_narration'=>false];
    }

private function getStateBlock(): array {
        // 无状态设计：后端不预设任何剧本参数，完全由 LLM 返回的文字设定
        // 仅保证系统开关字段有默认值，其余字段原样返回
        $s = $this->state;
        $s['game_started'] = $s['game_started'] ?? false;
        $s['image_mode'] = $s['image_mode'] ?? false;
        $s['voice_mode'] = $s['voice_mode'] ?? false;
        $s['vision_mode'] = $s['vision_mode'] ?? false;
        return $s;
    }
}

// 用户系统动作路由（优先级高于 API 代理）
$action = $input['_action'] ?? $_GET['_action'] ?? '';
if (in_array($action, ['register','login','logout','session','get_proxy_token','quota','file_read','file_write','file_edit','file_delete','list_files','file_rename','file_save_from_url','file_download','generate_share_link','web_fetch','bash','memory','image_proxy','mud_action'])) {
if (session_status() === PHP_SESSION_NONE) session_start();
    // ─── 全域 Token 认证（排除无需 token 的动作） ───
    $exemptActions = ['register','login','session','get_proxy_token'];
    $requiresToken = !in_array($action, $exemptActions);
    // file_download + share_token 路径也无需 token
    if ($action === 'file_download' && !empty($input['share_token'] ?? $_GET['share_token'] ?? '')) {
        $requiresToken = false;
    }
    if ($requiresToken) {
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
            $users[$username] = password_hash($password, PASSWORD_BCRYPT);
            saveUsers($users);
            getUserDir($username);
            echo json_encode(['success'=>true,'message'=>'注册成功']);
            exit;

        case 'login':
            $username = trim($input['username'] ?? '');
            $password = $input['password'] ?? '';
            $users = loadUsers();
            if (!isset($users[$username]) || !password_verify($password, $users[$username])) {
                echo json_encode(['error'=>'用户名或密码错误']); exit;
            }
            $_SESSION['user'] = $username;
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
            $newContent = !empty($input['replace_all']) ? str_replace($oldStr, $newStr, $oldContent) : preg_replace('#'.preg_quote($oldStr, '#').'#', $newStr, $oldContent, 1);
            $diff = strlen($newContent) - strlen($oldContent);
            if (getUserUsageSafe() + $diff > getUserQuotaSafe()) { echo json_encode(['error'=>'超出存储配额']); exit; }
            file_put_contents($fullPath, $newContent);
            echo json_encode(['success'=>true,'message'=>'文件已编辑: '.$input['file_path']]);
            exit;

        case 'file_delete':
            $fullPath = getUserDirSafe() . '/' . ltrim($input['file_path'], '/');
            if (strpos($fullPath, '..') !== false) { echo json_encode(['error'=>'路径不合法']); exit; }
            if (!file_exists($fullPath)) { echo json_encode(['error'=>'文件不存在']); exit; }
            if (is_dir($fullPath)) {
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fullPath, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($it as $f) { if ($f->isDir()) rmdir($f->getRealPath()); else unlink($f->getRealPath()); }
                rmdir($fullPath);
                echo json_encode(['success'=>true,'message'=>'目录已删除']);
            } else {
                unlink($fullPath);
                echo json_encode(['success'=>true,'message'=>'文件已删除']);
            }
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
                            header('Content-Type: ' . ($GLOBALS['MIME_MAP'][$ext] ?? 'application/octet-stream'));
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
            header('Content-Type: ' . ($GLOBALS['MIME_MAP'][$ext] ?? 'application/octet-stream'));
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
            // 在用户目录内执行命令，避免触发主机 open_basedir/jailshell 限制
            $workDir = getUserDirSafe();
            $escaped = 'cd ' . escapeshellarg($workDir) . ' && ' . escapeshellcmd($cmd);
            $result = null;
            @set_time_limit($timeout + 5);
            // 尝试多种执行方式（proc_open→exec→shell_exec）
            if ($result === null && function_exists('proc_open') && !in_array('proc_open', explode(',', ini_get('disable_functions')))) {
                $descriptorspec = [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
                $process = @proc_open($escaped, $descriptorspec, $pipes, $workDir);
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
                        // 使用 MySQL FULLTEXT 搜索
                        $stmt = $pdo->prepare(
                            "SELECT fact_id, fact_preview, category, importance, access_count, 
                             MATCH(fact_preview) AGAINST(:q IN NATURAL LANGUAGE MODE) as text_score 
                             FROM memory_index 
                             WHERE user_id=:uid AND MATCH(fact_preview) AGAINST(:q IN NATURAL LANGUAGE MODE) > 0 
                             ORDER BY text_score DESC, importance DESC, access_count DESC 
                             LIMIT :lim"
                        );
                        $stmt->bindValue('q', $query, PDO::PARAM_STR);
                        $stmt->bindValue('uid', $userId, PDO::PARAM_STR);
                        $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
                        $stmt->execute();
                        $candidates = $stmt->fetchAll();
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
                                } catch (Exception $e) {}
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
        case 'mud_action':
            header('Content-Type: application/json; charset=utf-8');
            $cmd = $input['command'] ?? '';
            $originalMsg = $input['original_message'] ?? $cmd;
            $stateUpdates = isset($input['state_updates']) && is_array($input['state_updates']) ? $input['state_updates'] : null;
            if (!$cmd && !$stateUpdates) { echo json_encode(['error'=>'command or state_updates required']); exit; }
            $mudUserDir = getUserDirSafe();
            if (!is_dir($mudUserDir . '/mud')) @mkdir($mudUserDir . '/mud', 0755, true);
            $engine = new GameEngine($mudUserDir . '/mud');
            $result = null;
            if ($stateUpdates) {
                $result = $engine->updateState($stateUpdates);
            } elseif (preg_match('/^(开始泥巴游戏|开始mud|start\s*mud|新游戏)$/iu', $cmd)) {
                $result = $engine->initNewGame();
            } else {
                $messages = isset($input['messages']) && is_array($input['messages']) ? $input['messages'] : [];
                $scenario = $input['scenario'] ?? '';
                $result = $engine->dispatchCommand($cmd, $originalMsg, $messages, $scenario);
            }
            if ($result === null) $result = ['game_output'=>'[ENGINE_NULL]', 'state'=>$engine->getStateBlock(), 'needs_narration'=>false];
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            exit;
    }
    exit;
}

// ═══════════════════════════════════════
// ④ 前端访问令牌验证（API 代理需要）
// ═══════════════════════════════════════

$token = $input['_token'] ?? $_GET['_token'] ?? $_POST['_token'] ?? '';
unset($input['_token']);
unset($_POST['_token']);

if ($token !== ACCESS_TOKEN) {
    http_response_code(403);
    echo json_encode([
        'error' => 'token_invalid',
        'message' => 'Access token is invalid or missing',
    ]);
    exit;
}

// ═══════════════════════════════════════
// ⑤ 加密配置已在文件顶部加载
// ═══════════════════════════════════════

function getEndpointTimeout(string $apiPath): int {
    $timeouts = [
        '/image_generation' => 25,
        '/image_submit' => 5,
        '/image_poll' => 5,
        '/image_pending' => 5,
        '/image_process' => 120,
        '/music_generation' => 5,
        '/music_process' => 180,
        '/video_submit' => 5,
        '/video_poll' => 5,
        '/video_process' => 180,
        '/files/retrieve' => 15,
        '/files/upload' => 60,
        '/files/delete' => 15,
        '/files/retrieve_content' => 25,
        '/video_template_generation' => 25,
        '/query/video_template_generation' => 15,
        '/t2a_v2' => 28,
        '/tts_submit' => 5,
        '/tts_poll' => 5,
        '/chat/completions' => 25,
    ];
    return $timeouts[$apiPath] ?? 25;
}

function getRotationState(): array {
    $default = [];
    $file = defined('ROTATION_STATE_FILE') ? ROTATION_STATE_FILE : '/vhost/tmp/provider_rotation.json';
    if (!file_exists($file)) return $default;
    $data = @json_decode(@file_get_contents($file), true);
    return is_array($data) ? $data : $default;
}

function getRotationStartIndex(string $groupName): int {
    // 强制 minimax 组从 key0 开始（key0 拥有 image/t2a 等多模态权限）
    if ($groupName === 'minimax') return 0;
    $state = getRotationState();
    return isset($state[$groupName]['current']) ? (int)$state[$groupName]['current'] : 0;
}

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

// ── Key cooldown helpers (backed by file) ──
define('KEY_COOLDOWN_FILE', '/vhost/tmp/key_cooldowns.json');
function getKeyCooldowns(): array {
    if (!file_exists(KEY_COOLDOWN_FILE)) return [];
    $data = @json_decode(@file_get_contents(KEY_COOLDOWN_FILE), true);
    return is_array($data) ? $data : [];
}
function saveKeyCooldowns(array $cooldowns): void {
    file_put_contents(KEY_COOLDOWN_FILE, json_encode($cooldowns, JSON_PRETTY_PRINT), LOCK_EX);
}

// ── 解析请求 ──
$method = $_SERVER['REQUEST_METHOD'];
$provider_name = $input['_provider'] ?? $_GET['_provider'] ?? $_POST['_provider'] ?? 'minimax';
$api_path = $input['_path'] ?? $_GET['_path'] ?? $_POST['_path'] ?? '';
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

// 如果 provider 属于某个轮换组，展开为整条链的所有 key
if (defined('ROTATION_GROUPS')) {
    $rotGroups = @unserialize(ROTATION_GROUPS);
    if (is_array($rotGroups)) {
        foreach ($rotGroups as $groupName => $groupMembers) {
            if (in_array($provider_name, $groupMembers)) {
                $startIdx = getRotationStartIndex($groupName);
                $expanded = expandRotationKeys($groupMembers, $startIdx, $PROVIDERS);
                if (!empty($expanded)) {
                    $api_keys = $expanded;
                }
                break;
            }
        }
    }
}

// 如果没传 _path，默认使用 /chat/completions
if (!$api_path) {
    $api_path = '/chat/completions';
}

$target_url = $base_url . $api_path;

// ═══════════════════════════════════════
// ⑥ 异步图像生成处理（30s 服务器超时绕过）
// ═══════════════════════════════════════
$IMAGE_TASK_DIR = '/vhost/tmp/image_tasks';

// 惰性处理函数：由 /image_poll 触发
function processImageTaskInternal($taskId, $paramFile, $taskDir, $PROVIDERS) {
    // 锁文件防止并发处理
    $lockFile = "$taskDir/$taskId.lock";
    if (file_exists($lockFile)) {
        // 另一个进程正在处理
        return false;
    }
    
    // 创建锁文件
    file_put_contents($lockFile, time());
    
    try {
        $taskData = unserialize(file_get_contents($paramFile));
        if (!$taskData || !is_array($taskData)) {
            file_put_contents("$taskDir/$taskId.result", json_encode([
                'error' => 'invalid_params', 'message' => '任务参数损坏',
            ]));
            @unlink($lockFile);
            return true;
        }
        
        $taskProvider = $taskData['provider'] ?? 'minimax';
        $originalInput = $taskData['input'] ?? [];
        
        if (!isset($PROVIDERS[$taskProvider])) {
            file_put_contents("$taskDir/$taskId.result", json_encode([
                'error' => 'unknown_provider', 'message' => '供应商不存在: ' . $taskProvider,
            ]));
            @unlink($lockFile);
            return true;
        }
        
        $taskProvConfig = $PROVIDERS[$taskProvider];
        $taskKeys = $taskProvConfig['keys'];
        $taskBaseUrl = rtrim($taskProvConfig['base_url'], '/');
        
        $image_url = $taskBaseUrl . '/image_generation';
        $last_error = '';
        $result = null;
        
        foreach ($taskKeys as $idx => $key) {
            if (empty($key)) continue;
            $cleanInput = array_intersect_key($originalInput, array_flip(['model','prompt','aspect_ratio','n','response_format','seed','prompt_optimizer','width','height','aigc_watermark']));
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $image_url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $key,
                ],
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($cleanInput),
                CURLOPT_SSL_VERIFYPEER => true,
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
        } else {
            file_put_contents("$taskDir/$taskId.result", json_encode([
                'error' => 'proxy_all_keys_exhausted',
                'message' => '所有 API Key 均已耗尽: ' . $last_error,
            ]));
        }
        
        @unlink($lockFile);
        return true;
    } catch (Exception $e) {
        @unlink($lockFile);
        return false;
    }
}

if ($api_path === '/image_submit') {
    $taskId = bin2hex(random_bytes(8));
    if (!is_dir($IMAGE_TASK_DIR)) {
        @mkdir($IMAGE_TASK_DIR, 0755, true);
    }
    // 保存 provider 信息和请求参数
    $taskData = [
        'provider' => $provider_name,
        'input' => $input,
    ];
    file_put_contents("$IMAGE_TASK_DIR/$taskId.params", serialize($taskData));

    // P2: DB 持久化（image_tasks 表）
    try {
        recordTaskStart(getMemoryDB(), 'image', $taskId, getAuthenticatedUserId() ?? 'guest', $input);
    } catch (Throwable $e) { /* 静默失败 */ }

    header('Content-Type: application/json');
    echo json_encode([
        'task_id' => $taskId,
        'status' => 'processing',
        'message' => '图像生成已提交',
    ]);
    exit;
}

if ($api_path === '/image_poll') {
    $taskId = $input['task_id'] ?? $_GET['task_id'] ?? '';
    if (!$taskId || !preg_match('/^[a-f0-9]{16}$/', $taskId)) {
        echo json_encode(['error' => 'invalid_task_id', 'message' => '无效的 task_id']);
        exit;
    }
    $resultFile = "$IMAGE_TASK_DIR/$taskId.result";
    $paramFile = "$IMAGE_TASK_DIR/$taskId.params";
    header('Content-Type: application/json');

    // 如果结果已存在，直接返回
    if (file_exists($resultFile)) {
        $content = file_get_contents($resultFile);
        $resultData = json_decode($content, true) ?: [];
        // P2: DB UPDATE
        try {
            $httpCode = $resultData['_http_code'] ?? null;
            recordTaskFinish(getMemoryDB(), 'image', $taskId, $resultData, is_int($httpCode) ? $httpCode : null);
        } catch (Throwable $e) {}
        @unlink($resultFile);
        @unlink($paramFile);
        echo $content;
        exit;
    }

    // 惰性处理：如果任务还在 pending，尝试处理它
    if (file_exists($paramFile)) {
        // 调用处理逻辑（内部函数，不通过 HTTP）
        $processResult = processImageTaskInternal($taskId, $paramFile, $IMAGE_TASK_DIR, $PROVIDERS);
        if ($processResult && file_exists($resultFile)) {
            $content = file_get_contents($resultFile);
            $resultData = json_decode($content, true) ?: [];
            // P2: DB UPDATE
            try {
                $httpCode = $resultData['_http_code'] ?? null;
                recordTaskFinish(getMemoryDB(), 'image', $taskId, $resultData, is_int($httpCode) ? $httpCode : null);
            } catch (Throwable $e) {}
            @unlink($resultFile);
            @unlink($paramFile);
            echo $content;
            exit;
        }
    }

    echo json_encode(['status' => 'pending']);
    exit;
}

if ($api_path === '/image_pending') {
    header('Content-Type: application/json');
    $pending = [];
    if (is_dir($IMAGE_TASK_DIR)) {
        foreach (glob("$IMAGE_TASK_DIR/*.params") as $paramFile) {
            $id = basename($paramFile, '.params');
            if (!preg_match('/^[a-f0-9]{16}$/', $id)) continue;
            if (!file_exists("$IMAGE_TASK_DIR/$id.result")) {
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

if ($api_path === '/image_process') {
    header('Content-Type: application/json');
    $taskId = $_GET['task_id'] ?? $input['task_id'] ?? '';
    if (!$taskId || !preg_match('/^[a-f0-9]{16}$/', $taskId)) {
        echo json_encode(['error' => 'invalid_task_id']);
        exit;
    }
    $paramFile = "$IMAGE_TASK_DIR/$taskId.params";
    if (!file_exists($paramFile)) {
        echo json_encode(['error' => 'task_not_found']);
        exit;
    }
    if (file_exists("$IMAGE_TASK_DIR/$taskId.result")) {
        $content = file_get_contents("$IMAGE_TASK_DIR/$taskId.result");
        echo $content;
        exit;
    }
    $taskData = unserialize(file_get_contents($paramFile));
    if (!$taskData || !is_array($taskData)) {
        file_put_contents("$IMAGE_TASK_DIR/$taskId.result", json_encode([
            'error' => 'invalid_params', 'message' => '任务参数损坏',
        ]));
        echo json_encode(['status' => 'failed', 'error' => 'invalid_params']);
        exit;
    }
    // 从保存的任务数据中恢复 provider 和 input
    $taskProvider = $taskData['provider'] ?? 'minimax';
    $originalInput = $taskData['input'] ?? [];
    
    // 获取该 provider 的密钥
    if (!isset($PROVIDERS[$taskProvider])) {
        file_put_contents("$IMAGE_TASK_DIR/$taskId.result", json_encode([
            'error' => 'unknown_provider', 'message' => '供应商不存在: ' . $taskProvider,
        ]));
        echo json_encode(['status' => 'failed', 'error' => 'unknown_provider']);
        exit;
    }
    $taskProvConfig = $PROVIDERS[$taskProvider];
    $taskKeys = $taskProvConfig['keys'];
    $taskBaseUrl = rtrim($taskProvConfig['base_url'], '/');
    
    $image_url = $taskBaseUrl . '/image_generation';
    $last_error = '';
    $result = null;
    foreach ($taskKeys as $idx => $key) {
        if (empty($key)) continue;
        $cleanInput = array_intersect_key($originalInput, array_flip(['model','prompt','aspect_ratio','n','response_format','seed','prompt_optimizer','width','height','aigc_watermark']));
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $image_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
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
        file_put_contents("$IMAGE_TASK_DIR/$taskId.result", json_encode($output));
        @unlink($paramFile);
        echo json_encode(['status' => 'completed']);
    } else {
        file_put_contents("$IMAGE_TASK_DIR/$taskId.result", json_encode([
            'error' => 'proxy_all_keys_exhausted',
            'message' => '所有 API Key 均已耗尽: ' . $last_error,
        ]));
        echo json_encode(['status' => 'failed', 'error' => $last_error]);
    }
    exit;
}

// ═══════════════════════════════════════
// ⑦ 异步音乐生成处理
// ═══════════════════════════════════════
// MiniMax 音乐生成耗时 60-90s，但 XinCache nginx proxy_read_timeout 约 60s → 504
// 方案：
//   ① proxy.php 立即返回 task_id（fastcgi_finish_request）
//   ② PHP 继续在后台执行 curl（IO 等待不计入 max_execution_time=30s）
//   ③ 前端轮询 /music_poll 拿结果
// ⚠️ 所有进程执行函数（exec/proc_open/shell_exec）均已禁用，无法启动独立 worker
//     必须用 fastcgi_finish_request 在同一进程里"去前台后处理"

if ($api_path === '/music_generation') {
    $taskId = bin2hex(random_bytes(8));
    $taskDir = '/vhost/tmp/music_tasks';
    if (!is_dir($taskDir)) {
        @mkdir($taskDir, 0755, true);
    }

    $taskData = [
        'provider' => $provider_name,
        'input' => $input,
    ];
    file_put_contents("$taskDir/$taskId.params", serialize($taskData));

    // ── P2: DB 持久化（worker.sh 路径也能查到）──
    try {
        recordTaskStart(getMemoryDB(), 'music', $taskId, getAuthenticatedUserId() ?? 'guest', $input);
    } catch (Throwable $e) { /* 静默失败，不影响主流程 */ }

    header('Content-Type: application/json');
    echo json_encode([
        'task_id' => $taskId,
        'status' => 'processing',
        'message' => '音乐生成已提交',
    ]);

    // fastcgi_finish_request: 关闭 nginx 连接（防 30s 超时），PHP 继续后台处理
    $bgProcess = function() use ($taskId, $taskDir, $taskData, $PROVIDERS) {
        $taskProvConfig = $PROVIDERS[$taskData['provider']] ?? null;
        if (!$taskProvConfig) { @unlink("$taskDir/$taskId.params"); return; }
        $taskKeys = $taskProvConfig['keys'];
        $taskBaseUrl = rtrim($taskProvConfig['base_url'], '/');
        $musicUrl = $taskBaseUrl . '/music_generation';
        $originalInput = $taskData['input'];
        // 确保使用 url 格式
        if (!isset($originalInput['output_format'])) {
            $originalInput['output_format'] = 'url';
        }
        $last_error = '';
        $result = null;
        foreach ($taskKeys as $idx => $key) {
            if (empty($key)) continue;
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $musicUrl,
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
            @unlink("$taskDir/$taskId.params");
            // P2: DB 持久化（音乐生成完成）
            try { recordTaskFinish(getMemoryDB(), 'music', $taskId, $output, $result['http_code']); } catch (Throwable $e) {}
        } else {
            $errOutput = [
                'error' => 'proxy_all_keys_exhausted',
                'message' => '所有 API Key 均已耗尽: ' . $last_error,
            ];
            file_put_contents("$taskDir/$taskId.result", json_encode($errOutput));
            // P2: DB 持久化（音乐失败）
            try { recordTaskFinish(getMemoryDB(), 'music', $taskId, $errOutput, null); } catch (Throwable $e) {}
        }
    };

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        $bgProcess();
    }
    // fallback: 无 fastcgi_finish_request → 文件排队等 cron 轮询
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
    $paramFile = "$taskDir/$taskId.params";

    header('Content-Type: application/json');
    if (file_exists($resultFile)) {
        $content = file_get_contents($resultFile);
        @unlink($resultFile);
        @unlink($paramFile);
        echo $content;
        exit;
    }
    
    // 惰性处理：如果任务还在 pending，尝试处理它
    if (file_exists($paramFile)) {
        $lockFile = "$taskDir/$taskId.lock";
        if (!file_exists($lockFile)) {
            // 直接调用 music_process 逻辑
            $_GET['task_id'] = $taskId;
            // 模拟调用 /music_process
            $taskData = unserialize(file_get_contents($paramFile));
            if ($taskData && is_array($taskData)) {
                $taskProvider = $taskData['provider'] ?? 'minimax';
                $originalInput = $taskData['input'] ?? [];
                if (isset($PROVIDERS[$taskProvider])) {
                    file_put_contents($lockFile, time());
                    $taskProvConfig = $PROVIDERS[$taskProvider];
                    $taskKeys = $taskProvConfig['keys'];
                    $taskBaseUrl = rtrim($taskProvConfig['base_url'], '/');
                    $music_url = $taskBaseUrl . '/music_generation';
                    $last_error = '';
                    $result = null;
                    foreach ($taskKeys as $idx => $key) {
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
                        @unlink($lockFile);
                        echo json_encode($output);
                        exit;
                    } else {
                        file_put_contents("$taskDir/$taskId.result", json_encode([
                            'error' => 'proxy_all_keys_exhausted',
                            'message' => '所有 API Key 均已耗尽: ' . $last_error,
                        ]));
                        @unlink($lockFile);
                    }
                }
            }
        }
    }
    
    echo json_encode(['status' => 'pending']);
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

    // 读取任务数据（包含 provider 和 input）
    $taskData = unserialize(file_get_contents($paramFile));
    if (!$taskData || !is_array($taskData)) {
        file_put_contents("$taskDir/$taskId.result", json_encode([
            'error' => 'invalid_params',
            'message' => '任务参数损坏',
        ]));
        echo json_encode(['status' => 'failed', 'error' => 'invalid_params']);
        exit;
    }

    // 从保存的任务数据中恢复 provider 和 input
    $taskProvider = $taskData['provider'] ?? 'minimax';
    $originalInput = $taskData['input'] ?? [];
    
    // 获取该 provider 的密钥
    if (!isset($PROVIDERS[$taskProvider])) {
        file_put_contents("$taskDir/$taskId.result", json_encode([
            'error' => 'unknown_provider',
            'message' => '供应商不存在: ' . $taskProvider,
        ]));
        echo json_encode(['status' => 'failed', 'error' => 'unknown_provider']);
        exit;
    }
    $taskProvConfig = $PROVIDERS[$taskProvider];
    $taskKeys = $taskProvConfig['keys'];
    $taskBaseUrl = rtrim($taskProvConfig['base_url'], '/');

    // 调用 MiniMax API（同步，长超时 180s）
    $music_url = $taskBaseUrl . '/music_generation';
    $last_error = '';
    $result = null;

    foreach ($taskKeys as $idx => $key) {
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
        // P2: DB 持久化（worker 路径 → music_process 完成）
        try { recordTaskFinish(getMemoryDB(), 'music', $taskId, $output, $result['http_code']); } catch (Throwable $e) {}
        echo json_encode(['status' => 'completed']);
    } else {
        $errOutput = [
            'error' => 'proxy_all_keys_exhausted',
            'message' => '所有 API Key 均已耗尽: ' . $last_error,
        ];
        file_put_contents("$taskDir/$taskId.result", json_encode($errOutput));
        // P2: DB 持久化（worker 路径 → music_process 失败）
        try { recordTaskFinish(getMemoryDB(), 'music', $taskId, $errOutput, null); } catch (Throwable $e) {}
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
    // P2: DB 持久化（worker.sh 通过 music_write_result 回写）
    try {
        $httpCode = $resultData['_http_code'] ?? null;
        recordTaskFinish(getMemoryDB(), 'music', $taskId, $resultData, is_int($httpCode) ? $httpCode : null);
    } catch (Throwable $e) {}
    echo json_encode(['status' => 'saved']);
    exit;
}

// 获取供应商配置（仅供系统 crontab 从本机服务器调用，不暴露给浏览器）
if ($api_path === '/music_get_provider') {
    header('Content-Type: application/json');
    // IP 限制：只允许本机 Hermes 服务器调用
    $allowedIPs = ['156.227.27.58'];
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
// 音乐/视频任务统计查询（P3）
// ═══════════════════════════════════════
// 入参（GET 或 POST body）：
//   type  = 'music' | 'video'（默认 music）
//   group = 'month' | 'day' | 'status' | 'user'（默认 month）
//   month = 'YYYY-MM'（限定月份，可选）
//   user  = 'user_id'（限定用户，可选）
// 输出：按 group 分组的 COUNT(*)，含 completed/failed/total
function taskStatsQuery(PDO $pdo, string $type, array $input): array {
    $table = $type === 'video' ? 'video_tasks' : 'music_tasks';
    $group = $input['group'] ?? $_GET['group'] ?? 'month';
    $month = $input['month'] ?? $_GET['month'] ?? null;
    $user  = $input['user']  ?? $_GET['user']  ?? null;
    $taskType = $input['task_type'] ?? $_GET['task_type'] ?? null; // 过滤子类型（normal/template）

    $where = ['1=1'];
    $bind  = [];
    if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
        $where[] = 'DATE_FORMAT(created_at, ?) = ?';
        $bind[]  = '%Y-%m';
        $bind[]  = $month;
    }
    if ($user) {
        $where[] = 'user_id = ?';
        $bind[]  = $user;
    }
    // task_type 过滤：params JSON 字段里 JSON_EXTRACT(...,'$.task_type')
    if ($taskType) {
        $where[] = "JSON_UNQUOTE(JSON_EXTRACT(params, '$.task_type')) = ?";
        $bind[]  = $taskType;
    }
    $whereSql = implode(' AND ', $where);

    // 总数（不受 group 影响）
    $totalStmt = $pdo->prepare("SELECT COUNT(*) AS total,
        SUM(status='completed') AS completed,
        SUM(status='failed')    AS failed,
        SUM(status='pending')   AS pending
        FROM {$table} WHERE {$whereSql}");
    $totalStmt->execute($bind);
    $totals = $totalStmt->fetch();

    // 按 group 分组
    $groupMap = [
        'day'    => "DATE_FORMAT(created_at, '%Y-%m-%d')",
        'status' => 'status',
        'user'   => 'user_id',
        'month'  => "DATE_FORMAT(created_at, '%Y-%m')",
    ];
    $groupBy = $groupMap[$group] ?? $groupMap['month'];

    $grpStmt = $pdo->prepare("SELECT {$groupBy} AS bucket, COUNT(*) AS total,
        SUM(status='completed') AS completed,
        SUM(status='failed')    AS failed,
        SUM(status='pending')   AS pending
        FROM {$table} WHERE {$whereSql}
        GROUP BY bucket ORDER BY bucket DESC LIMIT 100");
    $grpStmt->execute($bind);
    $buckets = $grpStmt->fetchAll();

    return [
        'type'    => $type,
        'totals'  => [
            'total'     => (int)($totals['total'] ?? 0),
            'completed' => (int)($totals['completed'] ?? 0),
            'failed'    => (int)($totals['failed'] ?? 0),
            'pending'   => (int)($totals['pending'] ?? 0),
        ],
        'group'   => $group,
        'buckets' => array_map(fn($r) => [
            'bucket'    => $r['bucket'],
            'total'     => (int)$r['total'],
            'completed' => (int)$r['completed'],
            'failed'    => (int)$r['failed'],
            'pending'   => (int)$r['pending'],
        ], $buckets),
    ];
}

if ($api_path === '/music_stats' || $api_path === '/video_stats') {
    header('Content-Type: application/json');
    $type = ($api_path === '/video_stats') ? 'video' : 'music';
    try {
        $result = taskStatsQuery(getMemoryDB(), $type, $input);
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'stats_failed', 'message' => $e->getMessage()]);
    }
    exit;
}

// image_stats / tts_stats 端点（复用 taskStatsQuery）
if ($api_path === '/image_stats' || $api_path === '/tts_stats') {
    header('Content-Type: application/json');
    $type = ($api_path === '/image_stats') ? 'image' : 'tts';
    try {
        $result = taskStatsQuery(getMemoryDB(), $type, $input);
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'stats_failed', 'message' => $e->getMessage()]);
    }
    exit;
}

// ═══════════════════════════════════════
// TTS 任务记录（text_to_speech → /tts_record + /t2a_v2）
// ═══════════════════════════════════════
// 设计：保持 UI 调 /t2a_v2 同步路径不变，仅在 UI 调用前后用 /tts_record 记录任务
// 优势：TTS 是短任务（5-30s），同步调用更简单；DB 记录提供 stats + 失败归因

// TTS 任务开始记录（UI 调用 /t2a_v2 前调用）
if ($api_path === '/tts_record') {
    header('Content-Type: application/json');
    $taskId = bin2hex(random_bytes(8));
    try {
        recordTaskStart(getMemoryDB(), 'tts', $taskId, getAuthenticatedUserId() ?? 'guest', $input);
        echo json_encode(['task_id' => $taskId, 'status' => 'recorded']);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'record_failed', 'message' => $e->getMessage()]);
    }
    exit;
}

// TTS 任务完成记录（UI 调用 /t2a_v2 后调用）
if ($api_path === '/tts_finish') {
    header('Content-Type: application/json');
    $taskId = $input['task_id'] ?? $_GET['task_id'] ?? '';
    $resultData = $input['result'] ?? [];
    if (!$taskId || !preg_match('/^[a-f0-9]{16}$/', $taskId)) {
        echo json_encode(['error' => 'invalid_task_id']);
        exit;
    }
    try {
        $httpCode = $resultData['_http_code'] ?? null;
        $textChars = isset($input['text_chars']) ? (int)$input['text_chars'] : null;
        recordTaskFinish(getMemoryDB(), 'tts', $taskId, $resultData, is_int($httpCode) ? $httpCode : null);
        // 额外记录 text_chars
        if ($textChars !== null) {
            $pdo = getMemoryDB();
            $pdo->prepare("UPDATE tts_tasks SET text_chars=? WHERE task_id=?")->execute([$textChars, $taskId]);
        }
        echo json_encode(['status' => 'recorded']);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'record_failed', 'message' => $e->getMessage()]);
    }
    exit;
}

// ═══════════════════════════════════════
// TTS 异步回退处理（sync 超时 → submit + poll）
// ═══════════════════════════════════════

// 惰性处理函数：由 /tts_poll 触发，后台执行 TTS API 调用
function processTtsTaskInternal($taskId, $paramFile, $taskDir, $PROVIDERS) {
    $lockFile = "$taskDir/$taskId.lock";
    if (file_exists($lockFile)) return false;
    file_put_contents($lockFile, time());
    try {
        $taskData = unserialize(file_get_contents($paramFile));
        if (!$taskData || !is_array($taskData)) {
            file_put_contents("$taskDir/$taskId.result", json_encode([
                'error' => 'invalid_params', 'message' => '任务参数损坏',
            ]));
            @unlink($lockFile);
            return true;
        }
        $taskProvider = $taskData['provider'] ?? 'minimax';
        $originalInput = $taskData['input'] ?? [];
        if (!isset($PROVIDERS[$taskProvider])) {
            file_put_contents("$taskDir/$taskId.result", json_encode([
                'error' => 'unknown_provider', 'message' => '供应商不存在: ' . $taskProvider,
            ]));
            @unlink($lockFile);
            return true;
        }
        $taskProvConfig = $PROVIDERS[$taskProvider];
        $taskKeys = $taskProvConfig['keys'];
        $taskBaseUrl = rtrim($taskProvConfig['base_url'], '/');
        $tts_url = $taskBaseUrl . '/t2a_v2';
        $last_error = '';
        $result = null;
        foreach ($taskKeys as $idx => $key) {
            if (empty($key)) continue;
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $tts_url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $key,
                ],
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($originalInput),
                CURLOPT_SSL_VERIFYPEER => true,
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
        } else {
            file_put_contents("$taskDir/$taskId.result", json_encode([
                'error' => 'proxy_all_keys_exhausted',
                'message' => '所有 API Key 均已耗尽: ' . $last_error,
            ]));
        }
        @unlink($lockFile);
        return true;
    } catch (Exception $e) {
        @unlink($lockFile);
        return false;
    }
}

$TTS_TASK_DIR = '/vhost/tmp/tts_tasks';

// TTS 任务提交（异步回退入口）
if ($api_path === '/tts_submit') {
    $taskId = bin2hex(random_bytes(8));
    if (!is_dir($TTS_TASK_DIR)) {
        @mkdir($TTS_TASK_DIR, 0755, true);
    }
    $taskData = [
        'provider' => $provider_name,
        'input' => $input,
    ];
    file_put_contents("$TTS_TASK_DIR/$taskId.params", serialize($taskData));

    // DB 持久化
    try {
        recordTaskStart(getMemoryDB(), 'tts', $taskId, getAuthenticatedUserId() ?? 'guest', $input);
    } catch (Throwable $e) {}

    header('Content-Type: application/json');
    echo json_encode([
        'task_id' => $taskId,
        'status' => 'processing',
        'message' => '语音生成已提交',
    ]);

    // fastcgi_finish_request + 后台处理
    $bgTtsProcess = function() use ($taskId, $TTS_TASK_DIR, $taskData, $PROVIDERS) {
        $processResult = processTtsTaskInternal($taskId, "$TTS_TASK_DIR/$taskId.params", $TTS_TASK_DIR, $PROVIDERS);
        if ($processResult) {
            $resultFile = "$TTS_TASK_DIR/$taskId.result";
            if (file_exists($resultFile)) {
                $content = file_get_contents($resultFile);
                $resultData = json_decode($content, true) ?: [];
                try {
                    $httpCode = $resultData['_http_code'] ?? null;
                    recordTaskFinish(getMemoryDB(), 'tts', $taskId, $resultData, is_int($httpCode) ? $httpCode : null);
                } catch (Throwable $e) {}
            }
        }
    };

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        $bgTtsProcess();
    }
    exit;
}

// TTS 任务结果查询
if ($api_path === '/tts_poll') {
    $taskId = $input['task_id'] ?? $_GET['task_id'] ?? '';
    if (!$taskId || !preg_match('/^[a-f0-9]{16}$/', $taskId)) {
        echo json_encode(['error' => 'invalid_task_id', 'message' => '无效的 task_id']);
        exit;
    }
    $resultFile = "$TTS_TASK_DIR/$taskId.result";
    $paramFile = "$TTS_TASK_DIR/$taskId.params";

    header('Content-Type: application/json');
    if (file_exists($resultFile)) {
        $content = file_get_contents($resultFile);
        $resultData = json_decode($content, true) ?: [];
        try {
            $httpCode = $resultData['_http_code'] ?? null;
            recordTaskFinish(getMemoryDB(), 'tts', $taskId, $resultData, is_int($httpCode) ? $httpCode : null);
        } catch (Throwable $e) {}
        @unlink($resultFile);
        @unlink($paramFile);
        echo $content;
        exit;
    }

    // 惰性处理：如果任务还在 pending，尝试处理它
    if (file_exists($paramFile)) {
        $processResult = processTtsTaskInternal($taskId, $paramFile, $TTS_TASK_DIR, $PROVIDERS);
        if ($processResult && file_exists($resultFile)) {
            $content = file_get_contents($resultFile);
            $resultData = json_decode($content, true) ?: [];
            try {
                $httpCode = $resultData['_http_code'] ?? null;
                recordTaskFinish(getMemoryDB(), 'tts', $taskId, $resultData, is_int($httpCode) ? $httpCode : null);
            } catch (Throwable $e) {}
            @unlink($resultFile);
            @unlink($paramFile);
            echo $content;
            exit;
        }
    }

    echo json_encode(['status' => 'pending']);
    exit;
}

// 调试端点：直接测 INSERT/UPDATE（admin 鉴权）
if ($api_path === '/_debug_task_insert' && ($_GET['debug'] ?? '') === 'test123') {
    header('Content-Type: application/json');
    $op = $_GET['op'] ?? 'debug';
    try {
        $pdo = getMemoryDB();
        if ($op === 'debug') {
            $table = ($_GET['type'] ?? 'music') === 'video' ? 'video_tasks' : 'music_tasks';
            $tid = $_GET['task_id'] ?? 'dbg1234567890ab';
            $stmt = $pdo->prepare("INSERT INTO {$table} (task_id, user_id, status, http_code, file_id) VALUES (?, 'debug', 'completed', 200, 'debug_001') ON DUPLICATE KEY UPDATE status='completed', http_code=200");
            $stmt->execute([$tid]);
            $check = $pdo->query("SELECT task_id, status FROM {$table} WHERE task_id='{$tid}'")->fetchAll(PDO::FETCH_ASSOC);
            $pdo->prepare("DELETE FROM {$table} WHERE task_id=?")->execute([$tid]);
            echo json_encode(['success' => true, 'verified' => $check, 'pdo_driver' => $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)], JSON_UNESCAPED_UNICODE);
        } elseif ($op === 'call_record') {
            // 直接调 recordTaskStart，模拟 music_generation 流程
            $tid = $_GET['task_id'] ?? 'rec1234567890ab';
            $params = ['model' => 'music-2.6-free', 'prompt' => 'test', 'is_instrumental' => true];
            recordTaskStart($pdo, 'music', $tid, 'guest', $params);
            $check = $pdo->query("SELECT task_id, user_id, status FROM music_tasks WHERE task_id='{$tid}'")->fetchAll(PDO::FETCH_ASSOC);
            $pdo->prepare("DELETE FROM music_tasks WHERE task_id=?")->execute([$tid]);
            echo json_encode(['success' => true, 'record_called' => true, 'verified' => $check], JSON_UNESCAPED_UNICODE);
        } elseif ($op === 'full_flow') {
            // 完全模拟 music_generation 入口的所有步骤
            $tid = bin2hex(random_bytes(8));
            $params = ['model' => 'music-2.6-free', 'prompt' => 'test full flow', 'is_instrumental' => true];
            // Step 1: recordTaskStart
            recordTaskStart($pdo, 'music', $tid, 'guest', $params);
            // Step 2: 模拟 recordTaskFinish（用户立即取消/失败）
            $result = ['error' => 'invalid_params', 'message' => 'test error'];
            recordTaskFinish($pdo, 'music', $tid, $result, 400);
            $check = $pdo->query("SELECT task_id, user_id, status, http_code, error_message FROM music_tasks WHERE task_id='{$tid}'")->fetchAll(PDO::FETCH_ASSOC);
            $pdo->prepare("DELETE FROM music_tasks WHERE task_id=?")->execute([$tid]);
            echo json_encode([
                'success' => true,
                'task_id' => $tid,
                'verified_after_both' => $check,
            ], JSON_UNESCAPED_UNICODE);
        }
    } catch (Throwable $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'error_class' => get_class($e),
            'trace' => $e->getTraceAsString(),
        ], JSON_UNESCAPED_UNICODE);
    }
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
    $taskData = [
        'provider' => $provider_name,
        'input' => $input,
    ];
    file_put_contents("$VIDEO_TASK_DIR/$taskId.params", serialize($taskData));

    // ── P2: DB 持久化（worker.sh 路径也能查到）──
    try {
        recordTaskStart(getMemoryDB(), 'video', $taskId, getAuthenticatedUserId() ?? 'guest', $input);
    } catch (Throwable $e) { /* 静默失败，不影响主流程 */ }

    header('Content-Type: application/json');
    echo json_encode([
        'task_id' => $taskId,
        'status' => 'processing',
        'message' => '视频生成已提交',
    ]);

    // fastcgi_finish_request: 关闭 nginx 连接（防 30s 超时），PHP 继续后台处理
    $videoBgProcess = function() use ($taskId, $VIDEO_TASK_DIR, $taskData, $PROVIDERS) {
        $taskProvConfig = $PROVIDERS[$taskData['provider']] ?? null;
        if (!$taskProvConfig) { @unlink("$VIDEO_TASK_DIR/$taskId.params"); return; }
        $taskKeys = $taskProvConfig['keys'];
        $taskBaseUrl = rtrim($taskProvConfig['base_url'], '/');
        $videoUrl = $taskBaseUrl . '/video_generation';
        $originalInput = $taskData['input'];
        $last_error = '';
        $result = null;
        foreach ($taskKeys as $idx => $key) {
            if (empty($key)) continue;
            $cleanInput = array_intersect_key($originalInput, array_flip(['model','prompt','first_frame_image','last_frame_image','subject_reference']));
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $videoUrl,
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
            @unlink("$VIDEO_TASK_DIR/$taskId.params");
            // P2: DB 持久化（视频生成完成）
            try { recordTaskFinish(getMemoryDB(), 'video', $taskId, $output, $result['http_code']); } catch (Throwable $e) {}
        } else {
            $errOutput = [
                'error' => 'proxy_all_keys_exhausted',
                'message' => '所有 API Key 均已耗尽: ' . $last_error,
            ];
            file_put_contents("$VIDEO_TASK_DIR/$taskId.result", json_encode($errOutput));
            // P2: DB 持久化（视频失败）
            try { recordTaskFinish(getMemoryDB(), 'video', $taskId, $errOutput, null); } catch (Throwable $e) {}
        }
    };

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        $videoBgProcess();
    }
    // fallback: 文件排队等 cron 轮询
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
    $paramFile = "$VIDEO_TASK_DIR/$taskId.params";
    header('Content-Type: application/json');
    if (file_exists($resultFile)) {
        $content = file_get_contents($resultFile);
        @unlink($resultFile);
        @unlink($paramFile);
        echo $content;
        exit;
    }
    
    // 惰性处理：如果任务还在 pending，尝试处理它
    if (file_exists($paramFile)) {
        $lockFile = "$VIDEO_TASK_DIR/$taskId.lock";
        if (!file_exists($lockFile)) {
            file_put_contents($lockFile, time());
            $taskData = unserialize(file_get_contents($paramFile));
            if ($taskData && is_array($taskData)) {
                $taskProvider = $taskData['provider'] ?? 'minimax';
                $originalInput = $taskData['input'] ?? [];
                if (isset($PROVIDERS[$taskProvider])) {
                    $taskProvConfig = $PROVIDERS[$taskProvider];
                    $taskKeys = $taskProvConfig['keys'];
                    $taskBaseUrl = rtrim($taskProvConfig['base_url'], '/');
                    $video_url = $taskBaseUrl . '/video_generation';
                    $last_error = '';
                    $result = null;
                    foreach ($taskKeys as $idx => $key) {
                        if (empty($key)) continue;
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
                        @unlink($lockFile);
                        echo json_encode($output);
                        exit;
                    } else {
                        file_put_contents("$VIDEO_TASK_DIR/$taskId.result", json_encode([
                            'error' => 'proxy_all_keys_exhausted',
                            'message' => '所有 API Key 均已耗尽: ' . $last_error,
                        ]));
                        @unlink($lockFile);
                    }
                }
            }
        }
    }
    
    echo json_encode(['status' => 'pending']);
    exit;
}

// 视频生成任务状态查询（直接调用 MiniMax API）
if ($api_path === '/query/video_generation') {
    $queryTaskId = $input['task_id'] ?? $_GET['task_id'] ?? '';
    if (!$queryTaskId) {
        echo json_encode(['error' => 'missing_task_id']);
        exit;
    }
    $query_url = $base_url . '/query/video_generation?task_id=' . urlencode($queryTaskId);
    $last_error = '';
    foreach ($api_keys as $idx => $key) {
        if (empty($key)) continue;
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $query_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($error) { $last_error = $error; continue; }
        if ($http_code !== 200) { $last_error = "HTTP $http_code"; continue; }
        echo $response;
        exit;
    }
    http_response_code(503);
    echo json_encode(['error' => 'download_failed', 'message' => $last_error]);
    exit;
}

// 文件列表查询（GET）
if (strpos($api_path, '/files/list') !== false) {
    $queryStr = '';
    $parts = parse_url($api_path);
    if (!empty($parts['query'])) $queryStr = '?' . $parts['query'];
    $list_url = $base_url . '/files/list' . $queryStr;
    $last_error = '';
    foreach ($api_keys as $idx => $key) {
        if (empty($key)) continue;
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $list_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($error) { $last_error = $error; continue; }
        if ($http_code !== 200) { $last_error = "HTTP $http_code"; continue; }
        echo $response;
        exit;
    }
    http_response_code(503);
    echo json_encode(['error' => 'list_failed', 'message' => $last_error]);
    exit;
}

// 查询视频Agent模板生成任务状态
if ($api_path === '/query/video_template_generation') {
    $queryTaskId = $input['task_id'] ?? $_GET['task_id'] ?? '';
    if (!$queryTaskId) {
        echo json_encode(['error' => 'missing_task_id']);
        exit;
    }
    $query_url = $base_url . '/query/video_template_generation?task_id=' . urlencode($queryTaskId);
    $last_error = '';
    foreach ($api_keys as $idx => $key) {
        if (empty($key)) continue;
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $query_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($error) { $last_error = $error; continue; }
        if ($http_code !== 200) { $last_error = "HTTP $http_code"; continue; }
        echo $response;
        exit;
    }
    http_response_code(503);
    echo json_encode(['error' => 'query_failed', 'message' => $last_error]);
    exit;
}

// 视频文件下载（通过 file_id 获取下载链接）
 if (strpos($api_path, '/files/retrieve') !== false && strpos($api_path, 'retrieve_content') === false) {
    $fileId = $input['file_id'] ?? $_GET['file_id'] ?? '';
    $queryStr = $fileId ? '?file_id=' . urlencode($fileId) : '';
    // 从完整路径提取 query 参数
    $parts = parse_url($api_path);
    if (!empty($parts['query'])) $queryStr = '?' . $parts['query'];
    $download_url = $base_url . '/files/retrieve' . $queryStr;
    $last_error = '';
    foreach ($api_keys as $idx => $key) {
        if (empty($key)) continue;
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $download_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($error) { $last_error = $error; continue; }
        if ($http_code !== 200) { $last_error = "HTTP $http_code"; continue; }
        echo $response;
        exit;
    }
    http_response_code(503);
    echo json_encode(['error' => 'download_failed', 'message' => $last_error]);
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
    $taskData = unserialize(file_get_contents($paramFile));
    if (!$taskData || !is_array($taskData)) {
        file_put_contents("$VIDEO_TASK_DIR/$taskId.result", json_encode([
            'error' => 'invalid_params', 'message' => '任务参数损坏',
        ]));
        echo json_encode(['status' => 'failed', 'error' => 'invalid_params']);
        exit;
    }
    // 从保存的任务数据中恢复 provider 和 input
    $taskProvider = $taskData['provider'] ?? 'minimax';
    $originalInput = $taskData['input'] ?? [];
    
    // 获取该 provider 的密钥
    if (!isset($PROVIDERS[$taskProvider])) {
        file_put_contents("$VIDEO_TASK_DIR/$taskId.result", json_encode([
            'error' => 'unknown_provider', 'message' => '供应商不存在: ' . $taskProvider,
        ]));
        echo json_encode(['status' => 'failed', 'error' => 'unknown_provider']);
        exit;
    }
    $taskProvConfig = $PROVIDERS[$taskProvider];
    $taskKeys = $taskProvConfig['keys'];
    $taskBaseUrl = rtrim($taskProvConfig['base_url'], '/');
    
    $video_url = $taskBaseUrl . '/video_generation';
    $last_error = '';
    $result = null;
    foreach ($taskKeys as $idx => $key) {
        if (empty($key)) continue;
        // 过滤：仅保留 MiniMax API 支持的字段
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
        // P2: DB 持久化（worker 路径 → video_process 完成）
        try { recordTaskFinish(getMemoryDB(), 'video', $taskId, $output, $result['http_code']); } catch (Throwable $e) {}
        echo json_encode(['status' => 'completed']);
    } else {
        $errOutput = [
            'error' => 'proxy_all_keys_exhausted',
            'message' => '所有 API Key 均已耗尽: ' . $last_error,
        ];
        file_put_contents("$VIDEO_TASK_DIR/$taskId.result", json_encode($errOutput));
        // P2: DB 持久化（worker 路径 → video_process 失败）
        try { recordTaskFinish(getMemoryDB(), 'video', $taskId, $errOutput, null); } catch (Throwable $e) {}
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
    // P2: DB 持久化（worker.sh 通过 video_write_result 回写）
    try {
        $httpCode = $resultData['_http_code'] ?? null;
        recordTaskFinish(getMemoryDB(), 'video', $taskId, $resultData, is_int($httpCode) ? $httpCode : null);
    } catch (Throwable $e) {}
    echo json_encode(['status' => 'saved']);
    exit;
}

// 获取视频供应商配置
if ($api_path === '/video_get_provider') {
    header('Content-Type: application/json');
    $allowedIPs = ['156.227.27.58'];
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
// ⑧B 视频模板生成（create_video_agent_task → video_template_*）
// ═══════════════════════════════════════
// 与普通 video_submit 同架构：存 .params → worker 异步处理 → 写 .result
// 复用 $VIDEO_TASK_DIR + video_tasks 表（通过 params.task_type='template' 区分）

// 模板视频提交（前端入口：create_video_agent_task）
if ($api_path === '/video_template_submit') {
    $templateId = $input['template_id'] ?? '';
    if (!$templateId) {
        echo json_encode(['error' => 'missing_template_id']);
        exit;
    }
    $taskId = bin2hex(random_bytes(8));
    if (!is_dir($VIDEO_TASK_DIR)) {
        @mkdir($VIDEO_TASK_DIR, 0755, true);
    }
    // 注入 task_type=template 标记，便于 stats 区分
    $input['task_type'] = 'template';
    $input['template_id'] = $templateId;
    $taskData = [
        'provider' => $provider_name,
        'input' => $input,
    ];
    file_put_contents("$VIDEO_TASK_DIR/$taskId.params", serialize($taskData));

    // P2: DB 持久化（复用 video_tasks 表，task_type=template）
    try {
        recordTaskStart(getMemoryDB(), 'video', $taskId, getAuthenticatedUserId() ?? 'guest', $input);
    } catch (Throwable $e) { /* 静默失败 */ }

    header('Content-Type: application/json');
    echo json_encode([
        'task_id' => $taskId,
        'status' => 'processing',
        'message' => '视频模板生成已提交（template_id=' . $templateId . '）',
    ]);

    // fastcgi_finish_request：PHP 后台继续跑（绕过 30s 超时）
    $vtBgProcess = function() use ($taskId, $VIDEO_TASK_DIR, $taskData, $PROVIDERS) {
        $taskProvConfig = $PROVIDERS[$taskData['provider']] ?? null;
        if (!$taskProvConfig) { @unlink("$VIDEO_TASK_DIR/$taskId.params"); return; }
        $taskBaseUrl = rtrim($taskProvConfig['base_url'], '/');
        $vtUrl = $taskBaseUrl . '/video_template_generation';
        $originalInput = $taskData['input'];
        $last_error = '';
        $result = null;
        foreach ($taskProvConfig['keys'] as $idx => $key) {
            if (empty($key)) continue;
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $vtUrl,
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
            // 模板任务可能返回 MiniMax task_id（用于后续 query），保留它
            file_put_contents("$VIDEO_TASK_DIR/$taskId.result", json_encode($output));
            @unlink("$VIDEO_TASK_DIR/$taskId.params");
            // P2: 持久化完成
            try { recordTaskFinish(getMemoryDB(), 'video', $taskId, $output, $result['http_code']); } catch (Throwable $e) {}
        } else {
            $errOutput = ['error' => 'proxy_all_keys_exhausted', 'message' => '所有 API Key 均已耗尽: ' . $last_error];
            file_put_contents("$VIDEO_TASK_DIR/$taskId.result", json_encode($errOutput));
            try { recordTaskFinish(getMemoryDB(), 'video', $taskId, $errOutput, null); } catch (Throwable $e) {}
        }
    };

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        $vtBgProcess();
    }
    // fallback：worker.sh 接管
    exit;
}

// 模板任务 pending（worker 拉取）
if ($api_path === '/video_template_pending') {
    header('Content-Type: application/json');
    $pending = [];
    if (is_dir($VIDEO_TASK_DIR)) {
        // 只挑 task_type=template 的 .params 文件
        foreach (glob("$VIDEO_TASK_DIR/*.params") as $paramFile) {
            $id = basename($paramFile, '.params');
            if (!preg_match('/^[a-f0-9]{16}$/', $id)) continue;
            if (file_exists("$VIDEO_TASK_DIR/$id.result")) continue;
            $data = @unserialize(file_get_contents($paramFile));
            if (!is_array($data) || ($data['input']['task_type'] ?? '') !== 'template') continue;
            $age = time() - filemtime($paramFile);
            if ($age < 1800) {
                $pending[] = $id;
            }
        }
    }
    echo json_encode(['pending' => $pending, 'count' => count($pending)]);
    exit;
}

// 模板任务参数读取（worker 读取）
if ($api_path === '/video_template_read_params') {
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
    $data = unserialize(file_get_contents($paramFile));
    echo json_encode([
        'task_id' => $taskId,
        'params' => $data['input'] ?? [],
        'provider' => $provider_name,
        'api_path' => '/video_template_generation',
    ]);
    exit;
}

// 模板任务结果写入（worker 回写 + DB UPDATE）
if ($api_path === '/video_template_write_result') {
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
    // P2: DB UPDATE
    try {
        $httpCode = $resultData['_http_code'] ?? null;
        recordTaskFinish(getMemoryDB(), 'video', $taskId, $resultData, is_int($httpCode) ? $httpCode : null);
    } catch (Throwable $e) {}
    echo json_encode(['status' => 'saved']);
    exit;
}

// 模板任务统计（复用 taskStatsQuery，加 task_type=template 过滤）
if ($api_path === '/video_template_stats') {
    header('Content-Type: application/json');
    try {
        $result = taskStatsQuery(getMemoryDB(), 'video', array_merge($input, ['task_type' => 'template']));
        $result['sub_type'] = 'video_template';
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'stats_failed', 'message' => $e->getMessage()]);
    }
    exit;
}

// 模板任务 pending count（worker 健康检查用）
if ($api_path === '/video_template_pending_count') {
    header('Content-Type: application/json');
    $count = 0;
    if (is_dir($VIDEO_TASK_DIR)) {
        foreach (glob("$VIDEO_TASK_DIR/*.params") as $paramFile) {
            $id = basename($paramFile, '.params');
            if (!preg_match('/^[a-f0-9]{16}$/', $id)) continue;
            if (file_exists("$VIDEO_TASK_DIR/$id.result")) continue;
            $data = @unserialize(file_get_contents($paramFile));
            if (!is_array($data) || ($data['input']['task_type'] ?? '') !== 'template') continue;
            $age = time() - filemtime($paramFile);
            if ($age < 1800) $count++;
        }
    }
    echo json_encode(['count' => $count]);
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
            } catch (Exception $e) {}
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
// ═══════════════════════════════════════
// ⑧ 记忆系统后台任务处理（Hermes Cron 驱动）
// ═══════════════════════════════════════

// 列出待处理记忆任务（仅限内部服务器调用 + 有效 token）
if ($api_path === '/memory_pending') {
    header('Content-Type: application/json');
    // 支持两种认证方式：内部IP白名单 或 有效AccessToken
    $remoteIP = $_SERVER['REMOTE_ADDR'] ?? '';
    $allowedIPs = ['156.227.27.58'];
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
    $allowedIPs = ['156.227.27.58'];
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

// ── 流式传输支持（chat/completions） ──
$isStream = !empty($input['stream']);
if ($isStream && strpos($api_path, 'chat/completions') !== false) {
    // 禁用所有输出缓冲
    while (ob_get_level() > 0) ob_end_clean();
    
    // 流式模式：逐块转发，永不超时
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');
    header('Content-Encoding: none');
    @ini_set('zlib.output_compression', 0);
    @ini_set('output_buffering', 0);

    $last_error = '';
    foreach ($api_keys as $idx => $key) {
        if (empty($key)) continue;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $target_url,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $key,
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($input),
        ]);

        // 流式回调：每收到一块数据立即转发给前端
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $chunk) use (&$http_code) {
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            echo $chunk;
            ob_flush();
            flush();
            return strlen($chunk);
        });

        $http_code = 0;
        curl_exec($ch);
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
        if ($http_code >= 200 && $http_code < 300) {
            exit; // 成功，流式传输完成
        }
        $last_error = "Key " . ($idx + 1) . " HTTP " . $http_code;
    }

    // 所有密钥失败
    echo "data: " . json_encode(['error' => 'proxy_all_keys_exhausted', 'message' => $last_error]) . "\n\n";
    echo "data: [DONE]\n\n";
    exit;
}

// ── 文件内容下载（GET 代理，返回二进制）──
if (strpos($api_path, '/files/retrieve_content') !== false) {
    $fileId = $input['file_id'] ?? $_GET['file_id'] ?? '';
    $queryStr = $fileId ? '?file_id=' . urlencode($fileId) : '';
    $parts = parse_url($api_path);
    if (!empty($parts['query'])) $queryStr = '?' . $parts['query'];
    $dl_url = $base_url . '/files/retrieve_content' . $queryStr;
    $last_error = '';
    foreach ($api_keys as $idx => $key) {
        if (empty($key)) continue;
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $dl_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($error) { $last_error = $error; continue; }
        if ($http_code !== 200) { $last_error = "HTTP $http_code"; continue; }
        header('Content-Type: ' . ($contentType ?: 'application/octet-stream'));
        header('Content-Length: ' . strlen($response));
        echo $response;
        exit;
    }
    http_response_code(503);
    echo json_encode(['error' => 'download_failed', 'message' => $last_error]);
    exit;
}

// ── 文件上传（multipart/form-data 透传）──
if ($api_path === '/files/upload' || (empty($api_path) && $method === 'POST' && !empty($_FILES))) {
    $uploadProvider = $provider_name;
    if (!isset($PROVIDERS[$uploadProvider])) {
        http_response_code(400);
        echo json_encode(['error' => 'unknown_provider']);
        exit;
    }
    $uploadProvConfig = $PROVIDERS[$uploadProvider];
    $uploadKeys = $uploadProvConfig['keys'];
    $uploadBaseUrl = rtrim($uploadProvConfig['base_url'], '/');
    $upload_url = $uploadBaseUrl . '/files/upload';
    $last_error = '';
    // 从 $_FILES 和 $_POST 重建 multipart 数据
    $fileField = $_FILES['file'] ?? null;
    $purpose = $_POST['purpose'] ?? '';
    foreach ($uploadKeys as $idx => $key) {
        if (empty($key)) continue;
        $ch = curl_init();
        $postFields = ['purpose' => $purpose];
        if ($fileField && $fileField['tmp_name'] && is_uploaded_file($fileField['tmp_name'])) {
            $postFields['file'] = new CURLFile($fileField['tmp_name'], $fileField['type'] ?? '', $fileField['name'] ?? 'file');
        }
        curl_setopt_array($ch, [
            CURLOPT_URL => $upload_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($error) { $last_error = $error; continue; }
        if ($http_code >= 400) { $last_error = "HTTP $http_code"; continue; }
        echo $response;
        exit;
    }
    http_response_code(503);
    echo json_encode(['error' => 'upload_failed', 'message' => $last_error]);
    exit;
}

// ── 非流式模式：原有逻辑 ──
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
        CURLOPT_TIMEOUT => getEndpointTimeout($api_path),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
        ],
    ]);

    // Debug logging for image_generation and t2a_v2
    if (strpos($api_path, 'image_generation') !== false || strpos($api_path, 't2a_v2') !== false) {
        $debugPayload = json_encode($input, JSON_UNESCAPED_UNICODE);
        $debugFile = '/vhost/tmp/api_debug_' . md5($api_path . microtime(true)) . '.json';
        @file_put_contents($debugFile, json_encode([
            'timestamp' => date('Y-m-d H:i:s'),
            'api_path' => $api_path,
            'target_url' => $target_url,
            'provider' => $provider_name,
            'method' => $method,
            'payload_size' => strlen($debugPayload),
            'payload_preview' => mb_substr($debugPayload, 0, 500),
            'key_index' => $idx,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

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

    // Debug log for failed requests (all endpoints)
    if ($error || $http_code >= 400) {
        $debugFile = '/vhost/tmp/api_debug_' . md5($api_path . microtime(true)) . '.json';
        @file_put_contents($debugFile, json_encode([
            'timestamp' => date('Y-m-d H:i:s'),
            'api_path' => $api_path,
            'target_url' => $target_url,
            'provider' => $provider_name,
            'key_index' => $idx,
            'http_code' => $http_code,
            'curl_error' => $error ?: null,
            'response_preview' => mb_substr($response, 0, 1000),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    if ($error) {
        $last_error = $error;
        continue;
    }

    if ($http_code === 429) {
        $last_error = "Key " . ($idx + 1) . " rate limited";
        continue;
    }

    // 非成功响应：记录详细信息用于调试
    if ($http_code === 400) {
        // 400 Bad Request: 请求格式错误，轮换Key无意义，直接返回
        http_response_code(400);
        echo json_encode([
            'error' => 'bad_request',
            'message' => '请求格式错误: ' . mb_substr($response, 0, 300),
            'detail' => ['provider' => $provider_name, 'target_url' => $target_url, 'response' => mb_substr($response, 0, 500)],
        ]);
        exit;
    }
    if ($http_code >= 400) {
        $last_error = "Key " . ($idx + 1) . " HTTP " . $http_code . ": " . mb_substr($response, 0, 300);
        continue;
    }

    // 成功：检查 MiniMax API 错误码，转换为对应 HTTP 状态码
    $respData = json_decode($response, true);
    if (isset($respData['base_resp']['status_code'])) {
        $sc = (int)$respData['base_resp']['status_code'];
        $sm = $respData['base_resp']['status_msg'] ?? '';
        // 1004: 鉴权失败/权限不足；2049: 无效 api key
        if ($sc === 1004 || $sc === 2049) {
            // 记录错误但继续尝试下一个密钥（不同密钥可能有不同权限）
            $last_error = ($sc === 1004 ? 'Key ' . ($idx+1) . ' 无权限 (' . $api_path . ')' : 'Key ' . ($idx+1) . ' 无效');
            continue;
        }
        if ($sc === 1002) http_response_code(429);
        elseif ($sc === 1008) http_response_code(402);
        elseif ($sc === 1026 || $sc === 1027 || $sc === 2013) http_response_code(400);
        elseif ($sc !== 0) http_response_code(502);
    }
    http_response_code($http_code);
    header('Content-Type: application/json');
    echo $response;
    exit;
}

// ── 所有密钥都失败 ──
http_response_code(503);
echo json_encode([
    'error' => 'proxy_all_keys_exhausted',
    'message' => '所有 API Key 均已耗尽或代理请求失败: ' . $last_error,
    'detail' => [
        'provider' => $provider_name,
        'target_url' => $target_url,
        'keys_count' => count($api_keys),
        'last_error' => $last_error,
    ],
]);
?>

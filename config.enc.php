<?php
/**
 * config.enc.php — 加密 API 密钥配置
 * 
 * 所有密钥使用 AES-256-CBC 加密存储，仅在服务端运行时解密。
 * 密钥永不输出到前端。
 * 
 * ⚠️ 安全说明：
 *   此文件包含加密密钥和密文。如果被完全读取，攻击者可解密。
 *   真正的安全性来自：① PHP 文件不会被直接输出 ② 密钥从不出现在前端的 HTML/JS 中。
 *   如需更高安全性，可将 PASSPHRASE 移至 Web 根目录外的文件。
 */

// ── 加密密钥（AES-256-CBC 解密密钥，经 SHA-256 派生为 32 字节） ──
define('ENC_PASSPHRASE', '__YOUR_ENCRYPTION_PASSPHRASE__');

// PHP 7.2+ required for hash_equals timing-safe comparison
if (PHP_VERSION_ID < 70200) {
    throw new RuntimeException('PHP 7.2+ required');
}

// Fallback for hash_equals if not available (PHP < 5.6)
if (!function_exists('hash_equals')) {
    function hash_equals($a, $b) {
        return substr_count($a ^ $b, "\0") * 2 === 0;
    }
}

/**
 * 解密 AES-256-CBC 加密的密钥
 * 格式：base64(16字节IV + 密文)
 */
function decrypt_key($encrypted) {
    $key = hash('sha256', ENC_PASSPHRASE, true); // 32字节
    $data = base64_decode($encrypted, true);
    if ($data === false || strlen($data) <= 16) return '';
    $iv = substr($data, 0, 16);
    $ct = substr($data, 16);
    $decrypted = openssl_decrypt($ct, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return $decrypted !== false ? $decrypted : '';
}

// ── 供应商配置 ──
// 每个供应商：base_url + keys 数组（key 在首次访问时解密并缓存）
return [
    // MiniMax — 三密钥轮换容灾
    'minimax' => [
        'base_url' => 'https://api.minimaxi.com/v1',
        'keys' => [
            decrypt_key('5+Of+GNDRqGrALH76zFuCljL3oA6MmH0RAB28iP5gz9Ysg2Jur5MyDKm1Ujr2p4oCHNdBMXVc3kgl1IjZhq4nhaBzF7EDgSnhBytWPHA3lVXQc+SxME3MHCMMcMtGZjP2V4bxZ0LNT0Aiygi9BAYv4DhWKOg4P1a9eQY15/4vcOnVfX8Xy2cpHJw9yFo+SoS'),
            decrypt_key('UnUFCc/1skOApWno9gfeMP7QIefomydvRMLaKg/BZ45K/0bBj2hqba5aSjDor5hEW3JFlrkLtBnFZ277yOEb3GuA/1oNkk7Ylm9KOnxRHzwzrPNxHrnZ04UZsrqVVzyWnq1QHraqeMym0z3t9zVv689peEOeGG0eDEo3GGqg1lOZG4jcwBYclgI6uP9QmXzf'),
            decrypt_key('xP326FZv30L8rkubNd70M56tbIjQ8pHS4fWLbUqEcv6uXulnHoA1k5OTRzk3cLOMFSJ2Xqjcy/8tfBi9T8HYioINtGHBktKSCK+f7VWBcI8n5dxrddnE08GxhITH8PfnntS4luwy3qRC1QE6yuIbad/rn7RiC1EaxcwgWdJFWOMUWZs6o/6aubVMieik5+CF'),
        ],
    ],

    // OpenCode Go — 单密钥
    'opencode-go' => [
        'base_url' => 'https://opencode.ai/zen/go/v1',
        'keys' => [
            decrypt_key('4MXsratJUz8gNlH5eXHNKxUpzFLRe53WAz2PFPEKKorDUF2B1pUXPyPDfbLTCVVFqJnNG8yqmxhsgVPndjvXE2FV7LHFY26lzjp/SpdkyC+d3CrG/nHaR0CdFZVHK9Jz'),
        ],
        // 显示名和模型名（供前端参考，不包含密钥信息）
        'display_name' => 'deepseek-v4-flash',
        'model_name' => 'deepseek-v4-flash',
    ],
];

<?php
/**
 * mlogin 插件 - 核心逻辑文件
 *
 * 功能：未登录中转页、URI/IP 黑白名单、时间段控制、访问日志、自定义 403
 *       IPv6 支持、反向代理 IP、规则缓存、拦截频率限制、分类/标签控制
 *
 * @package mlogin
 */

// 时区：可配置，默认 PRC（由 mlogin_CheckLogin 中动态设置）
if (!defined('MLOGIN_DEFAULT_TZ')) define('MLOGIN_DEFAULT_TZ', 'PRC');
date_default_timezone_set(MLOGIN_DEFAULT_TZ);

// 插件注册
if (function_exists("RegisterPlugin")) {
    RegisterPlugin("mlogin", "ActivePlugin_mlogin");
}

/**
 * 插件激活回调：挂载钩子
 */
function ActivePlugin_mlogin()
{
    Add_Filter_Plugin('Filter_Plugin_Begin', 'mlogin_CheckLogin');
    Add_Filter_Plugin('Filter_Plugin_ViewAuto_Begin', 'mlogin_CheckLogin');
    // [改进#6] 登录成功后清除拦截计数
    Add_Filter_Plugin('Filter_Plugin_Login_Succeed', 'mlogin_login_succeed');
}

// ─── 常量定义 ───────────────────────────────────────────────

// [改进#3] 系统路径放行范围收窄：去掉 zb_users/plugin/，改为更精确路径
if (!defined('MLOGIN_SYSTEM_PASS')) define('MLOGIN_SYSTEM_PASS', [
    'zb_system/login.php',
    'zb_system/cmd.php',
    'zb_system/admin/',
    'zb_system/function/c_system_base.php',
    'zb_system/function/c_system_admin.php',
    'zb_users/c_option.php',
    'zb_users/plugin/mlogin/',
]);
if (!defined('MLOGIN_ALLOWED_IMG_EXT'))  define('MLOGIN_ALLOWED_IMG_EXT', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
if (!defined('MLOGIN_ALLOWED_IMG_MIME')) define('MLOGIN_ALLOWED_IMG_MIME', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
if (!defined('MLOGIN_UPLOAD_DIR_NAME'))  define('MLOGIN_UPLOAD_DIR_NAME', 'images');
if (!defined('MLOGIN_MAX_IMG_SIZE'))     define('MLOGIN_MAX_IMG_SIZE', 5 * 1024 * 1024);
if (!defined('MLOGIN_MAX_JSON_SIZE'))    define('MLOGIN_MAX_JSON_SIZE', 1 * 1024 * 1024);
if (!defined('MLOGIN_LOG_DIR'))          define('MLOGIN_LOG_DIR', __DIR__ . '/logs');
if (!defined('MLOGIN_LOG_FILE'))         define('MLOGIN_LOG_FILE', MLOGIN_LOG_DIR . '/access.log');
if (!defined('MLOGIN_MAX_LOG_SIZE'))     define('MLOGIN_MAX_LOG_SIZE', 10 * 1024 * 1024);
if (!defined('MLOGIN_LOG_RETENTION_DAYS')) define('MLOGIN_LOG_RETENTION_DAYS', 30);
// [改进#6] 拦截频率记录文件
if (!defined('MLOGIN_FAIL_LOG_FILE'))    define('MLOGIN_FAIL_LOG_FILE', MLOGIN_LOG_DIR . '/login_failures.json');
// [改进#1] 日志保护 htaccess 路径
if (!defined('MLOGIN_HTACCESS_FILE'))    define('MLOGIN_HTACCESS_FILE', MLOGIN_LOG_DIR . '/.htaccess');

/** 默认 403 页面 */
if (!defined('MLOGIN_DEFAULT_403_HTML')) define('MLOGIN_DEFAULT_403_HTML', '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 拒绝访问</title>
    <style>
        body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f8f9fa;display:flex;justify-content:center;align-items:center;height:100vh;margin:0;color:#333}
        .container{text-align:center;background:#fff;padding:50px;border-radius:10px;box-shadow:0 4px 15px rgba(0,0,0,.05);max-width:500px;width:90%}
        h1{font-size:72px;margin:0;color:#dc3545;font-weight:800;line-height:1}
        h2{margin:10px 0 20px;font-size:24px;color:#555}
        p{font-size:16px;color:#777;margin-bottom:30px;line-height:1.6}
        .btn{display:inline-block;padding:12px 30px;background:#007bff;color:#fff;text-decoration:none;border-radius:5px;transition:background .3s}
        .btn:hover{background:#0056b3}
        .meta{margin-top:20px;font-size:12px;color:#aaa}
    </style>
</head>
<body>
    <div class="container">
        <h1>403</h1>
        <h2>拒绝访问</h2>
        <p>抱歉，您没有权限访问此页面。<br>原因：{{reason}}</p>
        <a href="{{site_url}}" class="btn">返回首页</a>
        <div class="meta">{{site_name}} · {{current_time}}</div>
    </div>
</body>
</html>');

// ─── 辅助函数 ───────────────────────────────────────────────

/**
 * 从多行文本中提取非空行
 */
function mlogin_parse_lines($text)
{
    $text = trim((string)$text);
    if ($text === '') return [];
    return array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", '', $text))), 'strlen'));
}

/**
 * 清洗多行文本（去空行 + trim + 重拼）
 */
function mlogin_clean_lines($text)
{
    return implode("\n", mlogin_parse_lines($text));
}

/**
 * 检查用户级别是否在启用的级别列表中
 */
function mlogin_level_enabled($userLevel, $levelsConfig)
{
    $levelsConfig = trim((string)$levelsConfig);
    if ($levelsConfig === '') return true;
    $enabled = array_map('intval', explode(',', $levelsConfig));
    return in_array((int)$userLevel, $enabled, true);
}

/**
 * 检测 URI 是否匹配规则列表
 */
function mlogin_uri_matches($uri, array $list)
{
    foreach ($list as $item) {
        if ($item !== '' && mlogin_match_single_rule($uri, $item)) return true;
    }
    return false;
}

/**
 * 单条 URI 规则匹配
 */
function mlogin_match_single_rule($uri, $rule)
{
    if (strpos($rule, '?') === 0) {
        $pos = stripos($uri, $rule);
        if ($pos === false) return false;
        $afterPos = $pos + strlen($rule);
        if ($afterPos >= strlen($uri)) return true;
        return ($uri[$afterPos] === '&' || $uri[$afterPos] === '#');
    }
    $pos = stripos($uri, $rule);
    if ($pos === false || $pos !== 0) return false;
    $afterPos = strlen($rule);
    if ($afterPos >= strlen($uri)) return true;
    return ($uri[$afterPos] === '/' || $uri[$afterPos] === '?');
}

/**
 * 限制自动跳转秒数 [0, 120]
 */
function mlogin_clamp_jump_seconds($val)
{
    $val = (int)$val;
    return $val < 0 ? 0 : ($val > 120 ? 120 : $val);
}

// ─── [改进#2] IP 获取：支持反向代理 ──────────────────────────

/**
 * 获取客户端真实 IP（支持反向代理）
 * @param bool $trustProxy 是否信任代理头
 * @return string
 */
function mlogin_get_client_ip($trustProxy = false)
{
    if (!$trustProxy) {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    $headers = [
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'HTTP_CF_CONNECTING_IP',  // Cloudflare
        'HTTP_CLIENT_IP',
    ];

    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            // X-Forwarded-For 可能包含多个 IP，取第一个（最原始客户端 IP）
            $ips = array_map('trim', explode(',', $_SERVER[$header]));
            $ip = $ips[0];
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }

    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// ─── [改进#4] IP 匹配：支持 IPv4 + IPv6 ─────────────────────

/**
 * 检测 IP 是否匹配规则列表（精确 / CIDR / 通配符，IPv4 + IPv6）
 */
function mlogin_ip_matches($ip, array $list)
{
    $isIPv6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

    foreach ($list as $rule) {
        $rule = trim($rule);
        if ($rule === '') continue;

        // 精确匹配
        if ($ip === $rule) return true;

        // IPv6 精确匹配（标准化后比较）
        if ($isIPv6 && mlogin_normalize_ipv6($ip) === mlogin_normalize_ipv6($rule)) {
            return true;
        }

        // CIDR
        if (strpos($rule, '/') !== false) {
            $parts = explode('/', $rule, 2);
            $subnet = trim($parts[0]);
            $bits = (int)trim($parts[1]);

            // 判断是 IPv4 还是 IPv6 CIDR
            if (filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                if (!$isIPv6 && $bits >= 0 && $bits <= 32 && mlogin_ip_in_cidr_v4($ip, $subnet, $bits)) {
                    return true;
                }
            } elseif (filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                if ($isIPv6 && $bits >= 0 && $bits <= 128 && mlogin_ip_in_cidr_v6($ip, $subnet, $bits)) {
                    return true;
                }
            }
        }

        // 通配符（仅 IPv4）
        if (!$isIPv6 && strpos($rule, '*') !== false) {
            $pattern = str_replace(['.', '*'], ['\\.', '\\d+'], $rule);
            if (preg_match('/^' . $pattern . '$/', $ip)) return true;
        }
    }
    return false;
}

/**
 * IPv4 CIDR 范围检查
 */
function mlogin_ip_in_cidr_v4($ip, $subnet, $bits)
{
    $ipLong = ip2long($ip);
    $subnetLong = ip2long($subnet);
    if ($ipLong === false || $subnetLong === false) return false;
    $mask = $bits === 0 ? 0 : (-1 << (32 - $bits));
    return ($ipLong & $mask) === ($subnetLong & $mask);
}

/**
 * IPv6 CIDR 范围检查
 */
function mlogin_ip_in_cidr_v6($ip, $subnet, $bits)
{
    $ipBin = mlogin_ipv6_to_binary($ip);
    $subnetBin = mlogin_ipv6_to_binary($subnet);
    if ($ipBin === false || $subnetBin === false) return false;

    // 比较前 $bits 位
    $fullBytes = intdiv($bits, 8);
    $remainBits = $bits % 8;

    if (strncmp($ipBin, $subnetBin, $fullBytes) !== 0) return false;
    if ($remainBits > 0 && $fullBytes < strlen($ipBin)) {
        $mask = 0xFF << (8 - $remainBits) & 0xFF;
        if ((ord($ipBin[$fullBytes]) & $mask) !== (ord($subnetBin[$fullBytes]) & $mask)) {
            return false;
        }
    }
    return true;
}

/**
 * IPv6 地址转二进制字符串
 */
function mlogin_ipv6_to_binary($ip)
{
    $packed = @inet_pton($ip);
    if ($packed === false) return false;
    return $packed;
}

/**
 * IPv6 地址标准化（用于精确比较）
 */
function mlogin_normalize_ipv6($ip)
{
    $packed = @inet_pton($ip);
    if ($packed === false) return $ip;
    $unpacked = @inet_ntop($packed);
    return $unpacked !== false ? $unpacked : $ip;
}

// ─── 时间段控制 ──────────────────────────────────────────────

/**
 * 规范化单行时间段
 */
function mlogin_normalize_time_range($range)
{
    $range = trim((string)$range);
    if ($range === '') return '';

    $range = str_replace(
        ['：', '－', '—', '–', '～', '~', '〜'],
        [':',  '-',  '-', '-', '-', '-', '-'],
        $range
    );
    if (substr_count($range, '-') !== 1) return '';

    list($start, $end) = explode('-', $range, 2);
    $start = trim($start);
    $end   = trim($end);

    if (!preg_match('/^([01]?\d|2[0-3]):[0-5][0-9]$/', $start)) return '';
    if (!preg_match('/^(?:[01]?\d|2[0-3]):[0-5][0-9]$|^24:00$/', $end)) return '';

    $fmt = function ($t) {
        $p = explode(':', $t);
        return str_pad($p[0], 2, '0', STR_PAD_LEFT) . ':' . str_pad($p[1], 2, '0', STR_PAD_LEFT);
    };
    return $fmt($start) . '-' . $fmt($end);
}

/**
 * [改进#15] 获取当前时间 HH:MM（支持可配置时区偏移）
 * @param int $tzOffset 时区偏移小时数，默认 8（北京时间）
 */
function mlogin_now_hhmm($tzOffset = 8)
{
    return gmdate('H:i', time() + $tzOffset * 3600);
}

/**
 * 检查当前时间是否在允许的时间段内
 */
function mlogin_in_time_range($timeRanges, $tzOffset = 8)
{
    $ranges = mlogin_parse_lines($timeRanges);
    if (empty($ranges)) return true;

    $now = mlogin_now_hhmm($tzOffset);

    foreach ($ranges as $range) {
        $range = mlogin_normalize_time_range($range);
        if ($range === '') continue;

        list($start, $end) = explode('-', $range, 2);

        if ($start > $end) {
            if ($now >= $start || $now <= $end) return true;
        } else {
            if ($now >= $start && $now <= $end) return true;
        }
    }
    return false;
}

// ─── [改进#9] 规则解析缓存 ──────────────────────────────────

/**
 * 带缓存的规则解析（使用 Z-Blog 缓存或内存缓存）
 */
function mlogin_parse_lines_cached($text, $cacheKey)
{
    static $memCache = [];

    // 同一请求内的内存缓存
    if (isset($memCache[$cacheKey])) {
        return $memCache[$cacheKey];
    }

    $result = mlogin_parse_lines($text);
    $memCache[$cacheKey] = $result;
    return $result;
}

// ─── [改进#1][10] 访问日志 ──────────────────────────────────

/**
 * [改进#1] 确保日志目录受保护（自动生成 .htaccess）
 */
function mlogin_ensure_log_protection()
{
    if (!is_dir(MLOGIN_LOG_DIR)) {
        @mkdir(MLOGIN_LOG_DIR, 0755, true);
    }

    // 生成 Apache .htaccess 保护日志目录
    if (!file_exists(MLOGIN_HTACCESS_FILE)) {
        $htaccess = "# mlogin log protection\n";
        $htaccess .= "# Generated by mlogin plugin\n";
        $htaccess .= "Require all denied\n";
        $htaccess .= "\n# For Apache 2.2 compatibility\n";
        $htaccess .= "<IfModule !mod_authz_core.c>\n";
        $htaccess .= "    Order deny,allow\n";
        $htaccess .= "    Deny from all\n";
        $htaccess .= "</IfModule>\n";
        @file_put_contents(MLOGIN_HTACCESS_FILE, $htaccess);
    }

    // 生成 Nginx 提示文件（仅供管理员参考）
    $nginxHint = MLOGIN_LOG_DIR . '/nginx_protection_readme.txt';
    if (!file_exists($nginxHint)) {
        $hint = "Nginx 用户请手动添加以下配置以保护日志目录：\n\n";
        $hint .= "location ~ /zb_users/plugin/mlogin/logs/ {\n";
        $hint .= "    deny all;\n";
        $hint .= "    return 403;\n";
        $hint .= "}\n";
        @file_put_contents($nginxHint, $hint);
    }
}

// [改进#10] 日志写入缓冲区
$_mlogin_log_buffer = [];

/**
 * 记录访问日志（自动轮转 + 过期清理 + 缓冲写入）
 * @param string $uri
 * @param string $ip
 * @param string $action ALLOWED/BLOCKED/REDIRECT
 * @param string $reason
 * @param bool $logOnlyBlocked 是否仅记录拦截日志
 */
function mlogin_log_access($uri, $ip, $action, $reason = '', $logOnlyBlocked = false)
{
    global $_mlogin_log_buffer;

    // 如果设置为仅记录拦截日志，放行日志跳过
    if ($logOnlyBlocked && $action === 'ALLOWED') {
        return;
    }

    mlogin_ensure_log_protection();

    $entry = sprintf(
        "[%s] %s | IP: %s | URI: %s | Action: %s | Reason: %s | UA: %s\n",
        date('Y-m-d H:i:s'),
        $_SERVER['REQUEST_METHOD'] ?? 'GET',
        $ip,
        $uri,
        $action,
        $reason,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '-', 0, 200)
    );

    // 缓冲写入
    $_mlogin_log_buffer[] = $entry;
}

/**
 * 刷新日志缓冲区到文件（在脚本结束前调用）
 */
function mlogin_flush_log_buffer()
{
    global $_mlogin_log_buffer;

    if (empty($_mlogin_log_buffer)) return;

    // 轮转
    if (file_exists(MLOGIN_LOG_FILE) && filesize(MLOGIN_LOG_FILE) > MLOGIN_MAX_LOG_SIZE) {
        @rename(MLOGIN_LOG_FILE, MLOGIN_LOG_FILE . '.' . date('Y-m-d-H-i-s') . '.bak');
    }

    // 批量写入
    @file_put_contents(MLOGIN_LOG_FILE, implode('', $_mlogin_log_buffer), FILE_APPEND | LOCK_EX);
    $_mlogin_log_buffer = [];

    // 每 100 次写入清理一次过期日志
    if (mt_rand(1, 100) === 1) {
        mlogin_cleanup_old_logs();
    }
}

// 注册 shutdown 函数确保缓冲区刷新
register_shutdown_function('mlogin_flush_log_buffer');

/**
 * 清理超过保留天数的旧日志
 */
function mlogin_cleanup_old_logs()
{
    if (!is_dir(MLOGIN_LOG_DIR)) return;
    $cutoff = time() - MLOGIN_LOG_RETENTION_DAYS * 86400;
    foreach (glob(MLOGIN_LOG_DIR . '/*.bak') as $file) {
        if (is_file($file) && filemtime($file) < $cutoff) {
            @unlink($file);
        }
    }
}

// ─── [改进#6] 登录失败限制 ──────────────────────────────────

/**
 * 读取登录失败记录
 */
function mlogin_read_failures()
{
    if (!file_exists(MLOGIN_FAIL_LOG_FILE)) return [];
    $data = @file_get_contents(MLOGIN_FAIL_LOG_FILE);
    if ($data === false) return [];
    $decoded = @json_decode($data, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * 写入登录失败记录
 */
function mlogin_write_failures($data)
{
    if (!is_dir(MLOGIN_LOG_DIR)) {
        @mkdir(MLOGIN_LOG_DIR, 0755, true);
    }
    @file_put_contents(MLOGIN_FAIL_LOG_FILE, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/**
 * 检查 IP 是否被锁定
 * @param string $ip
 * @param int $maxAttempts 最大失败次数
 * @param int $lockMinutes 锁定时间（分钟）
 * @return bool
 */
function mlogin_is_ip_locked($ip, $maxAttempts, $lockMinutes)
{
    if ($maxAttempts <= 0) return false; // 功能关闭

    $failures = mlogin_read_failures();
    if (!isset($failures[$ip])) return false;

    $record = $failures[$ip];
    $count = $record['count'] ?? 0;
    $firstFail = $record['first_fail'] ?? 0;

    // 超过锁定窗口，自动重置
    if (time() - $firstFail > $lockMinutes * 60) {
        unset($failures[$ip]);
        mlogin_write_failures($failures);
        return false;
    }

    return $count >= $maxAttempts;
}

/**
 * 获取 IP 剩余锁定秒数
 */
function mlogin_get_lock_remaining($ip, $lockMinutes)
{
    $failures = mlogin_read_failures();
    if (!isset($failures[$ip])) return 0;

    $firstFail = $failures[$ip]['first_fail'] ?? 0;
    $remaining = ($firstFail + $lockMinutes * 60) - time();
    return max(0, $remaining);
}

/**
 * 记录一次拦截（用于拦截频率限制）
 * @param string $ip 客户端 IP
 * @param int $maxAttempts 最大拦截次数（0=关闭）
 * @param int $lockMinutes 锁定时间窗口（分钟）
 * @return int 当前计数
 */
function mlogin_record_intercept($ip, $maxAttempts, $lockMinutes)
{
    if ($maxAttempts <= 0) return 0; // 功能关闭

    $failures = mlogin_read_failures();
    if (!isset($failures[$ip])) {
        $failures[$ip] = ['count' => 0, 'first_fail' => time()];
    }

    // 超过锁定窗口则重置
    if (time() - $failures[$ip]['first_fail'] > $lockMinutes * 60) {
        $failures[$ip] = ['count' => 0, 'first_fail' => time()];
    }

    $failures[$ip]['count']++;
    mlogin_write_failures($failures);
    return $failures[$ip]['count'];
}

/**
 * 登录成功钩子：清除拦截计数
 */
function mlogin_login_succeed()
{
    global $zbp;
    $cfg = $zbp->Config('mlogin');
    $trustProxy = (int)($cfg->trust_proxy ?? 0) === 1;
    $ip = mlogin_get_client_ip($trustProxy);
    $failures = mlogin_read_failures();
    if (isset($failures[$ip])) {
        unset($failures[$ip]);
        mlogin_write_failures($failures);
    }
}

// ─── [改进#8] 分类/标签登录控制 ─────────────────────────────

/**
 * 检查当前文章是否属于需要登录的分类/标签
 * @return bool
 */
function mlogin_check_category_access()
{
    global $zbp;

    $catRules = mlogin_parse_lines($zbp->Config('mlogin')->require_login_categories ?? '');
    $tagRules = mlogin_parse_lines($zbp->Config('mlogin')->require_login_tags ?? '');

    if (empty($catRules) && empty($tagRules)) return false;

    // 尝试从 Z-Blog 全局变量获取当前文章信息
    if (isset($zbp->posts) && !empty($zbp->posts)) {
        foreach ($zbp->posts as $post) {
            // 检查分类
            if (!empty($catRules) && isset($post->Category)) {
                $catName = $post->Category->Name ?? '';
                $catId = $post->Category->ID ?? 0;
                foreach ($catRules as $rule) {
                    if ($rule == $catId || stripos($catName, $rule) !== false) {
                        return true;
                    }
                }
            }
            // 检查标签
            if (!empty($tagRules) && isset($post->Tags)) {
                foreach ($post->Tags as $tag) {
                    $tagName = $tag->Name ?? '';
                    foreach ($tagRules as $rule) {
                        if (stripos($tagName, $rule) !== false) {
                            return true;
                        }
                    }
                }
            }
        }
    }

    return false;
}

// ─── 403 页面 ────────────────────────────────────────────────

/**
 * 输出 403 页面并终止脚本
 */
function mlogin_show_403($reason = '')
{
    global $zbp;
    $cfg = $zbp->Config('mlogin');

    header('HTTP/1.1 403 Forbidden');
    header('X-Robots-Tag: noindex');

    $custom403 = trim($cfg->custom_403_html ?? '');
    $template  = $custom403 !== '' ? $custom403 : MLOGIN_DEFAULT_403_HTML;

    echo str_replace(
        ['{{reason}}', '{{site_name}}', '{{site_url}}', '{{current_time}}'],
        [
            htmlspecialchars($reason, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($zbp->name ?? '', ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($zbp->host ?? '', ENT_QUOTES, 'UTF-8'),
            date('Y-m-d H:i:s')
        ],
        $template
    );
    exit();
}

// ─── 中转提示页 ──────────────────────────────────────────────

/**
 * 输出美化中转页并终止脚本
 */
function mlogin_show_custom_login_page($redirectUrl)
{
    global $zbp;
    $cfg = $zbp->Config('mlogin');

    $bg       = htmlspecialchars($cfg->login_bg    ?? '', ENT_QUOTES, 'UTF-8');
    $title    = htmlspecialchars($cfg->login_title  ?? '', ENT_QUOTES, 'UTF-8');
    $tip      = htmlspecialchars($cfg->login_tip    ?? '', ENT_QUOTES, 'UTF-8');
    $autoJump = max(0, (int)($cfg->auto_jump_seconds ?? 0));
    $sysLoginUrl = $zbp->host . 'zb_system/login.php?redirect=' . urlencode($redirectUrl);
?>
<!DOCTYPE html>
<html xml:lang="zh-Hans" lang="zh-Hans">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $title ?: '访问受限'; ?></title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{
            <?php if ($bg) echo "background:url('{$bg}') center/cover no-repeat fixed;"; ?>
            background-color:#f4f6f8;
            font-family:system-ui,-apple-system,"Segoe UI",Roboto,"Microsoft Yahei",sans-serif;
            min-height:100vh;display:flex;align-items:center;justify-content:center;padding:16px;
        }
        .login-box{width:100%;max-width:420px;background:rgba(255,255,255,.96);border-radius:16px;
            box-shadow:0 20px 50px rgba(0,0,0,.12);padding:44px 36px;backdrop-filter:blur(10px);
            -webkit-backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,.6)}
        .login-title{text-align:center;font-size:22px;font-weight:600;color:#1f2937;margin-bottom:12px}
        .login-tip{text-align:center;font-size:15px;color:#6b7280;margin-bottom:32px;line-height:1.7}
        .btn-goto{display:block;width:100%;text-align:center;height:52px;line-height:52px;border:none;
            border-radius:10px;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;font-size:16px;
            font-weight:600;text-decoration:none;transition:transform .15s,box-shadow .2s;
            box-shadow:0 8px 20px rgba(37,99,235,.25);cursor:pointer}
        .btn-goto:hover{transform:translateY(-1px);box-shadow:0 12px 26px rgba(37,99,235,.35)}
        .btn-goto:active{transform:translateY(0)}
        .jump-note{margin-top:14px;text-align:center;font-size:13px;color:#2563eb}
        .jump-note .num{font-weight:700;font-size:15px}
    </style>
</head>
<body>
    <div class="login-box">
        <div class="login-title"><?php echo $title ?: '该内容登录后可访问'; ?></div>
        <?php if ($tip): ?>
            <div class="login-tip"><?php echo $tip; ?></div>
        <?php endif; ?>
        <a class="btn-goto" id="btn_goto_login"
           href="<?php echo htmlspecialchars($sysLoginUrl, ENT_QUOTES, 'UTF-8'); ?>">点击登录</a>
        <?php if ($autoJump > 0): ?>
            <div class="jump-note" id="auto_jump_note">
                等待 <span class="num" id="auto_jump_sec"><?php echo $autoJump; ?></span> 秒后自动跳转登录页…
            </div>
        <?php endif; ?>
    </div>
    <?php if ($autoJump > 0): ?>
    <script>
    (function(){
        var sec=<?php echo $autoJump; ?>,el=document.getElementById('auto_jump_sec'),
            lnk=document.getElementById('btn_goto_login'),t=null;
        if(lnk) lnk.addEventListener('click',function(){if(t){clearInterval(t);t=null}});
        t=setInterval(function(){sec--;if(el)el.textContent=sec;
            if(sec<=0){clearInterval(t);t=null;window.location.replace(<?php echo json_encode($sysLoginUrl); ?>)}
        },1000);
    })();
    </script>
    <?php endif; ?>
</body>
</html>
<?php
    exit();
}

// ─── 登录检查主逻辑 ──────────────────────────────────────────

/**
 * 钩子函数：登录检查（增强版）
 */
function mlogin_CheckLogin($templateFile = null)
{
    global $zbp;

    static $executed = false;
    if ($executed) return $templateFile;
    $executed = true;

    if (!isset($zbp->user)) return $templateFile;

    $cfg = $zbp->Config('mlogin');

    // [改进#15] 时区配置
    $tzOffset = (int)($cfg->timezone_offset ?? 8);

    // ⓪ 总开关
    if ((int)($cfg->plugin_enabled ?? 1) === 0) return $templateFile;

    // [改进#2] 获取客户端 IP（支持反向代理）
    $trustProxy = (int)($cfg->trust_proxy ?? 0) === 1;
    $clientIP = mlogin_get_client_ip($trustProxy);

    $uri = $_SERVER['REQUEST_URI'];

    // [改进#6] 拦截频率限制参数
    $interceptMax = (int)($cfg->login_fail_max ?? 0);
    $interceptLockMinutes = max(1, (int)($cfg->login_fail_lock_minutes ?? 15));
    
    // 检查 IP 是否因拦截过多被锁定
    if ($interceptMax > 0 && mlogin_is_ip_locked($clientIP, $interceptMax, $interceptLockMinutes)) {
        $remaining = mlogin_get_lock_remaining($clientIP, $interceptLockMinutes);
        mlogin_show_403('访问频率过高，请在 ' . ceil($remaining / 60) . ' 分钟后再试。');
    }

    // ① 系统路径始终放行
    $systemPass = MLOGIN_SYSTEM_PASS;
    // [改进#3] 合并用户自定义放行路径
    $extraPass = mlogin_parse_lines_cached($cfg->extra_system_pass ?? '', 'extra_system_pass');
    if (!empty($extraPass)) {
        $systemPass = array_merge($systemPass, $extraPass);
    }
    if (mlogin_uri_matches($uri, $systemPass)) return $templateFile;

    // ② 时间段控制
    $timeRanges    = $cfg->allowed_time_ranges ?? '';
    $timeGuestMode = (int)($cfg->time_guest_mode ?? 0);
    if (!in_array($timeGuestMode, [0, 1, 2], true)) $timeGuestMode = 0;

    if ($timeGuestMode !== 0 && trim($timeRanges) !== '') {
        if (mlogin_in_time_range($timeRanges, $tzOffset)) {
            $logOnlyBlocked = (int)($cfg->log_only_blocked ?? 0) === 1;
            mlogin_log_access($uri, $clientIP, 'ALLOWED', 'In time range (guest bypass)', $logOnlyBlocked);
            return $templateFile;
        }
        if ($timeGuestMode === 2) {
            mlogin_log_access($uri, $clientIP, 'BLOCKED', 'Outside allowed time range');
            mlogin_show_403('Access is only allowed during specified hours.');
        }
    }

    // ③ IP 黑名单
    $ipBlacklist = mlogin_parse_lines_cached($cfg->ip_blacklist ?? '', 'ip_blacklist');
    if (!empty($ipBlacklist) && mlogin_ip_matches($clientIP, $ipBlacklist)) {
        mlogin_log_access($uri, $clientIP, 'BLOCKED', 'IP Blacklist match');
        mlogin_show_403('Your IP has been blocked.');
    }

    // ④ IP 白名单
    $ipWhitelist = mlogin_parse_lines_cached($cfg->ip_whitelist ?? '', 'ip_whitelist');
    if (!empty($ipWhitelist) && mlogin_ip_matches($clientIP, $ipWhitelist)) {
        $logOnlyBlocked = (int)($cfg->log_only_blocked ?? 0) === 1;
        mlogin_log_access($uri, $clientIP, 'ALLOWED', 'IP Whitelist match', $logOnlyBlocked);
        return $templateFile;
    }

    // ⑤ URI 黑名单
    if ((int)($cfg->blacklist_enabled ?? 1) === 1) {
        $blacklist = mlogin_parse_lines_cached($cfg->blacklist ?? '', 'blacklist');
        if (!empty($blacklist) && mlogin_uri_matches($uri, $blacklist)) {
            $userLevel = (int)$zbp->user->ID > 0 ? (int)$zbp->user->Level : 0;
            $blacklistLevels = $cfg->blacklist_levels ?? '';
            if ($userLevel === 0 || mlogin_level_enabled($userLevel, $blacklistLevels)) {
                mlogin_log_access($uri, $clientIP, 'BLOCKED', 'URI Blacklist match (Level ' . $userLevel . ')');
                mlogin_show_403('URI Blacklist match');
            }
        }
    }

    // ⑥ URI 白名单
    if ((int)($cfg->whitelist_enabled ?? 1) === 1) {
        $whitelist = mlogin_parse_lines_cached($cfg->whitelist ?? '', 'whitelist');
        if (!empty($whitelist) && mlogin_uri_matches($uri, $whitelist)) {
            $userLevel = (int)$zbp->user->ID > 0 ? (int)$zbp->user->Level : 0;
            $whitelistLevels = $cfg->whitelist_levels ?? '';
            if ($userLevel === 0 || mlogin_level_enabled($userLevel, $whitelistLevels)) {
                $logOnlyBlocked = (int)($cfg->log_only_blocked ?? 0) === 1;
                mlogin_log_access($uri, $clientIP, 'ALLOWED', 'URI Whitelist match (Level ' . $userLevel . ')', $logOnlyBlocked);
                return $templateFile;
            }
        }
    }

    // [改进#8] 分类/标签控制
    if ((int)($cfg->category_access_enabled ?? 0) === 1) {
        if (mlogin_check_category_access()) {
            if ((int)$zbp->user->ID === 0) {
                mlogin_log_access($uri, $clientIP, 'REDIRECT', 'Category/Tag requires login');
                mlogin_show_custom_login_page($zbp->host . ltrim($uri, '/'));
            }
        }
    }

    // ⑦ 未登录用户 → 中转页
    if ((int)$zbp->user->ID === 0) {
        // [改进#6] 记录拦截并检查频率限制
        if ($interceptMax > 0) {
            $count = mlogin_record_intercept($clientIP, $interceptMax, $interceptLockMinutes);
            if ($count > $interceptMax) {
                $remaining = mlogin_get_lock_remaining($clientIP, $interceptLockMinutes);
                mlogin_log_access($uri, $clientIP, 'BLOCKED', 'Intercept rate limit exceeded (' . $count . '/' . $interceptMax . ')');
                mlogin_show_403('访问频率过高，请在 ' . ceil($remaining / 60) . ' 分钟后再试。');
            }
        }
        mlogin_log_access($uri, $clientIP, 'REDIRECT', 'Not logged in');
        mlogin_show_custom_login_page($zbp->host . ltrim($uri, '/'));
    }

    return $templateFile;
}

// ─── 插件安装 / 卸载 ─────────────────────────────────────────

/**
 * 插件安装/启用：初始化配置
 */
function InstallPlugin_mlogin()
{
    global $zbp;

    $defaults = [
        'plugin_enabled'      => 1,
        'whitelist_enabled'   => 1,
        'blacklist_enabled'   => 1,
        'whitelist_levels'    => '1,2,3,4,5',
        'blacklist_levels'    => '1,2,3,4,5',
        'whitelist'           => '',
        'blacklist'           => '',
        'login_bg'            => '',
        'login_title'         => '',
        'login_tip'           => '',
        'auto_jump_seconds'   => 5,
        'ip_whitelist'        => '',
        'ip_blacklist'        => '',
        'custom_403_html'     => MLOGIN_DEFAULT_403_HTML,
        'allowed_time_ranges' => '',
        'time_guest_mode'     => 0,
        // [新增] 改进项配置字段
        'trust_proxy'              => 0,      // #2 反向代理信任
        'extra_system_pass'        => '',     // #3 自定义放行路径
        'timezone_offset'          => 8,      // #15 时区偏移
        'login_fail_max'           => 0,      // #6 登录失败最大次数（0=关闭）
        'login_fail_lock_minutes'  => 15,     // #6 锁定时间（分钟）
        'log_only_blocked'         => 0,      // #10 仅记录拦截日志
        'category_access_enabled'  => 0,      // #8 分类/标签控制开关
        'require_login_categories' => '',     // #8 需要登录的分类
        'require_login_tags'       => '',     // #8 需要登录的标签
    ];

    if (!$zbp->HasConfig('mlogin')) {
        $cfg = $zbp->Config('mlogin');
        foreach ($defaults as $key => $val) {
            $cfg->$key = $val;
        }
        $zbp->SaveConfig('mlogin');
    } else {
        $cfg = $zbp->Config('mlogin');
        $changed = false;
        foreach ($defaults as $key => $val) {
            if (!isset($cfg->$key)) {
                $cfg->$key = $val;
                $changed = true;
            }
        }
        if ($changed) $zbp->SaveConfig('mlogin');
    }

    // 初始化日志目录保护
    mlogin_ensure_log_protection();
}

/**
 * 插件停用
 */
function UninstallPlugin_mlogin()
{
    // 不做任何操作，保留配置
}

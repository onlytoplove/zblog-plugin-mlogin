<?php
/**
 * mlogin 插件 - 后台配置页面（增强版）
 *
 * 模块：基础规则、登录外观、规则测试、访问日志、数据备份、高级设置
 */

// 先加载 Z-Blog 系统（必须在 require include.php 之前，因为 include.php 中调用了 RegisterPlugin）
require '../../../zb_system/function/c_system_base.php';
require '../../../zb_system/function/c_system_admin.php';

$zbp->Load();

// [改进#12] Z-Blog 加载后再 require include.php，消除常量重复定义
// 此时 RegisterPlugin 等 Z-Blog 函数已可用
require_once __DIR__ . '/include.php';

if (!$zbp->CheckRights('root')) { $zbp->ShowError(6); die(); }
if (!$zbp->CheckPlugin('mlogin')) { $zbp->ShowError(48); die(); }

$allowedTabs = ['basic', 'login', 'backup', 'test', 'logs', 'advanced'];

// ─── AJAX 辅助 ──────────────────────────────────────────────

function mlogin_json_exit($success, $msg = '', array $extra = [])
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['success' => $success, 'msg' => $msg], $extra));
    exit();
}

function mlogin_check_csrf()
{
    global $zbp;
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $zbp->GetCSRFToken())
        mlogin_json_exit(false, 'CSRF令牌校验失败');
}

function mlogin_check_csrf_get()
{
    global $zbp;
    if (!isset($_GET['csrf_token']) || $_GET['csrf_token'] !== $zbp->GetCSRFToken())
        mlogin_json_exit(false, 'CSRF令牌校验失败');
}

function mlogin_upload_paths()
{
    global $zbp;
    return [__DIR__ . '/' . MLOGIN_UPLOAD_DIR_NAME . '/',
            $zbp->host . 'zb_users/plugin/mlogin/' . MLOGIN_UPLOAD_DIR_NAME . '/'];
}

// ─── AJAX：删除图片 ─────────────────────────────────────────
if (isset($_GET['act']) && $_GET['act'] === 'delete_image') {
    mlogin_check_csrf();
    $fileUrl = trim($_POST['file_url'] ?? '');
    [, $webPrefix] = mlogin_upload_paths();

    if (strpos($fileUrl, $webPrefix) !== 0) mlogin_json_exit(false, '非法文件地址');
    $fileName = substr($fileUrl, strlen($webPrefix));
    if (strpos($fileName, '/') !== false || strpos($fileName, '\\') !== false || strpos($fileName, '..') !== false)
        mlogin_json_exit(false, '非法文件名');

    $realDir      = realpath(__DIR__ . '/' . MLOGIN_UPLOAD_DIR_NAME);
    $realFilePath = realpath(__DIR__ . '/' . MLOGIN_UPLOAD_DIR_NAME . '/' . $fileName);
    if ($realFilePath === false || strpos($realFilePath, $realDir) !== 0)
        mlogin_json_exit(false, '文件路径异常');
    if (!is_file($realFilePath)) mlogin_json_exit(false, '文件不存在');

    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if (!in_array($ext, MLOGIN_ALLOWED_IMG_EXT)) mlogin_json_exit(false, '非法文件类型');
    $ok = @unlink($realFilePath);
    mlogin_json_exit($ok, $ok ? '删除成功' : '删除失败');
}

// ─── AJAX：上传图片 ─────────────────────────────────────────
if (isset($_GET['act']) && $_GET['act'] === 'upload_image') {
    mlogin_check_csrf();
    if (!isset($_FILES['image_file']) || $_FILES['image_file']['error'] !== UPLOAD_ERR_OK)
        mlogin_json_exit(false, '上传错误');
    if ($_FILES['image_file']['size'] > MLOGIN_MAX_IMG_SIZE)
        mlogin_json_exit(false, '图片超过 5MB');

    [$uploadDir] = mlogin_upload_paths();
    if (!file_exists($uploadDir)) @mkdir($uploadDir, 0755, true);

    $ext = strtolower(pathinfo($_FILES['image_file']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, MLOGIN_ALLOWED_IMG_EXT)) mlogin_json_exit(false, '不支持的格式');

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    if (!in_array($finfo->file($_FILES['image_file']['tmp_name']), MLOGIN_ALLOWED_IMG_MIME))
        mlogin_json_exit(false, '非有效图片');

    $newFile = 'mlogin_' . date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $target  = $uploadDir . $newFile;
    if (move_uploaded_file($_FILES['image_file']['tmp_name'], $target)) {
        [, $webPrefix] = mlogin_upload_paths();
        mlogin_json_exit(true, '上传成功', ['url' => $webPrefix . $newFile]);
    }
    mlogin_json_exit(false, '移动文件失败');
}

// ─── AJAX：导出 JSON ────────────────────────────────────────
if (isset($_GET['act']) && $_GET['act'] === 'export_json') {
    mlogin_check_csrf_get();
    $cfg = $zbp->Config('mlogin');
    $data = [
        'plugin_enabled'      => (int)($cfg->plugin_enabled ?? 1),
        'whitelist_enabled'   => (int)($cfg->whitelist_enabled ?? 1),
        'blacklist_enabled'   => (int)($cfg->blacklist_enabled ?? 1),
        'whitelist_levels'    => $cfg->whitelist_levels ?? '1,2,3,4,5',
        'blacklist_levels'    => $cfg->blacklist_levels ?? '1,2,3,4,5',
        'whitelist'           => $cfg->whitelist,
        'blacklist'           => $cfg->blacklist,
        'ip_whitelist'        => $cfg->ip_whitelist ?? '',
        'ip_blacklist'        => $cfg->ip_blacklist ?? '',
        'custom_403_html'     => $cfg->custom_403_html ?? '',
        'allowed_time_ranges' => $cfg->allowed_time_ranges ?? '',
        'time_guest_mode'     => (int)($cfg->time_guest_mode ?? 0),
        'login_bg'            => $cfg->login_bg,
        'login_title'         => $cfg->login_title,
        'login_tip'           => $cfg->login_tip,
        'auto_jump_seconds'   => (int)($cfg->auto_jump_seconds ?? 0),
        // [新增字段]
        'trust_proxy'              => (int)($cfg->trust_proxy ?? 0),
        'extra_system_pass'        => $cfg->extra_system_pass ?? '',
        'timezone_offset'          => (int)($cfg->timezone_offset ?? 8),
        'login_fail_max'           => (int)($cfg->login_fail_max ?? 0),
        'login_fail_lock_minutes'  => (int)($cfg->login_fail_lock_minutes ?? 15),
        'log_only_blocked'         => (int)($cfg->log_only_blocked ?? 0),
        'category_access_enabled'  => (int)($cfg->category_access_enabled ?? 0),
        'require_login_categories' => $cfg->require_login_categories ?? '',
        'require_login_tags'       => $cfg->require_login_tags ?? '',
    ];
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="mlogin_backup.json"');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit();
}

// ─── [改进#7] AJAX：日志筛选 ─────────────────────────────────
if (isset($_POST['act']) && $_POST['act'] === 'filter_logs') {
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $zbp->GetCSRFToken())
        mlogin_json_exit(false, 'CSRF令牌校验失败');

    $filterAction = trim($_POST['filter_action'] ?? '');   // ALL/BLOCKED/ALLOWED/REDIRECT
    $filterIp     = trim($_POST['filter_ip'] ?? '');
    $filterDate   = trim($_POST['filter_date'] ?? '');    // YYYY-MM-DD
    $maxLines     = max(50, min(2000, (int)($_POST['max_lines'] ?? 500)));

    $logLines = [];
    if (file_exists(MLOGIN_LOG_FILE)) {
        $allLines = file(MLOGIN_LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($allLines as $line) {
            // Action filter
            if ($filterAction !== '' && $filterAction !== 'ALL') {
                if (strpos($line, $filterAction) === false) continue;
            }
            // IP filter
            if ($filterIp !== '' && stripos($line, 'IP: ' . $filterIp) === false) continue;
            // Date filter
            if ($filterDate !== '' && strpos($line, '[' . $filterDate) === false) continue;

            $logLines[] = $line;
        }
    }

    $logLines = array_reverse(array_slice($logLines, -$maxLines));
    mlogin_json_exit(true, '筛选完成', [
        'lines'     => $logLines,
        'total'     => count($logLines),
    ]);
}

// ─── AJAX：规则测试（[改进#14] 支持用户级别模拟）─────────────
if (isset($_POST['act']) && $_POST['act'] === 'test_rule') {
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $zbp->GetCSRFToken())
        mlogin_json_exit(false, 'CSRF令牌校验失败');

    $testUri   = trim($_POST['test_uri'] ?? '');
    $testIp    = trim($_POST['test_ip'] ?? '127.0.0.1');
    $testLevel = (int)($_POST['test_level'] ?? 0); // 0=未登录, 1-5=用户级别
    if ($testUri === '') mlogin_json_exit(false, '请输入测试 URI');

    $cfg = $zbp->Config('mlogin');
    $steps = [];

    $finish = function($steps, $action, $class, $rule, $uri, $ip, $level) {
        echo json_encode([
            'success' => true, 'uri' => $uri, 'ip' => $ip, 'level' => $level,
            'steps' => $steps, 'final_action' => $action,
            'final_class' => $class, 'matched_rule' => $rule,
        ], JSON_UNESCAPED_UNICODE);
        exit();
    };

    $levelLabel = $testLevel === 0 ? '未登录用户' : '级别 ' . $testLevel;

    // Step 0: 总开关
    if ((int)($cfg->plugin_enabled ?? 1) === 0) {
        $steps[] = ['✅ 插件已关闭', '放行（插件未启用）'];
        $finish($steps, '✅ 放行', 'ml-result-allow', '插件已关闭', $testUri, $testIp, $levelLabel);
    }
    $steps[] = ['✅ 插件已启用', '继续检查（模拟: ' . $levelLabel . '）'];

    // Step 1: 系统路径
    $systemPass = MLOGIN_SYSTEM_PASS;
    $extraPass = mlogin_parse_lines($cfg->extra_system_pass ?? '');
    if (!empty($extraPass)) $systemPass = array_merge($systemPass, $extraPass);

    if (mlogin_uri_matches($testUri, $systemPass)) {
        $steps[] = ['✅ 系统路径命中', '放行'];
        $finish($steps, '✅ 放行', 'ml-result-allow', '系统路径', $testUri, $testIp, $levelLabel);
    }
    $steps[] = ['ℹ️ 非系统路径', '继续检查'];

    // Step 2: 时间段
    $timeRanges = $cfg->allowed_time_ranges ?? '';
    $timeMode   = (int)($cfg->time_guest_mode ?? 0);
    $tzOffset   = (int)($cfg->timezone_offset ?? 8);
    if (!in_array($timeMode, [0, 1, 2], true)) $timeMode = 0;

    if ($timeMode === 0) {
        $steps[] = ['ℹ️ 时间段控制已关闭', '继续后续规则'];
    } elseif (trim($timeRanges) !== '') {
        if (!mlogin_in_time_range($timeRanges, $tzOffset)) {
            if ($timeMode === 1) {
                $steps[] = ['ℹ️ 不在允许时间段内（模式1）', '继续后续检查'];
            } else {
                $steps[] = ['⛔ 不在允许时间段内', '拦截（' . mlogin_now_hhmm($tzOffset) . ' 不在配置时段）'];
                $finish($steps, '🚫 拦截 (403)', 'ml-result-block', '时间段控制', $testUri, $testIp, $levelLabel);
            }
        } else {
            $steps[] = ['✅ 时间段检查通过', '当前 ' . mlogin_now_hhmm($tzOffset) . ' 在允许范围内'];
            $steps[] = ['✅ 免登录放行', '直接访问所有页面'];
            $finish($steps, '✅ 放行', 'ml-result-allow', '时间段内免登录', $testUri, $testIp, $levelLabel);
        }
    } else {
        $steps[] = ['✅ 未配置时间段', '继续检查'];
    }

    // Step 3: IP 黑名单
    $ipBL = mlogin_parse_lines($cfg->ip_blacklist ?? '');
    if (!empty($ipBL) && mlogin_ip_matches($testIp, $ipBL)) {
        $steps[] = ['⛔ IP 黑名单命中', '拦截'];
        $finish($steps, '🚫 拦截 (403)', 'ml-result-block', 'IP 黑名单', $testUri, $testIp, $levelLabel);
    }
    $steps[] = ['✅ IP 黑名单通过', '不在黑名单中'];

    // Step 4: IP 白名单
    $ipWL = mlogin_parse_lines($cfg->ip_whitelist ?? '');
    if (!empty($ipWL) && mlogin_ip_matches($testIp, $ipWL)) {
        $steps[] = ['✅ IP 白名单命中', '放行'];
        $finish($steps, '✅ 放行', 'ml-result-allow', 'IP 白名单', $testUri, $testIp, $levelLabel);
    }
    $steps[] = ['ℹ️ IP 白名单未命中', empty($ipWL) ? '未配置' : '不在白名单中'];

    // Step 5: URI 黑名单
    if ((int)($cfg->blacklist_enabled ?? 1) === 1) {
        $bl = mlogin_parse_lines($cfg->blacklist ?? '');
        if (!empty($bl) && mlogin_uri_matches($testUri, $bl)) {
            $blLevels = $cfg->blacklist_levels ?? '';
            $levelsInfo = $blLevels !== '' ? '（生效级别: ' . $blLevels . '）' : '（所有级别生效）';
            // [改进#14] 级别判断
            if ($testLevel === 0 || mlogin_level_enabled($testLevel, $blLevels)) {
                $steps[] = ['⛔ URI 黑名单命中' . $levelsInfo, '拦截（' . $levelLabel . '适用）'];
                $finish($steps, '🚫 拦截 (403)', 'ml-result-block', 'URI 黑名单', $testUri, $testIp, $levelLabel);
            } else {
                $steps[] = ['ℹ️ URI 黑名单命中但不适用', $levelLabel . '不在生效级别中，跳过'];
            }
        }
        $steps[] = ['✅ URI 黑名单通过', '不在黑名单中'];
    } else {
        $steps[] = ['ℹ️ URI 黑名单已禁用', '跳过'];
    }

    // Step 6: URI 白名单
    if ((int)($cfg->whitelist_enabled ?? 1) === 1) {
        $wl = mlogin_parse_lines($cfg->whitelist ?? '');
        if (!empty($wl) && mlogin_uri_matches($testUri, $wl)) {
            $wlLevels = $cfg->whitelist_levels ?? '';
            $levelsInfo = $wlLevels !== '' ? '（生效级别: ' . $wlLevels . '）' : '（所有级别生效）';
            if ($testLevel === 0 || mlogin_level_enabled($testLevel, $wlLevels)) {
                $steps[] = ['✅ URI 白名单命中' . $levelsInfo, '放行（' . $levelLabel . '适用）'];
                $finish($steps, '✅ 放行', 'ml-result-allow', 'URI 白名单', $testUri, $testIp, $levelLabel);
            } else {
                $steps[] = ['ℹ️ URI 白名单命中但不适用', $levelLabel . '不在生效级别中，跳过'];
            }
        }
        $steps[] = ['ℹ️ URI 白名单未命中', empty($wl) ? '未配置' : '不在白名单中'];
    } else {
        $steps[] = ['ℹ️ URI 白名单已禁用', '跳过'];
    }

    // 最终
    if ($testLevel === 0) {
        $steps[] = ['⚠️ 需要登录', '未匹配任何放行/拦截规则'];
        $finish($steps, '🔐 需要登录', 'ml-result-login', '无（需要登录）', $testUri, $testIp, $levelLabel);
    } else {
        $steps[] = ['✅ 已登录用户放行', $levelLabel . ' 已通过所有检查'];
        $finish($steps, '✅ 放行', 'ml-result-allow', '已登录用户通过', $testUri, $testIp, $levelLabel);
    }
}

// ─── 保存配置 ────────────────────────────────────────────────
if (count($_POST) > 0) {
    mlogin_check_csrf();
    $currentTab = in_array($_POST['current_tab'] ?? '', $allowedTabs) ? $_POST['current_tab'] : 'basic';
    $cfg = $zbp->Config('mlogin');

    if ($currentTab === 'basic') {
        $cfg->plugin_enabled    = (isset($_POST['plugin_enabled']) && $_POST['plugin_enabled'] === '1') ? 1 : 0;
        $cfg->whitelist_enabled = (isset($_POST['whitelist_enabled']) && $_POST['whitelist_enabled'] === '1') ? 1 : 0;
        $cfg->blacklist_enabled = (isset($_POST['blacklist_enabled']) && $_POST['blacklist_enabled'] === '1') ? 1 : 0;
        // 白名单级别
        $wlLevels = [];
        for ($i = 1; $i <= 5; $i++) {
            if (isset($_POST['whitelist_level_' . $i]) && $_POST['whitelist_level_' . $i] === '1') {
                $wlLevels[] = $i;
            }
        }
        $cfg->whitelist_levels = implode(',', $wlLevels);

        // 黑名单级别
        $blLevels = [];
        for ($i = 1; $i <= 5; $i++) {
            if (isset($_POST['blacklist_level_' . $i]) && $_POST['blacklist_level_' . $i] === '1') {
                $blLevels[] = $i;
            }
        }
        $cfg->blacklist_levels = implode(',', $blLevels);

        $cfg->whitelist    = mlogin_clean_lines($_POST['whitelist'] ?? '');
        $cfg->blacklist    = mlogin_clean_lines($_POST['blacklist'] ?? '');
        $cfg->ip_whitelist = mlogin_clean_lines($_POST['ip_whitelist'] ?? '');
        $cfg->ip_blacklist = mlogin_clean_lines($_POST['ip_blacklist'] ?? '');
        $cfg->custom_403_html = $_POST['custom_403_html'] ?? '';

        // 时间段：逐行校验
        $rawTime = $_POST['allowed_time_ranges'] ?? '';
        $validLines = []; $invalidLines = [];
        foreach (mlogin_parse_lines($rawTime) as $line) {
            $norm = mlogin_normalize_time_range($line);
            if ($norm === '') $invalidLines[] = $line; else $validLines[] = $norm;
        }
        if (!empty($invalidLines)) {
            $bad = implode(' | ', array_slice($invalidLines, 0, 3));
            Redirect($zbp->host . 'zb_users/plugin/mlogin/main.php?act=time_invalid&tab=basic&bad=' . urlencode($bad));
            exit();
        }
        $cfg->allowed_time_ranges = implode("\n", $validLines);
        $cfg->time_guest_mode = in_array((int)($_POST['time_guest_mode'] ?? 0), [0, 1, 2], true)
            ? (int)$_POST['time_guest_mode'] : 0;
    }

    if ($currentTab === 'login') {
        $rawBg = trim($_POST['login_bg'] ?? '');
        if ($rawBg !== '' && !preg_match('/^https?:\/\//i', $rawBg) && strpos($rawBg, '/') !== 0) $rawBg = '';
        $cfg->login_bg    = $rawBg;
        $cfg->login_title = trim($_POST['login_title'] ?? '');
        $cfg->login_tip   = trim($_POST['login_tip'] ?? '');

        $jumpEnable  = isset($_POST['auto_jump_enable']) && $_POST['auto_jump_enable'] === '1';
        $jumpSeconds = (int)($_POST['auto_jump_seconds'] ?? 0);
        if ($jumpSeconds < 1) $jumpSeconds = 5;
        $cfg->auto_jump_seconds = $jumpEnable ? mlogin_clamp_jump_seconds($jumpSeconds) : 0;
    }

    // [新增] 高级设置保存
    if ($currentTab === 'advanced') {
        // 反向代理
        $cfg->trust_proxy = (isset($_POST['trust_proxy']) && $_POST['trust_proxy'] === '1') ? 1 : 0;

        // 自定义放行路径
        $cfg->extra_system_pass = mlogin_clean_lines($_POST['extra_system_pass'] ?? '');

        // 时区偏移
        $tzOffset = (int)($_POST['timezone_offset'] ?? 8);
        $cfg->timezone_offset = ($tzOffset >= -12 && $tzOffset <= 14) ? $tzOffset : 8;

        // 拦截频率限制
        $cfg->login_fail_max = max(0, min(100, (int)($_POST['login_fail_max'] ?? 0)));
        $cfg->login_fail_lock_minutes = max(1, min(1440, (int)($_POST['login_fail_lock_minutes'] ?? 15)));

        // 日志优化
        $cfg->log_only_blocked = (isset($_POST['log_only_blocked']) && $_POST['log_only_blocked'] === '1') ? 1 : 0;

        // 分类/标签控制
        $cfg->category_access_enabled = (isset($_POST['category_access_enabled']) && $_POST['category_access_enabled'] === '1') ? 1 : 0;
        $cfg->require_login_categories = mlogin_clean_lines($_POST['require_login_categories'] ?? '');
        $cfg->require_login_tags = mlogin_clean_lines($_POST['require_login_tags'] ?? '');
    }

    if ($currentTab === 'backup' && isset($_POST['backup_action']) && $_POST['backup_action'] === 'import') {
        $importData = '';
        if (isset($_FILES['import_file']) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
            if ($_FILES['import_file']['size'] > MLOGIN_MAX_JSON_SIZE) {
                Redirect($zbp->host . 'zb_users/plugin/mlogin/main.php?act=file_too_large&tab=backup');
                exit();
            }
            $importData = file_get_contents($_FILES['import_file']['tmp_name']);
        } elseif (!empty($_POST['import_text'])) {
            $importData = trim($_POST['import_text']);
        }
        if (!empty($importData) && strlen($importData) > MLOGIN_MAX_JSON_SIZE) {
            Redirect($zbp->host . 'zb_users/plugin/mlogin/main.php?act=file_too_large&tab=backup');
            exit();
        }
        if (!empty($importData)) {
            $data = json_decode($importData, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                foreach (['plugin_enabled', 'whitelist_enabled', 'blacklist_enabled'] as $k) {
                    if (isset($data[$k])) $cfg->$k = ((int)$data[$k] === 1) ? 1 : 0;
                }
                foreach (['whitelist_levels', 'blacklist_levels'] as $k) {
                    if (isset($data[$k]) && is_string($data[$k])) $cfg->$k = $data[$k];
                }
                foreach (['whitelist', 'blacklist', 'login_bg', 'login_title', 'login_tip',
                           'ip_whitelist', 'ip_blacklist', 'custom_403_html',
                           'extra_system_pass', 'require_login_categories', 'require_login_tags'] as $k) {
                    if (isset($data[$k]) && is_string($data[$k])) $cfg->$k = $data[$k];
                }
                // 新增整数字段
                foreach (['trust_proxy', 'timezone_offset', 'login_fail_max', 'login_fail_lock_minutes',
                           'log_only_blocked', 'category_access_enabled'] as $k) {
                    if (isset($data[$k])) $cfg->$k = (int)$data[$k];
                }
                // 时间段规范化
                if (isset($data['allowed_time_ranges']) && is_string($data['allowed_time_ranges'])) {
                    $valid = [];
                    foreach (mlogin_parse_lines($data['allowed_time_ranges']) as $l) {
                        $n = mlogin_normalize_time_range($l);
                        if ($n !== '') $valid[] = $n;
                    }
                    $cfg->allowed_time_ranges = implode("\n", $valid);
                }
                if (isset($data['auto_jump_seconds']))
                    $cfg->auto_jump_seconds = mlogin_clamp_jump_seconds($data['auto_jump_seconds']);
                if (isset($data['time_guest_mode']))
                    $cfg->time_guest_mode = in_array((int)$data['time_guest_mode'], [0,1,2], true)
                        ? (int)$data['time_guest_mode'] : 0;
            } else {
                Redirect($zbp->host . 'zb_users/plugin/mlogin/main.php?act=json_error&tab=backup');
                exit();
            }
        }
    }

    // 恢复默认 403
    if ($currentTab === 'basic' && isset($_POST['basic_action']) && $_POST['basic_action'] === 'reset_403') {
        $cfg->custom_403_html = MLOGIN_DEFAULT_403_HTML;
        $zbp->SaveConfig('mlogin');
        Redirect($zbp->host . 'zb_users/plugin/mlogin/main.php?act=reset_403_success&tab=basic');
        exit();
    }

    // 清空日志
    if ($currentTab === 'logs' && isset($_POST['logs_action']) && $_POST['logs_action'] === 'clear') {
        if (file_exists(MLOGIN_LOG_FILE)) @file_put_contents(MLOGIN_LOG_FILE, '');
        Redirect($zbp->host . 'zb_users/plugin/mlogin/main.php?act=logs_cleared&tab=logs');
        exit();
    }

    $zbp->SaveConfig('mlogin');
    Redirect($zbp->host . 'zb_users/plugin/mlogin/main.php?act=save_success&tab=' . $currentTab);
    exit();
}

// ─── 读取配置 ────────────────────────────────────────────────
$cfg = $zbp->Config('mlogin');
[$uploadDirAbs, $uploadDirWeb] = mlogin_upload_paths();

// 扫描已上传图片
$imageFileList = [];
if (is_dir($uploadDirAbs)) {
    foreach (scandir($uploadDirAbs) as $f) {
        if ($f === '.' || $f === '..') continue;
        if (is_file($uploadDirAbs . $f) && in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), MLOGIN_ALLOWED_IMG_EXT))
            $imageFileList[] = $uploadDirWeb . $f;
    }
}

// 配置值
$pluginEnabled    = (int)($cfg->plugin_enabled ?? 1) === 1;
$whitelistEnabled = (int)($cfg->whitelist_enabled ?? 1) === 1;
$blacklistEnabled = (int)($cfg->blacklist_enabled ?? 1) === 1;
$whitelistLevels = $cfg->whitelist_levels ?? '1,2,3,4,5';
$blacklistLevels = $cfg->blacklist_levels ?? '1,2,3,4,5';
$whitelistLevelArr = array_map('intval', explode(',', $whitelistLevels));
$blacklistLevelArr = array_map('intval', explode(',', $blacklistLevels));
$whitelist    = $cfg->whitelist;
$blacklist    = $cfg->blacklist;
$ipWhitelist  = $cfg->ip_whitelist ?? '';
$ipBlacklist  = $cfg->ip_blacklist ?? '';
$custom403    = $cfg->custom_403_html ?? '';
$timeRanges   = $cfg->allowed_time_ranges ?? '';
$timeMode     = (int)($cfg->time_guest_mode ?? 0);
if (!in_array($timeMode, [0, 1, 2], true)) $timeMode = 0;
$loginBg      = $cfg->login_bg;
$loginTitle   = $cfg->login_title;
$loginTip     = $cfg->login_tip;
$autoJumpSec  = mlogin_clamp_jump_seconds($cfg->auto_jump_seconds ?? 0);
$autoJumpOn   = $autoJumpSec > 0;
$autoJumpDisp = $autoJumpOn ? $autoJumpSec : 5;

// [新增] 高级设置配置值
$trustProxy       = (int)($cfg->trust_proxy ?? 0) === 1;
$extraSystemPass  = $cfg->extra_system_pass ?? '';
$timezoneOffset   = (int)($cfg->timezone_offset ?? 8);
$loginFailMax     = (int)($cfg->login_fail_max ?? 0);
$loginFailLock    = (int)($cfg->login_fail_lock_minutes ?? 15);
$logOnlyBlocked   = (int)($cfg->log_only_blocked ?? 0) === 1;
$catAccessEnabled = (int)($cfg->category_access_enabled ?? 0) === 1;
$reqLoginCats     = $cfg->require_login_categories ?? '';
$reqLoginTags     = $cfg->require_login_tags ?? '';

// 反馈消息
$successMsg = '';
$msgMap = [
    'reset_403_success' => '✅ 403 页面已恢复为默认模板！',
    'save_success'      => '✅ 配置已成功保存！',
    'logs_cleared'      => '✅ 日志已清空！',
    'json_error'        => '❌ JSON 格式错误，请检查内容！',
    'file_too_large'    => '❌ 导入文件过大，请检查文件大小！',
];
$act = $_GET['act'] ?? '';
if (isset($msgMap[$act])) {
    $cls = in_array($act, ['json_error', 'file_too_large']) ? 'ml-alert-error' : 'ml-alert-success';
    $successMsg = '<div class="ml-alert ' . $cls . '"><span>' . $msgMap[$act] . '</span></div>';
}
if ($act === 'time_invalid') {
    $successMsg = '<div class="ml-alert ml-alert-error"><span>❌ 时间段格式无效，已取消保存（格式 HH:MM-HH:MM，如 09:00-18:00；支持跨午夜 22:00-06:00）</span></div>';
}

$_tabParam = $_GET['tab'] ?? 'basic';
$activeTab = in_array($_tabParam, $allowedTabs) ? $_tabParam : 'basic';
$csrfToken = $zbp->GetCSRFToken();
$blogtitle = 'mlogin 插件配置';
require $blogpath . 'zb_system/admin/admin_header.php';
require $blogpath . 'zb_system/admin/admin_top.php';
?>
<style>
.ml-wrapper{max-width:1000px;margin:0 auto;padding:20px 0 60px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#374151}
.ml-card{background:#fff;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.05),0 1px 2px rgba(0,0,0,.03);border:1px solid #e5e7eb;padding:32px;margin-bottom:24px}
.ml-tabs{display:flex;gap:8px;margin-bottom:24px;border-bottom:1px solid #e5e7eb;padding-bottom:1px;flex-wrap:wrap}
.ml-tab{padding:12px 20px;text-decoration:none;color:#6b7280;font-weight:500;font-size:14px;border-bottom:2px solid transparent;transition:all .2s;margin-bottom:-1px}
.ml-tab:hover{color:#2563eb}.ml-tab.active{color:#2563eb;border-bottom-color:#2563eb;font-weight:600}
.ml-form-group{margin-bottom:28px}
.ml-label{display:block;font-weight:600;margin-bottom:8px;color:#111827;font-size:14px}
.ml-input,.ml-textarea{width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;transition:border-color .15s,box-shadow .15s;background:#fff;box-sizing:border-box;color:#1f2937}
.ml-textarea{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;line-height:1.6;resize:vertical;min-height:100px}
.ml-input:focus,.ml-textarea:focus{border-color:#2563eb;outline:none;box-shadow:0 0 0 3px rgba(37,99,235,.1)}
.ml-hint{font-size:13px;color:#6b7280;margin-top:8px;display:block;line-height:1.5}
.ml-upload-row{display:flex;align-items:stretch;gap:0}
.ml-upload-row .ml-input{border-top-right-radius:0;border-bottom-right-radius:0;border-right:none;background:#f9fafb}
.ml-upload-row .ml-btn{border-top-left-radius:0;border-bottom-left-radius:0;white-space:nowrap}
.ml-btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 16px;border:1px solid transparent;border-radius:6px;cursor:pointer;font-size:14px;font-weight:500;transition:all .2s;text-decoration:none;line-height:1.5}
.ml-btn:active{transform:translateY(1px)}
.ml-btn-primary{background:#2563eb;color:#fff}.ml-btn-primary:hover{background:#1d4ed8}
.ml-btn-secondary{background:#f3f4f6;color:#374151;border-color:#d1d5db}.ml-btn-secondary:hover{background:#e5e7eb;border-color:#9ca3af;color:#111827}
.ml-btn-danger{background:#fee2e2;color:#dc2626;border-color:#fecaca}.ml-btn-danger:hover{background:#fecaca;color:#b91c1c}
.ml-gallery{display:grid;grid-template-columns:repeat(auto-fill,minmax(70px,1fr));gap:12px;margin-top:16px}
.ml-gallery-item{position:relative;aspect-ratio:1;border-radius:6px;overflow:hidden;cursor:pointer;border:1px solid #e5e7eb;transition:all .2s;background:#f9fafb}
.ml-gallery-item:hover{border-color:#2563eb;box-shadow:0 4px 6px -1px rgba(0,0,0,.1)}
.ml-gallery-item img{width:100%;height:100%;object-fit:cover;display:block}
.ml-gallery-del{position:absolute;top:4px;right:4px;width:18px;height:18px;background:rgba(0,0,0,.6);color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;opacity:0;transition:opacity .2s;cursor:pointer}
.ml-gallery-item:hover .ml-gallery-del{opacity:1}.ml-gallery-del:hover{background:#dc2626}
.ml-split-layout{display:flex;gap:32px;align-items:flex-start}
.ml-col-left{flex:1;min-width:0}.ml-col-right{flex:0 0 55%;position:sticky;top:20px}
.ml-preview-viewport{position:relative;width:100%;overflow:hidden;border-radius:10px;border:1px solid #d1d5db;background:#e5e7eb}
.ml-preview-label{position:absolute;top:8px;left:12px;z-index:20;font-size:11px;color:#6b7280;background:rgba(255,255,255,.85);padding:3px 10px;border-radius:4px;font-weight:500;letter-spacing:.3px;backdrop-filter:blur(4px)}
.ml-preview-iframe-wrap{width:100%;height:600px;overflow:hidden}
.ml-preview-iframe-wrap iframe{width:100%;height:100%;border:none;display:block}
.ml-backup-grid{display:grid;grid-template-columns:1fr 1fr;gap:24px}
.ml-backup-box{background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:20px}
.ml-section-head{font-size:15px;font-weight:600;color:#111827;margin-bottom:12px;display:flex;align-items:center;gap:6px}
.ml-alert{padding:12px 16px;border-radius:6px;margin-bottom:20px;font-size:14px;font-weight:500;display:flex;align-items:center;gap:8px}
.ml-alert-success{background:#ecfdf5;color:#059669;border:1px solid #a7f3d0}
.ml-alert-error{background:#fef2f2;color:#dc2626;border:1px solid #fecaca}
.ml-switch-group{display:flex;align-items:center;gap:12px;background:#f9fafb;padding:12px;border-radius:6px;border:1px solid #e5e7eb}
.ml-checkbox{width:16px;height:16px;accent-color:#2563eb;cursor:pointer}
.ml-divider{border:none;border-top:1px solid #e5e7eb;margin:28px 0 24px}
.ml-sub-title{font-size:15px;font-weight:600;color:#374151;margin-bottom:16px;padding-left:10px;border-left:3px solid #2563eb}
.ml-log-box{background:#1f2937;color:#d1d5db;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:12px;line-height:1.7;padding:16px;border-radius:8px;max-height:500px;overflow-y:auto;white-space:pre-wrap;word-break:break-all}
.ml-log-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:20px}
.ml-stat-card{background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:14px;text-align:center}
.ml-stat-num{font-size:24px;font-weight:700;color:#111827}.ml-stat-label{font-size:12px;color:#6b7280;margin-top:4px}
.ml-result-box{padding:16px;border-radius:8px;margin-top:16px;font-size:14px;line-height:1.8}
.ml-result-allow{background:#ecfdf5;border:1px solid #a7f3d0;color:#059669}
.ml-result-block{background:#fef2f2;border:1px solid #fecaca;color:#dc2626}
.ml-result-login{background:#fffbeb;border:1px solid #fde68a;color:#d97706}
/* [新增] 高级设置样式 */
.ml-adv-grid{display:grid;grid-template-columns:1fr 1fr;gap:24px}
.ml-adv-box{background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:20px}
.ml-adv-box-full{grid-column:1/-1}
.ml-select{width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;background:#fff;color:#1f2937;box-sizing:border-box}
.ml-select:focus{border-color:#2563eb;outline:none;box-shadow:0 0 0 3px rgba(37,99,235,.1)}
.ml-log-filter{display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap;align-items:flex-end}
.ml-log-filter .ml-form-group{margin-bottom:0;flex:1;min-width:120px}
.ml-badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600}
.ml-badge-new{background:#dbeafe;color:#2563eb}
@media(max-width:1100px){.ml-split-layout{flex-direction:column}.ml-col-right{width:100%;flex:none;position:static}.ml-backup-grid{grid-template-columns:1fr}.ml-adv-grid{grid-template-columns:1fr}}
</style>

<div class="ml-wrapper">
    <?php echo $successMsg; ?>

    <div class="ml-tabs">
        <a href="?tab=basic" class="ml-tab <?php echo $activeTab=='basic'?'active':''; ?>">⚙️ 基础规则</a>
        <a href="?tab=login" class="ml-tab <?php echo $activeTab=='login'?'active':''; ?>">🎨 登录外观</a>
        <a href="?tab=test" class="ml-tab <?php echo $activeTab=='test'?'active':''; ?>">🧪 规则测试</a>
        <a href="?tab=logs" class="ml-tab <?php echo $activeTab=='logs'?'active':''; ?>">📋 访问日志</a>
        <a href="?tab=advanced" class="ml-tab <?php echo $activeTab=='advanced'?'active':''; ?>">🔧 高级设置 <span class="ml-badge ml-badge-new">NEW</span></a>
        <a href="?tab=backup" class="ml-tab <?php echo $activeTab=='backup'?'active':''; ?>">💾 数据备份</a>
    </div>

    <!-- ==================== 基础配置 ==================== -->
    <div class="ml-card" style="display:<?php echo $activeTab=='basic'?'block':'none'; ?>;">
        <form method="post" action="">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
            <input type="hidden" name="current_tab" value="basic">

            <!-- 总开关 -->
            <div class="ml-form-group" style="margin-bottom:24px;">
                <div class="ml-switch-group" style="background:<?php echo $pluginEnabled?'#ecfdf5':'#fef2f2'; ?>;border-color:<?php echo $pluginEnabled?'#a7f3d0':'#fecaca'; ?>;">
                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-weight:600;color:#111827;font-size:15px;">
                        <input type="checkbox" name="plugin_enabled" id="plugin_enabled" class="ml-checkbox" value="1" <?php echo $pluginEnabled?'checked':''; ?> style="width:18px;height:18px;">
                        🔌 启用插件
                    </label>
                    <span style="color:#6b7280;font-size:13px;margin-left:auto;" id="plugin_status"><?php echo $pluginEnabled?'已启用 — 登录检查正常运行':'已禁用 — 所有登录检查已跳过'; ?></span>
                </div>
                <span class="ml-hint" style="margin-top:6px;">关闭后，插件不再拦截任何页面访问。系统后台路径始终放行。</span>
            </div>

            <!-- URI 白名单/黑名单 -->
            <div class="ml-sub-title">URI 规则</div>
            <div class="ml-split-layout">
                <div class="ml-col-left">
                    <div class="ml-form-group">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                            <label class="ml-label" style="margin-bottom:0;">✅ 白名单 (免登录)</label>
                            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;color:#374151;">
                                <input type="checkbox" name="whitelist_enabled" id="whitelist_enabled" class="ml-checkbox" value="1" <?php echo $whitelistEnabled?'checked':''; ?>> 启用
                            </label>
                        </div>
                        <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:10px;padding:8px 10px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;">
                            <span style="font-size:12px;color:#0369a1;font-weight:600;line-height:24px;">生效级别：</span>
                            <?php $levelNames = [1=>'管理员',2=>'网站编辑',3=>'作者',4=>'协作者',5=>'评论者']; ?>
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                            <label style="display:flex;align-items:center;gap:4px;cursor:pointer;font-size:12px;color:#374151;">
                                <input type="hidden" name="whitelist_level_<?php echo $i; ?>" value="0">
                                <input type="checkbox" name="whitelist_level_<?php echo $i; ?>" class="ml-checkbox" value="1" <?php echo in_array($i, $whitelistLevelArr)?'checked':''; ?> style="width:14px;height:14px;">
                                <?php echo $levelNames[$i]; ?>
                            </label>
                            <?php endfor; ?>
                            <span style="font-size:11px;color:#6b7280;line-height:24px;">（未登录用户始终适用）</span>
                        </div>
                        <textarea name="whitelist" class="ml-textarea" rows="8" placeholder="每行一个路径，例如：&#10;?life&#10;?id=2" <?php echo !$whitelistEnabled?'style="opacity:0.5;"':''; ?>><?php echo htmlspecialchars($whitelist); ?></textarea>
                        <span class="ml-hint">允许游客直接访问的路径，匹配后放行。仅对勾选级别的用户生效，未勾选级别的用户不受此白名单影响。</span>
                    </div>
                </div>
                <div class="ml-col-left">
                    <div class="ml-form-group">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                            <label class="ml-label" style="margin-bottom:0;">🚫 黑名单 (直接 403)</label>
                            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;color:#374151;">
                                <input type="checkbox" name="blacklist_enabled" id="blacklist_enabled" class="ml-checkbox" value="1" <?php echo $blacklistEnabled?'checked':''; ?>> 启用
                            </label>
                        </div>
                        <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:10px;padding:8px 10px;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;">
                            <span style="font-size:12px;color:#dc2626;font-weight:600;line-height:24px;">生效级别：</span>
                            <?php // $levelNames 已在上方白名单区域定义 ?>
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                            <label style="display:flex;align-items:center;gap:4px;cursor:pointer;font-size:12px;color:#374151;">
                                <input type="hidden" name="blacklist_level_<?php echo $i; ?>" value="0">
                                <input type="checkbox" name="blacklist_level_<?php echo $i; ?>" class="ml-checkbox" value="1" <?php echo in_array($i, $blacklistLevelArr)?'checked':''; ?> style="width:14px;height:14px;">
                                <?php echo $levelNames[$i]; ?>
                            </label>
                            <?php endfor; ?>
                            <span style="font-size:11px;color:#6b7280;line-height:24px;">（未登录用户始终适用）</span>
                        </div>
                        <textarea name="blacklist" class="ml-textarea" rows="8" placeholder="每行一个路径，例如：&#10;?private-page" <?php echo !$blacklistEnabled?'style="opacity:0.5;"':''; ?>><?php echo htmlspecialchars($blacklist); ?></textarea>
                        <span class="ml-hint">禁止访问的路径，匹配后返回 403。仅对勾选级别的用户生效，未勾选级别的用户不受此黑名单限制。</span>
                    </div>
                </div>
            </div>

            <hr class="ml-divider">

            <!-- IP 白名单/黑名单 -->
            <div class="ml-sub-title">IP 规则</div>
            <div class="ml-split-layout">
                <div class="ml-col-left">
                    <div class="ml-form-group">
                        <label class="ml-label">🔓 IP 白名单</label>
                        <textarea name="ip_whitelist" class="ml-textarea" rows="5" placeholder="每行一个，支持：&#10;192.168.1.100&#10;192.168.1.0/24&#10;10.0.0.*&#10;2001:db8::/32"><?php echo htmlspecialchars($ipWhitelist); ?></textarea>
                        <span class="ml-hint">匹配的 IP 直接放行，无需登录。支持精确、CIDR、通配符。现已支持 IPv6 地址。</span>
                    </div>
                </div>
                <div class="ml-col-left">
                    <div class="ml-form-group">
                        <label class="ml-label">🔒 IP 黑名单</label>
                        <textarea name="ip_blacklist" class="ml-textarea" rows="5" placeholder="每行一个，支持：&#10;203.0.113.50&#10;198.51.100.0/24&#10;172.16.*.*&#10;::1"><?php echo htmlspecialchars($ipBlacklist); ?></textarea>
                        <span class="ml-hint">匹配的 IP 直接拦截，返回 403。优先于 IP 白名单。现已支持 IPv6 地址。</span>
                    </div>
                </div>
            </div>

            <hr class="ml-divider">

            <!-- 时间段控制 -->
            <div class="ml-sub-title">时间段控制</div>
            <div class="ml-form-group">
                <label class="ml-label">⏰ 允许访问的时间段</label>
                <textarea name="allowed_time_ranges" class="ml-textarea" rows="3" placeholder="每行一个，格式 HH:MM-HH:MM&#10;例如：&#10;09:00-18:00&#10;22:00-06:00"><?php echo htmlspecialchars($timeRanges); ?></textarea>
                <span class="ml-hint">配合下方模式使用。模式「关闭」时时间段不生效；模式 1/2 时仅在指定时段内放行。支持跨午夜与全角符号。当前时区偏移：UTC<?php echo $timezoneOffset >= 0 ? '+' : ''; echo $timezoneOffset; ?></span>
                <div class="ml-switch-group" style="margin-top:12px;flex-direction:column;align-items:stretch;gap:8px;">
                    <div style="font-weight:600;color:#111827;font-size:14px;margin-bottom:2px;">时间段模式</div>
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:#374151;">
                        <input type="radio" name="time_guest_mode" value="0" class="ml-checkbox" <?php echo $timeMode==0?'checked':''; ?>>
                        关闭（默认）：时间段不生效，其他规则照常
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:#374151;">
                        <input type="radio" name="time_guest_mode" value="1" class="ml-checkbox" <?php echo $timeMode==1?'checked':''; ?>>
                        时段内免登录放行；时段外走白名单
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:#374151;">
                        <input type="radio" name="time_guest_mode" value="2" class="ml-checkbox" <?php echo $timeMode==2?'checked':''; ?>>
                        时段内免登录放行；时段外直接 403
                    </label>
                </div>
            </div>

            <hr class="ml-divider">

            <!-- 自定义 403 -->
            <div class="ml-sub-title">自定义 403 页面</div>
            <div class="ml-form-group">
                <label class="ml-label">🎨 403 页面 HTML</label>
                <textarea name="custom_403_html" class="ml-textarea" style="min-height:160px;" placeholder="留空使用默认模板。支持变量：&#10;{{reason}} {{site_name}} {{site_url}} {{current_time}}"><?php echo htmlspecialchars($custom403); ?></textarea>
                <span class="ml-hint">支持完整 HTML。变量：<code>{{reason}}</code> <code>{{site_name}}</code> <code>{{site_url}}</code> <code>{{current_time}}</code></span>
                <div style="margin-top:10px;">
                    <button type="submit" name="basic_action" value="reset_403" class="ml-btn ml-btn-secondary" onclick="return confirm('确定恢复默认 403 页面？')">🔄 默认 403 页面</button>
                </div>
            </div>

            <div style="text-align:right;margin-top:10px;">
                <button type="submit" class="ml-btn ml-btn-primary">💾 保存设置</button>
            </div>
        </form>
    </div>

    <!-- ==================== 登录外观 ==================== -->
    <div class="ml-card" style="display:<?php echo $activeTab=='login'?'block':'none'; ?>;">
        <form method="post" action="" id="login_config_form">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
            <input type="hidden" name="current_tab" value="login">
            <div class="ml-split-layout">
                <div class="ml-col-left">
                    <div class="ml-form-group">
                        <label class="ml-label">背景图片</label>
                        <div class="ml-upload-row">
                            <input type="text" name="login_bg" id="input_bg" class="ml-input" value="<?php echo htmlspecialchars($loginBg, ENT_QUOTES, 'UTF-8'); ?>" placeholder="图片 URL">
                            <button type="button" class="ml-btn ml-btn-secondary" id="btn_upload_bg">选择图片</button>
                        </div>
                        <input type="file" id="file_bg" accept="image/*" style="display:none;">
                        <div class="ml-gallery">
                            <?php if (!empty($imageFileList)): ?>
                                <?php foreach ($imageFileList as $imgUrl): ?>
                                    <div class="ml-gallery-item" data-img-url="<?php echo htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8'); ?>">
                                        <span class="ml-gallery-del" data-del-url="<?php echo htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8'); ?>">×</span>
                                        <img src="<?php echo htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="">
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div style="grid-column:1/-1;color:#9ca3af;font-size:13px;padding:10px;text-align:center;">暂无已上传图片</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="ml-form-group">
                        <label class="ml-label">页面标题</label>
                        <input type="text" name="login_title" id="input_title" class="ml-input" value="<?php echo htmlspecialchars($loginTitle, ENT_QUOTES, 'UTF-8'); ?>" placeholder="例如：需要登录后访问">
                    </div>
                    <div class="ml-form-group">
                        <label class="ml-label">提示文案</label>
                        <textarea name="login_tip" id="input_tip" class="ml-textarea" style="min-height:80px;" placeholder="例如：该内容仅限登录用户查看"><?php echo htmlspecialchars($loginTip, ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>
                    <div class="ml-form-group">
                        <label class="ml-label">自动跳转</label>
                        <div class="ml-switch-group">
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:500;color:#374151;">
                                <input type="checkbox" name="auto_jump_enable" id="auto_jump_enable" class="ml-checkbox" value="1" <?php echo $autoJumpOn?'checked':''; ?>> 启用
                            </label>
                            <input type="number" name="auto_jump_seconds" id="auto_jump_seconds" class="ml-input" style="width:70px;padding:4px 8px;" min="1" max="120" value="<?php echo $autoJumpDisp; ?>">
                            <span style="color:#6b7280;font-size:13px;">秒后跳转</span>
                        </div>
                    </div>
                    <div style="margin-top:30px;display:flex;gap:12px;justify-content:flex-end;">
                        <button type="button" class="ml-btn ml-btn-secondary" id="btn_reset_login">🔄 恢复默认</button>
                        <button type="submit" class="ml-btn ml-btn-primary">💾 保存外观设置</button>
                    </div>
                </div>
                <div class="ml-col-right">
                    <div class="ml-preview-viewport">
                        <div class="ml-preview-label">实时预览</div>
                        <div class="ml-preview-iframe-wrap">
                            <iframe id="live-preview-iframe"></iframe>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- ==================== 规则测试 ==================== -->
    <div class="ml-card" style="display:<?php echo $activeTab=='test'?'block':'none'; ?>;">
        <div class="ml-section-head" style="font-size:17px;margin-bottom:20px;">🧪 规则测试</div>
        <p class="ml-hint" style="margin-top:-10px;margin-bottom:20px;">输入 URI、IP 和用户级别，模拟检查流程，验证规则配置是否正确。</p>
        <div id="test_form_area">
            <div class="ml-split-layout">
                <div class="ml-col-left">
                    <div class="ml-form-group">
                        <label class="ml-label">测试 URI</label>
                        <input type="text" id="ajax_test_uri" class="ml-input" placeholder="例如：/?id=2" value="">
                    </div>
                </div>
                <div class="ml-col-left" style="flex:0 0 25%;">
                    <div class="ml-form-group">
                        <label class="ml-label">测试 IP</label>
                        <input type="text" id="ajax_test_ip" class="ml-input" placeholder="127.0.0.1" value="">
                    </div>
                </div>
                <div class="ml-col-left" style="flex:0 0 25%;">
                    <div class="ml-form-group">
                        <label class="ml-label">模拟用户级别 <span class="ml-badge ml-badge-new">NEW</span></label>
                        <select id="ajax_test_level" class="ml-select" style="padding:8px 10px;">
                            <option value="0">未登录用户</option>
                            <option value="1">1 - 管理员</option>
                            <option value="2">2 - 网站编辑</option>
                            <option value="3">3 - 作者</option>
                            <option value="4">4 - 协作者</option>
                            <option value="5">5 - 评论者</option>
                        </select>
                    </div>
                </div>
            </div>
            <button type="button" id="btn_ajax_test" class="ml-btn ml-btn-primary">🔍 开始测试</button>
            <span id="test_loading" style="display:none;margin-left:12px;color:#6b7280;">测试中...</span>
        </div>
        <div id="test_result_area" style="display:none;margin-top:24px;"></div>
    </div>

    <!-- ==================== 访问日志 ==================== -->
    <div class="ml-card" style="display:<?php echo $activeTab=='logs'?'block':'none'; ?>;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <div class="ml-section-head" style="font-size:17px;margin-bottom:0;">📋 访问日志</div>
            <form method="post" action="" style="margin:0;">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="current_tab" value="logs">
                <input type="hidden" name="logs_action" value="clear">
                <button type="submit" class="ml-btn ml-btn-danger" onclick="return confirm('确定清空所有日志？')">🗑️ 清空日志</button>
            </form>
        </div>

        <?php
        $logStats = ['total' => 0, 'blocked' => 0, 'allowed' => 0, 'redirect' => 0];
        $logLines = [];
        if (file_exists(MLOGIN_LOG_FILE)) {
            $allLines = file(MLOGIN_LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $logStats['total'] = count($allLines);
            foreach ($allLines as $line) {
                if (strpos($line, 'BLOCKED') !== false) $logStats['blocked']++;
                elseif (strpos($line, 'ALLOWED') !== false) $logStats['allowed']++;
                elseif (strpos($line, 'REDIRECT') !== false) $logStats['redirect']++;
            }
            $logLines = array_reverse(array_slice($allLines, -500));
        }
        ?>
        <div class="ml-log-stats">
            <div class="ml-stat-card"><div class="ml-stat-num"><?php echo $logStats['total']; ?></div><div class="ml-stat-label">总记录</div></div>
            <div class="ml-stat-card"><div class="ml-stat-num" style="color:#dc2626;"><?php echo $logStats['blocked']; ?></div><div class="ml-stat-label">已拦截</div></div>
            <div class="ml-stat-card"><div class="ml-stat-num" style="color:#059669;"><?php echo $logStats['allowed']; ?></div><div class="ml-stat-label">已放行</div></div>
            <div class="ml-stat-card"><div class="ml-stat-num" style="color:#d97706;"><?php echo $logStats['redirect']; ?></div><div class="ml-stat-label">跳转登录</div></div>
        </div>

        <!-- [改进#7] 日志筛选器 -->
        <div class="ml-log-filter">
            <div class="ml-form-group">
                <label class="ml-label" style="font-size:12px;margin-bottom:4px;">操作类型</label>
                <select id="log_filter_action" class="ml-select" style="padding:6px 10px;font-size:13px;">
                    <option value="">全部</option>
                    <option value="BLOCKED">已拦截</option>
                    <option value="ALLOWED">已放行</option>
                    <option value="REDIRECT">跳转登录</option>
                </select>
            </div>
            <div class="ml-form-group">
                <label class="ml-label" style="font-size:12px;margin-bottom:4px;">IP 地址</label>
                <input type="text" id="log_filter_ip" class="ml-input" style="padding:6px 10px;font-size:13px;" placeholder="输入 IP 筛选">
            </div>
            <div class="ml-form-group">
                <label class="ml-label" style="font-size:12px;margin-bottom:4px;">日期</label>
                <input type="date" id="log_filter_date" class="ml-input" style="padding:6px 10px;font-size:13px;">
            </div>
            <div class="ml-form-group" style="flex:0 0 auto;">
                <label class="ml-label" style="font-size:12px;margin-bottom:4px;">&nbsp;</label>
                <button type="button" id="btn_filter_logs" class="ml-btn ml-btn-secondary" style="padding:6px 16px;font-size:13px;">🔍 筛选</button>
                <button type="button" id="btn_reset_filter" class="ml-btn ml-btn-secondary" style="padding:6px 16px;font-size:13px;">↩️ 重置</button>
            </div>
        </div>

        <?php if (empty($logLines)): ?>
            <div style="text-align:center;padding:40px;color:#9ca3af;font-size:14px;">📭 暂无日志</div>
        <?php else: ?>
            <div class="ml-log-box" id="log_content_box"><?php echo htmlspecialchars(implode("\n", $logLines)); ?></div>
            <span class="ml-hint" style="margin-top:10px;" id="log_count_hint">显示最近 <?php echo count($logLines); ?> 条。日志超 10MB 自动归档，超 30 天自动清理。日志目录已自动添加 .htaccess 保护（Apache）。</span>
        <?php endif; ?>
    </div>

    <!-- ==================== [新增] 高级设置 ==================== -->
    <div class="ml-card" style="display:<?php echo $activeTab=='advanced'?'block':'none'; ?>;">
        <form method="post" action="">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
            <input type="hidden" name="current_tab" value="advanced">

            <div class="ml-adv-grid">
                <!-- 反向代理 IP -->
                <div class="ml-adv-box">
                    <div class="ml-section-head">🔗 反向代理 IP 获取</div>
                    <div class="ml-form-group" style="margin-bottom:12px;">
                        <div class="ml-switch-group">
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:500;color:#374151;">
                                <input type="checkbox" name="trust_proxy" class="ml-checkbox" value="1" <?php echo $trustProxy?'checked':''; ?>>
                                信任反向代理头
                            </label>
                        </div>
                        <span class="ml-hint">启用后，将从 X-Forwarded-For、X-Real-IP、CF-Connecting-IP 等 HTTP 头中提取客户端真实 IP。适用于 CDN（Cloudflare 等）或 Nginx 反向代理环境。<strong style="color:#dc2626;">仅在确认代理可信时启用。</strong></span>
                    </div>
                </div>

                <!-- 时区配置 -->
                <div class="ml-adv-box">
                    <div class="ml-section-head">🌍 时区配置</div>
                    <div class="ml-form-group" style="margin-bottom:12px;">
                        <label class="ml-label">时区偏移（小时）</label>
                        <select name="timezone_offset" class="ml-select">
                            <?php for ($tz = -12; $tz <= 14; $tz++): ?>
                                <option value="<?php echo $tz; ?>" <?php echo $timezoneOffset==$tz?'selected':''; ?>>
                                    UTC<?php echo $tz >= 0 ? '+' : ''; ?><?php echo $tz; ?>
                                    <?php if ($tz === 8) echo '（北京时间）'; ?>
                                    <?php if ($tz === 0) echo '（格林威治）'; ?>
                                    <?php if ($tz === 9) echo '（东京时间）'; ?>
                                    <?php if ($tz === -5) echo '（美东时间）'; ?>
                                    <?php if ($tz === -8) echo '（美西时间）'; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                        <span class="ml-hint">影响时间段控制的时间计算。默认 UTC+8（北京时间）。</span>
                    </div>
                </div>

                <!-- 拦截频率限制 -->
                <div class="ml-adv-box">
                    <div class="ml-section-head">🔐 拦截频率限制</div>
                    <div class="ml-form-group" style="margin-bottom:12px;">
                        <label class="ml-label">拦截频率上限</label>
                        <input type="number" name="login_fail_max" class="ml-input" style="width:100px;" min="0" max="100" value="<?php echo $loginFailMax; ?>">
                        <span class="ml-hint">同一 IP 在时间窗口内被拦截的最大次数，超过后临时锁定。设为 0 表示关闭。</span>
                    </div>
                    <div class="ml-form-group" style="margin-bottom:0;">
                        <label class="ml-label">锁定时间窗口（分钟）</label>
                        <input type="number" name="login_fail_lock_minutes" class="ml-input" style="width:100px;" min="1" max="1440" value="<?php echo $loginFailLock; ?>">
                        <span class="ml-hint">超过拦截频率上限后，锁定该 IP 的时长（分钟）。</span>
                    </div>
                </div>

                <!-- 日志优化 -->
                <div class="ml-adv-box">
                    <div class="ml-section-head">📝 日志写入优化</div>
                    <div class="ml-form-group" style="margin-bottom:0;">
                        <div class="ml-switch-group">
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:500;color:#374151;">
                                <input type="checkbox" name="log_only_blocked" class="ml-checkbox" value="1" <?php echo $logOnlyBlocked?'checked':''; ?>>
                                仅记录拦截日志
                            </label>
                        </div>
                        <span class="ml-hint">启用后，放行类日志不再写入文件，仅记录拦截和跳转日志。适合高流量站点减少 I/O。</span>
                    </div>
                </div>

                <!-- 自定义放行路径 -->
                <div class="ml-adv-box ml-adv-box-full">
                    <div class="ml-section-head">🛤️ 自定义放行路径</div>
                    <div class="ml-form-group" style="margin-bottom:0;">
                        <textarea name="extra_system_pass" class="ml-textarea" rows="4" placeholder="每行一个路径，例如：&#10;zb_users/plugin/my_plugin/api.php&#10;custom/callback.php"><?php echo htmlspecialchars($extraSystemPass); ?></textarea>
                        <span class="ml-hint">除系统内置放行路径外，额外需要始终放行的 URI 路径。这些路径无论用户是否登录都会直接放行。内置放行路径已收窄，不再默认放行整个 zb_users/plugin/ 目录。</span>
                    </div>
                </div>

                <!-- 分类/标签控制 -->
                <div class="ml-adv-box ml-adv-box-full">
                    <div class="ml-section-head">📂 按分类/标签设置登录要求</div>
                    <div class="ml-form-group" style="margin-bottom:16px;">
                        <div class="ml-switch-group">
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:500;color:#374151;">
                                <input type="checkbox" name="category_access_enabled" class="ml-checkbox" value="1" <?php echo $catAccessEnabled?'checked':''; ?>>
                                启用分类/标签登录控制
                            </label>
                        </div>
                        <span class="ml-hint">启用后，属于下方指定分类或标签的文章，未登录用户将被重定向到中转页。</span>
                    </div>
                    <div class="ml-split-layout">
                        <div class="ml-col-left">
                            <div class="ml-form-group" style="margin-bottom:0;">
                                <label class="ml-label">需要登录的分类</label>
                                <textarea name="require_login_categories" class="ml-textarea" rows="4" placeholder="每行一个，支持分类名称或分类 ID&#10;例如：&#10;会员专区&#10;3"><?php echo htmlspecialchars($reqLoginCats); ?></textarea>
                                <span class="ml-hint">属于这些分类的文章，未登录用户需要登录后才能查看。</span>
                            </div>
                        </div>
                        <div class="ml-col-left">
                            <div class="ml-form-group" style="margin-bottom:0;">
                                <label class="ml-label">需要登录的标签</label>
                                <textarea name="require_login_tags" class="ml-textarea" rows="4" placeholder="每行一个标签名称&#10;例如：&#10;付费内容&#10;内部资料"><?php echo htmlspecialchars($reqLoginTags); ?></textarea>
                                <span class="ml-hint">包含这些标签的文章，未登录用户需要登录后才能查看。</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div style="text-align:right;margin-top:20px;">
                <button type="submit" class="ml-btn ml-btn-primary">💾 保存高级设置</button>
            </div>
        </form>
    </div>

    <!-- ==================== 数据备份 ==================== -->
    <div class="ml-card" style="display:<?php echo $activeTab=='backup'?'block':'none'; ?>;">
        <div class="ml-backup-grid">
            <div class="ml-backup-box">
                <div class="ml-section-head">📤 导出配置</div>
                <textarea id="export_data" readonly class="ml-textarea" style="background:#fff;height:200px;font-size:12px;color:#6b7280;"></textarea>
                <div class="ml-hint" style="margin-bottom:16px;">当前配置的 JSON 数据，可直接复制保存。</div>
                <a href="?act=export_json&csrf_token=<?php echo urlencode($csrfToken); ?>" class="ml-btn ml-btn-primary" style="width:100%;">📥 下载 JSON 备份</a>
            </div>
            <div class="ml-backup-box">
                <form method="post" action="" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="current_tab" value="backup">
                    <input type="hidden" name="backup_action" value="import">
                    <div class="ml-section-head">📥 导入配置</div>
                    <textarea name="import_text" class="ml-textarea" style="height:160px;" placeholder="粘贴 JSON 数据..."></textarea>
                    <div style="margin:12px 0;font-size:13px;color:#4b5563;">或选择文件（最大 1MB）：</div>
                    <input type="file" name="import_file" accept=".json" class="ml-input" style="padding:6px;font-size:13px;">
                    <button type="submit" class="ml-btn ml-btn-danger" style="width:100%;margin-top:16px;">🚀 导入并覆盖</button>
                </form>
            </div>
        </div>
    </div>

</div>

<script>
(function(){
    'use strict';
    function esc(s){var d=document.createElement('div');d.textContent=s||'';return d.innerHTML}

    // --- 开关交互 ---
    var pluginSw = document.getElementById('plugin_enabled');
    var wlSw = document.getElementById('whitelist_enabled');
    var blSw = document.getElementById('blacklist_enabled');
    var statusEl = document.getElementById('plugin_status');
    function updatePluginUI(){
        var on = pluginSw && pluginSw.checked;
        var g = pluginSw ? pluginSw.closest('.ml-switch-group') : null;
        if(g){g.style.background=on?'#ecfdf5':'#fef2f2';g.style.borderColor=on?'#a7f3d0':'#fecaca'}
        if(statusEl) statusEl.textContent=on?'已启用 — 登录检查正常运行':'已禁用 — 所有登录检查已跳过';
    }
    function updateListUI(sw,ta){if(ta) ta.style.opacity=(sw&&sw.checked)?'1':'0.5'}
    if(pluginSw) pluginSw.addEventListener('change',function(){updatePluginUI();markDirty()});
    if(wlSw) wlSw.addEventListener('change',function(){updateListUI(wlSw,document.querySelector('textarea[name="whitelist"]'));markDirty()});
    if(blSw) blSw.addEventListener('change',function(){updateListUI(blSw,document.querySelector('textarea[name="blacklist"]'));markDirty()});

    // --- 导出 JSON ---
    var exportData = <?php echo json_encode([
        'plugin_enabled'      => $pluginEnabled ? 1 : 0,
        'whitelist_enabled'   => $whitelistEnabled ? 1 : 0,
        'blacklist_enabled'   => $blacklistEnabled ? 1 : 0,
        'whitelist_levels'    => $whitelistLevels,
        'blacklist_levels'    => $blacklistLevels,
        'whitelist'           => $whitelist,
        'blacklist'           => $blacklist,
        'ip_whitelist'        => $ipWhitelist,
        'ip_blacklist'        => $ipBlacklist,
        'custom_403_html'     => $custom403,
        'allowed_time_ranges' => $timeRanges,
        'time_guest_mode'     => $timeMode,
        'login_bg'            => $loginBg,
        'login_title'         => $loginTitle,
        'login_tip'           => $loginTip,
        'auto_jump_seconds'   => $autoJumpSec,
        'trust_proxy'         => $trustProxy ? 1 : 0,
        'extra_system_pass'   => $extraSystemPass,
        'timezone_offset'     => $timezoneOffset,
        'login_fail_max'      => $loginFailMax,
        'login_fail_lock_minutes' => $loginFailLock,
        'log_only_blocked'    => $logOnlyBlocked ? 1 : 0,
        'category_access_enabled' => $catAccessEnabled ? 1 : 0,
        'require_login_categories' => $reqLoginCats,
        'require_login_tags'  => $reqLoginTags,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT); ?>;
    var csrfToken = <?php echo json_encode($csrfToken); ?>;
    var exportEl = document.getElementById('export_data');
    if(exportEl) exportEl.value = JSON.stringify(exportData,null,2);

    // --- 上传背景图 ---
    var fileBg = document.getElementById('file_bg');
    var inputBg = document.getElementById('input_bg');
    var btnUpload = document.getElementById('btn_upload_bg');
    var previewIframe = document.getElementById('live-preview-iframe');
    if(btnUpload && fileBg){
        btnUpload.addEventListener('click',function(){fileBg.click()});
        fileBg.addEventListener('change',function(){
            if(!this.files||!this.files[0]) return;
            if(this.files[0].size>5*1024*1024){alert('图片超过 5MB');return}
            var fd=new FormData(); fd.append('image_file',this.files[0]); fd.append('csrf_token',csrfToken);
            var orig=btnUpload.innerText; btnUpload.disabled=true; btnUpload.innerText='上传中...';
            var xhr=new XMLHttpRequest();
            xhr.open('POST','?act=upload_image',true);
            xhr.onload=function(){
                btnUpload.disabled=false; btnUpload.innerText=orig;
                if(xhr.status===200){
                    try{var r=JSON.parse(xhr.responseText);
                        if(r.success&&r.url){inputBg.value=r.url;renderPreview();location.reload()}
                        else alert(r.msg||'上传失败');
                    }catch(e){alert('返回数据异常')}
                }else alert('上传失败');
            };
            xhr.send(fd);
        });
    }
    if(inputBg) inputBg.addEventListener('input',renderPreview);
    var titleInput = document.getElementById('input_title');
    var tipInput = document.getElementById('input_tip');
    var jumpSw = document.getElementById('auto_jump_enable');
    var jumpSec = document.getElementById('auto_jump_seconds');
    var DEF_TITLE = '该内容登录后可访问';
    var DEF_TIP = '请登录后查看完整内容';
    if(titleInput) titleInput.addEventListener('input',renderPreview);
    if(tipInput) tipInput.addEventListener('input',renderPreview);
    if(jumpSw) jumpSw.addEventListener('change',renderPreview);
    if(jumpSec) jumpSec.addEventListener('input',renderPreview);

    // 恢复默认
    var btnReset = document.getElementById('btn_reset_login');
    if(btnReset) btnReset.addEventListener('click',function(){
        if(!confirm('确定恢复中转页为默认配置？此操作将立即保存。')) return;
        if(inputBg) inputBg.value='';
        if(titleInput){titleInput.value=DEF_TITLE;titleInput.dispatchEvent(new Event('input'))}
        if(tipInput){tipInput.value=DEF_TIP;tipInput.dispatchEvent(new Event('input'))}
        if(jumpSw) jumpSw.checked=true;
        if(jumpSec) jumpSec.value=5;
        renderPreview();
        document.getElementById('login_config_form').submit();
    });

    // 图片库点击选中
    document.querySelectorAll('.ml-gallery-item').forEach(function(item){
        item.addEventListener('click',function(e){
            if(e.target.classList.contains('ml-gallery-del')) return;
            if(inputBg){inputBg.value=this.dataset.imgUrl;renderPreview()}
        });
    });

    // 图片库删除
    document.querySelectorAll('.ml-gallery-del').forEach(function(btn){
        btn.addEventListener('click',function(e){
            e.stopPropagation();
            var url=this.dataset.delUrl;
            if(!confirm('确认删除这张图片？')) return;
            var self=this;
            var xhr=new XMLHttpRequest();
            xhr.open('POST','?act=delete_image',true);
            xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
            xhr.onload=function(){
                if(xhr.status===200){
                    try{var r=JSON.parse(xhr.responseText);
                        if(r.success){self.closest('.ml-gallery-item').remove();
                            if(inputBg&&inputBg.value===url){inputBg.value='';renderPreview()}}
                        else alert(r.msg||'删除失败');
                    }catch(e){}
                }
            };
            xhr.send('csrf_token='+encodeURIComponent(csrfToken)+'&file_url='+encodeURIComponent(url));
        });
    });

    // --- iframe 预览 ---
    function renderPreview(){
        if(!previewIframe) return;
        var bg = inputBg?inputBg.value:'';
        var title = titleInput?(titleInput.value.trim()||DEF_TITLE):DEF_TITLE;
        var tip = tipInput?tipInput.value:'';
        var jOn = jumpSw?jumpSw.checked:false;
        var jSec = jumpSec?parseInt(jumpSec.value,10):5;
        if(isNaN(jSec)||jSec<1) jSec=5;
        var tipHtml = esc(tip).replace(/\n/g,'<br>');
        var jumpHtml = jOn?'<div style="font-size:13px;color:#888;margin-top:16px">等待 <span style="color:#2563eb;font-weight:bold">'+jSec+'</span> 秒后自动跳转...</div>':'';
        var bgStyle = bg?"background:url('"+esc(bg)+"') center/cover no-repeat fixed;":'background:#fff;';
        var html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>'
            + '*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}'
            + 'html,body{width:100%;height:100%;'+bgStyle+'display:flex;justify-content:center;align-items:center;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}'
            + '.card{background:rgba(255,255,255,.95);width:90%;max-width:420px;border-radius:24px;overflow:hidden;box-shadow:0 10px 40px -10px rgba(0,0,0,.15);text-align:center;backdrop-filter:blur(10px)}'
            + '.body{padding:32px 24px}'
            + '.title{font-size:20px;font-weight:700;color:#1a1a1a;margin-bottom:12px}'
            + '.tip{font-size:14px;color:#666;margin-bottom:32px;line-height:1.6}'
            + '.btn{display:block;width:100%;padding:14px 0;background:#2563eb;color:#fff;border:none;border-radius:12px;font-size:16px;font-weight:600;cursor:pointer;box-shadow:0 4px 12px rgba(37,99,235,.2);text-decoration:none;margin-bottom:16px}'
            + '</style></head><body>'
            + '<div class="card"><div class="body">'
            + '<div class="title">'+esc(title)+'</div>'
            + '<div class="tip">'+tipHtml+'</div>'
            + '<a href="#" class="btn">点击登录</a>'
            + jumpHtml
            + '</div></div></body></html>';
        var doc=previewIframe.contentWindow.document;doc.open();doc.write(html);doc.close();
    }
    renderPreview();

    // --- [改进#14] AJAX 规则测试（含用户级别模拟）---
    var btnTest = document.getElementById('btn_ajax_test');
    if(btnTest) btnTest.addEventListener('click',function(){
        var uri=document.getElementById('ajax_test_uri').value.trim();
        var ip=document.getElementById('ajax_test_ip').value.trim();
        var level=document.getElementById('ajax_test_level')?parseInt(document.getElementById('ajax_test_level').value,10):0;
        var loading=document.getElementById('test_loading');
        var area=document.getElementById('test_result_area');
        if(!uri){alert('请输入测试 URI');return}
        btnTest.disabled=true;loading.style.display='inline';area.style.display='none';
        var fd=new FormData();
        fd.append('act','test_rule');fd.append('csrf_token',csrfToken);
        fd.append('test_uri',uri);fd.append('test_ip',ip||'127.0.0.1');
        fd.append('test_level',level);
        var xhr=new XMLHttpRequest();
        xhr.open('POST','',true);
        xhr.onload=function(){
            btnTest.disabled=false;loading.style.display='none';
            if(xhr.status===200){
                try{
                    var r=JSON.parse(xhr.responseText);
                    if(!r.success&&r.msg){alert(r.msg);return}
                    var h='<div class="ml-result-box '+(r.final_class||'')+'">';
                    h+='<div style="font-size:18px;font-weight:700;margin-bottom:8px">最终：'+esc(r.final_action)+'</div>';
                    h+='<div style="font-size:13px;opacity:.8">URI: <code>'+esc(r.uri)+'</code> | IP: <code>'+esc(r.ip)+'</code> | 级别: <strong>'+esc(r.level)+'</strong> | 命中: <strong>'+esc(r.matched_rule)+'</strong></div></div>';
                    if(r.steps&&r.steps.length){
                        h+='<div style="margin-top:20px"><div class="ml-sub-title" style="font-size:14px">检查流程</div>';
                        h+='<table style="width:100%;border-collapse:collapse;font-size:13px">';
                        for(var i=0;i<r.steps.length;i++){
                            h+='<tr style="border-bottom:1px solid #e5e7eb"><td style="padding:10px 12px;font-weight:600;color:#374151;width:40%">'+esc(r.steps[i][0])+'</td>';
                            h+='<td style="padding:10px 12px;color:#6b7280">'+esc(r.steps[i][1])+'</td></tr>';
                        }
                        h+='</table></div>';
                    }
                    area.innerHTML=h;area.style.display='block';
                }catch(e){alert('解析失败: '+e.message)}
            }else alert('HTTP '+xhr.status);
        };
        xhr.onerror=function(){btnTest.disabled=false;loading.style.display='none';alert('网络错误')};
        xhr.send(fd);
    });

    // --- [改进#7] 日志筛选 AJAX ---
    var btnFilterLogs = document.getElementById('btn_filter_logs');
    var btnResetFilter = document.getElementById('btn_reset_filter');
    if(btnFilterLogs) btnFilterLogs.addEventListener('click', function(){
        var action = document.getElementById('log_filter_action').value;
        var ip = document.getElementById('log_filter_ip').value.trim();
        var date = document.getElementById('log_filter_date').value;
        var box = document.getElementById('log_content_box');
        var hint = document.getElementById('log_count_hint');
        if(!box) return;

        btnFilterLogs.disabled = true;
        btnFilterLogs.innerText = '筛选中...';

        var fd = new FormData();
        fd.append('act', 'filter_logs');
        fd.append('csrf_token', csrfToken);
        fd.append('filter_action', action);
        fd.append('filter_ip', ip);
        fd.append('filter_date', date);
        fd.append('max_lines', '500');

        var xhr = new XMLHttpRequest();
        xhr.open('POST', '', true);
        xhr.onload = function(){
            btnFilterLogs.disabled = false;
            btnFilterLogs.innerText = '🔍 筛选';
            if(xhr.status === 200){
                try{
                    var r = JSON.parse(xhr.responseText);
                    if(r.success && r.lines){
                        if(r.lines.length === 0){
                            box.textContent = '（无匹配日志）';
                        } else {
                            box.textContent = r.lines.join('\n');
                        }
                        if(hint) hint.textContent = '筛选结果：' + r.total + ' 条匹配记录';
                    } else {
                        alert(r.msg || '筛选失败');
                    }
                }catch(e){alert('解析失败: '+e.message)}
            }
        };
        xhr.onerror = function(){btnFilterLogs.disabled=false;btnFilterLogs.innerText='🔍 筛选';alert('网络错误')};
        xhr.send(fd);
    });

    if(btnResetFilter) btnResetFilter.addEventListener('click', function(){
        document.getElementById('log_filter_action').value = '';
        document.getElementById('log_filter_ip').value = '';
        document.getElementById('log_filter_date').value = '';
        location.reload();
    });

})();
</script>
<?php
require $blogpath . 'zb_system/admin/admin_footer.php';
RunTime();

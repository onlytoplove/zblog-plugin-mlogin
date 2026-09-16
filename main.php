<?php
/**
 * mlogin 插件 - 后台配置页面
 *
 * 模块：基础规则、登录外观、规则测试、访问日志、数据备份、高级设置
 */

// ─── 系统引导 ──────────────────────────────────────────────────
require_once __DIR__ . '/bootstrap.php';

// ─── 路由分发 ──────────────────────────────────────────────────
$mloginRouter = new MloginRouter($zbp);
$mloginRouter->dispatch();

/**
 * 后台路由控制器
 * 统一处理 AJAX 请求和页面渲染
 */
class MloginRouter
{
    private $zbp;
    private $config;
    private $allowedTabs = ['basic', 'login', 'backup', 'test', 'logs', 'advanced'];

    public function __construct($zbp)
    {
        $this->zbp = $zbp;
        $this->config = $zbp->Config('mlogin');
    }

    public function dispatch()
    {
        // 权限检查
        if (!$this->zbp->CheckRights('root')) {
            $this->zbp->ShowError(6);
            die();
        }
        if (!$this->zbp->CheckPlugin('mlogin')) {
            $this->zbp->ShowError(48);
            die();
        }

        // AJAX 请求路由
        if ($this->isAjaxRequest()) {
            $this->handleAjax();
            return;
        }

        // POST 保存配置
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {
            $this->handleSave();
            return;
        }

        // 页面渲染
        $this->renderPage();
    }

    private function isAjaxRequest()
    {
        return isset($_GET['act']) && in_array($_GET['act'], [
            'delete_image', 'upload_image', 'export_json'
        ]) || isset($_POST['act']) && in_array($_POST['act'], [
            'filter_logs', 'test_rule'
        ]);
    }

    private function handleAjax()
    {
        $act = $_GET['act'] ?? $_POST['act'] ?? '';

        switch ($act) {
            case 'delete_image':
                $this->ajaxDeleteImage();
                break;
            case 'upload_image':
                $this->ajaxUploadImage();
                break;
            case 'export_json':
                $this->ajaxExportJson();
                break;
            case 'filter_logs':
                $this->ajaxFilterLogs();
                break;
            case 'test_rule':
                $this->ajaxTestRule();
                break;
            default:
                $this->jsonResponse(false, '未知操作');
        }
    }

    // ─── AJAX 处理器 ───────────────────────────────────────────

    private function ajaxDeleteImage()
    {
        $this->verifyCsrf('POST');

        $fileUrl = trim($_POST['file_url'] ?? '');
        [$uploadDir, $webPrefix] = $this->getUploadPaths();

        // 安全检查：URL 前缀
        if (strpos($fileUrl, $webPrefix) !== 0) {
            $this->jsonResponse(false, '非法文件地址');
        }

        $fileName = substr($fileUrl, strlen($webPrefix));

        // 安全检查：路径遍历
        if ($this->containsPathTraversal($fileName)) {
            $this->jsonResponse(false, '非法文件名');
        }

        $realDir      = realpath(__DIR__ . '/' . MLOGIN_UPLOAD_DIR_NAME);
        $realFilePath = realpath(__DIR__ . '/' . MLOGIN_UPLOAD_DIR_NAME . '/' . $fileName);

        if ($realFilePath === false || strpos($realFilePath, $realDir) !== 0) {
            $this->jsonResponse(false, '文件路径异常');
        }
        if (!is_file($realFilePath)) {
            $this->jsonResponse(false, '文件不存在');
        }

        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (!in_array($ext, MLOGIN_ALLOWED_IMG_EXT)) {
            $this->jsonResponse(false, '非法文件类型');
        }

        $success = @unlink($realFilePath);
        $this->jsonResponse($success, $success ? '删除成功' : '删除失败');
    }

    private function ajaxUploadImage()
    {
        $this->verifyCsrf('POST');

        if (!isset($_FILES['image_file']) || $_FILES['image_file']['error'] !== UPLOAD_ERR_OK) {
            $this->jsonResponse(false, '上传错误');
        }
        if ($_FILES['image_file']['size'] > MLOGIN_MAX_IMG_SIZE) {
            $this->jsonResponse(false, '图片超过 5MB');
        }

        [$uploadDir] = $this->getUploadPaths();
        if (!file_exists($uploadDir)) {
            @mkdir($uploadDir, 0755, true);
        }

        $ext = strtolower(pathinfo($_FILES['image_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, MLOGIN_ALLOWED_IMG_EXT)) {
            $this->jsonResponse(false, '不支持的格式');
        }

        // MIME 类型二次验证
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($_FILES['image_file']['tmp_name']);
        if (!in_array($mimeType, MLOGIN_ALLOWED_IMG_MIME)) {
            $this->jsonResponse(false, '非有效图片');
        }

        $newFile = 'mlogin_' . date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $target  = $uploadDir . $newFile;

        if (move_uploaded_file($_FILES['image_file']['tmp_name'], $target)) {
            [, $webPrefix] = $this->getUploadPaths();
            $this->jsonResponse(true, '上传成功', ['url' => $webPrefix . $newFile]);
        }

        $this->jsonResponse(false, '移动文件失败');
    }

    private function ajaxExportJson()
    {
        $this->verifyCsrf('GET');

        $exportFields = [
            'plugin_enabled', 'whitelist_enabled', 'blacklist_enabled',
            'whitelist_levels', 'blacklist_levels', 'whitelist', 'blacklist',
            'ip_whitelist', 'ip_blacklist', 'custom_403_html',
            'allowed_time_ranges', 'time_guest_mode',
            'login_bg', 'login_title', 'login_tip', 'auto_jump_seconds',
            'trust_proxy', 'extra_system_pass', 'timezone_offset',
            'login_fail_max', 'login_fail_lock_minutes',
            'log_blocked', 'log_allowed', 'log_redirect',
            'category_access_enabled', 'require_login_categories', 'require_login_tags',
        ];

        $data = [];
        foreach ($exportFields as $field) {
            $data[$field] = $this->config->$field ?? '';
        }

        // 整数字段强制转换
        $intFields = [
            'plugin_enabled', 'whitelist_enabled', 'blacklist_enabled',
            'time_guest_mode', 'auto_jump_seconds', 'trust_proxy',
            'timezone_offset', 'login_fail_max', 'login_fail_lock_minutes',
            'log_blocked', 'log_allowed', 'log_redirect',
            'category_access_enabled',
        ];
        foreach ($intFields as $field) {
            $data[$field] = (int)($data[$field] ?? 0);
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="mlogin_backup.json"');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }

    private function ajaxFilterLogs()
    {
        $this->verifyCsrf('POST');

        $filterAction = trim($_POST['filter_action'] ?? '');
        $filterIp     = trim($_POST['filter_ip'] ?? '');
        $filterDate   = trim($_POST['filter_date'] ?? '');
        $maxLines     = $this->clamp((int)($_POST['max_lines'] ?? 500), 50, 2000);

        $logLines = [];

        if (file_exists(MLOGIN_LOG_FILE)) {
            $allLines = file(MLOGIN_LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            foreach ($allLines as $line) {
                if (!$this->logLineMatchesFilter($line, $filterAction, $filterIp, $filterDate)) {
                    continue;
                }
                $logLines[] = $line;
            }
        }

        $logLines = array_reverse(array_slice($logLines, -$maxLines));

        $this->jsonResponse(true, '筛选完成', [
            'lines' => $logLines,
            'total' => count($logLines),
        ]);
    }

    private function ajaxTestRule()
    {
        $this->verifyCsrf('POST');

        $testUri   = trim($_POST['test_uri'] ?? '');
        $testIp    = trim($_POST['test_ip'] ?? '127.0.0.1');
        $testLevel = (int)($_POST['test_level'] ?? 0);

        if ($testUri === '') {
            $this->jsonResponse(false, '请输入测试 URI');
        }

        $tester = new MloginRuleTester($this->config);
        $result = $tester->test($testUri, $testIp, $testLevel);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit();
    }

    // ─── 配置保存 ─────────────────────────────────────────────

    private function handleSave()
    {
        $this->verifyCsrf('POST');

        $currentTab = in_array($_POST['current_tab'] ?? '', $this->allowedTabs)
            ? $_POST['current_tab']
            : 'basic';

        $saver = new MloginConfigSaver($this->config, $this->zbp);

        switch ($currentTab) {
            case 'basic':
                $saver->saveBasic($_POST);
                break;
            case 'login':
                $saver->saveLogin($_POST);
                break;
            case 'advanced':
                $saver->saveAdvanced($_POST);
                break;
            case 'backup':
                if (($_POST['backup_action'] ?? '') === 'import') {
                    $saver->saveImport($_POST, $_FILES);
                    return; // import 有自己的 redirect
                }
                break;
        }

        // 特殊操作
        if ($currentTab === 'basic' && ($_POST['basic_action'] ?? '') === 'reset_403') {
            $this->config->custom_403_html = MLOGIN_DEFAULT_403_HTML;
            $this->zbp->SaveConfig('mlogin');
            $this->redirect('reset_403_success', 'basic');
        }

        if ($currentTab === 'logs' && ($_POST['logs_action'] ?? '') === 'clear') {
            if (file_exists(MLOGIN_LOG_FILE)) {
                @file_put_contents(MLOGIN_LOG_FILE, '');
            }
            $this->redirect('logs_cleared', 'logs');
        }

        $this->zbp->SaveConfig('mlogin');
        $this->redirect('save_success', $currentTab);
    }

    // ─── 页面渲染 ─────────────────────────────────────────────

    private function renderPage()
    {
        $viewData = $this->prepareViewData();
        extract($viewData);

        // 将 Z-Blog 全局变量赋值给局部变量，供后台模板（admin_header/top/footer）使用
        global $zbp;
        $zbp = $this->zbp;
        $lang = $this->zbp->lang;
        $action = 'mlogin';
        $blogname = $this->zbp->option['ZC_BLOG_NAME'];
        $bloghost = $this->zbp->host;
        $blogversion = ZC_VERSION_DISPLAY;
        // $blogtitle 已通过 extract($viewData) 从 prepareViewData() 中获取

        // 加载 Z-Blog 后台头部
        require $this->zbp->path . 'zb_system/admin/admin_header.php';
        require $this->zbp->path . 'zb_system/admin/admin_top.php';

        // 加载 CSS
        echo '<link rel="stylesheet" href="assets/style.css">';

        // 加载视图模板
        require __DIR__ . '/views/admin_page.php';

        // 加载 JS
        echo '<script src="assets/app.js"></script>';

        // Z-Blog 后台底部
        require $this->zbp->path . 'zb_system/admin/admin_footer.php';
        RunTime();
    }

    private function prepareViewData()
    {
        $cfg = $this->config;

        // 上传图片列表
        [$uploadDirAbs, $uploadDirWeb] = $this->getUploadPaths();
        $imageFileList = $this->scanUploadedImages($uploadDirAbs, $uploadDirWeb);

        // 反馈消息
        $successMsg = $this->getFeedbackMessage();

        // 当前 Tab
        $activeTab = in_array($_GET['tab'] ?? '', $this->allowedTabs)
            ? $_GET['tab']
            : 'basic';

        return [
            // 基础规则
            'pluginEnabled'      => (int)($cfg->plugin_enabled ?? 1) === 1,
            'whitelistEnabled'   => (int)($cfg->whitelist_enabled ?? 1) === 1,
            'blacklistEnabled'   => (int)($cfg->blacklist_enabled ?? 1) === 1,
            'whitelistLevelArr'  => array_map('intval', explode(',', $cfg->whitelist_levels ?? '1,2,3,4,5')),
            'blacklistLevelArr'  => array_map('intval', explode(',', $cfg->blacklist_levels ?? '1,2,3,4,5')),
            'whitelist'          => $cfg->whitelist,
            'blacklist'          => $cfg->blacklist,
            'ipWhitelist'        => $cfg->ip_whitelist ?? '',
            'ipBlacklist'        => $cfg->ip_blacklist ?? '',
            'custom403'          => $cfg->custom_403_html ?? '',
            'timeRanges'         => $cfg->allowed_time_ranges ?? '',
            'timeMode'           => $this->clamp((int)($cfg->time_guest_mode ?? 0), 0, 2),

            // 登录外观
            'loginBg'            => $cfg->login_bg,
            'loginTitle'         => $cfg->login_title,
            'loginTip'           => $cfg->login_tip,
            'autoJumpSec'        => mlogin_clamp_jump_seconds($cfg->auto_jump_seconds ?? 0),
            'autoJumpOn'         => mlogin_clamp_jump_seconds($cfg->auto_jump_seconds ?? 0) > 0,
            'autoJumpDisp'       => mlogin_clamp_jump_seconds($cfg->auto_jump_seconds ?? 0) ?: 5,
            'imageFileList'      => $imageFileList,

            // 高级设置
            'trustProxy'         => (int)($cfg->trust_proxy ?? 0) === 1,
            'extraSystemPass'    => $cfg->extra_system_pass ?? '',
            'timezoneOffset'     => (int)($cfg->timezone_offset ?? 8),
            'loginFailMax'       => (int)($cfg->login_fail_max ?? 0),
            'loginFailLock'      => (int)($cfg->login_fail_lock_minutes ?? 15),
            'logBlocked'         => (int)($cfg->log_blocked ?? 1) === 1,
            'logAllowed'         => (int)($cfg->log_allowed ?? 0) === 1,
            'logRedirect'        => (int)($cfg->log_redirect ?? 0) === 1,
            'catAccessEnabled'   => (int)($cfg->category_access_enabled ?? 0) === 1,
            'reqLoginCats'       => $cfg->require_login_categories ?? '',
            'reqLoginTags'       => $cfg->require_login_tags ?? '',

            // 通用
            'successMsg'         => $successMsg,
            'activeTab'          => $activeTab,
            'csrfToken'          => $this->zbp->GetCSRFToken(),
            'blogtitle'          => 'mlogin 插件配置',

            // 导出用 JSON
            'exportJson'         => json_encode($this->buildExportData(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'csrfTokenJson'      => json_encode($this->zbp->GetCSRFToken()),
        ];
    }

    private function buildExportData()
    {
        $cfg = $this->config;
        return [
            'plugin_enabled'            => (int)($cfg->plugin_enabled ?? 1),
            'whitelist_enabled'         => (int)($cfg->whitelist_enabled ?? 1),
            'blacklist_enabled'         => (int)($cfg->blacklist_enabled ?? 1),
            'whitelist_levels'          => $cfg->whitelist_levels ?? '',
            'blacklist_levels'          => $cfg->blacklist_levels ?? '',
            'whitelist'                 => $cfg->whitelist,
            'blacklist'                 => $cfg->blacklist,
            'ip_whitelist'              => $cfg->ip_whitelist ?? '',
            'ip_blacklist'              => $cfg->ip_blacklist ?? '',
            'custom_403_html'           => $cfg->custom_403_html ?? '',
            'allowed_time_ranges'       => $cfg->allowed_time_ranges ?? '',
            'time_guest_mode'           => (int)($cfg->time_guest_mode ?? 0),
            'login_bg'                  => $cfg->login_bg,
            'login_title'               => $cfg->login_title,
            'login_tip'                 => $cfg->login_tip,
            'auto_jump_seconds'         => mlogin_clamp_jump_seconds($cfg->auto_jump_seconds ?? 0),
            'trust_proxy'               => (int)($cfg->trust_proxy ?? 0),
            'extra_system_pass'         => $cfg->extra_system_pass ?? '',
            'timezone_offset'           => (int)($cfg->timezone_offset ?? 8),
            'login_fail_max'            => (int)($cfg->login_fail_max ?? 0),
            'login_fail_lock_minutes'   => (int)($cfg->login_fail_lock_minutes ?? 15),
            'log_blocked'               => (int)($cfg->log_blocked ?? 1),
            'log_allowed'               => (int)($cfg->log_allowed ?? 0),
            'log_redirect'              => (int)($cfg->log_redirect ?? 0),
            'category_access_enabled'   => (int)($cfg->category_access_enabled ?? 0),
            'require_login_categories'  => $cfg->require_login_categories ?? '',
            'require_login_tags'        => $cfg->require_login_tags ?? '',
        ];
    }

    // ─── 工具方法 ──────────────────────────────────────────────

    private function getUploadPaths()
    {
        return [
            __DIR__ . '/' . MLOGIN_UPLOAD_DIR_NAME . '/',
            $this->zbp->host . 'zb_users/plugin/mlogin/' . MLOGIN_UPLOAD_DIR_NAME . '/',
        ];
    }

    private function scanUploadedImages($absDir, $webPrefix)
    {
        $list = [];
        if (!is_dir($absDir)) return $list;

        foreach (scandir($absDir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if (is_file($absDir . $f) && in_array($ext, MLOGIN_ALLOWED_IMG_EXT)) {
                $list[] = $webPrefix . $f;
            }
        }
        return $list;
    }

    private function getFeedbackMessage()
    {
        $msgMap = [
            'reset_403_success' => ['✅ 403 页面已恢复为默认模板！', 'success'],
            'save_success'      => ['✅ 配置已成功保存！', 'success'],
            'logs_cleared'      => ['✅ 日志已清空！', 'success'],
            'json_error'        => ['❌ JSON 格式错误，请检查内容！', 'error'],
            'file_too_large'    => ['❌ 导入文件过大，请检查文件大小！', 'error'],
        ];

        $act = $_GET['act'] ?? '';

        if (isset($msgMap[$act])) {
            [$text, $type] = $msgMap[$act];
            $cls = $type === 'error' ? 'ml-alert-error' : 'ml-alert-success';
            return '<div class="ml-alert ' . $cls . '"><span>' . $text . '</span></div>';
        }

        if ($act === 'time_invalid') {
            return '<div class="ml-alert ml-alert-error"><span>❌ 时间段格式无效，已取消保存（格式 HH:MM-HH:MM，如 09:00-18:00；支持跨午夜 22:00-06:00）</span></div>';
        }

        return '';
    }

    private function containsPathTraversal($fileName)
    {
        return strpos($fileName, '/') !== false
            || strpos($fileName, '\\') !== false
            || strpos($fileName, '..') !== false;
    }

    private function logLineMatchesFilter($line, $action, $ip, $date)
    {
        if ($action !== '' && $action !== 'ALL' && strpos($line, $action) === false) {
            return false;
        }
        if ($ip !== '' && stripos($line, 'IP: ' . $ip) === false) {
            return false;
        }
        if ($date !== '' && strpos($line, '[' . $date) === false) {
            return false;
        }
        return true;
    }

    private function verifyCsrf($method = 'POST')
    {
        $token = $method === 'GET' ? ($_GET['csrf_token'] ?? '') : ($_POST['csrf_token'] ?? '');
        if ($token !== $this->zbp->GetCSRFToken()) {
            $this->jsonResponse(false, 'CSRF 令牌校验失败');
        }
    }

    private function jsonResponse($success, $msg = '', array $extra = [])
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge(['success' => $success, 'msg' => $msg], $extra));
        exit();
    }

    private function redirect($act, $tab)
    {
        $url = $this->zbp->host . 'zb_users/plugin/mlogin/main.php?act=' . $act . '&tab=' . $tab;
        header('Location: ' . $url);
        exit();
    }

    private function clamp($value, $min, $max)
    {
        return max($min, min($max, $value));
    }
}

/**
 * 规则测试器
 * 模拟登录检查流程，返回逐步检查结果
 */
class MloginRuleTester
{
    private $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function test($uri, $ip, $level)
    {
        $steps = [];
        $levelLabel = $level === 0 ? '未登录用户' : '级别 ' . $level;

        // Step 0: 总开关
        if ((int)($this->config->plugin_enabled ?? 1) === 0) {
            $steps[] = ['✅ 插件已关闭', '放行（插件未启用）'];
            return $this->buildResult($steps, '✅ 放行', 'ml-result-allow', '插件已关闭', $uri, $ip, $levelLabel);
        }
        $steps[] = ['✅ 插件已启用', '继续检查（模拟: ' . $levelLabel . '）'];

        // Step 1: 系统路径
        if ($this->checkSystemPass($uri, $steps, $uri, $ip, $levelLabel)) {
            return $this->lastResult;
        }

        // Step 2: 时间段
        if ($this->checkTimeRange($steps, $uri, $ip, $levelLabel)) {
            return $this->lastResult;
        }

        // Step 3: IP 黑名单
        $ipBL = mlogin_parse_lines($this->config->ip_blacklist ?? '');
        if (!empty($ipBL) && mlogin_ip_matches($ip, $ipBL)) {
            $steps[] = ['⛔ IP 黑名单命中', '拦截'];
            return $this->buildResult($steps, '🚫 拦截 (403)', 'ml-result-block', 'IP 黑名单', $uri, $ip, $levelLabel);
        }
        $steps[] = ['✅ IP 黑名单通过', '不在黑名单中'];

        // Step 4: IP 白名单
        $ipWL = mlogin_parse_lines($this->config->ip_whitelist ?? '');
        if (!empty($ipWL) && mlogin_ip_matches($ip, $ipWL)) {
            $steps[] = ['✅ IP 白名单命中', '放行'];
            return $this->buildResult($steps, '✅ 放行', 'ml-result-allow', 'IP 白名单', $uri, $ip, $levelLabel);
        }
        $steps[] = ['ℹ️ IP 白名单未命中', empty($ipWL) ? '未配置' : '不在白名单中'];

        // Step 5: URI 黑名单
        if ($this->checkUriBlacklist($uri, $level, $steps, $ip, $levelLabel)) {
            return $this->lastResult;
        }

        // Step 6: URI 白名单
        if ($this->checkUriWhitelist($uri, $level, $steps, $ip, $levelLabel)) {
            return $this->lastResult;
        }

        // 最终判定
        if ($level === 0) {
            $steps[] = ['⚠️ 需要登录', '未匹配任何放行/拦截规则'];
            return $this->buildResult($steps, '🔐 需要登录', 'ml-result-login', '无（需要登录）', $uri, $ip, $levelLabel);
        }

        $steps[] = ['✅ 已登录用户放行', $levelLabel . ' 已通过所有检查'];
        return $this->buildResult($steps, '✅ 放行', 'ml-result-allow', '已登录用户通过', $uri, $ip, $levelLabel);
    }

    private $lastResult;

    private function checkSystemPass($uri, &$steps, $testUri, $ip, $levelLabel)
    {
        $systemPass = MLOGIN_SYSTEM_PASS;
        $extraPass = mlogin_parse_lines($this->config->extra_system_pass ?? '');
        if (!empty($extraPass)) {
            $systemPass = array_merge($systemPass, $extraPass);
        }

        if (mlogin_uri_matches($uri, $systemPass)) {
            $steps[] = ['✅ 系统路径命中', '放行'];
            $this->lastResult = $this->buildResult($steps, '✅ 放行', 'ml-result-allow', '系统路径', $testUri, $ip, $levelLabel);
            return true;
        }

        $steps[] = ['ℹ️ 非系统路径', '继续检查'];
        return false;
    }

    private function checkTimeRange(&$steps, $testUri, $ip, $levelLabel)
    {
        $timeRanges = $this->config->allowed_time_ranges ?? '';
        $timeMode   = (int)($this->config->time_guest_mode ?? 0);
        $tzOffset   = (int)($this->config->timezone_offset ?? 8);

        if (!in_array($timeMode, [0, 1, 2], true)) $timeMode = 0;

        if ($timeMode === 0) {
            $steps[] = ['ℹ️ 时间段控制已关闭', '继续后续规则'];
            return false;
        }

        if (trim($timeRanges) === '') {
            $steps[] = ['✅ 未配置时间段', '继续检查'];
            return false;
        }

        if (!mlogin_in_time_range($timeRanges, $tzOffset)) {
            if ($timeMode === 1) {
                $steps[] = ['ℹ️ 不在允许时间段内（模式1）', '继续后续检查'];
            } else {
                $steps[] = ['⛔ 不在允许时间段内', '拦截（' . mlogin_now_hhmm($tzOffset) . ' 不在配置时段）'];
                $this->lastResult = $this->buildResult($steps, '🚫 拦截 (403)', 'ml-result-block', '时间段控制', $testUri, $ip, $levelLabel);
                return true;
            }
        } else {
            $steps[] = ['✅ 时间段检查通过', '当前 ' . mlogin_now_hhmm($tzOffset) . ' 在允许范围内'];
            $steps[] = ['✅ 免登录放行', '直接访问所有页面'];
            $this->lastResult = $this->buildResult($steps, '✅ 放行', 'ml-result-allow', '时间段内免登录', $testUri, $ip, $levelLabel);
            return true;
        }

        return false;
    }

    private function checkUriBlacklist($uri, $level, &$steps, $ip, $levelLabel)
    {
        if ((int)($this->config->blacklist_enabled ?? 1) !== 1) {
            $steps[] = ['ℹ️ URI 黑名单已禁用', '跳过'];
            return false;
        }

        $bl = mlogin_parse_lines($this->config->blacklist ?? '');
        if (empty($bl) || !mlogin_uri_matches($uri, $bl)) {
            $steps[] = ['✅ URI 黑名单通过', '不在黑名单中'];
            return false;
        }

        $blLevels = $this->config->blacklist_levels ?? '';
        $levelsInfo = $blLevels !== '' ? '（生效级别: ' . $blLevels . '）' : '（所有级别生效）';

        if ($level === 0 || mlogin_level_enabled($level, $blLevels)) {
            $steps[] = ['⛔ URI 黑名单命中' . $levelsInfo, '拦截（' . $levelLabel . '适用）'];
            $this->lastResult = $this->buildResult($steps, '🚫 拦截 (403)', 'ml-result-block', 'URI 黑名单', $uri, $ip, $levelLabel);
            return true;
        }

        $steps[] = ['ℹ️ URI 黑名单命中但不适用', $levelLabel . '不在生效级别中，跳过'];
        $steps[] = ['✅ URI 黑名单通过', '不在黑名单中'];
        return false;
    }

    private function checkUriWhitelist($uri, $level, &$steps, $ip, $levelLabel)
    {
        if ((int)($this->config->whitelist_enabled ?? 1) !== 1) {
            $steps[] = ['ℹ️ URI 白名单已禁用', '跳过'];
            return false;
        }

        $wl = mlogin_parse_lines($this->config->whitelist ?? '');
        if (empty($wl) || !mlogin_uri_matches($uri, $wl)) {
            $steps[] = ['ℹ️ URI 白名单未命中', empty($wl) ? '未配置' : '不在白名单中'];
            return false;
        }

        $wlLevels = $this->config->whitelist_levels ?? '';
        $levelsInfo = $wlLevels !== '' ? '（生效级别: ' . $wlLevels . '）' : '（所有级别生效）';

        if ($level === 0 || mlogin_level_enabled($level, $wlLevels)) {
            $steps[] = ['✅ URI 白名单命中' . $levelsInfo, '放行（' . $levelLabel . '适用）'];
            $this->lastResult = $this->buildResult($steps, '✅ 放行', 'ml-result-allow', 'URI 白名单', $uri, $ip, $levelLabel);
            return true;
        }

        $steps[] = ['ℹ️ URI 白名单命中但不适用', $levelLabel . '不在生效级别中，跳过'];
        $steps[] = ['ℹ️ URI 白名单未命中', empty($wl) ? '未配置' : '不在白名单中'];
        return false;
    }

    private function buildResult($steps, $action, $class, $rule, $uri, $ip, $level)
    {
        return [
            'success'       => true,
            'uri'           => $uri,
            'ip'            => $ip,
            'level'         => $level,
            'steps'         => $steps,
            'final_action'  => $action,
            'final_class'   => $class,
            'matched_rule'  => $rule,
        ];
    }
}

/**
 * 配置保存器
 * 负责各 Tab 的配置保存逻辑
 */
class MloginConfigSaver
{
    private $config;
    private $zbp;

    public function __construct($config, $zbp)
    {
        $this->config = $config;
        $this->zbp = $zbp;
    }

    public function saveBasic(array $post)
    {
        $cfg = $this->config;

        $cfg->plugin_enabled    = $this->boolToInt($post, 'plugin_enabled');
        $cfg->whitelist_enabled = $this->boolToInt($post, 'whitelist_enabled');
        $cfg->blacklist_enabled = $this->boolToInt($post, 'blacklist_enabled');

        $cfg->whitelist_levels = $this->collectLevels($post, 'whitelist_level_');
        $cfg->blacklist_levels = $this->collectLevels($post, 'blacklist_level_');

        $cfg->whitelist    = mlogin_clean_lines($post['whitelist'] ?? '');
        $cfg->blacklist    = mlogin_clean_lines($post['blacklist'] ?? '');
        $cfg->ip_whitelist = mlogin_clean_lines($post['ip_whitelist'] ?? '');
        $cfg->ip_blacklist = mlogin_clean_lines($post['ip_blacklist'] ?? '');
        $cfg->custom_403_html = $post['custom_403_html'] ?? '';

        // 时间段校验
        $rawTime = $post['allowed_time_ranges'] ?? '';
        $validLines = [];
        $invalidLines = [];

        foreach (mlogin_parse_lines($rawTime) as $line) {
            $norm = mlogin_normalize_time_range($line);
            if ($norm === '') {
                $invalidLines[] = $line;
            } else {
                $validLines[] = $norm;
            }
        }

        if (!empty($invalidLines)) {
            $bad = implode(' | ', array_slice($invalidLines, 0, 3));
            header('Location: ' . $this->zbp->host . 'zb_users/plugin/mlogin/main.php?act=time_invalid&tab=basic&bad=' . urlencode($bad));
            exit();
        }

        $cfg->allowed_time_ranges = implode("\n", $validLines);
        $cfg->time_guest_mode = in_array((int)($post['time_guest_mode'] ?? 0), [0, 1, 2], true)
            ? (int)$post['time_guest_mode']
            : 0;
    }

    public function saveLogin(array $post)
    {
        $cfg = $this->config;

        $rawBg = trim($post['login_bg'] ?? '');
        if ($rawBg !== '' && !preg_match('/^https?:\/\//i', $rawBg) && strpos($rawBg, '/') !== 0) {
            $rawBg = '';
        }

        $cfg->login_bg    = $rawBg;
        $cfg->login_title = trim($post['login_title'] ?? '');
        $cfg->login_tip   = trim($post['login_tip'] ?? '');

        $jumpEnable  = isset($post['auto_jump_enable']) && $post['auto_jump_enable'] === '1';
        $jumpSeconds = (int)($post['auto_jump_seconds'] ?? 0);
        if ($jumpSeconds < 1) $jumpSeconds = 5;

        $cfg->auto_jump_seconds = $jumpEnable ? mlogin_clamp_jump_seconds($jumpSeconds) : 0;
    }

    public function saveAdvanced(array $post)
    {
        $cfg = $this->config;

        $cfg->trust_proxy = $this->boolToInt($post, 'trust_proxy');
        $cfg->extra_system_pass = mlogin_clean_lines($post['extra_system_pass'] ?? '');

        $tzOffset = (int)($post['timezone_offset'] ?? 8);
        $cfg->timezone_offset = ($tzOffset >= -12 && $tzOffset <= 14) ? $tzOffset : 8;

        $cfg->login_fail_max = $this->clamp((int)($post['login_fail_max'] ?? 0), 0, 100);
        $cfg->login_fail_lock_minutes = $this->clamp((int)($post['login_fail_lock_minutes'] ?? 15), 1, 1440);

        $cfg->log_blocked  = $this->boolToInt($post, 'log_blocked');
        $cfg->log_allowed  = $this->boolToInt($post, 'log_allowed');
        $cfg->log_redirect = $this->boolToInt($post, 'log_redirect');
        $cfg->category_access_enabled = $this->boolToInt($post, 'category_access_enabled');
        $cfg->require_login_categories = mlogin_clean_lines($post['require_login_categories'] ?? '');
        $cfg->require_login_tags = mlogin_clean_lines($post['require_login_tags'] ?? '');
    }

    public function saveImport(array $post, array $files)
    {
        $importData = '';

        // 从文件导入
        if (isset($files['import_file']) && $files['import_file']['error'] === UPLOAD_ERR_OK) {
            if ($files['import_file']['size'] > MLOGIN_MAX_JSON_SIZE) {
                $this->redirect('file_too_large', 'backup');
            }
            $importData = file_get_contents($files['import_file']['tmp_name']);
        } elseif (!empty($post['import_text'])) {
            $importData = trim($post['import_text']);
        }

        if (empty($importData)) return;

        if (strlen($importData) > MLOGIN_MAX_JSON_SIZE) {
            $this->redirect('file_too_large', 'backup');
        }

        $data = json_decode($importData, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            $this->redirect('json_error', 'backup');
        }

        $this->applyImportData($data);
        $this->zbp->SaveConfig('mlogin');
        $this->redirect('save_success', 'backup');
    }

    private function applyImportData(array $data)
    {
        $cfg = $this->config;

        // 布尔字段
        foreach (['plugin_enabled', 'whitelist_enabled', 'blacklist_enabled'] as $k) {
            if (isset($data[$k])) $cfg->$k = ((int)$data[$k] === 1) ? 1 : 0;
        }

        // 字符串字段
        $stringFields = [
            'whitelist_levels', 'blacklist_levels', 'whitelist', 'blacklist',
            'login_bg', 'login_title', 'login_tip', 'ip_whitelist', 'ip_blacklist',
            'custom_403_html', 'extra_system_pass', 'require_login_categories',
            'require_login_tags',
        ];
        foreach ($stringFields as $k) {
            if (isset($data[$k]) && is_string($data[$k])) $cfg->$k = $data[$k];
        }

        // 整数字段
        $intFields = [
            'trust_proxy', 'timezone_offset', 'login_fail_max',
            'login_fail_lock_minutes',
            'log_blocked', 'log_allowed', 'log_redirect',
            'category_access_enabled',
        ];
        foreach ($intFields as $k) {
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

        if (isset($data['auto_jump_seconds'])) {
            $cfg->auto_jump_seconds = mlogin_clamp_jump_seconds($data['auto_jump_seconds']);
        }

        if (isset($data['time_guest_mode'])) {
            $cfg->time_guest_mode = in_array((int)$data['time_guest_mode'], [0, 1, 2], true)
                ? (int)$data['time_guest_mode']
                : 0;
        }
    }

    private function boolToInt(array $post, $key)
    {
        return (isset($post[$key]) && $post[$key] === '1') ? 1 : 0;
    }

    private function collectLevels(array $post, $prefix)
    {
        $levels = [];
        for ($i = 1; $i <= 5; $i++) {
            if (isset($post[$prefix . $i]) && $post[$prefix . $i] === '1') {
                $levels[] = $i;
            }
        }
        return implode(',', $levels);
    }

    private function clamp($value, $min, $max)
    {
        return max($min, min($max, $value));
    }

    private function redirect($act, $tab)
    {
        header('Location: ' . $this->zbp->host . 'zb_users/plugin/mlogin/main.php?act=' . $act . '&tab=' . $tab);
        exit();
    }
}

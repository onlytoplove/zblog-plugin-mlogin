<?php
/**
 * 访问日志面板
 */
$logStats = ['total' => 0, 'blocked' => 0, 'allowed' => 0, 'redirect' => 0];
$logLines = [];

if (file_exists(MLOGIN_LOG_FILE)) {
    $allLines = file(MLOGIN_LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $logStats['total'] = count($allLines);
    foreach ($allLines as $line) {
        if (strpos($line, 'BLOCKED') !== false)  $logStats['blocked']++;
        elseif (strpos($line, 'ALLOWED') !== false) $logStats['allowed']++;
        elseif (strpos($line, 'REDIRECT') !== false) $logStats['redirect']++;
    }
    $logLines = array_reverse(array_slice($allLines, -500));
}
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
    <div class="ml-section-head" style="font-size:17px;margin-bottom:0;">📋 访问日志</div>
    <form method="post" action="" style="margin:0;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="current_tab" value="logs">
        <input type="hidden" name="logs_action" value="clear">
        <button type="submit" class="ml-btn ml-btn-danger"
                onclick="return confirm('确定清空所有日志？')">🗑️ 清空日志</button>
    </form>
</div>

<!-- 统计卡片 -->
<div class="ml-log-stats">
    <div class="ml-stat-card">
        <div class="ml-stat-num"><?= $logStats['total'] ?></div>
        <div class="ml-stat-label">总记录</div>
    </div>
    <div class="ml-stat-card">
        <div class="ml-stat-num" style="color:#dc2626;"><?= $logStats['blocked'] ?></div>
        <div class="ml-stat-label">已拦截</div>
    </div>
    <div class="ml-stat-card">
        <div class="ml-stat-num" style="color:#059669;"><?= $logStats['allowed'] ?></div>
        <div class="ml-stat-label">已放行</div>
    </div>
    <div class="ml-stat-card">
        <div class="ml-stat-num" style="color:#d97706;"><?= $logStats['redirect'] ?></div>
        <div class="ml-stat-label">跳转登录</div>
    </div>
</div>

<!-- 筛选器 -->
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
        <input type="text" id="log_filter_ip" class="ml-input"
               style="padding:6px 10px;font-size:13px;" placeholder="输入 IP 筛选">
    </div>
    <div class="ml-form-group">
        <label class="ml-label" style="font-size:12px;margin-bottom:4px;">日期</label>
        <input type="date" id="log_filter_date" class="ml-input" style="padding:6px 10px;font-size:13px;">
    </div>
    <div class="ml-form-group" style="flex:0 0 auto;">
        <label class="ml-label" style="font-size:12px;margin-bottom:4px;">&nbsp;</label>
        <button type="button" id="btn_filter_logs" class="ml-btn ml-btn-secondary"
                style="padding:6px 16px;font-size:13px;">🔍 筛选</button>
        <button type="button" id="btn_reset_filter" class="ml-btn ml-btn-secondary"
                style="padding:6px 16px;font-size:13px;">↩️ 重置</button>
    </div>
</div>

<?php if (empty($logLines)): ?>
    <div style="text-align:center;padding:40px;color:#9ca3af;font-size:14px;">📭 暂无日志</div>
<?php else: ?>
    <div class="ml-log-box" id="log_content_box">
        <?= htmlspecialchars(implode("\n", $logLines)) ?>
    </div>
    <span class="ml-hint" style="margin-top:10px;" id="log_count_hint">
        显示最近 <?= count($logLines) ?> 条。日志超 10MB 自动归档，超 30 天自动清理。
    </span>
<?php endif; ?>

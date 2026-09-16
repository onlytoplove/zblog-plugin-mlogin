<?php
/**
 * mlogin 插件 - 后台管理页面模板
 *
 * 变量由 MloginRouter::renderPage() 通过 extract() 注入
 * 可用变量参见 prepareViewData() 返回值
 */
?>
<div class="ml-wrapper">

    <?php echo $successMsg; ?>

    <!-- Tab 导航 -->
    <div class="ml-tabs">
        <?php
        $tabs = [
            'basic'    => '⚙️ 基础规则',
            'login'    => '🎨 登录外观',
            'test'     => '🧪 规则测试',
            'logs'     => '📋 访问日志',
            'advanced' => '🔧 高级设置 <span class="ml-badge ml-badge-new">NEW</span>',
            'backup'   => '💾 数据备份',
        ];
        foreach ($tabs as $tabId => $tabLabel):
            $activeClass = ($activeTab === $tabId) ? 'active' : '';
        ?>
            <a href="?tab=<?= $tabId ?>" class="ml-tab <?= $activeClass ?>"><?= $tabLabel ?></a>
        <?php endforeach; ?>
    </div>

    <!-- ==================== 基础配置 ==================== -->
    <div class="ml-card" style="display:<?= $activeTab === 'basic' ? 'block' : 'none' ?>;">
        <form method="post" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="current_tab" value="basic">

            <!-- 总开关 -->
            <div class="ml-form-group" style="margin-bottom:24px;">
                <div class="ml-switch-group"
                     style="background:<?= $pluginEnabled ? '#ecfdf5' : '#fef2f2' ?>;border-color:<?= $pluginEnabled ? '#a7f3d0' : '#fecaca' ?>;">
                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-weight:600;color:#111827;font-size:15px;">
                        <input type="checkbox" name="plugin_enabled" id="plugin_enabled"
                               class="ml-checkbox" value="1" <?= $pluginEnabled ? 'checked' : '' ?> style="width:18px;height:18px;">
                        🔌 启用插件
                    </label>
                    <span style="color:#6b7280;font-size:13px;margin-left:auto;" id="plugin_status">
                        <?= $pluginEnabled ? '已启用 — 登录检查正常运行' : '已禁用 — 所有登录检查已跳过' ?>
                    </span>
                </div>
                <span class="ml-hint" style="margin-top:6px;">关闭后，插件不再拦截任何页面访问。系统后台路径始终放行。</span>
            </div>

            <!-- URI 规则 -->
            <div class="ml-sub-title">URI 规则</div>
            <div class="ml-split-layout">
                <?php include __DIR__ . '/partials/list_rules.php'; ?>
            </div>

            <hr class="ml-divider">

            <!-- IP 规则 -->
            <div class="ml-sub-title">IP 规则</div>
            <div class="ml-split-layout">
                <div class="ml-col-left">
                    <div class="ml-form-group">
                        <label class="ml-label">🔓 IP 白名单</label>
                        <textarea name="ip_whitelist" class="ml-textarea" rows="5"
                                  placeholder="每行一个，支持：&#10;192.168.1.100&#10;192.168.1.0/24&#10;10.0.0.*&#10;2001:db8::/32"><?= htmlspecialchars($ipWhitelist) ?></textarea>
                        <span class="ml-hint">匹配的 IP 直接放行，无需登录。支持精确、CIDR、通配符。现已支持 IPv6 地址。</span>
                    </div>
                </div>
                <div class="ml-col-left">
                    <div class="ml-form-group">
                        <label class="ml-label">🔒 IP 黑名单</label>
                        <textarea name="ip_blacklist" class="ml-textarea" rows="5"
                                  placeholder="每行一个，支持：&#10;203.0.113.50&#10;198.51.100.0/24&#10;172.16.*.*&#10;::1"><?= htmlspecialchars($ipBlacklist) ?></textarea>
                        <span class="ml-hint">匹配的 IP 直接拦截，返回 403。优先于 IP 白名单。现已支持 IPv6 地址。</span>
                    </div>
                </div>
            </div>

            <hr class="ml-divider">

            <!-- 时间段控制 -->
            <div class="ml-sub-title">时间段控制</div>
            <div class="ml-form-group">
                <label class="ml-label">⏰ 允许访问的时间段</label>
                <textarea name="allowed_time_ranges" class="ml-textarea" rows="3"
                          placeholder="每行一个，格式 HH:MM-HH:MM&#10;例如：&#10;09:00-18:00&#10;22:00-06:00"><?= htmlspecialchars($timeRanges) ?></textarea>
                <span class="ml-hint">
                    配合下方模式使用。当前时区偏移：UTC<?= $timezoneOffset >= 0 ? '+' : '' ?><?= $timezoneOffset ?>
                </span>
                <div class="ml-switch-group" style="margin-top:12px;flex-direction:column;align-items:stretch;gap:8px;">
                    <div style="font-weight:600;color:#111827;font-size:14px;margin-bottom:2px;">时间段模式</div>
                    <?php
                    $modes = [
                        0 => '关闭（默认）：时间段不生效，其他规则照常',
                        1 => '时段内免登录放行；时段外走白名单',
                        2 => '时段内免登录放行；时段外直接 403',
                    ];
                    foreach ($modes as $modeVal => $modeText):
                    ?>
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:#374151;">
                            <input type="radio" name="time_guest_mode" value="<?= $modeVal ?>"
                                   class="ml-checkbox" <?= $timeMode == $modeVal ? 'checked' : '' ?>>
                            <?= $modeText ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <hr class="ml-divider">

            <!-- 自定义 403 页面 -->
            <div class="ml-sub-title">自定义 403 页面</div>
            <div class="ml-form-group">
                <label class="ml-label">🎨 403 页面 HTML</label>
                <textarea name="custom_403_html" class="ml-textarea" style="min-height:160px;"
                          placeholder="留空使用默认模板。支持变量：&#10;{{reason}} {{site_name}} {{site_url}} {{current_time}}"><?= htmlspecialchars($custom403) ?></textarea>
                <span class="ml-hint">
                    支持完整 HTML。变量：<code>{{reason}}</code> <code>{{site_name}}</code> <code>{{site_url}}</code> <code>{{current_time}}</code>
                </span>
                <div style="margin-top:10px;">
                    <button type="submit" name="basic_action" value="reset_403"
                            class="ml-btn ml-btn-secondary"
                            onclick="return confirm('确定恢复默认 403 页面？')">🔄 默认 403 页面</button>
                </div>
            </div>

            <div style="text-align:right;margin-top:10px;">
                <button type="submit" class="ml-btn ml-btn-primary">💾 保存设置</button>
            </div>
        </form>
    </div>

    <!-- ==================== 登录外观 ==================== -->
    <div class="ml-card" style="display:<?= $activeTab === 'login' ? 'block' : 'none' ?>;">
        <?php include __DIR__ . '/partials/login_appearance.php'; ?>
    </div>

    <!-- ==================== 规则测试 ==================== -->
    <div class="ml-card" style="display:<?= $activeTab === 'test' ? 'block' : 'none' ?>;">
        <?php include __DIR__ . '/partials/rule_test.php'; ?>
    </div>

    <!-- ==================== 访问日志 ==================== -->
    <div class="ml-card" style="display:<?= $activeTab === 'logs' ? 'block' : 'none' ?>;">
        <?php include __DIR__ . '/partials/access_logs.php'; ?>
    </div>

    <!-- ==================== 高级设置 ==================== -->
    <div class="ml-card" style="display:<?= $activeTab === 'advanced' ? 'block' : 'none' ?>;">
        <?php include __DIR__ . '/partials/advanced_settings.php'; ?>
    </div>

    <!-- ==================== 数据备份 ==================== -->
    <div class="ml-card" style="display:<?= $activeTab === 'backup' ? 'block' : 'none' ?>;">
        <?php include __DIR__ . '/partials/backup.php'; ?>
    </div>

</div>

<!-- 注入前端配置 -->
<script>
window.mloginConfig = {
    csrfToken:  <?= $csrfTokenJson ?>,
    exportData: <?= $exportJson ?>
};
</script>

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

    <!-- ==================== 高级设置（内联） ==================== -->
    <div class="ml-card" style="display:<?= $activeTab === 'advanced' ? 'block' : 'none' ?>;">
        <form method="post" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="current_tab" value="advanced">

            <!-- 代理信任 -->
            <div class="ml-form-group">
                <label class="ml-label">🌐 反向代理信任</label>
                <div class="ml-switch-group">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px;color:#374151;">
                        <input type="checkbox" name="trust_proxy" value="1"
                               class="ml-checkbox" <?= $trustProxy ? 'checked' : '' ?>>
                        信任 X-Forwarded-For / X-Real-IP 头
                    </label>
                </div>
                <span class="ml-hint">如果站点部署在 Nginx/CDN 反向代理后方，开启此项可获取访客真实 IP。仅在代理服务器可信时开启。</span>
            </div>

            <hr class="ml-divider">

            <!-- 额外放行路径 -->
            <div class="ml-form-group">
                <label class="ml-label">🛤️ 额外放行路径</label>
                <textarea name="extra_system_pass" class="ml-textarea" rows="4"
                          placeholder="每行一个路径，例如：&#10;/api/webhook&#10;/custom/callback"><?= htmlspecialchars($extraSystemPass) ?></textarea>
                <span class="ml-hint">这些路径将始终放行，不受其他规则约束。适用于 API 回调、Webhook 等场景。</span>
            </div>

            <hr class="ml-divider">

            <!-- 时区偏移 -->
            <div class="ml-form-group">
                <label class="ml-label">🕐 时区偏移（UTC）</label>
                <input type="number" name="timezone_offset" class="ml-input" style="width:80px;"
                       value="<?= htmlspecialchars($timezoneOffset) ?>" min="-12" max="14">
                <span class="ml-hint">当前偏移：UTC<?= $timezoneOffset >= 0 ? '+' : '' ?><?= $timezoneOffset ?>。用于时间段控制和日志时间戳。中国标准时间为 8。</span>
            </div>

            <hr class="ml-divider">

            <!-- 登录失败锁定 -->
            <div class="ml-sub-title">登录失败保护</div>
            <div class="ml-split-layout">
                <div class="ml-col-left">
                    <div class="ml-form-group">
                        <label class="ml-label">🔢 最大失败次数</label>
                        <input type="number" name="login_fail_max" class="ml-input" style="width:80px;"
                               value="<?= htmlspecialchars($loginFailMax) ?>" min="0" max="100">
                        <span class="ml-hint">连续失败超过此次数后锁定 IP。设为 0 表示不启用。</span>
                    </div>
                </div>
                <div class="ml-col-left">
                    <div class="ml-form-group">
                        <label class="ml-label">⏳ 锁定时长（分钟）</label>
                        <input type="number" name="login_fail_lock_minutes" class="ml-input" style="width:80px;"
                               value="<?= htmlspecialchars($loginFailLock) ?>" min="1" max="1440">
                        <span class="ml-hint">IP 被锁定的持续时间，范围 1~1440 分钟。</span>
                    </div>
                </div>
            </div>

            <hr class="ml-divider">

            <!-- ★ 日志记录类型（三个勾选框） ★ -->
            <div class="ml-form-group">
                <label class="ml-label">📝 日志写入设置</label>
                <p class="ml-help" style="color:#6b7280;font-size:13px;margin:4px 0 8px;">勾选需要记录的访问类型，未勾选的类型不会写入日志文件。</p>
                <div class="ml-log-checkbox-group">
                    <label class="ml-log-checkbox">
                        <input type="checkbox" name="log_blocked" value="1"
                               <?= $logBlocked ? 'checked' : '' ?>>
                        <span class="ml-log-checkbox-label">
                            <span class="ml-log-badge ml-log-badge-blocked">已拦截</span>
                            <small>BLOCKED</small>
                        </span>
                    </label>
                    <label class="ml-log-checkbox">
                        <input type="checkbox" name="log_allowed" value="1"
                               <?= $logAllowed ? 'checked' : '' ?>>
                        <span class="ml-log-checkbox-label">
                            <span class="ml-log-badge ml-log-badge-allowed">已放行</span>
                            <small>ALLOWED</small>
                        </span>
                    </label>
                    <label class="ml-log-checkbox">
                        <input type="checkbox" name="log_redirect" value="1"
                               <?= $logRedirect ? 'checked' : '' ?>>
                        <span class="ml-log-checkbox-label">
                            <span class="ml-log-badge ml-log-badge-redirect">跳转登录</span>
                            <small>REDIRECT</small>
                        </span>
                    </label>
                </div>
            </div>

            <hr class="ml-divider">

            <!-- 分类/标签访问控制 -->
            <div class="ml-sub-title">分类 / 标签访问控制</div>
            <div class="ml-form-group">
                <div class="ml-switch-group" style="margin-bottom:12px;">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px;color:#374151;">
                        <input type="checkbox" name="category_access_enabled" value="1"
                               class="ml-checkbox" <?= $catAccessEnabled ? 'checked' : '' ?>>
                        启用分类/标签级别的访问控制
                    </label>
                </div>
            </div>
            <div class="ml-split-layout">
                <div class="ml-col-left">
                    <div class="ml-form-group">
                        <label class="ml-label">📂 需要登录的分类</label>
                        <textarea name="require_login_categories" class="ml-textarea" rows="4"
                                  placeholder="每行一个分类名称或别名&#10;例如：&#10;private&#10;会员专区"><?= htmlspecialchars($reqLoginCats) ?></textarea>
                        <span class="ml-hint">属于这些分类的文章页面需要登录后才能访问。</span>
                    </div>
                </div>
                <div class="ml-col-left">
                    <div class="ml-form-group">
                        <label class="ml-label">🏷️ 需要登录的标签</label>
                        <textarea name="require_login_tags" class="ml-textarea" rows="4"
                                  placeholder="每行一个标签名称&#10;例如：&#10;付费内容&#10;内部资料"><?= htmlspecialchars($reqLoginTags) ?></textarea>
                        <span class="ml-hint">带有这些标签的文章页面需要登录后才能访问。</span>
                    </div>
                </div>
            </div>

            <div style="text-align:right;margin-top:16px;">
                <button type="submit" class="ml-btn ml-btn-primary">💾 保存高级设置</button>
            </div>
        </form>
    </div>

    <!-- ==================== 数据备份 ==================== -->
    <div class="ml-card" style="display:<?= $activeTab === 'backup' ? 'block' : 'none' ?>;">
        <?php include __DIR__ . '/partials/backup.php'; ?>
    </div>

</div>

<!-- 日志勾选框样式 -->
<style>
.ml-log-checkbox-group {
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
    margin-top: 6px;
}
.ml-log-checkbox {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    user-select: none;
    padding: 8px 14px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    background: #f9fafb;
    transition: all 0.15s ease;
}
.ml-log-checkbox:hover {
    border-color: #9ca3af;
    background: #f3f4f6;
}
.ml-log-checkbox:has(input:checked) {
    border-color: #6366f1;
    background: #eef2ff;
}
.ml-log-checkbox input[type="checkbox"] {
    width: 16px;
    height: 16px;
    cursor: pointer;
    accent-color: #6366f1;
}
.ml-log-checkbox-label {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.ml-log-badge {
    font-size: 13px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 4px;
}
.ml-log-badge-blocked {
    background: #fef2f2;
    color: #dc2626;
}
.ml-log-badge-allowed {
    background: #ecfdf5;
    color: #059669;
}
.ml-log-badge-redirect {
    background: #eff6ff;
    color: #2563eb;
}
.ml-log-checkbox small {
    color: #9ca3af;
    font-size: 11px;
    font-family: monospace;
}
</style>

<!-- 注入前端配置 -->
<script>
window.mloginConfig = {
    csrfToken:  <?= $csrfTokenJson ?>,
    exportData: <?= $exportJson ?>
};
</script>

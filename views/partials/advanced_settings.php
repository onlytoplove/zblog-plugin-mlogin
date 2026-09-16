<?php
/**
 * 高级设置面板
 */
?>
<form method="post" action="">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
    <input type="hidden" name="current_tab" value="advanced">

    <div class="ml-adv-grid">
        <!-- 反向代理 IP -->
        <div class="ml-adv-box">
            <div class="ml-section-head">🔗 反向代理 IP 获取</div>
            <div class="ml-form-group" style="margin-bottom:12px;">
                <div class="ml-switch-group">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:500;color:#374151;">
                        <input type="checkbox" name="trust_proxy" class="ml-checkbox"
                               value="1" <?= $trustProxy ? 'checked' : '' ?>>
                        信任反向代理头
                    </label>
                </div>
                <span class="ml-hint">
                    启用后从 X-Forwarded-For、X-Real-IP、CF-Connecting-IP 等 HTTP 头中提取客户端真实 IP。
                    <strong style="color:#dc2626;">仅在确认代理可信时启用。</strong>
                </span>
            </div>
        </div>

        <!-- 时区配置 -->
        <div class="ml-adv-box">
            <div class="ml-section-head">🌍 时区配置</div>
            <div class="ml-form-group" style="margin-bottom:12px;">
                <label class="ml-label">时区偏移（小时）</label>
                <select name="timezone_offset" class="ml-select">
                    <?php for ($tz = -12; $tz <= 14; $tz++): ?>
                        <option value="<?= $tz ?>" <?= $timezoneOffset == $tz ? 'selected' : '' ?>>
                            UTC<?= $tz >= 0 ? '+' : '' ?><?= $tz ?>
                            <?php if ($tz === 8)  echo '（北京时间）'; ?>
                            <?php if ($tz === 0)  echo '（格林威治）'; ?>
                            <?php if ($tz === 9)  echo '（东京时间）'; ?>
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
                <input type="number" name="login_fail_max" class="ml-input" style="width:100px;"
                       min="0" max="100" value="<?= $loginFailMax ?>">
                <span class="ml-hint">同一 IP 在时间窗口内被拦截的最大次数，超过后临时锁定。设为 0 表示关闭。</span>
            </div>
            <div class="ml-form-group" style="margin-bottom:0;">
                <label class="ml-label">锁定时间窗口（分钟）</label>
                <input type="number" name="login_fail_lock_minutes" class="ml-input" style="width:100px;"
                       min="1" max="1440" value="<?= $loginFailLock ?>">
                <span class="ml-hint">超过拦截频率上限后，锁定该 IP 的时长（分钟）。</span>
            </div>
        </div>

        <!-- 日志优化 -->
        <div class="ml-adv-box">
            <div class="ml-section-head">📝 日志写入优化</div>
            <div class="ml-form-group" style="margin-bottom:0;">
                <div class="ml-switch-group">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:500;color:#374151;">
                        <input type="checkbox" name="log_only_blocked" class="ml-checkbox"
                               value="1" <?= $logOnlyBlocked ? 'checked' : '' ?>>
                        仅记录拦截日志
                    </label>
                </div>
                <span class="ml-hint">启用后放行类日志不再写入文件，仅记录拦截和跳转日志。适合高流量站点减少 I/O。</span>
            </div>
        </div>

        <!-- 自定义放行路径 -->
        <div class="ml-adv-box ml-adv-box-full">
            <div class="ml-section-head">🛤️ 自定义放行路径</div>
            <div class="ml-form-group" style="margin-bottom:0;">
                <textarea name="extra_system_pass" class="ml-textarea" rows="4"
                          placeholder="每行一个路径，例如：&#10;zb_users/plugin/my_plugin/api.php&#10;custom/callback.php"><?= htmlspecialchars($extraSystemPass) ?></textarea>
                <span class="ml-hint">除系统内置放行路径外，额外需要始终放行的 URI 路径。无论用户是否登录都会直接放行。</span>
            </div>
        </div>

        <!-- 分类/标签控制 -->
        <div class="ml-adv-box ml-adv-box-full">
            <div class="ml-section-head">📂 按分类/标签设置登录要求</div>
            <div class="ml-form-group" style="margin-bottom:16px;">
                <div class="ml-switch-group">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:500;color:#374151;">
                        <input type="checkbox" name="category_access_enabled" class="ml-checkbox"
                               value="1" <?= $catAccessEnabled ? 'checked' : '' ?>>
                        启用分类/标签登录控制
                    </label>
                </div>
                <span class="ml-hint">启用后，属于下方指定分类或标签的文章，未登录用户将被重定向到中转页。</span>
            </div>
            <div class="ml-split-layout">
                <div class="ml-col-left">
                    <div class="ml-form-group" style="margin-bottom:0;">
                        <label class="ml-label">需要登录的分类</label>
                        <textarea name="require_login_categories" class="ml-textarea" rows="4"
                                  placeholder="每行一个，支持分类名称或分类 ID&#10;例如：&#10;会员专区&#10;3"><?= htmlspecialchars($reqLoginCats) ?></textarea>
                        <span class="ml-hint">属于这些分类的文章，未登录用户需要登录后才能查看。</span>
                    </div>
                </div>
                <div class="ml-col-left">
                    <div class="ml-form-group" style="margin-bottom:0;">
                        <label class="ml-label">需要登录的标签</label>
                        <textarea name="require_login_tags" class="ml-textarea" rows="4"
                                  placeholder="每行一个标签名称&#10;例如：&#10;付费内容&#10;内部资料"><?= htmlspecialchars($reqLoginTags) ?></textarea>
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

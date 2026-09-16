<?php
/**
 * URI 白名单/黑名单规则面板
 *
 * 使用变量：$whitelistEnabled, $blacklistEnabled, $whitelistLevelArr,
 *           $blacklistLevelArr, $whitelist, $blacklist, $csrfToken
 */
$levelNames = [1 => '管理员', 2 => '网站编辑', 3 => '作者', 4 => '协作者', 5 => '评论者'];
?>

<!-- 白名单 -->
<div class="ml-col-left">
    <div class="ml-form-group">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
            <label class="ml-label" style="margin-bottom:0;">✅ 白名单 (免登录)</label>
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;color:#374151;">
                <input type="checkbox" name="whitelist_enabled" id="whitelist_enabled"
                       class="ml-checkbox" value="1" <?= $whitelistEnabled ? 'checked' : '' ?>> 启用
            </label>
        </div>

        <!-- 生效级别选择 -->
        <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:10px;padding:8px 10px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;">
            <span style="font-size:12px;color:#0369a1;font-weight:600;line-height:24px;">生效级别：</span>
            <?php for ($i = 1; $i <= 5; $i++): ?>
                <label style="display:flex;align-items:center;gap:4px;cursor:pointer;font-size:12px;color:#374151;">
                    <input type="hidden" name="whitelist_level_<?= $i ?>" value="0">
                    <input type="checkbox" name="whitelist_level_<?= $i ?>" class="ml-checkbox"
                           value="1" <?= in_array($i, $whitelistLevelArr) ? 'checked' : '' ?> style="width:14px;height:14px;">
                    <?= $levelNames[$i] ?>
                </label>
            <?php endfor; ?>
            <span style="font-size:11px;color:#6b7280;line-height:24px;">（未登录用户始终适用）</span>
        </div>

        <textarea name="whitelist" class="ml-textarea" rows="8"
                  placeholder="每行一个路径，例如：&#10;?life&#10;?id=2"
                  <?= !$whitelistEnabled ? 'style="opacity:0.5;"' : '' ?>><?= htmlspecialchars($whitelist) ?></textarea>
        <span class="ml-hint">允许游客直接访问的路径，匹配后放行。仅对勾选级别的用户生效。</span>
    </div>
</div>

<!-- 黑名单 -->
<div class="ml-col-left">
    <div class="ml-form-group">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
            <label class="ml-label" style="margin-bottom:0;">🚫 黑名单 (直接 403)</label>
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;color:#374151;">
                <input type="checkbox" name="blacklist_enabled" id="blacklist_enabled"
                       class="ml-checkbox" value="1" <?= $blacklistEnabled ? 'checked' : '' ?>> 启用
            </label>
        </div>

        <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:10px;padding:8px 10px;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;">
            <span style="font-size:12px;color:#dc2626;font-weight:600;line-height:24px;">生效级别：</span>
            <?php for ($i = 1; $i <= 5; $i++): ?>
                <label style="display:flex;align-items:center;gap:4px;cursor:pointer;font-size:12px;color:#374151;">
                    <input type="hidden" name="blacklist_level_<?= $i ?>" value="0">
                    <input type="checkbox" name="blacklist_level_<?= $i ?>" class="ml-checkbox"
                           value="1" <?= in_array($i, $blacklistLevelArr) ? 'checked' : '' ?> style="width:14px;height:14px;">
                    <?= $levelNames[$i] ?>
                </label>
            <?php endfor; ?>
            <span style="font-size:11px;color:#6b7280;line-height:24px;">（未登录用户始终适用）</span>
        </div>

        <textarea name="blacklist" class="ml-textarea" rows="8"
                  placeholder="每行一个路径，例如：&#10;?private-page"
                  <?= !$blacklistEnabled ? 'style="opacity:0.5;"' : '' ?>><?= htmlspecialchars($blacklist) ?></textarea>
        <span class="ml-hint">禁止访问的路径，匹配后返回 403。仅对勾选级别的用户生效。</span>
    </div>
</div>

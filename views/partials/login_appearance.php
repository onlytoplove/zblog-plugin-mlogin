<?php
/**
 * 登录外观设置面板
 */
?>
<form method="post" action="" id="login_config_form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
    <input type="hidden" name="current_tab" value="login">

    <div class="ml-split-layout">
        <div class="ml-col-left">
            <!-- 背景图片 -->
            <div class="ml-form-group">
                <label class="ml-label">背景图片</label>
                <div class="ml-upload-row">
                    <input type="text" name="login_bg" id="input_bg" class="ml-input"
                           value="<?= htmlspecialchars($loginBg, ENT_QUOTES, 'UTF-8') ?>" placeholder="图片 URL">
                    <button type="button" class="ml-btn ml-btn-secondary" id="btn_upload_bg">选择图片</button>
                </div>
                <input type="file" id="file_bg" accept="image/*" style="display:none;">

                <!-- 图片画廊 -->
                <div class="ml-gallery">
                    <?php if (!empty($imageFileList)): ?>
                        <?php foreach ($imageFileList as $imgUrl): ?>
                            <div class="ml-gallery-item"
                                 data-img-url="<?= htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8') ?>">
                                <span class="ml-gallery-del"
                                      data-del-url="<?= htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8') ?>">×</span>
                                <img src="<?= htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8') ?>" alt="">
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="grid-column:1/-1;color:#9ca3af;font-size:13px;padding:10px;text-align:center;">
                            暂无已上传图片
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 页面标题 -->
            <div class="ml-form-group">
                <label class="ml-label">页面标题</label>
                <input type="text" name="login_title" id="input_title" class="ml-input"
                       value="<?= htmlspecialchars($loginTitle, ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="例如：需要登录后访问">
            </div>

            <!-- 提示文案 -->
            <div class="ml-form-group">
                <label class="ml-label">提示文案</label>
                <textarea name="login_tip" id="input_tip" class="ml-textarea" style="min-height:80px;"
                          placeholder="例如：该内容仅限登录用户查看"><?= htmlspecialchars($loginTip, ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>

            <!-- 自动跳转 -->
            <div class="ml-form-group">
                <label class="ml-label">自动跳转</label>
                <div class="ml-switch-group">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:500;color:#374151;">
                        <input type="checkbox" name="auto_jump_enable" id="auto_jump_enable"
                               class="ml-checkbox" value="1" <?= $autoJumpOn ? 'checked' : '' ?>> 启用
                    </label>
                    <input type="number" name="auto_jump_seconds" id="auto_jump_seconds" class="ml-input"
                           style="width:70px;padding:4px 8px;" min="1" max="120" value="<?= $autoJumpDisp ?>">
                    <span style="color:#6b7280;font-size:13px;">秒后跳转</span>
                </div>
            </div>

            <div style="margin-top:30px;display:flex;gap:12px;justify-content:flex-end;">
                <button type="button" class="ml-btn ml-btn-secondary" id="btn_reset_login">🔄 恢复默认</button>
                <button type="submit" class="ml-btn ml-btn-primary">💾 保存外观设置</button>
            </div>
        </div>

        <!-- 实时预览 -->
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

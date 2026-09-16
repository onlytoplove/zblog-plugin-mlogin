<?php
/**
 * 数据备份面板
 */
?>
<div class="ml-backup-grid">
    <div class="ml-backup-box">
        <div class="ml-section-head">📤 导出配置</div>
        <textarea id="export_data" readonly class="ml-textarea"
                  style="background:#fff;height:200px;font-size:12px;color:#6b7280;"></textarea>
        <div class="ml-hint" style="margin-bottom:16px;">当前配置的 JSON 数据，可直接复制保存。</div>
        <a href="?act=export_json&csrf_token=<?= urlencode($csrfToken) ?>"
           class="ml-btn ml-btn-primary" style="width:100%;">📥 下载 JSON 备份</a>
    </div>

    <div class="ml-backup-box">
        <form method="post" action="" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="current_tab" value="backup">
            <input type="hidden" name="backup_action" value="import">

            <div class="ml-section-head">📥 导入配置</div>
            <textarea name="import_text" class="ml-textarea" style="height:160px;"
                      placeholder="粘贴 JSON 数据..."></textarea>
            <div style="margin:12px 0;font-size:13px;color:#4b5563;">或选择文件（最大 1MB）：</div>
            <input type="file" name="import_file" accept=".json" class="ml-input"
                   style="padding:6px;font-size:13px;">
            <button type="submit" class="ml-btn ml-btn-danger" style="width:100%;margin-top:16px;">
                🚀 导入并覆盖
            </button>
        </form>
    </div>
</div>

/**
 * mlogin 插件 - 后台交互脚本
 *
 * 从 main.php 内联 JS 提取，使用 IIFE 封装，不污染全局命名空间
 * 依赖：window.mloginConfig（由视图模板注入）
 */
(function () {
    'use strict';

    // ─── 配置与工具 ─────────────────────────────────────────
    var config   = window.mloginConfig || {};
    var csrf     = config.csrfToken    || '';

    function $(id) { return document.getElementById(id); }
    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    /**
     * 通用 AJAX POST（FormData 或 URL-encoded）
     */
    function ajaxPost(url, data, onSuccess, onError) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', url, true);
        // FormData 不需要手动设置 Content-Type
        if (typeof data === 'string') {
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        }
        xhr.onload = function () {
            if (xhr.status === 200) {
                try {
                    var r = JSON.parse(xhr.responseText);
                    onSuccess(r);
                } catch (e) {
                    if (onError) onError('解析失败: ' + e.message);
                }
            } else {
                if (onError) onError('HTTP ' + xhr.status);
            }
        };
        xhr.onerror = function () {
            if (onError) onError('网络错误');
        };
        xhr.send(data);
    }

    // ─── 插件开关交互 ─────────────────────────────────────
    var pluginSw = $('plugin_enabled');
    var wlSw     = $('whitelist_enabled');
    var blSw     = $('blacklist_enabled');
    var statusEl = $('plugin_status');

    function updatePluginUI() {
        var on = pluginSw && pluginSw.checked;
        var group = pluginSw ? pluginSw.closest('.ml-switch-group') : null;
        if (group) {
            group.style.background  = on ? '#ecfdf5' : '#fef2f2';
            group.style.borderColor = on ? '#a7f3d0' : '#fecaca';
        }
        if (statusEl) {
            statusEl.textContent = on
                ? '已启用 — 登录检查正常运行'
                : '已禁用 — 所有登录检查已跳过';
        }
    }

    function updateListOpacity(sw, ta) {
        if (ta) ta.style.opacity = (sw && sw.checked) ? '1' : '0.5';
    }

    if (pluginSw) pluginSw.addEventListener('change', updatePluginUI);
    if (wlSw) wlSw.addEventListener('change', function () {
        updateListOpacity(wlSw, document.querySelector('textarea[name="whitelist"]'));
    });
    if (blSw) blSw.addEventListener('change', function () {
        updateListOpacity(blSw, document.querySelector('textarea[name="blacklist"]'));
    });

    // ─── 图片上传 ─────────────────────────────────────────
    var fileBg    = $('file_bg');
    var inputBg   = $('input_bg');
    var btnUpload = $('btn_upload_bg');
    var iframe    = $('live-preview-iframe');

    if (btnUpload && fileBg) {
        btnUpload.addEventListener('click', function () { fileBg.click(); });

        fileBg.addEventListener('change', function () {
            if (!this.files || !this.files[0]) return;
            if (this.files[0].size > 5 * 1024 * 1024) {
                alert('图片超过 5MB');
                return;
            }

            var fd = new FormData();
            fd.append('image_file', this.files[0]);
            fd.append('csrf_token', csrf);

            var origText = btnUpload.innerText;
            btnUpload.disabled  = true;
            btnUpload.innerText = '上传中...';

            ajaxPost('?act=upload_image', fd, function (r) {
                btnUpload.disabled  = false;
                btnUpload.innerText = origText;
                if (r.success && r.url) {
                    inputBg.value = r.url;
                    renderPreview();
                    location.reload();
                } else {
                    alert(r.msg || '上传失败');
                }
            }, function (err) {
                btnUpload.disabled  = false;
                btnUpload.innerText = origText;
                alert('上传失败: ' + err);
            });
        });
    }

    // ─── 登录外观实时预览 ─────────────────────────────────
    var titleInput = $('input_title');
    var tipInput   = $('input_tip');
    var jumpSw     = $('auto_jump_enable');
    var jumpSec    = $('auto_jump_seconds');
    var DEF_TITLE  = '该内容登录后可访问';
    var DEF_TIP    = '请登录后查看完整内容';

    [inputBg, titleInput, tipInput, jumpSw, jumpSec].forEach(function (el) {
        if (!el) return;
        el.addEventListener('input', renderPreview);
        if (el.type === 'checkbox') el.addEventListener('change', renderPreview);
    });

    function renderPreview() {
        if (!iframe) return;

        var bg    = inputBg    ? inputBg.value : '';
        var title = titleInput ? (titleInput.value.trim() || DEF_TITLE) : DEF_TITLE;
        var tip   = tipInput   ? tipInput.value : '';
        var jOn   = jumpSw     ? jumpSw.checked : false;
        var jSec  = jumpSec    ? parseInt(jumpSec.value, 10) : 5;
        if (isNaN(jSec) || jSec < 1) jSec = 5;

        var tipHtml  = esc(tip).replace(/\n/g, '<br>');
        var jumpHtml = jOn
            ? '<div style="font-size:13px;color:#888;margin-top:16px">等待 <span style="color:#2563eb;font-weight:bold">' + jSec + '</span> 秒后自动跳转...</div>'
            : '';
        var bgStyle  = bg
            ? "background:url('" + esc(bg) + "') center/cover no-repeat fixed;"
            : 'background:#fff;';

        var html = [
            '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>',
            '*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}',
            'html,body{width:100%;height:100%;' + bgStyle + 'display:flex;justify-content:center;align-items:center;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}',
            '.card{background:rgba(255,255,255,.95);width:90%;max-width:420px;border-radius:24px;overflow:hidden;box-shadow:0 10px 40px -10px rgba(0,0,0,.15);text-align:center;backdrop-filter:blur(10px)}',
            '.body{padding:32px 24px}',
            '.title{font-size:20px;font-weight:700;color:#1a1a1a;margin-bottom:12px}',
            '.tip{font-size:14px;color:#666;margin-bottom:32px;line-height:1.6}',
            '.btn{display:block;width:100%;padding:14px 0;background:#2563eb;color:#fff;border:none;border-radius:12px;font-size:16px;font-weight:600;cursor:pointer;box-shadow:0 4px 12px rgba(37,99,235,.2);text-decoration:none;margin-bottom:16px}',
            '</style></head><body>',
            '<div class="card"><div class="body">',
            '<div class="title">' + esc(title) + '</div>',
            '<div class="tip">' + tipHtml + '</div>',
            '<a href="#" class="btn">点击登录</a>',
            jumpHtml,
            '</div></div></body></html>'
        ].join('');

        var doc = iframe.contentWindow.document;
        doc.open();
        doc.write(html);
        doc.close();
    }

    renderPreview();

    // ─── 恢复默认外观 ──────────────────────────────────────
    var btnReset = $('btn_reset_login');
    if (btnReset) {
        btnReset.addEventListener('click', function () {
            if (!confirm('确定恢复中转页为默认配置？此操作将立即保存。')) return;
            if (inputBg)    inputBg.value = '';
            if (titleInput) { titleInput.value = DEF_TITLE; titleInput.dispatchEvent(new Event('input')); }
            if (tipInput)   { tipInput.value   = DEF_TIP;   tipInput.dispatchEvent(new Event('input'));   }
            if (jumpSw)     jumpSw.checked = true;
            if (jumpSec)    jumpSec.value  = 5;
            renderPreview();
            $('login_config_form').submit();
        });
    }

    // ─── 图片画廊交互 ─────────────────────────────────────────
    document.querySelectorAll('.ml-gallery-item').forEach(function (item) {
        item.addEventListener('click', function (e) {
            if (e.target.classList.contains('ml-gallery-del')) return;
            if (inputBg) { inputBg.value = this.dataset.imgUrl; renderPreview(); }
        });
    });

    document.querySelectorAll('.ml-gallery-del').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var url = this.dataset.delUrl;
            if (!confirm('确认删除这张图片？')) return;

            var self     = this;
            var postData = 'csrf_token=' + encodeURIComponent(csrf) + '&file_url=' + encodeURIComponent(url);

            ajaxPost('?act=delete_image', postData, function (r) {
                if (r.success) {
                    self.closest('.ml-gallery-item').remove();
                    if (inputBg && inputBg.value === url) { inputBg.value = ''; renderPreview(); }
                } else {
                    alert(r.msg || '删除失败');
                }
            });
        });
    });

    // ─── 规则测试 ─────────────────────────────────────────
    var btnTest = $('btn_ajax_test');
    if (btnTest) {
        btnTest.addEventListener('click', function () {
            var uri     = $('ajax_test_uri').value.trim();
            var ip      = $('ajax_test_ip').value.trim();
            var level   = $('ajax_test_level') ? parseInt($('ajax_test_level').value, 10) : 0;
            var loading = $('test_loading');
            var area    = $('test_result_area');

            if (!uri) { alert('请输入测试 URI'); return; }

            btnTest.disabled      = true;
            loading.style.display = 'inline';
            area.style.display    = 'none';

            var fd = new FormData();
            fd.append('act', 'test_rule');
            fd.append('csrf_token', csrf);
            fd.append('test_uri', uri);
            fd.append('test_ip', ip || '127.0.0.1');
            fd.append('test_level', level);

            ajaxPost('', fd, function (r) {
                btnTest.disabled      = false;
                loading.style.display = 'none';

                if (!r.success && r.msg) { alert(r.msg); return; }

                var h = '<div class="ml-result-box ' + (r.final_class || '') + '">';
                h += '<div style="font-size:18px;font-weight:700;margin-bottom:8px">最终：' + esc(r.final_action) + '</div>';
                h += '<div style="font-size:13px;opacity:.8">';
                h += 'URI: <code>' + esc(r.uri) + '</code> | ';
                h += 'IP: <code>' + esc(r.ip) + '</code> | ';
                h += '级别: <strong>' + esc(r.level) + '</strong> | ';
                h += '命中: <strong>' + esc(r.matched_rule) + '</strong>';
                h += '</div></div>';

                if (r.steps && r.steps.length) {
                    h += '<div style="margin-top:20px"><div class="ml-sub-title" style="font-size:14px">检查流程</div>';
                    h += '<table style="width:100%;border-collapse:collapse;font-size:13px">';
                    for (var i = 0; i < r.steps.length; i++) {
                        h += '<tr style="border-bottom:1px solid #e5e7eb">';
                        h += '<td style="padding:10px 12px;font-weight:600;color:#374151;width:40%">' + esc(r.steps[i][0]) + '</td>';
                        h += '<td style="padding:10px 12px;color:#6b7280">' + esc(r.steps[i][1]) + '</td></tr>';
                    }
                    h += '</table></div>';
                }

                area.innerHTML     = h;
                area.style.display = 'block';
            }, function (err) {
                btnTest.disabled      = false;
                loading.style.display = 'none';
                alert('测试失败: ' + err);
            });
        });
    }

    // ─── 日志筛选 ─────────────────────────────────────────
    var btnFilter = $('btn_filter_logs');
    var btnResetFilter = $('btn_reset_filter');

    if (btnFilter) {
        btnFilter.addEventListener('click', function () {
            var action = $('log_filter_action').value;
            var ip     = $('log_filter_ip').value.trim();
            var date   = $('log_filter_date').value;
            var box    = $('log_content_box');
            var hint   = $('log_count_hint');
            if (!box) return;

            btnFilter.disabled  = true;
            btnFilter.innerText = '筛选中...';

            var fd = new FormData();
            fd.append('act', 'filter_logs');
            fd.append('csrf_token', csrf);
            fd.append('filter_action', action);
            fd.append('filter_ip', ip);
            fd.append('filter_date', date);
            fd.append('max_lines', '500');

            ajaxPost('', fd, function (r) {
                btnFilter.disabled  = false;
                btnFilter.innerText = '\u{1F50D} 筛选';
                if (r.success && r.lines) {
                    box.textContent = r.lines.length === 0 ? '（无匹配日志）' : r.lines.join('\n');
                    if (hint) hint.textContent = '筛选结果：' + r.total + ' 条匹配记录';
                } else {
                    alert(r.msg || '筛选失败');
                }
            }, function () {
                btnFilter.disabled  = false;
                btnFilter.innerText = '\u{1F50D} 筛选';
                alert('网络错误');
            });
        });
    }

    if (btnResetFilter) {
        btnResetFilter.addEventListener('click', function () {
            $('log_filter_action').value = '';
            $('log_filter_ip').value     = '';
            $('log_filter_date').value   = '';
            location.reload();
        });
    }


    // ─── 导出配置数据渲染 ──────────────────────────────────
    var exportTextarea = $('export_data');
    if (exportTextarea && config.exportData) {
        var exportStr = config.exportData;
        if (typeof exportStr === 'object') {
            exportStr = JSON.stringify(exportStr, null, 4);
        }
        exportTextarea.value = exportStr;
    }

})();
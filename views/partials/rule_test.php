<?php
/**
 * 规则测试面板
 */
?>
<div class="ml-section-head" style="font-size:17px;margin-bottom:20px;">🧪 规则测试</div>
<p class="ml-hint" style="margin-top:-10px;margin-bottom:20px;">
    输入 URI、IP 和用户级别，模拟检查流程，验证规则配置是否正确。
</p>

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
                <label class="ml-label">
                    模拟用户级别 <span class="ml-badge ml-badge-new">NEW</span>
                </label>
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

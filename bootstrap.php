<?php
/**
 * mlogin 插件 - 系统引导文件
 *
 * 负责加载 Z-Blog 系统和插件核心，统一入口
 */

// 加载 Z-Blog 系统（必须在 require include.php 之前）
require_once dirname(__DIR__, 3) . '/zb_system/function/c_system_base.php';
require_once dirname(__DIR__, 3) . '/zb_system/function/c_system_admin.php';

$zbp->Load();

// Z-Blog 加载后再 require include.php，消除常量重复定义
// 此时 RegisterPlugin 等 Z-Blog 函数已可用
require_once __DIR__ . '/include.php';

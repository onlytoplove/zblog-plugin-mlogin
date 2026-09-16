Nginx 用户请手动添加以下配置以保护日志目录：

location ~ /zb_users/plugin/mlogin/logs/ {
    deny all;
    return 403;
}

#!/bin/bash
# ============================================================
#  LY云计算 - 服务启动脚本（沙箱/测试环境）
#  启动 MySQL 5.7 + PHP-FPM 8.2 + Nginx
# ============================================================
set -e

echo "==> 1/3 启动 MySQL 5.7 ..."
mkdir -p /var/run/mysqld /var/log/mysql
chown -R mysql:mysql /var/run/mysqld /var/log/mysql /var/lib/mysql57 2>/dev/null || true
if pgrep -f "mysqld --defaults-file=/etc/mysql57.cnf" > /dev/null; then
    echo "    MySQL 已经在运行"
else
    setsid /usr/local/mysql/bin/mysqld --defaults-file=/etc/mysql57.cnf --user=mysql \
        > /var/log/mysql/console.log 2>&1 < /dev/null &
    disown
    for i in $(seq 1 30); do
        if /usr/local/mysql/bin/mysqladmin --socket=/var/run/mysqld/mysqld.sock -uroot ping > /dev/null 2>&1; then
            echo "    MySQL 5.7 启动成功"; break
        fi
        sleep 1
    done
fi

echo "==> 2/3 启动 PHP-FPM 8.2 ..."
mkdir -p /run/php
if pgrep -f "php-fpm: master process" > /dev/null; then
    echo "    PHP-FPM 已经在运行"
else
    setsid php-fpm8.2 --fpm-config /etc/php/8.2/fpm/php-fpm.conf > /dev/null 2>&1 < /dev/null &
    disown
    sleep 2
    echo "    PHP-FPM 8.2 启动完成"
fi

echo "==> 3/3 启动 Nginx ..."
nginx -t 2>&1 | tail -1
if pgrep -x nginx > /dev/null; then
    nginx -s reload 2>/dev/null || true
    echo "    Nginx 已重载"
else
    setsid nginx > /dev/null 2>&1 < /dev/null &
    disown
    sleep 1
    echo "    Nginx 启动完成"
fi

echo ""
echo "============================================"
echo "  LY云计算 已启动"
echo "  前台:  http://localhost:8080/"
echo "  后台:  http://localhost:8080/index.php?r=admin/auth/login"
echo "  MySQL: 127.0.0.1:3306  库=lycloud  用户=lycloud / LyCloud@2026"
echo "============================================"

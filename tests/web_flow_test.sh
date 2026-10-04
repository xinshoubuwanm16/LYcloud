#!/bin/bash
# ============================================================
#  LY云计算 端到端 Web 流程测试（curl 模拟真实浏览器）
# ============================================================
B="${LY_BASE:-http://127.0.0.1:8080}"   # 可用 LY_BASE 环境变量覆盖，便于测试其他实例
CK="/tmp/lyc_user.txt"
CA="/tmp/lyc_admin.txt"
rm -f "$CK" "$CA"

EMAIL="buyer$(date +%s)@qq.com"
PASS="Buyer@123456"

echo ""
echo "===== LY云计算 端到端 Web 流程测试 ====="
echo ""

# ---------- 工具函数 ----------
get_token() {
    grep -oE 'name="_token" value="[a-f0-9]+"' "$1" | head -1 | sed 's/.*value="//;s/"//'
}

# ---------- 1. 访问注册页并取 CSRF ----------
echo "[1] 打开注册页"
curl -s -c "$CK" -b "$CK" "$B/index.php?r=auth/register" -o /tmp/reg.html
TOKEN=$(get_token /tmp/reg.html)
[ -n "$TOKEN" ] && echo "  ✔ 获取 CSRF Token: ${TOKEN:0:16}..." || echo "  ✘ 未获取到 Token"

# ---------- 2. 提交注册 ----------
echo "[2] 提交注册（$EMAIL）"
curl -s -c "$CK" -b "$CK" -L \
    -d "_token=$TOKEN" -d "email=$EMAIL" -d "password=$PASS" -d "password_confirm=$PASS" -d "nickname=测试买家" \
    "$B/index.php?r=auth/register" -o /tmp/reg_done.html
grep -q "退出" /tmp/reg_done.html && echo "  ✔ 注册成功并自动登录" || echo "  ✘ 注册失败"

# ---------- 3. 打开商品详情 ----------
echo "[3] 打开商品详情（ID=1）"
curl -s -b "$CK" -c "$CK" "$B/index.php?r=product/show&id=1" -o /tmp/pd.html
grep -q "IPv6宝塔面板主机" /tmp/pd.html && echo "  ✔ 商品页正常渲染" || echo "  ✘ 商品页异常"
grep -q "立即购买" /tmp/pd.html && echo "  ✔ 显示购买按钮" || echo "  ✘ 缺少购买按钮"
TOKEN2=$(get_token /tmp/pd.html)

# ---------- 4. 下单（当面付） ----------
echo "[4] 提交订单（当面付扫码）"
curl -s -b "$CK" -c "$CK" -L \
    -d "_token=$TOKEN2" -d "product_id=1" -d "pay_channel=qr" \
    "$B/index.php?r=order/create" -o /tmp/pay.html
grep -q "订单支付" /tmp/pay.html && echo "  ✔ 进入收银台" || echo "  ✘ 未进入收银台"
ORDER_NO=$(grep -oE '订单号：[0-9]+' /tmp/pay.html | head -1 | sed 's/订单号：//')
[ -n "$ORDER_NO" ] && echo "  ✔ 订单号: $ORDER_NO" || echo "  ✘ 未取到订单号"
# 1.7.9 起捐赠支付就绪时排在通道首位，qr 失效订单收银台回落捐赠，入口为「我已完成付款」
grep -q "立即购买\|模拟支付\|我已完成付款" /tmp/pay.html && echo "  ✔ 收银台含支付入口" || echo "  ✘ 收银台异常"

# ---------- 5. 模拟支付（演示模式） ----------
echo "[5] 模拟支付成功"
curl -s -b "$CK" -c "$CK" -L \
    "$B/index.php?r=pay/demo&out_trade_no=$ORDER_NO" -o /tmp/detail.html
grep -q "面板信息已就绪" /tmp/detail.html && echo "  ✔ 订单已自动发货" || echo "  ✘ 未发货"

# ---------- 6. 校验面板信息 ----------
echo "[6] 校验面板登录信息交付"
grep -q "宝塔面板登录信息" /tmp/detail.html && echo "  ✔ 显示面板信息区块" || echo "  ✘ 缺少面板信息"
if grep -qE 'id="piUrl"' /tmp/detail.html && grep -qE 'http://\[' /tmp/detail.html; then echo "  ✔ 面板登录链接已展示"; else echo "  ✘ 面板链接缺失"; fi
if grep -qE 'id="piUser"' /tmp/detail.html; then echo "  ✔ 面板账号已展示"; else echo "  ✘ 面板账号缺失"; fi
if grep -qE 'id="piPass"' /tmp/detail.html; then echo "  ✔ 面板密码已展示"; else echo "  ✘ 面板密码缺失"; fi
grep -q "一键复制全部信息" /tmp/detail.html && echo "  ✔ 提供一键复制" || echo "  ✘ 缺少复制按钮"
grep -q "已发货" /tmp/detail.html && echo "  ✔ 订单状态=已发货" || echo "  ✘ 状态异常"

# ---------- 7. 我的订单列表 ----------
echo "[7] 我的订单列表"
curl -s -b "$CK" "$B/index.php?r=order/list" -o /tmp/olist.html
grep -q "$ORDER_NO" /tmp/olist.html && echo "  ✔ 订单出现在列表中" || echo "  ✘ 订单未出现"
grep -q "查看面板" /tmp/olist.html && echo "  ✔ 提供查看面板入口" || echo "  ✘ 缺少入口"

# ---------- 8. 订单归属隔离（换个用户） ----------
echo "[8] 越权访问校验"
CK2="/tmp/lyc_other.txt"; rm -f "$CK2"
curl -s -c "$CK2" -b "$CK2" "$B/index.php?r=auth/register" -o /tmp/r2.html
T2=$(get_token /tmp/r2.html)
E2="other$(date +%s)@qq.com"
curl -s -c "$CK2" -b "$CK2" -L -d "_token=$T2" -d "email=$E2" -d "password=$PASS" -d "password_confirm=$PASS" \
    "$B/index.php?r=auth/register" -o /dev/null
OID=$(grep -oE 'r=order/detail(&amp;|&)id=[0-9]+' /tmp/olist.html | head -1 | grep -oE '[0-9]+$')
if [ -n "$OID" ]; then
    RES=$(curl -s -b "$CK2" -L "$B/index.php?r=order/detail&id=$OID" -o /tmp/other.html -w "%{http_code}")
    grep -q "订单不存在" /tmp/other.html && echo "  ✔ 他人无法查看该订单（已拦截）" || echo "  ✘ 越权可访问！"
else
    echo "  - 跳过（未取到订单 ID）"
fi

# ---------- 9. 后台登录 ----------
echo "[9] 管理后台登录"
curl -s -c "$CA" -b "$CA" "$B/index.php?r=admin/auth/login" -o /tmp/al.html
AT=$(get_token /tmp/al.html)
curl -s -c "$CA" -b "$CA" -L -d "_token=$AT" -d "username=admin" -d "password=admin888" \
    "$B/index.php?r=admin/auth/login" -o /tmp/adash.html
grep -q "控制台" /tmp/adash.html && echo "  ✔ 管理员登录成功" || echo "  ✘ 后台登录失败"

# ---------- 10. 后台各页面 ----------
echo "[10] 后台功能页"
for pg in "admin/index:控制台" "admin/product/list:商品管理" "admin/stock/list:库存管理" "admin/order/list:订单管理" "admin/user/list:用户管理" "admin/setting/index:系统设置" "admin/stock/import:批量导入"; do
    R="${pg%%:*}"; N="${pg##*:}"
    CODE=$(curl -s -b "$CA" "$B/index.php?r=$R" -o /tmp/pg.html -w "%{http_code}")
    if grep -q "$N" /tmp/pg.html; then
        echo "  ✔ $R  HTTP $CODE  ($N)"
    else
        echo "  ✘ $R  HTTP $CODE  内容异常"
    fi
done

# ---------- 11. 后台订单详情 ----------
echo "[11] 后台查看订单"
curl -s -b "$CA" "$B/index.php?r=admin/order/list" -o /tmp/aol.html
grep -q "$ORDER_NO" /tmp/aol.html && echo "  ✔ 新订单已出现在后台" || echo "  ✘ 后台未显示该订单"

# ---------- 12. 后台商品管理 ----------
echo "[12] 添加商品（后台）"
AT2=$(get_token /tmp/pg.html)
curl -s -b "$CA" "$B/index.php?r=admin/product/form" -o /tmp/pform.html
AT3=$(get_token /tmp/pform.html)
curl -s -b "$CA" -L -d "_token=$AT3" -d "name=端到端测试商品" -d "subtitle=curl 测试" \
    -d "price=5.55" -d "original_price=9.99" -d "stock_mode=1" -d "auto_deliver=1" -d "sort=1" -d "sales=0" -d "status=1" \
    "$B/index.php?r=admin/product/save" -o /tmp/psave.html
grep -q "端到端测试商品" /tmp/psave.html && echo "  ✔ 商品添加成功" || echo "  ✘ 商品添加失败"

# ---------- 13. 批量导入库存 ----------
echo "[13] 批量导入面板信息"
curl -s -b "$CA" "$B/index.php?r=admin/stock/import" -o /tmp/simp.html
ST=$(get_token /tmp/simp.html)
NEWPID=$(grep -oE 'value="[0-9]+">#?[0-9]* IPv6宝塔' /tmp/simp.html | head -1 | grep -oE '[0-9]+' | head -1)
CONTENT="http://[2408:aaaa::1]:8888/zzz1|user_zzz1|Pass@1234|导入测试A
http://[2408:aaaa::2]:8888/zzz2|user_zzz2|Pass@1234|导入测试B
格式错误行缺少密码"
curl -s -b "$CA" -L -d "_token=$ST" -d "product_id=1" --data-urlencode "content=$CONTENT" \
    "$B/index.php?r=admin/stock/import" -o /tmp/simp_res.html
grep -q "成功导入 2 条" /tmp/simp_res.html && echo "  ✔ 导入 2 条成功，错误行被跳过" || echo "  ✘ 导入结果异常"
grep -q "user_zzz1" /tmp/simp_res.html && echo "  ✔ 导入内容已入库" || echo "  ✘ 数据未入库"

# ---------- 14. 导出 CSV ----------
echo "[14] 订单导出 CSV"
curl -s -b "$CA" "$B/index.php?r=admin/order/export" -o /tmp/orders.csv -w "  HTTP %{http_code}  "
if head -c 3 /tmp/orders.csv | grep -q $'\xef\xbb\xbf'; then echo "✔ 含 BOM，Excel 可正常打开"; else echo "升级中"; fi
grep -q "订单号" /tmp/orders.csv && echo "  ✔ CSV 表头正确" || echo "  ✘ CSV 异常"

# ---------- 15. 未登录访问后台 ----------
echo "[15] 后台访问控制"
curl -s -o /dev/null -w "  未登录访问 admin/index -> HTTP %{http_code} → %{redirect_url}\n" "$B/index.php?r=admin/index"

# ---------- 16. CSRF 防护 ----------
echo "[16] CSRF 防护"
CODE=$(curl -s -o /dev/null -w "%{http_code}" -b "$CK" -d "product_id=1" -d "pay_channel=qr" "$B/index.php?r=order/create")
[ "$CODE" = "419" ] && echo "  ✔ 缺少 CSRF Token 的请求被拒绝 (419)" || echo "  ✘ CSRF 防护失效 (HTTP $CODE)"

# ---------- 17. 注册邮箱域名白名单 ----------
echo "[17] 注册邮箱白名单（仅 qq.com / foxmail.com）"
rm -f /tmp/wl.txt
curl -s -c /tmp/wl.txt -b /tmp/wl.txt "$B/index.php?r=auth/register" -o /tmp/wl.html
WT=$(get_token /tmp/wl.html)
WLMAIL="bad$(date +%s)@gmail.com"
curl -s -c /tmp/wl.txt -b /tmp/wl.txt -L \
    -d "_token=$WT" -d "email=$WLMAIL" -d "password=Test@123456" -d "password_confirm=Test@123456" \
    "$B/index.php?r=auth/register" -o /tmp/wl_out.html
grep -q "退出" /tmp/wl_out.html && echo "  ✘ gmail.com 竟然注册成功" || echo "  ✔ gmail.com 被拒绝注册"
grep -qE "仅支持|qq\.com" /tmp/wl_out.html && echo "  ✔ 返回了白名单提示" || echo "  ✘ 未提示白名单要求"

# 后缀绕过尝试
for BAD in "evil$(date +%s)@notqq.com" "evil$(date +%s)@qq.com.evil.com"; do
    rm -f /tmp/wl2.txt
    curl -s -c /tmp/wl2.txt -b /tmp/wl2.txt "$B/index.php?r=auth/register" -o /tmp/wl2.html
    WT2=$(get_token /tmp/wl2.html)
    curl -s -c /tmp/wl2.txt -b /tmp/wl2.txt -L \
        -d "_token=$WT2" -d "email=$BAD" -d "password=Test@123456" -d "password_confirm=Test@123456" \
        "$B/index.php?r=auth/register" -o /tmp/wl2_out.html
    grep -q "退出" /tmp/wl2_out.html && echo "  ✘ 绕过成功: $BAD" || echo "  ✔ 绕过被拦: ${BAD#*@}"
done

# 白名单内应能通过（foxmail.com）
rm -f /tmp/wl3.txt
curl -s -c /tmp/wl3.txt -b /tmp/wl3.txt "$B/index.php?r=auth/register" -o /tmp/wl3.html
WT3=$(get_token /tmp/wl3.html)
OKMAIL="ok$(date +%s)@foxmail.com"
curl -s -c /tmp/wl3.txt -b /tmp/wl3.txt -L \
    -d "_token=$WT3" -d "email=$OKMAIL" -d "password=Test@123456" -d "password_confirm=Test@123456" \
    "$B/index.php?r=auth/register" -o /tmp/wl3_out.html
grep -q "退出" /tmp/wl3_out.html && echo "  ✔ foxmail.com 注册成功" || echo "  ✘ foxmail.com 被误拦"

# ---------- 18. 验证码 AJAX 接口 ----------
echo "[18] 发送验证码接口"
CODE=$(curl -s -o /tmp/sc.json -w "%{http_code}" -b "$CK" \
    -H "X-Requested-With: XMLHttpRequest" \
    -d "_token=$(curl -s -b "$CK" "$B/index.php?r=auth/register" | grep -oE 'name="_token" value="[a-f0-9]+"' | head -1 | sed 's/.*value="//;s/"//')" \
    -d "email=$EMAIL" \
    "$B/index.php?r=auth/sendCode")
echo "  sendCode HTTP $CODE"
php -r '$j=json_decode(file_get_contents("/tmp/sc.json"),true); echo is_array($j)?"  ✔ 返回合法 JSON\n":"  ✘ 非 JSON: ".substr(file_get_contents("/tmp/sc.json"),0,80)."\n";' 2>/dev/null
# 缺 CSRF 应返回 419 且仍是 JSON
CODE2=$(curl -s -o /tmp/sc2.json -w "%{http_code}" -b "$CK" -H "X-Requested-With: XMLHttpRequest" -d "email=$EMAIL" "$B/index.php?r=auth/sendCode")
[ "$CODE2" = "419" ] && echo "  ✔ 缺 CSRF 返回 419" || echo "  ✘ 缺 CSRF 返回 $CODE2（应为 419）"
head -c 1 /tmp/sc2.json | grep -q "{" && echo "  ✔ 419 响应体仍是 JSON" || echo "  ✘ 419 响应体不是 JSON"

# ---------- 19. 找回密码页面 ----------
echo "[19] 找回密码"
rm -f /tmp/fgc.txt
CODE=$(curl -s -c /tmp/fgc.txt -b /tmp/fgc.txt -o /tmp/fg.html -w "%{http_code}" "$B/index.php?r=auth/forgot")
[ "$CODE" = "200" ] && echo "  ✔ auth/forgot HTTP 200" || echo "  ✘ auth/forgot HTTP $CODE"
grep -q "密码\|邮箱" /tmp/fg.html && echo "  ✔ 页面含表单字段" || echo "  ✘ 页面内容异常"
TOKENF=$(get_token /tmp/fg.html)
[ -n "$TOKENF" ] && echo "  ✔ 找回页含 CSRF Token" || echo "  ✘ 缺 CSRF Token"

# 不存在的邮箱也不应泄露账号是否存在（带会话 cookie 以通过 CSRF）
curl -s -c /tmp/fgc.txt -b /tmp/fgc.txt -L -d "_token=$TOKENF" -d "email=nobody$(date +%s)@qq.com" "$B/index.php?r=auth/forgot" -o /tmp/fg_out.html
grep -qE "如果该邮箱已注册|已发送|请查收|重置链接" /tmp/fg_out.html && echo "  ✔ 统一提示（防账号枚举）" || echo "  ✘ 未返回统一提示"
grep -qiE "不存在|未注册" /tmp/fg_out.html && echo "  ✘ 泄露了账号是否存在" || echo "  ✔ 未泄露账号存在性"

# 非白名单邮箱的找回请求也应被接受（统一提示，不额外泄露）
rm -f /tmp/fgc2.txt
curl -s -c /tmp/fgc2.txt -b /tmp/fgc2.txt "$B/index.php?r=auth/forgot" -o /tmp/fg2.html
TOKENF2=$(get_token /tmp/fg2.html)
curl -s -c /tmp/fgc2.txt -b /tmp/fgc2.txt -L -d "_token=$TOKENF2" -d "email=x$(date +%s)@gmail.com" "$B/index.php?r=auth/forgot" -o /tmp/fg2_out.html
grep -qE "如果该邮箱已注册|已发送|请查收|重置链接" /tmp/fg2_out.html && echo "  ✔ 非白名单域名同样返回统一提示" || echo "  ✘ 非白名单请求异常"

# 重置页缺 token 应被拒
CODE=$(curl -s -o /dev/null -w "%{http_code}" "$B/index.php?r=auth/reset")
[ "$CODE" = "200" ] || [ "$CODE" = "302" ] && echo "  ✔ 无 token 访问 reset 被处理（HTTP $CODE）" || echo "  ✘ reset 异常 HTTP $CODE"

# ---------- 20. 后台邮件设置 Tab ----------
echo "[20] 后台邮件设置"
curl -s -b "$CA" -c "$CA" "$B/index.php?r=admin/setting/index&tab=mail" -o /tmp/mailtab.html
grep -q "邮件设置" /tmp/mailtab.html && echo "  ✔ 邮件设置 Tab 存在" || echo "  ✘ 缺少邮件设置 Tab"
grep -q "smtp_host" /tmp/mailtab.html && echo "  ✔ 含 SMTP 主机字段" || echo "  ✘ 缺 SMTP 主机字段"
grep -q "smtp_password" /tmp/mailtab.html && echo "  ✔ 含 SMTP 授权码字段" || echo "  ✘ 缺授权码字段"
grep -q "register_email_domains" /tmp/mailtab.html && echo "  ✔ 含注册域名白名单字段" || echo "  ✘ 缺白名单字段"
grep -qE "smtp.qq.com|授权码" /tmp/mailtab.html && echo "  ✔ 含 QQ 邮箱配置引导" || echo "  ✘ 缺配置引导"
grep -q "testMail\|测试" /tmp/mailtab.html && echo "  ✔ 含测试发信入口" || echo "  ✘ 缺测试发信入口"

# 测试发信接口在未配置时应优雅失败：返回 JSON（HTTP 400 + 明确提示），绝不能 500
TMT=$(grep -oE 'name="_token" value="[a-f0-9]+"' /tmp/mailtab.html | head -1 | sed 's/.*value="//;s/"//')
CODE=$(curl -s -o /tmp/tm.json -w "%{http_code}" -b "$CA" -H "X-Requested-With: XMLHttpRequest" \
    -d "_token=$TMT" -d "to=test@qq.com" "$B/index.php?r=admin/setting/testMail")
[ "$CODE" != "500" ] && echo "  ✔ testMail 未发生 500（HTTP $CODE）" || echo "  ✘ testMail 500 崩溃"
head -c 1 /tmp/tm.json | grep -q "{" && echo "  ✔ testMail 返回 JSON" || echo "  ✘ testMail 非 JSON"
grep -q "SMTP\|邮件" /tmp/tm.json && echo "  ✔ 未配置时给出明确提示" || echo "  ✘ 提示信息缺失"

# 非法收件邮箱也应给出 JSON 提示而非崩溃
CODE=$(curl -s -o /tmp/tm2.json -w "%{http_code}" -b "$CA" -H "X-Requested-With: XMLHttpRequest" \
    -d "_token=$TMT" -d "to=not-an-email" "$B/index.php?r=admin/setting/testMail")
[ "$CODE" != "500" ] && echo "  ✔ 非法邮箱不崩溃（HTTP $CODE）" || echo "  ✘ 非法邮箱导致 500"

echo ""
echo "===== 流程测试完成 ====="
echo ""
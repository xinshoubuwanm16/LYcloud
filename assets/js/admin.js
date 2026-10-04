/* ============================================================
   LY云计算 - 后台脚本
   ============================================================ */
(function () {
    'use strict';

    /* ---------- 全选 ---------- */
    var checkAll = document.getElementById('checkAll');
    if (checkAll) {
        checkAll.addEventListener('change', function () {
            var boxes = document.querySelectorAll('input[name="ids[]"]');
            for (var i = 0; i < boxes.length; i++) {
                boxes[i].checked = checkAll.checked;
            }
        });
    }

    /* ---------- 危险操作二次确认 ---------- */
    document.addEventListener('submit', function (ev) {
        var form = ev.target;
        if (!form.classList || !form.classList.contains('js-confirm')) return;

        // 提交按钮可携带自己的文案（data-confirm），支持 %n 占位（如券张数）
        var msg = form.dataset.confirm || '确定执行该操作吗？';
        var submitter = ev.submitter || null;
        if (submitter && submitter.dataset && submitter.dataset.confirm) {
            msg = submitter.dataset.confirm;
            if (submitter.dataset.confirmN !== undefined) {
                msg = msg.replace('%n', submitter.dataset.confirmN);
            }
        }
        if (!window.confirm(msg)) {
            ev.preventDefault();
        }
    });

    /* ---------- 生成密钥对（前端本地生成提示，实际由服务端处理时需后端支持） ---------- */
    var genBtn = document.getElementById('btnGenKey');
    if (genBtn) {
        genBtn.addEventListener('click', function () {
            var el = document.getElementById('keyResult');
            el.className = 'test-result show';
            el.innerHTML = '密钥对生成需要服务端 openssl 支持。请在服务器上执行以下命令生成 RSA2 密钥对：<br><br>'
                + '<code>openssl genrsa -out app_private_key.pem 2048</code><br>'
                + '<code>openssl rsa -in app_private_key.pem -pubout -out app_public_key.pem</code><br><br>'
                + '将 <b>app_private_key.pem</b> 的全部内容填入「应用私钥」，'
                + '把 <b>app_public_key.pem</b> 的内容填入支付宝开放平台「应用公钥」，'
                + '保存后把支付宝生成的「支付宝公钥」复制回来填入本页。';
        });
    }
})();

/* ============================================================
   v1.5.1 移动端表格卡片化：把表头列名写入每个单元格的 data-label，
   供 768px 以下卡片化布局显示「列名：值」
   ============================================================ */
(function () {
    'use strict';
    function labelize() {
        var tables = document.querySelectorAll('table.table');
        for (var t = 0; t < tables.length; t++) {
            var ths = tables[t].querySelectorAll('thead th');
            var rows = tables[t].querySelectorAll('tbody tr');
            for (var r = 0; r < rows.length; r++) {
                var tds = rows[r].querySelectorAll('td');
                for (var i = 0; i < tds.length; i++) {
                    var td = tds[i];
                    if (td.colSpan > 1 || td.hasAttribute('data-label')) continue;
                    var th = ths[i];
                    var label = th ? th.textContent.replace(/\s+/g, ' ').trim() : '';
                    if (label) td.setAttribute('data-label', label);
                }
            }
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', labelize);
    } else {
        labelize();
    }
})();

/* ============================================================
   LY云计算 - 前台脚本
   ============================================================ */
(function () {
    'use strict';

    /* ---------- 复制到剪贴板（带降级） ---------- */
    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function (resolve, reject) {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            try {
                document.execCommand('copy') ? resolve() : reject(new Error('copy failed'));
            } catch (e) {
                reject(e);
            } finally {
                document.body.removeChild(ta);
            }
        });
    }

    function toast(msg, type) {
        var el = document.createElement('div');
        el.className = 'flash flash-' + (type || 'success');
        el.style.cssText = 'position:fixed;top:24px;left:50%;transform:translateX(-50%);z-index:9999;' +
                           'box-shadow:0 8px 30px rgba(0,0,0,.5);min-width:200px;text-align:center;';
        el.textContent = msg;
        document.body.appendChild(el);
        setTimeout(function () {
            el.style.transition = 'opacity .3s, transform .3s';
            el.style.opacity = '0';
            el.style.transform = 'translateX(-50%) translateY(-10px)';
            setTimeout(function () { document.body.removeChild(el); }, 320);
        }, 1900);
    }

    /* ---------- 复制按钮 ---------- */
    document.addEventListener('click', function (ev) {
        /* ---------- 直接复制按钮上的文本（用于兑换码列表等无 input 的场景） ---------- */
        var textBtn = ev.target.closest('[data-copy-text]');
        if (textBtn) {
            copyText(textBtn.dataset.copyText).then(function () {
                var old = textBtn.textContent;
                textBtn.textContent = '已复制';
                toast('已复制到剪贴板');
                setTimeout(function () { textBtn.textContent = old; }, 1500);
            }).catch(function () {
                toast('复制失败，请手动选中复制', 'danger');
            });
            return;
        }

        var btn = ev.target.closest('[data-copy-target]');
        if (btn) {
            var input = document.getElementById(btn.dataset.copyTarget);
            if (!input) return;
            copyText(input.value).then(function () {
                var old = btn.textContent;
                btn.textContent = '已复制';
                toast('已复制到剪贴板');
                setTimeout(function () { btn.textContent = old; }, 1500);
            }).catch(function () {
                input.select();
                toast('请按 Ctrl+C 手动复制', 'warning');
            });
            return;
        }

        /* ---------- 一键复制全部面板信息 ---------- */
        var allBtn = ev.target.closest('#copyAll');
        if (allBtn) {
            var txt = '【面板登录信息】\n'
                + '面板地址：' + allBtn.dataset.url + '\n'
                + '面板账号：' + allBtn.dataset.user + '\n'
                + '面板密码：' + allBtn.dataset.pass;
            copyText(txt).then(function () {
                toast('面板信息已全部复制');
            }).catch(function () {
                toast('复制失败，请手动复制', 'danger');
            });
        }
    });

    /* ---------- 密码显示/隐藏 ---------- */
    document.addEventListener('click', function (ev) {
        var t = ev.target.closest('[data-toggle-pass]');
        if (!t) return;
        var input = document.getElementById(t.dataset.togglePass);
        if (!input) return;
        input.type = input.type === 'password' ? 'text' : 'password';
        t.textContent = input.type === 'password' ? '显示' : '隐藏';
    });

    /* ---------- 确认操作 ---------- */
    document.addEventListener('submit', function (ev) {
        var form = ev.target;
        if (!form.classList || !form.classList.contains('js-confirm')) return;
        var msg = form.dataset.confirm || '确定执行该操作吗？';
        if (!window.confirm(msg)) {
            ev.preventDefault();
        }
    });

    /* ---------- 移动端导航自动收起 ---------- */
    document.addEventListener('click', function (ev) {
        if (document.body.classList.contains('nav-open')) {
            var a = ev.target.closest('.main-nav a');
            if (a) document.body.classList.remove('nav-open');
        }
    });

    /* ---------- 平滑滚动锚点 ---------- */
    document.addEventListener('click', function (ev) {
        var a = ev.target.closest('a[href^="#"]');
        if (!a) return;
        var id = a.getAttribute('href');
        if (id === '#' || id.length < 2) return;
        var target = document.querySelector(id);
        if (target) {
            ev.preventDefault();
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });

    window.LY = { copy: copyText, toast: toast };
})();

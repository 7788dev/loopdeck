/*
 * 每日签到平台（百度贴吧、夸克网盘、天翼云盘、阿里云盘）共用的页面交互。
 * 页面经 pjax 加载时本脚本会重复执行，所有事件都挂在带命名空间的委托上。
 */
(function () {
    'use strict';
    var NS = '.loopdeckCheckin';

    function api(type, act) {
        return '/index/ajax/' + encodeURIComponent(type) + '/' + act;
    }

    function go(url, delay) {
        setTimeout(function () {
            window.location.href = url;
        }, delay || 900);
    }

    function fail() {
        x.notify('请求失败，请稍后重试', 'danger');
    }

    function resetChallenge(form) {
        delete form.dataset.challenge;
        var box = form.querySelector('.js-captcha-box');
        if (box) {
            box.classList.add('d-none');
            box.querySelector('input').value = '';
        }
    }

    // 添加 / 更新账号
    $(document).off('submit' + NS).on('submit' + NS, 'form.js-checkin-form', function (event) {
        event.preventDefault();
        var form = this;
        var data = {};
        var missing = null;
        $(form).find('[data-field]').each(function () {
            // 密码按原样提交，其余字段去掉首尾空白
            var value = this.dataset.field === 'raw' ? this.value : $.trim(this.value);
            if (value === '' && missing === null) {
                missing = this;
            }
            data[this.name] = value;
        });
        if (missing !== null) {
            missing.focus();
            x.notify('请填写' + (missing.dataset.label || '必填项'), 'warning');
            return;
        }
        if (form.dataset.expected) {
            data.expected_user_id = form.dataset.expected;
        }
        if (form.dataset.challenge) {
            var code = $.trim($(form).find('.js-captcha-box input').val());
            if (!/^[A-Za-z0-9]{3,8}$/.test(code)) {
                $(form).find('.js-captcha-box input').focus();
                x.notify('请输入图片中的验证码', 'warning');
                return;
            }
            data.challenge = form.dataset.challenge;
            data.captcha_code = code;
        }
        var button = $(form).find('[type="submit"]').prop('disabled', true);
        x.ajax(api(form.dataset.type, 'add'), data, function (response) {
            button.prop('disabled', false);
            if (response.code === 1) {
                x.notify(response.message, 'success');
                go(response.data && response.data.url ? response.data.url : '/index/console/' + form.dataset.type + '/list');
                return;
            }
            var image = response.data && typeof response.data.captcha === 'string' ? response.data.captcha : '';
            if (response.code === 2 && /^data:image\/(png|jpeg|gif);base64,/.test(image)) {
                form.dataset.challenge = String(response.data.challenge || '');
                var box = form.querySelector('.js-captcha-box');
                box.querySelector('img').src = image;
                box.querySelector('input').value = '';
                box.classList.remove('d-none');
                box.querySelector('input').focus();
                x.notify(response.message, 'warning');
                return;
            }
            resetChallenge(form);
            x.notify(response.message || '保存失败，请稍后重试', 'warning');
        }, function () {
            button.prop('disabled', false);
            fail();
        });
    });

    // 验证码看不清：清除挑战，重新提交时服务端会下发新图片
    $(document).off('click' + NS, '.js-captcha-renew').on('click' + NS, '.js-captcha-renew', function () {
        var form = this.closest('form');
        resetChallenge(form);
        $(form).trigger('submit');
    });

    // 删除账号
    $(document).off('click' + NS, '.js-checkin-delete').on('click' + NS, '.js-checkin-delete', function () {
        var type = this.dataset.type;
        var userId = this.dataset.userId;
        layer.confirm('删除后将同时清除该账号的任务与运行日志，确定删除吗？', {btn: ['确定删除', '取消'], closeBtn: 0}, function (index) {
            x.close(index);
            x.ajax(api(type, 'delete'), {user_id: userId}, function (response) {
                x.notify(response.message, response.code === 1 ? 'success' : 'warning');
                if (response.code === 1) {
                    go('/index/console/' + type + '/list');
                }
            }, fail);
        });
    });

    // 任务开关：提交目标状态，重复点击不会把状态反转
    $(document).off('change' + NS, '.js-checkin-toggle').on('change' + NS, '.js-checkin-toggle', function () {
        var input = this;
        var wanted = input.checked;
        input.disabled = true;
        x.ajax(api(input.dataset.type, 'set'), {
            act: 'state', do: input.dataset.task, user_id: input.dataset.userId, enabled: wanted ? '1' : '0'
        }, function (response) {
            input.disabled = false;
            if (response.code === 1) {
                x.notify(response.message, 'success');
                var row = input.closest('tr');
                var label = row ? row.querySelector('.js-task-state') : null;
                if (label) {
                    label.textContent = wanted ? '已开启' : '已暂停';
                    label.className = 'js-task-state badge ' + (wanted ? 'bg-success' : 'bg-secondary');
                }
            } else {
                input.checked = !wanted;
                x.notify(response.message, 'warning');
            }
        }, function () {
            input.disabled = false;
            input.checked = !wanted;
            fail();
        }, false);
    });

    // 挂机时间
    $(document).off('click' + NS, '.js-checkin-timing').on('click' + NS, '.js-checkin-timing', function () {
        var type = this.dataset.type;
        var userId = this.dataset.userId;
        var timing = this.dataset.timing || '';
        layer.open({
            title: '挂机时间配置',
            btn: ['保存', '取消'],
            btnAlign: 'c',
            closeBtn: 0,
            shadeClose: true,
            zIndex: 1000,
            content: '<div class="form-floating mb-2"><input type="text" class="js-flatpickr form-control" id="checkin-timing"'
                + ' placeholder="请选择" data-enable-time="true" data-no-calendar="true" data-date-format="H:i"'
                + ' data-time_24hr="true" data-allow-input="true"><label class="form-label" for="checkin-timing">挂机时间</label></div>'
                + '<div class="fs-sm"><span class="text-danger">未设置时不会自动执行；清空时间并保存可关闭自动挂机。</span><br>'
                + '系统在设定时间之后为您执行签到，排队较多时可能稍有延迟。</div>',
            success: function () {
                $('#checkin-timing').val(timing);
                Codebase.helpersOnLoad(['js-flatpickr']);
            },
            yes: function (index) {
                var value = $.trim($('#checkin-timing').val());
                layer.close(index);
                x.ajax(api(type, 'set'), {act: 'timing', user_id: userId, timing: value}, function (response) {
                    x.notify(response.message, response.code === 1 ? 'success' : 'warning');
                    if (response.code === 1) {
                        go(window.location.pathname);
                    }
                }, fail);
            }
        });
    });

    // 申请补挂
    $(document).off('click' + NS, '.js-checkin-reexecute').on('click' + NS, '.js-checkin-reexecute', function () {
        var type = this.dataset.type;
        var userId = this.dataset.userId;
        layer.confirm('确定立即重新执行今日签到吗？', {btn: ['确定', '取消'], closeBtn: 0}, function (index) {
            x.close(index);
            x.ajax(api(type, 'reExecute'), {user_id: userId}, function (response) {
                if (response.code === 1) {
                    x.btn(response.message);
                } else {
                    x.notify(response.message, 'warning');
                }
            }, fail);
        });
    });

    // 复制页面上的获取脚本
    $(document).off('click' + NS, '.js-copy-snippet').on('click' + NS, '.js-copy-snippet', function () {
        var target = document.querySelector(this.dataset.target);
        if (!target) {
            return;
        }
        var text = target.textContent;
        var done = function () {
            x.notify('已复制到剪贴板', 'success');
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done, function () {
                x.notify('复制失败，请手动选择复制', 'warning');
            });
            return;
        }
        var range = document.createRange();
        range.selectNodeContents(target);
        var selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
        try {
            document.execCommand('copy');
            done();
        } catch (error) {
            x.notify('复制失败，请手动选择复制', 'warning');
        }
        selection.removeAllRanges();
    });
})();

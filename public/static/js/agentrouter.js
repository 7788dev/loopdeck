(function ($) {
    'use strict';

    function syncProxy(fieldset) {
        var root = $(fieldset);
        var custom = root.find('[name="proxy_mode"]:checked').val() === 'custom';
        var input = root.find('[name="proxy_url"]');
        root.find('[data-agentrouter-custom]').prop('hidden', !custom);
        root.find('[data-agentrouter-platform-help]').prop('hidden', custom).toggle(!custom);
        input.prop('disabled', !custom).prop('required', custom && input.attr('data-proxy-saved') !== '1');
    }

    function send(action, data, button) {
        if (button.prop('disabled')) return;
        var label = button.text();
        button.prop('disabled', true).text(button.attr('data-busy-text') || '正在处理…');
        $.ajax({url: '/index/ajax/agentrouter/' + action, type: 'POST', data: data, dataType: 'json', timeout: 110000})
            .done(function (result) {
                x.notify(result.message, result.code === 1 ? 'success' : 'warning');
                if (result.code === 1) {
                    window.location.href = action === 'delete' ? '/index/console/agentrouter/list'
                        : (action === 'save' ? result.data.url : window.location.pathname);
                }
            })
            .fail(function () { x.notify('请求未完成，请稍后重试', 'warning'); })
            .always(function () { button.prop('disabled', false).text(label); });
    }

    function setTiming(button, userId) {
        layer.open({
            title: '挂机时间配置', btn: ['保存', '取消'], btnAlign: 'c', closeBtn: 0, shadeClose: true,
            area: [Math.min(420, window.innerWidth - 32) + 'px'],
            content: '<div class="form-group"><label for="agentrouter-timing">挂机时间</label>'
                + '<input type="time" id="agentrouter-timing" class="form-control"></div>'
                + '<p class="text-muted">系统会在设定时间后执行签到，排队时可能延迟。清空时间并保存可关闭自动挂机。</p>',
            success: function (dom) { $(dom).find('#agentrouter-timing').val(button.attr('data-timing') || ''); },
            yes: function (index, dom) {
                var timing = $(dom).find('#agentrouter-timing').val();
                layer.close(index);
                send('timing', {user_id: userId, timing: timing}, button);
            }
        });
    }

    $(document).off('.ldAgentrouter')
        .on('change.ldAgentrouter', '[data-agentrouter-proxy] [name="proxy_mode"]', function () {
            syncProxy($(this).closest('[data-agentrouter-proxy]'));
        })
        .on('submit.ldAgentrouter', '[data-agentrouter-form]', function (event) {
            event.preventDefault();
            var form = $(this);
            send(form.attr('data-agentrouter-form'), form.serialize(), form.find('button[type="submit"]'));
        })
        .on('change.ldAgentrouter.toggle', '[data-agentrouter-toggle]', function () {
            x.taskSwitch('/index/ajax/agentrouter/toggle', $(this).attr('data-agentrouter-toggle'),
                $(this).attr('data-user-id'), this, function () { window.location.reload(); });
        })
        .on('click.ldAgentrouter', '[data-agentrouter-action]', function () {
            var button = $(this);
            var action = button.attr('data-agentrouter-action');
            if (action === 'delete' && !window.confirm('删除账号后会停止签到并清理运行日志，确定继续吗？')) return;
            var userId = button.attr('data-user-id') || button.closest('[data-agentrouter-account]').attr('data-agentrouter-account');
            if (action === 'timing') return setTiming(button, userId);
            if (action === 'retry') {
                layer.confirm('你确定申请补挂吗？', {btn: ['确定', '取消'], closeBtn: 0}, function (index) {
                    layer.close(index);
                    send(action, {user_id: userId}, button);
                });
                return;
            }
            send(action, {user_id: userId}, button);
        });
    $('[data-agentrouter-proxy]').each(function () { syncProxy(this); });
})(jQuery);

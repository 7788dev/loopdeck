(function ($) {
    'use strict';

    function sync(form) {
        var kind = form.find('[name="kind"]').val();
        var clear = form.find('[name="clear"]').prop('checked');
        var builtin = kind === 'builtin' || clear;
        var retained = form.attr('data-proxy-saved') === '1' && kind === form.attr('data-saved-kind');
        form.find('[data-anyrouter-builtin]').toggle(builtin);
        form.find('[name="http_sources"], [name="socks5_sources"]').prop('disabled', !builtin);
        form.find('[data-anyrouter-secret]').toggle(!builtin);
        form.find('[name="source"]').prop('disabled', builtin).prop('required', !builtin && !retained);
        form.find('[data-anyrouter-clear]').toggle(kind !== 'builtin');
        form.find('[data-anyrouter-source-label]').text(kind === 'proxy' ? 'HTTP / SOCKS5 代理地址' : '订阅链接');
    }

    $(document).off('.ldAnyrouterAdmin')
        .on('change.ldAnyrouterAdmin', '[data-anyrouter-platform-form] [name="kind"], [data-anyrouter-platform-form] [name="clear"]', function () {
            sync($(this).closest('form'));
        })
        .on('click.ldAnyrouterAdmin', '[data-restore-anyrouter-sources]', function () {
            var form = $(this).closest('form');
            form.find('[name="kind"]').val('builtin');
            form.find('[name="clear"]').prop('checked', false);
            form.find('[name="http_sources"], [name="socks5_sources"]').each(function () {
                $(this).val($(this).attr('data-default-value'));
            });
            sync(form);
            x.notify('已填入项目内置地址，请保存使其生效', 'info');
        })
        .on('submit.ldAnyrouterAdmin', '[data-anyrouter-platform-form]', function (event) {
            event.preventDefault();
            var form = $(this);
            var button = form.find('button[type="submit"]');
            if (button.prop('disabled')) return;
            var label = button.text();
            button.prop('disabled', true).text(button.attr('data-busy-text'));
            $.ajax({url: '/admin/ajax/set/anyrouterProxy', type: 'POST', data: form.serialize(), dataType: 'json', timeout: 20000})
                .done(function (result) {
                    x.notify(result.message, result.code === 1 ? 'success' : 'warning');
                    if (result.code === 1) window.location.reload();
                })
                .fail(function () { x.notify('代理保存未完成，请稍后重试', 'warning'); })
                .always(function () { button.prop('disabled', false).text(label); });
        });
    $('[data-anyrouter-platform-form]').each(function () { sync($(this)); });
})(jQuery);

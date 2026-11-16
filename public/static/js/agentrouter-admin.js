(function ($) {
    'use strict';

    function sync(form) {
        var clear = form.find('[name="clear"]').prop('checked');
        var retained = form.attr('data-proxy-saved') === '1'
            && form.find('[name="kind"]').val() === form.attr('data-saved-kind');
        form.find('[name="source"]').prop('disabled', clear).prop('required', !clear && !retained);
    }

    $(document).off('.ldAgentrouterAdmin')
        .on('change.ldAgentrouterAdmin', '[data-agentrouter-platform-form] [name="kind"], [data-agentrouter-platform-form] [name="clear"]', function () {
            sync($(this).closest('form'));
        })
        .on('submit.ldAgentrouterAdmin', '[data-agentrouter-platform-form]', function (event) {
            event.preventDefault();
            var form = $(this);
            var button = form.find('button[type="submit"]');
            if (button.prop('disabled')) return;
            var label = button.text();
            button.prop('disabled', true).text(button.attr('data-busy-text'));
            $.ajax({url: '/admin/ajax/set/agentrouterProxy', type: 'POST', data: form.serialize(), dataType: 'json', timeout: 20000})
                .done(function (result) {
                    x.notify(result.message, result.code === 1 ? 'success' : 'warning');
                    if (result.code === 1) window.location.reload();
                })
                .fail(function () { x.notify('代理保存未完成，请稍后重试', 'warning'); })
                .always(function () { button.prop('disabled', false).text(label); });
        });
    $('[data-agentrouter-platform-form]').each(function () { sync($(this)); });
})(jQuery);

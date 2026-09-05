(function ($) {
    'use strict';
    var selector = '#notification-settings';
    $(document).off('submit.loopdeckNotifications', selector).on('submit.loopdeckNotifications', selector, function (event) {
        event.preventDefault();
        var form = $(this);
        var data = {};
        form.serializeArray().forEach(function (entry) { data[entry.name] = entry.value; });
        data.enabled = form.find('[name="enabled"]').prop('checked') ? 1 : 0;
        var button = $('#save-notification-settings').prop('disabled', true);
        x.ajax('/index/ajax/user/notification', data, function (result) {
            x.notify(result.message, result.code === 1 ? 'success' : 'warning');
            if (result.code !== 1 || !result.data) return;
            ['bark_token', 'pushplus_token', 'wxpusher_app_token', 'wxpusher_uid'].forEach(function (key) {
                form.find('[name="' + key + '"]').val('').attr('placeholder', result.data[key + '_configured'] ? '已配置，留空保留' : '尚未配置');
                form.find('[name="clear_' + key + '"]').prop('checked', false);
            });
        }).always(function () { button.prop('disabled', false); });
    });
    $(document).off('click.loopdeckNotifications', '[data-notification-test]').on('click.loopdeckNotifications', '[data-notification-test]', function () {
        var button = $(this).prop('disabled', true);
        x.ajax('/index/ajax/user/notificationTest', {channel: button.attr('data-notification-test')}, function (result) {
            x.notify(result.message, result.code === 1 ? 'success' : 'warning');
        }).always(function () { button.prop('disabled', false); });
    });
})(jQuery);

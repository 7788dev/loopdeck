(function () {
    'use strict';
    function refresh(panel) {
        if (panel.dataset.loading === '1') return;
        panel.dataset.loading = '1';
        panel.dataset.stale = '0';
        var button = panel.querySelector('.js-refresh-profile');
        var status = panel.querySelector('.js-profile-status');
        button.disabled = true;
        status.textContent = '正在更新账号信息…';
        $.ajax({
            url: '/index/ajax/profile/refresh', type: 'POST', dataType: 'json', timeout: 45000,
            data: {type: panel.dataset.type, user_id: panel.dataset.userId}
        }).done(function (response) {
            if (response.code === 1 && response.data && typeof response.data.html === 'string') {
                if (document.documentElement.contains(panel)) $(panel).replaceWith(response.data.html);
            } else {
                status.textContent = response.message || '刷新失败，请稍后重试';
            }
        }).fail(function () {
            status.textContent = '暂时无法刷新，已保留现有信息';
        }).always(function () {
            panel.dataset.loading = '0';
            button.disabled = false;
        });
    }
    $(document).off('click.loopdeckProfile', '.js-refresh-profile').on('click.loopdeckProfile', '.js-refresh-profile', function () {
        refresh(this.closest('.account-profile'));
    });
    document.querySelectorAll('.account-profile[data-stale="1"]').forEach(refresh);
})();

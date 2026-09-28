(function (global) {
    'use strict';
    var stopPrevious = null;
    var phases = {starting: '准备检查', versions: '检查版本', mirrors: '选择镜像来源', pull: '下载镜像',
        verify: '校验镜像', restart: '重启应用', health: '检查应用健康', rollback: '恢复原版本'};
    var results = {up_to_date: '已是最新版本', updated: '更新完成', failed: '检查失败',
        rolled_back: '已回滚', disabled: '自动更新未开启', waiting: '等待更新器'};

    function mount(panel, initial, checkUrl, statusUrl) {
        if (stopPrevious) stopPrevious();
        if (!panel) return;
        var state = initial, stopped = false, timer, ticker, requestController;
        var posting = false, optimistic = false, failures = 0, received = Date.now(), checkedBefore;
        var button = panel.querySelector('#updater-check-button');
        function el(id) { return panel.querySelector('#updater-' + id); }
        function text(id, value) { el(id).textContent = value == null ? '' : String(value); }
        function active() { return !stopped && document.body.contains(panel); }
        function busy() { return posting || optimistic || !!state.check_requested_at || state.status === 'checking'; }
        function time(value) {
            if (!value) return '尚未检查';
            var date = new Date(value);
            return isNaN(date.getTime()) ? value : date.toLocaleString();
        }
        function render() {
            if (!active()) return stop();
            var seconds = Math.floor((Date.now() - received) / 1000);
            var cooldown = Math.max(0, Number(state.cooldown_seconds || 0) - seconds);
            var checking = state.status === 'checking';
            var pending = optimistic || !!state.check_requested_at;
            var title = posting ? '正在提交检查请求' : checking ? (phases[state.phase] || '正在检查更新')
                : pending ? (cooldown ? '等待检查冷却' : '等待更新器响应') : (results[state.status] || '等待检查');
            text('live-title', title);
            text('status', title);
            text('button-label', posting ? '正在提交…' : busy() ? '检查进行中…' : '立即检查');
            button.disabled = busy() || !state.manual_check_available;
            el('check-icon').className = 'fa fa-sync-alt me-1' + (busy() ? ' fa-spin' : '');
            el('activity').className = 'progress-bar bg-info' + (busy() ? ' progress-bar-striped progress-bar-animated' : '');
            el('activity').style.width = '100%';
            el('activity-track').hidden = !busy();
            var detail = state.message || '检查过程会实时显示，无需刷新页面。';
            if (posting) detail = '正在通知更新器，请稍候。';
            else if (checking) {
                if (state.probe_total > 0) detail += '（已响应 ' + state.probe_completed + '/' + state.probe_total + ' 个来源）';
                if (state.phase === 'pull') detail += '（第 ' + state.mirror_attempt + '/' + state.mirror_total + ' 个来源）';
            } else if (pending) detail = cooldown ? '已提交检查请求，约 ' + cooldown + ' 秒后开始。两次检查至少间隔 60 秒。'
                : '已提交检查请求，等待更新器接收；本页会自动显示进度。';
            else if (!state.manual_check_available) detail = state.manual_check_hint || detail;
            text('live-detail', detail);
            text('elapsed', checking || state.finished_at ? '本次已用时 ' + (Number(state.elapsed_seconds || 0) + (checking ? seconds : 0)) + ' 秒' : '');
            var warning = state.check_request_stale ? '更新器超过 2 分钟未响应，请检查 updater 容器状态。' : state.error || '';
            if (checking && state.heartbeat_age_seconds != null && Number(state.heartbeat_age_seconds) + seconds > 15) {
                warning = '更新器近期没有报告新进度，仍在等待后续状态；如持续不变，请查看 updater 日志。';
            }
            el('warning').hidden = !warning;
            text('warning', warning);
        }
        function apply(next) {
            state = next;
            received = Date.now();
            failures = 0;
            if (next.check_requested_at || next.status === 'checking' || next.checked_at !== checkedBefore) optimistic = false;
            text('current', 'v' + next.current_version);
            text('latest', next.latest_version ? 'v' + next.latest_version : '等待检查');
            text('checked', time(next.checked_at));
            text('next', next.status === 'checking' ? '本次完成后安排' : time(next.next_check_at));
            text('source', next.version_source || '正在确认');
            text('image', next.image_repository || '尚未选择');
            text('result', next.message);
            text('connection', '实时连接正常 · ' + new Date().toLocaleTimeString());
            render();
        }
        function request(url) {
            requestController = new AbortController();
            var controller = requestController;
            var timeout = setTimeout(function () { controller.abort(); }, 8000);
            return fetch(url, {method: 'POST', credentials: 'same-origin', cache: 'no-store',
                headers: {'X-Requested-With': 'XMLHttpRequest'}, signal: controller.signal})
                .then(function (response) {
                    if (!response.ok) throw new Error('HTTP ' + response.status);
                    return response.json();
                }).finally(function () { clearTimeout(timeout); });
        }
        function poll() {
            if (!active()) return stop();
            request(statusUrl).then(function (response) {
                if (!active()) return;
                if (response.code !== 1 || !response.data) throw new Error('状态不可用');
                apply(response.data);
            }).catch(function () {
                if (!active()) return;
                failures++;
                text('connection', '暂时无法连接，正在自动重连（' + failures + ' 次）。更新重启期间可能短暂断开，无需重复提交。');
            }).finally(function () {
                if (active() && !posting) timer = setTimeout(poll, failures ? Math.min(10000, failures * 2000) : busy() ? 1000 : 10000);
            });
        }
        function check() {
            if (busy() || !state.manual_check_available) return;
            clearTimeout(timer);
            if (requestController) requestController.abort();
            posting = true;
            checkedBefore = state.checked_at;
            render();
            request(checkUrl).then(function (response) {
                if (!active()) return;
                optimistic = response.code === 1;
                if (!optimistic) text('connection', response.message || '检查请求未被接受，请稍后重试。');
            }).catch(function () {
                if (!active()) return;
                text('connection', '提交响应暂未收到，正在查询是否已受理；请勿连续点击。');
            }).finally(function () {
                posting = false;
                render();
                clearTimeout(timer);
                if (active()) timer = setTimeout(poll, 0);
            });
        }
        function stop() {
            stopped = true;
            clearTimeout(timer);
            clearInterval(ticker);
            if (requestController) requestController.abort();
            button.removeEventListener('click', check);
        }
        stopPrevious = stop;
        button.addEventListener('click', check);
        render();
        ticker = setInterval(render, 1000);
        timer = setTimeout(poll, 0);
        return stop;
    }
    global.LoopDeckUpdater = {mount: mount};
})(window);

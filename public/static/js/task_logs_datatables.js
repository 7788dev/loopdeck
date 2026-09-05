!function () {
    $.extend($.fn.dataTable.ext.classes, {
        sWrapper: "dataTables_wrapper dt-bootstrap5",
        sFilterInput: "form-control",
        sLengthSelect: "form-select",
    }), $.extend(!0, $.fn.dataTable.defaults, {
        language: {
            lengthMenu: "_MENU_",
            search: "_INPUT_",
            searchPlaceholder: "输入关键词进行搜索",
            info: "<span class='text-muted fs-sm'>共有 _TOTAL_ 条 / _PAGES_ 页</span>",
            paginate: {
                first: '<i class="fa fa-angle-double-left"></i>',
                previous: '<i class="fa fa-angle-left"></i>',
                next: '<i class="fa fa-angle-right"></i>',
                last: '<i class="fa fa-angle-double-right"></i>'
            }
        },
    })

    // 运行日志的响应文本以 [成功]/[重试中]/[失败] 开头（由定时任务控制器写入），
    // 列表里只显示状态徽章，完整响应放在隐藏列中，点击行首 ➕ 展开。
    var STATUS_STYLES = {
        "成功": "bg-success",
        "重试中": "bg-warning",
        "失败": "bg-danger"
    };

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (ch) {
            return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[ch];
        });
    }

    function statusOf(response) {
        var match = /^\[(成功|重试中|失败)\]/.exec(String(response || ""));
        return match ? match[1] : "";
    }

    function detailOf(response) {
        return String(response || "").replace(/^\[(成功|重试中|失败)\]\s*/, "");
    }

    function renderStatus(response, type) {
        if (type !== "display") {
            return statusOf(response);
        }
        var status = statusOf(response);
        return status
            ? '<span class="badge ' + STATUS_STYLES[status] + '">' + status + "</span>"
            : '<span class="badge bg-secondary">详情</span>';
    }

    function renderTime(value, type) {
        return type === "display" ? escapeHtml(String(value || "").slice(0, 16)) : String(value || "");
    }

    function renderDetail(response, type) {
        return type === "display" ? escapeHtml(detailOf(response)) : detailOf(response);
    }

    var table = $("#task-logs-list");
    table.DataTable({
        ajax: {
            "url": "/index/ajax/"+ table.data("type") +"/logs",
            "dataType": "json",
            "type": "post",
            "data": {
                user_id: table.data("user_id"),
            },
            "dataSrc": function (rows) {
                return Array.isArray(rows) ? rows : [];
            },
        },
        columns: [
            {"title": "ID", "data": "id"},
            {"title": "任务名称", "data": "do", "render": x.renderText},
            {"title": "任务响应", "data": "response", "render": renderStatus},
            {"title": "时间", "data": "addtime", "render": renderTime},
            {"title": "响应详情", "data": "response", "className": "none", "render": renderDetail}
        ],
        ordering: false,
        pagingType: "full_numbers",
        pageLength: 10,
        lengthMenu: [[10, 20, 50], [10, 20, 50]],
        autoWidth: !1,
        responsive: !0
    })
    table.parent().addClass("table-responsive");
}()

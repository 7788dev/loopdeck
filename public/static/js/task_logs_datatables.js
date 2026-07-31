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
    var table = $("#task-logs-list");
    table.DataTable({
        ajax: {
            "url": "/index/ajax/"+ table.data("type") +"/logs",
            "dataType": "json",
            "type": "post",
            "data": {
                user_id: table.data("user_id"),
            },
            "dataSrc": "",
        },
        columns: [
            {"title": "ID", "data": "id"},
            {"title": "任务名称", "data": "do"},
            {"title": "任务响应", "data": "response"},
            {"title": "运行时间", "data": "addtime"}
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
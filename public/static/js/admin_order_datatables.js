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
    $("#admin-order").DataTable({
        ajax: {
            "url": "/admin/ajax/pay/order",
            "dataType": "json",
            "type": "get",
            "dataSrc": "",
        },
        columns: [
            {"title": "订单号", "data": "trade_no"},
            {"title": "UID", "data": "uid"},
            {"title": "名称", "data": "name"},
            {"title": "金额", "data": "money", "render": function (data, type, row, meta) {
                    if (row.type == 'alipay') {
                        return "￥" + data + "<span class='text-primary'> [支付宝]</span>";
                    } else if (row.type == 'wxpay') {
                        return "￥" + data + "<span class='text-primary'> [微信支付]</span>";
                    } else if (row.type == 'qqpay') {
                        return "￥" + data + "<span class='text-primary'> [QQ支付]</span>";
                    }
                }},
            {"title": "时间", "data": "time"},
            {"title": "状态", "data": "status", "render": function (data, type, row, meta) {
                    if (row.status == 0) {
                        return "<span class=\"badge bg-danger\">未支付</span>";
                    } else {
                        return "<span class=\"badge bg-success\">已支付</span>";
                    }
                }},
        ],
        pagingType: "full_numbers",
        pageLength: 10,
        lengthMenu: [[10, 20, 50, 100], [10, 20, 50, 100]],
        autoWidth: !1,
        responsive: !0,
        ordering: 0,
    });
}()
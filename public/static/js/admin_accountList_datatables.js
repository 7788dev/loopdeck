!function () {
    $.extend($.fn.dataTable.ext.classes, {
        sWrapper: "dataTables_wrapper dt-bootstrap5", sFilterInput: "form-control", sLengthSelect: "form-select",
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
            },
        },
        ordering: false,
    })
    $("#accounts-list").DataTable({
        ajax: function (data, callback, settings) {
            var param = {};
            param.length = data.length;//页面显示记录条数，在页面显示每页显示多少项的时候
            param.start = data.start;//开始的记录序号
            param.page = (data.start / data.length) + 1;//当前页码

            param.search = {
                uid: x.getval('#search-uid'),
                user_id: x.getval('#search-user_id'),
                status: $('#search-status').val()
            };

            $.ajax({
                type: "POST", url: "/admin/ajax/data/list/accounts", cache: false, //禁用缓存
                data: param, //传入组装的参数
                dataType: "json", success: function (result) {
                    //封装返回数据
                    var returnData = {};
                    returnData.draw = result.draw;
                    returnData.recordsTotal = result.total;
                    returnData.recordsFiltered = result.total;
                    returnData.data = result.data;
                    callback(returnData)
                },
            });
        },
        columns: [{"title": "ID", "data": "id"}, {"title": "用户UID", "data": "uid"}, {
            "title": "类型",
            "data": "type",
            "className": ""
        }, {"title": "账号ID", "data": "user_id", "className": ""}, {
            "title": "添加时间",
            "data": "addtime",
            "className": ""
        }, {"title": "添加站点", "data": "zid", "className": ""}, {
            "title": "状态", "data": "state", "className": "", "render": function (data, type, row, meta) {
                if (data == 1) {
                    return "<span class=\"badge bg-success\">正常</span>";
                } else {
                    return "<span class=\"badge bg-danger\">失效</span>";
                }
            }
        }, {
            "title": "操作",
            "data": "id",
            "className": "",
            "sortable": false,
            "render": function (data, type, row, meta) {
                return "<button type=\"button\"class=\"btn btn-sm btn-alt-danger\"data-bs-toggle=\"tooltip\" onclick=\"ajax_account_delete('" + row.user_id + "');\"><i class=\"fa fa-trash-alt\"></i></button>";
            }
        },],
        pagingType: "simple_numbers",
        pageLength: 10,
        lengthMenu: [[10, 20, 50, 100], [10, 20, 50, 100]],
        autoWidth: !1,
        responsive: !0,
        serverSide: true,
        searching: false,
        bLengthChange: false,
        bProcessing: true,
    })

}()

function table_search() {
    let table = $("#accounts-list").dataTable();
    table.fnDraw();
}

function ajax_account_delete(id) {
    x.del('/admin/ajax/data/delete/account', {id: id}, function (data) {
        if (data.code == 1) {
            var table = $('#accounts-list').DataTable();
            table.ajax.reload();
            x.notify(data.message, 'success');
        } else {
            x.notify(data.message, 'warning');
        }
    })
}
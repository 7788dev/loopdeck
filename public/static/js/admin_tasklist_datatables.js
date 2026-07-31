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
            info: "<span class='text-muted '>共有 _TOTAL_ 条 / _PAGES_ 页</span>",
            paginate: {
                first: '<i class="fa fa-angle-double-left"></i>',
                previous: '<i class="fa fa-angle-left"></i>',
                next: '<i class="fa fa-angle-right"></i>',
                last: '<i class="fa fa-angle-double-right"></i>'
            }
        },
    })
    $("#admin-taskList").DataTable({
        ajax: {
            "url": "/admin/ajax/task/list",
            "dataType": "json",
            "type": "post",
            "dataSrc": "",
        },
        columns: [
            {"title": "ID", "data": "id", "className": "fs-sm",},
            {"title": "类型", "data": "type", "className": "fs-sm",},
            {"title": "名称", "data": "name", "className": "fs-sm", "sortable": false},
            {"title": "描述", "data": "describe", "className": "fs-sm", "sortable": false},
            {"title": "图标", "data": "icon", "className": "fs-sm", "sortable": false,"render": function (data, type, row, meta) {
                    return "<i class='" + data + " me-2'></i>" + data;
                }},
            {"title": "执行方法", "data": "execute_name", "className": "fs-sm", "sortable": false},
            {"title": "执行频率", "data": "execute_rate", "className": "fs-sm", "sortable": false},
            {"title": "拓展配置", "data": "more", "className": "fs-sm", "sortable": false, "render": function (data, type, row, meta) {
                    return data
                }},
            {"title": "VIP", "data": "vip", "className": "fs-sm", "sortable": false},
            {"title": "状态","data": "state", "className": "fs-sm"},
            {"title": "操作", "data": "id", "sortable": false, "render": function (data, type, row, meta) {
                    return "<button type=\"button\"class=\"btn btn-sm btn-alt-info me-1\"data-bs-toggle=\"tooltip\" onclick=\"ajax_edit_task('" + row.id + "');\"><i class=\"fa fa-edit\"></i></button><button type=\"button\"class=\"btn btn-sm btn-alt-danger\"data-bs-toggle=\"tooltip\" onclick=\"ajax_del_task('" + row.id + "');\"><i class=\"fa fa-trash-alt\"></i></button>";
                }}
        ],
        pagingType: "full_numbers",
        pageLength: 10,
        lengthMenu: [[10, 20, 50, 100], [10, 20, 50, 100]],
        autoWidth: !1,
        responsive: !0
    })
}()

function ajax_edit_task(id)
{
    layer.open({
        title: "任务配置",
        btn: ['保存', '取消'],
        btnAlign: 'c',
        closeBtn: 0,
        shadeClose: true,
        content: '<form id="info-form"><div class="row"><div class="col-md-6"><div class="form-floating mb-4"><div class="form-floating mb-4"><input class="form-control"id="type"name="type"placeholder="."type="text"><label class="form-label"for="type">任务类型</label></div></div></div><div class="col-md-6"><div class="form-floating mb-4"><input class="form-control"id="name"name="name"placeholder="."type="text"><label class="form-label"for="name">任务名称</label></div></div></div><div class="row"><div class="col-md-12"><div class="form-floating mb-4"><div class="form-floating mb-4"><textarea class="form-control"id="describe"name="describe"placeholder="write something"style="height: 100px"></textarea><label class="form-label"for="describe">任务描述</label></div></div></div></div><div class="row"><div class="col-md-4"><div class="form-floating mb-4"><div class="form-floating mb-4"><input class="form-control"id="icon"name="icon"placeholder="."type="text"><label class="form-label"for="icon">任务图标</label></div></div></div><div class="col-md-4"><div class="form-floating mb-4"><input class="form-control"id="execute_name"name="execute_name"placeholder="."type="text"><label class="form-label"for="execute_name">执行方法</label></div></div><div class="col-md-4"><div class="form-floating mb-4"><input class="form-control"id="execute_rate"name="execute_rate"placeholder="."type="text"><label class="form-label"for="execute_rate">执行频率</label></div></div></div><div class="row"><div class="col-md-6"><div class="form-floating mb-4"><input class="form-control"id="more"name="more"placeholder="."type="text"><label class="form-label"for="more">拓展配置</label></div></div><div class="col-md-6"><div class="form-floating mb-4"><select aria-label="vip"class="form-select"id="vip"name="vip"><option value="0">否</option><option value="1">是</option></select><label class="form-label"for="more">VIP功能</label></div></div></div><div class="row"><div class="col-md-6"><div class="form-floating mb-4"><select aria-label="状态"class="form-select"id="state"name="state"><option value="0">关闭</option><option value="1">开启</option></select><label class="form-label"for="state">状态</label></div></div><div class="col-md-6"><div class="form-floating mb-4"><input class="form-control"disabled id="time"name="time"placeholder="."type="text"><label class="form-label"for="type">添加时间</label></div></div></div></form>',
        success: function (res, index) {
            x.ajax('/admin/ajax/task/getInfo', {id: id}, function (data) {
                var data = JSON.parse(data);
                $("#type").val(data.type);
                $("#name").val(data.name);
                $("#describe").val(data.describe);
                $("#icon").val(data.icon);
                $("#execute_name").val(data.execute_name);
                $("#execute_rate").val(data.execute_rate);
                $("#more").val(data.more);
                $("#vip").val(data.vip);
                $("#state").val(data.state);
                $("#time").val(data.time);
            })
        },
        yes: function (index, dom) {
            layer.close(index);
            var loading = layer.load(2);
            const params = JSON.stringify($(dom).find("form").parseForm());
            x.ajax('/admin/ajax/task/set', 'id=' + id + '&' + $('#info-form').serialize(), function (data) {
                if (data.code == 1) {
                    x.close(loading);
                    var table = $("#admin-taskList").DataTable();
                    table.ajax.reload();
                    x.notify(data.message, 'success');
                } else {
                    x.close(loading);
                    x.notify(data.message, 'warning');
                }
            })
        },
    });
}

function ajax_del_task(id)
{
    layer.confirm('你确定删除任务吗?', {
        btn: ['确定', '取消'],
        closeBtn: 0,
    }, function () {
        x.ajax("/admin/ajax/task/delete", {id: id}, function (data) {
            if (data.code === 1) {
                layer.closeAll();
                var table = $("#admin-taskList").DataTable();
                table.ajax.reload();
                x.notify(data.message, 'success');
            } else {
                x.notify(data.message, 'warning');
            }
        });
    });
}

function ajax_add_task()
{
    layer.open({
        title: "新增任务",
        btn: ['保存', '取消'],
        btnAlign: 'c',
        closeBtn: 0,
        shadeClose: true,
        content: '<form id="add-form"><div class="row"><div class="col-md-6"><div class="form-floating mb-4"><div class="form-floating mb-4"><input class="form-control"id="type"name="type"placeholder="."type="text"><label class="form-label"for="type">任务类型</label></div></div></div><div class="col-md-6"><div class="form-floating mb-4"><input class="form-control"id="name"name="name"placeholder="."type="text"><label class="form-label"for="name">任务名称</label></div></div></div><div class="row"><div class="col-md-12"><div class="form-floating mb-4"><div class="form-floating mb-4"><textarea class="form-control"id="describe"name="describe"placeholder="write something"style="height: 100px"></textarea><label class="form-label"for="describe">任务描述</label></div></div></div></div><div class="row"><div class="col-md-4"><div class="form-floating mb-4"><div class="form-floating mb-4"><input class="form-control"id="icon"name="icon"placeholder="."type="text"><label class="form-label"for="icon">任务图标</label></div></div></div><div class="col-md-4"><div class="form-floating mb-4"><input class="form-control"id="execute_name"name="execute_name"placeholder="."type="text"><label class="form-label"for="execute_name">执行方法</label></div></div><div class="col-md-4"><div class="form-floating mb-4"><input class="form-control"id="execute_rate"name="execute_rate"placeholder="."type="text"><label class="form-label"for="execute_rate">执行频率</label></div></div></div><div class="row"><div class="col-md-6"><div class="form-floating mb-4"><input class="form-control"id="more"name="more"placeholder="."type="text"><label class="form-label"for="more">拓展配置</label></div></div><div class="col-md-6"><div class="form-floating mb-4"><select aria-label="vip"class="form-select"id="vip"name="vip"><option value="0">否</option><option value="1">是</option></select><label class="form-label"for="vip">VIP功能</label></div></div></div></form>',
        yes: function (index, dom) {
            x.ajax('/admin/ajax/task/add', $('#add-form').serialize(), function (data) {
                if (data.code == 1) {
                    var table = $("#admin-taskList").DataTable();
                    table.ajax.reload();
                    layer.msg(data.message);
                } else {
                    layer.msg(data.message);
                }
            })
        },
    });
}

function ajax_refresh_task()
{
    layer.confirm('你确定刷新所有任务吗?', {
        btn: ['确定', '取消'],
        closeBtn: 0,
    }, function () {
        x.ajax("/admin/ajax/task/refresh", null, function (data) {
            if (data.code === 1) {
                layer.closeAll();
                var table = $("#admin-taskList").DataTable();
                table.ajax.reload();
                x.notify(data.message, 'success');
            } else {
                x.notify(data.message, 'warning');
            }
        });
    });
}

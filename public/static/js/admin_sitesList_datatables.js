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

    $("#sitesList").DataTable({
        ajax: {
            "url": "/admin/ajax/data/list/sites",
            "dataType": "json",
            "type": "post",
            "dataSrc": "",
        },
        columns: [
            {"title": "站点编号", "data": "web_id", "className": "fs-sm",},
            {"title": "用户ID", "data": "user_id", "className": "fs-sm",},
            {"title": "联系QQ", "data": "user_qq", "className": "fs-sm", "sortable": false},
            {"title": "网站名称", "data": "webname", "className": "fs-sm", "sortable": false},
            {"title": "到期时间", "data": "end_time", "className": "fs-sm", "sortable": false},
            {
                "title": "运营状态",
                "data": "status",
                "className": "fs-sm",
                "sortable": false,
                "render": function (data, type, row, meta) {
                    return formartState(data);
                }
            },
            {
                "title": "操作", "data": "web_id", "sortable": false, "render": function (data, type, row, meta) {
                    return "<button type=\"button\"class=\"btn btn-sm btn-alt-primary me-1\"data-bs-toggle=\"tooltip\" onclick=\"ajax_sync_site('" + row.web_id + "');\">更新数据</button><button type=\"button\"class=\"btn btn-sm btn-alt-info me-1\"data-bs-toggle=\"tooltip\" onclick=\"ajax_edit_site('" + row.web_id + "');\"><i class=\"fa fa-edit\"></i></button><button type=\"button\"class=\"btn btn-sm btn-alt-danger\"data-bs-toggle=\"tooltip\" onclick=\"ajax_del_site('" + row.web_id + "');\"><i class=\"fa fa-trash-alt\"></i></button>";
                }
            }
        ],
        pagingType: "full_numbers",
        pageLength: 10,
        lengthMenu: [[10, 20, 50, 100], [10, 20, 50, 100]],
        autoWidth: !1,
        responsive: !0
    })
}()


function formartState(data) {
    if (data == 1) {
        return "正常";
    } else if (data == 0) {
        return "封禁";
    } else {
        return "未知";
    }
}

function ajax_del_site(id) {
    x.del('/admin/ajax/data/delete/site', {id: id}, function (data) {
        if (data.code == 1) {
            var table = $('#sitesList').DataTable();
            table.ajax.reload();
            x.notify(data.message, 'success');
        } else {
            x.notify(data.message, 'warning');
        }
    })
}

function ajax_edit_site(id)
{
    layer.open({
        title: "分站编辑[ID："+id+"]",
        btn: ['保存', '取消'],
        btnAlign: 'c',
        closeBtn: 0,
        shadeClose: true,
        content: '<form id="info-form"><div class="row"><div class="col-md-6"><div class="form-floating mb-4"><div class="form-floating mb-4"><input class="form-control"id="user_id"name="user_id"placeholder="."type="number"/><label class="form-label"for="user_id">开通UID</label></div></div></div><div class="col-md-6"><div class="form-floating mb-4"><input class="form-control"id="domain"name="domain"placeholder="."type="text"/><label class="form-label"for="domain">网站域名</label></div></div></div><div class="row"><div class="col-md-6"><div class="form-floating mb-4"><div class="form-floating mb-4"><input class="form-control"id="webname"name="webname"placeholder="."type="text"/><label class="form-label"for="webname">网站名称</label></div></div></div><div class="col-md-6"><div class="form-floating mb-4"><input class="form-control"id="user_qq"name="user_qq"placeholder="."type="text"/><label class="form-label"for="user_qq">站长QQ</label></div></div></div><div class="row"><div class="col-md-6"><div class="form-floating mb-4"><input class="form-control"id="mail"name="mail"placeholder="."type="mail"/><label class="form-label"for="mail">联系邮箱</label></div></div><div class="col-md-6"><div class="form-floating mb-4"><input class="form-control"id="end_time"name="end_time"placeholder="."type="date"/><label class="form-label"for="end_time">到期时间</label></div></div></div><div class="row"><div class="col-md-12"><div class="form-floating mb-4"><select aria-label="状态"class="form-select"id="status"name="status"><option value="0">封禁</option><option value="1">激活</option></select><label class="form-label"for="status">运营状态</label></div></div></div></form>',
        success: function (res, index) {
            x.ajax('/admin/ajax/data/info/site', {id: id}, function (data) {
                var data = JSON.parse(data);
                $("#user_id").val(data.user_id);
                $("#domain").val(data.domain);
                $("#webname").val(data.webname);
                $("#user_qq").val(data.user_qq);
                $("#mail").val(data.mail);
                $("#end_time").val(data.end_time);
                $("#status").val(data.status);
            })
        },
        yes: function (index, dom) {
            layer.close(index);
            var loading = layer.load(2);
            const params = JSON.stringify($(dom).find("form").parseForm());
            x.ajax('/admin/ajax/data/set/site', 'web_id=' + id + '&' + $('#info-form').serialize(), function (data) {
                if (data.code == 1) {
                    x.close(loading);
                    var table = $("#sitesList").DataTable();
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

function ajax_sync_site(web_id) {
    layer.confirm('你确定更新该站点数据吗?', {
        btn: ['确定', '取消'],
        closeBtn: 0,
    }, function () {
        x.ajax("/admin/ajax/data/set/syncSite", {web_id: web_id}, function (data) {
            if (data.code === 1) {
                x.btn(data.message);
            } else {
                x.notify(data.message);
            }
        });
    });
}

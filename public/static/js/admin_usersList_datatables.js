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
            },
        },
        ordering: false,
    })

    $("#usersList").DataTable({
        ajax: function (data, callback, settings) {
            var param = {};
            param.length = data.length;//页面显示记录条数，在页面显示每页显示多少项的时候
            param.start = data.start;//开始的记录序号
            param.page = (data.start / data.length) + 1;//当前页码

            param.search = {
                uid: x.getval('#search-uid'),
                username: x.getval('#search-username'),
                nickname: x.getval('#search-nickname'),
                qq: x.getval('#search-qq'),
                status: $('#search-status').val()
            };

            $.ajax({
                type: "POST", url: "/admin/ajax/data/list/users", cache: false, //禁用缓存
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
        columns: [
            {"title": "UID", "data": "uid", "className": "fs-sm",},
            {"title": "昵称", "data": "nickname", "className": "fs-sm",},
            {"title": "用户名", "data": "username", "className": "fs-sm", "sortable": false},
            {"title": "余额", "data": "money", "className": "fs-sm", "sortable": false},
            {
                "title": "代理信息",
                "data": "agent",
                "className": "fs-sm",
                "render": function (data, type, row, meta) {
                    return formartAgent(data);
                }
            },
            {"title": "归属站点", "data": "web_id", "className": "fs-sm",},
            {
                "title": "登录时间",
                "data": "login_time",
                "className": "fs-sm",
                "sortable": false,
                "render": function (data, type, row, meta) {
                    return formartTime(data);
                }
            },
            {
                "title": "状态",
                "data": "state",
                "className": "fs-sm",
                "sortable": false,
                "render": function (data, type, row, meta) {
                    return formartState(data);
                }
            },
            {
                "title": "操作", "data": "uid", "sortable": false, "render": function (data, type, row, meta) {
                    return "<button type=\"button\"class=\"btn btn-sm btn-alt-info me-1\"data-bs-toggle=\"tooltip\" onclick=\"ajax_edit_user('" + row.uid + "');\"><i class=\"fa fa-edit\"></i></button><button type=\"button\"class=\"btn btn-sm btn-alt-danger\"data-bs-toggle=\"tooltip\" onclick=\"ajax_del_user('" + row.uid + "');\"><i class=\"fa fa-trash-alt\"></i></button>";
                }
            }
        ],
        pagingType: "full_numbers",
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
    let table = $("#usersList").dataTable();
    table.fnDraw();
}

function formartAgent(data) {
    if (data == 1) {
        return "银牌代理";
    } else if (data == 2) {
        return "金牌代理";
    } else if (data == 3) {
        return "钻石代理";
    } else {
        return "不是代理";
    }
}

function formartTime(timestamp) {
    var date = new Date(timestamp * 1000);//时间戳为10位需*1000，时间戳为13位的话不需乘1000
    var Y = date.getFullYear() + '-';
    var M = (date.getMonth() + 1 < 10 ? '0' + (date.getMonth() + 1) : date.getMonth() + 1) + '-';
    var D = (date.getDate() < 10 ? '0' + (date.getDate()) : date.getDate()) + ' ';
    var h = (date.getHours() < 10 ? '0' + (date.getHours()) : date.getHours()) + ':';
    var m = (date.getMinutes() < 10 ? '0' + (date.getMinutes()) : date.getMinutes()) + ':';
    var s = (date.getSeconds() < 10 ? '0' + (date.getSeconds()) : date.getSeconds());
    return Y + M + D + h + m + s;
}

function formartState(data) {
    if (data == 1) {
        return "正常";
    } else if (data == 0) {
        return "封禁";
    } else {
        return "未知";
    }
}

function ajax_del_user(id) {
    x.del('/admin/ajax/data/delete/user', {id: id}, function (data) {
        if (data.code == 1) {
            var table = $('#usersList').DataTable();
            table.ajax.reload();;
            x.notify(data.message, 'success');
        } else {
            x.notify(data.message, 'warning');
        }
    })
}

function ajax_edit_user(id)
{
    layer.open({
        title: "用户编辑[UID："+id+"]",
        btn: ['保存', '取消'],
        btnAlign: 'c',
        closeBtn: 0,
        shadeClose: true,
        zIndex: 10000,
        content: '<form id="info-form"><div class="row"><div class="col-md-6"><div class="form-floating mb-4"><div class="form-floating mb-4"><input type="text" class="form-control" id="uid" name="uid"  placeholder="." / disabled><label class="form-label" for="uid">UID</label></div></div></div><div class="col-md-6"><div class="form-floating mb-4"><input type="text" class="form-control" id="username" name="username" placeholder="." / disabled><label class="form-label" for="username">用户名称</label></div></div></div><div class="row"><div class="col-md-12"><div class="form-floating mb-4"><input type="text" class="form-control" id="password" name="password" placeholder="."><label class="form-label" for="password">用户密码(不修改则留空)</label></div></div></div><div class="row"><div class="col-md-6"><div class="form-floating mb-4"><div class="form-floating mb-4"><input type="text" class="form-control" id="qq" name="qq" placeholder="." /><label class="form-label" for="qq">绑定QQ</label></div></div></div><div class="col-md-6"><div class="form-floating mb-4"><input type="text" class="form-control" id="mail" name="mail" placeholder="." /><label class="form-label" for="mail">绑定邮箱</label></div></div></div><div class="row"><div class="col-md-6"><div class="form-floating mb-4"><input type="text" class="form-control" id="money" name="money" placeholder="." /><label class="form-label" for="more">账号余额</label></div></div><div class="col-md-6"><div class="form-floating mb-4"><input type="text" class="form-control" id="quota" name="quota" placeholder="." /><label class="form-label" for="more">账号配额</label></div></div></div><div class="row"><div class="col-md-6"><div class="form-floating mb-4"><input type="text" class="js-flatpickr form-control flatpickr-input" id="vip_start" name="vip_start" placeholder="." / readonly="readonly"><label class="form-label" for="vip_start">会员开始</label></div></div><div class="col-md-6"><div class="form-floating mb-4"><input type="text" class="js-flatpickr form-control flatpickr-input" id="vip_end" name="vip_end" placeholder="." / readonly="readonly"><label class="form-label" for="vip_end">会员结束</label></div></div></div><div class="row"><div class="col-md-6"><div class="form-floating mb-4"><select class="form-select" id="agent" name="agent" aria-label="代理"  default="0"><option value="0"> 不开通</option><option value="1"> 银牌代理</option><option value="2"> 金牌代理</option><option value="3"> 钻石代理</option></select><label class="form-label" for="agent">账号代理</label></div></div><div class="col-md-6"><div class="form-floating mb-4"><select class="form-select" id="state" name="state" aria-label="状态"><option value="0">封禁</option><option value="1">激活</option></select><label class="form-label" for="state">账号状态</label></div></div></div></form>',
        success: function (res, index) {
            x.ajax('/admin/ajax/data/info/user', {id: id}, function (data) {
                var data = JSON.parse(data);
                $("#uid").val(data.uid);
                $("#username").val(data.username);
                $("#password").val();
                $("#qq").val(data.qq);
                $("#mail").val(data.mail);
                $("#money").val(data.money);
                $("#quota").val(data.quota);
                $("#vip_start").val(data.vip_start);
                $("#vip_end").val(data.vip_end);
                $("#agent").val(data.agent);
                $("#state").val(data.state);
                Codebase.helpersOnLoad(['js-flatpickr']);
            })
        },
        yes: function (index, dom) {
            layer.close(index);
            var loading = layer.load(2);
            const params = JSON.stringify($(dom).find("form").parseForm());
            x.ajax('/admin/ajax/data/set/user', 'id=' + id + '&' + $('#info-form').serialize(), function (data) {
                if (data.code == 1) {
                    x.close(loading);
                    var table = $("#usersList").DataTable();
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
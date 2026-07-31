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
        ordering: false,
    })
    $("#kmsList").DataTable({
        ajax: function (data, callback, settings) {
            var param = {};
            param.length = data.length;//页面显示记录条数，在页面显示每页显示多少项的时候
            param.start = data.start;//开始的记录序号
            param.page = (data.start / data.length) + 1;//当前页码

            param.search = {
                km: x.getval('#search-km'),
                type: x.getval('#search-type'),
                status: $('#search-status').val()
            };

            $.ajax({
                type: "POST", url: "/admin/ajax/data/list/kms", cache: false, //禁用缓存
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
            {"title": "ID", "data": "id"},
            {"title": "类型", "data": "type", "className":"", "render": function (data, type, row, meta) {
                    if (data == 'vip') {
                        return "<span class=\"text-corporate\">VIP卡密</span>";
                    } else if (data == 'quota') {
                        return "<span class=\"text-earth\">配额卡密</span>";
                    }else if (data == 'agent') {
                        return "<span class=\"text-earth\">代理卡密</span>";
                    }
                }},
            {"title": "卡密", "data": "km", "render": function (data, type, row, meta) {
                    if (row.useid != 0) {
                        return "<s>" + data + "</s>"
                    } else {
                        return data;
                    }
                }},
            {"title": "面值", "data": "value", "className":"", "render": function (data, type, row, meta) {
                    if (row.type == 'vip') {
                        return formartDay(data)
                    } else if (row.type == 'quota') {
                        return "<span class=\"text-default\">" + data + " 个配额</span>";
                    } else if (row.type == 'agent') {
                        return "<span class=\"text-default\">" + formartAgent(data) + "</span>";
                    }
                    function formartDay(day){
                        var i = parseInt(day / 365), month;
                        if(i == 0){
                            month = day / 30;
                            if(month < 1){
                                return "<span class=\"text-default\">" + parseInt(data) + " 天</span>";
                            }else{
                                return "<span class=\"text-default\">" + parseInt(month) + " 个月</span>";
                            }
                        } else {
                            if (i == 21) {
                                return '永久';
                            } else {
                                return parseInt(day /365) + '年';
                            }
                        }
                    }
                    function formartAgent(str){
                        if (data == 1){
                            return "银牌代理";
                        }else if (data == 2){
                            return "金牌代理";
                        }else{
                            return "钻石代理";
                        }
                    }
                }},
            {"title": "状态", "data": "useid", "className":"", "render": function (data, type, row, meta) {
                    if (data == 0) {
                        return "<span class=\"badge bg-success\">未使用</span>";
                    } else {
                        return "<span class=\"badge bg-danger\">已使用</span><span class=\"mx-1 fs-xs\">" + formartTime(row.usetime) + "</span><br><span class=\"fs-xs\">使用UID ： " + data + "</span>";
                    }
                    function formartTime(time) {
                        var date = (new Date(time));
                        var M = (date.getMonth() + 1 < 10 ? '0' + (date.getMonth() + 1) : date.getMonth() + 1) + '-';
                        var D = (date.getDate() < 10 ? '0' + (date.getDate()) : date.getDate()) + ' ';
                        var h = (date.getHours() < 10 ? '0' + (date.getHours()) : date.getHours()) + ':';
                        var m = (date.getMinutes() < 10 ? '0' + (date.getMinutes()) : date.getMinutes()) + ':';
                        var s = (date.getSeconds() < 10 ? '0' + (date.getSeconds()) : date.getSeconds());
                        return M+D+h+m+s;
                    }
                }},
            {"title": "生成时间", "data": "addtime", "className":"", "render": function (data, type, row, meta) {
                    return formartTime(data);
                    function formartTime(time) {
                        var date = (new Date(time));
                        var Y = date.getFullYear() + '-';
                        var M = (date.getMonth() + 1 < 10 ? '0' + (date.getMonth() + 1) : date.getMonth() + 1) + '-';
                        var D = (date.getDate() < 10 ? '0' + (date.getDate()) : date.getDate()) + ' ';
                        return Y+M+D;
                    }
                }},
            {"title": "操作", "data": "id", "className": "", "sortable": false, "render": function (data, type, row, meta) {
                    return "<button type=\"button\"class=\"btn btn-sm btn-alt-danger\"data-bs-toggle=\"tooltip\" onclick=\"ajax_del_km('" + data + "');\"><i class=\"fa fa-trash-alt\"></i></button>";
                }},
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
    let table = $("#kmsList").dataTable();
    table.fnDraw();
}

function ajax_del_km(id) {
    x.del('/admin/ajax/data/delete/km', {id: id}, function (data) {
        if (data.code == 1) {
            var table = $('#kmsList').DataTable();
            table.ajax.reload();;
            x.notify(data.message, 'success');
        } else {
            x.notify(data.message, 'warning');
        }
    })
}

function ajax_del_usedkm() {
    x.del('/admin/ajax/data/delete/usedkm',{id: 1}, function (data) {
        if (data.code == 1) {
            var table = $('#kmsList').DataTable();
            table.ajax.reload();
            x.notify(data.message, 'success');
        } else {
            x.notify(data.message, 'warning');
        }
    })
}

function ajax_del_notUsedkm() {
    x.del('/admin/ajax/data/delete/noUsedkm',{id: 0}, function (data) {
        if (data.code == 1) {
            var table = $('#kmsList').DataTable();
            table.ajax.reload();
            x.notify(data.message, 'success');
        } else {
            x.notify(data.message, 'warning');
        }
    })
}

function ajax_add_km()
{
    layer.open({
        title: "生成卡密",
        btn: ['生成', '取消'],
        btnAlign: 'c',
        closeBtn: 0,
        shadeClose: true,
        content: '<form id="add-form"><div class="row"><div class="col-md-12"><div class="form-floating mb-4"><div class="form-floating mb-4"><select class="form-select" id="type" name="type" aria-label="类型" size="1" onchange="typeChange(this);"><option value="vip">VIP卡密</option><option value="quota">配额卡密</option><option value="agent">代理卡密</option></select><label class="form-label" for="type">卡密类型</label></div></div></div></div><div class="row"><div class="col-md-12"><div class="form-floating mb-4"><select class="form-select" id="value-vip" size="1" placeholder="."><option value="5">3 天</option><option value="6">7 天</option><option value="1">1 个月</option><option value="2">3 个月</option><option value="3">6 个月</option><option value="4">12 个月</option></select><select class="form-select" id="value-quota" size="1" placeholder="." style="display: none"><option value="1">1 个</option><option value="2">3 个</option><option value="3">5 个</option><option value="4">10 个</option></select><select class="form-select" id="value-agent" size="1" placeholder="." style="display: none"><option value="1">银牌代理</option><option value="2">金牌代理</option><option value="3">钻石代理</option></select><label class="form-label" for="type">卡密面值</label></div></div></div><div class="row"><div class="col-md-12"><div class="form-floating mb-4"><div class="form-floating mb-4"><select class="form-select" id="num" name="type" size="1" placeholder="."><option value="1">1 张</option><option value="5">5 张</option><option value="20">20 张</option><option value="50">50 张</option><option value="100">100 张</option></select><label class="form-label" for="num">卡密数量</label></div></div></div></div></form>',
        yes: function (index, dom) {
            var type = $('#type').val(), value = $("#value-" + type).val(), num = $('#num').val();
            x.ajax('/admin/ajax/data/add/km', {type: type, value: value, num: num}, function (data) {
                if (data.code == 1) {
                    var table = $("#kmsList").DataTable();
                    table.ajax.reload();
                    layer.closeAll();
                    copy_km(data.data.copy);
                } else {
                    layer.msg(data.message);
                }
            })
        },
    });
}

function copy_km(km)
{
    layer.open({
        title: "生成卡密成功",
        btn: ['<div class="copy" id="copy">全部复制</div>', '取消'],
        btnAlign: 'c',
        closeBtn: 0,
        shadeClose: true,
        zIndex: 1,
        content: "<div data-clipboard-text='' id='success'></div>",
        success: function (index, dom) {
          var html = km.replace(/\n/g,"<br/>");
          $('#success').html(html) ;
          $('#copy').attr('data-clipboard-text',km);
        },
        yes: function (index, dom) {

        },
    });
}

function typeChange(o) {
    if (o.value == 'vip') {
        $('#value-vip').show();
        $('#value-quota').hide();
        $('#value-agent').hide();
    } else if (o.value == 'quota') {
        $('#value-vip').hide();
        $('#value-quota').show();
        $('#value-agent').hide();
    }else {
        $('#value-vip').hide();
        $('#value-quota').hide();
        $('#value-agent').show();
    }
}
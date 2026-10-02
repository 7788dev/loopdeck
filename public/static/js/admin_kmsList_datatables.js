!function () {
    $.extend(!0, $.fn.dataTable.ext.classes, window.LOOPDECK_DT_CLASSES || {sWrapper: "dataTables_wrapper dt-bootstrap5", sFilterInput: "form-control", sLengthSelect: "form-select"}), $.extend(!0, $.fn.dataTable.defaults, {
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
                    if (data == 'bundle') {
                        return "<span class=\"text-primary\">统一兑换码</span>";
                    } else if (data == 'vip') {
                        return "<span class=\"text-corporate\">旧版会员码</span>";
                    } else if (data == 'quota') {
                        return "<span class=\"text-earth\">旧版配额码</span>";
                    }
                    return "<span class=\"text-muted\">已停用</span>";
                }},
            {"title": "兑换码", "data": "km", "render": function (data, type, row, meta) {
                    if (row.useid != 0) {
                        return "<s>" + x.escapeHtml(data) + "</s>"
                    } else {
                        return x.escapeHtml(data);
                    }
                }},
            {"title": "兑换权益", "data": "benefits", "render": x.renderText},
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
    var submitting = false, form;
    function updateSummary() {
        var days = form.elements.vip_days.value, accounts = form.elements.account_limit.value;
        form.querySelector('#km-summary').textContent = '每张：'
            + (days === '' ? '请填写会员时长' : Number(days) === 0 ? '永久会员' : '会员 ' + days + ' 天') + ' · '
            + (accounts === '' ? '请填写账号总数' : Number(accounts) === 0 ? '账号数量不限' : '账号总数 ' + accounts + ' 个');
    }
    layer.open({
        type: 1,
        title: "生成兑换码",
        area: [Math.min(620, window.innerWidth - 32) + 'px'],
        maxHeight: window.innerHeight - 32,
        btn: ['生成', '取消'],
        btnAlign: 'c',
        shadeClose: false,
        content: $('#redemption-form-template').html(),
        success: function (dom) {
            form = dom.find('form')[0];
            var preset = form.querySelector('#km-preset');
            preset.value = '1';
            function applyPreset() {
                var option = preset.options[preset.selectedIndex];
                form.querySelector('#km-custom-fields').classList.toggle('d-none', preset.value !== '');
                if (preset.value !== '') {
                    form.elements.vip_days.value = option.dataset.days;
                    form.elements.account_limit.value = option.dataset.accounts;
                }
                updateSummary();
            }
            preset.addEventListener('change', applyPreset);
            ['vip_days', 'account_limit'].forEach(function (name) {
                form.elements[name].addEventListener('input', updateSummary);
            });
            applyPreset();
        },
        cancel: function () { return !submitting; },
        btn2: function () { return !submitting; },
        yes: function (index, dom) {
            if (submitting || !form.reportValidity()) return;
            var payload = {vip_days: form.elements.vip_days.value,
                account_limit: form.elements.account_limit.value, num: form.elements.num.value};
            submitting = true;
            dom.find('.layui-layer-btn0').text('正在生成…');
            function reset() {
                submitting = false;
                dom.find('.layui-layer-btn0').text('生成');
            }
            x.ajax('/admin/ajax/data/add/km', payload, function (data) {
                reset();
                if (data.code == 1) {
                    $("#kmsList").DataTable().ajax.reload();
                    layer.close(index);
                    copy_km(data.data.copy, data.data.benefits, data.data.count);
                } else {
                    layer.msg(data.message);
                }
            }, function () {
                reset();
                x.notify('请求未完成，请先查询列表确认是否已生成，再决定是否重试', 'warning');
            });
        },
    });
}

function copy_km(km, benefits, count)
{
    layer.open({
        type: 1,
        title: "生成兑换码成功",
        area: [Math.min(620, window.innerWidth - 32) + 'px'],
        btn: ['<div class="copy" id="copy">复制全部兑换码</div>', '关闭'],
        btnAlign: 'c',
        closeBtn: 0,
        shadeClose: true,
        content: '<div class="p-3"><p id="km-issued-summary">'
            + x.escapeHtml('共 ' + count + ' 张，每张：' + benefits)
            + '</p><pre id="success" style="max-height:280px;overflow:auto;white-space:pre-wrap">'
            + x.escapeHtml(km) + '</pre></div>',
        success: function (index, dom) {
          $('#copy').attr('data-clipboard-text',km);
        },
        yes: function (index, dom) {

        },
    });
}

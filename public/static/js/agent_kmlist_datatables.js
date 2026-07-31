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
    $("#agent-kmlist").DataTable({
        ajax: {
            "url": "/index/ajax/agent/kmList",
            "dataType": "json",
            "type": "get",
            "dataSrc": "",
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
                    return "<button type=\"button\"class=\"btn btn-sm btn-alt-danger\"data-bs-toggle=\"tooltip\" onclick=\"ajax_km_delete('" + data + "');\"><i class=\"fa fa-trash-alt\"></i></button>";
                }},
        ],
        pagingType: "full_numbers",
        pageLength: 10,
        lengthMenu: [[10, 20, 50, 100], [10, 20, 50, 100]],
        autoWidth: !1,
        responsive: !0
    })
}()
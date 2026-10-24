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
    })
    $("#noticesList").DataTable({
        ajax: {
            "url": "/admin/ajax/data/list/notices",
            "dataType": "json",
            "type": "get",
            "dataSrc": "",
        },
        columns: [
            {"title": "ID", "data": "id", "className": "fs-sm", "sortable": false},
            {"title": "内容", "data": "content", "className": "fs-sm", "sortable": false, "render": function (data, type, row, meta) {
                    return x.escapeHtml(cutstr(data, 30));
                }},
            {"title": "显示位置", "data": "type", "className": "fs-sm", "render": function (data, type, row, meta) {
                   if (row.type == 1) {
                       return '用户中心';
                   } else if (row.type == 2) {
                       return '<span class="text-danger fw-semibold">站长后台</span>';
                   }
                }},
            {"title": "用户中心弹窗", "data":"alert", "render": function (data, type, row, meta) {
                   if (row.alert == 1 && row.type !== 2) {
                       return "<div class=\"btn-group\"><div class=\"form-check form-switch\"><input type=\"checkbox\"class=\"form-check-input checked\" onclick=\"ajax_set_alert('" + row.id + "', this);\" checked=\"\"></div></div>";
                   } else if (row.alert == 0 && row.type !== 2) {
                       return "<div class=\"btn-group\"><div class=\"form-check form-switch\"><input type=\"checkbox\"class=\"form-check-input\" onclick=\"ajax_set_alert('" + row.id + "', this);\"></div></div>";
                   } else {
                       return "";
                   }
                }},
            {"title": "置顶等级", "data":"sort", "render": function (data, type, row, meta) {
                    if(row.sort == 0){
                        return "<div class=\"btn-group\"><select class=\"form-select\" name=\"sort\" onchange=\"selChange('" + row.id + "', this);\"><option value=\"0\" selected>无置顶</option><option value=\"1\" >低级置顶</option><option value=\"2\">中级置顶</option><option value=\"3\">高级置顶</option></select></div>";
                    } else if (row.sort == 1){
                        return "<div class=\"btn-group\"><select class=\"form-select\" name=\"sort\" onchange=\"selChange('" + row.id + "', this);\"><option value=\"0\">无置顶</option><option value=\"1\" selected>低级置顶</option><option value=\"2\">中级置顶</option><option value=\"3\">高级置顶</option></select></div>";
                    }else if (row.sort == 2){
                        return "<div class=\"btn-group\"><select class=\"form-select\" name=\"sort\" onchange=\"selChange('" + row.id + "', this);\"><option value=\"0\">无置顶</option><option value=\"1\">低级置顶</option><option value=\"2\" selected>中级置顶</option><option value=\"3\">高级置顶</option></select></div>";
                    }else{
                        return "<div class=\"btn-group\"><select class=\"form-select\" name=\"sort\" onchange=\"selChange('" + row.id + "', this);\"><option value=\"0\">无置顶</option><option value=\"1\">低级置顶</option><option value=\"2\">中级置顶</option><option value=\"3\" selected>高级置顶</option></select></div>";
                    }
                }},
            {"title": "添加时间", "data": "addtime", "className":"", "render": function (data, type, row, meta) {
                    return formartTime(data);
                }},
            {"title": "操作", "data": "id", "sortable": false, "render": function (data, type, row, meta) {
                    return "<button type=\"button\"class=\"btn btn-sm btn-alt-info me-1\"data-bs-toggle=\"tooltip\" onclick=\"ajax_edit_notice('" + row.id + "');\"><i class=\"fa fa-edit\"></i></button><button type=\"button\"class=\"btn btn-sm btn-alt-danger\"data-bs-toggle=\"tooltip\" onclick=\"ajax_del_notice('" + row.id + "');\"><i class=\"fa fa-trash-alt\"></i></button>";
                }},
        ],
        pagingType: "full_numbers",
        pageLength: 10,
        lengthMenu: [[10, 20, 50, 100], [10, 20, 50, 100]],
        autoWidth: !1,
        responsive: !0
    })
}()


function cutstr(str, len) {
    // 标签先剥离、实体后解码，模拟浏览器渲染顺序：&nbsp; 显示为空格，
    // 作者手打的 &lt;b&gt; 保留字面尖括号，由调用方的 escapeHtml 重新转义。
    str = String(str == null ? '' : str)
        .replace(/<\/?.+?\/?>/gs, '')
        .replace(/&#x([0-9a-f]+);/gi, function (entity, code) {
            var point = parseInt(code, 16);
            return point > 0 && point <= 0x10ffff ? String.fromCodePoint(point) : entity;
        })
        .replace(/&#(\d+);/g, function (entity, code) {
            var point = parseInt(code, 10);
            return point > 0 && point <= 0x10ffff ? String.fromCodePoint(point) : entity;
        })
        .replace(/&nbsp;/gi, ' ')
        .replace(/&quot;/gi, '"')
        .replace(/&apos;|&#39;/gi, "'")
        .replace(/&lt;/gi, '<')
        .replace(/&gt;/gi, '>')
        .replace(/&amp;/gi, '&');
    var temp,
        icount = 0,
        patrn = /[^\x00-\xff]/,
        strre = "",
        tail = "";
    for (var i = 0; i < str.length; i++) {
        if (icount < len - 1) {
            temp = str.substr(i, 1);
            if (patrn.exec(temp) == null) {
                icount = icount + 1
            } else {
                icount = icount + 2
            }
            strre += temp
        } else {
            tail = " ...";
            break;
        }
    }
    return strre + tail;
}

function formartTime(timestamp) {
    var date = new Date(timestamp * 1000);//时间戳为10位需*1000，时间戳为13位的话不需乘1000
    var Y = date.getFullYear() + '-';
    var M = (date.getMonth() + 1 < 10 ? '0' + (date.getMonth() + 1) : date.getMonth() + 1) + '-';
    var D = (date.getDate() < 10 ? '0' + (date.getDate()) : date.getDate()) + ' ';
    return Y + M + D ;
}

function selChange(id,_this) {
    let sort = $(_this).prop("value");
    x.ajax('/admin/ajax/data/set/notice', {id: id, sort: sort}, function (data) {
        if (data.code == 1) {
            var table = $("#noticesList").DataTable();
            table.ajax.reload();
            layer.closeAll();
            layer.msg(data.message);
        } else {
            layer.msg(data.message);
        }
    })
}

// The rich-text editor instance of the currently open notice dialog. It is
// module state rather than an implicit global: reading a bare `content`
// variable returned undefined whenever the admin saved without typing.
var noticeEditor = null;

function noticeContent() {
    return noticeEditor ? noticeEditor.getHtml() : '';
}

function noticeContentIsEmpty(content) {
    return String(content).replace(/<[^>]*>/g, '').replace(/&nbsp;|&#160;|\s/g, '') === '';
}

function ajax_edit_notice(id)
{
    // Drop the previous dialog's editor immediately: until this dialog's own
    // info response arrives, noticeContent() must return empty instead of the
    // last notice's HTML, which the empty check then refuses to save.
    noticeEditor = null;
    layer.open({
        title: "公告编辑 [ID："+id+"]",
        btn: ['保存', '取消'],
        closeBtn: 0,
        shadeClose: true,
        area: ['50%', '50%'],//设置相对于父页面的大小
        content: '<form id="edit-form"><input type="hidden" id="id" name="id"><div class="mb-4"><div id="toolbar-container"></div><div id="editor-container" style="height: 250px;"></div></div></form>',
        success: function (res, index) {
            x.ajax('/admin/ajax/data/info/notice', {id: id}, function (info) {
                if (!info || !info.id) {
                    layer.msg('公告不存在或已被删除');
                    return;
                }
                $("#id").val(info.id);
                noticeEditor = loadwangEditor(info.content);
            })
        },
        yes: function (index, dom) {
            var content = noticeContent();
            if (noticeContentIsEmpty(content)) {
                layer.msg('公告内容不能为空');
                return;
            }
            layer.close(index);
            var loading = layer.load(2);
            x.ajax('/admin/ajax/data/set/notice', {id: $("#id").val(), content: content}, function (data) {
                if (data.code == 1) {
                    x.close(loading);
                    var table = $("#noticesList").DataTable();
                    table.ajax.reload();
                    layer.msg(data.message);
                } else {
                    x.close(loading);
                    layer.msg(data.message);
                }
            })
        },
    });
}

function ajax_del_notice(id) {
    x.del('/admin/ajax/data/delete/notice', {id: id}, function (data) {
        if (data.code == 1) {
            var table = $('#noticesList').DataTable();
            table.ajax.reload();;
            x.notify(data.message, 'success');
        } else {
            x.notify(data.message, 'warning');
        }
    })
}

function ajax_add_notice()
{
    // A stale editor from a previous dialog must never feed this one's save.
    noticeEditor = null;
    layer.open({
        title: "添加公告",
        btn: ['添加', '取消'],
        closeBtn: 0,
        shadeClose: true,
        area: ['50%', '50%'],//设置相对于父页面的大小
        content: '<form id="add-form"><input type="hidden"id="id"name="id"><div class="mb-2"><select id="sort"class="form-select"name="sort"><option value="0"selected>置顶等级：无置顶</option><option value="1">置顶等级：低级置顶</option><option value="2">置顶等级：中级置顶</option><option value="3">置顶等级：高级置顶</option></select></div><div class="mb-4"><div id="toolbar-container"></div><div id="editor-container" style="height: 250px;"></div></div></form>',
        success:function (index, dom) {
            noticeEditor = loadwangEditor();
        },
        yes: function (index, dom) {
            var content = noticeContent();
            if (noticeContentIsEmpty(content)) {
                layer.msg('公告内容不能为空');
                return;
            }
            x.ajax('/admin/ajax/data/add/notice', {type: 1 ,content: content, sort: $('#sort').val()}, function (data) {
                if (data.code == 1) {
                    var table = $("#noticesList").DataTable();
                    table.ajax.reload();
                    layer.closeAll();
                    layer.msg(data.message);
                } else {
                    layer.msg(data.message);
                }
            })
        },
    });
}

function loadwangEditor(html) {

    const { createEditor, createToolbar } = window.wangEditor

    const editorConfig = {
        autofocus: true
    }

    // 保存时直接读取 editor.getHtml()，不再依赖 onChange 维护外部变量。

    editorConfig.placeholder = '请输入公告内容'

    const toolbarConfig = {
        /* 工具栏配置 */
        excludeKeys: [
            'headerSelect',
            'blockquote',
            'fontFamily',
            'bgColor',
            'todo',
            'group-image',
            'group-video',
            'insertTable',
            'codeBlock',
            'divider',
            'fullScreen',
        ]
    }
    // 创建编辑器
    const editor = createEditor({
        selector: '#editor-container',
        config: editorConfig,
        mode: 'default', // 或 'simple' 参考下文
        html: html,

    })
    // 创建工具栏
    const toolbar = createToolbar({
        editor,
        selector: '#toolbar-container',
        config: toolbarConfig,
        mode: 'default', // 或 'simple' 参考下文
    })
    return editor;
}

function ajax_set_alert(id, _this) {
    if ($('.checked').length !== 0 && !$(_this).hasClass('checked')) {
        layer.msg('你最多只能选择一个用户中心弹窗');
        var table = $("#noticesList").DataTable();
        table.ajax.reload();
        return;
    }
    let alert = $(_this).prop("checked") ? 1 : 0;
    x.ajax('/admin/ajax/data/set/notice', {id: id , alert: alert}, function (data) {
        if (data.code == 1) {
            var table = $("#noticesList").DataTable();
            table.ajax.reload();
            layer.closeAll();
            layer.msg(data.message);
        } else {
            layer.msg(data.message);
        }
    })
}
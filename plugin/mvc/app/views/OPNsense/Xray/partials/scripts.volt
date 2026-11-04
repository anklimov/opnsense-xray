<script>
    $(document).ready(function () {
        // ── Helpers ───────────────────────────────────────────────
        function escAttr(s) {
            return String(s).replace(/[&"<>]/g, function (c) {
                return {'&':'&amp;','"':'&quot;','<':'&lt;','>':'&gt;'}[c];
            });
        }

        // ── Per-instance status overlay ───────────────────────────
        var instanceStatusCache = {};
        var instanceTestCache   = {};

        function statusBadge(info) {
            if (!info) return '<span class="label label-default" style="font-size:11px;">--</span>';
            var xOk = info.xray_core === 'running';
            // v4.0.0: xray-драйвер отдаёт 'tun' (интерфейс), legacy — tun2socks
            var tVal = info.tun || info.tun2socks;
            var tOk = tVal === 'running';
            var tName = info.driver === 'xray' ? 'tun' : (info.driver === 'tun2socks' ? 'tun2socks' : (info.tun ? 'tun' : 'tun2socks'));
            return '<span class="label ' + (xOk ? 'label-success' : 'label-danger') + '" style="font-size:11px;">' +
                'xray: ' + (xOk ? 'up' : 'down') +
                '</span> ' +
                '<span class="label ' + (tOk ? 'label-success' : 'label-danger') + '" style="font-size:11px;">' +
                tName + ': ' + (tOk ? 'up' : 'down') +
                '</span>';
        }

        function applyStatusToGrid() {
            $('#grid-instances .xray-status-cell').each(function () {
                var uuid = $(this).data('uuid');
                $(this).html(statusBadge(instanceStatusCache[uuid]));
            });
        }

        function testResultBadge(info) {
            if (!info) return '<span style="font-size:11px;color:#999;">--</span>';
            var ok = info.result === 'ok';
            return '<span class="label ' + (ok ? 'label-success' : 'label-danger') + '" style="font-size:11px;">'
                + escAttr(info.message) + '</span>';
        }

        function applyTestResultToGrid() {
            $('#grid-instances .xray-test-cell').each(function () {
                var uuid = $(this).data('uuid');
                $(this).html(testResultBadge(instanceTestCache[uuid]));
            });
        }

        // ── Instances CRUD table (UIBootgrid) ───────────────────────
        $('#grid-instances').UIBootgrid({
            search: '/api/xray/instance/searchItem',
            get:    '/api/xray/instance/getItem/',
            set:    '/api/xray/instance/setItem/',
            add:    '/api/xray/instance/addItem',
            del:    '/api/xray/instance/delItem/',
            toggle: '/api/xray/instance/toggleItem/',
            options: {
                formatters: {
                    modeLabel: function (column, row) {
                        var labels = {
                            config: 'config',
                            group:  'group',
                            none:   'server',
                            raw:    'raw'
                        };
                        var v = labels[row.node_source] || 'config';
                        return '<span class="label label-default" style="font-size:11px;">' + escAttr(v) + '</span>';
                    },
                    instanceStatus: function (column, row) {
                        return '<span class="xray-status-cell" data-uuid="' + escAttr(row.uuid) + '">' +
                            statusBadge(instanceStatusCache[row.uuid]) + '</span>';
                    },
                    instanceTestResult: function (column, row) {
                        return '<span class="xray-test-cell" data-uuid="' + escAttr(row.uuid) + '">'
                            + testResultBadge(instanceTestCache[row.uuid]) + '</span>';
                    },
                    commands: function (column, row) {
                        var uuid = escAttr(row.uuid);
                        return '<button type="button" class="btn btn-xs btn-success cmd-inst-start bootgrid-tooltip"'
                             +   ' data-row-id="' + uuid + '" title="{{ lang._("Start this instance") }}">'
                             +   '<span class="fa fa-play fa-fw"></span></button> '
                             + '<button type="button" class="btn btn-xs btn-danger cmd-inst-stop bootgrid-tooltip"'
                             +   ' data-row-id="' + uuid + '" title="{{ lang._("Stop this instance") }}">'
                             +   '<span class="fa fa-stop fa-fw"></span></button> '
                             + '<button type="button" class="btn btn-xs btn-default cmd-inst-test bootgrid-tooltip"'
                             +   ' data-row-id="' + uuid + '" title="{{ lang._("Test") }}">'
                             +   '<span class="fa fa-plug fa-fw"></span></button> '
                             + '<button type="button" class="btn btn-xs btn-default command-edit bootgrid-tooltip"'
                             +   ' data-row-id="' + uuid + '" title="{{ lang._("Edit") }}">'
                             +   '<span class="fa fa-pencil fa-fw"></span></button> '
                             + '<button type="button" class="btn btn-xs btn-default command-delete bootgrid-tooltip"'
                             +   ' data-row-id="' + uuid + '" title="{{ lang._("Delete") }}">'
                             +   '<span class="fa fa-trash-o fa-fw"></span></button>';
                    }
                }
            }
        });

        // ── Groups & servers CRUD tables (UIBootgrid) ───────────────
        $('#grid-groups').UIBootgrid({
            search: '/api/xray/group/searchGroup',
            get:    '/api/xray/group/getGroup/',
            set:    '/api/xray/group/setGroup/',
            add:    '/api/xray/group/addGroup',
            del:    '/api/xray/group/delGroup/',
            options: {
                formatters: {
                    // NB: имя формата обязано быть 'commands' — UIBootgrid
                    // перебивает своим рендерером колонку с этим id, и любое
                    // другое имя формата молча игнорируется (проверено: кнопка
                    // Refresh не появлялась вовсе).
                    commands: function (column, row) {
                        var uuid = escAttr(row.uuid);
                        var html = '';
                        // Refresh only makes sense for imported groups
                        if (row.source === 'subscription') {
                            html += '<button type="button" class="btn btn-xs btn-default cmd-grp-refresh bootgrid-tooltip"'
                                 +    ' data-row-id="' + uuid + '" title="{{ lang._("Re-fetch this subscription") }}">'
                                 +    '<span class="fa fa-refresh fa-fw"></span></button> ';
                        }
                        return html
                             + '<button type="button" class="btn btn-xs btn-default command-edit bootgrid-tooltip"'
                             +   ' data-row-id="' + uuid + '" title="{{ lang._("Edit") }}">'
                             +   '<span class="fa fa-pencil fa-fw"></span></button> '
                             + '<button type="button" class="btn btn-xs btn-default command-delete bootgrid-tooltip"'
                             +   ' data-row-id="' + uuid + '" title="{{ lang._("Delete") }}">'
                             +   '<span class="fa fa-trash-o fa-fw"></span></button>';
                    }
                }
            }
        });

        $('#grid-servers').UIBootgrid({
            search: '/api/xray/group/searchServer',
            get:    '/api/xray/group/getServer/',
            set:    '/api/xray/group/setServer/',
            add:    '/api/xray/group/addServer',
            del:    '/api/xray/group/delServer/',
            options: {
                // NB: requestHandler читается ТОЛЬКО из options (opnsense_bootgrid.js:310);
                // на верхнем уровне он молча игнорируется, и грид отдаёт все серверы.
                requestHandler: function (request) {
                    request['group'] = $('#serverGroupFilter').val() || '';
                    return request;
                },
                formatters: {
                    serverStale: function (column, row) {
                        return row.stale === '1'
                            ? '<span class="label label-warning" style="font-size:11px;">{{ lang._("stale") }}</span>'
                            : '<span style="font-size:11px;color:#999;">--</span>';
                    }
                }
            }
        });

        // ── Routing rules CRUD table (UIBootgrid) ────────────────────
        $('#grid-rules').UIBootgrid({
            search: '/api/xray/rule/searchItem',
            get:    '/api/xray/rule/getItem/',
            set:    '/api/xray/rule/setItem/',
            add:    '/api/xray/rule/addItem',
            del:    '/api/xray/rule/delItem/',
            toggle: '/api/xray/rule/toggleItem/',
            options: {
                requestHandler: function (request) {
                    request['instance'] = $('#ruleInstanceFilter').val() || '';
                    return request;
                },
                formatters: {
                    ruleAction: function (column, row) {
                        var cls = { proxy: 'label-primary', balancer: 'label-info', direct: 'label-success', block: 'label-danger' };
                        return '<span class="label ' + (cls[row.action] || 'label-default') + '" style="font-size:11px;">'
                            + escAttr(row.action) + '</span>';
                    }
                }
            }
        });

        // Group filter above the servers grid
        $('#serverGroupFilter').on('change', function () {
            $('#grid-servers').bootgrid('reload');
        });

        // Keep the filter options in sync with the groups grid
        $('#grid-groups').on('loaded.rs.jquery.bootgrid', function () {
            $.ajax({url: '/api/xray/group/searchGroup', type: 'GET', dataType: 'json'})
                .done(function (data) {
                    var $sel = $('#serverGroupFilter');
                    var current = $sel.val();
                    $sel.empty().append($('<option>').val('').text("{{ lang._('All groups') }}"));
                    (data.rows || []).forEach(function (row) {
                        $sel.append($('<option>').val(row.uuid).text(row.name));
                    });
                    if (current) {
                        $sel.val(current);
                    }
                });
        });

        // Instance filter above the routing rules grid
        $('#ruleInstanceFilter').on('change', function () {
            $('#grid-rules').bootgrid('reload');
        });

        function populateRuleInstanceFilter() {
            $.ajax({url: '/api/xray/instance/searchItem', type: 'GET', dataType: 'json'})
                .done(function (data) {
                    var $sel = $('#ruleInstanceFilter');
                    var current = $sel.val();
                    $sel.empty().append($('<option>').val('').text("{{ lang._('All instances') }}"));
                    (data.rows || []).forEach(function (row) {
                        $sel.append($('<option>').val(row.uuid).text(row.name || row.uuid));
                    });
                    if (current) {
                        $sel.val(current);
                    }
                });
        }

        // ── Subscription import ─────────────────────────────────────
        $('#btnSubImport').on('click', function () {
            var $btn  = $(this);
            var $res  = $('#subImportResult');
            var name  = $.trim($('#subImportName').val());
            var url   = $.trim($('#subImportUrl').val());
            var ua    = $.trim($('#subImportUa').val());
            var body  = $('#subImportBody').val() || '';

            if (!name) {
                $res.removeClass('text-success').addClass('text-danger')
                    .text("{{ lang._('Group name is required.') }}");
                return;
            }
            if (!url && !$.trim(body)) {
                $res.removeClass('text-success').addClass('text-danger')
                    .text("{{ lang._('Provide a subscription URL or paste the body.') }}");
                return;
            }

            $btn.prop('disabled', true);
            $res.removeClass('text-success text-danger').text("{{ lang._('Importing...') }}");

            var payload = {name: name, sub_url: url, sub_ua: ua, body_b64: ''};
            if ($.trim(body)) {
                payload.body_b64 = btoa(unescape(encodeURIComponent(body)));
            }

            $.ajax({
                url:         '/api/xray/group/importSubscription',
                type:        'POST',
                contentType: 'application/json; charset=utf-8',
                data:        JSON.stringify(payload),
                dataType:    'json'
            }).done(function (data) {
                $btn.prop('disabled', false);
                if (data.status !== 'ok') {
                    $res.removeClass('text-success').addClass('text-danger')
                        .text("{{ lang._('Import failed:') }} " + (data.message || 'unknown error'));
                    return;
                }
                var msg = "{{ lang._('Imported') }} " + data.added + " {{ lang._('nodes') }}";
                if (data.skipped && data.skipped.length) {
                    msg += ", " + data.skipped.length + " {{ lang._('skipped') }}: "
                        + data.skipped.map(function (s) { return 'line ' + s.line + ': ' + s.reason; }).join('; ');
                }
                $res.removeClass('text-danger').addClass('text-success').text(msg);
                $('#subImportBody').val('');
                $('#grid-groups').bootgrid('reload');
                $('#grid-servers').bootgrid('reload');
            }).fail(function (xhr) {
                $btn.prop('disabled', false);
                $res.removeClass('text-success').addClass('text-danger')
                    .text("{{ lang._('HTTP error:') }} " + xhr.status);
            });
        });

        // ── Refresh a subscription group ────────────────────────────
        $(document).on('click', '#grid-groups .cmd-grp-refresh', function () {
            var uuid = $(this).data('row-id');
            var $btn = $(this).prop('disabled', true);
            var $res = $('#subImportResult');
            $res.removeClass('text-success text-danger').text("{{ lang._('Refreshing...') }}");

            $.ajax({
                url:      '/api/xray/group/refresh/' + uuid,
                type:     'POST',
                dataType: 'json'
            }).done(function (data) {
                $btn.prop('disabled', false);
                if (data.status !== 'ok') {
                    $res.removeClass('text-success').addClass('text-danger')
                        .text("{{ lang._('Refresh failed:') }} " + (data.message || 'unknown error'));
                    return;
                }
                var msg = 'added ' + data.added + ' / updated ' + data.updated
                        + ' / removed ' + data.removed + ' / stale ' + data.stale;
                if (data.skipped && data.skipped.length) {
                    msg += ", " + data.skipped.length + " {{ lang._('skipped') }}: "
                        + data.skipped.map(function (s) { return 'line ' + s.line + ': ' + s.reason; }).join('; ');
                }
                $res.removeClass('text-danger').addClass('text-success').text(msg);
                $('#grid-groups').bootgrid('reload');
                $('#grid-servers').bootgrid('reload');
            }).fail(function (xhr) {
                $btn.prop('disabled', false);
                $res.removeClass('text-success').addClass('text-danger')
                    .text("{{ lang._('HTTP error:') }} " + xhr.status);
            });
        });

        // After grid loads/reloads data, fetch and overlay status
        $('#grid-instances').on('loaded.rs.jquery.bootgrid', function () {
            refreshInstancesStatus();
            populateInstanceSelects();
            populateRuleInstanceFilter();
        });

        // Per-instance start / stop / test (row button handlers)
        $(document).on('click', '#grid-instances .cmd-inst-start', function () {
            instanceServiceAction('start', $(this).data('row-id'));
        });
        $(document).on('click', '#grid-instances .cmd-inst-stop', function () {
            instanceServiceAction('stop', $(this).data('row-id'));
        });
        $(document).on('click', '#grid-instances .cmd-inst-test', function () {
            var uuid = $(this).data('row-id');
            var $btn = $(this).prop('disabled', true);
            $.ajax({
                url: '/api/xray/service/testconnect/' + encodeURIComponent(uuid),
                type: 'POST', dataType: 'json',
                success: function (data) {
                    instanceTestCache[uuid] = data;
                    applyTestResultToGrid();
                },
                complete: function () { $btn.prop('disabled', false); }
            });
        });

        function instanceServiceAction(action, uuid) {
            var url = '/api/xray/service/' + action + (uuid ? '/' + encodeURIComponent(uuid) : '');
            $.ajax({
                url: url, type: 'POST', dataType: 'json',
                success: function () { setTimeout(refreshInstancesStatus, 1500); }
            });
        }

        // ── General settings form ───────────────────────────────────
        mapDataToFormUI({'frm_general_settings': "/api/xray/general/get"}).done(function () {
            formatTokenizersUI();
            $('.selectpicker').selectpicker('refresh');
        });

        // ── Apply (save general, then reconfigure) ──────────────────
        $("#reconfigureAct").SimpleActionButton({
            onPreAction: function () {
                var dfObj = new $.Deferred();
                saveFormToEndpoint("/api/xray/general/set", 'frm_general_settings', function () {
                    dfObj.resolve();
                });
                return dfObj;
            }
        });

        // ── Status badges + per-instance status ───────────────────
        function refreshInstancesStatus() {
            ajaxGet("/api/xray/service/statusall", {}, function (data) {
                if (data.error) return;
                instanceStatusCache = data;

                // Aggregate: any instance running = global running
                var anyXray = false, anyTun = false;
                $.each(data, function (uuid, info) {
                    if (info.xray_core === 'running') anyXray = true;
                    if ((info.tun || info.tun2socks) === 'running') anyTun = true;
                });
                var xok = anyXray, tok = anyTun;
                $('#badge_xray')
                    .removeClass('label-success label-danger label-default')
                    .addClass(xok ? 'label-success' : 'label-danger')
                    .text('xray-core: ' + (xok ? 'running' : 'stopped'));
                $('#badge_tun')
                    .removeClass('label-success label-danger label-default')
                    .addClass(tok ? 'label-success' : 'label-danger')
                    .text('tun: ' + (tok ? 'running' : 'stopped'));

                // Update per-instance status in grid
                applyStatusToGrid();

                var running = xok || tok;
                $('#btnStartAll').prop('disabled', running);
                $('#btnStopAll').prop('disabled', !running);
                $('#btnRestartAll').prop('disabled', !running);
            });
        }
        refreshInstancesStatus();
        setInterval(refreshInstancesStatus, 5000);

        // ── Start / Stop / Restart ──────────────────────────────────
        function serviceAction(action, confirmMsg, callback) {
            if (confirmMsg && !confirm(confirmMsg)) {
                return;
            }
            var $btns = $('#btnStartAll, #btnStopAll, #btnRestartAll').prop('disabled', true);
            var $btn = action === 'start'   ? $('#btnStartAll')
                     : action === 'stop'    ? $('#btnStopAll')
                     :                        $('#btnRestartAll');
            var origHtml = $btn.html();
            $btn.html('<i class="fa fa-spinner fa-spin"></i>');

            $.ajax({
                url:      '/api/xray/service/' + action,
                type:     'POST',
                dataType: 'json',
                success: function (data) {
                    $btn.html(origHtml);
                    if (data.result !== 'ok') {
                        alert('{{ lang._("Action failed:") }} ' + (data.message || 'unknown error'));
                    }
                    setTimeout(function () {
                        refreshInstancesStatus();
                        $btns.prop('disabled', false);
                        if (callback) callback();
                    }, 1500);
                },
                error: function (xhr) {
                    $btn.html(origHtml);
                    $btns.prop('disabled', false);
                    alert('{{ lang._("HTTP error:") }} ' + xhr.status);
                }
            });
        }

        $('#btnStartAll').click(function () {
            serviceAction('start', null, null);
        });
        $('#btnStopAll').click(function () {
            var confirmStop = '{{ lang._("Stop Xray VPN? Active connections will be terminated.") }}';
            serviceAction('stop', confirmStop, null);
        });
        $('#btnRestartAll').click(function () {
            serviceAction('restart', null, null);
        });

        // ── Test Connection ─────────────────────────────────────────
        $('#btnTestConnect').click(function () {
            var $btn = $(this).prop('disabled', true);
            var $res = $('#testConnectResult');

            var uuids = [];
            $.each(instanceStatusCache, function (uuid, info) {
                if (info.xray_core === 'running') { uuids.push(uuid); }
            });

            if (!uuids.length) {
                $res.removeClass('text-success').addClass('text-danger')
                    .text("{{ lang._('No running instances.') }}");
                $btn.prop('disabled', false);
                return;
            }

            $res.removeClass('text-success text-danger').text("{{ lang._('Testing...') }}");
            var pending = uuids.length;

            $.each(uuids, function (_, uuid) {
                $.ajax({
                    url: '/api/xray/service/testconnect/' + encodeURIComponent(uuid),
                    type: 'POST', dataType: 'json',
                    success: function (data) {
                        instanceTestCache[uuid] = data;
                        applyTestResultToGrid();
                    },
                    complete: function () {
                        pending--;
                        if (pending === 0) {
                            $btn.prop('disabled', false);
                            $res.removeClass('text-success text-danger').text('');
                        }
                    }
                });
            });
        });

        // ── Import VLESS (inside DialogInstance) ──────────────────
        function applyImportToDialog(data) {
            var $dlg = $('#DialogInstance');
            if (data.name) {
                $dlg.find('[id="instance.name"]').val(data.name);
            }
            $dlg.find('[id="instance.outbound_config"]').val(data.outbound_config || '');
        }

        // Inject Import panel + footer buttons into DialogInstance on first open
        var dialogInjected = false;
        $('#DialogInstance').on('show.bs.modal', function () {
            if (dialogInjected) {
                // Reset state on each open
                $('#dlgImportLink').val('');
                $('#dlgImportResult').text('').removeClass('text-success text-danger');
                $('#dlgImportPanel').collapse('hide');
                $('#dlgValidateResult').text('').removeClass('text-success text-danger');
                $('#dlgPreviewResult').text('').removeClass('text-success text-danger');
                return;
            }
            dialogInjected = true;

            // Import panel — collapsible, injected before the form table
            var importHtml =
                '<div id="dlgImportWrap" style="margin: 0 0 10px;">' +
                    '<a data-toggle="collapse" href="#dlgImportPanel" class="btn btn-sm btn-default" style="margin-bottom: 6px;">' +
                        '<i class="fa fa-upload"></i> {{ lang._("Import VLESS link") }}' +
                    '</a>' +
                    '<div id="dlgImportPanel" class="collapse">' +
                        '<div class="well well-sm" style="margin-bottom: 0;">' +
                            '<div class="input-group">' +
                                '<input type="text" id="dlgImportLink" class="form-control input-sm"' +
                                '  style="font-family: monospace; font-size: 12px;"' +
                                '  placeholder="vless://UUID@host:443?security=reality&pbk=...#Name" />' +
                                '<span class="input-group-btn">' +
                                    '<button type="button" id="dlgImportParseBtn" class="btn btn-sm btn-primary">' +
                                        '<i class="fa fa-magic"></i> {{ lang._("Parse & Fill") }}' +
                                    '</button>' +
                                '</span>' +
                            '</div>' +
                            '<span id="dlgImportResult" style="font-size: 12px; display: inline-block; margin-top: 4px;"></span>' +
                        '</div>' +
                    '</div>' +
                '</div>';
            var $body = $(this).find('.modal-body');
            $body.prepend(importHtml);

            // Helper buttons near server inbound fields (v4.0.0)
            var $usersTa = $body.find('[id="instance.srv_users"]');
            $usersTa.closest('td').append(
                '<div style="margin-top: 6px;">' +
                    '<button type="button" id="dlgAddUserBtn" class="btn btn-sm btn-default">' +
                        '<i class="fa fa-user-plus"></i> {{ lang._("Add user (generate UUID)") }}' +
                    '</button> ' +
                    '<button type="button" id="dlgNginxSnippetBtn" class="btn btn-sm btn-default">' +
                        '<i class="fa fa-code"></i> {{ lang._("nginx snippet") }}' +
                    '</button>' +
                '</div>'
            );

            // Footer buttons (v4.0.0): Validate Config + Format (raw mode),
            // Preview Generated Config (wizard mode)
            var validateHtml =
                '<button type="button" id="dlgValidateBtn" class="btn btn-info pull-left">' +
                    '<i class="fa fa-check-circle"></i> {{ lang._("Validate Config") }}' +
                '</button>' +
                '<button type="button" id="dlgFormatBtn" class="btn btn-default pull-left" style="margin-left: 4px;">' +
                    '{{ lang._("Format") }}' +
                '</button>' +
                '<button type="button" id="dlgPreviewBtn" class="btn btn-default pull-left" style="margin-left: 4px;">' +
                    '<i class="fa fa-eye"></i> {{ lang._("Preview Generated Config") }}' +
                '</button>' +
                '<span id="dlgValidateResult" class="pull-left" style="font-size: 12px; line-height: 34px; margin-left: 8px;"></span>' +
                '<span id="dlgPreviewResult" class="pull-left" style="font-size: 12px; line-height: 34px; margin-left: 8px;"></span>';
            var $footer = $(this).find('.modal-footer');
            $footer.prepend(validateHtml);
        });

        // ── v4.0.0: CodeMirror editor for the raw config ─────────────
        var rawCm = null;

        function initRawEditor() {
            if (rawCm !== null || !window.CodeMirror) {
                return;
            }
            var $ta = $('#DialogInstance').find('[id="instance.full_config"]');
            if (!$ta.length || $ta.prop('tagName') !== 'TEXTAREA') {
                return;
            }
            rawCm = window.CodeMirror.fromTextArea($ta.get(0), {
                mode: 'application/json',
                lineNumbers: true,
                indentUnit: 4,
                lineWrapping: true,
                matchBrackets: true,
                viewportMargin: Infinity
            });
            rawCm.on('change', function () { rawCm.save(); });
            rawCm.setSize(null, 420);
        }

        function rawEditorValue() {
            if (rawCm !== null) {
                rawCm.save();
                return rawCm.getValue();
            }
            return $('#DialogInstance').find('[id="instance.full_config"]').val() || '';
        }

        function setRawEditorValue(v) {
            if (rawCm !== null) {
                rawCm.setValue(v);
                rawCm.save();
            } else {
                $('#DialogInstance').find('[id="instance.full_config"]').val(v);
            }
        }

        // ── v4.0.0: Config Source / sections sync ─────────────────────
        // Селекторы через [id="…"]: у полей модели точка в id, и $('#instance.group')
        // jQuery читает как id="instance" + класс "group".
        var xrayServerGroup = {};   // server uuid -> group uuid

        function xrayLoadServerGroups(then) {
            $.ajax({url: '/api/xray/group/searchServer', type: 'GET', dataType: 'json'})
                .done(function (data) {
                    xrayServerGroup = {};
                    (data.rows || []).forEach(function (row) {
                        xrayServerGroup[row.uuid] = row.group;
                    });
                    if (then) then();
                })
                .fail(function () { if (then) then(); });
        }

        function xraySyncDialog() {
            var $mode = $('[id="instance.node_source"]');
            if (!$mode.length) return;
            var mode = $mode.val() || 'config';

            // 1. Источник узла
            $('[id="instance.outbound_config"]').closest('tr').toggle(mode === 'config');
            $('#dlgImportWrap').toggle(mode === 'config');
            $('[id="instance.group"]').closest('tr').toggle(mode === 'group');
            $('[id="instance.server"]').closest('tr').toggle(mode === 'group');
            $('[id="instance.full_config"]').closest('tr').toggle(mode === 'raw');
            if (rawCm !== null) {
                $(rawCm.getWrapperElement()).toggle(mode === 'raw');
                if (mode === 'raw') rawCm.refresh();
            }

            // 2. Footer buttons: raw → Validate/Format, иначе → Preview
            $('#dlgValidateBtn').toggle(mode === 'raw');
            $('#dlgFormatBtn').toggle(mode === 'raw');
            $('#dlgPreviewBtn').toggle(mode !== 'raw');

            // 3. Список Server ограничиваем выбранной группой (режим group)
            if (mode === 'group') {
                var gid = $('[id="instance.group"]').val() || '';
                var $srv = $('[id="instance.server"]');
                var current = $srv.val();
                var mapped = !$.isEmptyObject(xrayServerGroup);
                $srv.find('option').each(function () {
                    var v = $(this).val();
                    var mine = (v === '' || v === current || gid === '' || !mapped
                                || xrayServerGroup[v] === gid);
                    $(this).prop('disabled', !mine).toggle(mine);
                });
                if (current && gid && mapped && xrayServerGroup[current] !== undefined
                    && xrayServerGroup[current] !== gid) {
                    $srv.val('');
                }
                if ($srv.hasClass('selectpicker')) {
                    $srv.selectpicker('refresh');
                }
            }

            // 4. TUN-драйвер: gateway/auto-routing/auto-dns — только для xray
            var driver = $('[id="instance.tun_driver"]').val() || 'xray';
            var full = $('[id="instance.tun_auto_routing"]').val() === 'full';
            $('[id="instance.tun_gateway"]').closest('tr').toggle(driver === 'xray');
            $('[id="instance.tun_auto_routing"]').closest('tr').toggle(driver === 'xray');
            $('[id="instance.tun_auto_dns"]').closest('tr').toggle(driver === 'xray' && full);

            // 5. DNS upstream — только в hijack
            $('[id="instance.dns_upstream"]').closest('tr')
                .toggle($('[id="instance.dns_mode"]').val() === 'hijack');

            // 6. LB: custom list — только когда LB включён и режим config
            var lbOn = $('[id="instance.lb_enabled"]').is(':checked');
            $('[id="instance.lb_strategy"]').closest('tr').toggle(lbOn);
            $('[id="instance.lb_probe_url"]').closest('tr').toggle(lbOn);
            $('[id="instance.lb_probe_interval"]').closest('tr').toggle(lbOn);
            $('[id="instance.lb_custom"]').closest('tr').toggle(lbOn && mode === 'config');

            // 7. Server inbound: поля — только когда включён
            var srvOn = $('[id="instance.srv_enabled"]').is(':checked');
            var srvTransport = $('[id="instance.srv_transport"]').val() || 'ws';
            var srvSecurity = $('[id="instance.srv_security"]').val() || 'none';
            var srvFields = ['srv_listen', 'srv_port', 'srv_users', 'srv_transport', 'srv_path',
                             'srv_security', 'srv_accept_proxy_protocol', 'srv_custom_inbounds',
                             'srv_default_outbound'];
            $.each(srvFields, function (_, f) {
                $('[id="instance.' + f + '"]').closest('tr').toggle(srvOn);
            });
            $('#dlgAddUserBtn').toggle(srvOn);
            $('#dlgNginxSnippetBtn').toggle(srvOn);
            $('[id="instance.srv_tls_cert"]').closest('tr').toggle(srvOn && srvSecurity === 'tls');
            $('[id="instance.srv_tls_key"]').closest('tr').toggle(srvOn && srvSecurity === 'tls');
            $('[id="instance.srv_reality_privatekey"]').closest('tr').toggle(srvOn && srvSecurity === 'reality');
            $('[id="instance.srv_reality_shortids"]').closest('tr').toggle(srvOn && srvSecurity === 'reality');
            $('[id="instance.srv_reality_servernames"]').closest('tr').toggle(srvOn && srvSecurity === 'reality');
            $('[id="instance.srv_reality_dest"]').closest('tr').toggle(srvOn && srvSecurity === 'reality');
            // path не нужен для tcp
            $('[id="instance.srv_path"]').closest('tr').toggle(srvOn && srvTransport !== 'tcp');
        }

        $('#DialogInstance').on('shown.bs.modal', function () {
            // значения полей приезжают асинхронно через get, поэтому с задержкой
            xrayLoadServerGroups(function () {
                setTimeout(function () {
                    initRawEditor();
                    if (rawCm !== null) {
                        var saved = $('#DialogInstance').find('[id="instance.full_config"]').val() || '';
                        var cur = rawCm.getValue();
                        if (saved !== cur) {
                            setRawEditorValue(saved);
                        }
                    }
                    xraySyncDialog();
                }, 300);
            });
        });
        $(document).on('change', '[id="instance.node_source"], [id="instance.group"], ' +
            '[id="instance.tun_driver"], [id="instance.tun_auto_routing"], [id="instance.dns_mode"], ' +
            '[id="instance.lb_enabled"], [id="instance.srv_enabled"], [id="instance.srv_transport"], ' +
            '[id="instance.srv_security"]', xraySyncDialog);
        $(document).on('ifChanged', '[id="instance.lb_enabled"], [id="instance.srv_enabled"]', xraySyncDialog);

        // Import parse handler (inside dialog)
        $(document).on('click', '#dlgImportParseBtn', function () {
            var link = $.trim($('#dlgImportLink').val());
            var $res = $('#dlgImportResult');
            if (!link) {
                $res.removeClass('text-success').addClass('text-danger')
                    .text("{{ lang._('Paste a VLESS link first.') }}");
                return;
            }

            var $btn = $(this).prop('disabled', true);
            $res.removeClass('text-success text-danger').text("{{ lang._('Parsing...') }}");
            var b64 = btoa(unescape(encodeURIComponent(link)));

            $.ajax({
                url:         '/api/xray/import/parse',
                type:        'POST',
                contentType: 'application/json; charset=utf-8',
                data:        JSON.stringify({link_b64: b64}),
                dataType:    'json',
                success: function (data) {
                    $btn.prop('disabled', false);
                    if (data.status !== 'ok') {
                        $res.removeClass('text-success').addClass('text-danger')
                            .text("{{ lang._('Parse error:') }} " + (data.message || 'unknown'));
                        return;
                    }
                    applyImportToDialog(data);
                    $res.removeClass('text-danger').addClass('text-success')
                        .text("{{ lang._('Imported! Fields filled from link.') }}");
                    // Collapse import panel after success
                    setTimeout(function () { $('#dlgImportPanel').collapse('hide'); }, 1500);
                },
                error: function (xhr) {
                    $btn.prop('disabled', false);
                    $res.removeClass('text-success').addClass('text-danger')
                        .text("{{ lang._('HTTP error:') }} " + xhr.status);
                }
            });
        });

        // Enter key in import field triggers parse
        $(document).on('keypress', '#dlgImportLink', function (e) {
            if (e.which === 13) {
                e.preventDefault();
                $('#dlgImportParseBtn').click();
            }
        });

        // ── Validate Config draft (raw mode) ─────────────────────────
        $(document).on('click', '#dlgValidateBtn', function () {
            var $btn = $(this).prop('disabled', true);
            var $res = $('#dlgValidateResult');
            var draft = rawEditorValue();

            if ($.trim(draft) === '') {
                $btn.prop('disabled', false);
                $res.removeClass('text-success').addClass('text-danger')
                    .text("{{ lang._('Config is empty.') }}");
                return;
            }
            $res.removeClass('text-success text-danger').text("{{ lang._('Validating...') }}");

            $.ajax({
                url:      '/api/xray/service/validateConfig',
                type:     'POST',
                contentType: 'application/json; charset=utf-8',
                data:     JSON.stringify({config: draft}),
                dataType: 'json',
                success: function (data) {
                    $btn.prop('disabled', false);
                    if (data.result === 'ok') {
                        $res.removeClass('text-danger').addClass('text-success')
                            .text(data.message || "{{ lang._('Config is valid') }}");
                    } else {
                        $res.removeClass('text-success').addClass('text-danger')
                            .text(data.message || "{{ lang._('Validation failed') }}");
                    }
                },
                error: function (xhr) {
                    $btn.prop('disabled', false);
                    $res.addClass('text-danger').text("{{ lang._('HTTP error:') }} " + xhr.status);
                }
            });
        });

        // ── Format raw JSON ────────────────────────────────────────────
        $(document).on('click', '#dlgFormatBtn', function () {
            var $res = $('#dlgValidateResult');
            try {
                var parsed = JSON.parse(rawEditorValue());
                setRawEditorValue(JSON.stringify(parsed, null, 4));
                $res.removeClass('text-danger').addClass('text-success')
                    .text("{{ lang._('Formatted.') }}");
            } catch (e) {
                $res.removeClass('text-success').addClass('text-danger')
                    .text("{{ lang._('Cannot format:') }} " + e.message);
            }
        });

        // ── Preview generated config (wizard mode) ────────────────────
        // UUID редактируемой записи: UIBootgrid не кладёт его на диалог,
        // поэтому перехватываем клик по кнопке редактирования в строке.
        var xrayEditingUuid = null;
        $(document).on('click', '#grid-instances .command-edit', function () {
            xrayEditingUuid = $(this).data('row-id') || null;
        });
        $(document).on('click', '#grid-instances [data-action="add"]', function () {
            xrayEditingUuid = null;
        });

        $(document).on('click', '#dlgPreviewBtn', function () {
            var $btn = $(this).prop('disabled', true);
            var $res = $('#dlgPreviewResult');
            var uuid = xrayEditingUuid;

            if (!uuid) {
                $btn.prop('disabled', false);
                $res.removeClass('text-success').addClass('text-danger')
                    .text("{{ lang._('Save the instance first.') }}");
                return;
            }
            $res.removeClass('text-success text-danger').text("{{ lang._('Building...') }}");

            $.ajax({
                url:  '/api/xray/service/previewConfig/' + encodeURIComponent(uuid),
                type: 'POST',
                dataType: 'json',
                success: function (data) {
                    $btn.prop('disabled', false);
                    if (data.result !== 'ok') {
                        $res.removeClass('text-success').addClass('text-danger')
                            .text(data.message || 'build failed');
                        return;
                    }
                    $res.removeClass('text-danger').addClass('text-success')
                        .text("{{ lang._('OK') }}");
                    $('#configPreviewPre').text(data.config || '');
                    $('#ConfigPreviewModal').modal('show');
                },
                error: function (xhr) {
                    $btn.prop('disabled', false);
                    $res.removeClass('text-success').addClass('text-danger')
                        .text("{{ lang._('HTTP error:') }} " + xhr.status);
                }
            });
        });

        // ── Add user (generate UUID into srv_users) ───────────────────
        $(document).on('click', '#dlgAddUserBtn', function () {
            var $ta = $('#DialogInstance').find('[id="instance.srv_users"]');
            var list = [];
            var raw = $.trim($ta.val() || '');
            if (raw !== '') {
                try {
                    list = JSON.parse(raw);
                } catch (e) {
                    alert("{{ lang._('Users field is not valid JSON — fix it first.') }}");
                    return;
                }
            }
            if (!$.isArray(list)) {
                alert("{{ lang._('Users field must be a JSON array.') }}");
                return;
            }
            var uuid = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
                var r = Math.random() * 16 | 0;
                var v = c === 'x' ? r : (r & 0x3 | 0x8);
                return v.toString(16);
            });
            list.push({id: uuid, email: 'user' + (list.length + 1), flow: ''});
            $ta.val(JSON.stringify(list, null, 4));
        });

        // ── nginx snippet ──────────────────────────────────────────────
        function buildNginxSnippets() {
            var listen = $('[id="instance.srv_listen"]').val() || '127.0.0.1';
            var port   = $('[id="instance.srv_port"]').val() || '10086';
            var path   = $('[id="instance.srv_path"]').val() || '/vless';
            var target = listen + ':' + port;

            var ws =
                '# nginx http server block (reverse proxy for the VLESS WebSocket inbound)\n' +
                'location ' + path + ' {\n' +
                '    proxy_pass http://' + target + ';\n' +
                '    proxy_http_version 1.1;\n' +
                '    proxy_set_header Upgrade $http_upgrade;\n' +
                '    proxy_set_header Connection "upgrade";\n' +
                '    proxy_set_header Host $host;\n' +
                '    proxy_set_header X-Real-IP $remote_addr;\n' +
                '    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;\n' +
                '    proxy_read_timeout 300s;\n' +
                '    proxy_send_timeout 300s;\n' +
                '}\n' +
                '# TLS terminates on nginx (configure certificates on the server {} level).\n' +
                '# Other paths can serve a decoy site or return 404.';

            var grpc =
                '# nginx http server block (gRPC needs HTTP/2 on the listen socket)\n' +
                'location / {\n' +
                '    grpc_pass grpc://' + target + ';\n' +
                '    grpc_read_timeout 300s;\n' +
                '    grpc_send_timeout 300s;\n' +
                '}\n' +
                '# listen 443 ssl http2;  — required for gRPC.';

            var stream =
                '# nginx stream block (raw TCP / Reality pass-through)\n' +
                'stream {\n' +
                '    server {\n' +
                '        listen 443;\n' +
                '        proxy_pass ' + target + ';\n' +
                '        # To keep the real client IP also enable in Xray:\n' +
                '        # "Accept PROXY Protocol", then uncomment:\n' +
                '        # proxy_protocol on;\n' +
                '    }\n' +
                '}\n' +
                '# For Reality/xTLS use the stream module or expose Xray directly on WAN.';

            $('#nginxSnippetWs').text(ws);
            $('#nginxSnippetGrpc').text(grpc);
            $('#nginxSnippetStream').text(stream);
        }

        $(document).on('click', '#dlgNginxSnippetBtn', function () {
            buildNginxSnippets();
            $('#nginxSnippetTabs a:first').tab('show');
            $('#NginxSnippetModal').modal('show');
        });

        $(document).on('click', '#btnCopyNginxSnippet', function () {
            var active = $('#nginxSnippetTabs .active a').attr('href');
            var text = $(active + ' pre').text();
            var $btn = $(this);
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function () {
                    $btn.text("{{ lang._('Copied!') }}");
                    setTimeout(function () { $btn.text("{{ lang._('Copy active tab') }}"); }, 1500);
                });
            } else {
                alert(text);
            }
        });

        // ── Diagnostics ─────────────────────────────────────────────
        function populateInstanceSelects() {
            $.ajax({
                url: '/api/xray/instance/searchItem',
                type: 'POST',
                dataType: 'json',
                data: {rowCount: -1, current: 1, searchPhrase: ''},
                success: function (data) {
                    var rows = (data && data.rows) ? data.rows : [];
                    var $diagSel = $('#diagInstanceSelect');
                    var $logSel = $('#logInstanceSelect');
                    var savedDiag = $diagSel.val();
                    var savedLog = $logSel.val();
                    $diagSel.empty();
                    $logSel.empty();
                    $.each(rows, function (_, row) {
                        $diagSel.append($('<option></option>').val(row.uuid).text(row.name || row.uuid));
                        $logSel.append($('<option></option>').val(row.uuid).text(row.name || row.uuid));
                    });
                    if (savedDiag && $diagSel.find('option[value="' + savedDiag + '"]').length) {
                        $diagSel.val(savedDiag);
                    }
                    if (savedLog && $logSel.find('option[value="' + savedLog + '"]').length) {
                        $logSel.val(savedLog);
                    }
                }
            });
        }

        function loadDiagnostics() {
            var uuid = $('#diagInstanceSelect').val();
            var url = '/api/xray/service/diagnostics' + (uuid ? '/' + encodeURIComponent(uuid) : '');
            $('#btnDiagRefresh').prop('disabled', true);
            $('#diagError').hide();
            ajaxGet(url, {}, function (data) {
                $('#btnDiagRefresh').prop('disabled', false);
                if (data.error) {
                    $('#diagError').text(data.error).show();
                    return;
                }
                var running = data.tun_status === 'running';
                var statusHtml = running
                    ? '<span class="label label-success">running</span>'
                    : '<span class="label label-danger">' + escAttr(data.tun_status || 'down') + '</span>';

                $('#diag_tun_iface').text(data.tun_interface  || '\u2014');
                $('#diag_tun_status').html(statusHtml);
                $('#diag_tun_ip').text(data.tun_ip           || '\u2014');
                $('#diag_mtu').text(data.mtu > 0 ? data.mtu + ' bytes' : '\u2014');
                $('#diag_bytes_in').text(data.bytes_in_hr    || '\u2014');
                $('#diag_bytes_out').text(data.bytes_out_hr  || '\u2014');
                $('#diag_pkts_in').text(data.pkts_in != null ? data.pkts_in.toLocaleString() : '\u2014');
                $('#diag_pkts_out').text(data.pkts_out != null ? data.pkts_out.toLocaleString() : '\u2014');
                $('#diag_xray_uptime').text(data.xray_uptime || '\u2014');
                $('#diag_t2s_uptime').text(data.tun2socks_uptime || '\u2014');
                $('#diag_ping_rtt').text(data.ping_rtt || 'N/A');

                // v4.0.0: environment
                $('#diag_tun_driver').text(data.tun_driver || '\u2014');
                $('#diag_t2s_row').toggle(data.tun_driver !== 'xray');
                $('#diag_xray_version').text(data.xray_version || '\u2014');
                $('#diag_xray_ver_warn').toggle(data.xray_native_tun_ok !== 1);
                var geo = '\u2014';
                if (data.geoip_ok != null && data.geosite_ok != null) {
                    geo = 'geoip.dat: ' + (data.geoip_ok ? '<span class="label label-success">ok</span>' : '<span class="label label-danger">missing</span>')
                        + ' &nbsp; geosite.dat: ' + (data.geosite_ok ? '<span class="label label-success">ok</span>' : '<span class="label label-danger">missing</span>');
                }
                $('#diag_geodata').html(geo);
                var fibs = (data.net_fibs != null) ? String(data.net_fibs) : '\u2014';
                $('#diag_net_fibs').text(fibs);
                $('#diag_fibs_warn').toggle(data.net_fibs != null && data.net_fibs < 2);

                var ports = '\u2014';
                if (data.listen_ports && data.listen_ports.length) {
                    ports = data.listen_ports.map(function (p) {
                        return p.port + ': ' + (p.listening
                            ? '<span class="label label-success">listening</span>'
                            : '<span class="label label-danger">no</span>');
                    }).join(' &nbsp; ');
                }
                $('#diag_ports').html(ports);
            });
        }

        var diagAutoRefresh = null;
        $('a[href="#diagnostics"]').on('shown.bs.tab', function () {
            loadDiagnostics();
            if (!diagAutoRefresh) {
                diagAutoRefresh = setInterval(function () {
                    if ($('#diagnostics').hasClass('active')) {
                        loadDiagnostics();
                    }
                }, 30000);
            }
        });
        $('#btnDiagRefresh').click(function () {
            loadDiagnostics();
        });
        $('#diagInstanceSelect').on('change', function () {
            loadDiagnostics();
        });
        $('#logInstanceSelect').on('change', function () {
            loadLog("/api/xray/service/xraylog", 'logCoreContent', 'logCoreRefreshBtn');
        });

        // ── Logs ────────────────────────────────────────────────────
        function loadLog(apiEndpoint, preId, btnId) {
            var uuid = $('#logInstanceSelect').val();
            var apiEndpointWithUuid = apiEndpoint + (uuid ? '/' + encodeURIComponent(uuid) : '');
            $('#' + btnId).prop('disabled', true);
            $('#' + preId).text("{{ lang._('Loading...') }}");
            $.post(apiEndpointWithUuid, null, function (data) {
                var text = (data && data.log) || "{{ lang._('Log is empty.') }}";
                $('#' + preId).text(text);
                $('#' + btnId).prop('disabled', false);
                var pre = document.getElementById(preId);
                if (pre) { pre.scrollTop = pre.scrollHeight; }
            }, 'json').fail(function (xhr) {
                $('#' + preId).text("{{ lang._('Error loading log:') }} " + xhr.status);
                $('#' + btnId).prop('disabled', false);
            });
        }

        $('a[href="#logs"]').on('shown.bs.tab', function () {
            var $active = $('#logSubTabs .active a');
            var href = $active.attr('href');
            if (href === '#logBoot') {
                loadLog("/api/xray/service/log", 'logBootContent', 'logBootRefreshBtn');
            } else if (href === '#logCore') {
                loadLog("/api/xray/service/xraylog", 'logCoreContent', 'logCoreRefreshBtn');
            }
        });

        $('#logSubTabs a').on('shown.bs.tab', function (e) {
            var href = $(e.target).attr('href');
            if (href === '#logBoot') {
                loadLog("/api/xray/service/log", 'logBootContent', 'logBootRefreshBtn');
            } else if (href === '#logCore') {
                loadLog("/api/xray/service/xraylog", 'logCoreContent', 'logCoreRefreshBtn');
            }
        });

        $("#logBootRefreshBtn").click(function () {
            loadLog("/api/xray/service/log", 'logBootContent', 'logBootRefreshBtn');
        });
        $("#logCoreRefreshBtn").click(function () {
            loadLog("/api/xray/service/xraylog", 'logCoreContent', 'logCoreRefreshBtn');
        });

        // ── Copy Debug Info ─────────────────────────────────────────
        $('#btnCopyDebug').click(function () {
            var $btn = $(this).prop('disabled', true);
            var $res = $('#copyDebugResult');
            $res.removeClass('text-success text-danger').text("{{ lang._('Collecting...') }}");

            var diagData = {}, bootLog = '', coreLog = '';
            var diagDone = $.Deferred(), bootDone = $.Deferred(), coreDone = $.Deferred();

            ajaxGet('/api/xray/service/diagnostics', {}, function (data) {
                diagData = data;
                diagDone.resolve();
            });
            $.post('/api/xray/service/log', null, function (data) {
                bootLog = (data && data.log) || '';
                bootDone.resolve();
            }, 'json').fail(function () { bootDone.resolve(); });
            $.post('/api/xray/service/xraylog', null, function (data) {
                coreLog = (data && data.log) || '';
                coreDone.resolve();
            }, 'json').fail(function () { coreDone.resolve(); });

            $.when(diagDone, bootDone, coreDone).done(function () {
                var info = "=== os-xray Debug Info ===\n"
                    + "Date: " + new Date().toISOString() + "\n\n"
                    + "--- Diagnostics ---\n"
                    + JSON.stringify(diagData, null, 2) + "\n\n"
                    + "--- Boot Log (last 150 lines) ---\n"
                    + bootLog + "\n\n"
                    + "--- Core Log (last 200 lines) ---\n"
                    + coreLog + "\n";

                $('#debugInfoContent').val(info);
                $('#debugInfoModal').modal('show');
                $('#debugInfoModal').one('shown.bs.modal', function () {
                    var ta = document.getElementById('debugInfoContent');
                    ta.focus();
                    ta.select();
                });
                $res.addClass('text-success').text("{{ lang._('Use Ctrl+C / Cmd+C to copy') }}");
                $btn.prop('disabled', false);
            });
        });

        // ── Tab hash ────────────────────────────────────────────────
        if (window.location.hash !== "") {
            $('a[href="' + window.location.hash + '"]').click();
        }
        $('.nav-tabs a').on('shown.bs.tab', function (e) {
            history.pushState(null, null, e.target.hash);
        });
    });
</script>

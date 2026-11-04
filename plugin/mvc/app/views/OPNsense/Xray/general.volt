{{ partial('OPNsense/Xray/partials/scripts') }}

<link rel="stylesheet" href="/js/xray-codemirror.min.css">
<script src="/js/xray-codemirror.min.js"></script>
<script src="/js/xray-javascript.min.js"></script>
<script src="/js/xray-qrcode.min.js"></script>

<ul class="nav nav-tabs" data-tabs="tabs" id="maintabs">
    <li class="active"><a data-toggle="tab" href="#instances">{{ lang._('Instances') }}</a></li>
    <li><a data-toggle="tab" href="#groups">{{ lang._('Groups') }}</a></li>
    <li><a data-toggle="tab" href="#routing">{{ lang._('Routing') }}</a></li>
    <li><a data-toggle="tab" href="#general">{{ lang._('General') }}</a></li>
    <li><a data-toggle="tab" href="#diagnostics">{{ lang._('Diagnostics') }}</a></li>
    <li><a data-toggle="tab" href="#logs">{{ lang._('Log') }}</a></li>
</ul>

<div class="tab-content content-box">
    {{ partial('OPNsense/Xray/partials/tab_instances') }}

    <div id="general" class="tab-pane fade in">
        {{ partial("layout_partials/base_form", {'fields': generalForm, 'id': 'frm_general_settings'}) }}
    </div>

    {{ partial('OPNsense/Xray/partials/tab_groups') }}
    {{ partial('OPNsense/Xray/partials/tab_routing') }}
    {{ partial('OPNsense/Xray/partials/tab_diagnostics') }}
    {{ partial('OPNsense/Xray/partials/tab_logs') }}
</div>

{{ partial('layout_partials/base_apply_button', {'data_endpoint': '/api/xray/service/reconfigure'}) }}

{{ partial("layout_partials/base_dialog", ['fields': instanceForm, 'id': 'DialogInstance', 'label': lang._('Edit Instance')]) }}

{{ partial("layout_partials/base_dialog", ['fields': groupForm, 'id': 'DialogGroup', 'label': lang._('Edit Group')]) }}
{{ partial("layout_partials/base_dialog", ['fields': serverForm, 'id': 'DialogServer', 'label': lang._('Edit Server')]) }}
{{ partial("layout_partials/base_dialog", ['fields': ruleForm, 'id': 'DialogRule', 'label': lang._('Edit Routing Rule')]) }}

{{ partial('OPNsense/Xray/partials/modal_debug') }}

{# ── v4.0.0: Preview generated config ─────────────────────────────────── #}
<div class="modal fade" id="ConfigPreviewModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
                <h4 class="modal-title">{{ lang._('Generated config.json') }}</h4>
            </div>
            <div class="modal-body">
                <p class="text-muted" style="font-size: 12px;">
                    {{ lang._('This is what will be written to /usr/local/etc/xray-core/ on Apply (current saved settings).') }}
                </p>
                <pre id="configPreviewPre" style="max-height: 60vh; overflow: auto;"></pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">{{ lang._('Close') }}</button>
            </div>
        </div>
    </div>
</div>

{# ── v4.0.0: nginx snippet for the server inbound ────────────────────── #}
<div class="modal fade" id="NginxSnippetModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
                <h4 class="modal-title">{{ lang._('nginx snippet for this inbound') }}</h4>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs" id="nginxSnippetTabs">
                    <li class="active"><a data-toggle="tab" href="#ngsnipWs">WebSocket</a></li>
                    <li><a data-toggle="tab" href="#ngsnipXhttp">XHTTP</a></li>
                    <li><a data-toggle="tab" href="#ngsnipGrpc">gRPC</a></li>
                    <li><a data-toggle="tab" href="#ngsnipStream">Stream (TCP/Reality)</a></li>
                </ul>
                <div class="tab-content" style="padding-top: 10px;">
                    <div id="ngsnipWs" class="tab-pane fade in active">
                        <pre id="nginxSnippetWs" style="max-height: 45vh; overflow: auto;"></pre>
                    </div>
                    <div id="ngsnipXhttp" class="tab-pane fade">
                        <pre id="nginxSnippetXhttp" style="max-height: 45vh; overflow: auto;"></pre>
                    </div>
                    <div id="ngsnipGrpc" class="tab-pane fade">
                        <pre id="nginxSnippetGrpc" style="max-height: 45vh; overflow: auto;"></pre>
                    </div>
                    <div id="ngsnipStream" class="tab-pane fade">
                        <pre id="nginxSnippetStream" style="max-height: 45vh; overflow: auto;"></pre>
                    </div>
                </div>
                <p class="text-muted" style="font-size: 12px;">
                    {{ lang._('Place into the nginx plugin (Services → Nginx) or a custom include. Open the WAN port in Firewall → Rules.') }}
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" id="btnCopyNginxSnippet">{{ lang._('Copy active tab') }}</button>
                <button type="button" class="btn btn-default" data-dismiss="modal">{{ lang._('Close') }}</button>
            </div>
        </div>
    </div>
</div>

{# ── v4.1.0: client QR (share link) ────────────────────────────────────── #}
<div class="modal fade" id="ClientQRModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
                <h4 class="modal-title" id="clientQrTitle">{{ lang._('Client import link') }}</h4>
            </div>
            <div class="modal-body" style="text-align: center;">
                <div id="clientQrImg" style="margin: 0 auto 10px; display: inline-block;"></div>
                <pre id="clientQrUri" style="text-align: left; max-height: 18vh; overflow: auto; font-size: 11px; word-break: break-all;"></pre>
                <p class="text-muted" style="font-size: 12px;">
                    {{ lang._('Scan with a client app (v2rayNG, Nekoray, Streisand, ...) or copy the link.') }}
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" id="btnCopyClientLink">{{ lang._('Copy link') }}</button>
                <button type="button" class="btn btn-default" data-dismiss="modal">{{ lang._('Close') }}</button>
            </div>
        </div>
    </div>
</div>

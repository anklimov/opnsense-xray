<!-- ROUTING RULES -->
<div id="routing" class="tab-pane fade in">

    {# v4.3.0: drag&drop переупорядочивание правил (порядок = порядок применения) #}
    <style>
        .xray-drag-handle { cursor: move; color: #999; }
        .xray-drag-handle:hover { color: #337ab7; }
        #grid-rules tbody tr.xray-drop-target { outline: 2px dashed #337ab7; outline-offset: -2px; }
        #grid-rules tbody tr.xray-dragging { opacity: 0.5; }
    </style>

    {# ── Toolbar ─────────────────────────────────────────────────────── #}
    <div class="row">
        <section class="col-xs-12">
            <div style="padding: 8px 15px; border-bottom: 1px solid #ddd;
                        display: flex; flex-wrap: wrap; align-items: center; gap: 8px;">

                <div class="input-group input-group-sm" style="min-width: 220px; width: auto;">
                    <span class="input-group-addon"><i class="fa fa-server fa-fw"></i></span>
                    <select id="ruleInstanceFilter" class="form-control">
                        <option value="">{{ lang._('All instances') }}</option>
                    </select>
                </div>

                <span style="font-size: 12px; color: #666;">
                    {{ lang._('Rules are applied in creation order; technical rules (DNS hijack) come first, private networks → direct after them.') }}
                </span>
            </div>
        </section>
    </div>

    {# ── Rules grid ──────────────────────────────────────────────────── #}
    <div class="row">
        <section class="col-xs-12">
            <table id="grid-rules"
                   class="table table-condensed table-hover table-striped"
                   data-editDialog="DialogRule"
                   data-editAlert="RuleChangeMessage">
                <thead>
                    <tr>
                        <th data-column-id="uuid"
                            data-type="string"
                            data-identifier="true"
                            data-visible="false">{{ lang._('ID') }}</th>

                        <th data-column-id="drag"
                            data-width="2.5em"
                            data-sortable="false"
                            data-formatter="dragHandle">{{ lang._('') }}</th>

                        <th data-column-id="enabled"
                            data-width="6em"
                            data-type="string"
                            data-formatter="rowtoggle">{{ lang._('Enabled') }}</th>

                        <th data-column-id="instance_name"
                            data-type="string">{{ lang._('Instance') }}</th>

                        <th data-column-id="name"
                            data-type="string">{{ lang._('Name') }}</th>

                        <th data-column-id="type"
                            data-type="string"
                            data-width="9em">{{ lang._('Match') }}</th>

                        <th data-column-id="value"
                            data-type="string">{{ lang._('Value') }}</th>

                        <th data-column-id="action"
                            data-type="string"
                            data-width="9em"
                            data-formatter="ruleAction">{{ lang._('Action') }}</th>

                        <th data-column-id="commands"
                            data-formatter="commands"
                            data-sortable="false"
                            data-width="7em">{{ lang._('') }}</th>
                    </tr>
                </thead>
                <tbody></tbody>
                <tfoot>
                    <tr>
                        <td></td>
                        <td>
                            <button data-action="add" type="button" class="btn btn-xs btn-primary">
                                <span class="fa fa-fw fa-plus"></span>
                            </button>
                            <button data-action="deleteSelected" type="button" class="btn btn-xs btn-default">
                                <span class="fa fa-fw fa-trash-o"></span>
                            </button>
                        </td>
                    </tr>
                </tfoot>
            </table>

            <div id="RuleChangeMessage" class="alert alert-info" style="display: none;" role="alert">
                {{ lang._('After saving your changes, please click Apply to activate them.') }}
            </div>
        </section>
    </div>

</div>

<div class="panel panel-default">
    <div class="panel-heading">
        <h4 class="panel-title">
            Xero
            {{#if isLinked}}
            <span style="float:right;font-size:11px;font-weight:400;color:#2b7de9">&#10003; Synced</span>
            {{else}}
            <span style="float:right;font-size:11px;font-weight:400;color:#999">Not synced</span>
            {{/if}}
        </h4>
    </div>
    <div class="panel-body" style="font-size:13px">
        <div class="row" style="margin-bottom:6px">
            <div class="col-sm-5" style="color:#888">Contact ID</div>
            <div class="col-sm-7">{{xeroContactId}}</div>
        </div>
        <div class="row">
            <div class="col-sm-5" style="color:#888">Last Synced</div>
            <div class="col-sm-7">{{xeroSyncedAt}}</div>
        </div>
    </div>
</div>

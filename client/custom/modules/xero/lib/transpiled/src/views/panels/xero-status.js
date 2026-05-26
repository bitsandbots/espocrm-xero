define("modules/xero/views/panels/xero-status", ["exports", "view"], function (_exports, _view) {
  "use strict";

  Object.defineProperty(_exports, "__esModule", {
    value: true
  });
  _exports.default = void 0;
  _view = _interopRequireDefault(_view);
  function _interopRequireDefault(e) { return e && e.__esModule ? e : { default: e }; }
  /**
   * Side panel shown on Account and Contact detail views.
   * Displays Xero sync status fields in read-only mode.
   */
  class XeroStatusPanelView extends _view.default {
    template = 'xero:panels/xero-status';
    data() {
      const model = this.model;
      const xeroId = model.get('xeroContactId');
      const syncedAt = model.get('xeroSyncedAt');
      return {
        isLinked: !!xeroId,
        xeroContactId: xeroId ?? '—',
        xeroSyncedAt: syncedAt ? new Date(syncedAt).toLocaleString() : '—'
      };
    }
  }
  _exports.default = XeroStatusPanelView;
});
//# sourceMappingURL=xero-status.js.map ;
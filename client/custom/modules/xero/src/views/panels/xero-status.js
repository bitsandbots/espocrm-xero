import View from 'view';

/**
 * Side panel shown on Account and Contact detail views.
 * Displays Xero sync status fields in read-only mode.
 */
export default class XeroStatusPanelView extends View {

    template = 'xero:panels/xero-status'

    data() {
        const model = this.model;
        const xeroId = model.get('xeroContactId');
        const syncedAt = model.get('xeroSyncedAt');

        return {
            isLinked: !!xeroId,
            xeroContactId: xeroId ?? '—',
            xeroSyncedAt: syncedAt
                ? new Date(syncedAt).toLocaleString()
                : '—',
        };
    }
}

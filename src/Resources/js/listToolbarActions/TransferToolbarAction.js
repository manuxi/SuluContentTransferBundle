import React from 'react';
import {translate} from 'sulu-admin-bundle/utils';
import {AbstractListToolbarAction, listToolbarActionRegistry} from 'sulu-admin-bundle/views';
import ExportToolbarAction from './ExportToolbarAction';
import ImportToolbarAction from './ImportToolbarAction';
import {PREFIX} from '../utils';

const ENTRY_CSV = 'csv';
const ENTRY_EXPORT = 'export';
const ENTRY_IMPORT = 'import';
const SULU_CSV_EXPORT = 'sulu_admin.export';

/**
 * One dropdown "Export/Import" in the toolbar of an article list. The entries are given in the option "entries":
 * "csv" exports the list as CSV (it uses the export of Sulu itself: same dialog, same download), "export" and
 * "import" open the dialogs of the package export and import. The dropdown holds one action of its own for each
 * entry and passes on what the list view tells every toolbar action.
 */
export default class TransferToolbarAction extends AbstractListToolbarAction {
    constructor(...args) {
        super(...args);

        const entries = this.getEntries();

        this.csvAction = entries.includes(ENTRY_CSV) ? this.createCsvAction(args) : undefined;
        this.exportAction = entries.includes(ENTRY_EXPORT) ? new ExportToolbarAction(...args) : undefined;
        this.importAction = entries.includes(ENTRY_IMPORT) ? new ImportToolbarAction(...args) : undefined;
    }

    getEntries() {
        const {entries = []} = this.options || {};

        return Array.isArray(entries) ? entries : [];
    }

    /**
     * The CSV export of Sulu, taken from the registry so that it is the one the list would show without this
     * dropdown. Without it the entry is left out.
     */
    createCsvAction(args) {
        try {
            const SuluExportToolbarAction = listToolbarActionRegistry.get(SULU_CSV_EXPORT);

            return new SuluExportToolbarAction(...args);
        } catch (e) {
            return undefined;
        }
    }

    getActions() {
        return [this.csvAction, this.exportAction, this.importAction].filter((toolbarAction) => toolbarAction);
    }

    setLocales(locales) {
        super.setLocales(locales);
        this.getActions().forEach((toolbarAction) => toolbarAction.setLocales(locales));
    }

    destroy() {
        this.getActions().forEach((toolbarAction) => toolbarAction.destroy());
    }

    getToolbarItemConfig() {
        const options = [];

        if (this.csvAction) {
            const {disabled, onClick} = this.csvAction.getToolbarItemConfig();

            options.push({label: translate(`${PREFIX}.transfer_csv`), disabled, onClick});
        }

        if (this.exportAction) {
            const count = this.listStore.selectionIds.length;
            const {disabled, onClick} = this.exportAction.getToolbarItemConfig();

            options.push({
                label: count > 0
                    ? translate(`${PREFIX}.transfer_export_count`, {count})
                    : translate(`${PREFIX}.transfer_export`),
                disabled,
                onClick,
            });
        }

        if (this.importAction) {
            const {disabled, onClick} = this.importAction.getToolbarItemConfig();

            options.push({label: translate(`${PREFIX}.transfer_import`), disabled, onClick});
        }

        if (options.length === 0) {
            return null;
        }

        return {
            type: 'dropdown',
            label: translate(`${PREFIX}.transfer`),
            icon: 'su-exchange-alt',
            options,
        };
    }

    getNode() {
        return (
            <React.Fragment key="sulu_content_transfer.transfer">
                {this.getActions().map((toolbarAction) => toolbarAction.getNode())}
            </React.Fragment>
        );
    }
}

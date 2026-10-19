import React from 'react';
import {action, observable} from 'mobx';
import {translate} from 'sulu-admin-bundle/utils';
import {AbstractListToolbarAction} from 'sulu-admin-bundle/views';
import {Requester} from 'sulu-admin-bundle/services';
import {Button, Checkbox, CheckboxGroup, Dialog, Form, Loader} from 'sulu-admin-bundle/components';
import {Radio, RadioGroup} from 'sulu-admin-bundle/components/Radio';
import {ERROR_COLOR, PREFIX, formatBytes, languageName} from '../utils';

const API = '/admin/api/content-transfer/export';
const STAGE_LIVE = 'live';
const STAGE_DRAFT = 'draft';
const MEDIA_FILES = 'files';
const MEDIA_REFERENCES = 'references';
const MAX_LISTED_ARTICLES = 5;
const DEFAULT_FILE_NAME = 'export.sulu-transfer.zip';

function fileNameOf(response) {
    const header = response.headers.get('Content-Disposition') || '';
    const match = /filename=("?)([^";]+)\1/i.exec(header);

    return match ? match[2] : DEFAULT_FILE_NAME;
}

function saveFile(blob, fileName) {
    const url = window.URL.createObjectURL(blob);
    const link = document.createElement('a');

    link.href = url;
    link.download = fileName;
    link.style.display = 'none';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);

    // the download has started when the click returns; the address is only needed until then
    window.setTimeout(() => window.URL.revokeObjectURL(url), 10000);
}

/**
 * The button "Export" in the toolbar of an article list. It is active when articles are selected and opens a dialog
 * in which the editor chooses languages, version and images. The dialog asks the server what the export would contain
 * (preview) and downloads the package file from the server on confirmation.
 */
export default class ExportToolbarAction extends AbstractListToolbarAction {
    @observable open = false;
    @observable loading = false;
    @observable exporting = false;
    @observable preview = undefined;
    @observable error = undefined;
    @observable availableLocales = [];
    @observable selectedLocales = [];
    @observable stage = STAGE_LIVE;
    @observable includeMediaFiles = true;
    @observable extraArticles = [];

    requestCounter = 0;

    getToolbarItemConfig() {
        return {
            type: 'button',
            label: translate(`${PREFIX}.export`),
            icon: 'su-archive',
            disabled: this.listStore.selectionIds.length === 0,
            onClick: this.handleOpen,
        };
    }

    /**
     * The selected articles of the list plus those the editor added in the dialog.
     */
    getIds() {
        const ids = this.listStore.selectionIds.slice();

        this.extraArticles.forEach(({id}) => {
            if (!ids.includes(id)) {
                ids.push(id);
            }
        });

        return ids;
    }

    @action handleOpen = () => {
        this.requestCounter++;
        this.extraArticles = [];
        this.stage = STAGE_LIVE;
        this.includeMediaFiles = true;
        this.error = undefined;
        this.preview = undefined;
        this.availableLocales = [];
        this.selectedLocales = [];
        this.exporting = false;
        this.open = true;

        this.loadPreview();
    };

    @action handleCancel = () => {
        if (this.exporting) {
            return;
        }

        this.requestCounter++;
        this.open = false;
        this.loading = false;
    };

    @action handleLocalesChange = (locales) => {
        this.selectedLocales = locales;

        if (locales.length > 0) {
            this.loadPreview();
        }
    };

    @action handleStageChange = (stage) => {
        this.stage = stage;
        this.loadPreview();
    };

    @action handleMediaChange = (mode) => {
        this.includeMediaFiles = mode === MEDIA_FILES;
    };

    @action handleAddLinked = () => {
        const known = this.getIds();

        this.preview.linkedArticles.forEach(({id, title}) => {
            if (!known.includes(id)) {
                this.extraArticles.push({id, title});
            }
        });

        this.loadPreview();
    };

    /**
     * Asks the server what an export of the current choice would contain. The first call (no preview yet) also tells
     * which languages are there; later calls keep the choice of the editor and only select languages that are new.
     */
    @action loadPreview() {
        const requestId = ++this.requestCounter;
        const previousLocales = this.availableLocales.slice();
        const selectedLocales = this.selectedLocales.slice();
        const first = this.preview === undefined;

        this.loading = true;
        this.error = undefined;

        Requester.post(`${API}/preview?locale=${this.router.attributes.locale}`, {
            ids: this.getIds(),
            // without a choice the server offers every language: the first call is that question
            locales: first ? [] : selectedLocales,
            stage: this.stage,
        }).then(action((data) => {
            if (requestId !== this.requestCounter) {
                return;
            }

            this.preview = data;
            this.availableLocales = data.locales;
            this.selectedLocales = first
                ? data.locales.slice()
                : data.locales.filter((locale) => selectedLocales.includes(locale) || !previousLocales.includes(locale));
            this.loading = false;
        })).catch((response) => {
            if (requestId !== this.requestCounter) {
                return;
            }

            this.fail(response);
        });
    }

    @action handleConfirm = () => {
        if (!this.preview || this.selectedLocales.length === 0) {
            return;
        }

        this.exporting = true;
        this.error = undefined;

        const options = {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                ids: this.getIds(),
                locales: this.selectedLocales.slice(),
                stage: this.stage,
                includeMediaFiles: this.includeMediaFiles,
            }),
        };

        fetch(API, options).then((response) => {
            Requester.handleResponseHooks.forEach((hook) => hook(response, options));

            if (!response.ok) {
                return Promise.reject(response);
            }

            return response.blob().then((blob) => ({blob, fileName: fileNameOf(response)}));
        }).then(action(({blob, fileName}) => {
            saveFile(blob, fileName);
            this.exporting = false;
            this.open = false;
        })).catch((response) => {
            this.fail(response);
        });
    };

    /**
     * Shows why a request failed: the translated reason if the server gave one that is known, else the server text
     * or the status.
     */
    @action fail(response) {
        const show = action((message) => {
            this.error = message;
            this.loading = false;
            this.exporting = false;
        });

        if (!response || typeof response.json !== 'function') {
            show(translate(`${PREFIX}.error_connection`));

            return;
        }

        response.json().then((data) => {
            const code = data && data.code;
            const key = code ? `${PREFIX}.error_${code.replace(/[^A-Za-z0-9]+/g, '_')}` : undefined;
            const translated = key ? translate(key) : undefined;

            if (translated && translated !== key) {
                show(translated);

                return;
            }

            show(`${translate(`${PREFIX}.error`)} ${data && data.error ? data.error : '(' + response.status + ')'}`);
        }).catch(() => {
            show(`${translate(`${PREFIX}.error`)} (${response.status})`);
        });
    }

    renderLocales() {
        const noLocale = this.selectedLocales.length === 0;

        return (
            <Form.Field
                description={noLocale ? undefined : translate(`${PREFIX}.languages_info`)}
                error={noLocale ? translate(`${PREFIX}.languages_none`) : undefined}
                label={translate(`${PREFIX}.languages`)}
            >
                <CheckboxGroup onChange={this.handleLocalesChange} values={this.selectedLocales.slice()}>
                    {this.availableLocales.map((locale) => (
                        <Checkbox key={locale} value={locale}>{languageName(locale)}</Checkbox>
                    ))}
                </CheckboxGroup>
            </Form.Field>
        );
    }

    renderStage() {
        const {preview, stage} = this;
        const unpublished = preview && stage === STAGE_LIVE ? preview.unpublished : 0;

        return (
            <Form.Field
                description={unpublished > 0 ? translate(`${PREFIX}.version_unpublished`, {count: unpublished}) : undefined}
                label={translate(`${PREFIX}.version`)}
            >
                <RadioGroup onChange={this.handleStageChange} value={stage}>
                    <Radio value={STAGE_LIVE}>{translate(`${PREFIX}.version_live`)}</Radio>
                    <Radio value={STAGE_DRAFT}>{translate(`${PREFIX}.version_draft`)}</Radio>
                </RadioGroup>
            </Form.Field>
        );
    }

    renderImages() {
        const {count, bytes} = this.preview.media;

        return (
            <Form.Field label={translate(`${PREFIX}.images`)}>
                <RadioGroup onChange={this.handleMediaChange} value={this.includeMediaFiles ? MEDIA_FILES : MEDIA_REFERENCES}>
                    <Radio value={MEDIA_FILES}>
                        {count > 0
                            ? translate(`${PREFIX}.images_files`, {count, size: formatBytes(bytes)})
                            : translate(`${PREFIX}.images_files_none`)}
                    </Radio>
                    <Radio value={MEDIA_REFERENCES}>{translate(`${PREFIX}.images_references`)}</Radio>
                </RadioGroup>
            </Form.Field>
        );
    }

    renderLinked() {
        const {linkedArticles} = this.preview;

        if (linkedArticles.length === 0) {
            return null;
        }

        return (
            <Form.Field label={translate(`${PREFIX}.linked`)}>
                <p>{translate(`${PREFIX}.linked_text`, {count: linkedArticles.length})}</p>
                <ul>
                    {linkedArticles.slice(0, MAX_LISTED_ARTICLES).map(({id, title}) => (
                        <li key={id}>{`„${title}“`}</li>
                    ))}
                    {linkedArticles.length > MAX_LISTED_ARTICLES &&
                        <li>{translate(`${PREFIX}.more`, {count: linkedArticles.length - MAX_LISTED_ARTICLES})}</li>
                    }
                </ul>
                <Button disabled={this.loading || this.exporting} onClick={this.handleAddLinked} skin="secondary">
                    {translate(`${PREFIX}.linked_add`)}
                </Button>
            </Form.Field>
        );
    }

    renderError() {
        return this.error
            ? <p role="alert" style={{color: ERROR_COLOR}}>{this.error}</p>
            : null;
    }

    renderContent() {
        if (!this.preview) {
            return (
                <React.Fragment>
                    {this.renderError()}
                    {this.loading && <Loader />}
                </React.Fragment>
            );
        }

        const extras = this.extraArticles.map(({title}) => `„${title}“`).join(', ');

        return (
            <div style={{opacity: this.loading ? 0.5 : 1}}>
                {this.renderError()}
                {extras && <p>{translate(`${PREFIX}.also_selected`, {titles: extras})}</p>}
                <Form>
                    {this.renderLocales()}
                    {this.renderStage()}
                    {this.renderImages()}
                    {this.renderLinked()}
                </Form>
            </div>
        );
    }

    getNode() {
        return (
            <Dialog
                key="sulu_content_transfer.export"
                align="left"
                cancelText={translate('sulu_admin.cancel')}
                confirmDisabled={this.loading || !this.preview || this.selectedLocales.length === 0}
                confirmLoading={this.exporting}
                confirmText={translate(`${PREFIX}.export_confirm`)}
                onCancel={this.handleCancel}
                onConfirm={this.handleConfirm}
                open={this.open}
                title={translate(`${PREFIX}.export_title`, {count: this.open ? this.getIds().length : 0})}
            >
                {this.renderContent()}
            </Dialog>
        );
    }
}

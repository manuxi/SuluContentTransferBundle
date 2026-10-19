import React from 'react';
import {action, computed, observable} from 'mobx';
import {translate} from 'sulu-admin-bundle/utils';
import {AbstractListToolbarAction} from 'sulu-admin-bundle/views';
import {Dialog} from 'sulu-admin-bundle/components';
import {analyze, describeError, discard, execute, isPlanProblem} from '../api';
import {FileStep, PreviewStep, ReportStep} from '../ImportSteps';
import {deniedText} from '../messages';
import {PREFIX} from '../utils';

const STEP_FILE = 'file';
const STEP_PREVIEW = 'preview';
const STEP_REPORT = 'report';
const SKIP = 'skip';
const UPDATE = 'update';

/**
 * The button "Import package" in the toolbar of an article list. It opens a dialog with three steps: the editor
 * picks a package file, sees what the import would do (articles, languages, status, images, remarks) and decides,
 * then reads the report. The server analyzes and writes; it also decides what the editor may import, the dialog
 * only shows it.
 */
export default class ImportToolbarAction extends AbstractListToolbarAction {
    @observable open = false;
    @observable step = STEP_FILE;
    @observable file = undefined;
    @observable dragging = false;
    @observable analyzing = false;
    @observable executing = false;
    @observable error = undefined;
    @observable plan = undefined;
    @observable report = undefined;
    @observable actions = {};
    @observable selectedLocales = [];
    @observable keepStatus = false;
    @observable overwriteMediaMeta = false;

    getToolbarItemConfig() {
        return {
            type: 'button',
            label: translate(`${PREFIX}.import`),
            icon: 'su-upload',
            disabled: false,
            onClick: this.handleOpen,
        };
    }

    // ---------------------------------------------------------------------------------------------------------------
    // opening and closing

    @action handleOpen = () => {
        this.reset();
        this.open = true;
    };

    @action reset() {
        this.step = STEP_FILE;
        this.file = undefined;
        this.dragging = false;
        this.analyzing = false;
        this.executing = false;
        this.error = undefined;
        this.plan = undefined;
        this.report = undefined;
        this.actions = {};
        this.selectedLocales = [];
        this.keepStatus = false;
        this.overwriteMediaMeta = false;
    }

    @action handleCancel = () => {
        if (this.analyzing || this.executing) {
            return;
        }

        if (this.step === STEP_PREVIEW && this.plan) {
            discard(this.plan.token);
        }
        this.open = false;
    };

    @action handleClose = () => {
        this.open = false;
    };

    // ---------------------------------------------------------------------------------------------------------------
    // step 1: the file

    @action setFile(file) {
        if (file) {
            this.file = file;
            this.error = undefined;
        }
    }

    @action handleFileInput = (event) => {
        this.setFile(event.target.files && event.target.files[0]);
        // the same file can be chosen again after an error
        event.target.value = '';
    };

    @action handleDragOver = (event) => {
        event.preventDefault();
        this.dragging = true;
    };

    @action handleDragLeave = () => {
        this.dragging = false;
    };

    @action handleDrop = (event) => {
        event.preventDefault();
        this.dragging = false;
        this.setFile(event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files[0]);
    };

    @action handleAnalyze = () => {
        if (!this.file || this.analyzing) {
            return;
        }

        const file = this.file;
        this.analyzing = true;
        this.error = undefined;

        analyze(file).then(action((plan) => {
            this.plan = plan;
            this.selectedLocales = plan.locales.filter(({import: import_}) => import_).map(({locale}) => locale);
            this.keepStatus = false;
            this.overwriteMediaMeta = false;
            const actions = {};
            plan.articles.forEach((article) => {
                actions[article.id] = article.defaultAction;
            });
            this.actions = actions;
            this.analyzing = false;
            this.step = STEP_PREVIEW;
        })).catch(action((error) => {
            this.analyzing = false;
            this.error = describeError(error, file.name);
        }));
    };

    // ---------------------------------------------------------------------------------------------------------------
    // step 2: the decisions

    @action setAction = (id, value) => {
        this.actions = {...this.actions, [id]: value};
    };

    // not called setLocales: the list view calls that on every toolbar action to hand over its own languages
    @action setImportLocales = (locales) => {
        this.selectedLocales = locales;
    };

    @action setStatus = (status) => {
        this.keepStatus = status === 'source';
    };

    @action setOverwriteMediaMeta = (checked) => {
        this.overwriteMediaMeta = checked;
    };

    /**
     * The languages of the article that are written with the current choice of languages.
     */
    writtenLocales(article) {
        return article.locales
            .filter(({import: import_, locale}) => import_ && this.selectedLocales.includes(locale))
            .map(({locale}) => locale);
    }

    /**
     * Why the current user may not carry out an action on an article, null if they may. The server decides the same
     * way when the import is carried out.
     */
    denial(article, act) {
        if (act === SKIP) {
            return null;
        }

        const {add, edit, live, mediaCreate, mediaTexts} = article.access;
        const written = this.writtenLocales(article);
        const needed = act === UPDATE ? edit : add;

        if (written.some((locale) => !needed.includes(locale))) {
            return act === UPDATE ? 'edit' : 'add';
        }

        const published = article.locales
            .filter((locale) => written.includes(locale.locale) && locale.published && locale.stage === 'live');
        if (this.keepStatus && published.some(({locale}) => !live.includes(locale))) {
            return 'live';
        }

        if (!mediaCreate || (this.overwriteMediaMeta && !mediaTexts)) {
            return 'media';
        }

        return null;
    }

    /**
     * The actions the editor can choose for an article: those the plan allows and the user may carry out.
     */
    optionsFor(article) {
        return article.allowedActions.filter((option) => this.denial(article, option) === null);
    }

    /**
     * The chosen action; "skip" if the choice is not possible with the current languages and status.
     */
    effectiveAction(article) {
        const chosen = this.actions[article.id] || article.defaultAction;

        return this.optionsFor(article).includes(chosen) ? chosen : SKIP;
    }

    /**
     * The sentence under an article that cannot be imported by this user or in the chosen languages, else undefined.
     */
    blockReason(article) {
        if (article.state === 'blocked') {
            return undefined;
        }

        const others = article.allowedActions.filter((option) => option !== SKIP);
        if (others.length === 0) {
            return undefined;
        }
        if (this.writtenLocales(article).length === 0) {
            return translate(`${PREFIX}.no_chosen_language`);
        }

        const denials = others.map((option) => this.denial(article, option));
        if (denials.every((reason) => reason !== null)) {
            return `${translate(`${PREFIX}.state_not_allowed`)} ${deniedText(denials[0])}`;
        }

        return undefined;
    }

    /**
     * The note for an update of an article that is published on this system and gets only a draft.
     */
    draftNote(article) {
        if (this.keepStatus || this.effectiveAction(article) !== UPDATE) {
            return undefined;
        }

        const written = this.writtenLocales(article);
        if (!article.publishedOnTarget.some((locale) => written.includes(locale))) {
            return undefined;
        }

        return translate(`${PREFIX}.draft_note`);
    }

    @computed get articlesToWrite() {
        if (!this.plan) {
            return 0;
        }

        return this.plan.articles
            .filter((article) => this.effectiveAction(article) !== SKIP && this.writtenLocales(article).length > 0)
            .length;
    }

    // ---------------------------------------------------------------------------------------------------------------
    // import

    @action handleExecute = () => {
        if (!this.plan || this.executing || this.articlesToWrite === 0) {
            return;
        }

        const actions = {};
        this.plan.articles.forEach((article) => {
            actions[article.id] = this.effectiveAction(article);
        });

        this.executing = true;
        this.error = undefined;

        execute({
            token: this.plan.token,
            actions,
            locales: this.selectedLocales.slice(),
            keepStatus: this.keepStatus,
            overwriteMediaMeta: this.overwriteMediaMeta,
        }).then(action(({report}) => {
            this.report = report;
            this.executing = false;
            this.step = STEP_REPORT;
            this.listStore.reload();
        })).catch(action((error) => {
            this.executing = false;

            if (isPlanProblem(error)) {
                // the analysis cannot be used any more: start again with the file
                this.plan = undefined;
                this.step = STEP_FILE;
            }
            this.error = describeError(error, this.file ? this.file.name : undefined);
        }));
    };

    // ---------------------------------------------------------------------------------------------------------------
    // rendering

    renderStep() {
        switch (this.step) {
            case STEP_PREVIEW:
                return <PreviewStep store={this} />;
            case STEP_REPORT:
                return <ReportStep store={this} />;
            default:
                return <FileStep store={this} />;
        }
    }

    getNode() {
        const {step} = this;
        let confirm;

        if (step === STEP_PREVIEW) {
            confirm = {
                text: translate(`${PREFIX}.import_confirm`),
                disabled: this.articlesToWrite === 0 || this.selectedLocales.length === 0,
                loading: this.executing,
                onClick: this.handleExecute,
            };
        } else if (step === STEP_REPORT) {
            confirm = {text: translate(`${PREFIX}.import_close`), disabled: false, loading: false, onClick: this.handleClose};
        } else {
            confirm = {text: translate(`${PREFIX}.import_check`), disabled: !this.file || this.analyzing, loading: false, onClick: this.handleAnalyze};
        }

        return (
            <Dialog
                key="sulu_content_transfer.import"
                align="left"
                cancelText={step === STEP_REPORT ? undefined : translate('sulu_admin.cancel')}
                confirmDisabled={confirm.disabled}
                confirmLoading={confirm.loading}
                confirmText={confirm.text}
                onCancel={this.handleCancel}
                onConfirm={confirm.onClick}
                open={this.open}
                size="large"
                title={translate(`${PREFIX}.import_title`)}
            >
                {this.open && this.renderStep()}
            </Dialog>
        );
    }
}

import React from 'react';
import {observer} from 'mobx-react';
import {translate} from 'sulu-admin-bundle/utils';
import {Checkbox, CheckboxGroup, Form, Loader, SingleSelect, Table} from 'sulu-admin-bundle/components';
import {Radio, RadioGroup} from 'sulu-admin-bundle/components/Radio';
import {noticeText, skippedLanguageText} from './messages';
import {ERROR_COLOR, MUTED_COLOR, PREFIX, formatBytes, formatDate, languageName} from './utils';

const MAX_LISTED_NOTICES = 8;
const STATUS_DRAFT = 'draft';
const STATUS_SOURCE = 'source';

const dropZoneStyle = (active) => ({
    border: `2px dashed ${active ? '#52b6ca' : '#b9c0c7'}`,
    borderRadius: 4,
    background: active ? '#eef8fa' : '#f6f7f8',
    padding: '40px 20px',
    textAlign: 'center',
    cursor: 'pointer',
});

const hintStyle = {color: MUTED_COLOR, fontSize: 12, marginTop: 4};

// the cells of the table do not wrap their content
const cellStyle = {whiteSpace: 'normal', padding: '6px 0'};

function ErrorMessage({error}) {
    if (!error) {
        return null;
    }

    return (
        <div role="alert" style={{color: ERROR_COLOR, marginBottom: 16}}>
            <p style={{margin: 0}}>{error.text}</p>
            {error.command &&
                <pre style={{background: '#f6f7f8', color: '#222', padding: 8, margin: '8px 0 0', whiteSpace: 'pre-wrap', userSelect: 'all'}}>
                    {error.command}
                </pre>
            }
        </div>
    );
}

/**
 * Step 1: the editor picks the package file (drop area or file dialog).
 */
export const FileStep = observer(class FileStep extends React.Component {
    inputRef = React.createRef();

    handleKeyDown = (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            this.inputRef.current.click();
        }
    };

    render() {
        const {store} = this.props;
        const {file, dragging, analyzing} = store;

        if (analyzing) {
            return (
                <div style={{textAlign: 'center', padding: '20px 0'}}>
                    <Loader />
                    <p>{translate(`${PREFIX}.import_checking`)}</p>
                </div>
            );
        }

        return (
            <div>
                <ErrorMessage error={store.error} />
                <div
                    onClick={() => this.inputRef.current.click()}
                    onDragLeave={store.handleDragLeave}
                    onDragOver={store.handleDragOver}
                    onDrop={store.handleDrop}
                    onKeyDown={this.handleKeyDown}
                    role="button"
                    style={dropZoneStyle(dragging)}
                    tabIndex={0}
                >
                    {file
                        ? <strong>{file.name} ({formatBytes(file.size)})</strong>
                        : <span>{translate(`${PREFIX}.import_drop`)}</span>
                    }
                    <div style={hintStyle}>
                        {file ? translate(`${PREFIX}.import_drop_change`) : translate(`${PREFIX}.import_drop_hint`)}
                    </div>
                    <input
                        accept=".zip,application/zip"
                        onChange={store.handleFileInput}
                        ref={this.inputRef}
                        style={{display: 'none'}}
                        type="file"
                    />
                </div>
            </div>
        );
    }
});

function articleStateText(article, sameInstance) {
    if (article.state === 'blocked') {
        return translate(`${PREFIX}.state_blocked`);
    }
    if (sameInstance) {
        return translate(`${PREFIX}.state_same_system`);
    }
    if (article.state === 'new') {
        return translate(`${PREFIX}.state_new`);
    }
    if (article.state === 'changed') {
        return translate(
            article.changedBy ? `${PREFIX}.state_changed_by` : `${PREFIX}.state_changed`,
            {date: article.changedAt ? formatDate(article.changedAt) : '', user: article.changedBy || ''}
        );
    }

    return translate(article.matchedBy === 'mapping' ? `${PREFIX}.state_unchanged_import` : `${PREFIX}.state_unchanged`);
}

function renderArticleRow(article, store) {
    const options = store.optionsFor(article);
    const action = store.effectiveAction(article);
    const reason = store.blockReason(article);
    const note = store.draftNote(article);

    return (
        <Table.Row key={article.id}>
            <Table.Cell>
                <div style={cellStyle}>{article.title}</div>
            </Table.Cell>
            <Table.Cell>
                <div style={cellStyle}>
                    <div>{articleStateText(article, store.plan.package.sameInstance)}</div>
                    {reason && <div style={{color: ERROR_COLOR, fontSize: 12}}>{reason}</div>}
                    {note && <div style={hintStyle}>{note}</div>}
                </div>
            </Table.Cell>
            <Table.Cell>
                <SingleSelect onChange={(value) => store.setAction(article.id, value)} value={action}>
                    {options.map((option) => (
                        <SingleSelect.Option key={option} value={option}>
                            {translate(`${PREFIX}.action_${option}`)}
                        </SingleSelect.Option>
                    ))}
                </SingleSelect>
            </Table.Cell>
        </Table.Row>
    );
}

function NoticeList({notices, titles}) {
    const order = {error: 0, warning: 1, info: 2};
    const sorted = notices.slice().sort((a, b) => order[a.level] - order[b.level]);
    const shown = sorted.slice(0, MAX_LISTED_NOTICES);
    const icon = {error: '✖', warning: '⚠', info: 'ℹ'};
    const color = {error: ERROR_COLOR, warning: '#9a6700', info: MUTED_COLOR};

    return (
        <ul style={{listStyle: 'none', padding: 0, margin: 0}}>
            {shown.map((notice, index) => (
                <li key={index} style={{color: color[notice.level], marginBottom: 4}}>
                    {icon[notice.level]} {notice.articleUuid && titles[notice.articleUuid]
                        ? <strong>{titles[notice.articleUuid]}: </strong>
                        : null}
                    {noticeText(notice)}
                </li>
            ))}
            {sorted.length > shown.length &&
                <li style={{color: MUTED_COLOR}}>{translate(`${PREFIX}.more`, {count: sorted.length - shown.length})}</li>
            }
        </ul>
    );
}

function mediaSummary(media) {
    if (media.total === 0) {
        return translate(`${PREFIX}.images_none`);
    }

    const parts = [];
    if (media.reuse > 0) {
        parts.push(translate(`${PREFIX}.images_reuse`, {count: media.reuse}));
    }
    if (media.create > 0) {
        parts.push(translate(`${PREFIX}.images_create`, {count: media.create}));
    }
    if (media.newVersion > 0) {
        parts.push(translate(`${PREFIX}.images_new_version`, {count: media.newVersion}));
    }
    if (media.missing > 0) {
        parts.push(translate(`${PREFIX}.images_missing`, {count: media.missing}));
    }

    return parts.join(', ');
}

/**
 * Step 2: what the import would do, with the decisions per article, language and status.
 */
export const PreviewStep = observer(function PreviewStep({store}) {
    const {plan} = store;
    const importLocales = plan.locales.filter(({import: import_}) => import_).map(({locale}) => locale);
    const skippedLocales = plan.locales.filter(({import: import_}) => !import_).map(({locale}) => languageName(locale));
    const titles = {};
    plan.articles.forEach((article) => {
        titles[article.id] = article.title;
    });
    const media = plan.media;
    const mediaChange = media.reuse + media.newVersion > 0;

    return (
        <div style={{opacity: store.executing ? 0.5 : 1}}>
            <ErrorMessage error={store.error} />

            <p>
                {translate(`${PREFIX}.package_info`, {
                    source: plan.package.label === 'cli' ? translate(`${PREFIX}.source_cli`) : plan.package.label,
                    date: formatDate(plan.package.createdAt),
                    articles: plan.articles.length,
                    languages: plan.locales.map(({locale}) => languageName(locale)).join(', '),
                })}
            </p>
            {plan.package.sameInstance && <p style={{color: '#9a6700'}}>{translate(`${PREFIX}.notice_source_same_instance`)}</p>}

            <div style={{marginBottom: 24}}>
                <Table>
                    <Table.Header>
                        <Table.HeaderCell>{translate(`${PREFIX}.column_article`)}</Table.HeaderCell>
                        <Table.HeaderCell>{translate(`${PREFIX}.column_state`)}</Table.HeaderCell>
                        <Table.HeaderCell>{translate(`${PREFIX}.column_action`)}</Table.HeaderCell>
                    </Table.Header>
                    <Table.Body>
                        {plan.articles.map((article) => renderArticleRow(article, store))}
                    </Table.Body>
                </Table>
            </div>

            <Form>
                <Form.Field
                    description={skippedLocales.length > 0
                        ? translate(`${PREFIX}.languages_skipped`, {languages: skippedLocales.join(', ')})
                        : undefined}
                    error={store.selectedLocales.length === 0 ? translate(`${PREFIX}.languages_none`) : undefined}
                    label={translate(`${PREFIX}.languages`)}
                >
                    <CheckboxGroup onChange={store.setImportLocales} values={store.selectedLocales.slice()}>
                        {importLocales.map((locale) => <Checkbox key={locale} value={locale}>{languageName(locale)}</Checkbox>)}
                    </CheckboxGroup>
                </Form.Field>

                <Form.Field label={translate(`${PREFIX}.status`)}>
                    <RadioGroup onChange={store.setStatus} value={store.keepStatus ? STATUS_SOURCE : STATUS_DRAFT}>
                        <Radio value={STATUS_DRAFT}>{translate(`${PREFIX}.status_draft`)}</Radio>
                        <Radio value={STATUS_SOURCE}>{translate(`${PREFIX}.status_source`)}</Radio>
                    </RadioGroup>
                </Form.Field>

                {plan.categories.length > 0 &&
                    <Form.Field label={translate(`${PREFIX}.categories`)}>
                        <ul style={{margin: 0, paddingLeft: 18}}>
                            {plan.categories.map(({path}) => (
                                <li key={path}>{translate(`${PREFIX}.category_new`, {path})}</li>
                            ))}
                        </ul>
                    </Form.Field>
                }

                {plan.tags.length > 0 &&
                    <Form.Field label={translate(`${PREFIX}.tags`)}>
                        {translate(`${PREFIX}.tags_new`, {tags: plan.tags.join(', ')})}
                    </Form.Field>
                }

                <Form.Field label={translate(`${PREFIX}.images`)}>
                    <div>{mediaSummary(media)}</div>
                    {media.create > 0 && media.folders.length > 0 &&
                        <div style={hintStyle}>
                            {translate(media.folders.includes('collection') ? `${PREFIX}.images_folder_source` : `${PREFIX}.images_folder_system`)}
                        </div>
                    }
                    {mediaChange &&
                        <Checkbox checked={store.overwriteMediaMeta} onChange={store.setOverwriteMediaMeta}>
                            {translate(`${PREFIX}.images_overwrite`)}
                        </Checkbox>
                    }
                </Form.Field>

                {plan.notices.length > 0 &&
                    <Form.Field label={translate(`${PREFIX}.notices`)}>
                        <NoticeList notices={plan.notices} titles={plan.articles.length > 1 ? titles : {}} />
                    </Form.Field>
                }
            </Form>
        </div>
    );
});

function ReportArticle({article}) {
    const failed = article.status === 'failed';
    const links = Object.keys(article.adminLinks || {});
    const skipped = Object.keys(article.skippedLocales || {});

    return (
        <li style={{marginBottom: 12}}>
            <strong>{article.title}</strong>{' '}
            <span style={{color: failed ? ERROR_COLOR : undefined}}>
                {translate(`${PREFIX}.result_${article.status}`)}
            </span>
            {failed && article.message &&
                <div style={{color: ERROR_COLOR}}>{article.message}</div>
            }
            {!failed && article.status === 'skipped' &&
                <div style={hintStyle}>{translate(`${PREFIX}.result_skipped_${article.action === 'skip' ? 'chosen' : 'no_language'}`)}</div>
            }
            {links.length > 0 &&
                <div>
                    {links.map((locale) => (
                        <a
                            href={article.adminLinks[locale]}
                            key={locale}
                            rel="noopener noreferrer"
                            style={{marginRight: 12}}
                            target="_blank"
                        >
                            {translate(`${PREFIX}.result_open`, {language: languageName(locale)})}
                        </a>
                    ))}
                </div>
            }
            {article.published.length > 0 &&
                <div style={hintStyle}>{translate(`${PREFIX}.result_published`, {languages: article.published.map(languageName).join(', ')})}</div>
            }
            {Object.keys(article.renamedUrls || {}).map((locale) => (
                <div key={locale} style={hintStyle}>
                    {translate(`${PREFIX}.result_renamed`, article.renamedUrls[locale])}
                </div>
            ))}
            {article.removedReferences.length > 0 &&
                <div style={hintStyle}>{translate(`${PREFIX}.result_removed`, {count: article.removedReferences.length})}</div>
            }
            {skipped.map((locale) => (
                <div key={locale} style={hintStyle}>{skippedLanguageText(locale, article.skippedLocales[locale])}</div>
            ))}
        </li>
    );
}

function ReportMedia({media}) {
    if (media.length === 0) {
        return null;
    }

    const count = (outcome) => media.filter((medium) => medium.outcome === outcome).length;
    const failed = media.filter((medium) => medium.outcome === 'failed');
    const parts = [];
    [['created', 'result_images_created'], ['reused', 'result_images_reused'], ['new_version', 'result_images_new_version'], ['missing', 'result_images_missing']]
        .forEach(([outcome, textKey]) => {
            if (count(outcome) > 0) {
                parts.push(translate(`${PREFIX}.${textKey}`, {count: count(outcome)}));
            }
        });
    const warnings = media.filter((medium) => medium.warnings.length > 0);

    return (
        <Form.Field label={translate(`${PREFIX}.images`)}>
            {parts.length > 0 && <div>{parts.join(', ')}</div>}
            {failed.map((medium) => (
                <div key={medium.sourceId} style={{color: ERROR_COLOR}}>
                    {translate(`${PREFIX}.result_image_failed`, {name: medium.filename || medium.sourceId})} {medium.message}
                </div>
            ))}
            {warnings.map((medium) => medium.warnings.map((warning, index) => (
                <div key={`${medium.sourceId}-${index}`} style={hintStyle}>{medium.filename}: {warning}</div>
            )))}
        </Form.Field>
    );
}

/**
 * Step 3: what the import did, with links into the admin; errors in red.
 */
export const ReportStep = observer(function ReportStep({store}) {
    const {report} = store;
    const counts = {created: 0, updated: 0, copied: 0, skipped: 0, failed: 0};
    report.articles.forEach((article) => {
        counts[article.status]++;
    });
    const failures = report.articles.some((article) => article.status === 'failed')
        || report.media.some((medium) => medium.outcome === 'failed')
        || report.errors.length > 0;

    return (
        <div>
            <p style={{color: failures ? ERROR_COLOR : undefined}}>
                <strong>{translate(failures ? `${PREFIX}.report_with_errors` : `${PREFIX}.report_done`)}</strong>{' '}
                {translate(`${PREFIX}.report_counts`, counts)}
            </p>
            {report.errors.map((error, index) => <p key={index} style={{color: ERROR_COLOR}}>{error}</p>)}

            <ul style={{listStyle: 'none', padding: 0}}>
                {report.articles.map((article) => <ReportArticle article={article} key={article.sourceUuid} />)}
            </ul>

            <Form>
                <ReportMedia media={report.media} />
                {report.categories.length > 0 &&
                    <Form.Field label={translate(`${PREFIX}.categories`)}>
                        {translate(`${PREFIX}.result_categories`, {names: report.categories.map(({path}) => path).join(', ')})}
                    </Form.Field>
                }
                {report.tags.length > 0 &&
                    <Form.Field label={translate(`${PREFIX}.tags`)}>
                        {translate(`${PREFIX}.result_tags`, {names: report.tags.map(({name}) => name).join(', ')})}
                    </Form.Field>
                }
            </Form>
        </div>
    );
});

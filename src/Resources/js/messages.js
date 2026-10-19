import {translate} from 'sulu-admin-bundle/utils';
import {PREFIX, languageName} from './utils';

function key(group, code) {
    return `${PREFIX}.${group}_${String(code).replace(/[^A-Za-z0-9]+/g, '_')}`;
}

/**
 * The text of a remark of the analysis (a notice) in the language of the user. Codes this version does not know
 * keep the English text of the server.
 */
export function noticeText(notice) {
    const params = {};
    Object.keys(notice.params || {}).forEach((name) => {
        const value = notice.params[name];
        params[name] = value === null || value === undefined ? '' : value;
    });
    params.language = notice.locale ? languageName(notice.locale) : '';
    params.languages = typeof params.locales === 'string' && params.locales
        ? params.locales.split(',').map(languageName).join(', ')
        : '';

    let textKey = key('notice', notice.code);
    if (notice.code === 'webspace.fallback' && !params.webspace) {
        textKey += '_none';
    }

    const text = translate(textKey, params);

    return text !== textKey ? text : notice.message;
}

/**
 * Why an article cannot be imported by this user: "Es fehlt das Recht, ..." (a DENIED code of the server or the
 * short form "add", "edit", "live", "media").
 */
export function deniedText(reason) {
    const short = String(reason).replace(/^import\.denied_/, '');

    return translate(key('denied', short));
}

/**
 * Why a language of an article was left out.
 */
export function skippedLanguageText(locale, reason) {
    const textKey = key('skipped', reason);
    const text = translate(textKey, {language: languageName(locale)});

    return text !== textKey ? text : `${languageName(locale)}: ${reason}`;
}

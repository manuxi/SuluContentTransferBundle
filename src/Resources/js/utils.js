import {userStore} from 'sulu-admin-bundle/stores';

export const PREFIX = 'sulu_content_transfer';
export const ERROR_COLOR = '#cb2431';
export const MUTED_COLOR = '#6c757d';

/**
 * Name of a content locale in the language of the user ("en" -> "Englisch"), the code itself if the browser
 * does not know it.
 */
export function languageName(locale) {
    try {
        const name = new Intl.DisplayNames([userStore.systemLocale], {type: 'language'})
            .of(locale.replace('_', '-'));

        return name || locale;
    } catch (e) {
        return locale;
    }
}

/**
 * "8,4 MB" in the number format of the user; sizes below one kilobyte in bytes.
 */
export function formatBytes(bytes) {
    const units = ['B', 'KB', 'MB', 'GB'];
    let value = bytes;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    let number = String(Math.round(value * 10) / 10);
    try {
        number = new Intl.NumberFormat(userStore.systemLocale, {maximumFractionDigits: unit === 0 ? 0 : 1}).format(value);
    } catch (e) {
        // the plain number is good enough
    }

    return `${number} ${units[unit]}`;
}

/**
 * "19.10.2026, 15:30" in the format of the user, the text as it is if it is no date.
 */
export function formatDate(text) {
    const date = new Date(text);

    if (isNaN(date.getTime())) {
        return text;
    }

    try {
        return new Intl.DateTimeFormat(userStore.systemLocale, {dateStyle: 'medium', timeStyle: 'short'}).format(date);
    } catch (e) {
        return date.toLocaleString();
    }
}

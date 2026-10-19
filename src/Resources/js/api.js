import {Requester} from 'sulu-admin-bundle/services';
import {userStore} from 'sulu-admin-bundle/stores';
import {translate} from 'sulu-admin-bundle/utils';
import {PREFIX, formatBytes} from './utils';

const API = '/admin/api/content-transfer/import';
const PLAN_PROBLEMS = ['plan.expired', 'plan.missing', 'plan.changed', 'plan.token_invalid'];

/**
 * A request the server answered with an error: the status and, if the answer was JSON, {error, code, ...}.
 */
export class ApiError {
    constructor(status, data) {
        this.status = status;
        this.data = data || {};
    }
}

function send(url, options) {
    const requestOptions = {
        credentials: 'same-origin',
        ...options,
        headers: {'X-Requested-With': 'XMLHttpRequest', ...(options.headers || {})},
    };

    return fetch(url, requestOptions).then((response) => {
        // the hooks of Sulu log the user out when the session is gone
        Requester.handleResponseHooks.forEach((hook) => hook(response, requestOptions));

        return response.text().then((text) => {
            let data;
            try {
                data = text ? JSON.parse(text) : {};
            } catch (e) {
                data = undefined;
            }

            if (!response.ok) {
                return Promise.reject(new ApiError(response.status, data));
            }

            return data;
        });
    }, () => Promise.reject(new ApiError(0)));
}

/**
 * Sends the package; the answer is the plan with its token.
 */
export function analyze(file) {
    const body = new FormData();
    body.append('file', file, file.name);

    return send(`${API}/analyze`, {method: 'POST', body});
}

/**
 * Carries the import out; the answer holds the report.
 */
export function execute(payload) {
    return send(`${API}/execute`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload),
    });
}

/**
 * Throws away an analysis that is not carried out (the answer does not matter).
 */
export function discard(token) {
    return send(`${API}/${token}`, {method: 'DELETE'}).catch(() => undefined);
}

/**
 * True for the errors after which the analysis cannot be used any more (expired, thrown away, system changed).
 */
export function isPlanProblem(error) {
    return error instanceof ApiError && PLAN_PROBLEMS.includes(error.data.code);
}

/**
 * The command that imports a package on the server, with the name of the file and the user filled in.
 */
export function consoleCommand(fileName) {
    const user = userStore.user && userStore.user.username ? userStore.user.username : 'USER';

    return translate(`${PREFIX}.console_command`, {fileName, user});
}

/**
 * What to tell the person about a failed request: {text} and, if the file is too large for an upload, {command} as well.
 */
export function describeError(error, fileName) {
    if (!(error instanceof ApiError) || error.status === 0) {
        return {text: translate(`${PREFIX}.error_connection`)};
    }

    const {status, data} = error;
    const code = data.code;

    // a web server in front of PHP may refuse a large upload with a page of its own
    if (code === 'import.upload_too_large' || (status === 413 && !code)) {
        const limit = data.limit;

        return {
            text: limit
                ? translate(`${PREFIX}.error_import_upload_too_large`, {limit: formatBytes(limit)})
                : translate(`${PREFIX}.error_import_upload_too_large_nolimit`),
            command: consoleCommand(fileName || 'package.sulu-transfer.zip'),
        };
    }

    if (code) {
        const key = `${PREFIX}.error_${code.replace(/[^A-Za-z0-9]+/g, '_')}`;
        const translated = translate(key);

        if (translated !== key) {
            return {text: translated};
        }
    }

    return {text: `${translate(`${PREFIX}.error`)} ${data.error ? data.error : '(' + status + ')'}`};
}

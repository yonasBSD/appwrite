import http from 'k6/http';
import { check, group, sleep } from 'k6';
import encoding from 'k6/encoding';
import { Counter, Trend } from 'k6/metrics';

const ENDPOINT = (__ENV.APPWRITE_ENDPOINT || 'http://localhost/v1').replace(/\/+$/, '');
const MAILDEV_ENDPOINT = __ENV.APPWRITE_MAILDEV_ENDPOINT || 'http://localhost:9503/email';
const CONSOLE_PROJECT = __ENV.APPWRITE_CONSOLE_PROJECT || 'console';
const REGION = __ENV.APPWRITE_REGION || 'default';
const REDIRECT_URL = __ENV.APPWRITE_BENCHMARK_REDIRECT_URL || 'http://localhost';
const PASSWORD = __ENV.APPWRITE_BENCHMARK_PASSWORD || 'Password123!';
const MAIL_TIMEOUT_MS = Number(__ENV.APPWRITE_MAIL_TIMEOUT_MS || 20000);
const WORKER_TIMEOUT_MS = Number(__ENV.APPWRITE_WORKER_TIMEOUT_MS || 60000);
const ITERATIONS = Number(__ENV.APPWRITE_BENCHMARK_ITERATIONS || 1);
const VUS = Number(__ENV.APPWRITE_BENCHMARK_VUS || 1);
const SUMMARY_PATH = __ENV.APPWRITE_BENCHMARK_SUMMARY_PATH || 'tests/benchmarks/http-summary.json';
const PREVIOUS_SUMMARY = loadPreviousSummary();

export const apiDuration = new Trend('appwrite_api_duration', true);
export const databaseWorkerDuration = new Trend('appwrite_worker_database_duration', true);
export const tablesWorkerDuration = new Trend('appwrite_worker_tables_duration', true);
export const mailsWorkerDuration = new Trend('appwrite_worker_mails_duration', true);
export const messagingWorkerDuration = new Trend('appwrite_worker_messaging_duration', true);
export const flowFailures = new Counter('appwrite_benchmark_flow_failures');

export const options = {
    scenarios: {
        curated_flows: {
            executor: 'shared-iterations',
            exec: 'curatedFlows',
            vus: VUS,
            iterations: ITERATIONS,
            maxDuration: __ENV.APPWRITE_BENCHMARK_MAX_DURATION || '30m',
        },
    },
    thresholds: {
        http_req_failed: ['rate<0.05'],
        appwrite_api_duration: ['p(95)<2000'],
        appwrite_benchmark_flow_failures: ['count<1'],
    },
};

const API_SCOPES = [
    'sessions.write',
    'users.read',
    'users.write',
    'teams.read',
    'teams.write',
    'databases.read',
    'databases.write',
    'collections.read',
    'collections.write',
    'tables.read',
    'tables.write',
    'attributes.read',
    'attributes.write',
    'columns.read',
    'columns.write',
    'indexes.read',
    'indexes.write',
    'documents.read',
    'documents.write',
    'rows.read',
    'rows.write',
    'files.read',
    'files.write',
    'buckets.read',
    'buckets.write',
    'functions.read',
    'functions.write',
    'sites.read',
    'sites.write',
    'log.read',
    'log.write',
    'execution.read',
    'execution.write',
    'locale.read',
    'avatars.read',
    'health.read',
    'providers.read',
    'providers.write',
    'messages.read',
    'messages.write',
    'topics.read',
    'topics.write',
    'subscribers.read',
    'subscribers.write',
    'targets.read',
    'targets.write',
    'rules.read',
    'rules.write',
    'migrations.read',
    'migrations.write',
    'vcs.read',
    'vcs.write',
    'assistant.read',
    'tokens.read',
    'tokens.write',
    'platforms.read',
    'platforms.write',
];

const BASE_PERMISSIONS = [
    'read("any")',
    'create("any")',
    'update("any")',
    'delete("any")',
];

const ITEM_PERMISSIONS = [
    'read("any")',
    'update("any")',
    'delete("any")',
];

export function setup() {
    const runId = unique('run');
    const consoleEmail = __ENV.APPWRITE_ADMIN_EMAIL || `bench-admin-${runId}@example.com`;
    const consolePassword = __ENV.APPWRITE_ADMIN_PASSWORD || PASSWORD;

    const consoleHeaders = {
        'Content-Type': 'application/json',
        'X-Appwrite-Project': CONSOLE_PROJECT,
    };

    const account = rawRequest('POST', '/account', {
        userId: unique('admin'),
        email: consoleEmail,
        password: consolePassword,
        name: 'Benchmark Admin',
    }, consoleHeaders, 'setup.account.create');

    if (![201, 409].includes(account.status)) {
        failResponse(account, 'Unable to create or reuse the benchmark console account');
    }

    const session = rawRequest('POST', '/account/sessions/email', {
        email: consoleEmail,
        password: consolePassword,
    }, consoleHeaders, 'setup.account.session');

    assertStatus(session, [201], 'console session created');

    const consoleSessionHeaders = {
        ...consoleHeaders,
        Cookie: cookieHeader(session),
    };

    const team = api('POST', '/teams', {
        teamId: unique('team'),
        name: `Benchmark Team ${runId}`,
    }, consoleSessionHeaders, [201], 'setup.teams.create');

    const teamId = team.json('$id');
    const project = api('POST', '/projects', {
        projectId: unique('project'),
        name: `Benchmark Project ${runId}`,
        teamId,
        region: REGION,
    }, consoleSessionHeaders, [201], 'setup.projects.create');

    const projectId = project.json('$id');
    const key = api('POST', `/projects/${projectId}/keys`, {
        keyId: unique('key'),
        name: 'Benchmark API key',
        scopes: API_SCOPES,
    }, consoleSessionHeaders, [201], 'setup.projects.keys.create');

    const apiHeaders = {
        'Content-Type': 'application/json',
        'X-Appwrite-Project': projectId,
        'X-Appwrite-Key': key.json('secret'),
    };

    const platform = api('POST', '/project/platforms/web', {
        platformId: unique('web'),
        name: 'Benchmark web',
        hostname: hostnameFromUrl(REDIRECT_URL),
    }, apiHeaders, [201, 409], 'setup.project.platforms.web.create');

    const smtp = rawRequest('PATCH', `/projects/${projectId}/smtp`, {
        enabled: true,
        senderName: 'Benchmark',
        senderEmail: 'benchmark@appwrite.io',
        replyTo: 'benchmark@appwrite.io',
        host: __ENV.APPWRITE_SMTP_HOST || 'maildev',
        port: Number(__ENV.APPWRITE_SMTP_PORT || 1025),
        username: __ENV.APPWRITE_SMTP_USERNAME || 'user',
        password: __ENV.APPWRITE_SMTP_PASSWORD || 'password',
        ...(String(__ENV.APPWRITE_SMTP_SECURE || '') !== '' ? { secure: __ENV.APPWRITE_SMTP_SECURE } : {}),
    }, consoleSessionHeaders, 'setup.projects.smtp.update');

    if (smtp.status !== 200) {
        console.warn(`Custom SMTP was not enabled (${smtp.status}). Mail worker timings may be unavailable.`);
    }

    return {
        runId,
        teamId,
        projectId,
        consoleSessionHeaders,
        apiHeaders,
        platformStatus: platform.status,
    };
}

export function curatedFlows(data) {
    const ctx = { ...data };

    try {
        group('account and mail worker', () => accountFlow(ctx));
        group('databases documents flow', () => databasesFlow(ctx));
        group('tablesdb rows flow', () => tablesDbFlow(ctx));
        group('storage files and tokens flow', () => storageFlow(ctx));
        group('messaging worker flow', () => messagingFlow(ctx));
        group('functions and sites control-plane flow', () => computeFlow(ctx));
        group('health and queue probes', () => healthFlow(ctx));
    } catch (error) {
        flowFailures.add(1);
        throw error;
    }
}

export function teardown(data) {
    if (data && data.projectId && data.consoleSessionHeaders) {
        rawRequest('DELETE', `/projects/${data.projectId}`, null, data.consoleSessionHeaders, 'teardown.projects.delete');
    }

    if (data && data.teamId && data.consoleSessionHeaders) {
        rawRequest('DELETE', `/teams/${data.teamId}`, null, data.consoleSessionHeaders, 'teardown.teams.delete');
    }
}

function accountFlow(ctx) {
    const userId = unique('user');
    const email = `bench-user-${unique('mail')}@example.com`;
    const headers = projectHeaders(ctx.projectId);

    api('POST', '/account', {
        userId,
        email,
        password: PASSWORD,
        name: 'Benchmark User',
    }, headers, [201], 'account.create');

    const session = api('POST', '/account/sessions/email', {
        email,
        password: PASSWORD,
    }, headers, [201], 'account.sessions.email.create');

    const sessionHeaders = {
        ...headers,
        Cookie: cookieHeader(session),
    };

    ctx.userId = userId;
    ctx.userEmail = email;
    ctx.sessionHeaders = sessionHeaders;

    const jwt = api('POST', '/account/jwts', null, sessionHeaders, [201], 'account.jwts.create');
    ctx.jwtHeaders = {
        ...headers,
        'X-Appwrite-JWT': jwt.json('jwt'),
    };

    api('GET', '/account', null, sessionHeaders, [200], 'account.get');
    api('GET', '/account/logs', null, sessionHeaders, [200], 'account.logs.list');
    api('PATCH', '/account/prefs', { prefs: { benchmark: true, runId: ctx.runId } }, sessionHeaders, [200], 'account.prefs.update');
    api('PATCH', '/account/name', { name: 'Benchmark User Updated' }, sessionHeaders, [200], 'account.name.update');
    api('PATCH', '/account/password', { password: `${PASSWORD}2`, oldPassword: PASSWORD }, sessionHeaders, [200], 'account.password.update');

    const verificationStarted = Date.now();
    api('POST', '/account/verifications/email', { url: REDIRECT_URL }, sessionHeaders, [201], 'account.emailVerification.create');
    const verificationEmail = waitForEmail(email, (message) => {
        return includes(message.subject, 'verify')
            || includes(message.subject, 'verification')
            || includes(message.html, 'verify')
            || includes(message.html, 'verification')
            || includes(message.text, 'verify')
            || includes(message.text, 'verification');
    }, MAIL_TIMEOUT_MS);
    mailsWorkerDuration.add(Date.now() - verificationStarted, { job: 'email_verification' });

    const verification = extractQueryParams(verificationEmail);
    if (verification.userId && verification.secret) {
        api('PUT', '/account/verifications/email', {
            userId: verification.userId,
            secret: verification.secret,
        }, sessionHeaders, [200], 'account.emailVerification.update');
    }

    const recoveryStarted = Date.now();
    api('POST', '/account/recovery', { email, url: REDIRECT_URL }, headers, [201], 'account.recovery.create');
    const recoveryEmail = waitForEmail(email, (message) => {
        return includes(message.subject, 'recovery')
            || includes(message.subject, 'recover')
            || includes(message.subject, 'reset')
            || includes(message.html, 'recovery')
            || includes(message.html, 'recover')
            || includes(message.html, 'reset')
            || includes(message.text, 'recovery')
            || includes(message.text, 'recover')
            || includes(message.text, 'reset');
    }, MAIL_TIMEOUT_MS);
    mailsWorkerDuration.add(Date.now() - recoveryStarted, { job: 'password_recovery' });

    const recovery = extractQueryParams(recoveryEmail);
    if (recovery.userId && recovery.secret) {
        api('DELETE', '/account/sessions/current', null, sessionHeaders, [204], 'account.sessions.current.delete');

        api('PUT', '/account/recovery', {
            userId: recovery.userId,
            secret: recovery.secret,
            password: `${PASSWORD}3`,
        }, headers, [200], 'account.recovery.update');

        const recoveredSession = api('POST', '/account/sessions/email', {
            email,
            password: `${PASSWORD}3`,
        }, headers, [201], 'account.sessions.email.recovered');

        ctx.sessionHeaders = {
            ...headers,
            Cookie: cookieHeader(recoveredSession),
        };

        const recoveredJwt = api('POST', '/account/jwts', null, ctx.sessionHeaders, [201], 'account.jwts.recovered');
        ctx.jwtHeaders = {
            ...headers,
            'X-Appwrite-JWT': recoveredJwt.json('jwt'),
        };
    }
}

function databasesFlow(ctx) {
    const databaseId = unique('db');
    const collectionId = unique('col');
    const documentId = unique('doc');
    const indexKey = unique('idx');

    api('POST', '/databases', { databaseId, name: 'Benchmark DB' }, ctx.apiHeaders, [201], 'databases.create');
    api('POST', `/databases/${databaseId}/collections`, {
        collectionId,
        name: 'Benchmark Collection',
        permissions: BASE_PERMISSIONS,
        documentSecurity: false,
    }, ctx.apiHeaders, [201], 'databases.collections.create');

    const attributes = [
        ['string', 'title', { size: 128 }],
        ['integer', 'count', { min: 0, max: 100000 }],
        ['email', 'email', {}],
        ['boolean', 'active', {}],
        ['datetime', 'publishedAt', {}],
        ['float', 'score', { min: 0, max: 1000 }],
        ['url', 'url', {}],
        ['ip', 'ip', {}],
    ];

    for (const [type, key, extra] of attributes) {
        const started = Date.now();
        api('POST', `/databases/${databaseId}/collections/${collectionId}/attributes/${type}`, {
            key,
            required: false,
            array: false,
            ...extra,
        }, ctx.apiHeaders, [202], `databases.attributes.${type}.create`);
        waitForStatus(`/databases/${databaseId}/collections/${collectionId}/attributes/${key}`, ctx.apiHeaders, 'available', WORKER_TIMEOUT_MS);
        databaseWorkerDuration.add(Date.now() - started, { job: `attribute_${type}` });
    }

    const indexStarted = Date.now();
    api('POST', `/databases/${databaseId}/collections/${collectionId}/indexes`, {
        key: indexKey,
        type: 'key',
        attributes: ['title'],
        orders: ['asc'],
    }, ctx.apiHeaders, [202], 'databases.indexes.create');
    waitForStatus(`/databases/${databaseId}/collections/${collectionId}/indexes/${indexKey}`, ctx.apiHeaders, 'available', WORKER_TIMEOUT_MS);
    databaseWorkerDuration.add(Date.now() - indexStarted, { job: 'index' });

    api('POST', `/databases/${databaseId}/collections/${collectionId}/documents`, {
        documentId,
        data: documentPayload(),
        permissions: ITEM_PERMISSIONS,
    }, ctx.apiHeaders, [201], 'databases.documents.create');
    api('GET', `/databases/${databaseId}/collections/${collectionId}/documents`, null, ctx.apiHeaders, [200], 'databases.documents.list');
    api('GET', `/databases/${databaseId}/collections/${collectionId}/documents/${documentId}`, null, ctx.apiHeaders, [200], 'databases.documents.get');
    api('PATCH', `/databases/${databaseId}/collections/${collectionId}/documents/${documentId}`, {
        data: { title: 'Benchmark Document Updated' },
    }, ctx.apiHeaders, [200], 'databases.documents.update');
    api('PATCH', `/databases/${databaseId}/collections/${collectionId}/documents/${documentId}/count/increment`, {
        value: 1,
    }, ctx.apiHeaders, [200], 'databases.documents.increment');
    api('PATCH', `/databases/${databaseId}/collections/${collectionId}/documents/${documentId}/count/decrement`, {
        value: 1,
    }, ctx.apiHeaders, [200], 'databases.documents.decrement');
    api('DELETE', `/databases/${databaseId}/collections/${collectionId}/documents/${documentId}`, null, ctx.apiHeaders, [204], 'databases.documents.delete');
    api('DELETE', `/databases/${databaseId}`, null, ctx.apiHeaders, [204], 'databases.delete');
}

function tablesDbFlow(ctx) {
    const databaseId = unique('tdb');
    const tableId = unique('tbl');
    const rowId = unique('row');
    const indexKey = unique('tidx');

    api('POST', '/tablesdb', { databaseId, name: 'Benchmark TablesDB' }, ctx.apiHeaders, [201], 'tablesdb.create');
    api('POST', `/tablesdb/${databaseId}/tables`, {
        tableId,
        name: 'Benchmark Table',
        permissions: BASE_PERMISSIONS,
        rowSecurity: false,
    }, ctx.apiHeaders, [201], 'tablesdb.tables.create');

    const columns = [
        ['string', 'title', { size: 128 }],
        ['integer', 'count', { min: 0, max: 100000 }],
        ['email', 'email', {}],
        ['boolean', 'active', {}],
    ];

    for (const [type, key, extra] of columns) {
        const started = Date.now();
        api('POST', `/tablesdb/${databaseId}/tables/${tableId}/columns/${type}`, {
            key,
            required: false,
            array: false,
            ...extra,
        }, ctx.apiHeaders, [202], `tablesdb.columns.${type}.create`);
        waitForStatus(`/tablesdb/${databaseId}/tables/${tableId}/columns/${key}`, ctx.apiHeaders, 'available', WORKER_TIMEOUT_MS);
        tablesWorkerDuration.add(Date.now() - started, { job: `column_${type}` });
    }

    const indexStarted = Date.now();
    api('POST', `/tablesdb/${databaseId}/tables/${tableId}/indexes`, {
        key: indexKey,
        type: 'key',
        columns: ['title'],
        orders: ['asc'],
    }, ctx.apiHeaders, [202], 'tablesdb.indexes.create');
    waitForStatus(`/tablesdb/${databaseId}/tables/${tableId}/indexes/${indexKey}`, ctx.apiHeaders, 'available', WORKER_TIMEOUT_MS);
    tablesWorkerDuration.add(Date.now() - indexStarted, { job: 'index' });

    api('POST', `/tablesdb/${databaseId}/tables/${tableId}/rows`, {
        rowId,
        data: tablePayload(),
        permissions: ITEM_PERMISSIONS,
    }, ctx.sessionHeaders, [201], 'tablesdb.rows.create');
    api('GET', `/tablesdb/${databaseId}/tables/${tableId}/rows`, null, ctx.sessionHeaders, [200], 'tablesdb.rows.list');
    api('GET', `/tablesdb/${databaseId}/tables/${tableId}/rows/${rowId}`, null, ctx.sessionHeaders, [200], 'tablesdb.rows.get');
    api('PATCH', `/tablesdb/${databaseId}/tables/${tableId}/rows/${rowId}`, {
        data: { title: 'Benchmark Row Updated' },
    }, ctx.sessionHeaders, [200], 'tablesdb.rows.update');
    api('PATCH', `/tablesdb/${databaseId}/tables/${tableId}/rows/${rowId}/count/increment`, {
        value: 1,
    }, ctx.sessionHeaders, [200], 'tablesdb.rows.increment');
    api('PATCH', `/tablesdb/${databaseId}/tables/${tableId}/rows/${rowId}/count/decrement`, {
        value: 1,
    }, ctx.sessionHeaders, [200], 'tablesdb.rows.decrement');
    api('DELETE', `/tablesdb/${databaseId}/tables/${tableId}/rows/${rowId}`, null, ctx.sessionHeaders, [204], 'tablesdb.rows.delete');
    api('DELETE', `/tablesdb/${databaseId}`, null, ctx.apiHeaders, [204], 'tablesdb.delete');
}

function storageFlow(ctx) {
    const bucketId = unique('bucket');
    const fileId = unique('file');

    api('POST', '/storage/buckets', {
        bucketId,
        name: 'Benchmark Bucket',
        permissions: BASE_PERMISSIONS,
        fileSecurity: false,
        enabled: true,
        maximumFileSize: 30000000,
        allowedFileExtensions: [],
        compression: 'none',
        encryption: false,
        antivirus: false,
    }, ctx.apiHeaders, [201], 'storage.buckets.create');

    const multipartHeaders = { ...ctx.sessionHeaders };
    delete multipartHeaders['Content-Type'];

    const upload = http.post(`${ENDPOINT}/storage/buckets/${bucketId}/files`, {
        fileId,
        file: http.file(onePixelPng(), 'benchmark.png', 'image/png'),
        ...flattenMultipartArray('permissions', ITEM_PERMISSIONS),
    }, {
        headers: multipartHeaders,
        tags: { name: 'storage.files.create' },
    });

    apiDuration.add(upload.timings.duration, { name: 'storage.files.create' });
    assertStatus(upload, [201], 'storage file created');

    api('GET', `/storage/buckets/${bucketId}/files`, null, ctx.sessionHeaders, [200], 'storage.files.list');
    api('GET', `/storage/buckets/${bucketId}/files/${fileId}`, null, ctx.sessionHeaders, [200], 'storage.files.get');
    api('GET', `/storage/buckets/${bucketId}/files/${fileId}/view`, null, ctx.sessionHeaders, [200], 'storage.files.view');
    api('GET', `/storage/buckets/${bucketId}/files/${fileId}/download`, null, ctx.sessionHeaders, [200], 'storage.files.download');
    api('GET', `/storage/buckets/${bucketId}/files/${fileId}/preview`, null, ctx.sessionHeaders, [200], 'storage.files.preview');
    api('PUT', `/storage/buckets/${bucketId}/files/${fileId}`, {
        name: 'benchmark-renamed.png',
        permissions: ITEM_PERMISSIONS,
    }, ctx.sessionHeaders, [200], 'storage.files.update');

    const token = api('POST', `/tokens/buckets/${bucketId}/files/${fileId}`, {}, ctx.apiHeaders, [201], 'tokens.files.create');
    api('GET', `/tokens/buckets/${bucketId}/files/${fileId}`, null, ctx.apiHeaders, [200], 'tokens.files.list');
    api('GET', `/tokens/${token.json('$id')}`, null, ctx.apiHeaders, [200], 'tokens.get');
    api('PATCH', `/tokens/${token.json('$id')}`, { expire: null }, ctx.apiHeaders, [200], 'tokens.update');
    api('DELETE', `/tokens/${token.json('$id')}`, null, ctx.apiHeaders, [204], 'tokens.delete');

    api('DELETE', `/storage/buckets/${bucketId}/files/${fileId}`, null, ctx.sessionHeaders, [204], 'storage.files.delete');
    api('DELETE', `/storage/buckets/${bucketId}`, null, ctx.apiHeaders, [204], 'storage.buckets.delete');
}

function messagingFlow(ctx) {
    const providerId = unique('smtp');
    let targetId = unique('target');
    const topicId = unique('topic');
    const subscriberId = unique('sub');
    const messageId = unique('msg');

    api('POST', '/messaging/providers/smtp', {
        providerId,
        name: 'Benchmark SMTP',
        host: __ENV.APPWRITE_SMTP_HOST || 'maildev',
        port: Number(__ENV.APPWRITE_SMTP_PORT || 1025),
        username: __ENV.APPWRITE_SMTP_USERNAME || 'user',
        password: __ENV.APPWRITE_SMTP_PASSWORD || 'password',
        encryption: __ENV.APPWRITE_SMTP_ENCRYPTION || 'none',
        autoTLS: false,
        fromName: 'Benchmark',
        fromEmail: 'benchmark@appwrite.io',
        replyToName: 'Benchmark',
        replyToEmail: 'benchmark@appwrite.io',
        enabled: true,
    }, ctx.apiHeaders, [201], 'messaging.providers.smtp.create');

    const targets = api('GET', `/users/${ctx.userId}/targets`, null, ctx.apiHeaders, [200], 'users.targets.list');
    const existingTarget = (targets.json('targets') || []).find((target) => {
        return target.providerType === 'email' && target.identifier === ctx.userEmail;
    });

    if (existingTarget) {
        targetId = existingTarget.$id;
        api('PATCH', `/users/${ctx.userId}/targets/${targetId}`, {
            providerId,
            name: 'Benchmark email target',
        }, ctx.apiHeaders, [200], 'users.targets.update');
    } else {
        api('POST', `/users/${ctx.userId}/targets`, {
            targetId,
            providerType: 'email',
            identifier: ctx.userEmail,
            providerId,
            name: 'Benchmark email target',
        }, ctx.apiHeaders, [201], 'users.targets.create');
    }

    api('POST', '/messaging/topics', {
        topicId,
        name: 'Benchmark Topic',
        subscribe: ['users'],
    }, ctx.apiHeaders, [201], 'messaging.topics.create');

    api('POST', `/messaging/topics/${topicId}/subscribers`, {
        subscriberId,
        targetId,
    }, ctx.sessionHeaders, [201], 'messaging.subscribers.create');

    const started = Date.now();
    api('POST', '/messaging/messages/email', {
        messageId,
        subject: `Benchmark message ${ctx.runId}`,
        content: `Benchmark messaging worker probe ${ctx.runId}`,
        targets: [targetId],
        draft: false,
        html: false,
    }, ctx.apiHeaders, [201], 'messaging.messages.email.create');

    waitForMessage(messageId, ctx.apiHeaders, WORKER_TIMEOUT_MS);
    waitForEmail(ctx.userEmail, (message) => includes(message.subject, `Benchmark message ${ctx.runId}`), MAIL_TIMEOUT_MS, true);
    messagingWorkerDuration.add(Date.now() - started, { job: 'email_message' });

    api('GET', '/messaging/messages', null, ctx.apiHeaders, [200], 'messaging.messages.list');
    api('GET', `/messaging/messages/${messageId}/logs`, null, ctx.apiHeaders, [200], 'messaging.messages.logs.list');
    api('GET', `/messaging/messages/${messageId}/targets`, null, ctx.apiHeaders, [200], 'messaging.messages.targets.list');
    api('GET', `/messaging/providers/${providerId}/logs`, null, ctx.apiHeaders, [200], 'messaging.providers.logs.list');
    api('GET', `/messaging/topics/${topicId}/logs`, null, ctx.apiHeaders, [200], 'messaging.topics.logs.list');
    api('GET', `/messaging/subscribers/${subscriberId}/logs`, null, ctx.apiHeaders, [200], 'messaging.subscribers.logs.list');
    api('DELETE', `/messaging/topics/${topicId}/subscribers/${subscriberId}`, null, ctx.sessionHeaders, [204], 'messaging.subscribers.delete');
    api('DELETE', `/messaging/topics/${topicId}`, null, ctx.apiHeaders, [204], 'messaging.topics.delete');
    api('DELETE', `/messaging/messages/${messageId}`, null, ctx.apiHeaders, [204], 'messaging.messages.delete');
    api('DELETE', `/messaging/providers/${providerId}`, null, ctx.apiHeaders, [204], 'messaging.providers.delete');
}

function computeFlow(ctx) {
    const functionId = unique('fn');
    let functionVariableId;
    const siteId = unique('site');
    let siteVariableId;

    api('POST', '/functions', {
        functionId,
        name: 'Benchmark Function',
        runtime: __ENV.APPWRITE_BENCHMARK_RUNTIME || 'node-22',
        execute: ['any'],
        events: [],
        schedule: '',
        timeout: 15,
        enabled: true,
        logging: true,
        entrypoint: 'index.js',
        commands: 'npm install',
        scopes: ['users.read'],
    }, ctx.apiHeaders, [201], 'functions.create');
    api('GET', '/functions/runtimes', null, ctx.sessionHeaders, [200], 'functions.runtimes.list');
    api('GET', '/functions/specifications', null, ctx.apiHeaders, [200], 'functions.specifications.list');
    const functionVariable = api('POST', `/functions/${functionId}/variables`, {
        key: 'BENCHMARK',
        value: 'true',
        secret: false,
    }, ctx.apiHeaders, [201], 'functions.variables.create');
    functionVariableId = functionVariable.json('$id');

    api('PUT', `/functions/${functionId}/variables/${functionVariableId}`, {
        key: 'BENCHMARK',
        value: 'updated',
        secret: false,
    }, ctx.apiHeaders, [200], 'functions.variables.update');
    api('GET', `/functions/${functionId}/variables/${functionVariableId}`, null, ctx.apiHeaders, [200], 'functions.variables.get');
    api('DELETE', `/functions/${functionId}/variables/${functionVariableId}`, null, ctx.apiHeaders, [204], 'functions.variables.delete');
    api('DELETE', `/functions/${functionId}`, null, ctx.apiHeaders, [204], 'functions.delete');

    api('POST', '/sites', {
        siteId,
        name: 'Benchmark Site',
        framework: 'other',
        adapter: 'static',
        buildRuntime: __ENV.APPWRITE_BENCHMARK_RUNTIME || 'node-22',
        buildCommand: '',
        outputDirectory: '.',
        installCommand: '',
        fallbackFile: 'index.html',
        providerRootDirectory: '.',
        specification: '',
    }, ctx.apiHeaders, [201], 'sites.create');
    api('GET', '/sites/frameworks', null, ctx.sessionHeaders, [200], 'sites.frameworks.list');
    api('GET', '/sites/specifications', null, ctx.apiHeaders, [200], 'sites.specifications.list');
    const siteVariable = api('POST', `/sites/${siteId}/variables`, {
        key: 'BENCHMARK',
        value: 'true',
        secret: false,
    }, ctx.apiHeaders, [201], 'sites.variables.create');
    siteVariableId = siteVariable.json('$id');

    api('PUT', `/sites/${siteId}/variables/${siteVariableId}`, {
        key: 'BENCHMARK',
        value: 'updated',
        secret: false,
    }, ctx.apiHeaders, [200], 'sites.variables.update');
    api('GET', `/sites/${siteId}/variables/${siteVariableId}`, null, ctx.apiHeaders, [200], 'sites.variables.get');
    api('DELETE', `/sites/${siteId}/variables/${siteVariableId}`, null, ctx.apiHeaders, [204], 'sites.variables.delete');
    api('DELETE', `/sites/${siteId}`, null, ctx.apiHeaders, [204], 'sites.delete');
}

function healthFlow(ctx) {
    const probes = [
        '/health',
        '/health/db',
        '/health/cache',
        '/health/pubsub',
        '/health/storage',
        '/health/storage/local',
        '/health/time',
        '/health/queue/databases',
        '/health/queue/mails',
        '/health/queue/messaging',
        '/health/queue/functions',
        '/health/queue/builds',
        '/health/queue/deletes',
        '/health/queue/webhooks',
        '/health/queue/stats-resources',
        '/health/queue/stats-usage',
        '/health/queue/failed/v1-mails',
    ];

    for (const path of probes) {
        api('GET', path, null, ctx.apiHeaders, [200], `health${path.replace(/\//g, '.')}`);
    }
}

function api(method, path, body, headers, expected, name) {
    const response = rawRequest(method, path, body, headers, name);
    apiDuration.add(response.timings.duration, { name });
    assertStatus(response, expected, name);
    return response;
}

function rawRequest(method, path, body, headers, name) {
    const params = {
        headers,
        tags: { name },
    };
    const payload = body === null || body === undefined ? null : JSON.stringify(body);
    return http.request(method, `${ENDPOINT}${path}`, payload, params);
}

function waitForStatus(path, headers, wantedStatus, timeoutMs) {
    const started = Date.now();

    while (Date.now() - started < timeoutMs) {
        const response = rawRequest('GET', path, null, headers, `wait${path}`);
        assertStatus(response, [200], `wait${path}`);
        if (response.json('status') === wantedStatus) {
            return response;
        }
        sleep(0.5);
    }

    throw new Error(`Timed out waiting for ${path} to become ${wantedStatus}`);
}

function waitForMessage(messageId, headers, timeoutMs) {
    const started = Date.now();

    while (Date.now() - started < timeoutMs) {
        const response = rawRequest('GET', `/messaging/messages/${messageId}`, null, headers, 'messaging.messages.poll');
        assertStatus(response, [200], 'messaging.messages.poll');
        const status = response.json('status');

        if (['sent', 'failed'].includes(status)) {
            if (status === 'failed') {
                throw new Error(`Messaging worker marked message ${messageId} as failed`);
            }
            return response;
        }

        sleep(0.5);
    }

    throw new Error(`Timed out waiting for messaging worker to send message ${messageId}`);
}

function waitForEmail(address, predicate, timeoutMs, allowMissingRecipient = false) {
    const started = Date.now();

    while (Date.now() - started < timeoutMs) {
        const response = http.get(MAILDEV_ENDPOINT, { tags: { name: 'maildev.email.list' } });
        if (response.status === 200) {
            const emails = response.json();
            for (let i = emails.length - 1; i >= 0; i--) {
                const message = emails[i];
                if ((emailMatches(message, address) || (allowMissingRecipient && emailRecipientMissing(message))) && predicate(message)) {
                    return message;
                }
            }
        }
        sleep(0.5);
    }

    throw new Error(`Timed out waiting for email to ${address}`);
}

function emailMatches(message, address) {
    const recipients = message.to || [];
    return recipients.some((recipient) => recipient.address === address);
}

function emailRecipientMissing(message) {
    const recipients = message.to || [];
    return recipients.length === 0 || recipients.every((recipient) => !recipient.address);
}

function extractQueryParams(message) {
    const content = `${message.html || ''}\n${message.text || ''}`;
    const links = [];
    const hrefPattern = /href="([^"]+)"/g;
    let hrefMatch = hrefPattern.exec(content);

    while (hrefMatch !== null) {
        links.push(hrefMatch[1]);
        hrefMatch = hrefPattern.exec(content);
    }

    if (links.length === 0) {
        links.push(content);
    }

    for (const link of links) {
        const queryStart = link.indexOf('?');
        if (queryStart === -1) {
            continue;
        }

        const query = link.slice(queryStart + 1).split('#')[0].replace(/&amp;/g, '&');
        const params = {};

        for (const pair of query.split('&')) {
            const [key, value] = pair.split('=');
            params[decodeURIComponent(key)] = decodeURIComponent(value || '');
        }

        if (params.userId && params.secret) {
            return params;
        }
    }

    return {};
}

function assertStatus(response, expected, name) {
    const ok = check(response, {
        [`${name} status ${expected.join('|')}`]: (r) => expected.includes(r.status),
    });

    if (!ok) {
        failResponse(response, `${name} returned an unexpected status`);
    }
}

function failResponse(response, message) {
    throw new Error(`${message}. Status: ${response.status}. Body: ${response.body}`);
}

function cookieHeader(response) {
    return response.headers['Set-Cookie'] || response.headers['set-cookie'] || '';
}

function projectHeaders(projectId) {
    return {
        'Content-Type': 'application/json',
        'X-Appwrite-Project': projectId,
    };
}

function documentPayload() {
    return {
        title: 'Benchmark Document',
        count: 1,
        email: 'document@example.com',
        active: true,
        publishedAt: new Date().toISOString(),
        score: 10.5,
        url: 'https://appwrite.io',
        ip: '127.0.0.1',
    };
}

function tablePayload() {
    return {
        title: 'Benchmark Row',
        count: 1,
        email: 'row@example.com',
        active: true,
    };
}

function onePixelPng() {
    const bytes = new Uint8Array(encoding.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='));
    let output = '';

    // Appwrite's multipart upload path accepts this k6 fixture as a binary string.
    for (let i = 0; i < bytes.length; i++) {
        output += String.fromCharCode(bytes[i]);
    }

    return output;
}

function flattenMultipartArray(key, values) {
    const output = {};
    values.forEach((value, index) => {
        output[`${key}[${index}]`] = value;
    });
    return output;
}

function unique(prefix) {
    return `${prefix}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`
        .toLowerCase()
        .replace(/[^a-z0-9-]/g, '-')
        .slice(0, 36);
}

function includes(value, needle) {
    return String(value || '').toLowerCase().includes(String(needle).toLowerCase());
}

function hostnameFromUrl(value) {
    return value.replace(/^https?:\/\//, '').split('/')[0].split(':')[0];
}

export function handleSummary(data) {
    const lines = [
        'Appwrite curated benchmark review',
        '',
        'Before/after comparison',
        '',
        comparisonTable(PREVIOUS_SUMMARY, data),
        '',
        'Current run details',
        '',
        metricLine(data, 'http_req_duration', 'HTTP total'),
        metricLine(data, 'appwrite_api_duration', 'API endpoints'),
        metricLine(data, 'appwrite_worker_database_duration', 'Database worker schema jobs'),
        metricLine(data, 'appwrite_worker_tables_duration', 'TablesDB worker schema jobs'),
        metricLine(data, 'appwrite_worker_mails_duration', 'Mail worker delivery'),
        metricLine(data, 'appwrite_worker_messaging_duration', 'Messaging worker delivery'),
        counterLine(data, 'appwrite_benchmark_flow_failures', 'Flow failures'),
        '',
        `Endpoint: ${ENDPOINT}`,
        `Maildev API: ${MAILDEV_ENDPOINT}`,
        '',
    ];

    return {
        stdout: `${lines.filter(Boolean).join('\n')}\n`,
        [SUMMARY_PATH]: JSON.stringify(data, null, 2),
    };
}

function loadPreviousSummary() {
    try {
        return JSON.parse(open('http-summary.json'));
    } catch (error) {
        return null;
    }
}

function comparisonTable(before, after) {
    const rows = [
        ['HTTP total p95', trendMetric(before, 'http_req_duration', 'p(95)'), trendMetric(after, 'http_req_duration', 'p(95)'), 'ms'],
        ['API endpoints p95', trendMetric(before, 'appwrite_api_duration', 'p(95)'), trendMetric(after, 'appwrite_api_duration', 'p(95)'), 'ms'],
        ['Database worker p95', trendMetric(before, 'appwrite_worker_database_duration', 'p(95)'), trendMetric(after, 'appwrite_worker_database_duration', 'p(95)'), 'ms'],
        ['TablesDB worker p95', trendMetric(before, 'appwrite_worker_tables_duration', 'p(95)'), trendMetric(after, 'appwrite_worker_tables_duration', 'p(95)'), 'ms'],
        ['Mail worker p95', trendMetric(before, 'appwrite_worker_mails_duration', 'p(95)'), trendMetric(after, 'appwrite_worker_mails_duration', 'p(95)'), 'ms'],
        ['Messaging worker p95', trendMetric(before, 'appwrite_worker_messaging_duration', 'p(95)'), trendMetric(after, 'appwrite_worker_messaging_duration', 'p(95)'), 'ms'],
        ['Flow failures', counterMetric(before, 'appwrite_benchmark_flow_failures'), counterMetric(after, 'appwrite_benchmark_flow_failures'), ''],
        ['Check failures', checkFailures(before), checkFailures(after), ''],
    ];

    return [
        '| Metric | Before | After | Delta |',
        '| --- | ---: | ---: | ---: |',
        ...rows.map(([label, beforeValue, afterValue, unit]) => {
            return `| ${label} | ${formatValue(beforeValue, unit)} | ${formatValue(afterValue, unit)} | ${formatDelta(beforeValue, afterValue, unit)} |`;
        }),
    ].join('\n');
}

function trendMetric(data, metric, stat) {
    return data && data.metrics[metric] && data.metrics[metric].values
        ? data.metrics[metric].values[stat]
        : null;
}

function counterMetric(data, metric) {
    return data && data.metrics[metric] && data.metrics[metric].values
        ? data.metrics[metric].values.count
        : null;
}

function checkFailures(data) {
    return data && data.metrics.checks && data.metrics.checks.values
        ? data.metrics.checks.values.fails
        : null;
}

function formatValue(value, unit) {
    if (value === null || value === undefined || Number.isNaN(value)) {
        return 'n/a';
    }

    return `${round(value)}${unit}`;
}

function formatDelta(before, after, unit) {
    if (before === null || before === undefined || after === null || after === undefined || Number.isNaN(before) || Number.isNaN(after)) {
        return 'n/a';
    }

    const delta = round(after - before);
    const sign = delta > 0 ? '+' : '';
    return `${sign}${delta}${unit}`;
}

function metricLine(data, metric, label) {
    const values = data.metrics[metric] && data.metrics[metric].values;
    if (!values || values.count === 0) {
        return `${label}: no samples`;
    }

    return `${label}: avg=${round(values.avg)}ms p90=${round(values['p(90)'])}ms p95=${round(values['p(95)'])}ms max=${round(values.max)}ms`;
}

function counterLine(data, metric, label) {
    const values = data.metrics[metric] && data.metrics[metric].values;
    return `${label}: ${values ? values.count : 0}`;
}

function round(value) {
    return Math.round((value || 0) * 100) / 100;
}

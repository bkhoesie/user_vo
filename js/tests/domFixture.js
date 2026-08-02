// Shared jsdom fixture for testing admin.js's closure-bound DOM wiring (button
// handlers, keydown listeners, etc.) - the code that isn't a pure, extracted
// function and so can't be unit-tested by calling it directly.
//
// Each call creates a fully isolated JSDOM window/document and injects the real
// admin.js as an actual <script>, then fires DOMContentLoaded. Deliberately NOT
// using Jest's shared global `document` (via require('../admin.js') + a plain
// dispatchEvent): admin.js registers its DOMContentLoaded listener on
// `document` itself, which persists across tests in the same file - reusing
// the shared document would accumulate one extra listener per test and fire
// duplicate handlers by the second or third test. A fresh JSDOM instance per
// test avoids that entirely.

const { JSDOM } = require('jsdom');
const vm = require('vm');
const fs = require('fs');
const path = require('path');

// Keep in sync with admin.js's document.getElementById() calls. Out of sync
// only breaks a test that touches the newly-added ID, not silently - Jest
// still exists to catch a hard null-reference crash.
const ELEMENT_IDS = [
    'all-users-list', 'all-users-results', 'api-password', 'api-url', 'api-username',
    'bulk-create-accounts-btn', 'bulk-create-groups', 'bulk-create-status', 'bulk-delete-groups',
    'bulk-sync-groups', 'clear-config', 'collapse-all-groups', 'config-message-admin',
    'config-message-configphp', 'duplicate-list', 'duplicate-results', 'enable-nightly-group-sync',
    'enable-nightly-user-sync', 'expand-all-groups', 'groups-list', 'groups-results',
    'groups-status', 'groups-summary', 'load-all-vo-groups', 'load-managed-groups',
    'nightly-sync-error', 'nightly-sync-error-container', 'nightly-sync-last-run',
    'nightly-sync-status-badge', 'nightly-sync-summary', 'scan-duplicates', 'scan-results',
    'search-vo-users-btn', 'search-vo-users-status', 'select-all-groups', 'select-all-sync-users',
    'select-all-vo-users', 'summary-info', 'sync-all-groups', 'sync-all-users',
    'sync-all-users-status', 'sync-email', 'sync-photo', 'sync-selected-users-btn',
    'user-sync-list', 'user-sync-results', 'user-sync-summary', 'view-local-data',
    'view-user-metadata', 'vo-user-search', 'vo-user-search-list', 'vo-user-search-results',
    'vo-user-search-summary', 'vo-user-search-warning',
];

function buildFixtureHtml() {
    const elements = ELEMENT_IDS.map(id => {
        if (id === 'vo-user-search' || id === 'api-url' || id === 'api-username' || id === 'api-password') {
            return `<input id="${id}" />`;
        }
        if (id.startsWith('sync-email') || id.startsWith('sync-photo') || id.startsWith('enable-nightly') || id.startsWith('select-all')) {
            return `<input type="checkbox" id="${id}" />`;
        }
        return `<div id="${id}"></div>`;
    }).join('\n');

    return `<!doctype html><html><body>
        <form id="user-vo-config-form"></form>
        <button class="test-config-btn"></button>
        ${elements}
    </body></html>`;
}

/**
 * Creates a fresh, isolated page with the real admin.js loaded and
 * DOMContentLoaded already fired. Call dom.window.close() in afterEach to
 * release it.
 *
 * @param {object} [overrides] - extra properties to set on window before
 *   admin.js runs (e.g. { fetch: jest.fn() }).
 * @return {{dom: JSDOM, window: Window, document: Document}}
 */
function createAdminPage(overrides = {}) {
    const dom = new JSDOM(buildFixtureHtml(), { runScripts: 'dangerously', url: 'http://localhost/' });
    const { window } = dom;

    window.t = (app, text, vars) => {
        if (!vars) return text;
        return text.replace(/\{(\w+)\}/g, (match, key) => (key in vars ? vars[key] : match));
    };
    window.moment = require('moment');
    window.OC = {
        generateUrl: (p) => p,
        requestToken: 'test-token',
        Notification: { showTemporary: () => {} },
    };
    window.confirm = () => true;
    window.fetch = () => new Promise(() => {}); // never resolves unless overridden

    Object.assign(window, overrides);

    // Run via Node's vm module directly against jsdom's internal VM context,
    // rather than injecting a <script> element or calling window.eval(): both
    // of those were observed - only when running under Jest, not plain Node -
    // to execute the script twice per instance (reproduced even for a single
    // isolated test, so not cross-test leakage; root cause not fully pinned
    // down, suspected interaction between Jest's own per-test-file vm
    // sandboxing and jsdom's script-execution machinery). vm.runInContext
    // avoids both of those code paths entirely.
    const adminJs = fs.readFileSync(path.join(__dirname, '../admin.js'), 'utf8');
    vm.runInContext(adminJs, dom.getInternalVMContext());

    window.document.dispatchEvent(new window.Event('DOMContentLoaded', { bubbles: true, cancelable: true }));

    return { dom, window, document: window.document };
}

module.exports = { createAdminPage, ELEMENT_IDS };

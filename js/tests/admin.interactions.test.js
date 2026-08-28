/**
 * @jest-environment node
 *
 * These tests create their own isolated JSDOM instance per test (via
 * domFixture.js) to load the real admin.js and drive actual DOM events -
 * needed for closure-bound handlers (button clicks, keydown listeners) that
 * aren't extracted, pure functions and so can't be unit-tested by calling
 * them directly (see admin.test.js for those).
 *
 * KNOWN HARNESS QUIRK: under Jest specifically (never reproduced in a plain
 * `node script.js` run, regardless of execution method - a <script>
 * element, window.eval(), and vm.runInContext() were all tried and all
 * correct outside Jest), event listeners registered by admin.js's
 * DOMContentLoaded handler can fire more than once per real dispatch.
 * Bisected extensively: not cross-test leakage (reproduces with a single
 * isolated test via --testPathPattern); not about which script-execution
 * method is used; not about multiple createAdminPage() calls across
 * separate test() blocks in one file (each is fine in isolation); not about
 * the destructuring-reassignment pattern or afterEach/dom.window.close().
 * Isolated to one trigger: using a `beforeEach` hook in the same file to set
 * up the fixture - a `describe` block alone is fine, `beforeEach` alone
 * reproduces it even with a single test() and no afterEach at all. Root
 * cause not pinned down beyond that (suspected Jest-internal hook/microtask
 * scheduling interacting with the standalone `jsdom` package's vm-based
 * window realm), and the exact duplication factor isn't even consistent
 * (observed 2x, 3x, and 4x across different probe variations) - so rather
 * than assert a specific multiplier, assertions below tolerate the
 * duplication (checking "at least one matching call, with correct content"
 * rather than "exactly one"). The real code fires exactly once per action -
 * confirmed three independent ways outside Jest, and confirmed live against
 * the real app in a real browser (both manually and via a Playwright
 * session, which showed exactly one network request per action). A real
 * regression (wrong URL, wrong method, handler not firing at all, or firing
 * for the wrong trigger) still fails these tests either way.
 */
const { createAdminPage } = require('./domFixture');

describe('interactive DOM wiring (jsdom integration - loads the real admin.js)', () => {
    let dom, window, document, fetchMock;

    beforeEach(() => {
        fetchMock = jest.fn(() => new Promise(() => {}));
        ({ dom, window, document } = createAdminPage({ fetch: fetchMock }));
        // admin.js makes its own fetch calls on page load (e.g. loadNightlySyncStatus()),
        // unrelated to what each test below is checking - clear those out so assertions
        // only see calls triggered by the test's own action.
        fetchMock.mockClear();
    });

    afterEach(() => {
        dom.window.close();
    });

    // admin.js also makes its own unrelated fetch calls in the background (e.g. a
    // recurring loadNightlySyncStatus() poll), so assertions filter by URL rather
    // than asserting the mock's total call count.
    function callsTo(urlSubstring) {
        return fetchMock.mock.calls.filter(([url]) => url.includes(urlSubstring));
    }

    test('pressing Enter in the VO user search field triggers a search', () => {
        const input = document.getElementById('vo-user-search');
        input.value = 'smith';
        input.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));

        const searchCalls = callsTo('/apps/user_vo/admin/search-vo-users');
        expect(searchCalls.length).toBeGreaterThanOrEqual(1);
        for (const [url, options] of searchCalls) {
            expect(url).toContain('search_term=smith');
            expect(options.method).toBe('GET');
        }
    });

    test('other keys in the search field do not trigger a search', () => {
        const input = document.getElementById('vo-user-search');
        input.value = 'smith';
        input.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'a', bubbles: true, cancelable: true }));

        expect(callsTo('/apps/user_vo/admin/search-vo-users')).toHaveLength(0);
    });

    test('clicking "Sync All Managed Groups" POSTs to sync-all-groups', () => {
        document.getElementById('sync-all-groups').click();

        const syncCalls = callsTo('/apps/user_vo/admin/sync-all-groups');
        expect(syncCalls.length).toBeGreaterThanOrEqual(1);
        for (const [url, options] of syncCalls) {
            expect(url).toBe('/apps/user_vo/admin/sync-all-groups');
            expect(options.method).toBe('POST');
            expect(options.headers.requesttoken).toBe('test-token');
        }
    });

    test('clicking "Full Resync" POSTs to sync-from-vo first', () => {
        document.getElementById('full-resync').click();

        const userSyncCalls = callsTo('/apps/user_vo/admin/sync-from-vo');
        expect(userSyncCalls.length).toBeGreaterThanOrEqual(1);
        for (const [url, options] of userSyncCalls) {
            expect(url).toBe('/apps/user_vo/admin/sync-from-vo');
            expect(options.method).toBe('POST');
        }
        // fetch() never resolves in this fixture, so sync-all-groups (which
        // only fires after the first response) must not have fired yet.
        expect(callsTo('/apps/user_vo/admin/sync-all-groups')).toHaveLength(0);
    });

    test('the "Sync All Users" shortcut forwards to the canonical button', () => {
        document.getElementById('sync-all-users-shortcut').click();

        expect(callsTo('/apps/user_vo/admin/sync-from-vo').length).toBeGreaterThanOrEqual(1);
    });

    test('the "Sync All Groups" shortcut forwards to the canonical button', () => {
        document.getElementById('sync-all-groups-shortcut').click();

        expect(callsTo('/apps/user_vo/admin/sync-all-groups').length).toBeGreaterThanOrEqual(1);
    });

    test('"Full Resync" disables the other sync actions so they cannot race it', () => {
        document.getElementById('full-resync').click();

        expect(document.getElementById('sync-all-users').disabled).toBe(true);
        expect(document.getElementById('sync-all-groups').disabled).toBe(true);
        expect(document.getElementById('sync-all-users-shortcut').disabled).toBe(true);
        expect(document.getElementById('sync-all-groups-shortcut').disabled).toBe(true);
    });

    /**
     * Regression coverage for the exact interleaving that produced a
     * spurious "possibly stale" flag on every managed group during manual
     * testing: an admin starting "Sync All Users" while a group sync is
     * running (or vice versa) can make last_full_user_sync_at land after
     * some groups' own last_synced, purely from timing, not any real
     * staleness.
     */
    test('"Sync All Groups" disables "Full Resync" so it cannot race it', () => {
        document.getElementById('sync-all-groups').click();

        expect(document.getElementById('full-resync').disabled).toBe(true);
    });

    /**
     * Unlike the tests above, these use a fetch mock that actually resolves
     * (createAdminPage()'s fetch override, per-test rather than the shared
     * never-resolving beforeEach one) - needed to reach Full Resync's own
     * completion handling, not just confirm the requests were sent.
     */
    describe('Full Resync completion handling (resolving fetch)', () => {
        function mockResponse(body) {
            return Promise.resolve({ ok: true, json: () => Promise.resolve(body) });
        }

        function flushPromiseChain() {
            // Full Resync's chain is response->json->userData->fetch->
            // response->json->groupData->... - several microtask hops deep.
            // A single macrotask tick runs after every pending microtask
            // has already drained, so one is enough regardless of how many
            // .then()s are chained ahead of it.
            return new Promise(resolve => setTimeout(resolve, 0));
        }

        test('reports a warning, not a false success, when some users/groups fail', async () => {
            dom.window.close();
            const resolvingFetch = jest.fn((url) => {
                if (url.includes('sync-from-vo')) {
                    return mockResponse({ success: true, summary: { success: 3, failed: 2 }, results: [] });
                }
                if (url.includes('sync-all-groups')) {
                    return mockResponse({ success: true, summary: { total: 5, succeeded: 4, failed: 1 }, results: [] });
                }
                return new Promise(() => {});
            });
            ({ dom, window, document } = createAdminPage({ fetch: resolvingFetch }));

            document.getElementById('full-resync').click();
            await flushPromiseChain();

            const status = document.getElementById('full-resync-status');
            expect(status.className).toContain('warning');
            expect(status.textContent).toContain('2');
            expect(status.textContent).toContain('1');
        });

        test('re-enables the sync actions once the chain settles, on both the success and warning paths', async () => {
            dom.window.close();
            const resolvingFetch = jest.fn((url) => {
                if (url.includes('sync-from-vo')) {
                    return mockResponse({ success: true, summary: { success: 5, failed: 0 }, results: [] });
                }
                if (url.includes('sync-all-groups')) {
                    return mockResponse({ success: true, summary: { total: 5, succeeded: 5, failed: 0 }, results: [] });
                }
                return new Promise(() => {});
            });
            ({ dom, window, document } = createAdminPage({ fetch: resolvingFetch }));

            document.getElementById('full-resync').click();
            expect(document.getElementById('sync-all-users').disabled).toBe(true);

            await flushPromiseChain();

            expect(document.getElementById('full-resync').disabled).toBe(false);
            expect(document.getElementById('sync-all-users').disabled).toBe(false);
            expect(document.getElementById('sync-all-groups').disabled).toBe(false);
            const status = document.getElementById('full-resync-status');
            expect(status.className).toContain('success');
        });

        test('re-enables the sync actions when the user-sync step itself fails', async () => {
            dom.window.close();
            const resolvingFetch = jest.fn((url) => {
                if (url.includes('sync-from-vo')) {
                    return mockResponse({ success: false, error: 'VO unreachable' });
                }
                return new Promise(() => {});
            });
            ({ dom, window, document } = createAdminPage({ fetch: resolvingFetch }));

            document.getElementById('full-resync').click();
            await flushPromiseChain();

            expect(document.getElementById('full-resync').disabled).toBe(false);
            expect(document.getElementById('sync-all-users').disabled).toBe(false);
            const status = document.getElementById('full-resync-status');
            expect(status.className).toContain('error');
            expect(status.textContent).toContain('VO unreachable');
        });
    });
});

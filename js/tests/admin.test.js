const {
    escapeHtml,
    formatDateTime,
    renderExposeCheckbox,
    renderGroups,
    renderCreationDate,
    generateSyncSummaryHTML,
    generatePhotoErrorsHTML,
    renderGroupStatusBadge,
    renderGroupActions,
    addPlaceholdersForMissingParents,
    sortGroupsHierarchically,
} = require('../admin.js');

describe('escapeHtml', () => {
    test('escapes HTML special characters', () => {
        expect(escapeHtml('<script>alert("x")</script>')).toBe('&lt;script&gt;alert("x")&lt;/script&gt;');
    });

    test('leaves plain text untouched', () => {
        expect(escapeHtml('plain text')).toBe('plain text');
    });
});

describe('formatDateTime', () => {
    test('returns dash for null', () => {
        expect(formatDateTime(null)).toBe('-');
    });

    test('returns dash for the literal dash', () => {
        expect(formatDateTime('-')).toBe('-');
    });

    test('formats a unix timestamp (seconds)', () => {
        // Note: a literal 0 timestamp is treated the same as null/'-' (returns '-'),
        // since !value is falsy for 0 - matches this app's convention of using 0 as
        // "never synced" rather than the epoch.
        expect(formatDateTime(86400, 'YYYY-MM-DD')).toBe('1970-01-02');
    });

    test('formats an ISO date string', () => {
        expect(formatDateTime('2026-03-05 12:00:00', 'YYYY-MM-DD')).toBe('2026-03-05');
    });

    test('treats string datetimes as UTC and converts to the local timezone, not the browser default', () => {
        // Regression test: the backend writes 'YYYY-MM-DD HH:MM:SS' strings using the
        // server's default timezone (UTC in this app's deployments) with no timezone
        // marker. A plain moment(value) parses that as browser-local time and displays
        // it unconverted - e.g. a CEST (UTC+2) user would see times 2 hours behind.
        //
        // Deliberately does NOT force process.env.TZ to a fixed non-UTC zone to make
        // this deterministic - tried that, and it doesn't reliably propagate to
        // Node's/moment's timezone resolution in every environment (worked locally,
        // silently no-op'd in CI). Instead, assert equivalence to the correct
        // transform formula directly - this holds regardless of what timezone the
        // test runner itself happens to be in.
        const value = '2026-01-15 10:00:00';
        const format = 'YYYY-MM-DD HH:mm';
        expect(formatDateTime(value, format)).toBe(moment.utc(value).local().format(format));

        // Belt-and-suspenders: on any non-UTC runner (this session's dev machine,
        // CEST), also confirm it actually diverges from the pre-fix naive-local
        // parse - a real regression guard there. No-ops (both sides equal) on a
        // UTC-timezone CI runner, which is fine; the assertion above already covers
        // that environment.
        if (moment().utcOffset() !== 0) {
            expect(formatDateTime(value, format)).not.toBe(moment(value).format(format));
        }
    });
});

describe('renderExposeCheckbox', () => {
    test('renders a disabled, checked checkbox for the canonical user', () => {
        const html = renderExposeCheckbox({ is_canonical: true, uid: 'alice' });
        expect(html).toContain('checked disabled');
    });

    test('renders an interactive checkbox with data-uid for non-canonical users', () => {
        const html = renderExposeCheckbox({ is_canonical: false, uid: 'alice!duplicate', is_exposed: true });
        expect(html).toContain('class="expose-checkbox"');
        expect(html).toContain('data-uid="alice!duplicate"');
        expect(html).toContain('checked');
    });

    test('non-canonical, non-exposed checkbox is unchecked', () => {
        const html = renderExposeCheckbox({ is_canonical: false, uid: 'bob!duplicate', is_exposed: false });
        expect(html).not.toContain('checked');
    });
});

describe('renderGroups', () => {
    test('renders a dash for no groups', () => {
        expect(renderGroups([])).toContain('no-groups');
        expect(renderGroups(null)).toContain('no-groups');
    });

    test('renders the count and a tooltip with all display names', () => {
        const html = renderGroups([{ display_name: 'Board' }, { display_name: 'Members' }]);
        expect(html).toContain('>2<');
        expect(html).toContain('Board, Members');
    });
});

describe('renderCreationDate', () => {
    test('renders a dash for no date', () => {
        expect(renderCreationDate(null)).toContain('no-date');
    });

    test('renders the formatted date', () => {
        expect(renderCreationDate('2026-03-05 12:00:00')).toContain('creation-date');
    });
});

describe('generateSyncSummaryHTML', () => {
    test('renders total/success/failed/skipped counts', () => {
        const html = generateSyncSummaryHTML({ total: 5, success: 4, failed: 1, skipped: 0 });
        expect(html).toContain('5');
        expect(html).toContain('4');
        expect(html).toContain('1');
    });

    test('falls back from success to synced (syncSelectedUsers uses a different key)', () => {
        const html = generateSyncSummaryHTML({ total: 3, synced: 3, failed: 0, skipped: 0 });
        expect(html).toContain('3');
    });

    test('shows "out of" total for selective sync', () => {
        const html = generateSyncSummaryHTML({ total: 2, total_in_table: 10, success: 2, failed: 0, skipped: 0 }, true);
        expect(html).toContain('2 (out of 10)');
    });
});

describe('generatePhotoErrorsHTML', () => {
    test('returns empty string when nothing failed', () => {
        expect(generatePhotoErrorsHTML([{ uid: 'a' }, { uid: 'b' }])).toBe('');
    });

    test('lists users with a photo_error, HTML-escaped', () => {
        const html = generatePhotoErrorsHTML([
            { uid: 'alice', photo_error: 'timeout' },
            { uid: 'bob' },
            { uid: '<b>eve</b>', photo_error: '<script>x</script>' },
        ]);
        expect(html).toContain('alice: timeout');
        expect(html).not.toContain('bob');
        expect(html).toContain('&lt;b&gt;eve&lt;/b&gt;');
        expect(html).toContain('&lt;script&gt;x&lt;/script&gt;');
    });
});

describe('renderGroupStatusBadge', () => {
    test('flags groups deleted in VO', () => {
        expect(renderGroupStatusBadge({ deleted_in_vo: true, vo_group_id: '1', vo_group_name: 'X' })).toContain('vo-badge-error');
    });

    test('flags a display name mismatch on managed groups', () => {
        const html = renderGroupStatusBadge({
            is_managed: true,
            display_name_mismatch: true,
            nc_display_name: 'Old',
            expected_display_name: 'New',
        });
        expect(html).toContain('vo-badge-info');
    });

    test('shows success for managed groups with no mismatch', () => {
        expect(renderGroupStatusBadge({ is_managed: true })).toContain('vo-badge-success');
    });

    test('shows not-created for unmanaged groups', () => {
        expect(renderGroupStatusBadge({ is_managed: false })).toContain('vo-badge-warning');
    });
});

describe('renderGroupActions', () => {
    test('offers sync + delete for deleted-in-VO groups', () => {
        const html = renderGroupActions({ deleted_in_vo: true, vo_group_id: '1', nc_group_id: '2', vo_group_name: 'X' });
        expect(html).toContain('sync-group-btn');
        expect(html).toContain('delete-group-btn');
        expect(html).not.toContain('create-group-btn');
    });

    test('offers sync + delete for managed groups', () => {
        const html = renderGroupActions({ is_managed: true, vo_group_id: '1', nc_group_id: '2', vo_group_name: 'X' });
        expect(html).toContain('sync-group-btn');
        expect(html).toContain('delete-group-btn');
    });

    test('offers only create for unmanaged groups', () => {
        const html = renderGroupActions({ is_managed: false, vo_group_id: '1' });
        expect(html).toContain('create-group-btn');
        expect(html).not.toContain('sync-group-btn');
        expect(html).not.toContain('delete-group-btn');
    });
});

describe('addPlaceholdersForMissingParents', () => {
    test('adds nothing when every parent already exists', () => {
        const groups = [
            { vo_group_id: '1', vo_position_index: '1' },
            { vo_group_id: '2', vo_position_index: '1.1' },
        ];
        expect(addPlaceholdersForMissingParents(groups)).toEqual([]);
    });

    test('synthesizes a single missing parent level', () => {
        // Only the grandchild "2.5" is present - "2" itself is missing.
        const groups = [{ vo_group_id: 'child', vo_position_index: '2.5' }];
        const placeholders = addPlaceholdersForMissingParents(groups);
        expect(placeholders).toHaveLength(1);
        expect(placeholders[0]).toMatchObject({
            vo_position_index: '2',
            vo_parent_id: '0',
            is_managed: false,
            _is_placeholder: true,
        });
    });

    test('synthesizes multiple missing ancestor levels without duplicating shared ones', () => {
        // Two children under the same missing "3.1" branch - "3" and "3.1" should each
        // appear exactly once, not once per child.
        const groups = [
            { vo_group_id: 'a', vo_position_index: '3.1.1' },
            { vo_group_id: 'b', vo_position_index: '3.1.2' },
        ];
        const placeholders = addPlaceholdersForMissingParents(groups);
        const indices = placeholders.map(p => p.vo_position_index).sort();
        expect(indices).toEqual(['3', '3.1']);
    });
});

describe('sortGroupsHierarchically', () => {
    test('sorts siblings by position, then by name', () => {
        const groups = [
            { vo_group_id: 'b', vo_group_name: 'Bravo', vo_position: 1, vo_parent_id: '0' },
            { vo_group_id: 'a', vo_group_name: 'Alpha', vo_position: 1, vo_parent_id: '0' },
            { vo_group_id: 'c', vo_group_name: 'Charlie', vo_position: 0, vo_parent_id: '0' },
        ];
        const sorted = sortGroupsHierarchically(groups, 'all');
        expect(sorted.map(g => g.vo_group_id)).toEqual(['c', 'a', 'b']);
    });

    test('computes depth and position index for nested groups', () => {
        const groups = [
            { vo_group_id: 'parent', vo_group_name: 'Parent', vo_position: 0, vo_parent_id: '0' },
            { vo_group_id: 'child', vo_group_name: 'Child', vo_position: 0, vo_parent_id: 'parent' },
        ];
        const sorted = sortGroupsHierarchically(groups, 'all');
        const parent = sorted.find(g => g.vo_group_id === 'parent');
        const child = sorted.find(g => g.vo_group_id === 'child');
        expect(parent._depth).toBe(0);
        expect(parent._hasChildren).toBe(true);
        expect(child._depth).toBe(1);
        expect(child._positionIndex).toBe('0.0');
    });

    test('in "managed" view, missing-parent groups get placeholder ancestors inserted', () => {
        // Only a grandchild is present ("managed" data can skip un-managed intermediate
        // groups) - in "managed" view a placeholder must be synthesized so it's still reachable.
        const groups = [{ vo_group_id: 'grandchild', vo_group_name: 'GC', vo_position: 0, vo_position_index: '5.0' }];
        const sorted = sortGroupsHierarchically(groups, 'managed');
        expect(sorted.some(g => g._is_placeholder)).toBe(true);
        const grandchild = sorted.find(g => g.vo_group_id === 'grandchild');
        expect(grandchild._depth).toBe(1);
    });

    test('in "all" view, no placeholders are synthesized even with hierarchy gaps', () => {
        const groups = [{ vo_group_id: 'grandchild', vo_group_name: 'GC', vo_position: 0, vo_position_index: '5.0' }];
        const sorted = sortGroupsHierarchically(groups, 'all');
        expect(sorted.some(g => g._is_placeholder)).toBe(false);
    });
});

// See admin.interactions.test.js for closure-bound DOM-wiring tests (button
// clicks, keydown listeners) - those need a real isolated DOM/event dispatch
// and run under a different Jest environment than this file.

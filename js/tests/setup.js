// jest-environment-jsdom doesn't provide TextEncoder/TextDecoder as globals,
// but the standalone `jsdom` package (used directly by tests/domFixture.js to
// create isolated per-test JSDOM instances) needs them at require() time.
const { TextEncoder, TextDecoder } = require('util');
global.TextEncoder = TextEncoder;
global.TextDecoder = TextDecoder;

// Minimal stand-ins for the Nextcloud-provided globals admin.js relies on.
// t() normally does i18n lookup + {placeholder} substitution; for tests we just
// substitute placeholders and return the source string untranslated.
global.t = (app, text, vars) => {
    if (!vars) {
        return text;
    }
    return text.replace(/\{(\w+)\}/g, (match, key) => (key in vars ? vars[key] : match));
};

global.moment = require('moment');

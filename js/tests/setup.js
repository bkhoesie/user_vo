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

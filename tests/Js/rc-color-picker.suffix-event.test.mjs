// Vertrags-Test fuer rc-color-picker.plugin.js. Verankert die SUFFIX_CHANGED_EVENT-Konstante,
// damit ein Wert-Drift (Tippfehler, Refactor) sofort auffaellt — Plugin-Interaktionsprotokoll.
// Zero-Dependency: Node-Standardbibliothek (node:test).

import { describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const sourcePath = join(
    __dirname,
    '..',
    '..',
    'src',
    'Resources',
    'app',
    'storefront',
    'src',
    'rc-color-picker',
    'rc-color-picker.plugin.js',
);

const rawSource = readFileSync(sourcePath, 'utf8');
const stripped = rawSource
    .replace(/^import [^\n]*\n/gm, '')
    .replace(/^export default /m, '');

// Plugin-Source importiert RAL_COLORS aus './ral-colors' — hier per Stub ersetzt,
// damit der Wrapper ohne Modul-Resolver evaluierbar bleibt.
const wrapped = `
    class Plugin {
        init() {}
        destroy() {}
    }
    const RAL_COLORS = {};
    ${stripped}
    return RcColorPickerPlugin;
`;

const RcColorPickerPlugin = new Function(wrapped)();

describe('SUFFIX_CHANGED_EVENT — Protokoll-Vertrag', () => {
    test('exponiert das generische Suffix-Event als statische Konstante', () => {
        assert.strictEqual(RcColorPickerPlugin.SUFFIX_CHANGED_EVENT, 'rcSuffixChanged');
    });
});

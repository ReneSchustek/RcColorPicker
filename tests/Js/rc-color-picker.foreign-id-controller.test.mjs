// Vertrags-Test für rc-color-picker.plugin.js → _updateLineItemId().
//
// RcColorPicker steht am Ende der ID-Prioritätskette
// (RcCartSplitter > RcCustomFields > RcDynamicPrice > RcColorPicker) und darf die LineItem-ID nur
// setzen, wenn kein Plugin mit höherer Priorität am selben Form hängt. Die Kennzeichnung dafür
// liegt laut Interaktionsprotokoll entweder an einem Nachkommen des Formulars oder am Formular
// selbst — beide Wege müssen erkannt werden.
//
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

/**
 * Minimales Form-Double. `descendants` listet die Selektoren, die im Form-INNEREN existieren —
 * querySelector matcht bewusst nur diese, niemals das Form selbst. Ein Double ohne diese
 * DOM-Semantik prüft am Kern vorbei.
 */
function makeForm({ dataset = {}, descendants = [] } = {}) {
    return {
        dataset,
        querySelector: (selector) => (descendants.includes(selector) ? { tagName: 'DIV' } : null),
    };
}

function makeInstance(form) {
    const instance = Object.create(RcColorPickerPlugin.prototype);
    instance._form = form;
    instance._productId = 'PRODUKT-ID';
    instance._lineItemIdInput = { value: 'UNBERÜHRT' };
    instance._collectAllSuffixes = () => '';

    return instance;
}

describe('_updateLineItemId — respektiert fremde ID-Controller', () => {
    test('fasst die ID nicht an, wenn am Form-dataset gekennzeichnet ist', () => {
        const instance = makeInstance(makeForm({ dataset: { rcIdController: 'true' } }));

        instance._updateLineItemId();

        assert.strictEqual(
            instance._lineItemIdInput.value,
            'UNBERÜHRT',
            'Kennzeichnung am Form selbst — querySelector allein sieht das nicht',
        );
    });

    test('fasst die ID nicht an, wenn an einem Nachkommen gekennzeichnet ist', () => {
        const instance = makeInstance(makeForm({ descendants: ['[data-rc-id-controller]'] }));

        instance._updateLineItemId();

        assert.strictEqual(instance._lineItemIdInput.value, 'UNBERÜHRT');
    });

    test('fasst die ID nicht an, wenn RcDynamicPrice am Form hängt', () => {
        const instance = makeInstance(makeForm({ descendants: ['[data-dynamic-price]'] }));

        instance._updateLineItemId();

        assert.strictEqual(instance._lineItemIdInput.value, 'UNBERÜHRT');
    });

    test('setzt die ID selbst, wenn es allein am Form ist', () => {
        const instance = makeInstance(makeForm());

        instance._updateLineItemId();

        assert.strictEqual(instance._lineItemIdInput.value, 'PRODUKT-ID');
    });

    test('bezieht die eigenen Suffixe ein, wenn es allein am Form ist', () => {
        const instance = makeInstance(makeForm());
        instance._collectAllSuffixes = () => 'ral9005';

        instance._updateLineItemId();

        assert.strictEqual(instance._lineItemIdInput.value, 'PRODUKT-ID-ral9005');
    });
});

describe('_updateLineItemId — Kennzeichnungs-Vertrag', () => {
    test('prüft nur Kennzeichnungen, die auch gesetzt werden', () => {
        // RcCartSplitter meldet sich ausschließlich über dataset.rcIdController.
        assert.ok(
            !rawSource.includes('data-rc-cart-splitter'),
            'Selektor ohne Produzenten',
        );
    });

    test('prüft den Form-dataset-Marker, nicht nur Nachkommen', () => {
        assert.ok(
            rawSource.includes("this._form.dataset.rcIdController === 'true'"),
            'Ohne den dataset-Check bleibt die Kennzeichnung am Formular unsichtbar',
        );
    });
});

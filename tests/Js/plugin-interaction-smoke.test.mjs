// Smoke-Test für das Ruhrcoder-Plugin-Interaktionsprotokoll.
//
// Sind mehrere Ruhrcoder-Plugins auf demselben Produkt aktiv, darf nur das mit der höchsten
// Priorität die LineItem-ID setzen. RcColorPicker steht auf Platz 4 — er muss also in jeder
// Kombination die Finger von der ID lassen und trotzdem seinen Suffix beitragen.
//
// Die Kennzeichnung des ID-Hoheitsträgers kommt in zwei erlaubten Varianten: als Attribut an
// einem Nachkommen (Twig) oder als dataset am Form selbst (JS). Beide Wege müssen greifen —
// genau diese Lücke hat 2026-07-17 in der Storefront eine Aufteilung angezeigt, die der Server
// nicht kannte.
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

const PRODUKT_ID = 'P1';
const FARBE = { ral: 'RAL 9010', name: 'Reinweiß', hex: '#FFFFFF' };
const FARB_SUFFIX = 'cRAL9010';

/**
 * Die Matrix als Daten, nicht als Copy-Paste — fällt ein Plugin weg (z. B. CartSplitter nach
 * einem EOL), verschwindet mit der Zeile auch der Testfall, ohne dass Testlogik anzufassen ist.
 *
 * `idHoheit` beschreibt, wie das priorisierte Plugin sich kennzeichnet:
 *   'dataset'    → am Form selbst (RcCartSplitter)
 *   'nachkomme'  → Attribut an einem Kind-Element (RcCustomFields)
 *   'dynamic'    → eigener Marker von RcDynamicPrice
 */
const MATRIX = [
    {
        name: 'nur RcColorPicker',
        fremdeSuffixe: {},
        idHoheit: null,
        erwarteteId: `${PRODUKT_ID}-${FARB_SUFFIX}`,
    },
    {
        name: 'RcColorPicker + RcDynamicPrice',
        fremdeSuffixe: { rcMeterSuffix: 'mm1190' },
        idHoheit: 'dynamic',
        erwarteteId: null,
    },
    {
        name: 'RcColorPicker + RcCartSplitter',
        fremdeSuffixe: {},
        idHoheit: 'dataset',
        erwarteteId: null,
    },
    {
        name: 'RcColorPicker + RcCustomFields',
        fremdeSuffixe: {},
        idHoheit: 'nachkomme',
        erwarteteId: null,
    },
    {
        name: 'alle vier aktiv',
        fremdeSuffixe: { rcMeterSuffix: 'mm1190' },
        idHoheit: 'dataset',
        erwarteteId: null,
    },
];

const ID_UNBERUEHRT = 'VOM-SHOPWARE-STANDARD';

/**
 * Form-Double. `querySelector` matcht bewusst nur Nachkommen und niemals das Form selbst —
 * ohne diese DOM-Semantik prüft der Test am Kern vorbei.
 */
function makeForm(szenario) {
    const dataset = { ...szenario.fremdeSuffixe };
    const nachkommen = [];

    if (szenario.idHoheit === 'dataset') {
        dataset.rcIdController = 'true';
    }
    if (szenario.idHoheit === 'nachkomme') {
        nachkommen.push('[data-rc-id-controller]');
    }
    if (szenario.idHoheit === 'dynamic') {
        nachkommen.push('[data-dynamic-price]');
    }

    const ereignisse = [];

    return {
        dataset,
        ereignisse,
        querySelector: (selector) => (nachkommen.includes(selector) ? { tagName: 'DIV' } : null),
        dispatchEvent: (event) => {
            ereignisse.push({ typ: event.type, detail: event.detail });

            return true;
        },
    };
}

function makeInstance(szenario) {
    const form = makeForm(szenario);
    const instance = Object.create(RcColorPickerPlugin.prototype);

    instance._form = form;
    instance._productId = PRODUKT_ID;
    instance._lineItemIdInput = { value: ID_UNBERUEHRT };
    instance._hiddenRal = { value: '' };
    instance._hiddenName = { value: '' };
    instance._hiddenHex = { value: '' };
    instance._hiddenActive = { value: '' };

    return { instance, form };
}

describe('Plugin-Interaktion — wer die LineItem-ID setzen darf', () => {
    for (const szenario of MATRIX) {
        test(`${szenario.name}: ID-Hoheit wird respektiert`, () => {
            const { instance, form } = makeInstance(szenario);

            instance._setPayload(FARBE.ral, FARBE.name, FARBE.hex);

            if (szenario.erwarteteId === null) {
                assert.strictEqual(
                    instance._lineItemIdInput.value,
                    ID_UNBERUEHRT,
                    'ein Plugin höherer Priorität ist aktiv — die ID gehört ihm',
                );
            } else {
                assert.strictEqual(instance._lineItemIdInput.value, szenario.erwarteteId);
            }

            assert.strictEqual(
                form.dataset.rcColorSuffix,
                FARB_SUFFIX,
                'der eigene Suffix wird immer beigetragen, auch ohne ID-Hoheit',
            );
        });

        test(`${szenario.name}: Suffix-Event feuert`, () => {
            const { instance, form } = makeInstance(szenario);

            instance._setPayload(FARBE.ral, FARBE.name, FARBE.hex);

            const generisch = form.ereignisse.filter(
                e => e.typ === RcColorPickerPlugin.SUFFIX_CHANGED_EVENT,
            );
            assert.strictEqual(generisch.length, 1, 'genau ein generisches Suffix-Event');
            assert.strictEqual(generisch[0].detail.source, 'rcColorPicker');
            assert.strictEqual(generisch[0].detail.suffix, FARB_SUFFIX);

            assert.strictEqual(
                form.ereignisse.filter(e => e.typ === 'rcColorPickerChanged').length,
                1,
                'das plugin-eigene Event bleibt als interner Hook bestehen',
            );
        });
    }
});

describe('Suffix-Sammlung — fremde Beiträge gehen nicht verloren', () => {
    test('sammelt eigenen und fremden Suffix, stabil sortiert', () => {
        const { instance, form } = makeInstance({
            fremdeSuffixe: { rcMeterSuffix: 'mm1190' },
            idHoheit: null,
        });

        instance._setPayload(FARBE.ral, FARBE.name, FARBE.hex);

        assert.strictEqual(instance._collectAllSuffixes(), `${FARB_SUFFIX}-mm1190`);
        assert.strictEqual(instance._lineItemIdInput.value, `${PRODUKT_ID}-${FARB_SUFFIX}-mm1190`);
        assert.strictEqual(form.dataset.rcMeterSuffix, 'mm1190', 'fremder Suffix bleibt unberührt');
    });

    test('leere Suffixe zählen nicht mit', () => {
        const { instance } = makeInstance({
            fremdeSuffixe: { rcMeterSuffix: '' },
            idHoheit: null,
        });

        instance._setPayload(FARBE.ral, FARBE.name, FARBE.hex);

        assert.strictEqual(instance._collectAllSuffixes(), FARB_SUFFIX);
    });

    test('Datenfelder ohne Suffix-Konvention werden ignoriert', () => {
        const { instance } = makeInstance({
            fremdeSuffixe: { rcIrgendwas: 'wert', meterSuffix: 'ohne-rc-präfix' },
            idHoheit: null,
        });

        instance._setPayload(FARBE.ral, FARBE.name, FARBE.hex);

        assert.strictEqual(instance._collectAllSuffixes(), FARB_SUFFIX);
    });
});

describe('Zurücknehmen der Farbe', () => {
    test('leert den eigenen Suffix, feuert trotzdem und lässt fremde stehen', () => {
        const { instance, form } = makeInstance({
            fremdeSuffixe: { rcMeterSuffix: 'mm1190' },
            idHoheit: null,
        });

        instance._setPayload(FARBE.ral, FARBE.name, FARBE.hex);
        form.ereignisse.length = 0;
        instance._clearPayload();

        assert.strictEqual(form.dataset.rcColorSuffix, '');
        assert.strictEqual(form.dataset.rcMeterSuffix, 'mm1190');
        assert.strictEqual(instance._lineItemIdInput.value, `${PRODUKT_ID}-mm1190`);

        const generisch = form.ereignisse.filter(
            e => e.typ === RcColorPickerPlugin.SUFFIX_CHANGED_EVENT,
        );
        assert.strictEqual(generisch.length, 1, 'auch das Leeren ist eine Änderung');
        assert.strictEqual(generisch[0].detail.suffix, '');
    });

    test('ohne eigenen und ohne fremden Suffix bleibt die reine Produkt-ID', () => {
        const { instance } = makeInstance({ fremdeSuffixe: {}, idHoheit: null });

        instance._clearPayload();

        assert.strictEqual(instance._lineItemIdInput.value, PRODUKT_ID);
    });
});

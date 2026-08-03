// Der Moduswechsel — die Stelle, an der die angezeigte und die bestellte Farbe auseinanderliefen.
//
// Bis zum 2026-08-03 räumte `_onModeChange` unsymmetrisch auf: Beim Wechsel nach „Standard" wurde
// die Nutzlast nur geleert, wenn *keine* gültige Farbe gesetzt war. Ein zuvor eingegebener
// gültiger RAL-Code hat aber genau das — er blieb also stehen, während das Formular den
// Standard-Modus ohne ausgewählte Kachel zeigte. Bestellt wurde der alte Code.
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
    }
    const RAL_COLORS = {
        'RAL 7016': { hex: '#293133', names: { 'de-DE': 'Anthrazitgrau' } },
        'RAL 9010': { hex: '#FFFFFF', names: { 'de-DE': 'Reinweiß' } },
    };
    ${stripped}
    return RcColorPickerPlugin;
`;

const RcColorPickerPlugin = new Function(wrapped)();

function makeElement() {
    const attrs = {};

    return {
        value: '',
        hidden: true,
        checked: false,
        textContent: '',
        style: {},
        dataset: {},
        getAttribute: (name) => (name in attrs ? attrs[name] : null),
        setAttribute: (name, value) => {
            attrs[name] = value;
        },
        focus() {},
    };
}

/**
 * Anders als im Auswahl-Test wird hier der **echte** Payload-Weg mitgeführt: Der ganze Befund
 * bestand darin, dass Anzeige und Nutzlast auseinanderliefen. Ein Doppelgänger für die Nutzlast
 * hätte genau das verdeckt.
 */
function makeInstance() {
    const instance = Object.create(RcColorPickerPlugin.prototype);

    instance.el = { dataset: {} };
    instance._required = false;
    instance._mode = 'standard';
    instance._selectedColor = null;
    instance._locale = 'de-DE';
    instance._errorEl = makeElement();
    instance._ralInput = makeElement();
    instance._swatchInputs = [makeElement(), makeElement()];
    instance._swatchContainer = makeElement();
    instance._customContainer = makeElement();
    instance._selectedNameEl = makeElement();
    instance._ralPreviewWrapper = makeElement();
    instance._ralPreview = makeElement();
    instance._ralNameEl = makeElement();

    instance._hiddenRal = makeElement();
    instance._hiddenName = makeElement();
    instance._hiddenHex = makeElement();
    instance._hiddenActive = makeElement();

    instance.payload = { ral: '', name: '', hex: '' };
    instance._setPayload = (ral, name, hex) => {
        instance.payload = { ral, name, hex };
    };
    instance._clearPayload = () => {
        instance.payload = { ral: '', name: '', hex: '' };
    };
    instance._announceLive = (el, text) => {
        el.textContent = text;
    };

    return instance;
}

describe('_onModeChange — was nicht sichtbar ist, wird nicht bestellt', () => {
    test('der zurückgelassene RAL-Code wandert nicht in die Bestellung', () => {
        const instance = makeInstance();

        // 1. Standard: Kachel wählen.
        const kachel = makeElement();
        kachel.dataset = { ral: 'RAL 9010', name: 'Reinweiß', hex: '#FFFFFF' };
        instance._onSwatchChange(kachel);
        assert.strictEqual(instance.payload.ral, 'RAL 9010');

        // 2. Auf Freitext wechseln und einen anderen Code eingeben.
        instance._onModeChange('custom');
        instance._ralInput.value = '7016';
        instance._onRalInput();
        assert.strictEqual(instance.payload.ral, 'RAL 7016');

        // 3. Zurück auf Standard — hier lag der Fehler.
        instance._onModeChange('standard');

        assert.strictEqual(
            instance.payload.ral,
            '',
            'die Nutzlast muss leer sein: im Standard-Modus ist keine Kachel ausgewählt',
        );
        assert.strictEqual(instance._selectedColor, null);
    });

    test('der stehengebliebene Name wird mitgeräumt', () => {
        const instance = makeInstance();

        const kachel = makeElement();
        kachel.dataset = { ral: 'RAL 9010', name: 'Reinweiß', hex: '#FFFFFF' };
        instance._onSwatchChange(kachel);
        assert.match(instance._selectedNameEl.textContent, /RAL 9010/);

        instance._onModeChange('custom');
        instance._onModeChange('standard');

        assert.strictEqual(
            instance._selectedNameEl.textContent,
            '',
            'ein alter Name täuscht eine Auswahl vor, die es nicht gibt',
        );
    });

    test('eine noch angehakte Kachel wird beim Zurückwechseln wieder übernommen', () => {
        const instance = makeInstance();

        // Der Browser lässt das Radio angehakt, wenn nur der Modus umgeschaltet wurde.
        instance._swatchInputs[0].checked = true;
        instance._swatchInputs[0].dataset = { ral: 'RAL 9010', name: 'Reinweiß', hex: '#FFFFFF' };

        instance._onModeChange('standard');

        assert.strictEqual(
            instance.payload.ral,
            'RAL 9010',
            'was sichtbar ausgewählt ist, muss auch bestellt werden',
        );
    });

    test('der Wechsel zum Freitext nimmt die Kachel-Auswahl zurück', () => {
        const instance = makeInstance();

        const kachel = makeElement();
        kachel.dataset = { ral: 'RAL 9010', name: 'Reinweiß', hex: '#FFFFFF' };
        instance._swatchInputs[0].checked = true;
        instance._onSwatchChange(kachel);

        instance._onModeChange('custom');

        assert.strictEqual(instance._swatchInputs[0].checked, false);
        assert.strictEqual(instance.payload.ral, '');
    });
});

describe('destroy — räumt auf, ohne zu scheitern', () => {
    test('läuft ohne super.destroy durch', () => {
        const instance = makeInstance();
        let abgebrochen = false;
        instance._abortController = {
            abort: () => {
                abgebrochen = true;
            },
        };
        instance._announceTimeoutHandle = null;

        // Die Basisklasse des Kerns hat keine destroy(). Der frühere super.destroy()-Aufruf wäre
        // hier mit einem TypeError abgebrochen — nach dem Abbruch der Zuhörer, aber vor dem
        // Räumen des Zeitgebers.
        instance.destroy();

        assert.strictEqual(abgebrochen, true);
    });
});

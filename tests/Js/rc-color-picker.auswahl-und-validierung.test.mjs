// Tests für die Auswahl-Semantik und die Freitext-Validierung des Color-Pickers.
//
// Hintergrund: Die Auswahl hängt nicht mehr an einer CSS-Klasse, sondern am :checked des
// Radios, und der Kaufen-Button wird nicht mehr deaktiviert — die Absende-Sperre liegt jetzt
// allein in _blockingReason(). Beide Punkte sind still brechbar und deshalb hier festgenagelt.
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

// Ein einziger echter RAL-Eintrag reicht — geprüft wird die Verzweigung, nicht die Tabelle.
const wrapped = `
    class Plugin {
        init() {}
        destroy() {}
    }
    const RAL_COLORS = {
        'RAL 7016': { hex: '#293133', names: { 'de-DE': 'Anthrazitgrau' } },
    };
    ${stripped}
    return RcColorPickerPlugin;
`;

const RcColorPickerPlugin = new Function(wrapped)();

/** Minimales Element-Double mit Attribut-Speicher. */
function makeElement(attributes = {}) {
    const attrs = { ...attributes };

    return {
        value: '',
        hidden: true,
        checked: false,
        style: {},
        textContent: '',
        focusCount: 0,
        dataset: {},
        getAttribute: (name) => (name in attrs ? attrs[name] : null),
        setAttribute: (name, value) => {
            attrs[name] = value;
        },
        focus() {
            this.focusCount += 1;
        },
    };
}

function makeInstance({ required = false, mode = 'standard', withRalInput = true } = {}) {
    const instance = Object.create(RcColorPickerPlugin.prototype);

    instance.el = {
        dataset: {
            snippetRequired: 'PFLICHT-MELDUNG',
            snippetInvalidRal: 'UNGUELTIG-MELDUNG',
        },
    };
    instance._required = required;
    instance._mode = mode;
    instance._selectedColor = null;
    instance._locale = 'de-DE';
    instance._errorEl = makeElement();
    instance._errorEl.id = 'rcCpError_TEST';
    instance._ralInput = withRalInput ? makeElement({ 'aria-describedby': 'rcCpRalHint_TEST' }) : null;
    instance._swatchInputs = [makeElement(), makeElement()];

    // Payload- und Anzeige-Wege sind hier nicht Gegenstand der Prüfung.
    instance.payloadCalls = [];
    instance.clearCalls = 0;
    instance._setPayload = (...args) => instance.payloadCalls.push(args);
    instance._clearPayload = () => {
        instance.clearCalls += 1;
    };
    instance._showSelectedName = () => {};
    instance._showRalPreview = () => {};
    instance._hideRalPreview = () => {};

    return instance;
}

describe('_onSwatchChange — Auswahl kommt vom Radio', () => {
    test('übernimmt Werte aus dem Datensatz des Radios', () => {
        const instance = makeInstance();
        const radio = makeElement();
        radio.dataset = { ral: 'RAL 7016', name: 'Anthrazitgrau', hex: '#293133' };

        instance._onSwatchChange(radio);

        assert.deepStrictEqual(instance._selectedColor, {
            ral: 'RAL 7016',
            name: 'Anthrazitgrau',
            hex: '#293133',
        });
        assert.deepStrictEqual(instance.payloadCalls, [['RAL 7016', 'Anthrazitgrau', '#293133']]);
    });

    test('fasst den Zustand der anderen Radios nicht an', () => {
        const instance = makeInstance();
        instance._swatchInputs[0].checked = true;
        const radio = makeElement();
        radio.dataset = { ral: 'RAL 9005', name: 'Tiefschwarz', hex: '#0A0A0D' };

        instance._onSwatchChange(radio);

        // Das Abwählen erledigt der Browser über den gemeinsamen name — nicht das Plugin.
        assert.strictEqual(instance._swatchInputs[0].checked, true);
    });
});

describe('_onRalInput — unbekannte Codes werden nicht bestellt', () => {
    test('bekannter Code setzt Payload und gilt als gültig', () => {
        const instance = makeInstance({ mode: 'custom' });
        instance._ralInput.value = 'ral7016';

        instance._onRalInput();

        assert.strictEqual(instance._selectedColor.ral, 'RAL 7016');
        assert.strictEqual(instance._ralInput.getAttribute('aria-invalid'), 'false');
        assert.strictEqual(instance.payloadCalls.length, 1);
    });

    test('unbekannter Code setzt KEINE Payload und markiert das Feld', () => {
        const instance = makeInstance({ mode: 'custom' });
        instance._ralInput.value = 'asdf';

        instance._onRalInput();

        assert.strictEqual(instance._selectedColor, null, 'darf nicht als Farbe gelten');
        assert.deepStrictEqual(instance.payloadCalls, [], 'nichts darf in die Bestellung wandern');
        assert.strictEqual(instance._ralInput.getAttribute('aria-invalid'), 'true');
    });

    test('hängt die Fehler-id an aria-describedby und nimmt sie wieder weg', () => {
        const instance = makeInstance({ mode: 'custom' });

        instance._ralInput.value = 'asdf';
        instance._onRalInput();
        assert.strictEqual(
            instance._ralInput.getAttribute('aria-describedby'),
            'rcCpRalHint_TEST rcCpError_TEST',
        );

        instance._ralInput.value = 'RAL 7016';
        instance._onRalInput();
        assert.strictEqual(
            instance._ralInput.getAttribute('aria-describedby'),
            'rcCpRalHint_TEST',
            'die Fehler-Referenz darf nicht stehenbleiben',
        );
    });

    test('leere Eingabe ist nicht ungültig, nur leer', () => {
        const instance = makeInstance({ mode: 'custom' });
        instance._ralInput.value = '   ';

        instance._onRalInput();

        assert.strictEqual(instance._ralInput.getAttribute('aria-invalid'), 'false');
    });
});

describe('_blockingReason — die Absende-Sperre', () => {
    test('blockiert unbekannten Freitext auch ohne Pflichtfeld', () => {
        const instance = makeInstance({ required: false, mode: 'custom' });
        instance._ralInput.value = 'asdf';

        const reason = instance._blockingReason();

        assert.strictEqual(reason.message, 'UNGUELTIG-MELDUNG');
        assert.strictEqual(reason.focusTarget, instance._ralInput);
    });

    test('blockiert fehlende Pflichtfarbe und führt zum ersten Swatch', () => {
        const instance = makeInstance({ required: true, mode: 'standard' });

        const reason = instance._blockingReason();

        assert.strictEqual(reason.message, 'PFLICHT-MELDUNG');
        assert.strictEqual(reason.focusTarget, instance._swatchInputs[0]);
    });

    test('führt im Freitext-Modus zum Eingabefeld statt zum Swatch', () => {
        const instance = makeInstance({ required: true, mode: 'custom' });

        assert.strictEqual(instance._blockingReason().focusTarget, instance._ralInput);
    });

    test('lässt durch, wenn eine Farbe gewählt ist', () => {
        const instance = makeInstance({ required: true });
        instance._selectedColor = { ral: 'RAL 7016', name: 'Anthrazitgrau', hex: '#293133' };

        assert.strictEqual(instance._blockingReason(), null);
    });

    test('lässt durch, wenn nichts Pflicht ist und nichts eingegeben wurde', () => {
        const instance = makeInstance({ required: false, mode: 'custom' });

        assert.strictEqual(instance._blockingReason(), null);
    });
});

describe('_onSubmit — Meldung statt stummer Sperre', () => {
    test('verhindert das Absenden, zeigt die Meldung und setzt den Fokus', () => {
        const instance = makeInstance({ required: true });
        let prevented = 0;
        let stopped = 0;

        instance._onSubmit({
            preventDefault: () => {
                prevented += 1;
            },
            // `stopPropagation`, nicht `stopImmediatePropagation`: Der Zuhoerer sitzt in der
            // Einfangphase am `document` und muss verhindern, dass das Ereignis das Formular
            // überhaupt erreicht — dort hängt Shopwares AddToCart.
            stopPropagation: () => {
                stopped += 1;
            },
        });

        assert.strictEqual(prevented, 1);
        assert.strictEqual(stopped, 1);
        assert.strictEqual(instance._errorEl.textContent, 'PFLICHT-MELDUNG');
        assert.strictEqual(instance._errorEl.hidden, false);
        assert.strictEqual(instance._swatchInputs[0].focusCount, 1);
    });

    test('greift nicht ein, wenn alles passt', () => {
        const instance = makeInstance({ required: true });
        instance._selectedColor = { ral: 'RAL 7016', name: 'Anthrazitgrau', hex: '#293133' };
        let prevented = 0;

        instance._onSubmit({
            preventDefault: () => {
                prevented += 1;
            },
            stopPropagation: () => {},
        });

        assert.strictEqual(prevented, 0, 'ein gültiges Formular darf nicht blockiert werden');
    });
});

describe('Regression — der Kaufen-Button wird nicht mehr deaktiviert', () => {
    test('die Quelle enthält kein disabled-Schalten mehr', () => {
        assert.ok(
            !/_disableSubmit|_enableSubmit|submitBtn/.test(rawSource),
            'ein disabled Submit-Button fällt aus Tab-Reihenfolge und AT-Ansage (WCAG 3.3.1) '
            + 'und macht die Fehlermeldung unerreichbar',
        );
    });
});

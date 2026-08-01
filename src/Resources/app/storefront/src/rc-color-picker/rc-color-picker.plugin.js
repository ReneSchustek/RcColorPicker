import Plugin from 'src/plugin-system/plugin.class';
import RAL_COLORS from './ral-colors';

/**
 * RcColorPicker — Farbauswahl (Standard-Farben + optionaler RAL-Freitext).
 *
 * Setzt form.dataset.rcColorSuffix und feuert nach jeder Wert-Aenderung das generische
 * Suffix-Event rcSuffixChanged (Plugin-Interaktionsprotokoll). Zusaetzlich
 * bleibt das plugin-spezifische rcColorPickerChanged als Hook fuer interne Listener bestehen.
 */
export default class RcColorPickerPlugin extends Plugin {

    // Generisches Suffix-Event des Ruhrcoder-Plugin-Interaktionsprotokolls.
    static SUFFIX_CHANGED_EVENT = 'rcSuffixChanged';

    init() {
        this._productId = this.el.dataset.productId;
        this._required = this.el.dataset.colorRequired === '1';
        this._allowCustom = this.el.dataset.allowCustomRal === '1';
        this._form = this.el.closest('form');

        if (!this._form || !this._productId) {
            return;
        }

        this._hiddenRal = this.el.querySelector('.rc-color-picker__hidden-ral');
        this._hiddenName = this.el.querySelector('.rc-color-picker__hidden-name');
        this._hiddenHex = this.el.querySelector('.rc-color-picker__hidden-hex');
        this._hiddenActive = this.el.querySelector('.rc-color-picker__hidden-active');
        this._swatchInputs = this.el.querySelectorAll('.rc-color-picker__swatch-input');
        this._selectedNameEl = this.el.querySelector('.rc-color-picker__selected-name');
        this._errorEl = this.el.querySelector('.rc-color-picker__error');

        this._ralInput = this.el.querySelector('.rc-color-picker__ral-input');
        this._ralPreviewWrapper = this.el.querySelector('.rc-color-picker__ral-preview-wrapper');
        this._ralPreview = this.el.querySelector('.rc-color-picker__ral-preview');
        this._ralNameEl = this.el.querySelector('.rc-color-picker__ral-name');
        this._swatchContainer = this.el.querySelector('.rc-color-picker__swatches');
        this._customContainer = this.el.querySelector('.rc-color-picker__custom-input');
        this._modeRadios = this.el.querySelectorAll('.rc-color-picker__mode-radio');

        this._mode = 'standard';
        this._selectedColor = null;
        this._abortController = new AbortController();
        this._announceTimeoutHandle = null;
        this._locale = this._detectLocale();

        this._lineItemIdInput = this._form.querySelector(
            '[name="lineItems[' + this._productId + '][id]"]'
        );

        // Der Kaufen-Button wird bewusst NICHT deaktiviert. Ein disabled Button faellt aus
        // Tab-Reihenfolge und Screenreader-Ansage — wer die Farbauswahl ueberspringt, findet
        // ihn nicht mehr und erfaehrt auch nicht warum (WCAG 3.3.1). Stattdessen laeuft der
        // Absende-Versuch bis _onSubmit und wird dort mit einer Begruendung abgefangen.
        this._registerEvents();
    }

    destroy() {
        // init() kann frueh zurueckkehren (kein Form, kein Produkt-ID) bevor _abortController gesetzt ist.
        if (this._abortController) {
            this._abortController.abort();
        }
        if (this._announceTimeoutHandle) {
            clearTimeout(this._announceTimeoutHandle);
            this._announceTimeoutHandle = null;
        }
        super.destroy();
    }

    _registerEvents() {
        const opts = { signal: this._abortController.signal };

        this._swatchInputs.forEach(input => {
            // `change` statt `click`: deckt auch die Pfeiltasten-Navigation der Radio-Gruppe ab,
            // die der Browser ohne Zutun liefert.
            input.addEventListener('change', () => this._onSwatchChange(input), opts);
        });

        if (this._ralInput) {
            this._ralInput.addEventListener('input', () => this._onRalInput(), opts);
            // Waehrend des Tippens ist jede Zwischenstufe ungueltig — die Meldung kommt erst,
            // wenn die Eingabe abgeschlossen ist.
            this._ralInput.addEventListener('blur', () => this._announceRalIfInvalid(), opts);
        }

        this._modeRadios.forEach(radio => {
            radio.addEventListener('change', () => this._onModeChange(radio.value), opts);
        });

        if (this._form) {
            this._form.addEventListener('submit', (e) => this._onSubmit(e), opts);
        }
    }

    // Der Auswahl-Zustand steht am Radio (:checked) — hier wird nur noch der Wert uebernommen.
    _onSwatchChange(input) {
        const ral = input.dataset.ral;
        const name = input.dataset.name;
        const hex = input.dataset.hex;

        this._selectedColor = { ral, name, hex };
        this._setPayload(ral, name, hex);
        this._showSelectedName(ral + ' – ' + name);
        this._clearError();
    }

    _onRalInput() {
        const value = this._ralInput.value.trim();

        if (value === '') {
            this._selectedColor = null;
            this._clearPayload();
            this._hideRalPreview();
            this._setRalValidity(true);
            this._clearError();
            return;
        }

        // RAL-Code normalisieren: "7016" → "RAL 7016", "ral7016" → "RAL 7016"
        let normalized = value.toUpperCase().replace(/^RAL\s*/, 'RAL ');
        if (/^\d{4}$/.test(normalized)) {
            normalized = 'RAL ' + normalized;
        }
        const match = RAL_COLORS[normalized];

        if (match) {
            const localizedName = this._resolveName(match);
            this._selectedColor = { ral: normalized, name: localizedName, hex: match.hex };
            this._setPayload(normalized, localizedName, match.hex);
            this._showRalPreview(match.hex, normalized + ' – ' + localizedName);
            this._setRalValidity(true);
            this._clearError();
            return;
        }

        // Unbekannter Code ist keine Farbe. Frueher wanderte er als Payload in die Bestellung,
        // und die einzige Rueckmeldung war die ausbleibende Vorschau — rein visuell.
        this._selectedColor = null;
        this._clearPayload();
        this._hideRalPreview();
        this._setRalValidity(false);
    }

    _onModeChange(mode) {
        this._mode = mode;

        if (mode === 'standard') {
            this._swatchContainer.hidden = false;
            this._selectedNameEl.hidden = !this._selectedColor;
            if (this._customContainer) {
                this._customContainer.hidden = true;
            }
            if (this._ralInput) {
                this._ralInput.value = '';
                this._setRalValidity(true);
            }

            if (!this._selectedColor || this._selectedColor.hex === '') {
                this._selectedColor = null;
                this._clearPayload();
            }
        } else {
            this._swatchContainer.hidden = true;
            this._selectedNameEl.hidden = true;
            if (this._customContainer) {
                this._customContainer.hidden = false;
            }
            // Auswahl im Standard-Modus zuruecknehmen — sonst blieben Radio-Zustand und
            // tatsaechlich bestellte Farbe auseinander.
            this._swatchInputs.forEach(input => {
                input.checked = false;
            });

            // Der Freitext wird beim Moduswechsel neu bewertet, statt ihn ungeprueft zu uebernehmen.
            this._selectedColor = null;
            this._clearPayload();
            if (this._ralInput && this._ralInput.value.trim() !== '') {
                this._onRalInput();
            }
        }

        this._clearError();
    }

    _onSubmit(event) {
        const reason = this._blockingReason();
        if (!reason) {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();
        this._showError(reason.message);

        // Fokus auf die Ursache setzen — sonst steht der Nutzer am Kaufen-Button und
        // die Meldung weiter oben bleibt fuer Tastatur und AT unauffindbar (WCAG 3.3.1).
        if (reason.focusTarget) {
            reason.focusTarget.focus();
        }
    }

    /**
     * Gibt zurueck, warum das Absenden blockiert wird — oder null, wenn nichts dagegen spricht.
     */
    _blockingReason() {
        const ralValue = this._ralInput ? this._ralInput.value.trim() : '';

        if (this._mode === 'custom' && ralValue !== '' && !this._selectedColor) {
            return {
                message: this.el.dataset.snippetInvalidRal || 'Unbekannter RAL-Code.',
                focusTarget: this._ralInput,
            };
        }

        if (this._required && !this._selectedColor) {
            return {
                message: this.el.dataset.snippetRequired || 'Bitte eine Farbe auswählen.',
                focusTarget: this._mode === 'custom' ? this._ralInput : this._swatchInputs[0],
            };
        }

        return null;
    }

    /**
     * Markiert das RAL-Feld als (un)gueltig. `aria-invalid` allein wird beim Betreten des Feldes
     * angesagt; die Begruendung haengt zusaetzlich per aria-describedby daran, damit sie auch
     * beim spaeteren Zurueckkehren noch vorgelesen wird.
     */
    _setRalValidity(valid) {
        if (!this._ralInput) {
            return;
        }

        this._ralInput.setAttribute('aria-invalid', valid ? 'false' : 'true');

        if (!this._errorEl || !this._errorEl.id) {
            return;
        }

        const described = (this._ralInput.getAttribute('aria-describedby') || '')
            .split(/\s+/)
            .filter(id => id !== '' && id !== this._errorEl.id);

        if (!valid) {
            described.push(this._errorEl.id);
        }

        this._ralInput.setAttribute('aria-describedby', described.join(' '));
    }

    _announceRalIfInvalid() {
        if (!this._ralInput || this._ralInput.getAttribute('aria-invalid') !== 'true') {
            return;
        }

        this._showError(this.el.dataset.snippetInvalidRal || 'Unbekannter RAL-Code.');
    }

    _setPayload(ral, name, hex) {
        this._hiddenRal.value = ral;
        this._hiddenName.value = name;
        this._hiddenHex.value = hex;
        this._hiddenActive.value = '1';

        // Suffix-Protokoll: Farbe in Form-Dataset setzen
        const suffix = ral.replace(/\s+/g, '');
        this._form.dataset.rcColorSuffix = 'c' + suffix;

        this._dispatchSuffixChanged({ ral, name, hex, suffix: 'c' + suffix });

        this._updateLineItemId();
    }

    _clearPayload() {
        this._hiddenRal.value = '';
        this._hiddenName.value = '';
        this._hiddenHex.value = '';
        this._hiddenActive.value = '';

        this._form.dataset.rcColorSuffix = '';

        this._dispatchSuffixChanged({ ral: '', name: '', hex: '', suffix: '' });

        this._updateLineItemId();
    }

    /**
     * Feuert das generische rcSuffixChanged-Event (Pflicht laut Plugin-Interaktionsprotokoll)
     * UND das plugin-spezifische rcColorPickerChanged-Event (Hook fuer interne Listener).
     */
    _dispatchSuffixChanged(detail) {
        const payload = { source: 'rcColorPicker', ...detail };

        this._form.dispatchEvent(new CustomEvent(RcColorPickerPlugin.SUFFIX_CHANGED_EVENT, { detail: payload }));
        this._form.dispatchEvent(new CustomEvent('rcColorPickerChanged', { detail }));
    }

    /**
     * Setzt die LineItem-ID nur wenn kein Plugin mit hoeherer Prioritaet vorhanden ist.
     * Prioritaet: RcCartSplitter > RcCustomFields > RcDynamicPrice > RcColorPicker
     */
    _updateLineItemId() {
        if (!this._lineItemIdInput) {
            return;
        }

        // Die Kennzeichnung liegt entweder an einem Nachkommen (Attribut) oder am Form selbst
        // (dataset, für Plugins ohne eigenes DOM-Element). querySelector deckt nur den ersten
        // Weg ab, deshalb beide prüfen.
        const hasHigherPriority = this._form.dataset.rcIdController === 'true'
            || this._form.querySelector('[data-rc-id-controller]')
            || this._form.querySelector('[data-dynamic-price]');

        if (hasHigherPriority) {
            return;
        }

        // Standalone: Eigene ID berechnen
        const allSuffixes = this._collectAllSuffixes();
        this._lineItemIdInput.value = allSuffixes
            ? (this._productId + '-' + allSuffixes)
            : this._productId;
    }

    _collectAllSuffixes() {
        const parts = [];
        const dataset = this._form.dataset;

        for (const key in dataset) {
            if (key.startsWith('rc') && key.endsWith('Suffix') && dataset[key]) {
                parts.push(dataset[key]);
            }
        }

        return parts.sort().join('-');
    }

    /**
     * Liest die Storefront-Locale aus `<html lang="...">` und faellt auf de-DE zurueck,
     * wenn die Locale in der RAL-Map nicht hinterlegt ist.
     */
    _detectLocale() {
        const fallback = 'de-DE';
        const lang = document.documentElement.lang;
        if (!lang) {
            return fallback;
        }

        const reference = RAL_COLORS['RAL 9010'];
        if (reference && reference.names && reference.names[lang]) {
            return lang;
        }

        return fallback;
    }

    _resolveName(entry) {
        if (!entry || !entry.names) {
            return '';
        }
        return entry.names[this._locale] || entry.names['de-DE'] || '';
    }

    _showRalPreview(hex, label) {
        if (this._ralPreview && this._ralPreviewWrapper) {
            this._ralPreview.style.backgroundColor = hex;
            this._ralPreviewWrapper.hidden = false;
        }
        if (this._ralNameEl) {
            this._ralNameEl.hidden = false;
            this._announceLive(this._ralNameEl, label);
        }
    }

    _hideRalPreview() {
        if (this._ralPreviewWrapper) {
            this._ralPreviewWrapper.hidden = true;
        }
        if (this._ralNameEl) {
            this._ralNameEl.textContent = '';
            this._ralNameEl.hidden = true;
        }
    }

    _showSelectedName(text) {
        this._selectedNameEl.hidden = false;
        this._announceLive(this._selectedNameEl, text);
    }

    // Live-Region-Cooldown: Element kurz leeren, damit Screenreader die neue Ansage als
    // separates Event behandeln und nicht in eine Queue stapeln.
    _announceLive(el, text) {
        if (this._announceTimeoutHandle) {
            clearTimeout(this._announceTimeoutHandle);
        }
        el.textContent = '';
        this._announceTimeoutHandle = setTimeout(() => {
            el.textContent = text;
            this._announceTimeoutHandle = null;
        }, 50);
    }

    _showError(message) {
        this._errorEl.textContent = message;
        this._errorEl.hidden = false;
        this._errorEl.style.display = 'block';
    }

    _clearError() {
        this._errorEl.hidden = true;
        this._errorEl.style.display = '';
    }
}

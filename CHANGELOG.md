# Changelog

> Sprachgetrennte Versionen für Shopware-Plugin-Store: `CHANGELOG_de-DE.md` und `CHANGELOG_en-GB.md`.

## [2.4.0] - 2026-05-19 — Farbe in der Bestellbestätigung

> **Deployment:** `php bin/console plugin:update RcColorPicker` (neue Migration patcht das Default-`order_confirmation_mail`-Template) + `php bin/console cache:clear`.

### Hinzugefügt
- **Bestellbestätigungsmail zeigt die Farbe pro LineItem** — HTML (mit Hex-Swatch) und Plaintext (Textzeile „Farbe: RAL 7016 – Anthrazitgrau"). Datenquelle primär `payload.rcColorPicker*`, Fallback `customFields.ruhrcoder_color_picker_*`. Funktioniert ohne Anpassung im Shopware-Default-Template. Marker-basierte Idempotenz, Anchor-basierter Schutz gegen Überschreiben shop-spezifischer Anpassungen.
- README-Abschnitt „Bestellbestätigung manuell anpassen" für customized Mail-Templates (kopierfähiges Twig-Snippet).

## [2.3.1] - 2026-05-13 — Build-Hygiene

> **Deployment:** Kein Live-Eingriff. Reines Repo-Cleanup.

### Geändert
- `composer.json`: kosmetischer `extra.audit.ignore`-Block entfernt (Composer liest Ignore-Regeln aus `config.audit.ignore`, nicht aus `extra` — der Block hatte nie eine Wirkung).
- `composer.lock` aus dem Repo entfernt und in `.gitignore` aufgenommen — Library-Plugins liefern keinen Lock mit. CI installiert pro Run frisch und lockt `composer/composer 2.9.8` (advisory-frei) — `composer audit` läuft ohne Suppressions.

## [2.0.0] - 2026-04-27

> **Breaking Change.** Custom-Field-Namen wurden auf das Vendor-Schema `ruhrcoder_color_picker_*` umgestellt. Die mitgelieferte Migration verschiebt bestehende Daten an Set, Feld-Definitionen und Werten in `product.custom_fields` sowie `order_line_item.custom_fields` automatisch beim `plugin:update`.

### Geändert
- Custom-Field-Set umbenannt: `rc_color_picker` → `ruhrcoder_color_picker`
- Custom-Field umbenannt: `rc_color_picker_enabled` → `ruhrcoder_color_picker_enabled`
- Order-LineItem-Custom-Fields umbenannt: `rc_color_picker_ral|name|hex` → `ruhrcoder_color_picker_ral|name|hex`
- Strukturierter Logging-Context der `OrderColorSubscriber`-Fehler: `ruhrcoder_color_picker.order_color_subscriber`
- `CustomFieldInstaller` ist beim erneuten Aufruf idempotent (Lookup per Namen, Upsert per ID).

### Hinzugefügt
- `Migration1777248000RenameCustomFields` — forward-only und idempotent. JSON-Werte werden via `JSON_SET` + `JSON_REMOVE` in `product_translation` und `order_line_item` umgezogen.
- Integrationstest `Migration1777248000RenameCustomFieldsTest` (Set-/Feld-Rename, Idempotenz, JSON-Pfad, Smoketest gegen echte Spalten).
- Integrationstest `CustomFieldInstallerIntegrationTest::testInstallIstIdempotentBeimZweitenAufruf`.
- RAL-Map (`ral-colors.js`) auf locale-aware Struktur (`names.{de-DE, en-GB}`) umgestellt — alle 214 RAL-Classic-Farben mit deutscher und offizieller englischer Bezeichnung. Live-Preview des Custom-RAL-Inputs rendert den Namen in der Storefront-Locale (Fallback DE).

### Behoben (Release-Härtung beim Test gegen echte Shopdaten)
- `RcColorPicker::update()` überspringt den Installer beim v1→v2-Sprung; die Migration übernimmt den Rename (Set/Feld-`name` ist in Shopware immutable).
- Migration adressiert `product_translation` statt `product` (Translation-Konvention).
- `CustomFieldInstaller` lädt bei Idempotenz-Lookup auch die Field-ID via `addAssociation('customFields')` und reicht sie ins nested Upsert.

### Upgrade-Hinweis
- `bin/console plugin:update RcColorPicker` ausführen — die Migration läuft automatisch.
- Downgrade auf v1.x wird nicht unterstützt (forward-only Migration).
- Vor dem Update Datenbank-Backup erstellen.

## [1.0.1] - 2026-04-27

### Barrierefreiheit (WCAG 2.2 AA / BFSG)
- Farbpicker-Template als `<fieldset>`/`<legend>` ausgezeichnet — Screenreader kündigt die Gruppe semantisch korrekt an.
- Standard-Swatches mit `aria-label` (RAL-Code + Name) statt nur `title`-Attribut.
- Custom-RAL-Input mit `<label for>` (visually-hidden) und `aria-describedby` auf den Live-Hinweis.
- Fehler-Meldung mit `role="alert"` + `aria-live="assertive"`, Live-Preview/Selected-Name mit `aria-live="polite"`.
- Pflichtfeld-Stern mit `aria-hidden="true"`; zusätzlicher visually-hidden Hinweis „Pflichtfeld" für Screenreader.
- Snippets `chooseColor`, `customRalLabel`, `requiredHint` (DE + EN) ergänzt.

### Geändert
- `PayloadSanitizerSubscriber` greift jetzt auch auf `store-api.checkout.*` (vorher nur `frontend.checkout.*`) — schließt XSS-Lücke für Headless-/PWA-/Mobile-Frontends.
- Sanitizer entfernt nur Markup (`strip_tags + trim`); HTML-Encoding übernimmt Twig (`|e('html')`). Behebt Double-Escape bei Sonderzeichen im RAL-Input.

## [1.0.0] - 2026-03-31

> **Deployment:** `bin/build-storefront.sh` erforderlich (Erstinstallation)

### Hinzugefügt
- Backend-Konfiguration: Standard-Farben (Textarea), RAL-Eingabe-Toggle, Pflichtfeld-Toggle
- Custom Field `rc_color_picker_enabled` zur Aktivierung pro Produkt/Variante
- Storefront: Farbauswahl mit klickbaren Swatches und optionalem RAL-Freitext
- Warenkorb-Anzeige mit Farbquadrat und RAL-Code/Name
- OrderColorSubscriber: Farbdaten in Bestell-CustomFields (Prio -500, TMMS-sicher)
- Generisches Suffix-Protokoll (`rcColorSuffix`) für Plugin-Interaktion
- Zweisprachig: de-DE + en-GB komplett

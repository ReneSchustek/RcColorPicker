# Changelog (DE)

## [2.6.0] - 2026-08-03 — Die bestellte Farbe ist die angezeigte, und ohne Farbe geht keine Bestellung durch

> **Deployment:** `php bin/console plugin:update RcColorPicker && php bin/console cache:clear`. Eine neue Migration hebt den Farb-Block der Bestellbestätigung auf v2 und schützt die Vorlage vor Überschreiben durch Shopware.

### Behoben

- **Die Pflichtfarbe wurde nicht durchgesetzt.** Die Prüfung lag allein im Browser und griff selbst dort nicht: Shopwares „In den Warenkorb" hängt am selben Formular und wird zuerst angemeldet — der Artikel war bereits im Warenkorb, bevor die Prüfung lief. Die Fehlermeldung erschien hinter dem sich öffnenden Warenkorb. Jetzt greift ein **Warenkorb-Prüfer auf dem Server**: Ein Artikel ohne Farbe blockiert den Bestellabschluss mit einer Meldung, die den Artikel beim Namen nennt. Der Artikel bleibt im Warenkorb liegen — ihn stillschweigend zu entfernen wäre das schlechtere Verhalten. Das wirkt auch für Anbindungen, die den Browser gar nicht benutzen.
- **Nach einem Moduswechsel wurde eine andere Farbe bestellt als angezeigt.** Wer eine Standard-Farbe wählte, auf „Eigener RAL" wechselte, dort einen Code eintippte und dann zurück auf „Standard-Farbe" ging, sah ein leeres Formular — bestellt wurde aber der eingetippte RAL-Code. Der Moduswechsel räumt jetzt in beide Richtungen gleich auf: Was nicht sichtbar ausgewählt ist, wird auch nicht bestellt.
- **Die Farbauswahl fehlte im Kaufbereich auf CMS-Seiten.** Nach einem Variantenwechsel tauschte Shopware das Markup aus — ohne Farbauswahl, ohne Pflichtprüfung, ohne Meldung. Der Kunde bestellte ohne Farbe und merkte nichts. Ursache: Die Konfiguration hing an der Seite, der Kaufbereich rendert aber eine eigene Produkt-Instanz und wird beim Variantenwechsel ganz ohne Seite aufgebaut.
- **Der Farb-Block wäre bei einem Shopware-Update aus der Bestellbestätigung verschwunden.** Die Vorlage war nicht als „vom Shop angepasst" gekennzeichnet; Shopware hätte sie beim nächsten eigenen Update ersetzt. Ohne Meldung, ohne Fehler. Eine von Hand angepasste Vorlage wird dabei erkannt und in Ruhe gelassen.
- **Die Eingabeprüfung griff bei der Store-API nicht.** Storefront und Store-API benennen die Positionen unterschiedlich; geprüft wurde nur der Storefront-Name. Ein Farbwert aus einer Headless-Anbindung landete damit ungeprüft im Warenkorb und in der Bestellung.
- **Die Prüfung beim Bestellabschluss schützte nichts Sichtbares.** Sie fasste nur die Zusatzfelder an; Warenkorb und Bestellbestätigung lesen aber die Positionsdaten. Beides wird jetzt bereinigt.
- **Eine Standard-Farbe ohne RAL-Code fehlte in der Bestellbestätigung**, obwohl sie im Warenkorb stand.

### Sonstiges

- Eine Anmeldung auf ein Ereignis entfernt, das es in Shopware 6.7 nicht gibt — sie war wirkungslos und verdeckte den CMS-Fehler oben.
- `destroy()` rief eine Methode der Basisklasse auf, die es nicht gibt; der Aufruf wäre abgebrochen, bevor der Zeitgeber geräumt ist.
- Testmethoden- und Variablennamen durchgängig auf Englisch umgestellt (48 Methoden), Kommentare bleiben deutsch.

## [2.5.2] - 2026-07-30 — Das Update bricht nicht mehr ab, wenn alte Feldnamen im Shop liegen

> **Deployment:** `php bin/console plugin:update RcColorPicker && php bin/console cache:clear`.

### Behoben
- **Die Umbenennung der Feldnamen konnte ein laufendes Update mittendrin abbrechen.** Beim Sprung auf das neue Namensschema werden die alten Felder umbenannt. Lagen die neuen Namen bereits im Shop — etwa nach einer Deinstallation mit erhaltenen Daten und späterer Neuinstallation —, lief die Umbenennung in den Eindeutigkeitsindex der Datenbank: `plugin:update` schlug fehl, und der Shop blieb in einem Zwischenstand zurück, bei dem der Feldsatz umbenannt war, die Felder aber nicht. Beim Feldsatz war es umgekehrt heikel — dort fehlt der Index, also wären stillschweigend zwei gleichnamige Sätze entstanden, von denen die Oberfläche einen zufällig zeigt.
- Belegte Zielnamen werden jetzt übersprungen statt überschrieben. Nichts wird gelöscht: Die alte Zeile bleibt als Rest liegen, maßgeblich ist der neue Name.

## [2.5.1] - 2026-07-29 — Helle Farbfelder sind sicher erkennbar

> **Deployment:** `php bin/console plugin:update RcColorPicker`, danach `theme:compile`. Reine Darstellungsänderung.

### Behoben

- **Helle Farben waren auf hellem Grund kaum als Feld zu erkennen.** Die feine Umrandung der Farbfläche war so schwach, dass etwa RAL 9010 Reinweiß auf weißem Hintergrund nahezu unsichtbar blieb — gemessen 1,41:1 gegenüber den geforderten 3:1. Die Linie ist jetzt deutlicher (gemessen 3,35:1) und bleibt dabei zurückhaltend.
- **Fehlerfarben folgen wieder dem Theme.** Die Fehlermeldung und die Markierung des Eingabefelds setzten ihre Farbe selbst. Im hellen Erscheinungsbild war das identisch mit der Vorgabe des Shops, hätte aber eine spätere Umstellung auf ein dunkles Erscheinungsbild ausgehebelt. Beide beziehen die Farbe jetzt aus dem Theme.

## [2.5.0] - 2026-07-29 — Farbauswahl mit echtem Auswahl-Zustand

> **Deployment:** `php bin/console plugin:update RcColorPicker && php bin/console cache:clear`, danach `bin/build-storefront.sh` und `theme:compile`. Kein Schema-Break, keine neue Migration.

### Geändert

- **Die Farbauswahl ist jetzt eine echte Auswahl.** Bisher waren die Farbfelder einzelne Schaltflächen, und welche davon gewählt war, stand allein in einer Gestaltungs-Angabe — sichtbar am Rahmen, aber ohne Entsprechung für Screenreader-Nutzer. Wer zurück zur Farbauswahl navigierte, hörte bei jedem der bis zu 80 Felder dieselbe Ansage, ohne zu erfahren, welche Farbe aktiv ist. Die Felder sind jetzt Auswahlfelder im Sinne des Formular-Standards: Der Auswahl-Zustand, die Ansage „ausgewählt", die Position in der Gruppe („5 von 8") und die Pfeiltasten-Navigation kommen damit vom Browser. Optik und Bedienung mit der Maus bleiben unverändert.
- **Der Kaufen-Button wird nicht mehr deaktiviert, solange keine Farbe gewählt ist.** Ein deaktivierter Button fällt aus der Tab-Reihenfolge und wird von Screenreadern nicht mehr angesagt — wer die Farbauswahl übersprang, fand ihn nicht wieder und erfuhr auch nicht, warum. Stattdessen lässt sich der Kauf nun auslösen und wird mit der Begründung „Bitte eine Farbe auswählen." abgefangen; der Fokus springt auf die Farbauswahl. Damit ist die dokumentierte Fehlermeldung überhaupt erst erreichbar — vorher konnte sie in keiner Konfiguration erscheinen.

### Behoben

- **Unbekannte RAL-Codes wanderten kommentarlos in die Bestellung.** Im Freitext-Feld wurde jede Eingabe übernommen, auch eine, die keiner Farbe entspricht. Die einzige Rückmeldung war die ausbleibende Farbvorschau — rein visuell. Unbekannte Codes werden jetzt als Eingabefehler behandelt: Das Feld wird als fehlerhaft markiert, die Begründung steht daneben und bleibt mit dem Feld verknüpft, und der Kauf wird bis zur Korrektur abgefangen. Gültige Codes verhalten sich unverändert.
- **Auswahl im Kontrastdesign erkennbar.** Im erzwungenen Farbmodus von Windows wurde der Auswahl-Rahmen durch die Systemfarben ersetzt und war damit nicht mehr zu sehen.

## [2.4.2] - 2026-07-17 — Positionstrennung, Farbe bleibt aktuell, weniger Leerlauf

> **Deployment:** `php bin/console plugin:update RcColorPicker && php bin/console cache:clear`. Kein Schema-Break, keine neue Migration.

### Behoben

- **Geänderte Plugin-Konfiguration wirkte teils erst nach einem Neustart.** Das Plugin hielt einen
  eigenen Konfigurations-Zwischenspeicher, der nichts davon mitbekam, wenn die Einstellungen im
  Admin geändert wurden — im Worker-Betrieb konnten dadurch veraltete Werte weiterlaufen. Der
  Zwischenspeicher entfällt ersatzlos; Shopware speichert die Konfiguration ohnehin selbst zwischen
  und verwirft sie bei Änderungen korrekt.

- **Neuladen der Bestellbestätigung erzeugte unnötige Schreibzugriffe.** Die Farbwerte wurden bei
  jedem Aufruf von `/checkout/finish` erneut in die Bestellung geschrieben, auch wenn sie dort
  bereits identisch standen. Ein F5 auf der Bestellbestätigung löst jetzt keinen Leerlauf-Write mehr
  aus.

- **Die Farbe konnte in der Bestellbestätigungsmail unbemerkt fehlen.** Die Migration, die den
  Farb-Block in das Mail-Template einsetzt, greift nur bei unverändertem Shopware-Standard-Template.
  Bei einem angepassten Template wurde sie stillschweigend übersprungen — der Betreiber merkte es
  erst an einer Mail ohne Farbangabe. Der Übersprung wird jetzt als Warnung protokolliert und nennt
  das betroffene Template, sodass er von Hand nachgezogen werden kann.

- **Die Warenkorb-Positionstrennung konnte ausgehebelt werden.** RcColorPicker darf die Positions-ID
  nur selbst vergeben, wenn kein Plugin mit höherer Priorität am Kaufformular hängt. Die Prüfung
  erkannte ein solches Plugin nur, wenn dessen Kennzeichnung **innerhalb** des Formulars lag — lag sie
  am Formular selbst, blieb sie unbemerkt und RcColorPicker überschrieb die fremde Positions-ID.
  Praktische Folge: Positionen, die als getrennte Einträge im Warenkorb geführt werden sollten,
  konnten zusammenfallen. Beide Kennzeichnungs-Varianten werden jetzt geprüft.

  Gefunden bei der Verifikation eines gleichartigen Fehlers in RcDynamicPrice — dort war dieselbe
  Prüfung aus derselben Protokoll-Vorlage übernommen worden.

### Entfernt

- **Prüfung auf eine Kennzeichnung, die kein Plugin je gesetzt hat.** Sie konnte nie zutreffen und
  täuschte eine Absicherung vor, die es nicht gab. Eine zweite Prüfung war doppelt und entfällt
  ebenfalls — die verbleibende generische Kennzeichnung deckt denselben Fall ab.

## [2.4.1] - 2026-06-27 — Sicherheits- und Robustheits-Härtung

> **Deployment:** `php bin/console plugin:update RcColorPicker && php bin/console cache:clear`.

### Behoben

- **Schutz vor CSS-Injection bei Farbwerten:** Der Hex-Wert wird jetzt serverseitig streng validiert, bevor er in die Inline-Styles des Warenkorbs und der Bestellbestätigungsmail gelangt (`|e('html_attr')` escaped **kein** CSS innerhalb eines `style`-Attributs). Dafür der neue zentrale `ColorValidator`; ungültige Werte werden geleert. Greift auch für Headless-/Store-API-Clients, die das Storefront-JS umgehen.
- **Der Checkout kann nicht mehr blockieren:** `OrderColorSubscriber` wirft bei einem Datenbankfehler während des Schreibens der Farb-Custom-Fields (reine Anzeigedaten) keine Exception mehr, sondern loggt nur noch. Vorher konnte **nach** der Bestellung ein 500 auftreten.
- **Headless-tauglich:** Das Active-Flag akzeptiert jetzt auch `1`/`true`, nicht mehr nur den String `'1'`.
- **Längenbegrenzung** für RAL-/Namens-Freitext (maximal 64 Zeichen) gegen Custom-Field-Wildwuchs.
- **DRY:** Das Hex-Muster liegt zentral im `ColorValidator`.

## [2.4.0] - 2026-05-19

> **Deployment:** `php bin/console plugin:update RcColorPicker` (neue Migration patcht das Default-`order_confirmation_mail`-Template) + `php bin/console cache:clear`.

### Hinzugefügt
- **Bestellbestätigungsmail zeigt die Farbe pro LineItem** in HTML (mit Hex-Swatch) und Plaintext. Datenquelle primär `lineItem.payload.rcColorPicker*`, Fallback `lineItem.customFields.ruhrcoder_color_picker_*`. Funktioniert mit `RcCartSplitter`-Splits (Farbe je Child-LineItem) und `RcDynamicPrice` (Längen- und Farb-Block nebeneinander).
- Migration `Migration1779600000UpdateOrderConfirmationMailColorBlock`: forward-only, idempotent (Marker-Detection), schützt customized Templates (Anchor-Detection — kein Patch ohne exakten Default-Label-Anchor).
- README-Abschnitt „Bestellbestätigung manuell anpassen" mit kopierfähigem Twig-Snippet für Templates, die vom Shopware-Default abweichen.
- Snippet `rcColorPicker.cartLabel` wird in Mail-Templates wiederverwendet (kein neuer Snippet-Key nötig).

## [2.3.0] - 2026-05-11

> **Deployment:** `bin/build-storefront.sh` (Twig + JS geändert) + `php bin/console cache:clear`. Keine Datenbank-Migration.

### Hinzugefügt
- **Eindeutige Error-Codes** an `RcColorPickerException`: `CODE_CONTAINER_NOT_AVAILABLE = 1001`, `CODE_CUSTOM_FIELD_SET_REPOSITORY_MISSING = 1002`, `CODE_ORDER_LINE_ITEM_UPDATE_FAILED = 1003`. Logging/Monitoring kann Fehler ab sofort eindeutig klassifizieren — bisher trugen alle Plugin-Exceptions den generischen Code `0`. Pinning-Test `RcColorPickerExceptionTest` sichert die Konstanten-Werte als Vertrag.
- **Hex-Code-Validierung** in `ConfigService::getStandardColors()`. Eingaben wie `RAL 9010;Test;notahex` oder `RAL 7016;Foo;rgb(255,0,0)` werden ab sofort verworfen statt ein invalides `background-color: notahex;` ins DOM zu schreiben. Akzeptiert: `#rgb`, `#rrggbb`, `#rgba`, `#rrggbbaa` (Bootstrap-/CSS-kompatibel). Test-Provider mit 7 ungültigen und 7 gültigen Beispielen.
- **BFSG: `role="group"` + `aria-label` auf Swatch-Container** (`rc-color-picker.html.twig:48`). Screenreader bekommen ab sofort beim Eintritt in die Standard-Farben einen Gruppierungs-Hinweis („Verfügbare Standard-Farben" / „Available standard colors"). Neue Snippet-Keys `rcColorPicker.swatchGroupLabel` in beiden Locales.

### Behoben
- **Stabilitäts-Bug in `RcColorPickerPlugin.destroy()`**: Wenn `init()` über den Early-Return-Pfad zurückkehrt (kein Form-Eltern­element, keine Produkt-ID), war `this._abortController` noch nicht initialisiert. Späterer `destroy()`-Aufruf warf `TypeError: Cannot read property 'abort' of undefined`. Null-Check ergänzt; bestehende Happy-Path-Verhalten unverändert.

## [2.2.1] - 2026-04-30

> **Deployment:** keine Datenbank-Migration, kein Build-Schritt nötig — Test- und Tooling-Änderung.

### Hinzugefügt
- Vertrags-Test `tests/Js/rc-color-picker.suffix-event.test.mjs` verankert die statische Konstante `RcColorPickerPlugin.SUFFIX_CHANGED_EVENT` (= `rcSuffixChanged`). Ein Wert-Drift (Tippfehler, Refactor) bricht ab sofort den Test, statt stumm durchzulaufen.
- `composer test:js` und Aufnahme in `composer quality` ergänzt.

## [2.2.0] - 2026-04-30

> **Deployment:** `bin/build-storefront.sh` (JS geändert). Keine Datenbank-Migration, kein `plugin:update` nötig.

### Hinzugefügt
- **Generisches Suffix-Event** `rcSuffixChanged` wird zusätzlich zum bestehenden `rcColorPickerChanged` nach jeder Farb-Änderung gefeuert. Damit erfüllt RcColorPicker das aktualisierte Plugin-Interaktionsprotokoll: ID-berechnende Sibling-Plugins (RcCartSplitter ab v2.0.0) lauschen nur noch auf den neutralen Event-Namen, kein Plugin owned den Namespace. Event-Konstante als statische `RcColorPickerPlugin.SUFFIX_CHANGED_EVENT` exponiert.

### Geändert
- `_setPayload`/`_clearPayload` rufen den neuen privaten Helper `_dispatchSuffixChanged(detail)` auf, der beide Events an einer Stelle bündelt (DRY). Plugin-spezifisches `rcColorPickerChanged` bleibt für bestehende interne Listener verfügbar.

### Hinweis
- Standalone-Setups (RcColorPicker ohne RcCartSplitter) verhalten sich unverändert: das zusätzlich gefeuerte Event ist ohne Listener ein No-op.

## [2.1.1] - 2026-04-27

### Geändert (internes Refactoring — kein Verhaltenswechsel)
- `OrderColorSubscriber`: `onOrderPlaced` und `onCheckoutFinish` rufen einen privaten `processOrder`-Helper auf. Beseitigt 99 %-DRY-Duplikat.
- `RcColorPicker`: hartkodierte Versions-Schwelle `'2.0.0'` durch Konstante `MIGRATION_INTRODUCED_IN` mit erklärendem PHPDoc ersetzt.
- `ProductPageSubscriber`: redundantes `readonly` im Constructor entfernt (`final readonly class` impliziert es bereits).

## [2.1.0] - 2026-04-27

### Hinzugefügt
- Plugin-Konfiguration: neuer Schalter „Label für Custom-RAL sichtbar anzeigen" (`customRalLabelVisible`, Default `false`). Wenn aktiv, wird das Label des Eigener-RAL-Eingabefelds permanent eingeblendet statt nur für Screenreader (BFSG-Empfehlung, WCAG 3.3.2 — sehende Nutzer mit kognitiven Einschränkungen verlieren Kontext, wenn der Placeholder beim Tippen verschwindet).
- README: Hinweis zum CS-Fixer-Workaround unter Windows ohne `ext-intl`.

### Geändert
- Storefront-JS: Live-Region-Cooldown (50 ms) vor Schreiben von `__selected-name` und `__ral-name`. Verhindert das Stapeln von Screenreader-Ansagen bei schneller Swatch-Navigation oder Custom-RAL-Eingabe (WCAG 4.1.3).
- `ConfigService` parst maximal 80 Standard-Farben (`MAX_STANDARD_COLORS`) — schützt vor DOM-Bloat bei fehlerhafter Konfiguration. Helptext in der Plugin-Konfiguration nennt das Limit; Vendors mit umfangreichen Sets nutzen die Eigener-RAL-Eingabe.

## [2.0.0] - 2026-04-27

> **Breaking Change.** Custom-Field-Namen wurden auf das Vendor-Schema `ruhrcoder_color_picker_*` umgestellt. Die mitgelieferte Migration verschiebt bestehende Daten an Set, Feld-Definitionen und Werten in `product.custom_fields` sowie `order_line_item.custom_fields` automatisch beim `plugin:update`.

### Geändert
- Custom-Field-Set umbenannt: `rc_color_picker` → `ruhrcoder_color_picker`
- Custom-Field umbenannt: `rc_color_picker_enabled` → `ruhrcoder_color_picker_enabled`
- Order-LineItem-Custom-Fields umbenannt: `rc_color_picker_ral|name|hex` → `ruhrcoder_color_picker_ral|name|hex`
- Strukturierter Logging-Context der `OrderColorSubscriber`-Fehler: `ruhrcoder_color_picker.order_color_subscriber`
- `CustomFieldInstaller` ist beim erneuten Aufruf idempotent (sucht bestehendes Set per Namen und führt Upsert per ID).

### Hinzugefügt
- `Migration1777248000RenameCustomFields` — forward-only und idempotent. Verschiebt JSON-Werte in `custom_fields` per `JSON_SET` + `JSON_REMOVE` (Produkt-Custom-Fields liegen translation-seitig in `product_translation`, Order-LineItem-Custom-Fields in `order_line_item`).
- Integrationstest `Migration1777248000RenameCustomFieldsTest` deckt Set-/Feld-Rename, Idempotenz, JSON-Pfad und einen Smoketest gegen die echten Spaltennamen ab.
- Integrationstest `CustomFieldInstallerIntegrationTest::testInstallIstIdempotentBeimZweitenAufruf` schützt vor Doppelanlage von Set/Feld bei wiederholten Installer-Aufrufen.

### Behoben (im Rahmen des v2.0.0-Release-Tests gegen echte Shopdaten)
- `RcColorPicker::update()` überspringt den Installer beim Sprung von v1.x auf v2.0 — die Migration übernimmt das Set-/Feld-Rename. Ohne diesen Schutz lief der Installer vor der Migration und versuchte den Namen per DAL umzubenennen, was Shopware mit "name is immutable" blockiert.
- Migration verwendet `product_translation` (statt `product`) für den JSON-Rename des Produkt-Custom-Fields — entspricht der Shopware-Konvention, dass übersetzte Properties in der Translation-Tabelle liegen.
- `CustomFieldInstaller` holt beim Idempotenz-Lookup auch die ID des bestehenden `*_enabled`-Felds via `addAssociation('customFields')` und gibt sie ans nested Upsert weiter — verhindert Duplicate-Constraint-Verletzungen bei Re-Installs.

### Barrierefreiheit (BFSG-Optik)
- Sichtbarer Tastatur-Focus auf Standard-Swatches per `:focus-visible` (2 px Outline + 2 px Offset, Bootstrap-Primary). Maus-Klicks bleiben ringfrei (WCAG 2.4.7).
- Mode-Toggle (Standard / Eigene RAL) als `role="radiogroup"` mit `aria-label` ausgezeichnet — Screenreader kündigt die Gruppe semantisch an (WCAG 1.3.1).
- Touch-Ziele der Swatches von 36 × 36 auf 40 × 40 px vergrößert, Gap von 6 auf 8 px (WCAG 2.5.8).
- Snippet `modeLabel` (DE + EN) ergänzt.
- Live-Preview des Custom-RAL-Inputs gibt den Farbnamen jetzt locale-abhängig aus (DE/EN, Fallback DE). Alle 214 RAL-Classic-Farben tragen sowohl die deutsche als auch die offizielle englische RAL-Bezeichnung — Screenreader sprechen den Namen in der korrekten Engine aus (WCAG 3.1.1 / 3.1.2).

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
- Custom Field `rc_color_picker_enabled` zur Aktivierung pro Produkt/Variante (in v2.0.0 umbenannt zu `ruhrcoder_color_picker_enabled`)
- Storefront: Farbauswahl mit klickbaren Swatches und optionalem RAL-Freitext
- Warenkorb-Anzeige mit Farbquadrat und RAL-Code/Name
- OrderColorSubscriber: Farbdaten in Bestell-CustomFields (Prio -500, TMMS-sicher)
- Generisches Suffix-Protokoll (`rcColorSuffix`) für Plugin-Interaktion
- Zweisprachig: de-DE + en-GB komplett

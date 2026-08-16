# RcColorPicker – Farbauswahl für Shopware 6

Ermöglicht Kunden die Auswahl von Standard-Farben oder die Eingabe eigener RAL-Farbcodes auf der Produktseite.

## Features

- **Standard-Farben:** Klickbare Farbswatches mit Vorschau, konfigurierbar im Backend
- **Eigener RAL-Code:** Optionales Freitext-Feld für individuelle RAL-Eingabe; Live-Preview gibt den Farbnamen lokalisiert (DE/EN) aus
- **Pro Variante:** Aktivierung über Custom Field `ruhrcoder_color_picker_enabled`
- **Warenkorb:** Gewählte Farbe mit Farbquadrat in Cart, Checkout und Bestellbestätigung
- **Bestell-Mail:** Farbdaten werden in Order-CustomFields übertragen
- **Positions-Trennung:** Unterschiedliche Farben erzeugen separate Warenkorb-Positionen
- **Plugin-Kompatibilität:** Funktioniert standalone und mit RcDynamicPrice, RcCustomFields, RcCartSplitter

## Voraussetzungen

- Shopware 6.7 oder 6.8
- PHP 8.2+

## Installation

Das Plugin gehört als Ordner `custom/plugins/RcColorPicker` in die Shopware-Installation. Übertragen wird er von Hand — per FTP in dieses Verzeichnis kopieren; ein Composer-Paket gibt es nicht. Danach registriert Shopware ihn über die folgenden Befehle:

Als Archiv geht es auch ohne FTP: In der Administration unter **Erweiterungen → Meine Erweiterungen → Erweiterung hochladen** nimmt Shopware eine ZIP-Datei entgegen und legt sie selbst an die richtige Stelle; auf der Konsole tut `plugin:zip-import` dasselbe. Danach folgen dieselben Schritte wie unten. Wer das Archiv unter macOS packt, entfernt vorher den Ordner `__MACOSX` — sonst weist Shopware die Datei ab.

Das vorkompilierte Storefront-JS liegt dem Plugin bei (`Resources/app/storefront/dist`) — auf dem Server ist **kein Node-Build** nötig.

```bash
php bin/console plugin:refresh
php bin/console plugin:install --activate RcColorPicker
php bin/console theme:compile
php bin/console cache:clear
```

**Aktualisierungen laufen denselben Weg.** Den neuen Ordner per FTP über den alten legen, danach `plugin:refresh` und `plugin:update RcColorPicker`. Eine Aktualisierung von selbst gibt es nicht — ohne Composer-Paket und ohne Shopware-Store bleibt sie Sache des Betreibers. Vor einem Sprung über eine Hauptversion gehört ein Datenbank-Abzug dazu.

## Update

Plugin-Ordner überschreiben (inklusive `Resources/app/storefront/dist`), dann:

```bash
php bin/console plugin:refresh
php bin/console plugin:update RcColorPicker
php bin/console theme:compile
php bin/console cache:clear
```

## Konfiguration

### Backend (Einstellungen > System > Plugins > RcColorPicker)

| Einstellung | Beschreibung | Standard |
|-------------|-------------|----------|
| Standard-Farben | Eine Farbe pro Zeile: `RAL-Code;Name;Hex` | 8 Farben |
| Eigene RAL-Eingabe | Erlaubt Freitext-Eingabe neben Standard-Farben | Aktiv |
| Farbauswahl Pflicht | Artikel ohne Farbe blockieren den Bestellabschluss — im Browser mit einer Meldung am Formular, serverseitig über einen Warenkorb-Prüfer | Aktiv |

### Produkt (Custom Fields)

Am Produkt oder an der Variante das Feld **"Farbauswahl aktivieren"** (`ruhrcoder_color_picker_enabled`) auf `true` setzen.

## Plugin-Interaktion

RcColorPicker nutzt das generische Suffix-Protokoll (`form.dataset.rcColorSuffix`). Andere Ruhrcoder-Plugins beziehen diesen Suffix automatisch in ihre LineItem-ID-Berechnung ein.

| Kombination | Verhalten |
|-------------|-----------|
| Standalone | Eigene LineItem-ID bei unterschiedlicher Farbe |
| + RcDynamicPrice | Farbe + Länge → separate Positionen |
| + RcCustomFields | Farbe im Hash berücksichtigt |
| + RcCartSplitter | Farbe im TMMS-Hash berücksichtigt |

## Bestellbestätigung manuell anpassen

Die Migration `Migration1779600000UpdateOrderConfirmationMailColorBlock` patcht beim `plugin:update` das Default-`order_confirmation_mail`-Template (HTML + Plaintext) automatisch. Bedingungen:

- Der Inhalt enthält den exakten Shopware-Default-Label-Anchor (`{{ nestedItem.label|u.wordwrap(80) }}` bzw. `{{ lineItem.label|u.wordwrap(80) }}`).
- Der Marker `{# RcColorPicker:mail-color-block-v1 #}` ist noch nicht vorhanden.

Wurde das Mail-Template im Admin **inhaltlich angepasst**, lässt die Migration es unberührt. In diesem Fall den folgenden Block direkt nach der Label-Ausgabe im jeweiligen Template einfügen.

**HTML** (innerhalb der LineItem-Schleife, direkt nach `<div>{{ nestedItem.label|u.wordwrap(80) }}</div>`):

```twig
{# RcColorPicker:mail-color-block-v1 #}
{% set cpRal = nestedItem.payload.rcColorPickerRal|default(nestedItem.customFields.ruhrcoder_color_picker_ral|default('')) %}
{% set cpName = nestedItem.payload.rcColorPickerName|default(nestedItem.customFields.ruhrcoder_color_picker_name|default('')) %}
{% set cpHex = nestedItem.payload.rcColorPickerHex|default(nestedItem.customFields.ruhrcoder_color_picker_hex|default('')) %}
{% if cpRal %}
    <div style="font-size:11px;color:#666;margin-top:4px;">
        {{ 'rcColorPicker.cartLabel'|trans }}:
        {% if cpHex %}<span style="display:inline-block;width:10px;height:10px;background:{{ cpHex }};border:1px solid #ccc;vertical-align:middle;margin-right:4px;"></span>{% endif %}
        {{ cpRal }}{% if cpName %} – {{ cpName }}{% endif %}
    </div>
{% endif %}
```

**Plaintext** (direkt nach `Beschreibung {{ lineItem.label|u.wordwrap(80) }},`):

```twig
{# RcColorPicker:mail-color-block-v1 #}
{% set cpRal = lineItem.payload.rcColorPickerRal|default(lineItem.customFields.ruhrcoder_color_picker_ral|default('')) %}
{% set cpName = lineItem.payload.rcColorPickerName|default(lineItem.customFields.ruhrcoder_color_picker_name|default('')) %}
{% if cpRal %}
{{ 'rcColorPicker.cartLabel'|trans }}: {{ cpRal }}{% if cpName %} – {{ cpName }}{% endif %},
{% endif %}
```

## Observability

Der `OrderColorSubscriber` fängt Fehler beim DAL-Schreiben in einen strukturierten Error-Log (`context: ruhrcoder_color_picker.order_color_subscriber`) mit Order-ID, betroffener LineItem-Anzahl und Exception-Klasse. Danach wird eine `RcColorPickerException` geworfen, damit Shopware den Bestellprozess nicht still mit inkonsistenten Custom-Fields fortsetzt. Logs finden sich im konfigurierten PSR-3-Handler (default: `var/log/*.log`).

## Tests

Zwei Suites, getrennt ausführbar:

| Suite | Befehl | Voraussetzung |
|-------|--------|---------------|
| Unit | `composer test:unit` | keine — läuft ohne Datenbank |
| Integration | `composer test:integration` | MySQL/MariaDB + `DATABASE_URL`-Env |

Die Integration-Suite nutzt Shopwares `IntegrationTestBehaviour` mit Test-Kernel und DB-Rollback je Test. Der TestBootstrapper benötigt eine komplette Shopware-Installation (`addCallingPlugin()` löst nur dort auf) — Integration-Tests laufen daher auf einer Shopware-Test-Instanz, nicht im Plugin-Solo-Repo. In GitHub Actions läuft nur der `quality`-Job (Audit, CS-Fixer, PHPStan, Unit-Tests).

## Entwickler-Hinweise

- **CS-Fixer lokal:** PHP CS Fixer benötigt `ext-intl`. Auf Windows-PHP-Builds ohne intl-Extension lokal nicht ausführbar — stattdessen auf einem Linux-Host mit intl oder im CI laufen lassen.
- **PHP-Versions-Range:** Plugin testet gegen 8.2 / 8.3 / 8.4 (Shopware-6.7-/-6.8-Matrix). PHP 8.5 funktioniert für Runtime, CS-Fixer ist dort noch nicht offiziell freigegeben.

## Deployment

| Änderung | Befehl |
|----------|--------|
| Nur PHP/Twig | `bin/console cache:clear` |
| JS oder SCSS | `bin/build-storefront.sh` |
| Erstinstallation | `bin/build-storefront.sh` erforderlich |

## Upgrade von v1.x auf v2.x

In v2.0.0 wurden die Custom-Field-Namen auf das Vendor-Schema umgestellt:

| v1.x | v2.x |
|------|------|
| `rc_color_picker` (Set) | `ruhrcoder_color_picker` |
| `rc_color_picker_enabled` | `ruhrcoder_color_picker_enabled` |
| `rc_color_picker_ral|name|hex` (Order-LineItem) | `ruhrcoder_color_picker_ral|name|hex` |

Die Migration `Migration1777248000RenameCustomFields` läuft beim Plugin-Update automatisch und verschiebt sowohl die Definitionen als auch die JSON-Werte in `product.custom_fields` und `order_line_item.custom_fields`. Sie ist idempotent (zweiter Lauf ändert nichts) und forward-only — ein Downgrade auf v1.x wird nicht unterstützt.

```bash
# Vor dem Update Backup erstellen
bin/console plugin:update RcColorPicker
bin/console cache:clear
```

## Lizenz

MIT

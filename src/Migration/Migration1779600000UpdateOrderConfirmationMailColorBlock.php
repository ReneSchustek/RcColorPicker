<?php

declare(strict_types=1);

namespace Ruhrcoder\RcColorPicker\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Erweitert die Mail-Templates vom Typ order_confirmation_mail um einen
 * Farb-Block pro LineItem (HTML- und Plaintext-Variante). Datenquelle ist
 * primär der LineItem-Payload (rcColorPicker*), Fallback auf die Order-
 * LineItem-CustomFields (ruhrcoder_color_picker_*).
 *
 * Zwei Schutzschichten gegen unbeabsichtigtes Ueberschreiben:
 * - Marker-Detection: enthält der Inhalt bereits den Marker, wird nichts verändert (idempotent).
 * - Anchor-Detection: ohne den exakten Default-Anchor (Shopware-Default-Label-Ausgabe) wird nicht
 *   gepatcht. Damit bleiben shop-spezifische Anpassungen unangetastet — die manuelle Erweiterung
 *   ist in der README dokumentiert.
 *
 * Der Anchor-Skip wird nach `log_entry` protokolliert (WARNING). Sonst fände der Betreiber erst
 * an der ersten Bestellbestätigung ohne Farbe heraus, dass sein angepasstes Template Handarbeit
 * braucht.
 */
final class Migration1779600000UpdateOrderConfirmationMailColorBlock extends MigrationStep
{
    public const MARKER = '{# RcColorPicker:mail-color-block-v1 #}';

    private const TYPE_TECHNICAL_NAME = 'order_confirmation_mail';
    private const HTML_ANCHOR = '{{ nestedItem.label|u.wordwrap(80) }}';
    private const PLAIN_ANCHOR = '{{ lineItem.label|u.wordwrap(80) }}';
    private const HTML_CLOSE_DIV = '</div>';

    private const LOG_CHANNEL = 'rc_color_picker';
    // Monolog-Level WARNING — der Skip ist kein Fehler, verlangt aber Handarbeit.
    private const LOG_LEVEL_WARNING = 300;

    public function getCreationTimestamp(): int
    {
        return 1779600000;
    }

    public function update(Connection $connection): void
    {
        $rows = $connection->fetchAllAssociative(
            'SELECT mtt.mail_template_id, mtt.language_id, mtt.content_html, mtt.content_plain
             FROM mail_template_translation mtt
             INNER JOIN mail_template mt ON mt.id = mtt.mail_template_id
             INNER JOIN mail_template_type mtype ON mtype.id = mt.mail_template_type_id
             WHERE mtype.technical_name = :type',
            ['type' => self::TYPE_TECHNICAL_NAME],
        );

        $skipped = [];

        foreach ($rows as $row) {
            $update = [];

            $htmlPatched = $this->patchHtml($row['content_html'] ?? null);
            if ($htmlPatched !== null) {
                $update['content_html'] = $htmlPatched;
            } elseif ($this->needsManualPatch($row['content_html'] ?? null)) {
                $skipped[] = $this->describeSkip($row, 'content_html');
            }

            $plainPatched = $this->patchPlain($row['content_plain'] ?? null);
            if ($plainPatched !== null) {
                $update['content_plain'] = $plainPatched;
            } elseif ($this->needsManualPatch($row['content_plain'] ?? null)) {
                $skipped[] = $this->describeSkip($row, 'content_plain');
            }

            if ($update === []) {
                continue;
            }

            $connection->update(
                'mail_template_translation',
                $update,
                [
                    'mail_template_id' => $row['mail_template_id'],
                    'language_id' => $row['language_id'],
                ],
            );
        }

        if ($skipped !== []) {
            $this->logSkippedTemplates($connection, $skipped);
        }
    }

    public function updateDestructive(Connection $connection): void
    {
        // Forward-only, keine destruktive Phase.
    }

    /**
     * Patcht den HTML-Inhalt: fügt den Farb-Block direkt nach dem
     * `</div>`-Tag ein, das auf den Label-Anchor folgt. Skip-Bedingungen:
     * - Inhalt leer
     * - Marker bereits vorhanden (idempotent)
     * - Anchor nicht gefunden (Template wurde vom Shop angepasst)
     *
     * Nur für interne Verwendung und Tests public.
     */
    public function patchHtml(?string $content): ?string
    {
        if ($content === null || $content === '') {
            return null;
        }
        if (str_contains($content, self::MARKER)) {
            return null;
        }

        $anchorPos = strpos($content, self::HTML_ANCHOR);
        if ($anchorPos === false) {
            return null;
        }

        $closeDivPos = strpos($content, self::HTML_CLOSE_DIV, $anchorPos);
        if ($closeDivPos === false) {
            return null;
        }

        $insertPos = $closeDivPos + \strlen(self::HTML_CLOSE_DIV);

        return substr($content, 0, $insertPos)
            . "\n" . $this->htmlColorBlock()
            . substr($content, $insertPos);
    }

    /**
     * Patcht den Plaintext-Inhalt: fügt den Farb-Block direkt nach der
     * Zeile mit dem Label-Anchor ein. Skip-Bedingungen analog HTML.
     *
     * Nur für interne Verwendung und Tests public.
     */
    public function patchPlain(?string $content): ?string
    {
        if ($content === null || $content === '') {
            return null;
        }
        if (str_contains($content, self::MARKER)) {
            return null;
        }

        $anchorPos = strpos($content, self::PLAIN_ANCHOR);
        if ($anchorPos === false) {
            return null;
        }

        $eolPos = strpos($content, "\n", $anchorPos);
        if ($eolPos === false) {
            return null;
        }

        return substr($content, 0, $eolPos + 1)
            . $this->plainColorBlock()
            . substr($content, $eolPos + 1);
    }

    /**
     * Ein Template braucht Handarbeit, wenn Inhalt da ist, der Marker fehlt (also noch nicht
     * gepatcht wurde) und der Patch trotzdem nicht griff. Uebrig bleibt dann nur eine Ursache:
     * Das Template weicht vom Shopware-Default ab, der Anchor fehlt.
     */
    private function needsManualPatch(?string $content): bool
    {
        return $content !== null && $content !== '' && !str_contains($content, self::MARKER);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{mailTemplateId: string, languageId: string, variant: string}
     */
    private function describeSkip(array $row, string $variant): array
    {
        return [
            'mailTemplateId' => bin2hex((string) $row['mail_template_id']),
            'languageId' => bin2hex((string) $row['language_id']),
            'variant' => $variant,
        ];
    }

    /**
     * Schreibt den Skip in `log_entry`. Ein MigrationStep bekommt per Design keinen Logger
     * injiziert — nur die Connection. Der direkte Insert ist derselbe Weg, den auch der
     * Monolog-Handler des Cores nimmt, und macht den Fall im Admin-Log sichtbar.
     *
     * Ohne diesen Eintrag verläuft der Skip lautlos: Der Betreiber hält das Feature für
     * installiert, während die Bestellbestätigung die Farbe nie zeigt.
     *
     * @param list<array{mailTemplateId: string, languageId: string, variant: string}> $skipped
     */
    private function logSkippedTemplates(Connection $connection, array $skipped): void
    {
        $connection->insert('log_entry', [
            'id' => Uuid::randomBytes(),
            'message' => \sprintf(
                'RcColorPicker: %d angepasste Mail-Template-Variante(n) ohne Default-Anchor übersprungen — '
                . 'der Farb-Block fehlt dort und muss laut README von Hand eingefügt werden.',
                \count($skipped),
            ),
            'level' => self::LOG_LEVEL_WARNING,
            'channel' => self::LOG_CHANNEL,
            'context' => json_encode(['skipped' => $skipped], \JSON_THROW_ON_ERROR),
            'extra' => '[]',
            // UTC wie alle Shopware-Storage-Zeitstempel — sonst hängt die Log-Zeit an der Server-TZ.
            'created_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);
    }

    private function htmlColorBlock(): string
    {
        return <<<TWIG
                        {$this->markerLine()}
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

TWIG;
    }

    private function plainColorBlock(): string
    {
        return <<<TWIG
{$this->markerLine()}
{% set cpRal = lineItem.payload.rcColorPickerRal|default(lineItem.customFields.ruhrcoder_color_picker_ral|default('')) %}
{% set cpName = lineItem.payload.rcColorPickerName|default(lineItem.customFields.ruhrcoder_color_picker_name|default('')) %}
{% if cpRal %}
{{ 'rcColorPicker.cartLabel'|trans }}: {{ cpRal }}{% if cpName %} – {{ cpName }}{% endif %},
{% endif %}

TWIG;
    }

    private function markerLine(): string
    {
        return self::MARKER;
    }
}

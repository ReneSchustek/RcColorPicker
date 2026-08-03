<?php

declare(strict_types=1);

namespace Ruhrcoder\RcColorPicker\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Ersetzt den Farb-Block v1 in der Bestellbestätigung durch v2 — und setzt `updated_at`.
 *
 * Warum eine zweite Migration und nicht eine Änderung an der ersten
 * ----------------------------------------------------------------
 * `Migration1779600000` ist auf allen bestehenden Installationen längst gelaufen und gilt als
 * erledigt. Eine Änderung an ihrem Inhalt erreicht nur Neuinstallationen. Für alles, was schon
 * steht, braucht es einen zweiten Lauf.
 *
 * Zwei Dinge werden korrigiert
 * ----------------------------
 * **1. `updated_at` war nicht gesetzt.** Genau daran erkennt Shopware „von diesem Shop
 * angepasst"; der Kern überschreibt Mail-Vorlagen in seinen eigenen Migrationen ausschließlich
 * mit `WHERE updated_at IS NULL`. Solange das Feld leer blieb, galt unsere Vorlage als
 * unangetastet — die nächste Shopware-Migration für die Bestellbestätigung hätte sie ersetzt und
 * der Farb-Block wäre verschwunden. Ohne Eintrag, ohne Fehler, und der Marker-Schutz von v1
 * greift dann nicht mehr, weil sie als ausgeführt gilt.
 *
 * **2. Eine Farbe ohne RAL-Code fiel aus der Mail heraus.** `ConfigService::getStandardColors()`
 * lässt einen leeren RAL-Teil zu (`;Reinweiß;#FFFFFF`), die Mail-Blöcke prüften aber auf
 * `{% if cpRal %}`. Die Farbe stand im Warenkorb und fehlte in der Bestätigung — lautlos, und
 * nur bei dieser Konfiguration.
 *
 * Warum exakter Textvergleich
 * ---------------------------
 * Ersetzt wird nur, wenn der v1-Block **wortgleich** so dasteht, wie ihn v1 geschrieben hat. Wer
 * die Vorlage von Hand angepasst hat, hat das mit Absicht getan; eine Migration, die darüber
 * hinweggeht, zerstört Arbeit. Solche Fälle werden übersprungen und protokolliert.
 */
class Migration1785715200RepatchOrderConfirmationMailColorBlock extends MigrationStep
{
    public const MARKER_V2 = '{# RcColorPicker:mail-color-block-v2 #}';

    private const MARKER_V1 = '{# RcColorPicker:mail-color-block-v1 #}';
    private const TYPE_TECHNICAL_NAME = 'order_confirmation_mail';

    private const LOG_CHANNEL = 'rc_color_picker';
    private const LOG_LEVEL_WARNING = 300;

    public function getCreationTimestamp(): int
    {
        return 1785715200;
    }

    public function update(Connection $connection): void
    {
        $rows = $connection->fetchAllAssociative(
            'SELECT mtt.mail_template_id, mtt.language_id, mtt.content_html, mtt.content_plain
             FROM mail_template_translation mtt
             INNER JOIN mail_template mt ON mt.id = mtt.mail_template_id
             INNER JOIN mail_template_type mtty ON mtty.id = mt.mail_template_type_id
             WHERE mtty.technical_name = :type',
            ['type' => self::TYPE_TECHNICAL_NAME],
        );

        $skipped = [];
        $stamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->format(Defaults::STORAGE_DATE_TIME_FORMAT);

        foreach ($rows as $row) {
            $update = [];

            $html = (string) ($row['content_html'] ?? '');
            $plain = (string) ($row['content_plain'] ?? '');

            if (str_contains($html, self::MARKER_V1)) {
                $patched = str_replace($this->htmlBlockV1(), $this->htmlBlockV2(), $html);
                if ($patched !== $html) {
                    $update['content_html'] = $patched;
                } else {
                    $skipped[] = $this->describeSkip($row, 'content_html');
                }
            }

            if (str_contains($plain, self::MARKER_V1)) {
                $patched = str_replace($this->plainBlockV1(), $this->plainBlockV2(), $plain);
                if ($patched !== $plain) {
                    $update['content_plain'] = $patched;
                } else {
                    $skipped[] = $this->describeSkip($row, 'content_plain');
                }
            }

            // `updated_at` wird auch dann gesetzt, wenn am Inhalt nichts zu tun war: Die Vorlage
            // trägt unseren Block, also ist sie angepasst — und muss vor dem Kern geschützt sein.
            if ($update === [] && !str_contains($html, self::MARKER_V1) && !str_contains($plain, self::MARKER_V1)) {
                continue;
            }

            $update['updated_at'] = $stamp;

            $connection->update(
                'mail_template_translation',
                $update,
                [
                    'mail_template_id' => $row['mail_template_id'],
                    'language_id' => $row['language_id'],
                ],
            );

            $connection->update(
                'mail_template',
                ['updated_at' => $stamp],
                ['id' => $row['mail_template_id']],
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

    /** Der Block, den v1 geschrieben hat — wortgleich, sonst greift die Ersetzung nicht. */
    private function htmlBlockV1(): string
    {
        return <<<TWIG
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
TWIG;
    }

    /**
     * Neu: Es genügt, wenn **eine** der beiden Angaben da ist.
     *
     * Der Trenner steht nur zwischen zwei vorhandenen Werten — sonst begänne die Zeile bei einer
     * Farbe ohne RAL-Code mit einem Gedankenstrich.
     */
    private function htmlBlockV2(): string
    {
        return <<<TWIG
                        {# RcColorPicker:mail-color-block-v2 #}
                        {% set cpRal = nestedItem.payload.rcColorPickerRal|default(nestedItem.customFields.ruhrcoder_color_picker_ral|default('')) %}
                        {% set cpName = nestedItem.payload.rcColorPickerName|default(nestedItem.customFields.ruhrcoder_color_picker_name|default('')) %}
                        {% set cpHex = nestedItem.payload.rcColorPickerHex|default(nestedItem.customFields.ruhrcoder_color_picker_hex|default('')) %}
                        {% if cpRal or cpName %}
                            <div style="font-size:11px;color:#666;margin-top:4px;">
                                {{ 'rcColorPicker.cartLabel'|trans }}:
                                {% if cpHex %}<span style="display:inline-block;width:10px;height:10px;background:{{ cpHex }};border:1px solid #ccc;vertical-align:middle;margin-right:4px;"></span>{% endif %}
                                {{ cpRal }}{% if cpRal and cpName %} – {% endif %}{{ cpName }}
                            </div>
                        {% endif %}
TWIG;
    }

    private function plainBlockV1(): string
    {
        return <<<TWIG
{# RcColorPicker:mail-color-block-v1 #}
{% set cpRal = lineItem.payload.rcColorPickerRal|default(lineItem.customFields.ruhrcoder_color_picker_ral|default('')) %}
{% set cpName = lineItem.payload.rcColorPickerName|default(lineItem.customFields.ruhrcoder_color_picker_name|default('')) %}
{% if cpRal %}
{{ 'rcColorPicker.cartLabel'|trans }}: {{ cpRal }}{% if cpName %} – {{ cpName }}{% endif %},
{% endif %}
TWIG;
    }

    private function plainBlockV2(): string
    {
        return <<<TWIG
{# RcColorPicker:mail-color-block-v2 #}
{% set cpRal = lineItem.payload.rcColorPickerRal|default(lineItem.customFields.ruhrcoder_color_picker_ral|default('')) %}
{% set cpName = lineItem.payload.rcColorPickerName|default(lineItem.customFields.ruhrcoder_color_picker_name|default('')) %}
{% if cpRal or cpName %}
{{ 'rcColorPicker.cartLabel'|trans }}: {{ cpRal }}{% if cpRal and cpName %} – {% endif %}{{ cpName }},
{% endif %}
TWIG;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function describeSkip(array $row, string $field): string
    {
        return sprintf(
            'mail_template_id=%s language_id=%s field=%s',
            Uuid::fromBytesToHex((string) $row['mail_template_id']),
            Uuid::fromBytesToHex((string) $row['language_id']),
            $field,
        );
    }

    /**
     * @param array<int, string> $skipped
     */
    private function logSkippedTemplates(Connection $connection, array $skipped): void
    {
        $connection->insert('log_entry', [
            'id' => Uuid::randomBytes(),
            'message' => 'RcColorPicker: Farb-Block v1 wurde von Hand angepasst und deshalb nicht auf v2 gehoben.',
            'level' => self::LOG_LEVEL_WARNING,
            'channel' => self::LOG_CHANNEL,
            'context' => json_encode(['templates' => $skipped], \JSON_THROW_ON_ERROR),
            'extra' => '[]',
            'created_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);
    }
}

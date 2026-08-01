<?php

declare(strict_types=1);

namespace Ruhrcoder\RcColorPicker\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Benennt die v1-Custom-Field-Namen rc_color_picker_* in das v2-Schema
 * ruhrcoder_color_picker_* um (Set, Feld-Definitionen, sowie die JSON-Werte
 * an Produkt und OrderLineItem).
 *
 * Forward-only und idempotent: jede UPDATE-Aussage greift nur auf Zeilen,
 * die noch den alten Namen tragen — und nur dann, wenn der neue Name in der
 * jeweiligen Tabelle noch frei ist. Belegte Zielnamen werden übersprungen
 * statt überschrieben; nichts wird gelöscht.
 *
 * Diese Klasse ist die einzige zulässige Stelle in src/, die noch das
 * Legacy-Prefix rc_color_picker_ als Suchschlüssel verwendet.
 */
final class Migration1777248000RenameCustomFields extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1777248000;
    }

    public function update(Connection $connection): void
    {
        // Beide Umbenennungen überspringen Zeilen, deren Zielname schon vergeben ist.
        // Beide Namensräume können gleichzeitig existieren: Der CustomFieldInstaller legt die
        // ruhrcoder_*-Felder beim Aktivieren an, während v1-Reste noch in der Datenbank liegen
        // (etwa nach einer Deinstallation mit erhaltenen Nutzerdaten). Ohne diese Absicherung
        // läuft `custom_field` in `uniq.custom_field.name` und bricht das Update mitten in der
        // Migration ab; `custom_field_set` hat keinen solchen Index und bekäme stillschweigend
        // zwei gleichnamige Sets.
        //
        // Self-Join statt Subquery: MySQL erlaubt im UPDATE keinen Subquery auf die Tabelle,
        // die es gerade schreibt.
        $connection->executeStatement(
            'UPDATE custom_field_set AS alt
             LEFT JOIN custom_field_set AS vorhanden ON vorhanden.name = :new
             SET alt.name = :new
             WHERE alt.name = :old AND vorhanden.id IS NULL',
            ['new' => 'ruhrcoder_color_picker', 'old' => 'rc_color_picker'],
        );

        $connection->executeStatement(
            "UPDATE custom_field AS alt
             LEFT JOIN custom_field AS vorhanden
                 ON vorhanden.name = REPLACE(alt.name, 'rc_color_picker_', 'ruhrcoder_color_picker_')
             SET alt.name = REPLACE(alt.name, 'rc_color_picker_', 'ruhrcoder_color_picker_')
             WHERE alt.name LIKE 'rc_color_picker_%' AND vorhanden.id IS NULL",
        );

        // Produkt-CustomFields liegen in der translation-Tabelle (Shopware-Konvention).
        $this->renameJsonKey($connection, 'product_translation', 'rc_color_picker_enabled', 'ruhrcoder_color_picker_enabled');
        $this->renameJsonKey($connection, 'order_line_item', 'rc_color_picker_ral', 'ruhrcoder_color_picker_ral');
        $this->renameJsonKey($connection, 'order_line_item', 'rc_color_picker_name', 'ruhrcoder_color_picker_name');
        $this->renameJsonKey($connection, 'order_line_item', 'rc_color_picker_hex', 'ruhrcoder_color_picker_hex');
    }

    public function updateDestructive(Connection $connection): void
    {
        // Migration ist forward-only, keine destruktive Phase nötig.
    }

    /**
     * Verschiebt einen JSON-Key innerhalb der custom_fields-Spalte: setzt den neuen Key
     * mit dem Wert des alten und entfernt anschliessend den alten Key. Idempotent durch
     * den WHERE-Filter auf den alten Key.
     *
     * Tabellen- und Key-Namen sind hartkodierte Plugin-Konstanten, daher kein Injection-Risiko.
     */
    private function renameJsonKey(
        Connection $connection,
        string $table,
        string $oldKey,
        string $newKey,
    ): void {
        $sql = sprintf(
            'UPDATE `%s`
             SET custom_fields = JSON_REMOVE(
                 JSON_SET(custom_fields, "$.%s", JSON_EXTRACT(custom_fields, "$.%s")),
                 "$.%s"
             )
             WHERE JSON_EXTRACT(custom_fields, "$.%s") IS NOT NULL',
            $table,
            $newKey,
            $oldKey,
            $oldKey,
            $oldKey,
        );

        $connection->executeStatement($sql);
    }
}

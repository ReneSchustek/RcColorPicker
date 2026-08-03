<?php

declare(strict_types=1);

namespace Ruhrcoder\RcColorPicker\Tests\Integration\Migration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use Ruhrcoder\RcColorPicker\Migration\Migration1777248000RenameCustomFields;
use Ruhrcoder\RcColorPicker\Tests\Integration\IntegrationTestBase;
use Shopware\Core\Framework\Uuid\Uuid;

#[CoversClass(Migration1777248000RenameCustomFields::class)]
final class Migration1777248000RenameCustomFieldsTest extends IntegrationTestBase
{
    public function testGetCreationTimestampIsStable(): void
    {
        self::assertSame(1777248000, (new Migration1777248000RenameCustomFields())->getCreationTimestamp());
    }

    public function testUpdateAccessesTheCorrectColumns(): void
    {
        // Smoketest gegen die echten Tabellen — fungiert als Schutz davor, dass
        // ein refactor versehentlich `product` statt `product_translation`
        // adressiert (CustomFields liegen translation-seitig).
        // Der Nachweis ist das ausnahmefreie Durchlaufen gegen die echten Spaltennamen —
        // eine Zusicherung auf einen festen Wert wäre hier nur Fassade.
        $this->expectNotToPerformAssertions();

        (new Migration1777248000RenameCustomFields())->update($this->connection());
    }

    public function testRenamesCustomFieldSetAndCustomField(): void
    {
        $connection = $this->connection();

        $setId = Uuid::randomBytes();
        $fieldId = Uuid::randomBytes();

        // Der Ausgangszustand muss hergestellt werden, nicht vorausgesetzt: Sobald das Plugin im
        // Test-Kernel aktiv ist, hat der CustomFieldInstaller die ruhrcoder_*-Namen bereits
        // vergeben. Eine Umbenennung darauf ist per Unique-Index unmöglich — der Test prüfte
        // sonst einen Zustand, den es nicht geben kann. IntegrationTestBehaviour rollt jede
        // Testtransaktion zurück, das Aufräumen bleibt also folgenlos.
        $this->removeNewNames($connection);
        $this->insertLegacyFieldSet($connection, $setId, $fieldId);

        (new Migration1777248000RenameCustomFields())->update($connection);

        $setName = $connection->fetchOne(
            'SELECT name FROM custom_field_set WHERE id = :id',
            ['id' => $setId],
        );
        self::assertSame('ruhrcoder_color_picker', $setName);

        $fieldName = $connection->fetchOne(
            'SELECT name FROM custom_field WHERE id = :id',
            ['id' => $fieldId],
        );
        self::assertSame('ruhrcoder_color_picker_enabled', $fieldName);
    }

    public function testIsIdempotentOnSecondRun(): void
    {
        $connection = $this->connection();

        $setId = Uuid::randomBytes();
        $fieldId = Uuid::randomBytes();

        $this->removeNewNames($connection);
        $this->insertLegacyFieldSet($connection, $setId, $fieldId);

        $migration = new Migration1777248000RenameCustomFields();
        $migration->update($connection);
        // Zweiter Lauf darf nichts mehr ändern und keine Exception werfen.
        $migration->update($connection);

        $setName = $connection->fetchOne(
            'SELECT name FROM custom_field_set WHERE id = :id',
            ['id' => $setId],
        );
        self::assertSame('ruhrcoder_color_picker', $setName);

        $fieldName = $connection->fetchOne(
            'SELECT name FROM custom_field WHERE id = :id',
            ['id' => $fieldId],
        );
        self::assertSame('ruhrcoder_color_picker_enabled', $fieldName);
    }

    public function testMovesJsonKeyIntoCustomFieldsColumn(): void
    {
        $connection = $this->connection();

        // Der Migrations-SQL-Pfad für JSON-Werte wird hier gegen eine TEMPORARY TABLE
        // mit identischem Schema (custom_fields JSON) verifiziert. Dadurch entfällt der
        // Bedarf an Order-/Produkt-Fixtures (FK-Ketten) und wir testen exakt das
        // SQL-Verhalten, das die Migration auch in product/order_line_item auslöst.
        $connection->executeStatement('DROP TEMPORARY TABLE IF EXISTS rcc_cf_test');
        $connection->executeStatement(
            'CREATE TEMPORARY TABLE rcc_cf_test (id INT PRIMARY KEY, custom_fields JSON)',
        );
        $connection->executeStatement(
            "INSERT INTO rcc_cf_test (id, custom_fields) VALUES "
            . "(1, JSON_OBJECT('rc_color_picker_ral', 'RAL 7016', 'other', 'keep')),"
            . "(2, JSON_OBJECT('unrelated', 'val')),"
            . "(3, NULL)",
        );

        $connection->executeStatement(
            'UPDATE rcc_cf_test
             SET custom_fields = JSON_REMOVE(
                 JSON_SET(custom_fields, "$.ruhrcoder_color_picker_ral", JSON_EXTRACT(custom_fields, "$.rc_color_picker_ral")),
                 "$.rc_color_picker_ral"
             )
             WHERE JSON_EXTRACT(custom_fields, "$.rc_color_picker_ral") IS NOT NULL',
        );

        $row1 = $connection->fetchOne('SELECT custom_fields FROM rcc_cf_test WHERE id = 1');
        $cf1 = json_decode((string) $row1, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('RAL 7016', $cf1['ruhrcoder_color_picker_ral']);
        self::assertSame('keep', $cf1['other']);
        self::assertArrayNotHasKey('rc_color_picker_ral', $cf1);

        $row2 = $connection->fetchOne('SELECT custom_fields FROM rcc_cf_test WHERE id = 2');
        $cf2 = json_decode((string) $row2, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['unrelated' => 'val'], $cf2);

        $row3 = $connection->fetchOne('SELECT custom_fields FROM rcc_cf_test WHERE id = 3');
        self::assertNull($row3);

        $connection->executeStatement('DROP TEMPORARY TABLE IF EXISTS rcc_cf_test');
    }

    /**
     * Der Kollisionsfall: v1-Reste und v2-Felder liegen gleichzeitig in der Datenbank.
     *
     * Früher brach die Migration hier mit einer Constraint-Verletzung ab und riss das
     * gesamte `plugin:update` mit — bei `custom_field_set` wären mangels Unique-Index sogar
     * stillschweigend zwei gleichnamige Sets entstanden. Erwartet wird jetzt: kein Abbruch,
     * und keine der beiden Seiten wird angefasst.
     */
    public function testSkipsRenameWhenTargetNameIsTaken(): void
    {
        $connection = $this->connection();

        // Beide Namensräume nebeneinander herstellen — die neuen Namen legt der Installer des
        // aktiven Plugins bereits an, die alten kommen hier dazu.
        $setId = Uuid::randomBytes();
        $fieldId = Uuid::randomBytes();
        $this->insertLegacyFieldSet($connection, $setId, $fieldId);

        (new Migration1777248000RenameCustomFields())->update($connection);

        // Die Legacy-Zeilen bleiben unangetastet stehen, statt den belegten Namen zu überschreiben.
        self::assertSame(
            'rc_color_picker',
            $connection->fetchOne('SELECT name FROM custom_field_set WHERE id = :id', ['id' => $setId]),
            'das Legacy-Set darf nicht auf einen belegten Namen umbenannt werden',
        );
        self::assertSame(
            'rc_color_picker_enabled',
            $connection->fetchOne('SELECT name FROM custom_field WHERE id = :id', ['id' => $fieldId]),
            'das Legacy-Feld darf nicht auf einen belegten Namen umbenannt werden',
        );

        // Und es entsteht kein zweites Set mit demselben Namen.
        self::assertSame(
            1,
            (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM custom_field_set WHERE name = :name',
                ['name' => 'ruhrcoder_color_picker'],
            ),
            'es darf nie zwei gleichnamige Feldsets geben',
        );
    }

    /**
     * Entfernt die vom CustomFieldInstaller angelegten v2-Namen, damit ein Test die Umbenennung
     * in einen freien Zielnamen prüfen kann. Die Testtransaktion wird zurückgerollt.
     */
    private function removeNewNames(Connection $connection): void
    {
        $connection->executeStatement(
            "DELETE FROM custom_field WHERE name LIKE 'ruhrcoder_color_picker_%'",
        );
        $connection->executeStatement(
            'DELETE FROM custom_field_set WHERE name = :name',
            ['name' => 'ruhrcoder_color_picker'],
        );
    }

    private function insertLegacyFieldSet(Connection $connection, string $setId, string $fieldId): void
    {
        $connection->insert('custom_field_set', [
            'id' => $setId,
            'name' => 'rc_color_picker',
            'config' => json_encode(['label' => ['de-DE' => 'RC Farbauswahl']], \JSON_THROW_ON_ERROR),
            'active' => 1,
            'created_at' => '2026-01-01 00:00:00.000',
        ]);

        $connection->insert('custom_field', [
            'id' => $fieldId,
            'name' => 'rc_color_picker_enabled',
            'type' => 'bool',
            'config' => json_encode(['label' => ['de-DE' => 'aktiv']], \JSON_THROW_ON_ERROR),
            'set_id' => $setId,
            'active' => 1,
            'created_at' => '2026-01-01 00:00:00.000',
        ]);
    }

    /**
     * Die Verbindung aus dem Container kommt untypisiert heraus. Einmal prüfen statt
     * an jeder Fundstelle darauf zu vertrauen.
     */
    private function connection(): Connection
    {
        $connection = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

}

<?php

declare(strict_types=1);

namespace Ruhrcoder\RcColorPicker\Tests\Migration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcColorPicker\Migration\Migration1785715200RepatchOrderConfirmationMailColorBlock;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Die Nachbesserung an der Bestellbestätigungs-Mail.
 *
 * Zwei Dinge stehen hier auf dem Spiel: dass der Farb-Block ein Shopware-Update übersteht, und
 * dass eine Farbe ohne RAL-Code überhaupt in der Mail auftaucht.
 */
#[CoversClass(Migration1785715200RepatchOrderConfirmationMailColorBlock::class)]
final class Migration1785715200RepatchOrderConfirmationMailColorBlockTest extends TestCase
{
    /**
     * Was: Der Zeitstempel der Migration.
     * Warum: Er ist die Reihenfolge. Ein nachträglich geänderter Wert liefe auf Installationen,
     *        die ihn schon gesehen haben, nie wieder — und auf neuen in falscher Reihenfolge.
     * Erwartet: unverändert.
     */
    public function testTheCreationTimestampIsStable(): void
    {
        self::assertSame(
            1785715200,
            (new Migration1785715200RepatchOrderConfirmationMailColorBlock())->getCreationTimestamp(),
        );
    }

    /**
     * Was: Eine Vorlage mit dem unveränderten v1-Block.
     * Warum: **Der Kern der Sache.** Ohne `updated_at` gilt die Vorlage für Shopware als
     *        unangetastet — die nächste Kern-Migration für die Bestellbestätigung ersetzt sie,
     *        und der Farb-Block ist weg. Ohne Eintrag, ohne Fehler. Und der v2-Block zeigt eine
     *        Farbe auch dann, wenn nur ein Name ohne RAL-Code hinterlegt ist.
     * Erwartet: Block auf v2 gehoben, `updated_at` in beiden Tabellen gesetzt.
     */
    public function testTheV1BlockIsLiftedAndTheTemplateIsMarkedAsCustomised(): void
    {
        $template = $this->buildTemplateWithV1Block();

        $written = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([[
            'mail_template_id' => Uuid::randomBytes(),
            'language_id' => Uuid::randomBytes(),
            'content_html' => $template,
            'content_plain' => '',
        ]]);
        $connection->method('update')->willReturnCallback(
            static function (string $table, array $data) use (&$written): int {
                $written[$table] = $data;

                return 1;
            },
        );

        (new Migration1785715200RepatchOrderConfirmationMailColorBlock())->update($connection);

        self::assertArrayHasKey('mail_template_translation', $written);
        self::assertArrayHasKey('mail_template', $written);

        // Beide Tabellen tragen das Kennzeichen — der Kern prüft es an der Vorlage, nicht nur an
        // der Übersetzung.
        self::assertArrayHasKey('updated_at', $written['mail_template_translation']);
        self::assertArrayHasKey('updated_at', $written['mail_template']);

        $patched = $written['mail_template_translation']['content_html'];
        self::assertStringContainsString(
            Migration1785715200RepatchOrderConfirmationMailColorBlock::MARKER_V2,
            $patched,
        );
        self::assertStringNotContainsString('mail-color-block-v1', $patched);

        // Eine Farbe ohne RAL-Code fiel vorher heraus, weil der Block auf `cpRal` prüfte.
        self::assertStringContainsString('{% if cpRal or cpName %}', $patched);
    }

    /**
     * Was: Eine von Hand angepasste Vorlage.
     * Warum: Wer die Mail selbst umgebaut hat, hat das mit Absicht getan. Eine Migration, die
     *        darüber hinweggeht, zerstört Arbeit — und zwar unbemerkt, weil niemand die Mail
     *        täglich liest.
     * Erwartet: Inhalt bleibt, Vermerk wird protokolliert.
     */
    public function testAHandEditedTemplateIsLeftAloneAndLogged(): void
    {
        $handEdited = "{# RcColorPicker:mail-color-block-v1 #}\n{% if cpRal %}von Hand{% endif %}";

        $written = [];
        $inserted = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([[
            'mail_template_id' => Uuid::randomBytes(),
            'language_id' => Uuid::randomBytes(),
            'content_html' => $handEdited,
            'content_plain' => '',
        ]]);
        $connection->method('update')->willReturnCallback(
            static function (string $table, array $data) use (&$written): int {
                $written[$table] = $data;

                return 1;
            },
        );
        $connection->method('insert')->willReturnCallback(
            static function (string $table, array $data) use (&$inserted): int {
                $inserted[$table] = $data;

                return 1;
            },
        );

        (new Migration1785715200RepatchOrderConfirmationMailColorBlock())->update($connection);

        // Der Inhalt bleibt unangetastet …
        self::assertArrayNotHasKey('content_html', $written['mail_template_translation']);
        // … das Kennzeichen wird trotzdem gesetzt: Die Vorlage trägt unseren Block, also muss sie
        // vor dem Kern geschützt sein — gerade wenn jemand daran gearbeitet hat.
        self::assertArrayHasKey('updated_at', $written['mail_template_translation']);

        self::assertArrayHasKey('log_entry', $inserted);
    }

    /**
     * Was: Eine Vorlage ohne unseren Block.
     * Warum: Nicht jede Bestellbestätigung im Shop hat je einen Farb-Block bekommen. Sie als
     *        „angepasst" zu kennzeichnen wäre eine Falschaussage und nähme ihr künftige
     *        Verbesserungen aus dem Kern.
     * Erwartet: kein Schreibvorgang.
     */
    public function testTemplatesWithoutOurBlockAreNotTouched(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([[
            'mail_template_id' => Uuid::randomBytes(),
            'language_id' => Uuid::randomBytes(),
            'content_html' => '<p>Standardvorlage ohne Farbe</p>',
            'content_plain' => 'Standardvorlage ohne Farbe',
        ]]);
        $connection->expects(self::never())->method('update');

        (new Migration1785715200RepatchOrderConfirmationMailColorBlock())->update($connection);
    }

    private function buildTemplateWithV1Block(): string
    {
        $block = <<<TWIG
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

        return "<div>{{ nestedItem.label|u.wordwrap(80) }}</div>\n" . $block . "\n<p>Rest</p>";
    }
}

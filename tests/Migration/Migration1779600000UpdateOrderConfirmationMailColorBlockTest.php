<?php

declare(strict_types=1);

namespace Ruhrcoder\RcColorPicker\Tests\Migration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcColorPicker\Migration\Migration1779600000UpdateOrderConfirmationMailColorBlock;

#[CoversClass(Migration1779600000UpdateOrderConfirmationMailColorBlock::class)]
final class Migration1779600000UpdateOrderConfirmationMailColorBlockTest extends TestCase
{
    private Migration1779600000UpdateOrderConfirmationMailColorBlock $migration;

    protected function setUp(): void
    {
        $this->migration = new Migration1779600000UpdateOrderConfirmationMailColorBlock();
    }

    public function testTimestampIsStableAgainstRenaming(): void
    {
        self::assertSame(1779600000, $this->migration->getCreationTimestamp());
    }

    public function testPatchHtmlReturnsNullOnEmptyContent(): void
    {
        self::assertNull($this->migration->patchHtml(null));
        self::assertNull($this->migration->patchHtml(''));
    }

    public function testPatchHtmlReturnsNullWhenMarkerAlreadyPresent(): void
    {
        $content = "<div>... {# RcColorPicker:mail-color-block-v1 #} ...</div>";

        self::assertNull($this->migration->patchHtml($content));
    }

    public function testPatchHtmlReturnsNullWhenAnchorIsMissing(): void
    {
        $content = "<div>komplett angepasstes Template ohne Label-Anchor</div>";

        self::assertNull($this->migration->patchHtml($content));
    }

    public function testPatchHtmlInsertsBlockAfterLabelDiv(): void
    {
        $content = $this->defaultHtmlFragment();

        $patched = $this->migration->patchHtml($content);

        self::assertNotNull($patched);
        self::assertStringContainsString(Migration1779600000UpdateOrderConfirmationMailColorBlock::MARKER, $patched);
        self::assertStringContainsString('rcColorPickerRal', $patched);
        self::assertStringContainsString("rcColorPicker.cartLabel'|trans", $patched);
        // Anchor und folgender </div> bleiben unverändert vorhanden.
        self::assertStringContainsString('{{ nestedItem.label|u.wordwrap(80) }}', $patched);
        // Der Block kommt NACH dem schließenden div des Labels.
        $labelEnd = strpos($patched, '</div>');
        $markerPos = strpos($patched, Migration1779600000UpdateOrderConfirmationMailColorBlock::MARKER);
        self::assertNotFalse($labelEnd);
        self::assertNotFalse($markerPos);
        self::assertGreaterThan($labelEnd, $markerPos);
    }

    public function testPatchHtmlIsIdempotentOnSecondRun(): void
    {
        $content = $this->defaultHtmlFragment();
        $firstPass = $this->migration->patchHtml($content);
        self::assertNotNull($firstPass);

        $secondPass = $this->migration->patchHtml($firstPass);

        self::assertNull($secondPass, 'Zweiter Lauf darf nicht erneut patchen.');
    }

    public function testPatchPlainReturnsNullOnEmptyContent(): void
    {
        self::assertNull($this->migration->patchPlain(null));
        self::assertNull($this->migration->patchPlain(''));
    }

    public function testPatchPlainReturnsNullWhenMarkerAlreadyPresent(): void
    {
        $content = "Beschreibung X\n{# RcColorPicker:mail-color-block-v1 #}\n...";

        self::assertNull($this->migration->patchPlain($content));
    }

    public function testPatchPlainReturnsNullWhenAnchorIsMissing(): void
    {
        $content = "Komplett angepasstes Plaintext-Template ohne Label-Anchor\n";

        self::assertNull($this->migration->patchPlain($content));
    }

    public function testPatchPlainInsertsBlockAfterAnchorLine(): void
    {
        $content = $this->defaultPlainFragment();

        $patched = $this->migration->patchPlain($content);

        self::assertNotNull($patched);
        self::assertStringContainsString(Migration1779600000UpdateOrderConfirmationMailColorBlock::MARKER, $patched);
        self::assertStringContainsString('rcColorPickerRal', $patched);
        self::assertStringContainsString("rcColorPicker.cartLabel'|trans", $patched);

        // Reihenfolge: Anchor-Zeile zuerst, dann Marker.
        $anchorPos = strpos($patched, '{{ lineItem.label|u.wordwrap(80) }}');
        $markerPos = strpos($patched, Migration1779600000UpdateOrderConfirmationMailColorBlock::MARKER);
        self::assertNotFalse($anchorPos);
        self::assertNotFalse($markerPos);
        self::assertGreaterThan($anchorPos, $markerPos);
    }

    public function testPatchPlainIsIdempotentOnSecondRun(): void
    {
        $content = $this->defaultPlainFragment();
        $firstPass = $this->migration->patchPlain($content);
        self::assertNotNull($firstPass);

        self::assertNull($this->migration->patchPlain($firstPass));
    }

    public function testPatchHtmlKeepsFollowingContent(): void
    {
        $content = $this->defaultHtmlFragment();
        $marker = '<!-- nachfolgender Marker -->';
        $content .= "\n" . $marker;

        $patched = $this->migration->patchHtml($content);

        self::assertNotNull($patched);
        self::assertStringContainsString($marker, $patched);
        self::assertStringEndsWith($marker, trim($patched));
    }

    /**
     * Ein angepasstes Template ohne Anchor wird übersprungen — das muss im Log
     * auftauchen, sonst merkt der Betreiber nie, dass die Farbe in der Mail fehlt.
     */
    public function testUpdateLogsSkippedCustomizedTemplate(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([
            [
                'mail_template_id' => hex2bin('0102030405060708090a0b0c0d0e0f10'),
                'language_id' => hex2bin('102030405060708090a0b0c0d0e0f001'),
                'content_html' => '<div>eigenes Template ohne Anchor</div>',
                'content_plain' => 'eigenes Plaintext-Template ohne Anchor',
            ],
        ]);

        // Nichts zu patchen -> kein Template-Write.
        $connection->expects(self::never())->method('update');

        $connection->expects(self::once())
            ->method('insert')
            ->with('log_entry', self::callback(function (array $data): bool {
                self::assertSame('rc_color_picker', $data['channel']);
                self::assertSame(300, $data['level']);
                self::assertStringContainsString('README', $data['message']);

                $context = json_decode((string) $data['context'], true, 512, \JSON_THROW_ON_ERROR);
                // Beide Varianten derselben Zeile werden einzeln gemeldet.
                self::assertCount(2, $context['skipped']);
                self::assertSame('0102030405060708090a0b0c0d0e0f10', $context['skipped'][0]['mailTemplateId']);
                self::assertSame('content_html', $context['skipped'][0]['variant']);
                self::assertSame('content_plain', $context['skipped'][1]['variant']);

                return true;
            }));

        $this->migration->update($connection);
    }

    /**
     * Der Normalfall darf nicht ins Log rauschen: Default-Template wird gepatcht, kein Skip.
     */
    public function testUpdatePatchesDefaultTemplateWithoutLogEntry(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([
            [
                'mail_template_id' => hex2bin('0102030405060708090a0b0c0d0e0f10'),
                'language_id' => hex2bin('102030405060708090a0b0c0d0e0f001'),
                'content_html' => $this->defaultHtmlFragment(),
                'content_plain' => $this->defaultPlainFragment(),
            ],
        ]);

        $connection->expects(self::once())->method('update')->with(
            'mail_template_translation',
            self::callback(function (array $update): bool {
                self::assertStringContainsString(
                    Migration1779600000UpdateOrderConfirmationMailColorBlock::MARKER,
                    $update['content_html'],
                );
                self::assertStringContainsString(
                    Migration1779600000UpdateOrderConfirmationMailColorBlock::MARKER,
                    $update['content_plain'],
                );

                return true;
            }),
            self::isType('array'),
        );

        $connection->expects(self::never())->method('insert');

        $this->migration->update($connection);
    }

    /**
     * Zweitlauf auf bereits gepatchten Templates: idempotent und ebenfalls ohne Log-Rauschen.
     */
    public function testUpdateSecondRunStaysSilent(): void
    {
        $patchedHtml = $this->migration->patchHtml($this->defaultHtmlFragment());
        $patchedPlain = $this->migration->patchPlain($this->defaultPlainFragment());
        self::assertNotNull($patchedHtml);
        self::assertNotNull($patchedPlain);

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([
            [
                'mail_template_id' => hex2bin('0102030405060708090a0b0c0d0e0f10'),
                'language_id' => hex2bin('102030405060708090a0b0c0d0e0f001'),
                'content_html' => $patchedHtml,
                'content_plain' => $patchedPlain,
            ],
        ]);

        $connection->expects(self::never())->method('update');
        $connection->expects(self::never())->method('insert');

        $this->migration->update($connection);
    }

    /**
     * Repräsentativer Ausschnitt aus dem Shopware-Default-HTML-Template,
     * der den für den Anchor relevanten Bereich abbildet.
     */
    private function defaultHtmlFragment(): string
    {
        return <<<HTML
<table>
    {% for lineItem in order.nestedLineItems %}
        {% set nestedItem = lineItem %}
        <tr>
            <td>
                <div>
                    {{ nestedItem.label|u.wordwrap(80) }}
                </div>

                {% if nestedItem.payload.options is defined %}<div>Options</div>{% endif %}
            </td>
        </tr>
    {% endfor %}
</table>
HTML;
    }

    private function defaultPlainFragment(): string
    {
        return <<<PLAIN
{% for lineItem in order.lineItems %}
Pos. {{ loop.index }}
---------------------
Beschreibung {{ lineItem.label|u.wordwrap(80) }},
Menge {{ lineItem.quantity }},

{% endfor %}
PLAIN;
    }
}

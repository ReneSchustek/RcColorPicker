<?php

declare(strict_types=1);

namespace Ruhrcoder\RcColorPicker\Tests\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcColorPicker\Service\ColorValidator;

final class ColorValidatorTest extends TestCase
{
    #[DataProvider('validHexValues')]
    public function testAcceptsValidHex(string $hex): void
    {
        self::assertSame($hex, ColorValidator::sanitizeHex($hex));
    }

    #[DataProvider('invalidHexValues')]
    public function testRejectsInvalidHex(string $hex): void
    {
        self::assertSame('', ColorValidator::sanitizeHex($hex));
    }

    public function testSanitizeTextStripsMarkupAndTrims(): void
    {
        self::assertSame('RAL 7016', ColorValidator::sanitizeText('  <b>RAL 7016</b> '));
    }

    public function testSanitizeTextEnforcesLengthLimit(): void
    {
        $long = str_repeat('a', 200);

        self::assertSame(ColorValidator::MAX_TEXT_LENGTH, mb_strlen(ColorValidator::sanitizeText($long)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validHexValues(): iterable
    {
        yield 'rgb' => ['#abc'];
        yield 'rrggbb' => ['#293133'];
        yield 'rgba' => ['#abcd'];
        yield 'rrggbbaa' => ['#293133ff'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidHexValues(): iterable
    {
        yield 'css-injection' => ['red;background:url(//evil/x)'];
        yield 'kein-hash' => ['293133'];
        yield 'zu-lang' => ['#2931330011'];
        yield 'nonhex' => ['#zzzzzz'];
        yield 'leer' => [''];
    }
}

<?php

declare(strict_types=1);

namespace Ruhrcoder\RcColorPicker\Tests\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcColorPicker\Service\ConfigService;
use Shopware\Core\System\SystemConfig\SystemConfigService;

#[CoversClass(ConfigService::class)]
final class ConfigServiceTest extends TestCase
{
    public function testGetStandardColorsParsesDreiFelder(): void
    {
        $service = $this->createService([
            'RcColorPicker.config.standardColors' => "RAL 9010;Reinweiß;#FFFFFF\nRAL 7016;Anthrazitgrau;#293133",
        ]);

        $colors = $service->getStandardColors();

        self::assertCount(2, $colors);
        self::assertSame('RAL 9010', $colors[0]['ral']);
        self::assertSame('Reinweiß', $colors[0]['name']);
        self::assertSame('#FFFFFF', $colors[0]['hex']);
        self::assertSame('RAL 7016', $colors[1]['ral']);
    }

    public function testGetStandardColorsLeererString(): void
    {
        $service = $this->createService([
            'RcColorPicker.config.standardColors' => '',
        ]);

        self::assertSame([], $service->getStandardColors());
    }

    public function testGetStandardColorsNull(): void
    {
        $service = $this->createService([
            'RcColorPicker.config.standardColors' => null,
        ]);

        self::assertSame([], $service->getStandardColors());
    }

    public function testGetStandardColorsIgnoriertUnvollstaendigeZeilen(): void
    {
        $service = $this->createService([
            'RcColorPicker.config.standardColors' => "RAL 9010;Reinweiß\nRAL 7016;Anthrazitgrau;#293133\nNurEinWert",
        ]);

        $colors = $service->getStandardColors();

        self::assertCount(1, $colors);
        self::assertSame('RAL 7016', $colors[0]['ral']);
    }

    public function testGetStandardColorsIgnoriertLeerzeilen(): void
    {
        $service = $this->createService([
            'RcColorPicker.config.standardColors' => "RAL 9010;Reinweiß;#FFFFFF\n\n\nRAL 7016;Anthrazitgrau;#293133",
        ]);

        self::assertCount(2, $service->getStandardColors());
    }

    public function testGetStandardColorsTrimtWerte(): void
    {
        $service = $this->createService([
            'RcColorPicker.config.standardColors' => " RAL 9010 ; Reinweiß ; #FFFFFF ",
        ]);

        $colors = $service->getStandardColors();

        self::assertSame('RAL 9010', $colors[0]['ral']);
        self::assertSame('Reinweiß', $colors[0]['name']);
        self::assertSame('#FFFFFF', $colors[0]['hex']);
    }

    /**
     * @return iterable<string, array{0:string}>
     */
    public static function ungueltigeHexCodes(): iterable
    {
        yield 'kein-rautezeichen' => ['FFFFFF'];
        yield 'zu-kurz' => ['#FF'];
        yield 'sieben-zeichen' => ['#FFFFFF1'];
        yield 'unzulässige-zeichen' => ['#GGHHII'];
        yield 'leer' => [''];
        yield 'wort' => ['notahex'];
        yield 'rgb-notation' => ['rgb(255,255,255)'];
    }

    #[DataProvider('ungueltigeHexCodes')]
    public function testGetStandardColorsLehnEntUngueltigeHexAb(string $hex): void
    {
        $service = $this->createService([
            'RcColorPicker.config.standardColors' => sprintf("RAL 9010;Reinweiß;%s\nRAL 7016;Anthrazitgrau;#293133", $hex),
        ]);

        $colors = $service->getStandardColors();

        self::assertCount(1, $colors, sprintf('Hex "%s" hätte nicht akzeptiert werden dürfen', $hex));
        self::assertSame('RAL 7016', $colors[0]['ral']);
    }

    /**
     * @return iterable<string, array{0:string}>
     */
    public static function gueltigeHexCodes(): iterable
    {
        yield 'kurz-lowercase' => ['#fff'];
        yield 'kurz-uppercase' => ['#FFF'];
        yield 'lang-lowercase' => ['#293133'];
        yield 'lang-uppercase' => ['#ABCDEF'];
        yield 'mit-alpha-kurz' => ['#FFFF'];
        yield 'mit-alpha-lang' => ['#FFFFFF80'];
        yield 'numerisch' => ['#123456'];
    }

    #[DataProvider('gueltigeHexCodes')]
    public function testGetStandardColorsAkzeptiertGueltigeHex(string $hex): void
    {
        $service = $this->createService([
            'RcColorPicker.config.standardColors' => sprintf('RAL 9010;Reinweiß;%s', $hex),
        ]);

        self::assertCount(1, $service->getStandardColors(), sprintf('Hex "%s" hätte akzeptiert werden müssen', $hex));
    }

    public function testGetStandardColorsHardLimit(): void
    {
        $lines = [];
        for ($i = 0; $i < ConfigService::MAX_STANDARD_COLORS + 20; ++$i) {
            $lines[] = sprintf('RAL 9%03d;Farbe%d;#%06X', $i, $i, $i * 100);
        }

        $service = $this->createService([
            'RcColorPicker.config.standardColors' => implode("\n", $lines),
        ]);

        $colors = $service->getStandardColors();

        self::assertCount(ConfigService::MAX_STANDARD_COLORS, $colors);
        self::assertSame('RAL 9000', $colors[0]['ral']);
        self::assertSame(
            sprintf('RAL 9%03d', ConfigService::MAX_STANDARD_COLORS - 1),
            $colors[ConfigService::MAX_STANDARD_COLORS - 1]['ral'],
        );
    }

    public function testIsCustomRalAllowedDefault(): void
    {
        $service = $this->createService([]);

        self::assertTrue($service->isCustomRalAllowed());
    }

    public function testIsCustomRalAllowedFalse(): void
    {
        $service = $this->createService([
            'RcColorPicker.config.allowCustomRal' => false,
        ]);

        self::assertFalse($service->isCustomRalAllowed());
    }

    public function testIsColorRequiredDefault(): void
    {
        $service = $this->createService([]);

        self::assertTrue($service->isColorRequired());
    }

    public function testIsColorRequiredFalse(): void
    {
        $service = $this->createService([
            'RcColorPicker.config.colorRequired' => false,
        ]);

        self::assertFalse($service->isColorRequired());
    }

    public function testIsCustomRalLabelVisibleDefault(): void
    {
        $service = $this->createService([]);

        self::assertFalse($service->isCustomRalLabelVisible());
    }

    public function testIsCustomRalLabelVisibleTrue(): void
    {
        $service = $this->createService([
            'RcColorPicker.config.customRalLabelVisible' => true,
        ]);

        self::assertTrue($service->isCustomRalLabelVisible());
    }

    /**
     * Kein Eigen-Cache: Jede Abfrage geht an den Core durch,
     * damit dessen Invalidierung bei Konfigurationsänderung auch wirklich greift.
     */
    public function testJedeAbfrageGehtAnDenCoreDurch(): void
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->expects(self::exactly(2))
            ->method('get')
            ->with('RcColorPicker.config.allowCustomRal', null)
            ->willReturn(true);

        $service = new ConfigService($systemConfig);

        $service->isCustomRalAllowed();
        $service->isCustomRalAllowed();
    }

    public function testSalesChannelIdWirdWeitergegeben(): void
    {
        $channelId = 'abc123';

        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->expects(self::once())
            ->method('get')
            ->with('RcColorPicker.config.allowCustomRal', $channelId)
            ->willReturn(false);

        $service = new ConfigService($systemConfig);

        self::assertFalse($service->isCustomRalAllowed($channelId));
    }

    /**
     * @param array<string, mixed> $configValues
     */
    private function createService(array $configValues): ConfigService
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')
            ->willReturnCallback(function (string $key, ?string $salesChannelId) use ($configValues): mixed {
                return $configValues[$key] ?? null;
            });

        return new ConfigService($systemConfig);
    }
}

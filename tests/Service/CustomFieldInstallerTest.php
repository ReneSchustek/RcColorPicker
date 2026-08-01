<?php

declare(strict_types=1);

namespace Ruhrcoder\RcColorPicker\Tests\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcColorPicker\Service\CustomFieldInstaller;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetCollection;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetEntity;

#[CoversClass(CustomFieldInstaller::class)]
final class CustomFieldInstallerTest extends TestCase
{
    public function testInstallLegtSetMitNeuemNamenAnWennNichtVorhanden(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($this->emptySearchResult());

        $repository->expects(self::once())
            ->method('upsert')
            ->with(
                self::callback(function (array $data): bool {
                    self::assertCount(1, $data);
                    self::assertSame('ruhrcoder_color_picker', $data[0]['name']);
                    self::assertArrayNotHasKey('id', $data[0]);
                    self::assertArrayHasKey('config', $data[0]);

                    $field = $data[0]['customFields'][0];
                    self::assertSame('ruhrcoder_color_picker_enabled', $field['name']);
                    self::assertSame('bool', $field['type']);

                    $relations = $data[0]['relations'];
                    self::assertSame('product', $relations[0]['entityName']);

                    return true;
                }),
                self::isInstanceOf(Context::class),
            );

        $installer = new CustomFieldInstaller($repository);
        $installer->install(Context::createDefaultContext());
    }

    public function testInstallUebergibtBestehendeIdAnUpsertWennSetExistiert(): void
    {
        $set = new CustomFieldSetEntity();
        $set->setId('existing-set-id');
        $set->setUniqueIdentifier('existing-set-id');

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($this->searchResultWith($set));

        $repository->expects(self::once())
            ->method('upsert')
            ->with(
                self::callback(function (array $data): bool {
                    self::assertSame('existing-set-id', $data[0]['id']);
                    self::assertSame('ruhrcoder_color_picker', $data[0]['name']);

                    return true;
                }),
                self::isInstanceOf(Context::class),
            );

        $installer = new CustomFieldInstaller($repository);
        $installer->install(Context::createDefaultContext());
    }

    public function testUninstallLoeschtVorhandenesSet(): void
    {
        $set = new CustomFieldSetEntity();
        $set->setId('set-id-123');
        $set->setUniqueIdentifier('set-id-123');

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($this->searchResultWith($set));
        $repository->expects(self::once())
            ->method('delete')
            ->with(
                [['id' => 'set-id-123']],
                self::isInstanceOf(Context::class),
            );

        $installer = new CustomFieldInstaller($repository);
        $installer->uninstall(Context::createDefaultContext());
    }

    public function testUninstallBeiFehlenderSetMachtNichts(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($this->emptySearchResult());
        $repository->expects(self::never())->method('delete');

        $installer = new CustomFieldInstaller($repository);
        $installer->uninstall(Context::createDefaultContext());
    }

    /**
     * @return EntitySearchResult<CustomFieldSetCollection>
     */
    private function emptySearchResult(): EntitySearchResult
    {
        return new EntitySearchResult(
            'custom_field_set',
            0,
            new CustomFieldSetCollection(),
            null,
            $this->createMock(\Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria::class),
            Context::createDefaultContext(),
        );
    }

    /**
     * @return EntitySearchResult<CustomFieldSetCollection>
     */
    private function searchResultWith(CustomFieldSetEntity $set): EntitySearchResult
    {
        return new EntitySearchResult(
            'custom_field_set',
            1,
            new CustomFieldSetCollection([$set]),
            null,
            $this->createMock(\Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria::class),
            Context::createDefaultContext(),
        );
    }
}

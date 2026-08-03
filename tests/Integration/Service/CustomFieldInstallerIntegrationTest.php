<?php

declare(strict_types=1);

namespace Ruhrcoder\RcColorPicker\Tests\Integration\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use Ruhrcoder\RcColorPicker\Service\CustomFieldInstaller;
use Ruhrcoder\RcColorPicker\Tests\Integration\IntegrationTestBase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetCollection;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetEntity;

#[CoversClass(CustomFieldInstaller::class)]
final class CustomFieldInstallerIntegrationTest extends IntegrationTestBase
{
    private const SET_NAME = 'ruhrcoder_color_picker';

    public function testInstallCreatesCustomFieldSetWithFieldAndProductRelation(): void
    {
        /** @var EntityRepository<CustomFieldSetCollection> $customFieldSetRepository */
        $customFieldSetRepository = static::getContainer()->get('custom_field_set.repository');
        $context = Context::createDefaultContext();

        $installer = new CustomFieldInstaller($customFieldSetRepository);
        $installer->install($context);

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', self::SET_NAME));
        $criteria->addAssociation('customFields');
        $criteria->addAssociation('relations');

        /** @var CustomFieldSetEntity|null $set */
        $set = $customFieldSetRepository->search($criteria, $context)->first();

        self::assertInstanceOf(CustomFieldSetEntity::class, $set);
        self::assertSame(self::SET_NAME, $set->getName());

        $customFields = $set->getCustomFields();
        self::assertNotNull($customFields);
        $fieldNames = array_values(array_map(
            static fn ($field) => $field->getName(),
            $customFields->getElements()
        ));
        self::assertContains('ruhrcoder_color_picker_enabled', $fieldNames);

        $relations = $set->getRelations();
        self::assertNotNull($relations);
        $relationEntities = array_values(array_map(
            static fn ($relation) => $relation->getEntityName(),
            $relations->getElements()
        ));
        self::assertContains('product', $relationEntities);
    }

    public function testInstallIsIdempotentOnSecondCall(): void
    {
        /** @var EntityRepository<CustomFieldSetCollection> $customFieldSetRepository */
        $customFieldSetRepository = static::getContainer()->get('custom_field_set.repository');
        $context = Context::createDefaultContext();

        $installer = new CustomFieldInstaller($customFieldSetRepository);
        $installer->install($context);
        $installer->install($context);

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', self::SET_NAME));
        $criteria->addAssociation('customFields');

        $sets = $customFieldSetRepository->search($criteria, $context);

        self::assertSame(1, $sets->getTotal(), 'Zweiter install darf kein zweites Set anlegen');

        /** @var CustomFieldSetEntity $set */
        $set = $sets->first();
        $fields = $set->getCustomFields();
        self::assertNotNull($fields);

        $enabledFields = array_filter(
            $fields->getElements(),
            static fn ($field) => $field->getName() === 'ruhrcoder_color_picker_enabled'
        );
        self::assertCount(1, $enabledFields, 'Zweiter install darf kein zweites Feld anlegen');
    }

    public function testUninstallDeletesExistingCustomFieldSet(): void
    {
        /** @var EntityRepository<CustomFieldSetCollection> $customFieldSetRepository */
        $customFieldSetRepository = static::getContainer()->get('custom_field_set.repository');
        $context = Context::createDefaultContext();

        $installer = new CustomFieldInstaller($customFieldSetRepository);
        $installer->install($context);

        $installer->uninstall($context);

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', self::SET_NAME));
        $set = $customFieldSetRepository->search($criteria, $context)->first();

        self::assertNull($set);
    }
}

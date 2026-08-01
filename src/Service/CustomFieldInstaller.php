<?php

declare(strict_types=1);

namespace Ruhrcoder\RcColorPicker\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetCollection;
use Shopware\Core\System\CustomField\CustomFieldTypes;

final class CustomFieldInstaller
{
    public const CUSTOM_FIELD_SET_NAME = 'ruhrcoder_color_picker';
    public const CUSTOM_FIELD_ENABLED = 'ruhrcoder_color_picker_enabled';

    /**
     * @param EntityRepository<CustomFieldSetCollection> $customFieldSetRepository
     */
    public function __construct(
        private readonly EntityRepository $customFieldSetRepository,
    ) {
    }

    public function install(Context $context): void
    {
        // Idempotenz: nach Migration v1->v2 existieren Set + Feld bereits unter neuem Namen.
        // Beide IDs explizit mitgeben, sonst legt DAL für das nested customFields-Element
        // einen Datensatz mit neuer UUID an und kollidiert mit dem bestehenden Namen.
        $existing = $this->loadExistingSet($context);

        $payload = [
            'name' => self::CUSTOM_FIELD_SET_NAME,
            'config' => [
                'label' => [
                    'de-DE' => 'RC Farbauswahl',
                    'en-GB' => 'RC Color Picker',
                ],
            ],
            'customFields' => [
                [
                    'name' => self::CUSTOM_FIELD_ENABLED,
                    'type' => CustomFieldTypes::BOOL,
                    'config' => [
                        'label' => [
                            'de-DE' => 'Farbauswahl aktivieren',
                            'en-GB' => 'Enable color picker',
                        ],
                        'helpText' => [
                            'de-DE' => 'Zeigt die Farbauswahl (Standard-Farben / RAL-Code) auf der Produktseite.',
                            'en-GB' => 'Shows the color picker (standard colors / RAL code) on the product page.',
                        ],
                        'customFieldType' => 'checkbox',
                        'customFieldPosition' => 1,
                    ],
                ],
            ],
            'relations' => [
                ['entityName' => 'product'],
            ],
        ];

        if ($existing !== null) {
            $payload['id'] = $existing['setId'];

            if ($existing['fieldId'] !== null) {
                $payload['customFields'][0]['id'] = $existing['fieldId'];
            }

            // Relations weglassen — die existierende product-Relation des Sets darf
            // beim Re-Install nicht ohne ID erneut gepusht werden, sonst knallt
            // sie in den Unique-Constraint (set_id, entity_name).
            unset($payload['relations']);
        }

        $this->customFieldSetRepository->upsert([$payload], $context);
    }

    public function uninstall(Context $context): void
    {
        $existing = $this->loadExistingSet($context);

        if ($existing !== null) {
            $this->customFieldSetRepository->delete([['id' => $existing['setId']]], $context);
        }
    }

    /**
     * Liefert die IDs des bestehenden Sets und des enthaltenen `*_enabled`-Felds.
     * Beide werden beim Upsert benötigt, damit DAL die vorhandenen Datensätze
     * per Primary Key matcht statt neue UUIDs zu erzeugen.
     *
     * @return array{setId: string, fieldId: string|null}|null
     */
    private function loadExistingSet(Context $context): ?array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', self::CUSTOM_FIELD_SET_NAME));
        $criteria->addAssociation('customFields');
        $criteria->setLimit(1);

        $set = $this->customFieldSetRepository->search($criteria, $context)->first();

        if ($set === null) {
            return null;
        }

        $fieldId = null;
        $fields = $set->getCustomFields();
        if ($fields !== null) {
            foreach ($fields as $field) {
                if ($field->getName() === self::CUSTOM_FIELD_ENABLED) {
                    $fieldId = $field->getId();
                    break;
                }
            }
        }

        return ['setId' => $set->getId(), 'fieldId' => $fieldId];
    }
}

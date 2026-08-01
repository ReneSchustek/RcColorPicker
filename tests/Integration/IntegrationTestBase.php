<?php

declare(strict_types=1);

namespace Ruhrcoder\RcColorPicker\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;

/**
 * Basisklasse fuer Integration-Tests gegen den Shopware-Test-Kernel.
 * IntegrationTestBehaviour kapselt Kernel-Lifecycle, Test-Container und DB-Rollback je Test,
 * damit Tests reihenfolgenunabhaengig bleiben.
 */
abstract class IntegrationTestBase extends TestCase
{
    use IntegrationTestBehaviour;
}

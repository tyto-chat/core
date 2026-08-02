<?php

declare(strict_types=1);

namespace App\Tests\Functional\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaValidator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The test database is always built from migrations (ddev test + CI both run
 * doctrine:migrations:migrate), so this catches mapping↔migration drift: an
 * entity change without a migration, or a migration that diverges from the
 * mapping. Both fail silently until the first query touches the drifted
 * column — this makes them fail loudly instead.
 */
class SchemaValidityTest extends KernelTestCase
{
    public function testMappingIsValidAndInSyncWithTheMigratedSchema(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        $validator = new SchemaValidator($em);

        self::assertSame([], $validator->validateMapping(), 'Entity mapping is invalid.');

        // The migrations ledger is unmapped by design. It must NOT be excluded via
        // dbal schema_filter — that also hides it from the migrations bundle's own
        // table-exists check, making `migrate` re-CREATE it and crash on any
        // existing database (broke prod boot once).
        $sql = array_values(array_filter(
            $validator->getUpdateSchemaList(),
            static fn (string $statement): bool => !str_contains($statement, 'doctrine_migration_versions'),
        ));
        self::assertSame(
            [],
            $sql,
            "Migrated schema drifted from the entity mapping. Missing migration for:\n".implode("\n", $sql),
        );
    }
}

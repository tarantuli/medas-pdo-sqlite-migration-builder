<?php

declare(strict_types=1);

namespace Medas\PdoSqliteMigrationBuilder;

use Medas\Core\Attributes\Service;
use Medas\MigrationBuilder\Structure\Blueprint\ForeignKey;
use Medas\PdoStorage\{Database, Drivers\DriverHandler};

/**
 * SQLite cannot add or drop constraints on an existing table, so foreign keys are always
 * part of the create table statement; changing them means rebuilding the table.
 */
#[Service]
readonly class ForeignKeyConstraintBuilder
{
    public function buildInline(
        string        $entityName,
        DriverHandler $driver,
        Database      $database,
        ForeignKey    $foreignKey,
    ): string
    {
        return sprintf(
            ' constraint %s foreign key (%s) references %s (%s) on delete %s on update %s',
            $driver->quote($database, $this->createForeignKeyName($entityName, $foreignKey)),
            $driver->quote($database, $foreignKey->field),
            $driver->quote($database, $foreignKey->foreignEntity),
            $driver->quote($database, $foreignKey->foreignField),
            $foreignKey->onDelete->value,
            $foreignKey->onUpdate->value,
        );
    }

    private function createForeignKeyName(string $entityName, ForeignKey $foreignKey): string
    {
        return sha1($entityName . "\n" . $foreignKey->field . "\n" . $foreignKey->foreignEntity . "\n" . $foreignKey->foreignField);
    }
}

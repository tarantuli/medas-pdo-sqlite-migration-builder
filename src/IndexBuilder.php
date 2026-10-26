<?php

declare(strict_types=1);

namespace Medas\PdoSqliteMigrationBuilder;

use Medas\Core\Attributes\Service;
use Medas\MigrationBuilder\Structure\Blueprint\{Field, Index};
use Medas\PdoStorage\{Database, Drivers\DriverHandler};

#[Service]
readonly class IndexBuilder
{
    // The primary key constraint inside a create table statement
    public function buildPrimary(DriverHandler $driver, Database $database, Index $index): string
    {
        return ' primary key (' . $this->buildFieldList($driver, $database, $index) . ')';
    }

    // Unique and regular indexes are separate create index statements, which also lets
    // them be added to an existing table without rebuilding it
    public function buildCreate(DriverHandler $driver, Database $database, string $tableName, Index $index): string
    {
        return sprintf(
            'create %sindex %s on %s (%s)',
            $index->isUnique ? 'unique ' : '',
            $driver->quote($database, $this->createIndexName($tableName, $index)),
            $driver->quote($database, $tableName),
            $this->buildFieldList($driver, $database, $index),
        );
    }

    // Index names are unique per database in SQLite, so the table name is part of the hash
    private function createIndexName(string $tableName, Index $index): string
    {
        $names = array_map(fn(Field $field) => $field->name, $index->fields());

        return sha1($tableName . "\n" . implode("\n", $names) . "\n" . (int) $index->isUnique);
    }

    private function buildFieldList(DriverHandler $driver, Database $database, Index $index): string
    {
        return implode(
            ', ',
            array_map(fn(Field $field) => $driver->quote($database, $field->name), $index->fields())
        );
    }
}

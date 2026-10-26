<?php

declare(strict_types=1);

namespace Medas\PdoSqliteMigrationBuilder\TableStructureFinder;

use Medas\Core\Attributes\Service;
use Medas\PdoStorage\{
    Exceptions\PdoDatabase,
    PdoStorageController,
    Queries\Query,
    Queries\QueryExecutor,
    Table
};

#[Service]
readonly class TableStructureStringFinder
{
    public function __construct(
        private PdoStorageController $pdoStorageController,
        private QueryExecutor        $queryExecutor,
    )
    {
    }

    // The create table statement as SQLite keeps it in its schema table
    public function find(Table $table): string|null
    {
        return $this->fetchStatements($table, 'table')[0] ?? null;
    }

    /**
     * The create index statements of the table. Indexes SQLite creates itself for
     * constraints have no statement and are left out.
     *
     * @return string[]
     */
    public function findIndexes(Table $table): array
    {
        return $this->fetchStatements($table, 'index');
    }

    private function fetchStatements(Table $table, string $type): array
    {
        $escapedName = $this->pdoStorageController->escape($table->database, $table->name);
        $escapedType = $this->pdoStorageController->escape($table->database, $type);

        $query = new Query(
            'select sql from sqlite_schema where type = '
                . $escapedType
                . ' and tbl_name = '
                . $escapedName
                . ' and sql is not null order by name',
            [],
            $table->database,
        );

        try {
            $this->queryExecutor->execute($query);
        }
        catch (PdoDatabase) {
            return [];
        }

        $recordSet = $query->recordSet();
        $statements = [];

        while ($recordSet->hasRecords() && $record = $recordSet->fetchRecord()) {
            $statements[] = $record['sql'];
        }

        return $statements;
    }
}

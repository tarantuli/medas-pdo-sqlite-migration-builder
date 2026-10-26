<?php

declare(strict_types=1);

namespace Medas\PdoSqliteMigrationBuilder;

use Medas\Core\Attributes\Service;
use Medas\MigrationBuilder\Structure\{Blueprint, Changes\Changes};
use Medas\PdoStorage\{Database, Queries\Query, Queries\QuerySet};
use Medas\StorageManager\{Type, UnitOfWork\Priority};

/**
 * SQLite's alter table can only add columns. Added columns that it accepts and added
 * indexes are applied in place; any other change (changed columns, primary keys, foreign
 * keys, non-constant defaults) rebuilds the table, as described in
 * https://www.sqlite.org/lang_altertable.html#otheralter
 */
#[Service]
readonly class AlterTableBuilder
{
    public function __construct(
        private CollectionProcessor        $collectionProcessor,
        private CreateTableBuilder         $createTableBuilder,
        private FieldToDefinitionConverter $fieldToDefinitionConverter,
    )
    {
    }

    public function create(Database $database, Blueprint $blueprint, Changes $changes): QuerySet
    {
        $job = $this->createTableBuilder->createJob($database, $blueprint);

        $job->changes = $changes;
        $collections = $this->findCollections($job);

        if ($this->needsRebuild($job)) {
            $this->rebuild($job);
        }
        else {
            $this->addColumns($job);
            $this->addIndexes($job);
        }

        // Only the added or changed collections need their join tables checked
        $job->collections = $collections;

        $this->collectionProcessor->process($job);

        return $job->querySet;
    }

    /** @return Blueprint\Field[] */
    private function findCollections(TableBuilders\Job $job): array
    {
        return array_values(array_filter(
            array_merge($job->changes->addFields, $job->changes->changeFields),
            fn(Blueprint\Field $field) => $field->type === Type::Collection
        ));
    }

    private function needsRebuild(TableBuilders\Job $job): bool
    {
        if ($job->changes->addForeignKey || $job->changes->changeForeignKey) {
            return true;
        }

        if (array_any($job->changes->changeFields, fn($field) => $field->type !== Type::Collection)) {
            return true;
        }

        return array_any(
            $job->changes->addFields,
            fn($field)
                => $field->type !== Type::Collection
                    && !$this->fieldToDefinitionConverter->canBeAdded($field
            )
        );
    }

    /**
     * Creates the new table under a temporary name, copies the rows, drops the old table
     * and renames the new one. Renaming the new table rather than the old one keeps the
     * foreign keys of other tables pointing at this table intact, as SQLite rewrites those
     * references when a table they point at is renamed.
     */
    private function rebuild(TableBuilders\Job $job): void
    {
        $tableName = $job->changes->name;
        $temporaryName = $tableName . '__rebuild_tmp';
        $quotedTable = $job->driverHandler->quote($job->database, $tableName);
        $quotedTemporary = $job->driverHandler->quote($job->database, $temporaryName);

        $this->addQuery($job, $this->createTableBuilder->buildTableQuery($job, $temporaryName));

        // Copy the columns that already existed; added columns get their defaults
        $addedFields = array_map(
            fn(Blueprint\Field $field) => $field->name,
            $job->changes->addFields
        );

        $copiedFields = array_values(array_diff($job->columns, $addedFields));

        if ($copiedFields) {
            $quotedFields = implode(
                ', ',
                array_map(
                    fn(string $name) => $job->driverHandler->quote($job->database, $name),
                    $copiedFields
                )
            );

            $this->addQuery(
                $job,
                sprintf(
                    'insert into %s (%s) select %s from %s',
                    $quotedTemporary,
                    $quotedFields,
                    $quotedFields,
                    $quotedTable
                )
            );
        }

        // Dropping the old table also drops its indexes, so all of them are created again
        $this->addQuery($job, 'drop table ' . $quotedTable);

        $this->addQuery(
            $job,
            sprintf('alter table %s rename to %s', $quotedTemporary, $quotedTable)
        );

        foreach ($this->createTableBuilder->buildIndexQueries($job, $tableName) as $query) {
            $this->addQuery($job, $query);
        }
    }

    private function addQuery(TableBuilders\Job $job, string $query): void
    {
        $job->querySet[] = new Query($query, [], $job->database, Priority::AlterStore);
    }

    private function addColumns(TableBuilders\Job $job): void
    {
        foreach ($job->changes->addFields as $field) {
            if ($field->type === Type::Collection) {
                continue;
            }

            $definition = $this->fieldToDefinitionConverter->buildDefinition(
                $job->database,
                $field
            );

            if ($definition === null) {
                continue;
            }

            $job->querySet[] = new Query(
                sprintf(
                    'alter table %s add column %s %s',
                    $job->driverHandler->quote($job->database, $job->changes->name),
                    $job->driverHandler->quote($job->database, $field->name),
                    $definition,
                ),
                [],
                $job->database,
                Priority::AlterStore,
            );
        }
    }

    private function addIndexes(TableBuilders\Job $job): void
    {
        $queries = $this->createTableBuilder->buildIndexQueries(
            $job,
            $job->changes->name,
            $job->changes->addIndexes
        );

        foreach ($queries as $query) {
            $job->querySet[] = new Query($query, [], $job->database, Priority::AlterStore);
        }
    }
}

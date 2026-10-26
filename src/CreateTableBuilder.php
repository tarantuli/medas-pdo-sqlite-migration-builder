<?php

declare(strict_types=1);

namespace Medas\PdoSqliteMigrationBuilder;

use Medas\Core\Attributes\{ConfigValue, Service};
use Medas\EntityManager\Attributes\Relations\Action;
use Medas\MigrationBuilder\{OriginalClassStorageStrategyActionBuilder, Structure\Blueprint};
use Medas\PdoStorage\{Database, PdoStorageController, Queries\Query, Queries\QuerySet};
use Medas\StorageManager\ConfigOptions\OriginalClassStorage\DefaultStrategy;
use Medas\StorageManager\Inheritance\OriginalClassStorageStrategy;
use Medas\StorageManager\Type;
use Medas\StorageManager\UnitOfWork\Priority;

#[Service]
readonly class CreateTableBuilder
{
    public function __construct(
        private CollectionProcessor                       $collectionProcessor,
        private FieldToDefinitionConverter                $fieldToDefinitionConverter,
        private ForeignKeyConstraintBuilder               $foreignKeyConstraintBuilder,
        private IndexBuilder                              $indexBuilder,

        #[ConfigValue(DefaultStrategy::class)]
        private OriginalClassStorageStrategy              $originalClassStorageStrategy,
        private OriginalClassStorageStrategyActionBuilder $actionBuilder,
        private PdoStorageController                      $pdoStorageController,
    )
    {
    }

    public function create(Database $database, Blueprint $blueprint, bool $ignoreExistingStructure = false): QuerySet
    {
        $job = $this->createJob($database, $blueprint);

        $priority = $job->blueprint->isMigrationStore
            ? Priority::CreateMigrationStore
            : Priority::CreateStore;

        $job->querySet[] = new Query(
            $this->buildTableQuery($job, $job->blueprint->name),
            [],
            $job->database,
            $priority
        );

        foreach ($this->buildIndexQueries($job, $job->blueprint->name) as $indexQuery) {
            $job->querySet[] = new Query($indexQuery, [], $job->database, $priority);
        }

        $this->handleOriginalEntityType($job, $ignoreExistingStructure);
        $this->collectionProcessor->process($job, $ignoreExistingStructure);

        return $job->querySet;
    }

    private function handleOriginalEntityType(TableBuilders\Job $job, bool $ignoreExistingStructure): void
    {
        if (!$job->blueprint->storeOriginalClass) {
            return;
        }

        if ($job->blueprint->storeRequestingOriginalClassStorage !== $job->blueprint->name) {
            return;
        }

        $queries = $this->actionBuilder->build(
            $this->originalClassStorageStrategy,
            $job->blueprint,
            $job->database,
            $ignoreExistingStructure
        );

        foreach ($queries as $query) {
            $job->querySet[] = $query;
        }
    }

    public function createJob(Database $database, Blueprint $blueprint): TableBuilders\Job
    {
        return new TableBuilders\Job(
            $database,
            $this->pdoStorageController->getDatabaseController($database)->driverHandler,
            $blueprint,
        );
    }

    /**
     * Builds the create table statement of the job's blueprint under the given table name,
     * including the primary key and the foreign keys, which SQLite cannot add afterward.
     * Collection fields found on the way are collected in the job.
     */
    public function buildTableQuery(TableBuilders\Job $job, string $tableName): string
    {
        $parts = [];

        $this->addFields($job, $parts);
        $this->addPrimaryKey($job, $parts);
        $this->addForeignKeys($job, $parts);

        return sprintf(
            /** @lang text */
            "create table %s (\n%s\n)",
            $job->driverHandler->quote($job->database, $tableName),
            implode(",\n", $parts)
        );
    }

    private function addFields(TableBuilders\Job $job, array &$parts): void
    {
        foreach ($job->blueprint->fields as $field) {
            if ($field->store !== null && $field->store !== $job->blueprint->name) {
                // If this is the primary key, add it without generating value
                $primaryIndex = $job->blueprint->primaryIndex();

                if ($primaryIndex && in_array($field, $primaryIndex->fields(), true)) {
                    $foreignKey = new Blueprint\ForeignKey(
                        $field->name,
                        $field->store,
                        $field->name,
                        Action::Cascade,
                        Action::Cascade,
                        $job->blueprint->name
                    );

                    $job->blueprint->addForeignKey($foreignKey);

                    $field->isGenerated = false;
                }
                else {
                    continue;
                }
            }

            if ($field->type === Type::Collection) {
                $job->collections[] = $field;

                continue;
            }

            $definition = $this->fieldToDefinitionConverter->buildDefinition(
                $job->database,
                $field
            );

            if ($definition !== null) {
                $parts[] = sprintf(
                    ' %s %s',
                    $job->driverHandler->quote($job->database, $field->name),
                    $definition,
                );

                $job->columns[] = $field->name;
            }
        }
    }

    private function addPrimaryKey(TableBuilders\Job $job, array &$parts): void
    {
        $primaryIndex = $job->blueprint->primaryIndex();

        if ($primaryIndex === null || $primaryIndex->fields() === []) {
            return;
        }

        $fields = $primaryIndex->fields();

        // A generated field already is the primary key: "integer primary key" on the column
        if (count($fields) === 1 && $fields[0]->isGenerated) {
            return;
        }

        $parts[] = $this->indexBuilder->buildPrimary(
            $job->driverHandler,
            $job->database,
            $primaryIndex
        );
    }

    private function addForeignKeys(TableBuilders\Job $job, array &$parts): void
    {
        foreach ($job->blueprint->foreignKeys as $foreignKey) {
            if ($foreignKey->store !== null && $foreignKey->store !== $job->blueprint->name) {
                continue;
            }

            $parts[] = $this->foreignKeyConstraintBuilder->buildInline(
                $job->blueprint->name,
                $job->driverHandler,
                $job->database,
                $foreignKey
            );
        }
    }

    /**
     * Builds the create index statements of the blueprint's unique and regular indexes
     *
     * @return string[]
     */
    public function buildIndexQueries(TableBuilders\Job $job, string $tableName, array|null $indexes = null): array
    {
        $queries = [];

        foreach ($indexes ?? $job->blueprint->indexes as $index) {
            if ($index->isPrimary || $this->hasForeignField($job, $index)) {
                continue;
            }

            $queries[] = $this->indexBuilder->buildCreate(
                $job->driverHandler,
                $job->database,
                $tableName,
                $index
            );
        }

        return $queries;
    }

    // Whether the index covers a field of a parent store; only the primary key may do that
    private function hasForeignField(TableBuilders\Job $job, Blueprint\Index $index): bool
    {
        $primaryFields = $job->blueprint->primaryIndex()?->fields() ?? [];

        return array_any(
            $index->fields(),
            fn($field)
                => $field->store !== null
                    && $field->store !== $job->blueprint->name
                    && !in_array($field, $primaryFields, true
            )
        );
    }
}

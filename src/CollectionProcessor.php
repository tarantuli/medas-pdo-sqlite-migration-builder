<?php

declare(strict_types=1);

namespace Medas\PdoSqliteMigrationBuilder;

use Medas\Core\Attributes\Service;

#[Service]
readonly class CollectionProcessor
{
    public function __construct(
        private JoinTableManager $joinTableManager,
    )
    {
    }

    public function process(TableBuilders\Job $job, bool $ignoreExistingStructure = false): void
    {
        foreach ($job->collections as $collectionField) {
            $queries = $this->joinTableManager->createQueries(
                $job->database,
                $job->blueprint,
                $collectionField,
                $ignoreExistingStructure
            );

            if ($queries) {
                foreach ($queries as $query) {
                    $job->querySet[] = $query;
                }
            }
        }
    }
}

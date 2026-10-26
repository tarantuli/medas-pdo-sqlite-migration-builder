<?php

declare(strict_types=1);

namespace Medas\PdoSqliteMigrationBuilder\TableBuilders;

use Medas\MigrationBuilder\Structure\{Blueprint, Blueprint\Field, Changes\Changes};
use Medas\PdoStorage\{Database, Drivers\DriverHandler, Queries\QuerySet};

class Job
{
    public Changes $changes;

    /** @var Field[] */
    public array $collections = [];

    /** @var string[] Names of the columns emitted in the create table statement */
    public array $columns = [];

    public QuerySet $querySet;

    public function __construct(
        public Database      $database,
        public DriverHandler $driverHandler,
        public Blueprint     $blueprint,
    )
    {
        $this->querySet = new QuerySet();
    }
}

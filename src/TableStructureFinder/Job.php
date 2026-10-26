<?php

declare(strict_types=1);

namespace Medas\PdoSqliteMigrationBuilder\TableStructureFinder;

use Medas\MigrationBuilder\Structure\Blueprint;
use Medas\PdoStorage\{Database, Table};

class Job
{
    public Blueprint $blueprint;
    public string|null $createTable;

    /** @var string[] Column definitions and table constraints of the create table statement */
    public array $parts = [];

    /** @var string[] Create index statements */
    public array $createIndexes = [];

    public function __construct(
        public readonly Database $database,
        public readonly Table    $table,
    )
    {
        $this->blueprint = new Blueprint();
    }
}

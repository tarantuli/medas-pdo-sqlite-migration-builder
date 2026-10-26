<?php

declare(strict_types=1);

namespace Medas\PdoSqliteMigrationBuilder;

use Medas\Core\Attributes\Service;
use Medas\EntityManager\Attributes\Relations\Action;
use Medas\MigrationBuilder\Structure\{Blueprint, Blueprint\ForeignKey, Blueprint\Index};
use Medas\PdoStorage\Table;

#[Service]
readonly class TableStructureFinder
{
    private const string IDENTIFIER = '(?:"(?:[^"]|"")+"|`[^`]+`|\[[^\]]+\]|\w+)';

    public function __construct(
        private DefinitionToFieldConverter                      $definitionToFieldConverter,
        private TableStructureFinder\TableStructureStringFinder $tableStructureStringFinder,
    )
    {
    }

    public function find(Table $table): Blueprint|null
    {
        $job = new TableStructureFinder\Job($table->storage(), $table);

        $job->createTable = $this->tableStructureStringFinder->find($table);

        if ($job->createTable === null) {
            return null;
        }

        $job->createIndexes = $this->tableStructureStringFinder->findIndexes($table);
        $job->blueprint->name = $table->name;
        $job->parts = $this->splitBody($job->createTable);

        $this->findFields($job);
        $this->findPrimaryKey($job);
        $this->findUniqueConstraints($job);
        $this->findIndexes($job);
        $this->findForeignKeys($job);

        return $job->blueprint;
    }

    /**
     * Splits the part between the outer parentheses of the create table statement on the
     * commas that separate column definitions and table constraints. Columns added with
     * "alter table" are appended to the stored statement as ', "name" definition', so the
     * statement cannot be read line by line.
     *
     * @return string[]
     */
    private function splitBody(string $createTable): array
    {
        $start = strpos($createTable, '(');
        $end = strrpos($createTable, ')');

        if ($start === false || $end === false || $end < $start) {
            return [];
        }

        $body = substr($createTable, $start + 1, $end - $start - 1);
        $parts = [];
        $current = '';
        $depth = 0;
        $length = strlen($body);

        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];

            // Copy quoted strings and identifiers as a whole
            if ($char === '"' || $char === "'" || $char === '`') {
                $close = $i;

                do {
                    $close = strpos($body, $char, $close + 1);

                    if ($close === false) {
                        $close = $length - 1;

                        break;
                    }
                } while (($body[$close + 1] ?? '') === $char && ++$close);

                $current .= substr($body, $i, $close - $i + 1);
                $i = $close;

                continue;
            }

            // Copy comments as a whole
            if ($char === '/' && ($body[$i + 1] ?? '') === '*') {
                $close = strpos($body, '*/', $i + 2);
                $close = $close === false ? $length - 1 : $close + 1;
                $current .= substr($body, $i, $close - $i + 1);
                $i = $close;

                continue;
            }

            if ($char === '(') {
                $depth++;
            }
            elseif ($char === ')') {
                $depth--;
            }
            elseif ($char === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';

                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $parts[] = trim($current);
        }

        return $parts;
    }

    private function findFields(TableStructureFinder\Job $job): void
    {
        foreach ($job->parts as $part) {
            if ($this->isConstraint($part)) {
                continue;
            }

            if (!preg_match('/^(' . self::IDENTIFIER . ')\s+(.+)$/s', $part, $match)) {
                continue;
            }

            $name = $this->unquote($match[1]);
            $field = $this->definitionToFieldConverter->convert($name, $match[2]);

            $job->blueprint->addField($field);

            // A primary key on the column itself
            if (preg_match('/\bprimary\s+key\b/i', $match[2])) {
                $job->blueprint->addIndex(new Index([$field], isPrimary: true, isUnique: true));
            }
        }
    }

    private function isConstraint(string $part): bool
    {
        return (bool) preg_match(
            '/^(?:constraint|primary\s+key|unique|check|foreign\s+key)\b/i',
            $part
        );
    }

    private function findPrimaryKey(TableStructureFinder\Job $job): void
    {
        foreach ($job->parts as $part) {
            if (!preg_match(
                '/^(?:constraint\s+' . self::IDENTIFIER . '\s+)?primary\s+key\s*\((.+)\)$/is',
                $part,
                $match
            )) {
                continue;
            }

            $index = new Index(isPrimary: true, isUnique: true);

            foreach ($this->getNames($match[1]) as $name) {
                $field = $job->blueprint->fieldByName($name);

                if ($field !== null) {
                    $index->addField($field);
                }
            }

            $job->blueprint->addIndex($index);
        }
    }

    // Unique constraints inside the create table statement, as written by older versions
    private function findUniqueConstraints(TableStructureFinder\Job $job): void
    {
        foreach ($job->parts as $part) {
            if (preg_match('/^(?:constraint\s+' . self::IDENTIFIER . '\s+)?unique\s*\((.+)\)$/is', $part, $match)) {
                $this->addIndex($job, $this->getNames($match[1]), true);
            }
        }
    }

    private function findIndexes(TableStructureFinder\Job $job): void
    {
        foreach ($job->createIndexes as $createIndex) {
            if (!preg_match(
                '/^create\s+(?<isUnique>unique\s+)?index\s+(?:if\s+not\s+exists\s+)?'
                    . self::IDENTIFIER
                    . '\s+on\s+'
                    . self::IDENTIFIER
                    . '\s*\((?<fields>.+)\)\s*(?:where\s.*)?$/is',
                trim($createIndex),
                $match
            )) {
                continue;
            }

            $this->addIndex($job, $this->getNames($match['fields']), $match['isUnique'] !== '');
        }
    }

    private function addIndex(TableStructureFinder\Job $job, array $names, bool $isUnique): void
    {
        $index = new Index(isUnique: $isUnique);

        foreach ($names as $name) {
            $field = $job->blueprint->fieldByName($name);

            if ($field !== null) {
                $index->addField($field);
            }
        }

        if (count($index->fields()) === 1) {
            if ($index->isUnique) {
                $index->fields()[0]->isUnique = true;
            }
            else {
                $index->fields()[0]->isIndex = true;
            }
        }

        $job->blueprint->addIndex($index);
    }

    private function getNames(string $nameString): array
    {
        return array_map(function (string $name): string {
            // Strip a sort order or collation after the name
            $name = preg_replace('/\s+(?:asc|desc|collate\s+\w+)\b.*$/is', '', trim($name));

            return $this->unquote($name);
        }, explode(',', $nameString));
    }

    private function findForeignKeys(TableStructureFinder\Job $job): void
    {
        $action = '(?:cascade|set null|set default|restrict|no action)';

        foreach ($job->parts as $part) {
            if (!preg_match(
                '/^(?:constraint\s+'
                    . self::IDENTIFIER
                    . '\s+)?foreign\s+key\s*\((?<field>[^)]+)\)\s*references\s+(?<table>'
                    . self::IDENTIFIER
                    . ')\s*\((?<reference>[^)]+)\)(?:\s+on\s+delete\s+(?<onDelete>'
                    . $action
                    . '))?(?:\s+on\s+update\s+(?<onUpdate>'
                    . $action
                    . '))?/is',
                $part,
                $match
            )) {
                continue;
            }

            $foreignKey = new ForeignKey(
                $this->unquote(trim($match['field'])),
                $this->unquote($match['table']),
                $this->unquote(trim($match['reference'])),
                $this->getAction($match['onDelete'] ?? ''),
                $this->getAction($match['onUpdate'] ?? ''),
            );

            $field = $job->blueprint->fieldByName($foreignKey->field);

            if ($field !== null) {
                $field->isIndex = false;
            }

            $job->blueprint->addForeignKey($foreignKey);
        }
    }

    private function unquote(string $identifier): string
    {
        $identifier = trim($identifier);

        return match ($identifier[0] ?? '') {
            '"' => str_replace('""', '"', substr($identifier, 1, -1)),
            '`', '[' => substr($identifier, 1, -1),
            default => $identifier,
        };
    }

    private function getAction(string $action): Action
    {
        // SQLite's default action
        if ($action === '') {
            return Action::NoAction;
        }

        return Action::tryFrom(strtolower(preg_replace(
            '/\s+/',
            ' ',
            $action
        ))) ?? throw new Exceptions\InvalidForeignKeyAction($action);
    }
}

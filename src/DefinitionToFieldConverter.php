<?php

declare(strict_types=1);

namespace Medas\PdoSqliteMigrationBuilder;

use Medas\Core\{Attributes\Service, Types\Integer};
use Medas\MigrationBuilder\Structure\Blueprint\Field;
use Medas\PdoStorage\Exceptions\{CantDetermineTypeFromDefinition, CantTurnDefinitionIntoVariable};
use Medas\StorageManager\Type;

/**
 * Reads back the column definitions written by FieldToDefinitionConverter. The plain
 * SQLite type names (integer, real, text, blob) of tables created otherwise are
 * understood as well.
 */
#[Service]
readonly class DefinitionToFieldConverter
{
    public function convert(string $name, string $definition): Field
    {
        $remainder = trim(preg_replace('/\s+/', ' ', $definition));
        $isNullable = true;
        $isGenerated = false;
        $hasDefault = false;
        $default = null;
        $isCreationTimestamp = false;
        $isModificationTimestamp = false;

        // Strip collation
        $remainder = preg_replace('/ collate \w+/i', '', $remainder);

        if ($this->chop($remainder, FieldToDefinitionConverter::MODIFICATION_TIMESTAMP_DEFINITION)) {
            $isModificationTimestamp = true;
        }
        elseif ($this->chop($remainder, FieldToDefinitionConverter::CREATION_TIMESTAMP_DEFINITION)) {
            $isCreationTimestamp = true;
        }

        if (preg_match('/ default (.+)$/i', $remainder, $match)) {
            $remainder = substr($remainder, 0, -strlen($match[0]));
            $default = $this->parseString($match[1]);
            $hasDefault = $default !== null;
        }

        $isPrimaryKey = (bool) preg_match(
            '/ primary key(?: (?:asc|desc))?(?: autoincrement)?/i',
            $remainder,
            $match
        );

        if ($isPrimaryKey) {
            $remainder = str_replace($match[0], '', $remainder);
            $isNullable = false;
        }

        if (preg_match('/ not null/i', $remainder, $match)) {
            $remainder = str_replace($match[0], '', $remainder);
            $isNullable = false;
        }

        $remainder = strtolower(trim($remainder));

        // "integer primary key" is an alias of the rowid, which SQLite generates
        if ($isPrimaryKey && $remainder === 'integer') {
            $isGenerated = true;
        }

        $field = new Field(
            name: $name,
            type: $this->determineType($remainder, $definition),
            isNullable: $isNullable,
            isGenerated: $isGenerated,
            isCreationTimestamp: $isCreationTimestamp,
            isModificationTimestamp: $isModificationTimestamp,
            hasDefault: $hasDefault,
            default: $default,
        );

        if ($field->type === Type::Integer) {
            $this->setValueRange($field, $remainder);
        }

        if ($field->type === Type::Text || $field->type === Type::Binary) {
            $this->setLengthRange($field, $remainder);
        }

        return $field;
    }

    private function chop(string &$remainder, string $suffix): bool
    {
        if (strcasecmp(substr($remainder, -strlen($suffix)), $suffix) !== 0) {
            return false;
        }

        $remainder = substr($remainder, 0, -strlen($suffix));

        return true;
    }

    private function parseString(string $string): string|int|float|null
    {
        $string = trim($string);

        if (strcasecmp($string, 'null') === 0) {
            return null;
        }

        if (preg_match('/^-?\d+$/', $string)) {
            return (int) $string;
        }

        if (is_numeric($string)) {
            return (float) $string;
        }

        if (strlen($string) >= 2 && $string[0] === "'" && $string[-1] === "'") {
            return str_replace("''", "'", substr($string, 1, -1));
        }

        throw new CantTurnDefinitionIntoVariable($string);
    }

    private function determineType(string $remainder, string $definition): Type
    {
        return match (true) {
            (bool) preg_match('/^(?:tiny|small|medium|big)?int(?:eger)?(?:\(\d+\))?(?: unsigned)?$/', $remainder)
                => Type::Integer,

            (bool) preg_match('/^(?:var)?char\(\d+\)$/', $remainder), in_array($remainder, ['text', 'tinytext', 'mediumtext', 'longtext', 'clob'], true)
                => Type::Text,

            (bool) preg_match('/^(?:var)?binary blob\(\d+\)$/', $remainder), in_array($remainder, ['blob', 'mediumblob', 'longblob'], true)
                => Type::Binary,

            $remainder === 'datetime' => Type::DateTime,
            $remainder === 'date' => Type::Date,
            in_array($remainder, ['double', 'float', 'real'], true) => Type::Float,
            default => throw new CantDetermineTypeFromDefinition($remainder, $definition),
        };
    }

    private function setValueRange(Field $field, string $remainder): void
    {
        // The rowid is a signed 64 bit integer. Its upper bound is stretched to the
        // unsigned one, so that an id declared as unsigned does not count as a change
        // on every comparison.
        if ($field->isGenerated) {
            $field->minValue = Integer::SIGNED_8_BYTE_MIN;
            $field->maxValue = Integer::UNSIGNED_8_BYTE_MAX;

            return;
        }

        preg_match(
            '/^((?:tiny|small|medium|big)?int)(eger)?(?:\(\d+\))?( unsigned)?$/',
            $remainder,
            $match
        );

        // Plain "integer" columns can hold any 64 bit value
        if (($match[2] ?? '') !== '') {
            $field->minValue = Integer::SIGNED_8_BYTE_MIN;
            $field->maxValue = Integer::SIGNED_8_BYTE_MAX;

            return;
        }

        if (($match[3] ?? '') !== '') {
            $field->minValue = 0;
            $field->maxValue = match ($match[1]) {
                'tinyint' => Integer::UNSIGNED_1_BYTE_MAX,
                'smallint' => Integer::UNSIGNED_2_BYTE_MAX,
                'mediumint' => Integer::UNSIGNED_3_BYTE_MAX,
                'int' => Integer::UNSIGNED_4_BYTE_MAX,
                default => Integer::UNSIGNED_8_BYTE_MAX,
            };

            return;
        }

        $field->minValue = match ($match[1]) {
            'tinyint' => Integer::SIGNED_1_BYTE_MIN,
            'smallint' => Integer::SIGNED_2_BYTE_MIN,
            'mediumint' => Integer::SIGNED_3_BYTE_MIN,
            'int' => Integer::SIGNED_4_BYTE_MIN,
            default => Integer::SIGNED_8_BYTE_MIN,
        };

        $field->maxValue = match ($match[1]) {
            'tinyint' => Integer::SIGNED_1_BYTE_MAX,
            'smallint' => Integer::SIGNED_2_BYTE_MAX,
            'mediumint' => Integer::SIGNED_3_BYTE_MAX,
            'int' => Integer::SIGNED_4_BYTE_MAX,
            default => Integer::SIGNED_8_BYTE_MAX,
        };
    }

    private function setLengthRange(Field $field, string $remainder): void
    {
        // Fixed and variable length types up to 255 bytes declare their length
        if (preg_match('/^(var)?(?:char|binary blob)\((\d+)\)$/', $remainder, $match)) {
            $field->maxLength = (int) $match[2];
            $field->minLength = $match[1] === '' ? $field->maxLength : 0;

            return;
        }

        $field->minLength = 0;
        $field->maxLength = match ($remainder) {
            'tinytext' => Integer::UNSIGNED_1_BYTE_MAX,
            'mediumtext', 'mediumblob' => Integer::UNSIGNED_3_BYTE_MAX,
            'longtext', 'longblob' => Integer::UNSIGNED_4_BYTE_MAX,
            default => Integer::UNSIGNED_2_BYTE_MAX,
        };
    }
}

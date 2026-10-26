<?php

declare(strict_types=1);

namespace Medas\PdoSqliteMigrationBuilder;

use Medas\Core\{Attributes\Service, Types\Integer};
use Medas\MigrationBuilder\Structure\Blueprint\Field;
use Medas\StorageManager\Type;

/**
 * SQLite accepts any type name and only derives a column affinity from it, so the
 * declared names here carry the exact field bounds (for DefinitionToFieldConverter to
 * read back) while still resolving to the intended affinity:
 * - integer and boolean names contain "int" (INTEGER affinity)
 * - text names contain "char" or "text" (TEXT affinity)
 * - binary names contain "blob" (BLOB affinity, values are stored as given)
 * - "double" has REAL affinity
 * - "date" and "datetime" have NUMERIC affinity; ISO 8601 strings are not numeric
 *   literals and are therefore kept as text
 */
#[Service]
readonly class TypeHandler
{
    public function getBaseDefinition(Field $field): string|null
    {
        return match ($field->type) {
            Type::Binary => $this->binary($field),

            // Booleans are stored as 0/1 integers
            Type::Boolean => 'tinyint unsigned',

            // Collections are handled via join tables; no column is emitted
            Type::Collection => null,
            Type::Date => 'date',
            Type::DateTime => 'datetime',
            Type::Float => 'double',
            Type::Integer => $this->integer($field),
            Type::Text => $this->text($field),
        };
    }

    private function binary(Field $field): string
    {
        /** @noinspection PhpDuplicateMatchArmBodyInspection */
        return match (true) {
            $field->maxLength <= Integer::UNSIGNED_1_BYTE_MAX
                => $field->minLength === $field->maxLength
                    ? sprintf('binary blob(%u)', $field->maxLength)
                    : sprintf('varbinary blob(%u)', $field->maxLength),

            $field->maxLength <= Integer::UNSIGNED_2_BYTE_MAX => 'blob',
            $field->maxLength <= Integer::UNSIGNED_3_BYTE_MAX => 'mediumblob',
            $field->maxLength <= Integer::UNSIGNED_4_BYTE_MAX => 'longblob',
            default => 'blob',
        };
    }

    private function integer(Field $field): string
    {
        return match (true) {
            $field->minValue >= 0 && $field->maxValue <= Integer::UNSIGNED_1_BYTE_MAX
                => 'tinyint unsigned',

            $field->minValue >= 0 && $field->maxValue <= Integer::UNSIGNED_2_BYTE_MAX
                => 'smallint unsigned',

            $field->minValue >= 0 && $field->maxValue <= Integer::UNSIGNED_3_BYTE_MAX
                => 'mediumint unsigned',

            $field->minValue >= 0 && $field->maxValue <= Integer::UNSIGNED_4_BYTE_MAX
                => 'int unsigned',

            $field->minValue >= 0 && $field->maxValue <= Integer::UNSIGNED_8_BYTE_MAX
                => 'bigint unsigned',

            $field->minValue >= Integer::SIGNED_1_BYTE_MIN && $field->maxValue <= Integer::SIGNED_1_BYTE_MAX
                => 'tinyint',

            $field->minValue >= Integer::SIGNED_2_BYTE_MIN && $field->maxValue <= Integer::SIGNED_2_BYTE_MAX
                => 'smallint',

            $field->minValue >= Integer::SIGNED_3_BYTE_MIN && $field->maxValue <= Integer::SIGNED_3_BYTE_MAX
                => 'mediumint',

            $field->minValue >= Integer::SIGNED_4_BYTE_MIN && $field->maxValue <= Integer::SIGNED_4_BYTE_MAX
                => 'int',

            $field->minValue >= Integer::SIGNED_8_BYTE_MIN && $field->maxValue <= Integer::SIGNED_8_BYTE_MAX
                => 'bigint',

            default => throw new Exceptions\FieldValuesOutOfBounds($field),
        };
    }

    private function text(Field $field): string
    {
        /** @noinspection PhpDuplicateMatchArmBodyInspection */
        return match (true) {
            $field->maxLength <= Integer::UNSIGNED_1_BYTE_MAX
                => $field->minLength === $field->maxLength
                    ? sprintf('char(%u)', $field->maxLength)
                    : sprintf('varchar(%u)', $field->maxLength),

            $field->maxLength <= Integer::UNSIGNED_2_BYTE_MAX => 'text',
            $field->maxLength <= Integer::UNSIGNED_3_BYTE_MAX => 'mediumtext',
            $field->maxLength <= Integer::UNSIGNED_4_BYTE_MAX => 'longtext',
            default => 'text',
        };
    }
}

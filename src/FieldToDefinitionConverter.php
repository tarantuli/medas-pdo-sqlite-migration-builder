<?php

declare(strict_types=1);

namespace Medas\PdoSqliteMigrationBuilder;

use Medas\Core\Attributes\Service;
use Medas\MigrationBuilder\Structure\Blueprint\Field;
use Medas\PdoStorage\{Database, PdoStorageController};

#[Service]
readonly class FieldToDefinitionConverter
{
    // An auto-increment primary key must be declared exactly like this to become an alias of the rowid
    const string GENERATED_DEFINITION = 'integer primary key';
    const string CREATION_TIMESTAMP_DEFINITION = ' default current_timestamp';

    // SQLite has no "on update" clause; the entity persister sets modification timestamps
    // itself. The comment is kept verbatim in sqlite_schema, which lets the table structure
    // finder tell both kinds of timestamps apart.
    const string MODIFICATION_TIMESTAMP_DEFINITION = ' default current_timestamp /* on update current_timestamp */';

    public function __construct(
        private PdoStorageController $pdoStorageController,
        private TypeHandler          $typeHandler,
    )
    {
    }

    public function buildDefinition(Database $database, Field $field): string|null
    {
        if ($field->isGenerated) {
            return self::GENERATED_DEFINITION;
        }

        $baseDefinition = $this->typeHandler->getBaseDefinition($field);

        if ($baseDefinition === null) {
            return null;
        }

        if ($field->isModificationTimestamp) {
            $default = self::MODIFICATION_TIMESTAMP_DEFINITION;
        }
        elseif ($field->isCreationTimestamp) {
            $default = self::CREATION_TIMESTAMP_DEFINITION;
        }
        elseif ($field->hasDefault) {
            $driverHandler = $this->pdoStorageController->getDatabaseController($database)->driverHandler;
            $serializedDefault = $driverHandler->serializer()->serialize($field->default);
            $default = ' default ' . $driverHandler->escape($database, $serializedDefault);
        }
        else {
            $default = '';
        }

        return $baseDefinition . ($field->isNullable ? '' : ' not null') . $default;
    }

    // Whether "alter table ... add column" accepts the field; anything else needs a table rebuild
    public function canBeAdded(Field $field): bool
    {
        // Primary keys cannot be added, and neither can non-constant defaults
        if ($field->isGenerated || $field->isCreationTimestamp || $field->isModificationTimestamp) {
            return false;
        }

        // A not null column needs a non-null default to fill the existing rows
        return $field->isNullable || ($field->hasDefault && $field->default !== null);
    }
}

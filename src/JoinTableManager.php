<?php

declare(strict_types=1);

namespace Medas\PdoSqliteMigrationBuilder;

use Medas\Core\Attributes\{ConfigValue, Service};
use Medas\EntityManager\Attributes\Relations\Action;
use Medas\MigrationBuilder\{BuilderResolver, Structure\Blueprint};
use Medas\PdoStorage\ConfigOptions\JoinTables\TableNamingStrategy;
use Medas\PdoStorage\Database;
use Medas\PdoStorage\JoinTables\NamingStrategy;
use Medas\StorageManager\Type;

#[Service]
readonly class JoinTableManager
{
    public function __construct(
        #[ConfigValue(TableNamingStrategy::class)]
        private NamingStrategy  $namingStrategy,
        private BuilderResolver $builderResolver,
    )
    {
    }

    public function createQueries(
        Database        $database,
        Blueprint       $sourceBlueprint,
        Blueprint\Field $field,
        bool            $ignoreExistingStructure = false
    ): iterable|null
    {
        if ($sourceBlueprint->primaryIndex() === null) {
            return null;
        }

        $idField = $this->getIdField($sourceBlueprint);

        $idForeignKey = new Blueprint\ForeignKey(
            'id',
            $sourceBlueprint->name,
            $sourceBlueprint->primaryIndex()->fields()[0]->name,
            Action::Cascade,
            Action::Cascade
        );

        $valueField = $this->getValueField($field);

        $valueForeignKey = new Blueprint\ForeignKey(
            'value',
            $field->collectionStore,
            $field->collectionField->name,
            Action::Cascade,
            Action::Cascade
        );

        $orderField = new Blueprint\Field(
            name: 'order',
            type: Type::Integer,
            hasDefault: true,
            default: 0
        );

        $primaryIndex = new Blueprint\Index([$idField, $valueField], true);
        $searchIndex = new Blueprint\Index([$idField, $orderField]);
        $joinBlueprint = new Blueprint();

        $joinBlueprint->name = $this->namingStrategy->determine(
            $sourceBlueprint->name,
            $field->name
        );

        $joinBlueprint->storeOriginalClass = false;

        $joinBlueprint
            ->addField($idField)
            ->addField($valueField)
            ->addField($orderField)
            ->addIndex($primaryIndex)
            ->addIndex($searchIndex)
            ->addForeignKey($idForeignKey)
            ->addForeignKey($valueForeignKey);

        return $this->builderResolver->find($database)
            ->buildActions($database, $joinBlueprint, $ignoreExistingStructure);
    }

    private function getIdField(Blueprint $sourceBlueprint): Blueprint\Field
    {
        $idField = clone $sourceBlueprint->primaryIndex()->fields()[0];

        $idField->name = 'id';
        $idField->isGenerated = false;

        return $idField;
    }

    private function getValueField(Blueprint\Field $field): Blueprint\Field
    {
        $valueField = clone $field->collectionField;

        $valueField->name = 'value';
        $valueField->isGenerated = false;

        return $valueField;
    }
}

<?php

declare(strict_types=1);

namespace Medas\PdoSqliteMigrationBuilder;

use Medas\Core\{AsSingleton, BasePackage};
use Medas\MigrationBuilder\MigrationBuilderPackage;

class PdoSqliteMigrationBuilderPackage extends BasePackage
{
    use AsSingleton;

    public function dependencies(): array
    {
        return [
            MigrationBuilderPackage::instance(),
        ];
    }

    public function sourceDirectory(): string
    {
        return __DIR__;
    }
}

# medas-pdo-sqlite-migration-builder

Part of the [Medas framework](https://github.com/tarantuli/medas-core).

## Description

The SQLite driver for `medas-migration-builder`. Implements the `MigrationBuilder` interface for `Database` storages with an `sqlite:` DSN, generating `CREATE TABLE`, `CREATE INDEX` and `ALTER TABLE` SQL statements from entity `Blueprint` definitions. The runtime SQLite driver lives in `medas-pdo-sqlite`.

When `MakeMigrationCommand` processes an entity backed by a SQLite `Database`, this builder:

1. Reads the current table structure from `sqlite_schema` via `TableStructureFinder`
2. Runs `ChangeFinder` to diff it against the entity's `Blueprint`
3. If the table doesn't exist: emits `CREATE TABLE` and `CREATE INDEX` statements via `CreateTableBuilder`
4. If the table exists but differs: emits the statements to bring it up to date via `AlterTableBuilder`
5. If the table matches the blueprint: returns `false` (no migration needed)

**Column types** — SQLite accepts any type name and only derives a column affinity from it. The declared names mirror the MySQL builder, so the field bounds can be read back, while resolving to the intended affinity:

| Framework type | Declared type | Affinity |
|---|---|---|
| `Integer` | `tinyint unsigned` … `bigint` (by value range) | INTEGER |
| `Integer`, generated | `integer primary key` (rowid alias) | INTEGER |
| `Boolean` | `tinyint unsigned` | INTEGER |
| `Float` | `double` | REAL |
| `Text` | `char(n)`, `varchar(n)`, `text`, `mediumtext`, `longtext` | TEXT |
| `Binary` | `binary blob(n)`, `varbinary blob(n)`, `blob`, `mediumblob`, `longblob` | BLOB |
| `Date`, `DateTime` | `date`, `datetime` (ISO 8601 text) | NUMERIC |
| `Collection` | — (join table) | |

**Constraints** — the primary key and foreign keys are part of the `CREATE TABLE` statement; unique and regular indexes are separate `CREATE [UNIQUE] INDEX` statements whose names include the table name (index names are unique per database in SQLite).

**Timestamps** — creation and modification timestamps get `default current_timestamp`. SQLite has no `on update` clause; the entity persister sets modification timestamps itself. A `/* on update current_timestamp */` comment marks modification timestamps in the stored schema.

**Altering tables** — SQLite's `ALTER TABLE` can only add columns. Added nullable or constant-default columns and added indexes are applied in place. Any other change (changed columns, foreign keys, primary keys, timestamp columns) rebuilds the table: the new table is created under a temporary name, the rows are copied, the old table is dropped, and the new one is renamed. Review generated migrations before running them in production.

## Usage

Register the package next to `medas-pdo-sqlite`; the builder is discovered automatically by `BuilderResolver`:

```php
use Medas\PdoSqliteMigrationBuilder\PdoSqliteMigrationBuilderPackage;

PdoSqliteMigrationBuilderPackage::instance();
```

```bash
php bin/medas migration-builder:make-migration
php bin/medas storage-manager:migrate
```

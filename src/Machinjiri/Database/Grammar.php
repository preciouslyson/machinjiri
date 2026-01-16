<?php

namespace Mlangeni\Machinjiri\Core\Database;

abstract class Grammar
{
    protected string $tablePrefix = '';

    public function setTablePrefix(string $prefix): void
    {
        $this->tablePrefix = $prefix;
    }

    public function wrapTable(string $table): string
    {
        return $table;
    }

    public function wrapColumn(string $column): string
    {
        return $column;
    }

    public function compileCreateTable(string $table, array $columns, array $options = []): string
    {
        $definitions = [];
        
        foreach ($columns as $name => $definition) {
            $definitions[] = is_string($name) ? "$name $definition" : $definition;
        }
        
        $columns = implode(', ', $definitions);
        return "CREATE TABLE IF NOT EXISTS " . $this->wrapTable($table) . " ($columns)";
    }

    abstract public function compileAutoIncrement(): string;
    abstract public function compileColumnType(string $type, array $parameters = []) : string;
    
    public function compileAlterTable(
        string $table, 
        array $addedColumns, 
        array $modifiedColumns, 
        array $droppedColumns
    ): string {
        $sql = "ALTER TABLE {$this->wrapTable($table)}";
        $actions = [];

        foreach ($addedColumns as $name => $builder) {
            $actions[] = "ADD COLUMN {$builder}";
        }

        foreach ($modifiedColumns as $name => $builder) {
            $actions[] = "ALTER COLUMN {$builder}";
        }

        foreach ($droppedColumns as $name) {
            $actions[] = "DROP COLUMN {$this->wrapColumn($name)}";
        }

        return $sql . ' ' . implode(', ', $actions);
    }

    public function compileDropTable(string $table): string
    {
        return "DROP TABLE {$this->wrapTable($table)}";
    }

    public function compileTruncateTable(string $table): string
    {
        return "TRUNCATE TABLE {$this->wrapTable($table)}";
    }
}

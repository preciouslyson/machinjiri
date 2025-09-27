<?php

namespace Mlangeni\Machinjiri\Core\Database;

class PostgresGrammar extends Grammar
{
    public function compileAutoIncrement(): string
    {
        return '';
    }

    public function compileColumnType(string $type, array $parameters = []): string
    {
        $type = strtoupper($type);
        
        return match ($type) {
            'INTEGER', 'INT' => $this->compileIntegerType($parameters),
            'TINYINT' => $this->compileTinyIntType($parameters),
            'STRING' => $this->compileStringType($parameters),
            'TEXT' => 'TEXT',
            'FLOAT' => $this->compileFloatType($parameters),
            'DECIMAL' => $this->compileDecimalType($parameters),
            'DATE' => 'DATE',
            'DATETIME' => 'TIMESTAMP',
            'TIMESTAMP' => 'TIMESTAMP',
            default => $type
        };
    }

    public function wrapTable(string $table): string
    {
        return "\"{$this->tablePrefix}{$table}\"";
    }

    public function wrapColumn(string $column): string
    {
        return "\"{$column}\"";
    }

    protected function compileIntegerType(array $parameters): string
    {
        return 'INTEGER';
    }

    protected function compileTinyIntType(array $parameters): string
    {
        return 'SMALLINT';
    }

    protected function compileStringType(array $parameters): string
    {
        $length = $parameters[0] ?? 255;
        return "VARCHAR({$length})";
    }

    protected function compileFloatType(array $parameters): string
    {
        return 'REAL';
    }

    protected function compileDecimalType(array $parameters): string
    {
        if (!empty($parameters)) {
            return 'NUMERIC(' . implode(',', $parameters) . ')';
        }
        return 'NUMERIC';
    }

    public function compileCreateTable(string $table, array $columns, array $options = []): string
    {
        $sql = parent::compileCreateTable($table, $columns);
        
        if (!empty($options)) {
            $optionStrings = [];
            foreach ($options as $key => $value) {
                if ($key === 'engine') {
                    continue;
                }
                $optionStrings[] = strtoupper($key) . '=' . $value;
            }
            if (!empty($optionStrings)) {
                $sql .= ' ' . implode(' ', $optionStrings);
            }
        }
        
        return $sql;
    }

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
}
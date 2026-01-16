<?php

namespace Mlangeni\Machinjiri\Core\Database;
use Mlangeni\Machinjiri\Core\Database\Grammar;
class MySqlGrammar extends Grammar
{
    public function compileAutoIncrement(): string
    {
        return 'AUTO_INCREMENT';
    }

    public function compileColumnType(string $type, array $parameters = []): string
    {
        $type = strtoupper($type);
        
        return match ($type) {
            'INTEGER' => $this->compileIntegerType($parameters),
            'INT' => $this->compileIntegerType($parameters),
            'TINYINT' => $this->compileTinyIntType($parameters),
            'STRING' => $this->compileStringType($parameters),
            'TEXT' => 'TEXT',
            'FLOAT' => $this->compileFloatType($parameters),
            'DECIMAL' => $this->compileDecimalType($parameters),
            'DATE' => 'DATE',
            'DATETIME' => 'DATETIME',
            'TIMESTAMP' => 'TIMESTAMP',
            default => $type
        };
    }

    public function wrapTable(string $table): string
    {
        return "{$this->tablePrefix}{$table}";
    }

    public function wrapColumn(string $column): string
    {
        return "{$column}";
    }

    protected function compileIntegerType(array $parameters): string
    {
        $length = $parameters[0] ?? 11;
        return $length == 11 ? "INTEGER" : "INT({$length})";
    }

    protected function compileTinyIntType(array $parameters): string
    {
        $length = $parameters[0] ?? 1;
        return "TINYINT({$length})";
    }

    protected function compileStringType(array $parameters): string
    {
        $length = $parameters[0] ?? 255;
        return "VARCHAR({$length})";
    }

    protected function compileFloatType(array $parameters): string
    {
        if (!empty($parameters)) {
            return 'FLOAT(' . implode(',', $parameters) . ')';
        }
        return 'FLOAT';
    }

    protected function compileDecimalType(array $parameters): string
    {
        if (!empty($parameters)) {
            return 'DECIMAL(' . implode(',', $parameters) . ')';
        }
        return 'DECIMAL';
    }

    public function compileCreateTable(string $table, array $columns, array $options = []): string
    {
        $sql = parent::compileCreateTable($table, $columns);
        
        if (!empty($options)) {
            $optionStrings = [];
            foreach ($options as $key => $value) {
                $optionStrings[] = strtoupper($key) . '=' . $value;
            }
            $sql .= ' ' . implode(' ', $optionStrings);
        }
        
        return $sql;
    }
}
<?php

namespace Mlangeni\Machinjiri\Core\Database;

class SQLiteGrammar extends Grammar
{
    public function compileAutoIncrement(): string
    {
        return 'AUTOINCREMENT';
    }

    public function compileColumnType(string $type, array $parameters = []): string
    {
        $type = strtoupper($type);
        
        return match ($type) {
            'INTEGER', 'INT' => 'INTEGER',
            'TINYINT' => 'INTEGER',
            'BOOLEAN' => 'INTEGER',
            'STRING' => $this->compileStringType($parameters),
            'TEXT' => 'TEXT',
            'FLOAT', 'DECIMAL' => 'REAL',
            'DATE' => 'TEXT',
            'DATETIME', 'TIMESTAMP' => 'TEXT',
            default => $type
        };
    }

    protected function compileStringType(array $parameters): string
    {
        $length = $parameters[0] ?? 255;
        return "VARCHAR({$length})";
    }

    public function compileCreateTable(string $table, array $columns, array $options = []): string
    {
        $sql = parent::compileCreateTable($table, $columns);
        // SQLite ignores most table options
        return $sql;
    }
}
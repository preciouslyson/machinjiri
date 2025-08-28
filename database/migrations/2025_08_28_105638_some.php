<?php

use Mlangeni\Machinjiri\Core\Database\QueryBuilder;

class Some
{
    /**
     * Run the migration
     */
    public function up(QueryBuilder $query): void
    {
        // Implement your migration here
        // Example: 
        // $query->createTable('table', [
        // $query->id()->autoIncrement()->primaryKey(),
        // $query->string('column', 255)->notNull()
        // ]);
    }

    /**
     * Reverse the migration
     */
    public function down(QueryBuilder $query): void
    {
        // Implement rollback here
        // Example: 
        // $query-->dropTable('temp_data');
    }
}
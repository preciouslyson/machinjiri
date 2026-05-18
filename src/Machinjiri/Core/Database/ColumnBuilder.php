<?php

namespace Mlangeni\Machinjiri\Core\Database;

class ColumnBuilder
{
  protected string $definition;
  protected Grammar $grammar;

  public function __construct(string $column, string $type, Grammar $grammar)
    {
        $this->grammar = $grammar;
        $wrappedColumn = $grammar->wrapColumn($column);
        $this->definition = "{$wrappedColumn} {$type}";
    }

  public function nullable(): self
  {
      $this->definition .= ' NULL';
      return $this;
  }
  
  public function reference (string $table, string $column) : self {
    $this->definition .= ' REFERENCES ' . $table . '(' . $column . ')';
    return $this;
  }

  public function notNull(): self
  {
      $this->definition .= ' NOT NULL';
      return $this;
  }

  public function default($value): self
  {
    if ($value !== "CURRENT_TIMESTAMP") {
      $this->definition .= " DEFAULT " . (is_string($value) ? "'$value'" : $value);
    } else {
      $this->definition .= " DEFAULT  CURRENT_TIMESTAMP";    
      
    }
      
      return $this;
  }


  public function unique(): self
  {
      $this->definition .= ' UNIQUE';
      return $this;
  }

  public function unsigned(): self
  {
      $this->definition .= ' UNSIGNED';
      return $this;
  }

  public function primaryKey(): self
  {
      $this->definition .= ' PRIMARY KEY';
      return $this;
  }

  public function comment(string $text): self
  {
      $this->definition .= " COMMENT '$text'";
      return $this;
  }

  public function __toString(): string
  {
      return $this->definition;
  }
  
  public function autoIncrement(): self
    {
        $increment = $this->grammar->compileAutoIncrement();
        if ($increment) {
            $this->definition .= ' ' . $increment;
        }
        return $this;
    }
    
    public function foreignKey(string $references, string $on = 'id', string $onDelete = 'RESTRICT', string $onUpdate = 'RESTRICT'): self
    {
        $this->definition .= " REFERENCES {$references}({$on}) ON DELETE {$onDelete} ON UPDATE {$onUpdate}";
        return $this;
    }
    
}


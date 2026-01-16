<?php

namespace Mlangeni\Machinjiri\Components\Layout;

use Mlangeni\Machinjiri\Components\Base\ComponentBase;
use Mlangeni\Machinjiri\Components\Base\ComponentTrait;

class Grid extends ComponentBase
{
    use ComponentTrait;

    private array $rows = [];
    private bool $gutters = true;
    private string $gutterSize = 'g-4'; // g-0, g-1, g-2, g-3, g-4, g-5
    private bool $verticalGutters = true;
    private string $verticalGutterSize = 'gy-4'; // gy-0, gy-1, gy-2, gy-3, gy-4, gy-5
    private bool $horizontalGutters = true;
    private string $horizontalGutterSize = 'gx-4'; // gx-0, gx-1, gx-2, gx-3, gx-4, gx-5

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->addClass('container');
    }

    public function addRow(array $columns = [], array $rowAttributes = []): self
    {
        $row = new Row($rowAttributes);
        
        // Apply gutter settings to the row
        if (!$this->gutters) {
            $row->noGutters();
        } elseif ($this->gutterSize !== 'g-4') {
            $row->gutterSize($this->gutterSize);
        } else {
            if ($this->verticalGutters && $this->verticalGutterSize) {
                $row->addClass($this->verticalGutterSize);
            }
            if ($this->horizontalGutters && $this->horizontalGutterSize) {
                $row->addClass($this->horizontalGutterSize);
            }
        }
        
        foreach ($columns as $column) {
            $row->addColumn($column);
        }
        
        $this->rows[] = $row;
        return $this;
    }

    public function addRows(array $rows): self
    {
        foreach ($rows as $rowData) {
            $this->addRow($rowData['columns'] ?? [], $rowData['attributes'] ?? []);
        }
        return $this;
    }

    public function noGutters(bool $noGutters = true): self
    {
        $this->gutters = !$noGutters;
        return $this;
    }

    public function gutterSize(string $size): self
    {
        $this->gutterSize = $size;
        return $this;
    }

    public function verticalGutterSize(string $size): self
    {
        $this->verticalGutterSize = $size;
        $this->verticalGutters = true;
        return $this;
    }

    public function horizontalGutterSize(string $size): self
    {
        $this->horizontalGutterSize = $size;
        $this->horizontalGutters = true;
        return $this;
    }

    public function verticalGutters(bool $enabled = true): self
    {
        $this->verticalGutters = $enabled;
        return $this;
    }

    public function horizontalGutters(bool $enabled = true): self
    {
        $this->horizontalGutters = $enabled;
        return $this;
    }

    public function render(): string
    {
        $rowsHtml = array_map(function($row) {
            return $row->render();
        }, $this->rows);
        
        $content = implode("\n", $rowsHtml);
        return $this->renderElement('div', $content);
    }
}

class Row extends ComponentBase
{
    use ComponentTrait;

    private array $columns = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->addClass('row');
    }

    public function addColumn($column): self
    {
        if (is_string($column)) {
            $col = new Column();
            $col->addContent($column);
            $this->columns[] = $col;
        } elseif ($column instanceof Column) {
            $this->columns[] = $column;
        } elseif (is_array($column)) {
            $col = new Column();
            
            if (isset($column['content'])) {
                $col->addContent($column['content']);
            }
            
            if (isset($column['size'])) {
                $col->size($column['size']);
            }
            
            if (isset($column['breakpoints'])) {
                foreach ($column['breakpoints'] as $breakpoint => $size) {
                    $col->breakpoint($breakpoint, $size);
                }
            }
            
            if (isset($column['offset'])) {
                $col->offset($column['offset']);
            }
            
            if (isset($column['order'])) {
                $col->order($column['order']);
            }
            
            if (isset($column['attributes'])) {
                $col->setAttributes($column['attributes']);
            }
            
            $this->columns[] = $col;
        }
        
        return $this;
    }

    public function addColumns(array $columns): self
    {
        foreach ($columns as $column) {
            $this->addColumn($column);
        }
        return $this;
    }

    public function noGutters(): self
    {
        $this->addClass('g-0');
        return $this;
    }

    public function gutterSize(string $size): self
    {
        $this->addClass($size);
        return $this;
    }

    public function alignItems(string $alignment): self
    {
        $this->addClass('align-items-' . $alignment);
        return $this;
    }

    public function justifyContent(string $justification): self
    {
        $this->addClass('justify-content-' . $justification);
        return $this;
    }

    public function render(): string
    {
        $columnsHtml = array_map(function($column) {
            return $column->render();
        }, $this->columns);
        
        $content = implode("\n", $columnsHtml);
        return $this->renderElement('div', $content);
    }
}

class Column extends ComponentBase
{
    use ComponentTrait;

    private array $breakpoints = [];
    private array $offsets = [];
    private array $orders = [];
    private array $content = [];
    private string $size = ''; // Default size (1-12)

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->addClass('col');
    }

    public function size(string $size): self
    {
        $this->size = $size;
        
        // Remove existing size classes
        $pattern = '/^col(-\w+)?$/';
        $this->classes = array_filter($this->classes, function($class) use ($pattern) {
            return !preg_match($pattern, $class);
        });
        
        if ($size === 'auto') {
            $this->addClass('col-auto');
        } elseif (is_numeric($size) && $size >= 1 && $size <= 12) {
            $this->addClass('col-' . $size);
        } else {
            $this->addClass('col'); // Default
        }
        
        return $this;
    }

    public function auto(): self
    {
        return $this->size('auto');
    }

    public function breakpoint(string $breakpoint, string $size): self
    {
        $this->breakpoints[$breakpoint] = $size;
        
        // Remove existing breakpoint class for this breakpoint
        $pattern = '/^col-' . $breakpoint . '(-\d+|)$/';
        $this->classes = array_filter($this->classes, function($class) use ($pattern) {
            return !preg_match($pattern, $class);
        });
        
        if ($size === 'auto') {
            $this->addClass('col-' . $breakpoint . '-auto');
        } elseif (is_numeric($size) && $size >= 1 && $size <= 12) {
            $this->addClass('col-' . $breakpoint . '-' . $size);
        } else {
            $this->addClass('col-' . $breakpoint);
        }
        
        return $this;
    }

    public function offset(string $size): self
    {
        $this->offsets['default'] = $size;
        
        // Remove existing offset classes
        $this->classes = array_filter($this->classes, function($class) {
            return !str_starts_with($class, 'offset-');
        });
        
        if ($size === '0') {
            $this->addClass('offset-0');
        } elseif (is_numeric($size) && $size >= 1 && $size <= 11) {
            $this->addClass('offset-' . $size);
        }
        
        return $this;
    }

    public function offsetBreakpoint(string $breakpoint, string $size): self
    {
        $this->offsets[$breakpoint] = $size;
        
        // Remove existing offset class for this breakpoint
        $pattern = '/^offset-' . $breakpoint . '-\d+$/';
        $this->classes = array_filter($this->classes, function($class) use ($pattern) {
            return !preg_match($pattern, $class);
        });
        
        if (is_numeric($size) && $size >= 0 && $size <= 11) {
            $this->addClass('offset-' . $breakpoint . '-' . $size);
        }
        
        return $this;
    }

    public function order(string $order): self
    {
        $this->orders['default'] = $order;
        
        // Remove existing order classes
        $this->classes = array_filter($this->classes, function($class) {
            return !str_starts_with($class, 'order-');
        });
        
        if (is_numeric($order) && $order >= 1 && $order <= 12) {
            $this->addClass('order-' . $order);
        } elseif (in_array($order, ['first', 'last'])) {
            $this->addClass('order-' . $order);
        }
        
        return $this;
    }

    public function orderBreakpoint(string $breakpoint, string $order): self
    {
        $this->orders[$breakpoint] = $order;
        
        // Remove existing order class for this breakpoint
        $pattern = '/^order-' . $breakpoint . '(-\d+|)$/';
        $this->classes = array_filter($this->classes, function($class) use ($pattern) {
            return !preg_match($pattern, $class);
        });
        
        if (is_numeric($order) && $order >= 1 && $order <= 12) {
            $this->addClass('order-' . $breakpoint . '-' . $order);
        } elseif (in_array($order, ['first', 'last'])) {
            $this->addClass('order-' . $breakpoint . '-' . $order);
        }
        
        return $this;
    }

    public function addContent(string $content): self
    {
        $this->content[] = $content;
        return $this;
    }

    public function addContents(array $contents): self
    {
        $this->content = array_merge($this->content, $contents);
        return $this;
    }

    public function setContent(array $content): self
    {
        $this->content = $content;
        return $this;
    }

    public function alignSelf(string $alignment): self
    {
        $this->addClass('align-self-' . $alignment);
        return $this;
    }

    public function render(): string
    {
        $content = implode("\n", $this->content);
        return $this->renderElement('div', $content);
    }
}
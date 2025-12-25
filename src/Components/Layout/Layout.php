<?php

namespace Mlangeni\Machinjiri\Components\Layout;

class Layout
{
    private Container $container;
    private Grid $grid;

    public function __construct()
    {
        $this->container = new Container();
        $this->grid = new Grid();
    }

    /**
     * Create a responsive container
     */
    public static function container(array $attributes = []): Container
    {
        return new Container($attributes);
    }

    /**
     * Create a fluid container
     */
    public static function fluidContainer(array $attributes = []): Container
    {
        return (new Container($attributes))->fluid();
    }

    /**
     * Create a responsive container with breakpoint
     */
    public static function responsiveContainer(string $breakpoint, array $attributes = []): Container
    {
        return (new Container($attributes))->responsive($breakpoint);
    }

    /**
     * Create a grid layout
     */
    public static function grid(array $attributes = []): Grid
    {
        return new Grid($attributes);
    }

    /**
     * Create a single row with columns
     */
    public static function row(array $columns = [], array $attributes = []): Row
    {
        $row = new Row($attributes);
        $row->addColumns($columns);
        return $row;
    }

    /**
     * Create a column
     */
    public static function column(array $config = []): Column
    {
        $column = new Column($config['attributes'] ?? []);
        
        if (isset($config['content'])) {
            if (is_array($config['content'])) {
                $column->addContents($config['content']);
            } else {
                $column->addContent($config['content']);
            }
        }
        
        if (isset($config['size'])) {
            $column->size($config['size']);
        }
        
        if (isset($config['breakpoints'])) {
            foreach ($config['breakpoints'] as $breakpoint => $size) {
                $column->breakpoint($breakpoint, $size);
            }
        }
        
        if (isset($config['offset'])) {
            $column->offset($config['offset']);
        }
        
        if (isset($config['offsets'])) {
            foreach ($config['offsets'] as $breakpoint => $size) {
                $column->offsetBreakpoint($breakpoint, $size);
            }
        }
        
        if (isset($config['order'])) {
            $column->order($config['order']);
        }
        
        if (isset($config['orders'])) {
            foreach ($config['orders'] as $breakpoint => $order) {
                $column->orderBreakpoint($breakpoint, $order);
            }
        }
        
        if (isset($config['alignSelf'])) {
            $column->alignSelf($config['alignSelf']);
        }
        
        return $column;
    }

    /**
     * Create a two-column layout (sidebar + main content)
     */
    public static function twoColumn(array $config = []): string
    {
        $defaults = [
            'sidebarSize' => 3,
            'contentSize' => 9,
            'sidebarContent' => '',
            'content' => '',
            'sidebarBreakpoints' => [],
            'contentBreakpoints' => [],
            'containerAttributes' => [],
            'rowAttributes' => [],
            'sidebarAttributes' => [],
            'contentAttributes' => [],
            'fluid' => false,
        ];

        $config = array_merge($defaults, $config);

        $container = new Container($config['containerAttributes']);
        
        if ($config['fluid']) {
            $container->fluid();
        }

        $row = new Row($config['rowAttributes']);

        // Create sidebar column
        $sidebar = new Column($config['sidebarAttributes']);
        $sidebar->size($config['sidebarSize']);
        $sidebar->addContent($config['sidebarContent']);
        
        foreach ($config['sidebarBreakpoints'] as $breakpoint => $size) {
            $sidebar->breakpoint($breakpoint, $size);
        }

        // Create main content column
        $content = new Column($config['contentAttributes']);
        $content->size($config['contentSize']);
        $content->addContent($config['content']);
        
        foreach ($config['contentBreakpoints'] as $breakpoint => $size) {
            $content->breakpoint($breakpoint, $size);
        }

        $row->addColumn($sidebar);
        $row->addColumn($content);
        
        $container->addContent($row->render());
        
        return $container->render();
    }

    /**
     * Create a three-column layout
     */
    public static function threeColumn(array $config = []): string
    {
        $defaults = [
            'leftSize' => 2,
            'middleSize' => 7,
            'rightSize' => 3,
            'leftContent' => '',
            'middleContent' => '',
            'rightContent' => '',
            'leftBreakpoints' => [],
            'middleBreakpoints' => [],
            'rightBreakpoints' => [],
            'containerAttributes' => [],
            'rowAttributes' => [],
            'leftAttributes' => [],
            'middleAttributes' => [],
            'rightAttributes' => [],
            'fluid' => false,
        ];

        $config = array_merge($defaults, $config);

        $container = new Container($config['containerAttributes']);
        
        if ($config['fluid']) {
            $container->fluid();
        }

        $row = new Row($config['rowAttributes']);

        // Create left column
        $left = new Column($config['leftAttributes']);
        $left->size($config['leftSize']);
        $left->addContent($config['leftContent']);
        foreach ($config['leftBreakpoints'] as $breakpoint => $size) {
            $left->breakpoint($breakpoint, $size);
        }

        // Create middle column
        $middle = new Column($config['middleAttributes']);
        $middle->size($config['middleSize']);
        $middle->addContent($config['middleContent']);
        foreach ($config['middleBreakpoints'] as $breakpoint => $size) {
            $middle->breakpoint($breakpoint, $size);
        }

        // Create right column
        $right = new Column($config['rightAttributes']);
        $right->size($config['rightSize']);
        $right->addContent($config['rightContent']);
        foreach ($config['rightBreakpoints'] as $breakpoint => $size) {
            $right->breakpoint($breakpoint, $size);
        }

        $row->addColumn($left);
        $row->addColumn($middle);
        $row->addColumn($right);
        
        $container->addContent($row->render());
        
        return $container->render();
    }

    /**
     * Create a hero layout (centered content with optional background)
     */
    public static function hero(array $config = []): string
    {
        $defaults = [
            'title' => '',
            'subtitle' => '',
            'content' => '',
            'ctaButton' => '',
            'background' => 'bg-primary',
            'textColor' => 'text-white',
            'padding' => 'py-5',
            'containerAttributes' => [],
            'rowAttributes' => [],
            'columnAttributes' => [],
            'fluid' => false,
        ];

        $config = array_merge($defaults, $config);

        $container = new Container($config['containerAttributes']);
        
        if ($config['fluid']) {
            $container->fluid();
        }

        $container->addClass($config['background']);
        $container->addClass($config['textColor']);
        $container->addClass($config['padding']);

        $row = new Row($config['rowAttributes']);
        $row->addClass('justify-content-center align-items-center');

        $column = new Column($config['columnAttributes']);
        $column->addClass('text-center');
        $column->size(10);
        $column->breakpoint('md', 8);
        $column->breakpoint('lg', 6);

        $content = '';
        
        if ($config['title']) {
            $content .= '<h1 class="display-4">' . htmlspecialchars($config['title']) . '</h1>';
        }
        
        if ($config['subtitle']) {
            $content .= '<p class="lead">' . htmlspecialchars($config['subtitle']) . '</p>';
        }
        
        if ($config['content']) {
            $content .= '<div class="my-4">' . $config['content'] . '</div>';
        }
        
        if ($config['ctaButton']) {
            $content .= '<div class="mt-4">' . $config['ctaButton'] . '</div>';
        }

        $column->addContent($content);
        $row->addColumn($column);
        
        $container->addContent($row->render());
        
        return $container->render();
    }

    /**
     * Create a card grid layout
     */
    public static function cardGrid(array $config = []): string
    {
        $defaults = [
            'cards' => [],
            'columns' => 3,
            'breakpointColumns' => [
                'md' => 2,
                'lg' => 3,
                'xl' => 4
            ],
            'containerAttributes' => [],
            'rowAttributes' => [],
            'cardWrapperAttributes' => [],
            'fluid' => false,
        ];

        $config = array_merge($defaults, $config);

        $container = new Container($config['containerAttributes']);
        
        if ($config['fluid']) {
            $container->fluid();
        }

        $row = new Row($config['rowAttributes']);
        $row->addClass('row-cols-1');
        
        foreach ($config['breakpointColumns'] as $breakpoint => $columns) {
            $row->addClass('row-cols-' . $breakpoint . '-' . $columns);
        }

        foreach ($config['cards'] as $card) {
            $column = new Column($config['cardWrapperAttributes']);
            $column->addClass('mb-4');
            $column->addContent($card);
            $row->addColumn($column);
        }

        $container->addContent($row->render());
        
        return $container->render();
    }

    /**
     * Create a form layout
     */
    public static function formLayout(array $config = []): string
    {
        $defaults = [
            'form' => '',
            'sidebar' => '',
            'sidebarSize' => 4,
            'formSize' => 8,
            'containerAttributes' => [],
            'rowAttributes' => [],
            'formColumnAttributes' => [],
            'sidebarColumnAttributes' => [],
            'fluid' => false,
        ];

        $config = array_merge($defaults, $config);

        $container = new Container($config['containerAttributes']);
        
        if ($config['fluid']) {
            $container->fluid();
        }

        $row = new Row($config['rowAttributes']);

        if ($config['sidebar']) {
            // Two-column layout with sidebar
            $sidebarColumn = new Column($config['sidebarColumnAttributes']);
            $sidebarColumn->size($config['sidebarSize']);
            $sidebarColumn->addContent($config['sidebar']);
            
            $formColumn = new Column($config['formColumnAttributes']);
            $formColumn->size($config['formSize']);
            $formColumn->addContent($config['form']);
            
            $row->addColumn($sidebarColumn);
            $row->addColumn($formColumn);
        } else {
            // Single column for form
            $formColumn = new Column($config['formColumnAttributes']);
            $formColumn->size(12);
            $formColumn->addContent($config['form']);
            $row->addColumn($formColumn);
        }

        $container->addContent($row->render());
        
        return $container->render();
    }
}
<?php
namespace Mlangeni\Machinjiri\Components;

use Mlangeni\Machinjiri\Components\Components\{
    Alert, Badge, Breadcrumb, Button, Card, Form, Input, ListGroup, Modal, Nav, ProgressBar, Select, Textarea
};

class ComponentsFactory
{
    public static function button(string $text = '', array $attributes = []): Button
    {
        return new Button($text, $attributes);
    }

    public static function alert(string $message = '', array $attributes = []): Alert
    {
        return new Alert($message, $attributes);
    }

    public static function card(array $attributes = []): Card
    {
        return new Card($attributes);
    }

    public static function form(array $attributes = []): Form
    {
        return new Form($attributes);
    }

    public static function input(string $name = '', array $attributes = []): Input
    {
        return new Input($name, $attributes);
    }

    public static function progressBar(int $value = 0, array $attributes = []): ProgressBar
    {
        return new ProgressBar($value, $attributes);
    }

    public static function badge(string $text = '', array $attributes = []): Badge
    {
        return new Badge($text, $attributes);
    }

    public static function modal(string $id = '', array $attributes = []): Modal
    {
        return new Modal($id, $attributes);
    }

    public static function nav(array $attributes = []): Nav
    {
        return new Nav($attributes);
    }

    public static function select(string $name = '', array $attributes = []): Select
    {
        return new Select($name, $attributes);
    }

    public static function textarea(string $name = '', array $attributes = []): Textarea
    {
        return new Textarea($name, $attributes);
    }

    public static function breadcrumb(array $attributes = []): Breadcrumb
    {
        return new Breadcrumb($attributes);
    }

    public static function listGroup(array $attributes = []): ListGroup
    {
        return new ListGroup($attributes);
    }

    // Helper methods for quick creation
    public static function primaryButton(string $text): Button
    {
        return self::button($text)->primary();
    }

    public static function successAlert(string $message): Alert
    {
        return self::alert($message)->success()->dismissible();
    }

    public static function textInput(string $name, string $label = ''): Input
    {
        return self::input($name)->type('text')->label($label);
    }

    public static function emailInput(string $name, string $label = ''): Input
    {
        return self::input($name)->type('email')->label($label);
    }

    public static function passwordInput(string $name, string $label = ''): Input
    {
        return self::input($name)->type('password')->label($label);
    }
}
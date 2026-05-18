<?php

namespace Mlangeni\Machinjiri\Facade\UI\Angular;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Integrations\Angular\Angular;
use Mlangeni\Machinjiri\Core\Views\View;

/**
 * Cozy UI system for Angular SPA.
 *
 * Provides helpers to generate Angular‑compatible HTML, Bootstrap 5 markup,
 * and glue code that uses the machinjiri‑web NPM package (ajax, forms, modals, etc.).
 *
 * All generated code follows the “cozy” philosophy: clean, consistent, and
 * pleasant to work with.
 */
class AngularUI
{
    protected static Container $app;
    protected static Angular $angular;

    /**
     * Initialize the UI system with the application container.
     */
    public static function init(Container $app): void
    {
        self::$app = $app;
        self::$angular = $app->make(Angular::class);
    }

    /**
     * Generate a CSRF meta tag for Angular to read.
     */
    public static function csrfMetaTag(): string
    {
        $token = self::$angular->getCsrfToken();
        return sprintf(
            '<meta name="csrf-token" content="%s">',
            htmlspecialchars($token)
        );
    }

    /**
     * Output the necessary script tags for machinjiri‑web and Angular integration.
     */
    public static function includeScripts(): string
    {
        $assets = View::class;
        $baseUrl = self::$angular->getBaseUrl();

        // If using dev server, Vite client is already included by Angular::tags()
        if (self::$angular->isHot()) {
            $scripts = '';
        } else {
            $scripts = sprintf(
                '<script src="%s/machinjiri-web.js"></script>' . "\n",
                $baseUrl
            );
        }

        // Add Angular runtime scripts (produced by build)
        $scripts .= self::$angular->tags(true);

        return $scripts;
    }

    /**
     * Generate an Angular component tag.
     *
     * @param string $selector  Component selector (e.g., 'app-user-card')
     * @param array  $inputs    Input bindings [name => value]
     * @param string $content   Inner content (transclusion)
     */
    public static function component(string $selector, array $inputs = [], string $content = ''): string
    {
        $attrs = [];
        foreach ($inputs as $name => $value) {
            if (is_string($value) && strpos($value, '{{') !== false) {
                // Already Angular expression
                $attrs[] = sprintf('[%s]="%s"', $name, trim($value, '"\''));
            } elseif (is_string($value) && (strpos($value, '$') === 0 || strpos($value, 'function') !== false)) {
                // Event binding or function reference
                $attrs[] = sprintf('(%s)="%s"', $name, $value);
            } else {
                $attrs[] = sprintf('%s="%s"', $name, htmlspecialchars((string)$value));
            }
        }
        $attrStr = $attrs ? ' ' . implode(' ', $attrs) : '';
        return "<{$selector}{$attrStr}>{$content}</{$selector}>";
    }

    /**
     * Build a Bootstrap 5 form with Angular (reactive) bindings.
     *
     * @param array $config {
     *     @var string $name           Form group name (for FormGroup)
     *     @var array  $fields         Field definitions
     *     @var string $submitText     Button text
     *     @var string $submitHandler  Angular submit handler method
     *     @var bool   $useNgSubmit    Use (ngSubmit) instead of (submit)
     *     @var string $class          Additional CSS classes
     * }
     */
    public static function form(array $config): string
    {
        $defaults = [
            'name' => 'form',
            'fields' => [],
            'submitText' => 'Submit',
            'submitHandler' => 'onSubmit()',
            'useNgSubmit' => true,
            'class' => '',
        ];
        $config = array_merge($defaults, $config);

        $submitEvent = $config['useNgSubmit'] ? '(ngSubmit)' : '(submit)';
        $html = sprintf(
            '<form [formGroup]="%s" %s="%s" class="%s" novalidate>',
            htmlspecialchars($config['name']),
            $submitEvent,
            htmlspecialchars($config['submitHandler']),
            htmlspecialchars($config['class'])
        );

        foreach ($config['fields'] as $field) {
            $html .= self::formField($field);
        }

        $html .= sprintf(
            '<div class="mt-3"><button type="submit" class="btn btn-primary" [disabled]="%s.invalid || %s.pending">%s</button></div>',
            $config['name'],
            $config['name'],
            htmlspecialchars($config['submitText'])
        );
        $html .= '</form>';
        return $html;
    }

    /**
     * Generate a single form field with Bootstrap styling and Angular validation.
     *
     * @param array $field {
     *     @var string $type       input, select, textarea, checkbox, radio
     *     @var string $name       formControlName
     *     @var string $label      Label text
     *     @var string $placeholder
     *     @var array  $options    For select/radio: [value => label]
     *     @var string $help       Help text
     *     @var bool   $required
     *     @var string $class      Additional CSS
     * }
     */
    public static function formField(array $field): string
    {
        $defaults = [
            'type' => 'text',
            'name' => '',
            'label' => '',
            'placeholder' => '',
            'options' => [],
            'help' => '',
            'required' => false,
            'class' => '',
        ];
        $field = array_merge($defaults, $field);

        $controlName = htmlspecialchars($field['name']);
        $id = 'field_' . $controlName;

        $html = '<div class="mb-3 ' . htmlspecialchars($field['class']) . '">';
        if ($field['label']) {
            $html .= sprintf(
                '<label for="%s" class="form-label">%s%s</label>',
                $id,
                htmlspecialchars($field['label']),
                $field['required'] ? ' <span class="text-danger">*</span>' : ''
            );
        }

        switch ($field['type']) {
            case 'textarea':
                $html .= sprintf(
                    '<textarea formControlName="%s" id="%s" class="form-control" placeholder="%s"></textarea>',
                    $controlName,
                    $id,
                    htmlspecialchars($field['placeholder'])
                );
                break;

            case 'select':
                $html .= sprintf('<select formControlName="%s" id="%s" class="form-select">', $controlName, $id);
                foreach ($field['options'] as $value => $label) {
                    $html .= sprintf('<option value="%s">%s</option>', htmlspecialchars($value), htmlspecialchars($label));
                }
                $html .= '</select>';
                break;

            case 'checkbox':
                $html .= sprintf(
                    '<div class="form-check"><input type="checkbox" formControlName="%s" id="%s" class="form-check-input"><label class="form-check-label" for="%s">%s</label></div>',
                    $controlName,
                    $id,
                    $id,
                    htmlspecialchars($field['label'] ?: ucfirst($controlName))
                );
                break;

            case 'radio':
                foreach ($field['options'] as $value => $label) {
                    $radioId = $id . '_' . $value;
                    $html .= sprintf(
                        '<div class="form-check"><input type="radio" formControlName="%s" id="%s" value="%s" class="form-check-input"><label class="form-check-label" for="%s">%s</label></div>',
                        $controlName,
                        $radioId,
                        htmlspecialchars($value),
                        $radioId,
                        htmlspecialchars($label)
                    );
                }
                break;

            default: // text, email, password, number, etc.
                $html .= sprintf(
                    '<input type="%s" formControlName="%s" id="%s" class="form-control" placeholder="%s">',
                    htmlspecialchars($field['type']),
                    $controlName,
                    $id,
                    htmlspecialchars($field['placeholder'])
                );
        }

        // Validation feedback (Angular will add .is-invalid automatically)
        $html .= sprintf(
            '<div class="invalid-feedback" *ngIf="%s.get(\'%s\')?.invalid && %s.get(\'%s\')?.touched">',
            $controlName,
            $controlName,
            $controlName,
            $controlName
        );
        $html .= '<span *ngIf="' . $controlName . '.getError(\'required\')">This field is required.</span>';
        $html .= '<span *ngIf="' . $controlName . '.getError(\'email\')">Invalid email address.</span>';
        $html .= '<span *ngIf="' . $controlName . '.getError(\'minlength\')">Value is too short.</span>';
        $html .= '<span *ngIf="' . $controlName . '.getError(\'maxlength\')">Value is too long.</span>';
        $html .= '</div>';

        if ($field['help']) {
            $html .= sprintf('<div class="form-text">%s</div>', htmlspecialchars($field['help']));
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * Generate a Bootstrap modal that uses machinjiri‑web’s modal helper.
     *
     * @param array $config {
     *     @var string $id        Modal ID
     *     @var string $title     Modal title
     *     @var string $body      Modal body (HTML or Angular template)
     *     @var array  $buttons   Button definitions
     *     @var bool   $static    Static backdrop
     * }
     */
    public static function modal(array $config): string
    {
        $defaults = [
            'id' => 'cozyModal',
            'title' => 'Modal Title',
            'body' => 'Modal body content.',
            'buttons' => [
                ['text' => 'Close', 'class' => 'btn-secondary', 'dismiss' => true],
                ['text' => 'Save', 'class' => 'btn-primary', 'handler' => 'save()'],
            ],
            'static' => false,
        ];
        $config = array_merge($defaults, $config);

        $buttonsHtml = '';
        foreach ($config['buttons'] as $btn) {
            $dismissAttr = isset($btn['dismiss']) && $btn['dismiss'] ? 'data-bs-dismiss="modal"' : '';
            $clickAttr = isset($btn['handler']) ? sprintf('(click)="%s"', htmlspecialchars($btn['handler'])) : '';
            $buttonsHtml .= sprintf(
                '<button type="button" class="btn %s" %s %s>%s</button>',
                htmlspecialchars($btn['class']),
                $dismissAttr,
                $clickAttr,
                htmlspecialchars($btn['text'])
            );
        }

        $backdrop = $config['static'] ? 'data-bs-backdrop="static" data-bs-keyboard="false"' : '';

        return sprintf(
            '<div class="modal fade" id="%s" tabindex="-1" %s>
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">%s</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            %s
                        </div>
                        <div class="modal-footer">
                            %s
                        </div>
                    </div>
                </div>
            </div>',
            htmlspecialchars($config['id']),
            $backdrop,
            htmlspecialchars($config['title']),
            $config['body'],
            $buttonsHtml
        );
    }

    /**
     * Generate a button that triggers a toast notification using machinjiri‑web.
     *
     * @param string $message   Toast message
     * @param string $title     Toast title
     * @param string $variant   Variant (success, danger, warning, info)
     * @param string $buttonText Button text
     */
    public static function toastButton(string $message, string $title = '', string $variant = 'info', string $buttonText = 'Show Toast'): string
    {
        $call = sprintf(
            "machinjiri.bootstrap.toast({ title: '%s', message: '%s', variant: '%s' })",
            addslashes($title),
            addslashes($message),
            $variant
        );
        return sprintf(
            '<button type="button" class="btn btn-outline-%s" (click)="%s">%s</button>',
            $variant,
            $call,
            htmlspecialchars($buttonText)
        );
    }

    /**
     * Generate an Angular service class (as a string) that wraps machinjiri‑web AJAX.
     *
     * @param string $name       Service name (e.g., 'UserService')
     * @param array  $endpoints  [method => [url, options?]] or array of endpoint definitions
     */
    public static function ajaxService(string $name, array $endpoints): string
    {
        $methods = [];
        foreach ($endpoints as $methodName => $def) {
            if (is_string($def)) {
                $url = $def;
                $httpMethod = 'get';
            } else {
                $httpMethod = $def['method'] ?? 'get';
                $url = $def['url'];
            }
            $methods[] = sprintf(
                "    %s(data?: any, opts?: any): Promise<any> {
        return machinjiri.ajax.%s('%s', data, opts);
    }",
                $methodName,
                $httpMethod,
                $url
            );
        }

        return sprintf(
            "import { Injectable } from '@angular/core';\ndeclare const machinjiri: any;\n\n@Injectable({ providedIn: 'root' })\nexport class %s {\n%s\n}\n",
            $name,
            implode("\n\n", $methods)
        );
    }

    /**
     * Output a Bootstrap 5 responsive grid (row + columns) with Angular *ngFor support.
     *
     * @param array $config {
     *     @var string $ngFor    Optional *ngFor expression
     *     @var array  $columns  Column definitions [width => content]
     *     @var string $class    Additional row classes
     * }
     */
    public static function grid(array $config): string
    {
        $defaults = ['ngFor' => '', 'columns' => [], 'class' => ''];
        $config = array_merge($defaults, $config);

        $ngForAttr = $config['ngFor'] ? ' *ngFor="' . htmlspecialchars($config['ngFor']) . '"' : '';
        $html = sprintf('<div class="row %s"%s>', htmlspecialchars($config['class']), $ngForAttr);
        foreach ($config['columns'] as $width => $content) {
            $html .= sprintf('<div class="col-%s">%s</div>', $width, $content);
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * Generate Angular component boilerplate code (TypeScript + template).
     *
     * @param string $name         Component name (e.g., 'UserProfile')
     * @param string $selector     CSS selector
     * @param array  $inputs       @Input() names
     * @param array  $outputs      @Output() event names
     * @param string $template     Inline template or 'url' to external file
     * @param bool   $standalone   Use standalone component (Angular 14+)
     */
    public static function componentBoilerplate(string $name, string $selector, array $inputs = [], array $outputs = [], string $template = '', bool $standalone = true): string
    {
        $inputProps = '';
        foreach ($inputs as $in) {
            $inputProps .= sprintf("  @Input() %s: any;\n", $in);
        }
        $outputProps = '';
        foreach ($outputs as $out) {
            $outputProps .= sprintf("  @Output() %s = new EventEmitter<any>();\n", $out);
        }

        $templateCode = $template ? "template: `" . addslashes($template) . "`" : "templateUrl: './{$name}.component.html'";
        $standaloneFlag = $standalone ? 'standalone: true,' : '';

        return sprintf(
            "import { Component, Input, Output, EventEmitter %s } from '@angular/core';\n\n@Component({
    selector: '%s',
    %s,
    %s
    imports: [CommonModule]
})
export class %s {
%s%s
    constructor() { }
}",
            $standalone ? '' : ', CommonModule',
            $selector,
            $templateCode,
            $standaloneFlag,
            $name,
            $inputProps,
            $outputProps
        );
    }

    /**
     * Generate a cozy page wrapper with Bootstrap container and Angular router outlet.
     */
    public static function pageWrapper(string $title = '', string $additionalContent = ''): string
    {
        $html = '<div class="container py-4">';
        if ($title) {
            $html .= sprintf('<h1 class="mb-4">%s</h1>', htmlspecialchars($title));
        }
        $html .= $additionalContent;
        $html .= '<router-outlet></router-outlet>';
        $html .= '</div>';
        return $html;
    }

    /**
     * Generate a loading spinner overlay using machinjiri‑web loader.
     */
    public static function loadingSpinner(): string
    {
        return '<div id="global-loader" style="display:none;"></div>';
    }
}
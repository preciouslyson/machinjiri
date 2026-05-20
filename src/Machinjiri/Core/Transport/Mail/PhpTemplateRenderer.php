<?php

namespace Mlangeni\Machinjiri\Core\Transport\Mail;

use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

class PhpTemplateRenderer implements TemplateRendererInterface
{
    private string $templateDirectory;

    public function __construct(string $templateDirectory)
    {
        if (!is_dir($templateDirectory)) {
            throw new MachinjiriException(
                "Template directory not found: {$templateDirectory}",
                500,
                null,
                ['directory' => $templateDirectory],
                'mail_config'
            );
        }
        $this->templateDirectory = rtrim($templateDirectory, '/');
    }

    public function render(string $template, array $data = []): string
    {
        $templatePath = $this->templateDirectory . '/' . ltrim($template, '/');
        if (!file_exists($templatePath)) {
            throw new MachinjiriException(
                "Template not found: {$templatePath}",
                500,
                null,
                ['template' => $templatePath],
                'mail_template'
            );
        }

        extract($data, EXTR_SKIP);
        ob_start();
        include $templatePath;
        return ob_get_clean();
    }
}
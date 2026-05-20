<?php

namespace Mlangeni\Machinjiri\Core\Transport\Mail;

interface TemplateRendererInterface
{
    public function render(string $template, array $data = []): string;
}
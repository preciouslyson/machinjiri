<?php

namespace Mlangeni\Machinjiri\Core\Views\Contracts;

interface ViewCompilerInterface
{
    /**
     * Compile view content with custom tags into PHP.
     */
    public function compile(string $content): string;
}
<?php

namespace Mlangeni\Machinjiri\Core\Routing\Contracts;

interface UrlGeneratorInterface
{
    public function url(string $name, array $params = []): string;
    public function absoluteUrl(string $name, array $params = []): string;
    public function getBaseUrl(): string;
}
<?php

namespace Mlangeni\Machinjiri\Components\Helpers;

class Attributes
{
    public static function merge(array $defaults, array $custom): array
    {
        return array_merge($defaults, array_filter($custom, function($value) {
            return $value !== null && $value !== '';
        }));
    }

    public static function toString(array $attributes): string
    {
        $parts = [];
        foreach ($attributes as $key => $value) {
            if ($value !== null && $value !== '') {
                $parts[] = $key . '="' . htmlspecialchars($value) . '"';
            }
        }
        return implode(' ', $parts);
    }
}
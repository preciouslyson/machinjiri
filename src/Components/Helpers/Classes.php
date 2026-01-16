<?php

namespace Mlangeni\Machinjiri\Components\Helpers;

class Classes
{
    public static function merge(array ...$classLists): array
    {
        $merged = [];
        foreach ($classLists as $classes) {
            $merged = array_merge($merged, (array) $classes);
        }
        return array_unique($merged);
    }

    public static function toString(array $classes): string
    {
        return implode(' ', array_unique(array_filter($classes)));
    }
}
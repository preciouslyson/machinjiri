<?php
namespace Mlangeni\Machinjiri\Components\Base;

trait ComponentTrait
{
    protected function renderElement(string $tag, string $content = '', array $attributes = []): string
    {
        $attrs = $this->buildAttributes();
        
        if (!empty($attributes)) {
            $extraAttrs = [];
            foreach ($attributes as $key => $value) {
                $extraAttrs[] = $key . '="' . htmlspecialchars($value) . '"';
            }
            $attrs .= ' ' . implode(' ', $extraAttrs);
        }

        if ($content === '' && in_array($tag, ['input', 'img', 'br', 'hr', 'meta', 'link'])) {
            return "<{$tag} {$attrs}>";
        }

        return "<{$tag} {$attrs}>{$content}</{$tag}>";
    }
}
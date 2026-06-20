<?php

namespace Mlangeni\Machinjiri\Core\Views\Services;

use Mlangeni\Machinjiri\Core\Views\Contracts\ViewCompilerInterface;

class ViewCompiler implements ViewCompilerInterface
{
    public function compile(string $content): string
    {
        $patterns = [
            '/{!!(.*?)!!}/s'                               => '<?php echo $1; ?>',
            '/{{(.*?)}}/s'                                 => '<?php echo htmlspecialchars($1, ENT_QUOTES, "UTF-8"); ?>',
            '/<%\s*content\s*%>/'                          => '<?php View::yield(\'content\'); ?>',
            '/<%\s*section\(([^)]+)\)\s*%>/'               => '<?php View::section($1); ?>',
            '/<%\s*endsection\s*%>/'                       => '<?php View::endSection(); ?>',
            '/<%\s*include\s+([^,]+?)(?:,\s*(.+?))?\s*%>/' => '<?php View::include($1, $2 ?? []); ?>',
            '/<%\s*extend\s+([^%]+)\s*%>/'                 => '<?php View::extend($1); ?>',
            '/<%\s*if\s+(.+?)\s*%>/'                       => '<?php if ($1): ?>',
            '/<%\s*else\s*%>/'                             => '<?php else: ?>',
            '/<%\s*endif\s*%>/'                            => '<?php endif; ?>',
            '/<%\s*foreach\s+(.+?)\s*%>/'                  => '<?php foreach ($1): ?>',
            '/<%\s*endforeach\s*%>/'                       => '<?php endforeach; ?>',
            '/<%\s*parent\s*%>/'                           => '<?php View::parent(); ?>',
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $content);
    }
}
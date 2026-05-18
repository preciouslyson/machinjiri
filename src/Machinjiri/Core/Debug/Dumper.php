<?php

namespace Mlangeni\Machinjiri\Core\Debug;

use Mlangeni\Machinjiri\Core\Container;

/**
 * Dumper - Provides pretty variable dumping with optional termination.
 */
class Dumper
{
    /**
     * Dump one or more variables and optionally die.
     *
     * @param mixed ...$args
     * @return void
     */
    public static function dump(...$args): void
    {
        foreach ($args as $arg) {
            static::output($arg);
        }
    }

    /**
     * Dump and die.
     *
     * @param mixed ...$args
     * @return never
     */
    public static function dd(...$args): never
    {
        $app = Container::instancePresent() ? Container::getInstance() : null;
        if ($app && !$app->isDevelopment()) {
            // In production, maybe log instead of dumping?
            // For security, we can just die with a generic message.
            die('Application error.');
        }
        static::dump(...$args);
        exit(1);
    }

    /**
     * Output a single variable with formatting.
     *
     * @param mixed $var
     * @return void
     */
    protected static function output($var): void
    {
        // Determine if we're in CLI or web context
        if (php_sapi_name() === 'cli') {
            static::cliDump($var);
        } else {
            static::htmlDump($var);
        }
    }

    /**
     * CLI output.
     *
     * @param mixed $var
     * @return void
     */
    protected static function cliDump($var): void
    {
        if (is_string($var) || is_numeric($var)) {
            echo $var . PHP_EOL;
        } elseif (is_bool($var)) {
            echo ($var ? 'true' : 'false') . PHP_EOL;
        } elseif (is_null($var)) {
            echo 'null' . PHP_EOL;
        } elseif (is_array($var)) {
            print_r($var);
        } elseif (is_object($var)) {
            echo get_class($var) . " Object\n";
            print_r($var);
        } else {
            var_dump($var);
        }
    }

    /**
     * HTML output with styling.
     *
     * @param mixed $var
     * @return void
     */
    protected static function htmlDump($var): void
    {
        static $hasStyles = false;

        if (!$hasStyles) {
            echo static::getStyles();
            $hasStyles = true;
        }

        echo '<div class="machinjiri-dump cozy-dump">';
        echo '<pre>';
        static::formatHtml($var);
        echo '</pre>';
        echo '</div>';
    }

    /**
     * Format a variable for HTML display.
     *
     * @param mixed $var
     * @param int $depth
     * @return void
     */
    protected static function formatHtml($var, int $depth = 0): void
    {
        $indent = str_repeat('  ', $depth);

        if (is_null($var)) {
            echo '<span class="dump-null">null</span>';
        } elseif (is_bool($var)) {
            echo '<span class="dump-bool">' . ($var ? 'true' : 'false') . '</span>';
        } elseif (is_int($var)) {
            echo '<span class="dump-int">' . $var . '</span>';
        } elseif (is_float($var)) {
            echo '<span class="dump-float">' . $var . '</span>';
        } elseif (is_string($var)) {
            echo '<span class="dump-string">"' . htmlspecialchars($var) . '"</span>';
        } elseif (is_array($var)) {
            echo '<span class="dump-array">array</span> (' . count($var) . ') [';
            if (empty($var)) {
                echo ']';
                return;
            }
            echo "\n";
            foreach ($var as $key => $value) {
                echo $indent . '  ';
                if (is_string($key)) {
                    echo '<span class="dump-key">"' . htmlspecialchars($key) . '"</span> => ';
                } else {
                    echo '<span class="dump-key">' . $key . '</span> => ';
                }
                static::formatHtml($value, $depth + 1);
                echo "\n";
            }
            echo $indent . ']';
        } elseif (is_object($var)) {
            $class = get_class($var);
            echo '<span class="dump-object">' . $class . '</span> {';
            $properties = get_object_vars($var);
            if (empty($properties)) {
                echo '}';
                return;
            }
            echo "\n";
            foreach ($properties as $key => $value) {
                echo $indent . '  <span class="dump-property">' . htmlspecialchars($key) . '</span> => ';
                static::formatHtml($value, $depth + 1);
                echo "\n";
            }
            echo $indent . '}';
        } elseif (is_resource($var)) {
            echo '<span class="dump-resource">resource(' . get_resource_type($var) . ')</span>';
        } else {
            var_dump($var);
        }
    }

    /**
     *
     * @return string
     */
    protected static function getStyles(): string
    {
        return <<<CSS
<style>
.machinjiri-dump.cozy-dump {
    background: #FCF7F0;
    color: #2E2C2A;
    font-family: 'SF Mono', Monaco, Menlo, Consolas, 'Courier New', monospace;
    font-size: 14px;
    line-height: 1.6;
    padding: 1rem 1.2rem;
    margin: 1rem 0;
    border-radius: 28px;
    overflow-x: auto;
    border-left: 5px solid #E68A5E;
    box-shadow: 0 6px 14px rgba(0, 0, 0, 0.04);
    backdrop-filter: blur(2px);
    border: 1px solid #F2E5D8;
    border-left-width: 5px;
}
.machinjiri-dump.cozy-dump pre {
    margin: 0;
    white-space: pre-wrap;
    word-wrap: break-word;
    font-family: inherit;
}
.dump-null {
    color: #D9735A;
    font-weight: 500;
}
.dump-bool {
    color: #D9735A;
    font-weight: 500;
}
.dump-int {
    color: #7F9EB5;
}
.dump-float {
    color: #7F9EB5;
}
.dump-string {
    color: #C4633A;
}
.dump-array {
    color: #8A6E4B;
    font-weight: 500;
}
.dump-object {
    color: #8A6E4B;
    font-weight: 500;
}
.dump-resource {
    color: #9B6B9E;
}
.dump-key {
    color: #9C7A5C;
}
.dump-property {
    color: #9C7A5C;
}
/* optional subtle hover effect */
.machinjiri-dump.cozy-dump:hover {
    border-left-color: #CD7350;
    box-shadow: 0 10px 20px rgba(0, 0, 0, 0.06);
}
</style>
CSS;
    }
}
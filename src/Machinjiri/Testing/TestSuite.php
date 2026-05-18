<?php

namespace Mlangeni\Machinjiri\Testing;

use PHPUnit\Framework\TestSuite as BaseTestSuite;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

class TestSuite extends BaseTestSuite
{
    public static function fromDirectory(string $directory, string $suffix = 'Test.php'): self
    {
        $suite = new self();
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory)
        );

        foreach ($files as $file) {
            if (substr($file->getFilename(), -strlen($suffix)) === $suffix) {
                $className = self::classFromFile($file->getPathname(), $directory);
                if ($className) {
                    $suite->addTestSuite($className);
                }
            }
        }

        return $suite;
    }

    protected static function classFromFile(string $file, string $baseDir): ?string
    {
        $relativePath = substr($file, strlen($baseDir) + 1, -4);
        $className = str_replace(DIRECTORY_SEPARATOR, '\\', $relativePath);
        $fullClass = 'Mlangeni\\Machinjiri\\Tests\\' . $className;
        return class_exists($fullClass) ? $fullClass : null;
    }
}
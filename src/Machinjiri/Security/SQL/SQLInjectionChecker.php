<?php

namespace Mlangeni\Machinjiri\Core\Security\SQL;

class SQLInjectionChecker {
    // Common SQL injection patterns (case-insensitive)
    private const DEFAULT_PATTERNS = [
        // Pattern 1: Common SQL keywords in suspicious contexts
        '/(\b(union|select|insert|update|delete|drop|truncate|alter|create|replace)\b|\b(and|or)\b\s*[^\w\s]|\b(exec|execute|call|procedure)\b)/i',
        
        // Pattern 2: Quote-based termination
        '/(\'\'|""|\\\'.*?\\\'|\\".*?\\"|\\\\\')/',
        
        // Pattern 3: SQL comments and termination characters
        '/(--|\#|\/\*[\s\S]*?\*\/|;)/',
        
        // Pattern 4: Conditional logic patterns
        '/(\b(if|case|when|then)\b\s*[\(<>=])/i',
        
        // Pattern 5: Suspicious meta-characters sequences
        '/(\|\||&&|~~|:=)/',
        
        // Pattern 6: Hex encoding detection
        '/0x[0-9a-f]+/i',
        
        // Pattern 7: Basic schema probing
        '/(information_schema|pg_catalog|sys\.[a-z]+)/i'
    ];

    private array $patterns;

    public function __construct(array $customPatterns = []) {
        $this->patterns = !empty($customPatterns) 
            ? $customPatterns 
            : self::DEFAULT_PATTERNS;
    }

    /**
     * Check a single string for SQL injection patterns
     */
    public function checkString(string $input): bool {
        foreach ($this->patterns as $pattern) {
            if (preg_match($pattern, $input)) {
                return true; // Potential SQL injection detected
            }
        }
        return false; // No patterns found
    }

    /**
     * Check an associative array of data (e.g., $_POST, $_GET)
     * Returns array of suspicious keys or empty array if clean
     */
    public function checkArray(array $data): array {
        $suspiciousKeys = [];
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                // Recursive check for nested arrays
                $nested = $this->checkArray($value);
                if (!empty($nested)) {
                    $suspiciousKeys[$key] = $nested;
                }
            } elseif (is_string($value) && $this->checkString($value)) {
                $suspiciousKeys[] = $key;
            }
        }
        
        return $suspiciousKeys;
    }

    /**
     * Sanitize input by removing suspicious patterns
     * (Should be used cautiously - parameterized queries are better)
     */
    public function sanitizeInput(string $input): string {
        return preg_replace($this->patterns, '', $input);
    }
}
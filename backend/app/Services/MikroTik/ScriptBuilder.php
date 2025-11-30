<?php

namespace App\Services\MikroTik;

class ScriptBuilder
{
    /**
     * Escape special characters in RouterOS strings
     */
    public static function escape(string $value): string
    {
        return str_replace(
            ['\\', '"', '\n', '\r'], 
            ['\\\\', '\\"', '\\\\n', '\\\\r'],
            $value
        );
    }

    /**
     * Build a RouterOS command with parameters
     */
    public static function buildCommand(string $command, array $params = []): string
    {
        $parts = [$command];
        
        foreach ($params as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? 'yes' : 'no';
            } elseif (is_array($value)) {
                $value = implode(',', array_map('self::escape', $value));
            } else {
                $value = self::escape((string)$value);
            }
            
            // Handle both key=value and flag-only parameters
            if (is_numeric($key)) {
                $parts[] = $value;
            } else {
                $parts[] = "$key=$value";
            }
        }
        
        return implode(' ', $parts);
    }

    /**
     * Add a comment to the script
     */
    public static function comment(string $text): string
    {
        return '# ' . str_replace(["\r", "\n"], ' ', $text);
    }

    /**
     * Generate a script section header
     */
    public static function section(string $title): string
    {
        $line = str_repeat('=', 20);
        return "\n$line $title $line";
    }
}

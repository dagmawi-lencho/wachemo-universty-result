<?php

declare(strict_types=1);

/**
 * String helpers that mirror Python's str semantics used by the original bot.
 */
final class Text
{
    /** Characters Python's str.strip() treats as whitespace. */
    private const WS = '\s\x{1C}-\x{1F}\x{85}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}';

    public static function strip(string $s): string
    {
        $out = preg_replace('/^[' . self::WS . ']+|[' . self::WS . ']+$/u', '', $s);
        return $out ?? trim($s);
    }

    /**
     * Equivalent of [l.strip() for l in s.splitlines() if l.strip()].
     *
     * @return string[]
     */
    public static function lines(string $s): array
    {
        $parts = preg_split('/\r\n|[\n\r\x0B\x0C\x1C-\x1E\x{85}\x{2028}\x{2029}]/u', $s);
        if ($parts === false) {
            $parts = preg_split('/\r\n|[\n\r]/', $s) ?: [];
        }
        $lines = [];
        foreach ($parts as $part) {
            $part = self::strip($part);
            if ($part !== '') {
                $lines[] = $part;
            }
        }
        return $lines;
    }

    public static function length(string $s): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($s, 'UTF-8');
        }
        $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
        return $chars === false ? strlen($s) : count($chars);
    }

    public static function slice(string $s, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($s, 0, $length, 'UTF-8');
        }
        $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
        return $chars === false ? substr($s, 0, $length) : implode('', array_slice($chars, 0, $length));
    }

    public static function startsWith(string $haystack, string $needle): bool
    {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }

    public static function contains(string $haystack, string $needle): bool
    {
        return strpos($haystack, $needle) !== false;
    }
}

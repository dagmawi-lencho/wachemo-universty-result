<?php

declare(strict_types=1);

/**
 * Private log of portal pages the bot could not understand. Records page structure
 * (path, title, headings, form actions, link paths) only, never grades or credentials.
 */
final class Diagnostics
{
    private const MAX_BYTES = 2000000;

    private static ?string $file = null;

    public static function setFile(?string $file): void
    {
        self::$file = $file;
    }

    public static function record(string $context, array $details): void
    {
        if (self::$file === null) {
            return;
        }
        if (is_file(self::$file) && filesize(self::$file) > self::MAX_BYTES) {
            @rename(self::$file, self::$file . '.old');
        }
        $line = gmdate('Y-m-d H:i:s') . ' ' . $context . ' '
            . json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
        $isNew = !is_file(self::$file);
        @file_put_contents(self::$file, $line, FILE_APPEND | LOCK_EX);
        if ($isNew) {
            @chmod(self::$file, 0600);
        }
    }
}

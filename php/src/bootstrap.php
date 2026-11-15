<?php

declare(strict_types=1);

require __DIR__ . '/Text.php';
require __DIR__ . '/Html.php';
require __DIR__ . '/Diagnostics.php';
require __DIR__ . '/Crypto.php';
require __DIR__ . '/Store.php';
require __DIR__ . '/Telegram.php';
require __DIR__ . '/Portal.php';
require __DIR__ . '/Format.php';
require __DIR__ . '/Bot.php';

/**
 * Load config, preferring ~/wcu-bot-config.php (outside public_html) over php/config.php.
 */
function wcu_config(): array
{
    $candidates = [];
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $candidates[] = dirname((string) $_SERVER['DOCUMENT_ROOT']) . '/wcu-bot-config.php';
    }
    if (getenv('HOME')) {
        $candidates[] = rtrim((string) getenv('HOME'), '/') . '/wcu-bot-config.php';
    }
    $candidates[] = dirname(__DIR__) . '/config.php';
    foreach ($candidates as $path) {
        if (is_file($path)) {
            $config = require $path;
            Diagnostics::setFile(dirname($path) . '/wcu-bot-diagnostics.log');
            break;
        }
    }
    if (!isset($config) || !is_array($config)) {
        throw new RuntimeException('Missing config.php (copy config.example.php and fill it in).');
    }
    foreach (['bot_token', 'secret_key', 'webhook_secret', 'webhook_url', 'setup_key', 'db'] as $key) {
        if (empty($config[$key])) {
            throw new RuntimeException("config.php is missing '{$key}'.");
        }
    }
    return $config;
}

/**
 * Send the HTTP 200 to Telegram now and keep working, so slow portal logins
 * never make Telegram time out and resend the update.
 */
function wcu_finish_response(): void
{
    ignore_user_abort(true);
    http_response_code(200);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return;
    }
    if (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
        return;
    }
    header('Content-Type: text/plain');
    header('Content-Length: 0');
    header('Connection: close');
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
}

<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

try {
    $config = wcu_config();
} catch (Throwable $e) {
    error_log('[wcu-bot] ' . $e->getMessage());
    http_response_code(500);
    exit;
}

$secret = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
if (!hash_equals((string) $config['webhook_secret'], $secret)) {
    http_response_code(403);
    exit;
}

$update = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($update) || !isset($update['update_id'])) {
    http_response_code(400);
    exit;
}

wcu_finish_response();
@set_time_limit(180);

try {
    $store = Store::fromConfig($config);
    if (!$store->claimUpdate((int) $update['update_id'])) {
        exit;
    }
    (new Bot(new Telegram((string) $config['bot_token']), $store))->handle($update);
    if (random_int(1, 50) === 1) {
        $store->prune();
    }
} catch (Throwable $e) {
    error_log('[wcu-bot] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
}

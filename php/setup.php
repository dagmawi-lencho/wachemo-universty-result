<?php

declare(strict_types=1);

/**
 * One-time setup and diagnostics.
 *
 * Browser: setup.php?key=SETUP_KEY&action=check|webhook|info|delete
 * CLI:     php setup.php check|webhook|info|delete
 */

require __DIR__ . '/src/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

$isCli = PHP_SAPI === 'cli';

try {
    $config = wcu_config();
} catch (Throwable $e) {
    http_response_code(500);
    exit('Config error: ' . $e->getMessage() . "\n");
}

if (!$isCli && !hash_equals((string) $config['setup_key'], (string) ($_GET['key'] ?? ''))) {
    http_response_code(403);
    exit("Forbidden. Open setup.php?key=YOUR_SETUP_KEY&action=check\n");
}

$action = $isCli ? ($argv[1] ?? 'check') : (string) ($_GET['action'] ?? 'check');
$tg = new Telegram((string) $config['bot_token']);

function line(string $label, bool $ok, string $detail = ''): void
{
    echo ($ok ? '[ OK ] ' : '[FAIL] ') . $label . ($detail !== '' ? " - {$detail}" : '') . "\n";
}

switch ($action) {
    case 'check':
        line('PHP version ' . PHP_VERSION, version_compare(PHP_VERSION, '7.4.0', '>='), 'needs 7.4 or newer');
        foreach (['curl', 'dom', 'json', 'openssl', 'pdo_mysql', 'libxml'] as $extension) {
            line("Extension {$extension}", extension_loaded($extension));
        }
        line(
            'Early webhook response',
            function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request'),
            'not fatal; falls back to flushing the response'
        );

        try {
            $store = Store::fromConfig($config);
            $store->install((string) file_get_contents(__DIR__ . '/schema.sql'));
            line('MySQL connection and tables', true);
        } catch (Throwable $e) {
            line('MySQL connection and tables', false, $e->getMessage());
        }

        $me = $tg->call('getMe');
        line('Telegram bot token', is_array($me), is_array($me) ? '@' . $me['username'] : (string) $tg->lastError());

        $started = microtime(true);
        $curl = curl_init(PortalClient::BASE_URL);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 30,
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $elapsed = round(microtime(true) - $started, 2);
        if ($body === false) {
            line('WCU portal reachable from this server', false, curl_error($curl) . " after {$elapsed}s");
        } else {
            $hasLoginForm = stripos((string) $body, 'UserName') !== false || stripos((string) $body, '<form') !== false;
            line(
                'WCU portal reachable from this server',
                $status === 200 && $hasLoginForm,
                "HTTP {$status} in {$elapsed}s" . ($hasLoginForm ? ', login form found' : ', no login form found')
            );
        }
        break;

    case 'webhook':
        $ok = $tg->call('setWebhook', [
            'url' => $config['webhook_url'],
            'secret_token' => $config['webhook_secret'],
            'allowed_updates' => ['message', 'callback_query'],
            'drop_pending_updates' => true,
            'max_connections' => 10,
        ]);
        line('setWebhook ' . $config['webhook_url'], $ok !== null, (string) $tg->lastError());
        break;

    case 'info':
        $info = $tg->call('getWebhookInfo');
        if (!is_array($info)) {
            line('getWebhookInfo', false, (string) $tg->lastError());
            break;
        }
        echo 'URL:              ' . ($info['url'] ?? '') . "\n";
        echo 'Pending updates:  ' . ($info['pending_update_count'] ?? 0) . "\n";
        echo 'Last error:       ' . ($info['last_error_message'] ?? 'none') . "\n";
        if (!empty($info['last_error_date'])) {
            echo 'Last error at:    ' . gmdate('Y-m-d H:i:s', (int) $info['last_error_date']) . " UTC\n";
        }
        break;

    case 'delete':
        $ok = $tg->call('deleteWebhook');
        line('deleteWebhook', $ok !== null, (string) $tg->lastError());
        break;

    default:
        http_response_code(400);
        echo "Unknown action. Use check, webhook, info or delete.\n";
}

<?php

// Copy this file to config.php (or, better, to ../wcu-bot-config.php outside public_html)
// and fill in every value. Never commit the real file.
return [
    // From @BotFather.
    'bot_token' => '123456789:REPLACE_ME',

    // Long random string; encrypts everything stored in MySQL. Changing it logs everyone out.
    'secret_key' => 'REPLACE_WITH_A_LONG_RANDOM_STRING',

    // Long random string (A-Z, a-z, 0-9, _ and - only); Telegram sends it with every webhook call.
    'webhook_secret' => 'REPLACE_WITH_ANOTHER_RANDOM_STRING',

    // Public HTTPS URL of webhook.php on your domain.
    'webhook_url' => 'https://example.com/wcu-bot/webhook.php',

    // Long random string; required to open setup.php in the browser (setup.php?key=...).
    'setup_key' => 'REPLACE_WITH_A_THIRD_RANDOM_STRING',

    // MySQL database created in cPanel > MySQL Databases.
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'cpaneluser_wcubot',
        'user' => 'cpaneluser_wcubot',
        'password' => 'REPLACE_ME',
    ],
];

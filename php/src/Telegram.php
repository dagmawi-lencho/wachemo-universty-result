<?php

declare(strict_types=1);

/**
 * Minimal Telegram Bot API client over cURL.
 */
final class Telegram
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    private string $token;
    private ?string $lastError = null;

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * @param array{field: string, path: string, name: string, mime: string}|null $file
     * @return mixed The API "result" value, or null on failure.
     */
    public function call(string $method, array $params = [], ?array $file = null)
    {
        $this->lastError = null;
        if ($file === null) {
            $body = (string) json_encode($params, self::JSON_FLAGS);
            $headers = ['Content-Type: application/json'];
        } else {
            $body = [];
            foreach ($params as $key => $value) {
                $body[$key] = is_array($value) ? (string) json_encode($value, self::JSON_FLAGS) : (string) $value;
            }
            $body[$file['field']] = new CURLFile($file['path'], $file['mime'], $file['name']);
            $headers = [];
        }

        $curl = curl_init('https://api.telegram.org/bot' . $this->token . '/' . $method);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
        ]);
        $raw = curl_exec($curl);
        if ($raw === false) {
            $this->lastError = 'cURL: ' . curl_error($curl);
            return null;
        }

        $response = json_decode((string) $raw, true);
        if (!is_array($response) || empty($response['ok'])) {
            $this->lastError = is_array($response) ? (string) ($response['description'] ?? 'Unknown error') : 'Invalid response';
            return null;
        }
        return $response['result'] ?? true;
    }

    public function sendMessage(int $chatId, string $text, ?array $markup = null, ?string $parseMode = null, ?int $replyTo = null): ?array
    {
        $params = ['chat_id' => $chatId, 'text' => $text];
        if ($markup !== null) {
            $params['reply_markup'] = $markup;
        }
        if ($replyTo !== null) {
            $params['reply_parameters'] = ['message_id' => $replyTo, 'allow_sending_without_reply' => true];
        }
        if ($parseMode !== null) {
            $result = $this->call('sendMessage', $params + ['parse_mode' => $parseMode]);
            if (is_array($result) || !$this->isEntityError()) {
                return is_array($result) ? $result : null;
            }
        }
        $result = $this->call('sendMessage', $params);
        return is_array($result) ? $result : null;
    }

    /**
     * @return bool true when the message now shows the given text (including "not modified").
     */
    public function editMessageText(int $chatId, int $messageId, string $text, ?array $markup = null): bool
    {
        $params = ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text];
        if ($markup !== null) {
            $params['reply_markup'] = $markup;
        }
        if ($this->call('editMessageText', $params) !== null) {
            return true;
        }
        return stripos((string) $this->lastError, 'message is not modified') !== false;
    }

    public function deleteMessage(int $chatId, int $messageId): bool
    {
        return $this->call('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]) !== null;
    }

    public function answerCallbackQuery(string $callbackQueryId): void
    {
        $this->call('answerCallbackQuery', ['callback_query_id' => $callbackQueryId]);
    }

    public function sendChatAction(int $chatId, string $action = 'typing'): void
    {
        $this->call('sendChatAction', ['chat_id' => $chatId, 'action' => $action]);
    }

    public function sendPhotoUrl(int $chatId, string $url, string $caption, ?array $markup = null): ?array
    {
        $params = ['chat_id' => $chatId, 'photo' => $url, 'caption' => $caption];
        if ($markup !== null) {
            $params['reply_markup'] = $markup;
        }
        $result = $this->call('sendPhoto', $params);
        return is_array($result) ? $result : null;
    }

    public function sendPhotoBytes(int $chatId, string $bytes, string $caption, ?array $markup = null): ?array
    {
        $path = tempnam(sys_get_temp_dir(), 'wcu');
        if ($path === false || file_put_contents($path, $bytes) === false) {
            return null;
        }
        try {
            $params = ['chat_id' => $chatId, 'caption' => $caption];
            if ($markup !== null) {
                $params['reply_markup'] = $markup;
            }
            $result = $this->call('sendPhoto', $params, [
                'field' => 'photo',
                'path' => $path,
                'name' => 'profile.jpg',
                'mime' => 'image/jpeg',
            ]);
            return is_array($result) ? $result : null;
        } finally {
            @unlink($path);
        }
    }

    private function isEntityError(): bool
    {
        return stripos((string) $this->lastError, "can't parse entities") !== false;
    }
}

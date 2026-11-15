<?php

declare(strict_types=1);

/**
 * MySQL persistence for per-user conversation state and portal sessions,
 * which the Python bot kept in process memory.
 */
final class Store
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
    private const STALE_USER_DATA_SECONDS = 86400;

    private PDO $pdo;
    private Crypto $crypto;

    public function __construct(PDO $pdo, Crypto $crypto)
    {
        $this->pdo = $pdo;
        $this->crypto = $crypto;
    }

    public static function fromConfig(array $config): self
    {
        $db = $config['db'];
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $db['host'] ?? 'localhost',
            (int) ($db['port'] ?? 3306),
            $db['name']
        );
        $pdo = new PDO($dsn, $db['user'], $db['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return new self($pdo, new Crypto($config['secret_key']));
    }

    public function install(string $schemaSql): void
    {
        foreach (array_filter(array_map('trim', explode(';', $schemaSql))) as $statement) {
            $this->pdo->exec($statement);
        }
    }

    /**
     * Telegram retries webhooks it considers failed; process each update only once.
     */
    public function claimUpdate(int $updateId): bool
    {
        $statement = $this->pdo->prepare('INSERT IGNORE INTO wcu_updates (update_id, received_at) VALUES (?, ?)');
        $statement->execute([$updateId, time()]);
        return $statement->rowCount() === 1;
    }

    public function lock(int $userId): void
    {
        $statement = $this->pdo->prepare('SELECT GET_LOCK(?, 30)');
        $statement->execute(['wcu_bot_user_' . $userId]);
    }

    public function unlock(int $userId): void
    {
        $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
        $statement->execute(['wcu_bot_user_' . $userId]);
    }

    /**
     * @return array{state: ?string, data: array, portal: ?array, portal_expires_at: int}
     */
    public function load(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT conv_state, user_data, portal_session, portal_expires_at FROM wcu_users WHERE tg_id = ?'
        );
        $statement->execute([$userId]);
        $row = $statement->fetch();
        if (!$row) {
            return ['state' => null, 'data' => [], 'portal' => null, 'portal_expires_at' => 0];
        }
        return [
            'state' => $row['conv_state'],
            'data' => $this->decodeJson($row['user_data']) ?? [],
            'portal' => $this->decodeJson($row['portal_session']),
            'portal_expires_at' => (int) $row['portal_expires_at'],
        ];
    }

    public function save(int $userId, ?string $state, array $data, ?array $portal, int $portalExpiresAt): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO wcu_users (tg_id, conv_state, user_data, portal_session, portal_expires_at, updated_at)
             VALUES (:id, :state, :data, :portal, :expires, :now)
             ON DUPLICATE KEY UPDATE conv_state = VALUES(conv_state), user_data = VALUES(user_data),
                 portal_session = VALUES(portal_session), portal_expires_at = VALUES(portal_expires_at),
                 updated_at = VALUES(updated_at)'
        );
        $statement->execute([
            ':id' => $userId,
            ':state' => $state,
            ':data' => $data ? $this->encodeJson($data) : null,
            ':portal' => $portal !== null ? $this->encodeJson($portal) : null,
            ':expires' => $portal !== null ? $portalExpiresAt : 0,
            ':now' => time(),
        ]);
    }

    /**
     * Drop expired portal sessions, old update ids, and idle users' cached grade data.
     */
    public function prune(): void
    {
        $now = time();
        $this->pdo->prepare('DELETE FROM wcu_updates WHERE received_at < ?')->execute([$now - 86400]);
        $this->pdo->prepare(
            'UPDATE wcu_users SET portal_session = NULL, portal_expires_at = 0
             WHERE portal_session IS NOT NULL AND portal_expires_at < ?'
        )->execute([$now]);
        $this->pdo->prepare(
            'UPDATE wcu_users SET user_data = NULL, conv_state = NULL
             WHERE user_data IS NOT NULL AND updated_at < ?'
        )->execute([$now - self::STALE_USER_DATA_SECONDS]);
    }

    private function encodeJson(array $value): string
    {
        return $this->crypto->encrypt((string) json_encode($value, self::JSON_FLAGS));
    }

    private function decodeJson(?string $encrypted): ?array
    {
        $json = $this->crypto->decrypt($encrypted);
        if ($json === null) {
            return null;
        }
        $value = json_decode($json, true);
        return is_array($value) ? $value : null;
    }
}

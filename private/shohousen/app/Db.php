<?php
declare(strict_types=1);

final class Db
{
    /** @var array<string, PDO> */
    private static array $connections = [];

    public static function pdo(?string $name = null): PDO
    {
        $name = $name ?: self::defaultConnectionName();
        if (isset(self::$connections[$name]) && self::$connections[$name] instanceof PDO) {
            return self::$connections[$name];
        }

        $db = self::connectionConfig($name);
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $db['host'], $db['database'], $db['charset'] ?? 'utf8mb4');
        self::$connections[$name] = new PDO($dsn, $db['user'], $db['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return self::$connections[$name];
    }

    public static function branch(): PDO
    {
        return self::pdo('branch');
    }

    public static function knowledge(): PDO
    {
        return self::pdo('knowledge');
    }

    /**
     * Backward-compatible DB config resolver.
     *
     * Existing config.php used a flat `db` array. New code can use named
     * connections: db.branch / db.knowledge / db.company / db.admin.
     */
    private static function connectionConfig(string $name): array
    {
        $root = $GLOBALS['config']['db'] ?? [];

        if (isset($root[$name]) && is_array($root[$name])) {
            return self::normalizeConfig($root[$name]);
        }

        if ($name === 'branch' || $name === self::defaultConnectionName()) {
            return self::normalizeConfig($root);
        }

        // During temporary rental-server builds, allow knowledge DB to fall back
        // to the branch DB if a separate knowledge connection has not yet been set.
        if ($name === 'knowledge' && empty($root['knowledge'])) {
            return self::normalizeConfig($root['branch'] ?? $root);
        }

        throw new RuntimeException('DB接続設定が見つかりません: ' . $name);
    }

    private static function normalizeConfig(array $db): array
    {
        foreach (['host', 'database', 'user', 'password'] as $key) {
            if (!array_key_exists($key, $db) || $db[$key] === '') {
                throw new RuntimeException('DB設定が不足しています: ' . $key);
            }
        }
        $db['charset'] = $db['charset'] ?? 'utf8mb4';
        return $db;
    }

    private static function defaultConnectionName(): string
    {
        return (string)($GLOBALS['config']['app']['default_db_connection'] ?? 'branch');
    }
}

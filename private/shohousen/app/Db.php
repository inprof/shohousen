<?php
declare(strict_types=1);

final class Db
{
    /** @var array<string, PDO> */
    private static array $connections = [];

    public static function pdo(?string $name = null): PDO
    {
        $name = $name ?: self::defaultConnectionName();
        return match ($name) {
            'admin' => self::admin(),
            'company' => self::company(),
            'branch' => self::branch(),
            'knowledge' => self::knowledge(),
            default => self::connectNamed($name),
        };
    }

    public static function admin(): PDO
    {
        return self::connectFixed('admin', 'admin_knowledge');
    }

    public static function knowledge(): PDO
    {
        return self::connectFixed('knowledge', 'admin_knowledge');
    }

    public static function company(?string $dbName = null): PDO
    {
        $dbName = $dbName ?: (string)($_SESSION['company_db_name'] ?? '') ?: self::dbName('default_company');
        return self::connectDynamic('company', 'tenant', $dbName);
    }

    public static function branch(?string $dbName = null): PDO
    {
        $dbName = $dbName ?: (string)($_SESSION['branch_db_name'] ?? '') ?: self::dbName('default_branch');
        return self::connectDynamic('branch', 'tenant', $dbName);
    }

    public static function companyForUid(string $companyUid): PDO
    {
        $assignment = self::findCompanyAssignment($companyUid);
        return self::company($assignment['company_db_name'] ?? self::dbName('default_company'));
    }

    public static function branchForUid(string $branchUid): PDO
    {
        $assignment = self::findBranchAssignment($branchUid);
        return self::branch($assignment['branch_db_name'] ?? self::dbName('default_branch'));
    }

    /** @return array<string,mixed>|null */
    public static function findCompanyAssignment(string $companyUid): ?array
    {
        if ($companyUid === '') {
            return null;
        }
        try {
            if (self::tableExists(self::admin(), 'admin_company_db_assignments')) {
                $stmt = self::admin()->prepare('SELECT * FROM admin_company_db_assignments WHERE company_uid = :company_uid AND status = "active" LIMIT 1');
                $stmt->execute([':company_uid' => $companyUid]);
                $row = $stmt->fetch();
                if ($row) {
                    return $row;
                }
            }
        } catch (Throwable) {
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    public static function findBranchAssignment(string $branchUid): ?array
    {
        if ($branchUid === '') {
            return null;
        }
        $admin = self::admin();

        try {
            if (self::tableExists($admin, 'admin_branch_db_assignments')) {
                $stmt = $admin->prepare('SELECT * FROM admin_branch_db_assignments WHERE branch_uid = :branch_uid AND status = "active" LIMIT 1');
                $stmt->execute([':branch_uid' => $branchUid]);
                $row = $stmt->fetch();
                if ($row) {
                    return $row;
                }
            }
        } catch (Throwable) {
        }

        // 既存の管理者DBスキーマ互換: locations.location_code -> tenant_db_connections -> tenant_db_pool.db_name
        try {
            if (self::tableExists($admin, 'locations') && self::tableExists($admin, 'tenant_db_connections') && self::tableExists($admin, 'tenant_db_pool')) {
                $stmt = $admin->prepare('SELECT
                        t.tenant_code AS company_uid,
                        l.location_code AS branch_uid,
                        l.name AS branch_name,
                        p.db_name AS branch_db_name,
                        c.connection_key
                    FROM locations l
                    INNER JOIN tenants t ON t.id = l.tenant_id
                    INNER JOIN tenant_db_connections c ON c.location_id = l.id
                    LEFT JOIN tenant_db_pool p ON p.connection_key = c.connection_key
                    WHERE l.location_code = :branch_uid AND c.status = "active"
                    LIMIT 1');
                $stmt->execute([':branch_uid' => $branchUid]);
                $row = $stmt->fetch();
                if ($row && !empty($row['branch_db_name'])) {
                    return $row;
                }
            }
        } catch (Throwable) {
        }

        return null;
    }

    public static function tableExists(PDO $pdo, string $tableName): bool
    {
        try {
            $stmt = $pdo->prepare('SHOW TABLES LIKE :table_name');
            $stmt->execute([':table_name' => $tableName]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    public static function columnExists(PDO $pdo, string $tableName, string $columnName): bool
    {
        try {
            $stmt = $pdo->prepare('SHOW COLUMNS FROM `' . str_replace('`', '``', $tableName) . '` LIKE :column_name');
            $stmt->execute([':column_name' => $columnName]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    private static function connectFixed(string $dbNameKey, string $accountKey): PDO
    {
        $dbName = self::dbName($dbNameKey);
        return self::connectDynamic($dbNameKey, $accountKey, $dbName);
    }

    private static function connectDynamic(string $connectionName, string $accountKey, string $dbName): PDO
    {
        if ($dbName === '') {
            throw new RuntimeException('DB名が未設定です: ' . $connectionName);
        }
        $cacheKey = $connectionName . ':' . $dbName;
        if (isset(self::$connections[$cacheKey]) && self::$connections[$cacheKey] instanceof PDO) {
            return self::$connections[$cacheKey];
        }

        $account = self::accountConfig($accountKey);
        $config = [
            'host' => $account['host'],
            'database' => $dbName,
            'user' => $account['user'],
            'password' => $account['password'],
            'charset' => $account['charset'] ?? 'utf8mb4',
        ];
        self::$connections[$cacheKey] = self::makePdo($config);
        return self::$connections[$cacheKey];
    }

    private static function connectNamed(string $name): PDO
    {
        if (isset(self::$connections[$name]) && self::$connections[$name] instanceof PDO) {
            return self::$connections[$name];
        }
        $db = self::legacyConnectionConfig($name);
        self::$connections[$name] = self::makePdo($db);
        return self::$connections[$name];
    }

    private static function makePdo(array $db): PDO
    {
        foreach (['host', 'database', 'user', 'password'] as $key) {
            if (!array_key_exists($key, $db) || $db[$key] === '') {
                throw new RuntimeException('DB設定が不足しています: ' . $key);
            }
        }
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $db['host'], $db['database'], $db['charset'] ?? 'utf8mb4');
        return new PDO($dsn, $db['user'], $db['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    private static function accountConfig(string $accountKey): array
    {
        $accounts = $GLOBALS['config']['db_accounts'] ?? [];
        if (isset($accounts[$accountKey]) && is_array($accounts[$accountKey])) {
            return self::normalizeAccount($accounts[$accountKey]);
        }

        // 旧config互換: db.admin / db.knowledge / db.branch などからアカウント部分だけ流用する。
        $legacy = $GLOBALS['config']['db'] ?? [];
        $fallbackKey = $accountKey === 'admin_knowledge' ? 'admin' : 'branch';
        if (isset($legacy[$fallbackKey]) && is_array($legacy[$fallbackKey])) {
            return self::normalizeAccount($legacy[$fallbackKey]);
        }
        if ($accountKey === 'admin_knowledge' && isset($legacy['knowledge']) && is_array($legacy['knowledge'])) {
            return self::normalizeAccount($legacy['knowledge']);
        }
        if ($accountKey === 'tenant' && isset($legacy['company']) && is_array($legacy['company'])) {
            return self::normalizeAccount($legacy['company']);
        }

        throw new RuntimeException('DB接続アカウント設定が見つかりません: ' . $accountKey);
    }

    private static function normalizeAccount(array $account): array
    {
        foreach (['host', 'user', 'password'] as $key) {
            if (!array_key_exists($key, $account) || $account[$key] === '') {
                throw new RuntimeException('DB接続アカウント設定が不足しています: ' . $key);
            }
        }
        $account['charset'] = $account['charset'] ?? 'utf8mb4';
        return $account;
    }

    private static function dbName(string $key): string
    {
        $names = $GLOBALS['config']['db_names'] ?? [];
        if (!empty($names[$key])) {
            return (string)$names[$key];
        }

        // 旧config互換
        $legacy = $GLOBALS['config']['db'] ?? [];
        $legacyKey = match ($key) {
            'default_company' => 'company',
            'default_branch' => 'branch',
            default => $key,
        };
        if (isset($legacy[$legacyKey]['database']) && $legacy[$legacyKey]['database'] !== '') {
            return (string)$legacy[$legacyKey]['database'];
        }

        return '';
    }

    private static function legacyConnectionConfig(string $name): array
    {
        $root = $GLOBALS['config']['db'] ?? [];
        if (isset($root[$name]) && is_array($root[$name])) {
            return self::normalizeLegacyConfig($root[$name]);
        }
        if ($name === self::defaultConnectionName() && isset($root['branch'])) {
            return self::normalizeLegacyConfig($root['branch']);
        }
        throw new RuntimeException('DB接続設定が見つかりません: ' . $name);
    }

    private static function normalizeLegacyConfig(array $db): array
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

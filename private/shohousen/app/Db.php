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
        return self::company(self::companyDbNameForUid($companyUid));
    }

    public static function branchForUid(string $branchUid): PDO
    {
        return self::branch(self::branchDbNameForUid($branchUid));
    }

    public static function companyUidFromTenantId(int $tenantId): string
    {
        return 'cmp_' . self::formatSuffix($tenantId);
    }

    public static function branchUidFromLocationId(int $locationId): string
    {
        return 'br_' . self::formatSuffix($locationId);
    }

    public static function companyDbNameForUid(string $companyUid): string
    {
        $assignment = self::findCompanyAssignment($companyUid);
        if (!empty($assignment['company_db_name'])) {
            return (string)$assignment['company_db_name'];
        }

        $suffix = self::suffixFromUid($companyUid);
        if ($suffix !== null) {
            return self::buildDbName('company', $suffix);
        }

        return self::dbName('default_company');
    }

    public static function branchDbNameForUid(string $branchUid): string
    {
        $assignment = self::findBranchAssignment($branchUid);
        if (!empty($assignment['branch_db_name'])) {
            return (string)$assignment['branch_db_name'];
        }

        $suffix = self::suffixFromUid($branchUid);
        if ($suffix !== null) {
            return self::buildDbName('branch', $suffix);
        }

        return self::dbName('default_branch');
    }

    /** @return array<string,mixed>|null */
    public static function findCompanyAssignment(string $companyUid): ?array
    {
        if ($companyUid === '') {
            return null;
        }

        $admin = self::admin();
        try {
            if (self::tableExists($admin, 'admin_company_db_assignments')) {
                $stmt = $admin->prepare('SELECT *
                    FROM admin_company_db_assignments
                    WHERE company_uid = :company_uid
                       OR company_code = :company_uid
                    ORDER BY status = "active" DESC, id ASC
                    LIMIT 1');
                $stmt->execute([':company_uid' => $companyUid]);
                $row = $stmt->fetch();
                if ($row && (string)($row['status'] ?? 'active') === 'active') {
                    return $row;
                }
            }
        } catch (Throwable) {
        }

        // 既存管理者DB互換: tenants.id から company DB名を末尾4桁で推定する。
        $suffix = self::suffixFromUid($companyUid);
        if ($suffix !== null) {
            return [
                'company_uid' => $companyUid,
                'company_db_suffix' => $suffix,
                'company_db_name' => self::buildDbName('company', $suffix),
                'status' => 'active',
            ];
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
        $suffix = self::suffixFromUid($branchUid);
        $locationId = $suffix !== null ? (int)$suffix : null;

        try {
            if (self::tableExists($admin, 'admin_branch_db_assignments')) {
                $sql = 'SELECT *
                    FROM admin_branch_db_assignments
                    WHERE branch_uid = :branch_uid';
                $params = [':branch_uid' => $branchUid];
                if ($locationId !== null && $locationId > 0 && self::columnExists($admin, 'admin_branch_db_assignments', 'location_id')) {
                    $sql .= ' OR location_id = :location_id';
                    $params[':location_id'] = $locationId;
                }
                $sql .= ' ORDER BY status = "active" DESC, id ASC LIMIT 1';
                $stmt = $admin->prepare($sql);
                $stmt->execute($params);
                $row = $stmt->fetch();
                if ($row && (string)($row['status'] ?? 'active') === 'active') {
                    return $row;
                }
            }
        } catch (Throwable) {
        }

        // 既存の管理者DBスキーマ互換: locations -> tenant_db_connections -> tenant_db_pool.db_name
        try {
            if ($locationId !== null
                && self::tableExists($admin, 'locations')
                && self::tableExists($admin, 'tenant_db_connections')
                && self::tableExists($admin, 'tenant_db_pool')) {
                $stmt = $admin->prepare('SELECT
                        CONCAT("cmp_", LPAD(t.id, 4, "0")) AS company_uid,
                        CONCAT("br_", LPAD(l.id, 4, "0")) AS branch_uid,
                        l.location_code AS branch_code,
                        l.name AS branch_name,
                        COALESCE(p.db_name, CONCAT(:branch_prefix, LPAD(l.id, 4, "0"))) AS branch_db_name,
                        RIGHT(COALESCE(p.db_name, CONCAT(:branch_prefix2, LPAD(l.id, 4, "0"))), 4) AS branch_db_suffix,
                        c.connection_key
                    FROM locations l
                    INNER JOIN tenants t ON t.id = l.tenant_id
                    LEFT JOIN tenant_db_connections c ON c.location_id = l.id AND c.status = "active"
                    LEFT JOIN tenant_db_pool p ON p.connection_key = c.connection_key
                    WHERE l.id = :location_id
                    LIMIT 1');
                $prefix = self::patternValue('branch_prefix', 'inprof3_tenants');
                $stmt->execute([':location_id' => $locationId, ':branch_prefix' => $prefix, ':branch_prefix2' => $prefix]);
                $row = $stmt->fetch();
                if ($row && !empty($row['branch_db_name'])) {
                    return $row;
                }
            }
        } catch (Throwable) {
        }

        if ($suffix !== null) {
            return [
                'branch_uid' => $branchUid,
                'branch_db_suffix' => $suffix,
                'branch_db_name' => self::buildDbName('branch', $suffix),
                'status' => 'active',
            ];
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
        self::$connections[$cacheKey] = self::makePdo([
            'host' => $account['host'],
            'database' => $dbName,
            'user' => $account['user'],
            'password' => $account['password'],
            'charset' => $account['charset'] ?? 'utf8mb4',
        ]);
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

    private static function buildDbName(string $type, string|int $suffix): string
    {
        $suffix = self::formatSuffix((int)$suffix);
        $prefix = $type === 'company'
            ? self::patternValue('company_prefix', 'inprof3_company')
            : self::patternValue('branch_prefix', 'inprof3_tenants');
        return $prefix . $suffix;
    }

    private static function suffixFromUid(string $uid): ?string
    {
        if (preg_match('/(\d{1,})$/', $uid, $m) === 1) {
            return self::formatSuffix((int)$m[1]);
        }
        return null;
    }

    private static function formatSuffix(int $number): string
    {
        $digits = (int)self::patternValue('suffix_digits', '4');
        $digits = $digits > 0 ? $digits : 4;
        return str_pad((string)$number, $digits, '0', STR_PAD_LEFT);
    }

    private static function patternValue(string $key, string $default): string
    {
        $patterns = $GLOBALS['config']['db_name_patterns'] ?? [];
        return isset($patterns[$key]) && (string)$patterns[$key] !== '' ? (string)$patterns[$key] : $default;
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

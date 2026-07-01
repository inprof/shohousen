<?php
declare(strict_types=1);

final class Auth
{
    /**
     * 会社ログイン。
     * 認証情報は管理者DBの users / tenants を基準にする。
     * company_uid は tenants.id から cmp_0001 形式で生成し、会社DB名は末尾4桁から動的解決する。
     */
    public static function login(string $email, string $password, ?string $role = null): ?array
    {
        $sql = 'SELECT
                    u.*,
                    t.id AS login_tenant_id,
                    t.tenant_code AS company_code,
                    t.name AS company_name,
                    t.status AS company_status,
                    t.plan_name
                FROM users u
                INNER JOIN tenants t ON t.id = u.tenant_id
                WHERE u.email = :email
                  AND u.status = "active"
                  AND t.status IN ("trial", "active", "suspended", "cancelled")
                LIMIT 1';
        $stmt = Db::admin()->prepare($sql);
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return null;
        }

        $normalizedRole = self::normalizeRole((string)($user['role'] ?? ''));
        if ($role !== null && $normalizedRole !== self::normalizeRole($role)) {
            return null;
        }
        $user['role'] = $normalizedRole;
        $user['company_uid'] = Db::companyUidFromTenantId((int)$user['tenant_id']);
        return $user;
    }

    public static function completeLogin(array $user): void
    {
        session_regenerate_id(true);

        $tenantId = (int)$user['tenant_id'];
        $companyUid = (string)($user['company_uid'] ?? Db::companyUidFromTenantId($tenantId));
        $companyDbName = Db::companyDbNameForUid($companyUid);

        $_SESSION['user'] = [
            'id' => (int)$user['id'],
            'tenant_id' => $tenantId,
            'company_uid' => $companyUid,
            'company_code' => (string)($user['company_code'] ?? ''),
            'company_db_name' => $companyDbName,
            'role' => self::normalizeRole((string)$user['role']),
            'name' => (string)$user['name'],
            'email' => (string)$user['email'],
            'tenant_name' => (string)($user['company_name'] ?? ''),
            'company_name' => (string)($user['company_name'] ?? ''),
        ];
        $_SESSION['company_uid'] = $companyUid;
        $_SESSION['company_db_name'] = $companyDbName;

        unset($_SESSION['branch_uid'], $_SESSION['branch_db_name'], $_SESSION['branch_name'], $_SESSION['branch_role'], $_SESSION['location_id']);

        $stmt = Db::admin()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
        $stmt->execute([':id' => $user['id']]);
    }

    public static function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public static function requireLogin(): array
    {
        $user = self::user();
        if (!$user) {
            redirect('/login.php');
        }
        return $user;
    }

    public static function requireBranchSelected(): array
    {
        $user = self::requireLogin();
        if (empty($_SESSION['branch_uid']) || empty($_SESSION['branch_db_name'])) {
            redirect('/branch_select.php');
        }
        return $user;
    }

    public static function requireAdmin(): array
    {
        $user = self::requireLogin();
        if (($user['role'] ?? '') !== 'company_admin') {
            http_response_code(403);
            exit('管理者権限がありません');
        }
        return $user;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public static function issueAdminCode(int $userId): string
    {
        $code = app_config('app.demo_mode', true) ? '123456' : (string)random_int(100000, 999999);
        $hash = password_hash($code, PASSWORD_DEFAULT);
        $stmt = Db::admin()->prepare('INSERT INTO admin_login_codes (user_id, code_hash, expires_at) VALUES (:user_id, :code_hash, DATE_ADD(NOW(), INTERVAL 10 MINUTE))');
        $stmt->execute([':user_id' => $userId, ':code_hash' => $hash]);
        return $code;
    }

    public static function verifyAdminCode(int $userId, string $code): bool
    {
        $stmt = Db::admin()->prepare('SELECT * FROM admin_login_codes WHERE user_id = :user_id AND used_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1');
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($code, $row['code_hash'])) {
            return false;
        }
        $update = Db::admin()->prepare('UPDATE admin_login_codes SET used_at = NOW() WHERE id = :id');
        $update->execute([':id' => $row['id']]);
        return true;
    }

    /** @return array<int,array<string,mixed>> */
    public static function availableBranches(array $user): array
    {
        $tenantId = (int)$user['tenant_id'];
        $admin = Db::admin();
        $companyUidSql = 'CONCAT("cmp_", LPAD(t.id, 4, "0"))';
        $branchUidSql = 'CONCAT("br_", LPAD(l.id, 4, "0"))';

        $hasAssignmentTable = Db::tableExists($admin, 'admin_branch_db_assignments');
        $assignmentJoin = $hasAssignmentTable
            ? 'LEFT JOIN admin_branch_db_assignments ab ON (ab.location_id = l.id OR ab.branch_uid = ' . $branchUidSql . ') AND ab.status = "active"'
            : '';
        $dbSelect = $hasAssignmentTable
            ? 'COALESCE(ab.branch_db_name, p.db_name, CONCAT(:branch_prefix, LPAD(l.id, 4, "0"))) AS branch_db_name,
               COALESCE(ab.branch_db_suffix, RIGHT(COALESCE(p.db_name, CONCAT(:branch_prefix2, LPAD(l.id, 4, "0"))), 4)) AS branch_db_suffix'
            : 'COALESCE(p.db_name, CONCAT(:branch_prefix, LPAD(l.id, 4, "0"))) AS branch_db_name,
               RIGHT(COALESCE(p.db_name, CONCAT(:branch_prefix2, LPAD(l.id, 4, "0"))), 4) AS branch_db_suffix';

        $baseSelect = 'SELECT
                    l.id AS location_id,
                    ' . $companyUidSql . ' AS company_uid,
                    ' . $branchUidSql . ' AS branch_uid,
                    l.location_code AS branch_code,
                    l.name AS branch_name,
                    l.status AS branch_status,
                    COALESCE(ul.role_at_location, "manager") AS role_at_location,
                    COALESCE(ul.is_default, 0) AS is_default,
                    c.connection_key,
                    ' . $dbSelect . '
                FROM locations l
                INNER JOIN tenants t ON t.id = l.tenant_id
                LEFT JOIN user_locations ul ON ul.location_id = l.id AND ul.user_id = :user_id AND ul.status = "active"
                LEFT JOIN tenant_db_connections c ON c.location_id = l.id AND c.status = "active"
                LEFT JOIN tenant_db_pool p ON p.connection_key = c.connection_key
                ' . $assignmentJoin . '
                WHERE l.tenant_id = :tenant_id
                  AND l.status = "active"';

        $params = [
            ':user_id' => (int)$user['id'],
            ':tenant_id' => $tenantId,
            ':branch_prefix' => self::branchPrefix(),
            ':branch_prefix2' => self::branchPrefix(),
        ];

        try {
            if (($user['role'] ?? '') === 'company_admin') {
                $stmt = $admin->prepare($baseSelect . ' ORDER BY COALESCE(ul.is_default, 0) DESC, l.id ASC');
            } else {
                $stmt = $admin->prepare($baseSelect . ' AND ul.user_id IS NOT NULL ORDER BY ul.is_default DESC, l.id ASC');
            }
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            return self::decorateBranchRows($rows);
        } catch (Throwable) {
            return [];
        }
    }

    public static function selectBranch(array $user, string $branchUid): bool
    {
        foreach (self::availableBranches($user) as $branch) {
            if ((string)$branch['branch_uid'] !== $branchUid) {
                continue;
            }
            $dbName = (string)($branch['branch_db_name'] ?? '');
            if ($dbName === '') {
                $dbName = Db::branchDbNameForUid($branchUid);
            }
            if ($dbName === '') {
                return false;
            }
            $_SESSION['branch_uid'] = $branchUid;
            $_SESSION['branch_db_name'] = $dbName;
            $_SESSION['branch_name'] = (string)$branch['branch_name'];
            $_SESSION['branch_role'] = (string)($branch['role_at_location'] ?? 'staff');
            $_SESSION['location_id'] = (int)$branch['location_id'];

            $_SESSION['user']['branch_uid'] = $branchUid;
            $_SESSION['user']['branch_db_name'] = $dbName;
            $_SESSION['user']['branch_name'] = (string)$branch['branch_name'];
            $_SESSION['user']['branch_role'] = (string)($branch['role_at_location'] ?? 'staff');
            $_SESSION['user']['location_id'] = (int)$branch['location_id'];
            return true;
        }
        return false;
    }

    public static function normalizeRole(string $role): string
    {
        return match ($role) {
            'admin', 'tenant_admin', 'company_admin' => 'company_admin',
            'pharmacy_user', 'user', 'staff', '' => 'pharmacy_user',
            default => $role,
        };
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
    private static function decorateBranchRows(array $rows): array
    {
        foreach ($rows as &$row) {
            if (empty($row['branch_uid']) && !empty($row['location_id'])) {
                $row['branch_uid'] = Db::branchUidFromLocationId((int)$row['location_id']);
            }
            if (empty($row['branch_db_name']) && !empty($row['branch_uid'])) {
                $row['branch_db_name'] = Db::branchDbNameForUid((string)$row['branch_uid']);
            }
        }
        unset($row);
        return $rows;
    }

    private static function branchPrefix(): string
    {
        return (string)app_config('db_name_patterns.branch_prefix', 'inprof3_tenants');
    }
}

<?php
declare(strict_types=1);

final class Auth
{
    public static function login(string $email, string $password, ?string $role = null): ?array
    {
        $sql = 'SELECT u.*, t.name AS tenant_name, t.status AS tenant_status
                FROM users u
                INNER JOIN tenants t ON t.id = u.tenant_id
                WHERE u.email = :email AND u.status = "active" LIMIT 1';
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return null;
        }
        if ($role !== null && $user['role'] !== $role) {
            return null;
        }
        return $user;
    }

    public static function completeLogin(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => (int)$user['id'],
            'tenant_id' => (int)$user['tenant_id'],
            'role' => $user['role'],
            'name' => $user['name'],
            'email' => $user['email'],
            'tenant_name' => $user['tenant_name'] ?? '',
        ];
        $stmt = Db::pdo()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
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

    public static function requireAdmin(): array
    {
        $user = self::requireLogin();
        if ($user['role'] !== 'tenant_admin') {
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
        $stmt = Db::pdo()->prepare('INSERT INTO admin_login_codes (user_id, code_hash, expires_at) VALUES (:user_id, :code_hash, DATE_ADD(NOW(), INTERVAL 10 MINUTE))');
        $stmt->execute([':user_id' => $userId, ':code_hash' => $hash]);
        return $code;
    }

    public static function verifyAdminCode(int $userId, string $code): bool
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM admin_login_codes WHERE user_id = :user_id AND used_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1');
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($code, $row['code_hash'])) {
            return false;
        }
        $update = Db::pdo()->prepare('UPDATE admin_login_codes SET used_at = NOW() WHERE id = :id');
        $update->execute([':id' => $row['id']]);
        return true;
    }
}

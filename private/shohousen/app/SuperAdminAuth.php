<?php
declare(strict_types=1);

final class SuperAdminAuth
{
    public static function login(string $email, string $password): ?array
    {
        $email = trim($email);
        if ($email === '' || $password === '') {
            return null;
        }

        $stmt = Db::admin()->prepare(
            'SELECT id, email, password_hash, name, status, last_login_at
               FROM super_admin_users
              WHERE email = :email
                AND status = "active"
              LIMIT 1'
        );
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, (string)$user['password_hash'])) {
            return null;
        }

        return $user;
    }

    public static function completeLogin(array $user): void
    {
        session_regenerate_id(true);

        $_SESSION['super_admin'] = [
            'id' => (int)$user['id'],
            'email' => (string)$user['email'],
            'name' => (string)$user['name'],
            'role' => 'super_admin',
            'login_at' => date('Y-m-d H:i:s'),
        ];

        $stmt = Db::admin()->prepare('UPDATE super_admin_users SET last_login_at = NOW() WHERE id = :id');
        $stmt->execute([':id' => (int)$user['id']]);
    }

    public static function user(): ?array
    {
        return $_SESSION['super_admin'] ?? null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function isLoggedIn(): bool
    {
        return self::check();
    }

    public static function requireLogin(): array
    {
        $user = self::user();
        if (!$user) {
            redirect('/superadmin_login.php');
        }
        return $user;
    }

    public static function requireSuperAdmin(): array
    {
        return self::requireLogin();
    }

    public static function logout(): void
    {
        unset($_SESSION['super_admin']);
    }
}

<?php
declare(strict_types=1);

final class SuperAdminView
{
    public static function header(string $title, array $options = []): void
    {
        $user = SuperAdminAuth::user();
        $bodyClass = trim('superadmin ' . (string)($options['body_class'] ?? ''));

        echo '<!doctype html><html lang="ja"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
        echo '<title>' . h($title) . ' | SuperAdmin | ' . h(app_config('app.name', 'PharmaAssist')) . '</title>';
        echo '<link rel="stylesheet" href="' . h(app_url('/assets/css/app.css')) . '">';
        echo '</head><body class="' . h($bodyClass) . '">';
        echo '<header class="app-header superadmin-header">';
        echo '<a class="brand" href="' . h(app_url('/superadmin_home.php')) . '"><span class="brand-icon">⚙</span><span>SuperAdmin</span></a>';

        if ($user) {
            echo '<nav class="header-nav">';
            echo '<span>' . h((string)($user['name'] ?? 'superAdmin')) . ' 様</span>';
            echo '<a href="' . h(app_url('/superadmin_home.php')) . '">ホーム</a>';
            echo '<a href="' . h(app_url('/superadmin_logout.php')) . '">ログアウト</a>';
            echo '</nav>';
        } else {
            echo '<nav class="header-nav">';
            echo '<a href="' . h(app_url('/superadmin_login.php')) . '">ログイン</a>';
            echo '</nav>';
        }

        echo '</header><main class="app-main superadmin-main">';
    }

    public static function footer(): void
    {
        echo '</main><script src="' . h(app_url('/assets/js/app.js')) . '"></script></body></html>';
    }
}

<?php
declare(strict_types=1);

final class View
{
    public static function header(string $title, array $options = []): void
    {
        $user = Auth::user();
        $bodyClass = $options['body_class'] ?? '';
        echo '<!doctype html><html lang="ja"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
        echo '<title>' . h($title) . ' | ' . h(app_config('app.name', 'PharmaAssist')) . '</title>';
        echo '<link rel="stylesheet" href="' . h(app_url('/assets/css/app.css')) . '">';
        $styles = $options['styles'] ?? [];
        if (is_string($styles)) {
            $styles = [$styles];
        }
        if (is_array($styles)) {
            foreach ($styles as $stylePath) {
                $stylePath = trim((string)$stylePath);
                if ($stylePath === '') {
                    continue;
                }
                echo '<link rel="stylesheet" href="' . h(app_url($stylePath)) . '">';
            }
        }
        echo '</head><body class="' . h($bodyClass) . '">';
        echo '<header class="app-header">';
        echo '<a class="brand" href="' . h(app_url('/index.php')) . '"><span class="brand-icon">✚</span><span>' . h(app_config('app.name', 'PharmaAssist')) . '</span></a>';
        if ($user) {
            echo '<nav class="header-nav"><span>' . h($user['name']) . ' 様</span><a href="' . h(app_url('/menu.php')) . '">メニュー</a><a href="' . h(app_url('/logout.php')) . '">ログアウト</a></nav>';
        } else {
            echo '<nav class="header-nav"><a href="' . h(app_url('/index.php#service')) . '">サービス</a><a href="' . h(app_url('/index.php#price')) . '">料金</a><a href="' . h(app_url('/login.php')) . '">ログイン</a></nav>';
        }
        echo '</header><main class="app-main">';
    }

    public static function footer(): void
    {
        echo '</main><script src="' . h(app_url('/assets/js/app.js')) . '"></script></body></html>';
    }
}

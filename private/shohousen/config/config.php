<?php
return [
    // DB接続ユーザーは2系統です。
    // 1) admin_knowledge: 管理者DB + 補助学習DBを参照できるDBユーザー
    // 2) tenant: 会社DB + 拠点DBを参照できるDBユーザー
    'db_accounts' => [
        'admin_knowledge' => [
            'host' => 'localhost',
            'user' => 'YOUR_ADMIN_KNOWLEDGE_DB_USER',
            'password' => 'YOUR_ADMIN_KNOWLEDGE_DB_PASSWORD',
            'charset' => 'utf8mb4',
        ],
        'tenant' => [
            'host' => 'localhost',
            'user' => 'YOUR_COMPANY_BRANCH_DB_USER',
            'password' => 'YOUR_COMPANY_BRANCH_DB_PASSWORD',
            'charset' => 'utf8mb4',
        ],
    ],

    // 固定DB名とテスト用デフォルトDB名。
    // 会社DB/拠点DBはログイン後に company_uid / branch_uid から動的解決します。
    'db_names' => [
        // 管理者DB: tenants / users / locations / DB割当を持つDB
        'admin' => 'inprof3_prescription',

        // 補助学習DB: テンプレート、補正ルール、薬品辞書、出力マッピング
        'knowledge' => 'inprof3_assistantdata',

        // 未ログイン時・開発時のfallback。通常運用では動的解決が優先されます。
        'default_company' => 'inprof3_company0001',
        'default_branch' => 'inprof3_tenants0001',
    ],

    // DB名の採番ルール。末尾4桁を company_uid / branch_uid から組み立てます。
    // cmp_0001 -> inprof3_company0001
    // br_0001  -> inprof3_tenants0001
    'db_name_patterns' => [
        'company_prefix' => 'inprof3_company',
        'branch_prefix' => 'inprof3_tenants',
        'suffix_digits' => 4,
    ],

    // 旧形式互換用。新規実装では db_accounts / db_names / db_name_patterns を優先します。
    'db' => [],

    'app' => [
        'name' => 'PharmaAssist',
        'base_url' => '',
        'base_path' => '',
        'timezone' => 'Asia/Tokyo',
        'demo_mode' => true,
        'default_db_connection' => 'branch',

        // 未ログイン時のfallback。会社/拠点切替はconfigを書き換えず、ログイン情報から変動します。
        'fallback_company_uid' => 'cmp_0001',
        'fallback_branch_uid' => 'br_0001',
    ],

    'openai' => [
        'api_key' => 'YOUR_OPENAI_API_KEY',
        'model' => 'gpt-4o-mini',
        'vision_detail' => 'high',
        'timeout_seconds' => 60,
    ],

    'storage' => [
        'prescription_dir' => dirname(__DIR__) . '/storage/prescriptions',
    ],
];

<?php
return [
    // DB接続ユーザーは2系統。
    // 1) admin_knowledge: 管理者DB + 補助学習DBを参照できるアカウント
    // 2) tenant: 会社DB + 拠点DBを参照できるアカウント
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

    // 固定DB名と開発用デフォルトDB名。
    // SaaS本運用では会社/拠点DB名は管理者DBの割当テーブルから取得する。
    'db_names' => [
        'admin' => 'YOUR_ADMIN_DATABASE_NAME',
        'knowledge' => 'YOUR_KNOWLEDGE_DATABASE_NAME',
        'default_company' => 'YOUR_DEFAULT_COMPANY_DATABASE_NAME',
        'default_branch' => 'YOUR_DEFAULT_BRANCH_DATABASE_NAME',
    ],

    // 旧形式互換用。新規実装では db_accounts / db_names を優先する。
    'db' => [],

    'app' => [
        'name' => 'PharmaAssist',
        'base_url' => '',
        'base_path' => '',
        'timezone' => 'Asia/Tokyo',
        'demo_mode' => true,
        'default_db_connection' => 'branch',
        'default_company_uid' => 'cmp_dev_0001',
        'default_branch_uid' => 'br_dev_0001',
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

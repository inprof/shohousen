<?php
return [
    'db' => [
        // 拠点DB：患者、処方箋、AI解析ジョブ、画像メタ情報を保存する。
        'branch' => [
            'host' => 'localhost',
            'database' => 'YOUR_BRANCH_DATABASE_NAME',
            'user' => 'YOUR_BRANCH_DATABASE_USER',
            'password' => 'YOUR_BRANCH_DATABASE_PASSWORD',
            'charset' => 'utf8mb4',
        ],
        // 補助学習型DB：テンプレート、補正ルール、薬品辞書、出力マッピングを保存する。
        'knowledge' => [
            'host' => 'localhost',
            'database' => 'YOUR_KNOWLEDGE_DATABASE_NAME',
            'user' => 'YOUR_KNOWLEDGE_DATABASE_USER',
            'password' => 'YOUR_KNOWLEDGE_DATABASE_PASSWORD',
            'charset' => 'utf8mb4',
        ],
    ],
    'app' => [
        'name' => 'PharmaAssist',
        'base_url' => '',
        'base_path' => '',
        'timezone' => 'Asia/Tokyo',
        'demo_mode' => true,
        'default_db_connection' => 'branch',
        // 会社・拠点連携が未完成の間は仮UIDで固定する。
        'company_uid' => 'cmp_dev_0001',
        'branch_uid' => 'br_dev_0001',
    ],
    'openai' => [
        'api_key' => 'YOUR_OPENAI_API_KEY',
        'model' => 'gpt-4o-mini',
        'vision_detail' => 'high',
        'timeout_seconds' => 60,
    ],
    'storage' => [
        // public_html と同階層の private 配下に保存する前提。
        'prescription_dir' => dirname(__DIR__) . '/storage/prescriptions',
    ],
];

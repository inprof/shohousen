<?php
declare(strict_types=1);

final class OpenAiPrescriptionClient
{
    public function extractFromImage(string $imagePath, string $mimeType, ?array $templateHint = null): array
    {
        $apiKey = trim((string)app_config('openai.api_key', ''));
        if ($apiKey === '') {
            if ((bool)app_config('app.demo_mode', false)) {
                return [
                    'raw' => ['demo' => true],
                    'normalized' => self::demoNormalized(),
                    'model' => 'demo',
                ];
            }
            throw new RuntimeException('OpenAI APIキーが未設定です。');
        }

        $schema = self::responseSchema();
        $prompt = self::systemPrompt($templateHint);
        $base64 = base64_encode((string)file_get_contents($imagePath));
        $detail = (string)app_config('openai.vision_detail', 'high');

        $payload = [
            'model' => (string)app_config('openai.model', 'gpt-4o-mini'),
            'input' => [
                [
                    'role' => 'system',
                    'content' => [
                        ['type' => 'input_text', 'text' => $prompt],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'input_text', 'text' => '添付画像の処方箋を読み取り、指定JSON Schemaだけで出力してください。不明な項目は空文字またはnullにし、推測した場合はneeds_human_checkをtrueにしてください。'],
                        [
                            'type' => 'input_image',
                            'image_url' => 'data:' . $mimeType . ';base64,' . $base64,
                            'detail' => in_array($detail, ['low', 'high', 'auto'], true) ? $detail : 'high',
                        ],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'prescription_extraction',
                    'strict' => true,
                    'schema' => $schema,
                ],
            ],
            'max_output_tokens' => 3500,
        ];

        $response = $this->postJson('https://api.openai.com/v1/responses', $payload, $apiKey);
        $jsonText = self::extractOutputText($response);
        $normalized = json_decode($jsonText, true);
        if (!is_array($normalized)) {
            throw new RuntimeException('OpenAIレスポンスJSONの解析に失敗しました。');
        }

        return [
            'raw' => $response,
            'normalized' => self::normalize($normalized),
            'model' => (string)app_config('openai.model', 'gpt-4o-mini'),
        ];
    }

    private function postJson(string $url, array $payload, string $apiKey): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl初期化に失敗しました。');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT => (int)app_config('openai.timeout_seconds', 60),
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $body === '') {
            throw new RuntimeException('OpenAI API通信に失敗しました: ' . $error);
        }
        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('OpenAI APIレスポンスがJSONではありません。HTTP ' . $status);
        }
        if ($status < 200 || $status >= 300) {
            $message = $decoded['error']['message'] ?? ('HTTP ' . $status);
            throw new RuntimeException('OpenAI APIエラー: ' . $message);
        }
        return $decoded;
    }

    private static function extractOutputText(array $response): string
    {
        if (isset($response['output_text']) && is_string($response['output_text'])) {
            return $response['output_text'];
        }
        foreach (($response['output'] ?? []) as $output) {
            foreach (($output['content'] ?? []) as $content) {
                if (($content['type'] ?? '') === 'output_text' && isset($content['text'])) {
                    return (string)$content['text'];
                }
                if (isset($content['text']) && is_string($content['text'])) {
                    return $content['text'];
                }
            }
        }
        throw new RuntimeException('OpenAI APIレスポンスから出力テキストを取得できません。');
    }

    private static function systemPrompt(?array $templateHint): string
    {
        $template = '';
        if ($templateHint) {
            $template = "\n既知テンプレート情報: " . json_encode($templateHint, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return <<<PROMPT
あなたは日本の薬局で利用する処方箋OCR補助エンジンです。
医療情報のため、不明点は推測で埋めず、空欄・null・needs_human_check=trueで返してください。
患者情報、保険情報、医療機関情報、処方薬情報を抽出します。
出力は必ず指定JSON Schemaに従い、余計な文章を含めないでください。
数字、日付、薬品名、用法、日数は特に慎重に扱ってください。
薬品名や用法が読みにくい場合はneeds_human_check=trueにしてください。
{$template}
PROMPT;
    }

    public static function responseSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['patient', 'insurance', 'prescription', 'medical_institution', 'medications', 'warnings', 'overall_confidence'],
            'properties' => [
                'patient' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['name', 'kana', 'birth_date', 'gender', 'confidence', 'needs_human_check'],
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'kana' => ['type' => 'string'],
                        'birth_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD。読めない場合は空文字。'],
                        'gender' => ['type' => 'string', 'enum' => ['男性', '女性', '不明', '']],
                        'confidence' => ['type' => 'number'],
                        'needs_human_check' => ['type' => 'boolean'],
                    ],
                ],
                'insurance' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['insurance_no', 'insured_symbol_number', 'copay_rate', 'confidence', 'needs_human_check'],
                    'properties' => [
                        'insurance_no' => ['type' => 'string'],
                        'insured_symbol_number' => ['type' => 'string'],
                        'copay_rate' => ['type' => 'string'],
                        'confidence' => ['type' => 'number'],
                        'needs_human_check' => ['type' => 'boolean'],
                    ],
                ],
                'prescription' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['issued_on', 'expires_on', 'confidence', 'needs_human_check'],
                    'properties' => [
                        'issued_on' => ['type' => 'string'],
                        'expires_on' => ['type' => 'string'],
                        'confidence' => ['type' => 'number'],
                        'needs_human_check' => ['type' => 'boolean'],
                    ],
                ],
                'medical_institution' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['code', 'name', 'doctor_name', 'phone', 'confidence', 'needs_human_check'],
                    'properties' => [
                        'code' => ['type' => 'string'],
                        'name' => ['type' => 'string'],
                        'doctor_name' => ['type' => 'string'],
                        'phone' => ['type' => 'string'],
                        'confidence' => ['type' => 'number'],
                        'needs_human_check' => ['type' => 'boolean'],
                    ],
                ],
                'medications' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['drug_name', 'dose_text', 'usage_text', 'days_count', 'amount_text', 'confidence', 'needs_human_check', 'reason'],
                        'properties' => [
                            'drug_name' => ['type' => 'string'],
                            'dose_text' => ['type' => 'string'],
                            'usage_text' => ['type' => 'string'],
                            'days_count' => ['type' => ['integer', 'null']],
                            'amount_text' => ['type' => 'string'],
                            'confidence' => ['type' => 'number'],
                            'needs_human_check' => ['type' => 'boolean'],
                            'reason' => ['type' => 'string'],
                        ],
                    ],
                ],
                'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
                'overall_confidence' => ['type' => 'number'],
            ],
        ];
    }

    public static function normalize(array $value): array
    {
        $demo = self::demoNormalized();
        return array_replace_recursive($demo, $value);
    }

    public static function demoNormalized(): array
    {
        return [
            'patient' => ['name' => '山田 花子', 'kana' => '', 'birth_date' => '1975-04-12', 'gender' => '女性', 'confidence' => 94.2, 'needs_human_check' => true],
            'insurance' => ['insurance_no' => '12345678', 'insured_symbol_number' => '987654321', 'copay_rate' => '3割', 'confidence' => 90.0, 'needs_human_check' => true],
            'prescription' => ['issued_on' => '2024-05-20', 'expires_on' => '', 'confidence' => 92.0, 'needs_human_check' => true],
            'medical_institution' => ['code' => '1312345', 'name' => 'さくらクリニック', 'doctor_name' => '', 'phone' => '', 'confidence' => 90.0, 'needs_human_check' => true],
            'medications' => [
                ['drug_name' => 'アムロジピン0D錠5mg', 'dose_text' => '', 'usage_text' => '1日1回 朝食後', 'days_count' => 28, 'amount_text' => '28日分', 'confidence' => 78.0, 'needs_human_check' => true, 'reason' => 'デモデータ。0D/OD確認が必要。'],
                ['drug_name' => 'ムコソルバン錠15mg', 'dose_text' => '', 'usage_text' => '1日3回 毎食後', 'days_count' => 28, 'amount_text' => '28日分', 'confidence' => 88.0, 'needs_human_check' => true, 'reason' => 'デモデータ。'],
            ],
            'warnings' => ['デモモードの解析結果です。OpenAI APIキー設定後に実解析へ切り替わります。'],
            'overall_confidence' => 86.0,
        ];
    }
}

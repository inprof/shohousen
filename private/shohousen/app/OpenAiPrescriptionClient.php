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
処方箋の様式は医療機関・拠点ごとに異なるため、固定テンプレートだけに寄せず、画像内に見える項目名と値をできる限り form_fields に列挙してください。
form_fields には、空欄でも帳票上に存在する主要項目を入れてください。枠だけ存在して値が空欄の項目も is_empty_cell=true, value="" として残してください。例: 公費負担者番号、公費負担医療の受給者番号、保険者番号、被保険者証の記号番号、患者氏名、フリガナ、生年月日、性別、区分、交付年月日、処方箋使用期間、医療機関所在地、医療機関名、電話番号、保険医氏名、都道府県番号、点数表番号、医療機関コード、備考、保険医署名、薬品名、用量、用法、日数、QR有無など。
画面側では field_group, value_type, ui_template を見て、事前に用意した入力枠テンプレートへ配置します。画像内に見える項目は、固定項目に入らなくても form_fields に残してください。display_order は帳票上で上から下・左から右の順で付けてください。
出力に使うかどうかは人間が後で選択するため、include_default は「通常出力に使いそうな項目」だけ true にし、それ以外も form_fields には残してください。
出力は必ず指定JSON Schemaに従い、余計な文章を含めないでください。
数字、日付、薬品名、用法、日数は特に慎重に扱ってください。
薬品名や用法が読みにくい場合はneeds_human_check=trueにしてください。
薬品名については、一般名・商品名・販売名・後発品名・先発品名・屋号違いをできるだけ区別してください。
同じ処方ブロック内に商品名と一般名が併記されている場合は、別薬として分けず、原則1つのmedications要素にまとめてください。サーバー側では一般名処方マスタ由来の候補も補助的に照合しますが、画像解析時点では帳票上の文字列を優先してください。
その場合、drug_nameには薬局で表示・保存したい代表名、generic_nameには一般名候補、brand_nameには商品名候補、raw_drug_textには画像内で読めた薬品名行を改行区切りで入れてください。
同一薬か別薬か判断できない場合は、分けてもよいですが name_relation="multiple_candidates" とし、needs_human_check=trueにしてください。
{$template}
PROMPT;
    }

    public static function responseSchema(): array
    {
        $fieldItemSchema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'field_key',
                'field_label',
                'field_group',
                'value',
                'value_type',
                'source_section',
                'confidence',
                'needs_human_check',
                'include_default',
                'output_candidate',
                'reason',
                'ui_template',
                'display_order',
                'is_empty_cell'
            ],
            'properties' => [
                'field_key' => ['type' => 'string', 'description' => 'snake_case。分からない場合は項目名から生成。例: patient_name, public_expense_beneficiary_number'],
                'field_label' => ['type' => 'string', 'description' => '帳票上または人間に表示する項目名。例: 氏名、保険者番号、公費負担者番号'],
                'field_group' => ['type' => 'string', 'enum' => ['patient', 'insurance', 'public_expense', 'prescription', 'medical_institution', 'medication', 'pharmacy', 'note', 'qr', 'other']],
                'value' => ['type' => 'string', 'description' => '読み取った値。空欄なら空文字。'],
                'value_type' => ['type' => 'string', 'enum' => ['text', 'date', 'number', 'code', 'person_name', 'drug', 'usage', 'amount', 'boolean', 'unknown']],
                'ui_template' => ['type' => 'string', 'enum' => ['input', 'textarea', 'date', 'number', 'select', 'checkbox', 'drug_line', 'blank_cell', 'unknown'], 'description' => '画面表示用の入力枠テンプレート。空欄枠ならblank_cell。'],
                'display_order' => ['type' => 'integer', 'description' => '帳票上の表示順。上から下、左から右の順。'],
                'is_empty_cell' => ['type' => 'boolean', 'description' => '帳票上に枠はあるが値が空欄ならtrue。'],
                'source_section' => ['type' => 'string', 'description' => '帳票上のおおまかな場所。例: 上部左、上部右、処方欄、下部QR'],
                'confidence' => ['type' => 'number'],
                'needs_human_check' => ['type' => 'boolean'],
                'include_default' => ['type' => 'boolean', 'description' => '通常出力・QR化の候補ならtrue。判断に迷う場合はfalse。'],
                'output_candidate' => ['type' => 'boolean', 'description' => '出力項目として選択候補に出す場合true。原則true。'],
                'reason' => ['type' => 'string'],
            ],
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['patient', 'insurance', 'prescription', 'medical_institution', 'medications', 'form_fields', 'warnings', 'overall_confidence'],
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
                        'required' => ['drug_name', 'generic_name', 'brand_name', 'raw_drug_text', 'name_relation', 'dose_text', 'usage_text', 'days_count', 'amount_text', 'confidence', 'needs_human_check', 'reason'],
                        'properties' => [
                            'drug_name' => ['type' => 'string', 'description' => '代表として扱う薬品名。商品名・一般名併記時は人間が確認しやすい代表名。'],
                            'generic_name' => ['type' => 'string', 'description' => '一般名・般名・成分名候補。無ければ空文字。'],
                            'brand_name' => ['type' => 'string', 'description' => '商品名・販売名候補。無ければ空文字。'],
                            'raw_drug_text' => ['type' => 'string', 'description' => '処方欄に見えた薬品名関連行を改行区切りで保持。'],
                            'name_relation' => ['type' => 'string', 'enum' => ['single', 'generic_brand_pair', 'multiple_candidates', 'unknown']],
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
                'form_fields' => [
                    'type' => 'array',
                    'description' => '帳票内に見える全項目の読み取り結果。固定項目に入らない項目や空欄項目も含める。',
                    'items' => $fieldItemSchema,
                ],
                'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
                'overall_confidence' => ['type' => 'number'],
            ],
        ];
    }

    public static function normalize(array $value): array
    {
        $normalized = array_replace_recursive(self::blankNormalized(), $value);
        $normalized['medications'] = self::normalizeMedications(is_array($normalized['medications'] ?? null) ? $normalized['medications'] : []);
        $normalized['form_fields'] = self::normalizeFormFields($normalized);
        return $normalized;
    }

    public static function blankNormalized(): array
    {
        return [
            'patient' => ['name' => '', 'kana' => '', 'birth_date' => '', 'gender' => '不明', 'confidence' => 0.0, 'needs_human_check' => true],
            'insurance' => ['insurance_no' => '', 'insured_symbol_number' => '', 'copay_rate' => '', 'confidence' => 0.0, 'needs_human_check' => true],
            'prescription' => ['issued_on' => '', 'expires_on' => '', 'confidence' => 0.0, 'needs_human_check' => true],
            'medical_institution' => ['code' => '', 'name' => '', 'doctor_name' => '', 'phone' => '', 'confidence' => 0.0, 'needs_human_check' => true],
            'medications' => [],
            'form_fields' => [],
            'warnings' => [],
            'overall_confidence' => 0.0,
        ];
    }

    public static function demoNormalized(): array
    {
        return self::normalize([
            'patient' => ['name' => '山田 花子', 'kana' => '', 'birth_date' => '1975-04-12', 'gender' => '女性', 'confidence' => 94.2, 'needs_human_check' => true],
            'insurance' => ['insurance_no' => '12345678', 'insured_symbol_number' => '987654321', 'copay_rate' => '3割', 'confidence' => 90.0, 'needs_human_check' => true],
            'prescription' => ['issued_on' => '2024-05-20', 'expires_on' => '', 'confidence' => 88.0, 'needs_human_check' => true],
            'medical_institution' => ['code' => '1312345', 'name' => 'さくらクリニック', 'doctor_name' => '', 'phone' => '', 'confidence' => 86.0, 'needs_human_check' => true],
            'medications' => [
                ['drug_name' => 'アムロジピンOD錠5mg', 'generic_name' => 'アムロジピンOD錠5mg', 'brand_name' => '', 'raw_drug_text' => 'アムロジピンOD錠5mg', 'name_relation' => 'single', 'dose_text' => '1錠', 'usage_text' => '1日1回 朝食後', 'days_count' => 28, 'amount_text' => '28日分', 'confidence' => 89.0, 'needs_human_check' => true, 'reason' => 'demo'],
                ['drug_name' => 'ムコソルバン錠15mg', 'generic_name' => 'アンブロキソール塩酸塩錠15mg', 'brand_name' => 'ムコソルバン錠15mg', 'raw_drug_text' => "ムコソルバン錠15mg
【般】アンブロキソール塩酸塩錠15mg", 'name_relation' => 'generic_brand_pair', 'dose_text' => '1錠', 'usage_text' => '1日3回 毎食後', 'days_count' => 28, 'amount_text' => '28日分', 'confidence' => 87.0, 'needs_human_check' => true, 'reason' => 'demo'],
            ],
            'form_fields' => [
                ['field_key' => 'patient_name', 'field_label' => '氏名', 'field_group' => 'patient', 'value' => '山田 花子', 'value_type' => 'person_name', 'source_section' => '患者欄', 'confidence' => 94.2, 'needs_human_check' => true, 'include_default' => true, 'output_candidate' => true, 'ui_template' => 'input', 'display_order' => 10, 'is_empty_cell' => false, 'reason' => 'demo'],
                ['field_key' => 'insurance_no', 'field_label' => '保険者番号', 'field_group' => 'insurance', 'value' => '12345678', 'value_type' => 'code', 'source_section' => '保険欄', 'confidence' => 90.0, 'needs_human_check' => true, 'include_default' => true, 'output_candidate' => true, 'ui_template' => 'input', 'display_order' => 20, 'is_empty_cell' => false, 'reason' => 'demo'],
                ['field_key' => 'medical_institution_name', 'field_label' => '保険医療機関の名称', 'field_group' => 'medical_institution', 'value' => 'さくらクリニック', 'value_type' => 'text', 'source_section' => '医療機関欄', 'confidence' => 86.0, 'needs_human_check' => true, 'include_default' => true, 'output_candidate' => true, 'ui_template' => 'input', 'display_order' => 30, 'is_empty_cell' => false, 'reason' => 'demo'],
            ],
            'warnings' => ['demo data'],
            'overall_confidence' => 91.2,
        ]);
    }

    /** @param array<int,mixed> $medications @return array<int,array<string,mixed>> */
    private static function normalizeMedications(array $medications): array
    {
        $out = [];
        foreach ($medications as $med) {
            if (!is_array($med)) {
                continue;
            }
            $relation = (string)($med['name_relation'] ?? 'unknown');
            if (!in_array($relation, ['single', 'generic_brand_pair', 'multiple_candidates', 'unknown'], true)) {
                $relation = 'unknown';
            }
            $drugName = trim((string)($med['drug_name'] ?? ''));
            $genericName = trim((string)($med['generic_name'] ?? ''));
            $brandName = trim((string)($med['brand_name'] ?? ''));
            $rawDrugText = trim((string)($med['raw_drug_text'] ?? ''));
            if ($rawDrugText === '') {
                $rawDrugText = implode("
", array_values(array_filter([$drugName, $genericName, $brandName], static fn($v) => trim((string)$v) !== '')));
            }
            $row = [
                'drug_name' => $drugName,
                'generic_name' => $genericName,
                'brand_name' => $brandName,
                'raw_drug_text' => $rawDrugText,
                'name_relation' => $relation,
                'dose_text' => (string)($med['dose_text'] ?? ''),
                'usage_text' => (string)($med['usage_text'] ?? ''),
                'days_count' => is_numeric($med['days_count'] ?? null) ? (int)$med['days_count'] : null,
                'amount_text' => (string)($med['amount_text'] ?? ''),
                'confidence' => is_numeric($med['confidence'] ?? null) ? (float)$med['confidence'] : 0.0,
                'needs_human_check' => (bool)($med['needs_human_check'] ?? true),
                'reason' => (string)($med['reason'] ?? ''),
            ];
            if (class_exists('DrugNameMasterService')) {
                $row = DrugNameMasterService::enrichMedication($row);
            }
            $out[] = $row;
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private static function normalizeFormFields(array $normalized): array
    {
        $fields = [];
        foreach (($normalized['form_fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }
            $fields[] = self::normalizeFormField($field);
        }

        $auto = [
            ['patient_name', '氏名', 'patient', (string)($normalized['patient']['name'] ?? ''), 'person_name', '患者欄', $normalized['patient']['confidence'] ?? 0, true],
            ['patient_kana', 'フリガナ', 'patient', (string)($normalized['patient']['kana'] ?? ''), 'text', '患者欄', $normalized['patient']['confidence'] ?? 0, false],
            ['patient_birth_date', '生年月日', 'patient', (string)($normalized['patient']['birth_date'] ?? ''), 'date', '患者欄', $normalized['patient']['confidence'] ?? 0, true],
            ['patient_gender', '性別', 'patient', (string)($normalized['patient']['gender'] ?? ''), 'text', '患者欄', $normalized['patient']['confidence'] ?? 0, true],
            ['insurance_no', '保険者番号', 'insurance', (string)($normalized['insurance']['insurance_no'] ?? ''), 'code', '保険欄', $normalized['insurance']['confidence'] ?? 0, true],
            ['insured_symbol_number', '被保険者証・被保険者手帳の記号・番号', 'insurance', (string)($normalized['insurance']['insured_symbol_number'] ?? ''), 'code', '保険欄', $normalized['insurance']['confidence'] ?? 0, true],
            ['copay_rate', '負担割合', 'insurance', (string)($normalized['insurance']['copay_rate'] ?? ''), 'text', '保険欄', $normalized['insurance']['confidence'] ?? 0, false],
            ['issued_on', '交付年月日', 'prescription', (string)($normalized['prescription']['issued_on'] ?? ''), 'date', '処方箋欄', $normalized['prescription']['confidence'] ?? 0, true],
            ['expires_on', '処方箋の使用期間', 'prescription', (string)($normalized['prescription']['expires_on'] ?? ''), 'date', '処方箋欄', $normalized['prescription']['confidence'] ?? 0, false],
            ['medical_institution_code', '医療機関コード', 'medical_institution', (string)($normalized['medical_institution']['code'] ?? ''), 'code', '医療機関欄', $normalized['medical_institution']['confidence'] ?? 0, true],
            ['medical_institution_name', '保険医療機関の名称', 'medical_institution', (string)($normalized['medical_institution']['name'] ?? ''), 'text', '医療機関欄', $normalized['medical_institution']['confidence'] ?? 0, true],
            ['doctor_name', '保険医氏名', 'medical_institution', (string)($normalized['medical_institution']['doctor_name'] ?? ''), 'person_name', '医療機関欄', $normalized['medical_institution']['confidence'] ?? 0, false],
            ['medical_institution_phone', '電話番号', 'medical_institution', (string)($normalized['medical_institution']['phone'] ?? ''), 'text', '医療機関欄', $normalized['medical_institution']['confidence'] ?? 0, false],
        ];
        foreach ($auto as [$key, $label, $group, $value, $type, $section, $confidence, $include]) {
            $fields[] = self::normalizeFormField([
                'field_key' => $key,
                'field_label' => $label,
                'field_group' => $group,
                'value' => $value,
                'value_type' => $type,
                'source_section' => $section,
                'confidence' => $confidence,
                'needs_human_check' => true,
                'include_default' => $include && trim($value) !== '',
                'output_candidate' => true,
                'reason' => 'structured_field',
            ]);
        }

        foreach (($normalized['medications'] ?? []) as $i => $med) {
            if (!is_array($med)) {
                continue;
            }
            $n = $i + 1;
            $conf = $med['confidence'] ?? 0;
            $fields[] = self::normalizeFormField(['field_key' => 'medication_' . $n . '_drug_name', 'field_label' => '処方' . $n . ' 薬品名', 'field_group' => 'medication', 'value' => (string)($med['drug_name'] ?? ''), 'value_type' => 'drug', 'source_section' => '処方欄', 'confidence' => $conf, 'needs_human_check' => true, 'include_default' => trim((string)($med['drug_name'] ?? '')) !== '', 'output_candidate' => true, 'reason' => 'structured_medication']);
            $fields[] = self::normalizeFormField(['field_key' => 'medication_' . $n . '_generic_name', 'field_label' => '処方' . $n . ' 一般名候補', 'field_group' => 'medication', 'value' => (string)($med['generic_name'] ?? ''), 'value_type' => 'drug', 'source_section' => '処方欄', 'confidence' => $conf, 'needs_human_check' => true, 'include_default' => false, 'output_candidate' => true, 'reason' => 'structured_medication_generic']);
            $fields[] = self::normalizeFormField(['field_key' => 'medication_' . $n . '_brand_name', 'field_label' => '処方' . $n . ' 商品名候補', 'field_group' => 'medication', 'value' => (string)($med['brand_name'] ?? ''), 'value_type' => 'drug', 'source_section' => '処方欄', 'confidence' => $conf, 'needs_human_check' => true, 'include_default' => false, 'output_candidate' => true, 'reason' => 'structured_medication_brand']);
            $fields[] = self::normalizeFormField(['field_key' => 'medication_' . $n . '_raw_drug_text', 'field_label' => '処方' . $n . ' 薬品名元テキスト', 'field_group' => 'medication', 'value' => (string)($med['raw_drug_text'] ?? ''), 'value_type' => 'text', 'source_section' => '処方欄', 'confidence' => $conf, 'needs_human_check' => true, 'include_default' => false, 'output_candidate' => true, 'reason' => 'structured_medication_raw']);
            $fields[] = self::normalizeFormField(['field_key' => 'medication_' . $n . '_dose', 'field_label' => '処方' . $n . ' 用量', 'field_group' => 'medication', 'value' => (string)($med['dose_text'] ?? ''), 'value_type' => 'amount', 'source_section' => '処方欄', 'confidence' => $conf, 'needs_human_check' => true, 'include_default' => trim((string)($med['dose_text'] ?? '')) !== '', 'output_candidate' => true, 'reason' => 'structured_medication']);
            $fields[] = self::normalizeFormField(['field_key' => 'medication_' . $n . '_usage', 'field_label' => '処方' . $n . ' 用法', 'field_group' => 'medication', 'value' => (string)($med['usage_text'] ?? ''), 'value_type' => 'usage', 'source_section' => '処方欄', 'confidence' => $conf, 'needs_human_check' => true, 'include_default' => trim((string)($med['usage_text'] ?? '')) !== '', 'output_candidate' => true, 'reason' => 'structured_medication']);
            $fields[] = self::normalizeFormField(['field_key' => 'medication_' . $n . '_days', 'field_label' => '処方' . $n . ' 日数', 'field_group' => 'medication', 'value' => (string)($med['days_count'] ?? ''), 'value_type' => 'number', 'source_section' => '処方欄', 'confidence' => $conf, 'needs_human_check' => true, 'include_default' => ($med['days_count'] ?? '') !== '', 'output_candidate' => true, 'reason' => 'structured_medication']);
        }

        $seen = [];
        $out = [];
        foreach ($fields as $field) {
            $key = (string)$field['field_key'];
            $value = trim((string)$field['value']);
            $dedupeKey = $key . "\n" . $value . "\n" . (string)$field['field_label'];
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;
            $out[] = $field;
        }

        usort($out, static function (array $a, array $b): int {
            $groupOrder = ['patient' => 10, 'insurance' => 20, 'public_expense' => 30, 'prescription' => 40, 'medical_institution' => 50, 'medication' => 60, 'pharmacy' => 70, 'note' => 80, 'qr' => 90, 'other' => 99];
            return (($groupOrder[$a['field_group']] ?? 99) <=> ($groupOrder[$b['field_group']] ?? 99)) ?: (((int)($a['display_order'] ?? 9999)) <=> ((int)($b['display_order'] ?? 9999)));
        });

        return $out;
    }

    /** @param array<string,mixed> $field */
    private static function normalizeFormField(array $field): array
    {
        $group = (string)($field['field_group'] ?? 'other');
        if (!in_array($group, ['patient', 'insurance', 'public_expense', 'prescription', 'medical_institution', 'medication', 'pharmacy', 'note', 'qr', 'other'], true)) {
            $group = 'other';
        }
        $valueType = (string)($field['value_type'] ?? 'unknown');
        if (!in_array($valueType, ['text', 'date', 'number', 'code', 'person_name', 'drug', 'usage', 'amount', 'boolean', 'unknown'], true)) {
            $valueType = 'unknown';
        }
        $uiTemplate = (string)($field['ui_template'] ?? 'input');
        if (!in_array($uiTemplate, ['input', 'textarea', 'date', 'number', 'select', 'checkbox', 'drug_line', 'blank_cell', 'unknown'], true)) {
            $uiTemplate = match ($valueType) {
                'date' => 'date',
                'number' => 'number',
                'boolean' => 'checkbox',
                'drug', 'usage', 'amount' => 'input',
                default => 'input',
            };
        }
        $key = trim((string)($field['field_key'] ?? ''));
        $label = trim((string)($field['field_label'] ?? ''));
        if ($key === '') {
            $key = self::makeFieldKey($label !== '' ? $label : 'field');
        }
        if ($label === '') {
            $label = $key;
        }
        return [
            'field_key' => self::makeFieldKey($key),
            'field_label' => mb_substr($label, 0, 160),
            'field_group' => $group,
            'value' => (string)($field['value'] ?? ''),
            'value_type' => $valueType,
            'ui_template' => $uiTemplate,
            'display_order' => is_numeric($field['display_order'] ?? null) ? (int)$field['display_order'] : 9999,
            'is_empty_cell' => (bool)($field['is_empty_cell'] ?? (trim((string)($field['value'] ?? '')) === '')),
            'source_section' => mb_substr((string)($field['source_section'] ?? ''), 0, 160),
            'confidence' => is_numeric($field['confidence'] ?? null) ? (float)$field['confidence'] : 0.0,
            'needs_human_check' => (bool)($field['needs_human_check'] ?? true),
            'include_default' => (bool)($field['include_default'] ?? false),
            'output_candidate' => (bool)($field['output_candidate'] ?? true),
            'reason' => mb_substr((string)($field['reason'] ?? ''), 0, 255),
        ];
    }

    private static function makeFieldKey(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'field_' . substr(hash('sha1', uniqid('', true)), 0, 8);
        }
        $ascii = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $value) ?? '');
        $ascii = trim($ascii, '_');
        if ($ascii !== '') {
            return mb_substr($ascii, 0, 120);
        }
        return 'field_' . substr(hash('sha1', $value), 0, 12);
    }

}

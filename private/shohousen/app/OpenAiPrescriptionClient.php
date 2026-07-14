<?php
declare(strict_types=1);

final class OpenAiPrescriptionJsonParseException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly array $rawResponse,
        private readonly string $outputText,
        private readonly string $jsonError,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function rawResponse(): array
    {
        return $this->rawResponse;
    }

    public function outputText(): string
    {
        return $this->outputText;
    }

    public function jsonError(): string
    {
        return $this->jsonError;
    }
}

final class OpenAiPrescriptionClient
{
    private const FIXED_ANALYSIS_MODEL = 'gpt-4o-mini';

    private string $modelTier;

    public function __construct(?string $modelTier = null)
    {
        $this->modelTier = self::normalizeModelTier($modelTier ?? (string)app_config('openai.default_model_tier', 'high'));
    }

    public function withModelTier(?string $modelTier): self
    {
        return new self($modelTier ?: $this->modelTier);
    }

    /** @return array<string,array<string,string>> */
    public static function modelTierOptions(): array
    {
        if ((bool)app_config('prescription_minimal_analysis.lock_to_gpt4o_mini', true)) {
            return [
                'low' => [
                    'label' => 'gpt-4o-mini固定',
                    'description' => '現在は解析モデルをgpt-4o-miniのみに固定しています。',
                    'ocr_model' => self::FIXED_ANALYSIS_MODEL,
                    'structure_model' => self::FIXED_ANALYSIS_MODEL,
                    'mapping_model' => self::FIXED_ANALYSIS_MODEL,
                ],
            ];
        }

        $configured = app_config('openai.model_tiers', []);
        if (!is_array($configured) || $configured === []) {
            $configured = app_config('openai_model_tiers', []);
        }
        $defaults = self::defaultModelTiers();
        if (is_array($configured)) {
            foreach ($configured as $tier => $row) {
                if (!is_string($tier) || !is_array($row)) {
                    continue;
                }
                $tier = self::normalizeModelTier($tier);
                foreach (['label','description','ocr_model','structure_model','mapping_model'] as $key) {
                    if (isset($row[$key]) && is_string($row[$key]) && trim($row[$key]) !== '') {
                        $defaults[$tier][$key] = trim($row[$key]);
                    }
                }
            }
        }
        return $defaults;
    }

    public static function normalizeModelTier(?string $tier): string
    {
        $tier = strtolower(trim((string)$tier));
        if ((bool)app_config('prescription_minimal_analysis.lock_to_gpt4o_mini', true)) {
            return 'low';
        }
        $aliases = [
            'hi' => 'high',
            'high' => 'high',
            'premium' => 'high',
            'accurate' => 'high',
            '高精度' => 'high',
            'middle' => 'middle',
            'mid' => 'middle',
            'standard' => 'middle',
            'balanced' => 'middle',
            '中価格' => 'middle',
            'low' => 'low',
            'cheap' => 'low',
            'economy' => 'low',
            '低価格' => 'low',
        ];
        return $aliases[$tier] ?? 'high';
    }

    /** @return array<string,string> */
    public static function modelTierSummary(?string $tier): array
    {
        $tier = self::normalizeModelTier($tier);
        $options = self::modelTierOptions();
        $row = $options[$tier] ?? $options['high'];
        return [
            'tier' => $tier,
            'label' => $row['label'],
            'description' => $row['description'],
            'ocr_model' => $row['ocr_model'],
            'structure_model' => $row['structure_model'],
            'mapping_model' => $row['mapping_model'],
            'summary' => $tier . ':ocr=' . $row['ocr_model'] . ',structure=' . $row['structure_model'] . ',mapping=' . $row['mapping_model'],
        ];
    }

    /** @return array<string,array<string,string>> */
    private static function defaultModelTiers(): array
    {
        $fallback = (string)app_config('openai.model', 'gpt-4o-mini');
        return [
            'high' => [
                'label' => '高精度',
                'description' => '精度優先。OCR読取と項目化を高精度モデルで実行します。',
                'ocr_model' => (string)app_config('openai.high.ocr_model', 'gpt-5.5'),
                'structure_model' => (string)app_config('openai.high.structure_model', 'gpt-5.5'),
                'mapping_model' => (string)app_config('openai.high.mapping_model', 'gpt-4o-mini'),
            ],
            'middle' => [
                'label' => '中価格',
                'description' => '価格と精度のバランス。通常テスト向けです。',
                'ocr_model' => (string)app_config('openai.middle.ocr_model', 'gpt-4o'),
                'structure_model' => (string)app_config('openai.middle.structure_model', 'gpt-4o-mini'),
                'mapping_model' => (string)app_config('openai.middle.mapping_model', 'gpt-4o-mini'),
            ],
            'low' => [
                'label' => '低価格',
                'description' => 'コスト優先。大量テスト・低コスト確認向けです。',
                'ocr_model' => (string)app_config('openai.low.ocr_model', $fallback),
                'structure_model' => (string)app_config('openai.low.structure_model', $fallback),
                'mapping_model' => (string)app_config('openai.low.mapping_model', $fallback),
            ],
        ];
    }

    private function modelForStage(string $stage): string
    {
        if ((bool)app_config('prescription_minimal_analysis.lock_to_gpt4o_mini', true)) {
            return self::FIXED_ANALYSIS_MODEL;
        }

        $summary = self::modelTierSummary($this->modelTier);
        return match ($stage) {
            'ocr' => $summary['ocr_model'],
            'structure' => $summary['structure_model'],
            'mapping' => $summary['mapping_model'],
            default => (string)app_config('openai.model', 'gpt-4o-mini'),
        };
    }

    public function extractFromImage(string $imagePath, string $mimeType, ?array $templateHint = null): array
    {
        $tierSummary = self::modelTierSummary($this->modelTier);
        $ocr = $this->extractOcrFromImage($imagePath, $mimeType, $templateHint);
        $structured = $this->structurePrescriptionFromOcr($ocr['structured_json'], $templateHint);

        return [
            'raw' => [
                'pipeline_version' => 'ocr_4stage_v1',
                'model_tier' => $tierSummary,
                'model' => $structured['model'],
                'ocr_model' => $ocr['model'],
                'structure_model' => $structured['model'],
                'ocr_raw_response' => $ocr['raw'],
                'ocr_output' => $ocr['structured_json'],
                'structure_raw_response' => $structured['raw'],
                'structure_output' => $structured['item_json'],
            ],
            'ocr_raw_text' => $ocr['raw_text'],
            'ocr_structured_json' => $ocr['structured_json'],
            'prescription_item_json' => $structured['item_json'],
            'normalized' => $structured['normalized'],
            'model' => $structured['model'],
            'models' => [
                'tier' => $tierSummary['tier'],
                'tier_label' => $tierSummary['label'],
                'ocr' => $ocr['model'],
                'structure' => $structured['model'],
                'mapping' => $tierSummary['mapping_model'],
            ],
        ];
    }

    /**
     * 1段階目: 画像から見えた文字を、推測で項目確定せず OCR 生テキスト + 行/ブロックJSON として保存する。
     *
     * @return array{raw:array<string,mixed>,raw_text:string,structured_json:array<string,mixed>,model:string}
     */
    public function extractOcrFromImage(string $imagePath, string $mimeType, ?array $templateHint = null): array
    {
        $apiKey = trim((string)app_config('openai.api_key', ''));
        if ($apiKey === '') {
            if ((bool)app_config('app.demo_mode', false)) {
                $demo = self::demoOcrStructured();
                return [
                    'raw' => ['demo' => true, 'stage' => 'ocr_raw_text'],
                    'raw_text' => (string)$demo['raw_text'],
                    'structured_json' => $demo,
                    'model' => 'demo',
                ];
            }
            throw new RuntimeException('OpenAI APIキーが未設定です。');
        }

        $learningHints = '';
        if ((bool)app_config('prescription_minimal_analysis.use_learning_hints', false)) {
            try {
                $learningHints = (new PrescriptionKnowledgeService())->buildOpenAiLearningHints((string)($templateHint['layout_fingerprint'] ?? ''));
            } catch (Throwable) {
                $learningHints = '';
            }
        }

        $base64 = base64_encode((string)file_get_contents($imagePath));
        $detail = (string)app_config('openai.vision_detail', 'high');
        $model = $this->modelForStage('ocr');
        $payload = [
            'model' => $model,
            'input' => [
                [
                    'role' => 'system',
                    'content' => [
                        ['type' => 'input_text', 'text' => self::ocrSystemPrompt($templateHint, $learningHints)],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'input_text', 'text' => '処方箋画像から、見えている文字をできる限りそのまま文字起こししてください。患者・保険・薬品などの項目確定は次工程で行うため、この段階では推測で補完しないでください。'],
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
                    'name' => 'prescription_ocr_transcription',
                    'strict' => true,
                    'schema' => self::ocrResponseSchema(),
                ],
            ],
            'max_output_tokens' => (int)app_config('openai.ocr_max_output_tokens', app_config('openai.max_output_tokens', 9000)),
        ];

        $response = $this->postJson('https://api.openai.com/v1/responses', $payload, $apiKey, (int)app_config('openai.ocr_timeout_seconds', 180));
        $jsonText = self::extractOutputText($response);
        $structured = json_decode($jsonText, true);
        if (!is_array($structured)) {
            throw new OpenAiPrescriptionJsonParseException(
                'OpenAI OCR文字起こしJSONの解析に失敗しました。IO診断で失敗時レスポンスを確認してください。',
                $response,
                $jsonText,
                json_last_error_msg()
            );
        }

        $structured = self::normalizeOcrStructured($structured);
        return [
            'raw' => $response,
            'raw_text' => (string)$structured['raw_text'],
            'structured_json' => $structured,
            'model' => $model,
        ];
    }

    /**
     * 2段階目: OCR構造JSONを、処方箋の患者・保険・医療機関・薬品などの項目JSONへ書き出す。
     *
     * @param array<string,mixed> $ocrStructured
     * @return array{raw:array<string,mixed>,item_json:array<string,mixed>,normalized:array<string,mixed>,model:string}
     */
    public function structurePrescriptionFromOcr(array $ocrStructured, ?array $templateHint = null): array
    {
        $apiKey = trim((string)app_config('openai.api_key', ''));
        if ($apiKey === '') {
            if ((bool)app_config('app.demo_mode', false)) {
                $demo = self::demoNormalized();
                return [
                    'raw' => ['demo' => true, 'stage' => 'prescription_item_json'],
                    'item_json' => $demo,
                    'normalized' => $demo,
                    'model' => 'demo',
                ];
            }
            throw new RuntimeException('OpenAI APIキーが未設定です。');
        }

        $learningHints = '';
        if ((bool)app_config('prescription_minimal_analysis.use_learning_hints', false)) {
            try {
                $learningHints = (new PrescriptionKnowledgeService())->buildOpenAiLearningHints((string)($templateHint['layout_fingerprint'] ?? ''));
            } catch (Throwable) {
                $learningHints = '';
            }
        }

        $model = $this->modelForStage('structure');
        $payload = [
            'model' => $model,
            'input' => [
                [
                    'role' => 'system',
                    'content' => [
                        ['type' => 'input_text', 'text' => self::structureSystemPrompt($templateHint, $learningHints)],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'input_text', 'text' => json_encode([
                            'ocr_raw_text' => (string)($ocrStructured['raw_text'] ?? ''),
                            'ocr_structured_json' => $ocrStructured,
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'prescription_extraction',
                    'strict' => true,
                    'schema' => self::responseSchema(),
                ],
            ],
            'max_output_tokens' => (int)app_config('openai.structure_max_output_tokens', app_config('openai.max_output_tokens', 9000)),
        ];

        $response = $this->postJson('https://api.openai.com/v1/responses', $payload, $apiKey, (int)app_config('openai.structure_timeout_seconds', 180));
        $jsonText = self::extractOutputText($response);
        $itemJson = json_decode($jsonText, true);
        if (!is_array($itemJson)) {
            throw new OpenAiPrescriptionJsonParseException(
                'OpenAI処方箋項目JSONの解析に失敗しました。IO診断で失敗時レスポンスを確認してください。',
                $response,
                $jsonText,
                json_last_error_msg()
            );
        }

        return [
            'raw' => $response,
            'item_json' => $itemJson,
            'normalized' => self::normalize($itemJson),
            'model' => $model,
        ];
    }

    /**
     * 読み取り済みJSONを、処方箋ルールに沿った画面表示・保存用項目へAIで再配置する。
     * 画像再読取ではなく、JSON→表示項目マッピングの段階。
     *
     * @param array<string,mixed> $normalized
     * @param array<string,mixed>|null $templateHint
     * @param array<string,mixed> $ruleContext
     * @return array<string,mixed>
     */
    public function mapNormalizedToDisplay(array $normalized, ?array $templateHint = null, array $ruleContext = []): array
    {
        $apiKey = trim((string)app_config('openai.api_key', ''));
        if ($apiKey === '') {
            if ((bool)app_config('app.demo_mode', false)) {
                return self::demoDisplayMapping($normalized);
            }
            throw new RuntimeException('OpenAI APIキーが未設定です。');
        }

        $prompt = self::displayMappingPrompt($templateHint, $ruleContext);
        $payload = [
            'model' => $this->modelForStage('mapping'),
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
                        ['type' => 'input_text', 'text' => json_encode([
                            'normalized_prescription_json' => $normalized,
                            'rule_context' => $ruleContext,
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'prescription_display_mapping',
                    'strict' => true,
                    'schema' => self::displayMappingSchema(),
                ],
            ],
            'max_output_tokens' => (int)app_config('prescription_ai_mapping.max_output_tokens', 9000),
        ];

        $response = $this->postJson('https://api.openai.com/v1/responses', $payload, $apiKey, (int)app_config('prescription_ai_mapping.timeout_seconds', 120));
        $jsonText = self::extractOutputText($response);
        $mapping = json_decode($jsonText, true);
        if (!is_array($mapping)) {
            throw new OpenAiPrescriptionJsonParseException(
                'OpenAI表示マッピングJSONの解析に失敗しました。IO診断で失敗時レスポンスを確認してください。',
                $response,
                $jsonText,
                json_last_error_msg()
            );
        }
        $mapping = self::normalizeDisplayMapping($mapping);
        $mapping['raw_response'] = $response;
        $mapping['model'] = $this->modelForStage('mapping');
        return $mapping;
    }


    /**
     * 画像から患者値を取得せず、処方箋帳票の固定ラベル・区画・候補座標だけを抽出する単一タスク。
     *
     * @return array{raw:array<string,mixed>,template:array<string,mixed>,model:string}
     */
    public function extractTemplateFromImage(string $imagePath, string $mimeType): array
    {
        $apiKey = trim((string)app_config('openai.api_key', ''));
        if ($apiKey === '') {
            if ((bool)app_config('app.demo_mode', false)) {
                return [
                    'raw' => ['demo' => true, 'stage' => 'template_structure'],
                    'template' => [
                        'page_orientation' => 'unknown',
                        'template_kind' => 'unknown',
                        'fixed_labels' => [],
                        'sections' => [],
                        'frame_notes' => [],
                        'warnings' => ['demo_mode'],
                        'overall_confidence' => 0,
                    ],
                    'model' => 'demo',
                ];
            }
            throw new RuntimeException('OpenAI APIキーが未設定です。');
        }

        $base64 = base64_encode((string)file_get_contents($imagePath));
        $detail = (string)app_config('prescription_single_task_analysis.template_vision_detail', 'low');
        $model = $this->modelForStage('ocr');
        $payload = [
            'model' => $model,
            'input' => [
                [
                    'role' => 'system',
                    'content' => [
                        ['type' => 'input_text', 'text' => self::templateSystemPrompt()],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'input_text', 'text' => 'この画像の処方箋テンプレート構造だけを抽出してください。患者名、番号、日付、薬剤名、医師名などの実値は絶対に出力しないでください。'],
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
                    'name' => 'prescription_template_structure',
                    'strict' => true,
                    'schema' => self::templateResponseSchema(),
                ],
            ],
            'max_output_tokens' => (int)app_config('prescription_single_task_analysis.template_max_output_tokens', 3500),
        ];

        $response = $this->postJson(
            'https://api.openai.com/v1/responses',
            $payload,
            $apiKey,
            (int)app_config('prescription_single_task_analysis.template_timeout_seconds', 120)
        );
        $jsonText = self::extractOutputText($response);
        $template = json_decode($jsonText, true);
        if (!is_array($template)) {
            throw new OpenAiPrescriptionJsonParseException(
                '処方箋テンプレート構造JSONの解析に失敗しました。',
                $response,
                $jsonText,
                json_last_error_msg()
            );
        }

        return [
            'raw' => $response,
            'template' => self::normalizeTemplateObservation($template),
            'model' => $model,
        ];
    }

    /**
     * OCR済みテキストを根拠に、1リクエスト1項目で処方箋項目を抽出する。
     * 画像を各項目で再送しないため、画像入力コストを増やさず、低価格モデルの指示混線を抑える。
     *
     * @param array<int,array<string,mixed>> $tasks
     * @return array<string,mixed>
     */
    public function extractSingleTaskFieldsFromOcr(array $tasks): array
    {
        $apiKey = trim((string)app_config('openai.api_key', ''));
        $model = $this->modelForStage('structure');
        if ($apiKey === '') {
            if ((bool)app_config('app.demo_mode', false)) {
                return [
                    'results' => [],
                    'raw_responses' => [],
                    'errors' => ['demo_mode'],
                    'model' => 'demo',
                ];
            }
            throw new RuntimeException('OpenAI APIキーが未設定です。');
        }

        $requests = [];
        foreach ($tasks as $task) {
            if (!is_array($task)) {
                continue;
            }
            $taskId = trim((string)($task['task_id'] ?? ''));
            if ($taskId === '') {
                continue;
            }
            $responseType = (string)($task['response_type'] ?? 'scalar');
            $schema = $responseType === 'medications'
                ? self::singleMedicationTaskResponseSchema()
                : self::singleFieldTaskResponseSchema();
            $maxTokens = $responseType === 'medications'
                ? (int)app_config('prescription_single_task_analysis.medication_max_output_tokens', 5000)
                : (int)app_config('prescription_single_task_analysis.field_max_output_tokens', 700);

            $requests[$taskId] = [
                'payload' => [
                    'model' => $model,
                    'input' => [
                        [
                            'role' => 'system',
                            'content' => [
                                ['type' => 'input_text', 'text' => self::singleTaskSystemPrompt($responseType)],
                            ],
                        ],
                        [
                            'role' => 'user',
                            'content' => [
                                ['type' => 'input_text', 'text' => json_encode([
                                    'single_task' => (string)($task['question'] ?? ''),
                                    'field_key' => (string)($task['field_key'] ?? ''),
                                    'field_label' => (string)($task['field_label'] ?? ''),
                                    'ocr_evidence' => $task['evidence'] ?? [],
                                    'rules' => [
                                        'perform_only_this_one_task' => true,
                                        'do_not_infer_missing_values' => true,
                                        'return_empty_when_not_visible' => true,
                                        'preserve_original_wareki_and_digits' => true,
                                    ],
                                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)],
                            ],
                        ],
                    ],
                    'text' => [
                        'format' => [
                            'type' => 'json_schema',
                            'name' => self::schemaName('single_' . $taskId),
                            'strict' => true,
                            'schema' => $schema,
                        ],
                    ],
                    'max_output_tokens' => max(200, $maxTokens),
                ],
                'timeout_seconds' => (int)app_config('prescription_single_task_analysis.field_timeout_seconds', 90),
            ];
        }

        $parallelism = max(1, min(10, (int)app_config('prescription_single_task_analysis.parallelism', 6)));
        $batch = $this->postJsonBatch('https://api.openai.com/v1/responses', $requests, $apiKey, $parallelism);
        $results = [];
        $raw = [];
        $errors = [];
        foreach ($batch as $taskId => $row) {
            if (!is_array($row) || empty($row['ok']) || !is_array($row['response'] ?? null)) {
                $errors[$taskId] = (string)($row['error'] ?? 'OpenAI単一タスクに失敗しました。');
                continue;
            }
            $response = $row['response'];
            $raw[$taskId] = $response;
            try {
                $jsonText = self::extractOutputText($response);
                $decoded = json_decode($jsonText, true);
                if (!is_array($decoded)) {
                    throw new RuntimeException(json_last_error_msg());
                }
                $results[$taskId] = $decoded;
            } catch (Throwable $e) {
                $errors[$taskId] = 'JSON解析失敗: ' . $e->getMessage();
            }
        }

        return [
            'results' => $results,
            'raw_responses' => $raw,
            'errors' => $errors,
            'model' => $model,
            'request_count' => count($requests),
        ];
    }

    private static function templateSystemPrompt(): string
    {
        return <<<'PROMPT'
あなたは日本の処方箋帳票テンプレート抽出専用エンジンです。
今回の仕事は「帳票の固定構造だけを取得すること」1つだけです。

厳守:
- 患者氏名、患者住所、生年月日の実値、保険番号、公費番号、日付実値、薬剤名、用法、医師名などの個別値は出力しない。
- 固定ラベル文字、区画名、罫線・枠の構造、固定ラベルのおおよその位置比率だけを返す。
- 固定ラベルに見えない自由記載や手書き文字は fixed_labels に入れない。
- label_x_ratio/label_y_ratio/label_w_ratio/label_h_ratio は固定ラベル文字の領域です。
- value_x_ratio/value_y_ratio/value_w_ratio/value_h_ratio は、そのラベルに対応する記入値・印字値の欄領域です。
- いずれも画像左上を0,0、右下を1,1とした概算値。値欄を特定できない場合は value_* を0にしてください。
- canonical_field_key は既知の固定項目だけを使い、不明ラベルは other とする。
- JSON Schema以外の文章は出力しない。
PROMPT;
    }

    private static function singleTaskSystemPrompt(string $responseType): string
    {
        if ($responseType === 'medications') {
            return <<<'PROMPT'
あなたは日本の処方箋OCR結果から「処方薬情報だけ」を抽出する専用エンジンです。
今回の仕事は処方薬情報の抽出1つだけです。他の患者・保険・医療機関項目は扱わないでください。
OCR根拠に存在しない文字や薬剤を作らず、印字順、改行、総量、用法、日数、使用部位、使用条件を省略しないでください。
外用薬・点眼薬・頓服などを錠数×回数×日数へ無理に変換しないでください。
JSON Schema以外の文章は出力しないでください。
PROMPT;
        }

        return <<<'PROMPT'
あなたは日本の処方箋OCR結果から指定された「1項目だけ」を抽出する専用エンジンです。
ユーザーが指定した1項目以外を判断・出力しないでください。
OCR根拠に値が無い、読めない、別欄と区別できない場合は found=false、value="" としてください。
和暦、番号、記号、ハイフン等は勝手に変換・補完・削除せず、OCR上の元表記を返してください。
候補が複数ある場合は推測で選ばず needs_human_check=true にしてください。
JSON Schema以外の文章は出力しないでください。
PROMPT;
    }

    /** @return array<string,mixed> */
    private static function singleFieldTaskResponseSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['found', 'value', 'raw_text', 'source_section', 'source_line_numbers', 'confidence', 'needs_human_check', 'reason'],
            'properties' => [
                'found' => ['type' => 'boolean'],
                'value' => ['type' => 'string'],
                'raw_text' => ['type' => 'string'],
                'source_section' => ['type' => 'string'],
                'source_line_numbers' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'confidence' => ['type' => 'number'],
                'needs_human_check' => ['type' => 'boolean'],
                'reason' => ['type' => 'string'],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function singleMedicationTaskResponseSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['items', 'confidence', 'needs_human_check', 'reason'],
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['drug_name', 'generic_name', 'brand_name', 'raw_drug_text', 'name_relation', 'dose_text', 'usage_text', 'days_count', 'amount_text', 'confidence', 'needs_human_check', 'reason'],
                        'properties' => [
                            'drug_name' => ['type' => 'string'],
                            'generic_name' => ['type' => 'string'],
                            'brand_name' => ['type' => 'string'],
                            'raw_drug_text' => ['type' => 'string'],
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
                'confidence' => ['type' => 'number'],
                'needs_human_check' => ['type' => 'boolean'],
                'reason' => ['type' => 'string'],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function templateResponseSchema(): array
    {
        $region = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'label_text', 'canonical_field_key', 'source_section',
                'label_x_ratio', 'label_y_ratio', 'label_w_ratio', 'label_h_ratio',
                'value_x_ratio', 'value_y_ratio', 'value_w_ratio', 'value_h_ratio',
                'confidence'
            ],
            'properties' => [
                'label_text' => ['type' => 'string'],
                'canonical_field_key' => ['type' => 'string'],
                'source_section' => ['type' => 'string'],
                'label_x_ratio' => ['type' => 'number'],
                'label_y_ratio' => ['type' => 'number'],
                'label_w_ratio' => ['type' => 'number'],
                'label_h_ratio' => ['type' => 'number'],
                'value_x_ratio' => ['type' => 'number'],
                'value_y_ratio' => ['type' => 'number'],
                'value_w_ratio' => ['type' => 'number'],
                'value_h_ratio' => ['type' => 'number'],
                'confidence' => ['type' => 'number'],
            ],
        ];
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['page_orientation', 'template_kind', 'fixed_labels', 'sections', 'frame_notes', 'warnings', 'overall_confidence'],
            'properties' => [
                'page_orientation' => ['type' => 'string', 'enum' => ['portrait', 'landscape', 'unknown']],
                'template_kind' => ['type' => 'string'],
                'fixed_labels' => ['type' => 'array', 'items' => $region],
                'sections' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['section_name', 'x_ratio', 'y_ratio', 'w_ratio', 'h_ratio', 'confidence'],
                        'properties' => [
                            'section_name' => ['type' => 'string'],
                            'x_ratio' => ['type' => 'number'],
                            'y_ratio' => ['type' => 'number'],
                            'w_ratio' => ['type' => 'number'],
                            'h_ratio' => ['type' => 'number'],
                            'confidence' => ['type' => 'number'],
                        ],
                    ],
                ],
                'frame_notes' => ['type' => 'array', 'items' => ['type' => 'string']],
                'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
                'overall_confidence' => ['type' => 'number'],
            ],
        ];
    }

    /** @param array<string,mixed> $template @return array<string,mixed> */
    private static function normalizeTemplateObservation(array $template): array
    {
        foreach (['fixed_labels', 'sections'] as $listKey) {
            $rows = [];
            foreach ((array)($template[$listKey] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                foreach ([
                    'x_ratio', 'y_ratio', 'w_ratio', 'h_ratio',
                    'label_x_ratio', 'label_y_ratio', 'label_w_ratio', 'label_h_ratio',
                    'value_x_ratio', 'value_y_ratio', 'value_w_ratio', 'value_h_ratio'
                ] as $ratioKey) {
                    if (!array_key_exists($ratioKey, $row)) {
                        continue;
                    }
                    $row[$ratioKey] = is_numeric($row[$ratioKey] ?? null)
                        ? max(0.0, min(1.0, (float)$row[$ratioKey]))
                        : 0.0;
                }
                if (isset($row['confidence'])) {
                    $row['confidence'] = self::normalizeConfidencePercent($row['confidence']);
                }
                $rows[] = $row;
            }
            $template[$listKey] = $rows;
        }
        $template['frame_notes'] = array_values(array_filter(array_map('strval', (array)($template['frame_notes'] ?? []))));
        $template['warnings'] = array_values(array_filter(array_map('strval', (array)($template['warnings'] ?? []))));
        $template['overall_confidence'] = self::normalizeConfidencePercent($template['overall_confidence'] ?? 0);
        $template['privacy_level'] = 'fixed_labels_and_geometry_only_no_values';
        $template['status'] = 'candidate';
        return $template;
    }

    private static function schemaName(string $value): string
    {
        $value = preg_replace('/[^a-zA-Z0-9_]+/', '_', $value) ?: 'single_task';
        return substr($value, 0, 60);
    }

    /**
     * PHP検証でNG/判定不能になった主要項目だけを、同じ画像から再確認する。
     * 単一タスクモードでは1リクエスト1項目へ自動分割する。
     *
     * @param array<string,mixed> $currentNormalized
     * @param array<int,array<string,mixed>> $targetFields
     * @param array<string,mixed> $ocrStructured
     * @return array<string,mixed>
     */
    public function repairCoreFieldsFromImage(string $imagePath, string $mimeType, array $currentNormalized, array $targetFields, array $ocrStructured = []): array
    {
        $apiKey = trim((string)app_config('openai.api_key', ''));
        if ($apiKey === '') {
            if ((bool)app_config('app.demo_mode', false)) {
                return ['fields' => [], 'warnings' => ['demo_mode: targeted repair skipped'], 'overall_confidence' => 0, 'raw_response' => ['demo' => true], 'model' => 'demo'];
            }
            throw new RuntimeException('OpenAI APIキーが未設定です。');
        }
        if (!$targetFields) {
            return ['fields' => [], 'warnings' => [], 'overall_confidence' => 0, 'raw_response' => [], 'model' => $this->modelForStage('ocr')];
        }

        // 低価格モデルでは複数項目を同時に判断させず、1リクエスト1項目へ分割する。
        if ((bool)app_config('prescription_single_task_analysis.enabled', true) && count($targetFields) > 1) {
            $fields = [];
            $warnings = [];
            $rawResponses = [];
            $confidence = [];
            $model = $this->modelForStage('ocr');
            foreach ($targetFields as $index => $targetField) {
                if (!is_array($targetField)) {
                    continue;
                }
                try {
                    $single = $this->repairCoreFieldsFromImage($imagePath, $mimeType, $currentNormalized, [$targetField], $ocrStructured);
                    $fields = array_merge($fields, is_array($single['fields'] ?? null) ? $single['fields'] : []);
                    $warnings = array_merge($warnings, array_map('strval', (array)($single['warnings'] ?? [])));
                    if (is_numeric($single['overall_confidence'] ?? null)) {
                        $confidence[] = (float)$single['overall_confidence'];
                    }
                    $rawResponses[(string)($targetField['field_key'] ?? ('field_' . $index))] = $single['raw_response'] ?? [];
                    $model = (string)($single['model'] ?? $model);
                } catch (Throwable $e) {
                    $warnings[] = (string)($targetField['field_label'] ?? $targetField['field_key'] ?? '項目') . ': ' . $e->getMessage();
                }
            }
            return [
                'fields' => $fields,
                'warnings' => array_values(array_unique($warnings)),
                'overall_confidence' => $confidence ? array_sum($confidence) / count($confidence) : 0,
                'raw_response' => ['single_task_responses' => $rawResponses],
                'model' => $model,
            ];
        }

        $base64 = base64_encode((string)file_get_contents($imagePath));
        $detail = (string)app_config('openai.vision_detail', 'high');
        $model = $this->modelForStage('ocr');
        $payload = [
            'model' => $model,
            'input' => [
                [
                    'role' => 'system',
                    'content' => [
                        ['type' => 'input_text', 'text' => self::fieldRepairSystemPrompt()],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'input_text', 'text' => json_encode([
                            'task' => 'PHP検証でNGまたは判定不能になった項目だけを画像から再確認する',
                            'target_fields' => $targetFields,
                            'current_normalized_json' => self::compactRepairContext($currentNormalized),
                            'ocr_raw_text' => (string)($currentNormalized['_ocr_raw_text'] ?? ($ocrStructured['raw_text'] ?? '')),
                            'ocr_structured_json' => $ocrStructured,
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)],
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
                    'name' => 'prescription_field_repair',
                    'strict' => true,
                    'schema' => self::fieldRepairResponseSchema(),
                ],
            ],
            'max_output_tokens' => (int)app_config('openai.field_repair_max_output_tokens', 2500),
        ];

        $response = $this->postJson('https://api.openai.com/v1/responses', $payload, $apiKey, (int)app_config('openai.field_repair_timeout_seconds', 90));
        $jsonText = self::extractOutputText($response);
        $repair = json_decode($jsonText, true);
        if (!is_array($repair)) {
            throw new OpenAiPrescriptionJsonParseException(
                'OpenAI項目別再確認JSONの解析に失敗しました。IO診断で失敗時レスポンスを確認してください。',
                $response,
                $jsonText,
                json_last_error_msg()
            );
        }
        $repair['raw_response'] = $response;
        $repair['model'] = $model;
        return $repair;
    }

    /** @return array<string,mixed> */
    private static function fieldRepairResponseSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['fields', 'warnings', 'overall_confidence'],
            'properties' => [
                'fields' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['field_key', 'field_label', 'value', 'raw_text', 'confidence', 'needs_human_check', 'reason'],
                        'properties' => [
                            'field_key' => ['type' => 'string'],
                            'field_label' => ['type' => 'string'],
                            'value' => ['type' => 'string'],
                            'raw_text' => ['type' => 'string'],
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

    private static function fieldRepairSystemPrompt(): string
    {
        return <<<PROMPT
あなたは日本の処方箋OCRの「単一項目再確認」担当です。
target_fields には必ず1項目だけが入ります。その1項目だけを画像から読み直してください。

厳守:
- target_fields に無い項目は返さないでください。
- 読めない項目は value を空文字にし、reason に読めない理由を入れてください。
- 和暦は西暦へ変換せず、画像上の元表記を raw_text と value に残してください。変換はPHP側で行います。
- 数字の追加、削除、桁合わせをしないでください。画像に見えた値をそのまま返してください。
- 受付年月日、交付年月日、生年月日を混同しないでください。
- 保険者番号、公費負担者番号、公費受給者番号、記号番号を混同しないでください。
- 医療機関コードでは住所の番地や電話番号を採用しないでください。
- JSON Schema以外の文章は出力しないでください。
PROMPT;
    }

    /** @param array<string,mixed> $currentNormalized @return array<string,mixed> */
    private static function compactRepairContext(array $currentNormalized): array
    {
        return [
            'patient' => $currentNormalized['patient'] ?? [],
            'insurance' => $currentNormalized['insurance'] ?? [],
            'public_expense' => $currentNormalized['public_expense'] ?? [],
            'prescription' => $currentNormalized['prescription'] ?? [],
            'medical_institution' => $currentNormalized['medical_institution'] ?? [],
            'warnings' => $currentNormalized['warnings'] ?? [],
            'field_validations' => $currentNormalized['field_validations'] ?? [],
        ];
    }


    /**
     * @param array<string,array{payload:array<string,mixed>,timeout_seconds?:int}> $requests
     * @return array<string,array<string,mixed>>
     */
    private function postJsonBatch(string $url, array $requests, string $apiKey, int $parallelism): array
    {
        if (!$requests) {
            return [];
        }
        if (!function_exists('curl_multi_init')) {
            $out = [];
            foreach ($requests as $key => $request) {
                try {
                    $out[$key] = [
                        'ok' => true,
                        'response' => $this->postJson(
                            $url,
                            (array)($request['payload'] ?? []),
                            $apiKey,
                            (int)($request['timeout_seconds'] ?? app_config('openai.timeout_seconds', 60))
                        ),
                    ];
                } catch (Throwable $e) {
                    $out[$key] = ['ok' => false, 'error' => $e->getMessage()];
                }
            }
            return $out;
        }

        $out = [];
        foreach (array_chunk($requests, max(1, $parallelism), true) as $chunk) {
            $multi = curl_multi_init();
            if ($multi === false) {
                throw new RuntimeException('curl_multi初期化に失敗しました。');
            }
            $handles = [];
            foreach ($chunk as $key => $request) {
                $ch = curl_init($url);
                if ($ch === false) {
                    $out[$key] = ['ok' => false, 'error' => 'curl初期化に失敗しました。'];
                    continue;
                }
                $payloadJson = json_encode(
                    (array)($request['payload'] ?? []),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
                );
                if (!is_string($payloadJson)) {
                    $out[$key] = ['ok' => false, 'error' => 'OpenAI送信JSONの生成に失敗しました。'];
                    curl_close($ch);
                    continue;
                }
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer ' . $apiKey,
                        'Content-Type: application/json',
                    ],
                    CURLOPT_POSTFIELDS => $payloadJson,
                    CURLOPT_TIMEOUT => max(30, (int)($request['timeout_seconds'] ?? app_config('openai.timeout_seconds', 60))),
                    CURLOPT_CONNECTTIMEOUT => (int)app_config('openai.connect_timeout_seconds', 20),
                ]);
                curl_multi_add_handle($multi, $ch);
                $handles[] = ['key' => $key, 'handle' => $ch];
            }

            do {
                $status = curl_multi_exec($multi, $running);
                if ($running) {
                    curl_multi_select($multi, 1.0);
                }
            } while ($running && $status === CURLM_OK);

            foreach ($handles as $entry) {
                $key = $entry['key'];
                $ch = $entry['handle'];
                $body = curl_multi_getcontent($ch);
                $httpStatus = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $error = curl_error($ch);
                if ($body === false || $body === '') {
                    $out[$key] = ['ok' => false, 'error' => 'OpenAI API通信に失敗しました: ' . $error];
                } else {
                    $decoded = json_decode((string)$body, true);
                    if (!is_array($decoded)) {
                        $out[$key] = ['ok' => false, 'error' => 'OpenAI APIレスポンスがJSONではありません。HTTP ' . $httpStatus];
                    } elseif ($httpStatus < 200 || $httpStatus >= 300) {
                        $message = $decoded['error']['message'] ?? ('HTTP ' . $httpStatus);
                        $out[$key] = ['ok' => false, 'error' => 'OpenAI APIエラー: ' . $message, 'response' => $decoded];
                    } else {
                        $out[$key] = ['ok' => true, 'response' => $decoded];
                    }
                }
                curl_multi_remove_handle($multi, $ch);
                curl_close($ch);
            }
            curl_multi_close($multi);
        }
        return $out;
    }

    private function postJson(string $url, array $payload, string $apiKey, ?int $timeoutSeconds = null): array
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
            CURLOPT_TIMEOUT => max(30, $timeoutSeconds ?? (int)app_config('openai.timeout_seconds', 60)),
            CURLOPT_CONNECTTIMEOUT => (int)app_config('openai.connect_timeout_seconds', 20),
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

    private static function ocrSystemPrompt(?array $templateHint, string $learningHints = ''): string
    {
        $template = '';
        if ($templateHint && !(bool)app_config('prescription_minimal_analysis.ignore_template_hints', true)) {
            $template = "\n既知テンプレート情報: " . json_encode($templateHint, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        return <<<PROMPT
あなたは日本の処方箋画像のOCR文字起こしエンジンです。
この段階の目的は「画像上に見える文字をそのまま取り出すこと」です。
患者名・保険情報・薬品情報などの最終項目分類は次工程で行うため、この段階では推測で値を補完しないでください。

必須方針:
- 画像に見える文字を、上から下・左から右の自然な順番で raw_text にまとめてください。
- 欄名と値が読める場合は同じ行または近い行として残してください。
- 行単位の lines と、帳票上のまとまり単位の blocks を作ってください。
- source_section には「上部左」「上部右」「患者欄」「保険欄」「処方欄」「医療機関欄」「備考欄」「下部」などを入れてください。
- ぼけ、薄い印字、手書き疑い、罫線被り、小さい文字、読めない文字は visual_notes に残してください。
- 読めない箇所は「□」「?」「読取不能」などで明示し、勝手に補完しないでください。
- 薬品名や用法は、画像に見える改行・並びをなるべく維持してください。
- JSON Schema以外の文章は出力しないでください。
{$template}{$learningHints}
PROMPT;
    }

    public static function ocrResponseSchema(): array
    {
        $lineSchema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['line_no', 'source_section', 'text', 'confidence', 'visual_notes'],
            'properties' => [
                'line_no' => ['type' => 'integer'],
                'source_section' => ['type' => 'string'],
                'text' => ['type' => 'string'],
                'confidence' => ['type' => 'number'],
                'visual_notes' => ['type' => 'string'],
            ],
        ];
        $blockSchema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['block_id', 'source_section', 'text', 'confidence', 'visual_notes'],
            'properties' => [
                'block_id' => ['type' => 'string'],
                'source_section' => ['type' => 'string'],
                'text' => ['type' => 'string'],
                'confidence' => ['type' => 'number'],
                'visual_notes' => ['type' => 'string'],
            ],
        ];
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['raw_text', 'blocks', 'lines', 'warnings', 'overall_confidence'],
            'properties' => [
                'raw_text' => ['type' => 'string'],
                'blocks' => ['type' => 'array', 'items' => $blockSchema],
                'lines' => ['type' => 'array', 'items' => $lineSchema],
                'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
                'overall_confidence' => ['type' => 'number'],
            ],
        ];
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private static function normalizeOcrStructured(array $value): array
    {
        $rawText = trim((string)($value['raw_text'] ?? ''));
        $lines = [];
        foreach ((array)($value['lines'] ?? []) as $i => $line) {
            if (!is_array($line)) {
                continue;
            }
            $text = trim((string)($line['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $lines[] = [
                'line_no' => is_numeric($line['line_no'] ?? null) ? (int)$line['line_no'] : $i + 1,
                'source_section' => trim((string)($line['source_section'] ?? 'unknown')),
                'text' => $text,
                'confidence' => self::normalizeConfidencePercent($line['confidence'] ?? 0),
                'visual_notes' => trim((string)($line['visual_notes'] ?? '')),
            ];
        }
        $blocks = [];
        foreach ((array)($value['blocks'] ?? []) as $i => $block) {
            if (!is_array($block)) {
                continue;
            }
            $text = trim((string)($block['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $blocks[] = [
                'block_id' => trim((string)($block['block_id'] ?? ('block_' . ($i + 1)))),
                'source_section' => trim((string)($block['source_section'] ?? 'unknown')),
                'text' => $text,
                'confidence' => self::normalizeConfidencePercent($block['confidence'] ?? 0),
                'visual_notes' => trim((string)($block['visual_notes'] ?? '')),
            ];
        }
        if ($rawText === '' && $lines) {
            $rawText = implode("\n", array_map(static fn(array $line): string => (string)$line['text'], $lines));
        }
        return [
            'raw_text' => $rawText,
            'blocks' => $blocks,
            'lines' => $lines,
            'warnings' => array_values(array_filter(array_map('strval', (array)($value['warnings'] ?? [])))),
            'overall_confidence' => self::normalizeConfidencePercent($value['overall_confidence'] ?? 0),
        ];
    }

    /** @return array<string,mixed> */
    private static function demoOcrStructured(): array
    {
        return self::normalizeOcrStructured([
            'raw_text' => "処方箋\n氏名 山田 花子\n生年月日 昭和50年4月12日\n保険者番号 12345678\nカロナール錠200mg 1回1錠 1日3回 毎食後 5日分",
            'blocks' => [
                ['block_id' => 'patient', 'source_section' => '患者欄', 'text' => "氏名 山田 花子\n生年月日 昭和50年4月12日", 'confidence' => 92, 'visual_notes' => 'demo'],
                ['block_id' => 'medication_1', 'source_section' => '処方欄', 'text' => 'カロナール錠200mg 1回1錠 1日3回 毎食後 5日分', 'confidence' => 90, 'visual_notes' => 'demo'],
            ],
            'lines' => [
                ['line_no' => 1, 'source_section' => '患者欄', 'text' => '氏名 山田 花子', 'confidence' => 92, 'visual_notes' => 'demo'],
                ['line_no' => 2, 'source_section' => '患者欄', 'text' => '生年月日 昭和50年4月12日', 'confidence' => 90, 'visual_notes' => 'demo'],
                ['line_no' => 3, 'source_section' => '保険欄', 'text' => '保険者番号 12345678', 'confidence' => 91, 'visual_notes' => 'demo'],
                ['line_no' => 4, 'source_section' => '処方欄', 'text' => 'カロナール錠200mg 1回1錠 1日3回 毎食後 5日分', 'confidence' => 90, 'visual_notes' => 'demo'],
            ],
            'warnings' => ['demo data'],
            'overall_confidence' => 91,
        ]);
    }

    private static function structureSystemPrompt(?array $templateHint, string $learningHints = ''): string
    {
        $template = '';
        if ($templateHint && !(bool)app_config('prescription_minimal_analysis.ignore_template_hints', true)) {
            $template = "\n既知テンプレート情報: " . json_encode($templateHint, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        return <<<PROMPT
あなたは日本の薬局で利用する処方箋OCRの項目書き出しエンジンです。
入力は、前工程で作成されたOCR生テキストとOCR構造JSONです。
この段階では画像を直接見ていないため、OCR構造JSONに存在しない情報を推測で補完しないでください。

目的:
- OCR生テキスト/行/ブロックを読み、患者情報・保険情報・処方箋情報・医療機関情報・薬品情報へ項目分けしてください。
- 出力は必ず指定JSON Schemaに従ってください。
- 読めない値、複数候補、OCRが怪しい値は空欄または needs_human_check=true にしてください。
- 薬品名・用量・用法・日数・総量は medications 配列に集約してください。
- medications の順序は、処方欄に印字されている上から下、左から右の順番を厳守してください。薬効や重要度で並べ替えないでください。
- 外用薬・点眼薬・頓服等で「頭に」「乾燥部位に」「患部に」「右眼に」などの使用部位・使用条件が書かれている場合は不要扱いせず、usage_textに原文順で含めてください。
- 帳票上に存在する項目は form_fields に残してください。ただし薬品名元テキスト・辞書候補・推定候補などの補助学習専用データは form_fields に出さないでください。
- 和暦はPHP側でも検証しますが、この段階でも必ず和暦表で西暦へ変換してください。令和8年は2026年です。受付年月日、交付年月日、生年月日を混同しないでください。
- 保険者番号は6桁または8桁、公費負担者番号は8桁、公費受給者番号は7桁、医療機関コードは通常7桁として扱ってください。条件に合わない場合は needs_human_check=true にしてください。
{$template}{$learningHints}
PROMPT;
    }

    private static function systemPrompt(?array $templateHint, string $learningHints = ''): string
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
form_fields には、空欄でも帳票上に存在する主要項目を入れてください。例: 公費負担者番号、公費負担医療の受給者番号、保険者番号、被保険者証の記号番号、患者氏名、フリガナ/ふりがな/カナ/かな、生年月日、性別、区分、交付年月日、受付年月日、処方箋使用期間、医療機関所在地、医療機関名、電話番号、保険医氏名、都道府県番号、点数表番号、医療機関コード、備考、保険医署名、記名押印、変更不可（医療上必要）、後発品変更不可、患者希望、先発医薬品患者希望、QR有無など。
薬品名・用量・用法・日数・総量は form_fields に重複出力せず、medications 配列に集約してください。画面側では medications の処方薬カードで修正・DB保存します。
ただし、画像上に実際に書かれていない一般名候補・商品名候補・薬品名元テキスト・辞書候補・推定候補は、form_fieldsには出さないでください。これらは画面表示項目ではなく、medications内または後処理辞書の補助データとして扱います。
画面側では field_group と value_type を見て、form_fields から修正用の入力一覧を動的に生成します。画像内に見える項目は、固定項目に入らなくても form_fields に残してください。
source_section には、上部左、上部右、患者欄、保険欄、医療機関欄、処方欄、備考欄、下部QRなど、帳票上の位置が分かる表現を入れてください。これは拠点別レイアウト学習に使います。
出力に使うかどうかは人間が後で選択するため、include_default は「通常出力に使いそうな項目」だけ true にし、それ以外も form_fields には残してください。
出力は必ず指定JSON Schemaに従い、余計な文章を含めないでください。
数字、日付、薬品名、用法、日数は特に慎重に扱ってください。用法は、1回量（錠/包/カプセル/mL/mg/g等）、服薬回数（1日N回、分N、毎食後、朝夕、就寝前等）、日数に加えて、外用の使用部位・投与経路・条件（例: 頭に、乾燥部位に、患部に、右眼に、疼痛時）も省略せず原文順で読んでください。総量は 1回量×服薬回数/日×日数 で判断できる場合のみ amount_text に入れ、判断できない場合は空欄またはneeds_human_check=trueにしてください。薬品名中の5mg/0.05mgなどは規格量の可能性が高いため、総量として扱わないでください。
日付は西暦4桁または和暦（明治/大正/昭和/平成/令和、M/T/S/H/R）を認識してください。2桁年だけの場合は西暦・和暦を推測で確定せずneeds_human_check=trueにしてください。
保険者番号は6桁または8桁のみです。10桁などで読めた場合は、別欄の番号を混ぜている可能性が高いため、保険者番号として確定せずneeds_human_check=trueにしてください。
公費負担者番号は8桁、公費負担医療の受給者番号は7桁です。医療機関等コードは通常7桁、都道府県番号+点数表番号付きなら10桁候補として扱ってください。
薬品名や用法が読みにくい場合はneeds_human_check=trueにしてください。
手書き、薄い印字、小さい文字、ぼけ、にじみ、影、罫線被り、低解像度で読みにくい箇所は、推測で確定せずreasonへ「手書き疑い」「薄い印字」「ぼけ」「にじみ」「小さい文字」などの視覚的理由を短く残してください。これは文字品質の補助学習に使います。
印字と手書きが混在する場合、手書きらしい値は信頼度を下げ、候補として残しneeds_human_check=trueにしてください。
処方箋受付ルール判定のため、「変更不可（医療上必要）」「後発品変更不可」「患者希望」「保険医署名」「記名押印」は、見える場合必ずform_fieldsへ入れてください。チェック欄はvalue_type=booleanにし、チェックありなら「有」、チェックなし/空欄なら「無」にしてください。変更不可欄と患者希望欄が同時に見える場合も、推測で片方を消さず、読めたまま返してください。
薬品名については、一般名・商品名・販売名・後発品名・先発品名・屋号違いをできるだけ区別してください。
同じ処方ブロック内に商品名と一般名が併記されている場合は、別薬として分けず、原則1つのmedications要素にまとめてください。
その場合、drug_nameには薬局で表示・保存したい代表名を入れてください。generic_nameとbrand_nameは画像内に明確に書かれている場合だけ入れ、辞書照合で推定しただけなら空文字にしてください。raw_drug_textは補助学習用なので、画面表示用のform_fieldsには出さず、medications内だけに保持してください。
同一薬か別薬か判断できない場合は、分けてもよいですが name_relation="multiple_candidates" とし、needs_human_check=trueにしてください。
処方薬のraw_drug_textには、薬品名だけでなく処方欄に見える用法・使用部位・備考行を、印字の並び順どおり改行で保持してください。
{$template}{$learningHints}
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
                'reason'
            ],
            'properties' => [
                'field_key' => ['type' => 'string', 'description' => 'snake_case。分からない場合は項目名から生成。例: patient_name, public_expense_beneficiary_number'],
                'field_label' => ['type' => 'string', 'description' => '帳票上または人間に表示する項目名。例: 氏名、保険者番号、公費負担者番号'],
                'field_group' => ['type' => 'string', 'enum' => ['patient', 'insurance', 'public_expense', 'prescription', 'medical_institution', 'medication', 'pharmacy', 'note', 'qr', 'other']],
                'value' => ['type' => 'string', 'description' => '読み取った値。空欄なら空文字。'],
                'value_type' => ['type' => 'string', 'enum' => ['text', 'date', 'number', 'code', 'person_name', 'drug', 'usage', 'amount', 'boolean', 'unknown']],
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
                    'required' => ['issued_on', 'received_on', 'expires_on', 'confidence', 'needs_human_check'],
                    'properties' => [
                        'issued_on' => ['type' => 'string'],
                        'received_on' => ['type' => 'string'],
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
        $normalized = self::normalizeSectionConfidenceValues($normalized);
        $normalized['medications'] = self::normalizeMedications(is_array($normalized['medications'] ?? null) ? $normalized['medications'] : []);
        $normalized = self::applyReferenceNormalizers($normalized);
        $normalized['form_fields'] = self::normalizeFormFields($normalized);
        return $normalized;
    }

    /** @param array<string,mixed> $normalized @return array<string,mixed> */
    private static function normalizeSectionConfidenceValues(array $normalized): array
    {
        foreach (['patient', 'insurance', 'prescription', 'medical_institution'] as $section) {
            if (isset($normalized[$section]) && is_array($normalized[$section]) && array_key_exists('confidence', $normalized[$section])) {
                $normalized[$section]['confidence'] = self::normalizeConfidencePercent($normalized[$section]['confidence']);
            }
        }
        if (array_key_exists('overall_confidence', $normalized)) {
            $normalized['overall_confidence'] = self::normalizeConfidencePercent($normalized['overall_confidence']);
        }
        return $normalized;
    }

    private static function normalizeConfidencePercent(mixed $confidence): float
    {
        if (!is_numeric($confidence)) {
            return 0.0;
        }
        $value = (float)$confidence;
        if ($value >= 0.0 && $value <= 1.0) {
            $value *= 100.0;
        }
        return round(max(0.0, min(100.0, $value)), 2);
    }

    public static function blankNormalized(): array
    {
        return [
            'patient' => ['name' => '', 'kana' => '', 'birth_date' => '', 'gender' => '不明', 'confidence' => 0.0, 'needs_human_check' => true],
            'insurance' => ['insurance_no' => '', 'insured_symbol_number' => '', 'copay_rate' => '', 'confidence' => 0.0, 'needs_human_check' => true],
            'prescription' => ['issued_on' => '', 'received_on' => '', 'expires_on' => '', 'confidence' => 0.0, 'needs_human_check' => true],
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
            'prescription' => ['issued_on' => '2024-05-20', 'received_on' => '', 'expires_on' => '', 'confidence' => 88.0, 'needs_human_check' => true],
            'medical_institution' => ['code' => '1312345', 'name' => 'さくらクリニック', 'doctor_name' => '', 'phone' => '', 'confidence' => 86.0, 'needs_human_check' => true],
            'medications' => [
                ['drug_name' => 'アムロジピンOD錠5mg', 'generic_name' => 'アムロジピンOD錠5mg', 'brand_name' => '', 'raw_drug_text' => 'アムロジピンOD錠5mg', 'name_relation' => 'single', 'dose_text' => '1錠', 'usage_text' => '1日1回 朝食後', 'days_count' => 28, 'amount_text' => '28日分', 'confidence' => 89.0, 'needs_human_check' => true, 'reason' => 'demo'],
                ['drug_name' => 'ムコソルバン錠15mg', 'generic_name' => 'アンブロキソール塩酸塩錠15mg', 'brand_name' => 'ムコソルバン錠15mg', 'raw_drug_text' => "ムコソルバン錠15mg
【般】アンブロキソール塩酸塩錠15mg", 'name_relation' => 'generic_brand_pair', 'dose_text' => '1錠', 'usage_text' => '1日3回 毎食後', 'days_count' => 28, 'amount_text' => '28日分', 'confidence' => 87.0, 'needs_human_check' => true, 'reason' => 'demo'],
            ],
            'form_fields' => [
                ['field_key' => 'patient_name', 'field_label' => '氏名', 'field_group' => 'patient', 'value' => '山田 花子', 'value_type' => 'person_name', 'source_section' => '患者欄', 'confidence' => 94.2, 'needs_human_check' => true, 'include_default' => true, 'output_candidate' => true, 'reason' => 'demo'],
                ['field_key' => 'insurance_no', 'field_label' => '保険者番号', 'field_group' => 'insurance', 'value' => '12345678', 'value_type' => 'code', 'source_section' => '保険欄', 'confidence' => 90.0, 'needs_human_check' => true, 'include_default' => true, 'output_candidate' => true, 'reason' => 'demo'],
                ['field_key' => 'medical_institution_name', 'field_label' => '保険医療機関の名称', 'field_group' => 'medical_institution', 'value' => 'さくらクリニック', 'value_type' => 'text', 'source_section' => '医療機関欄', 'confidence' => 86.0, 'needs_human_check' => true, 'include_default' => true, 'output_candidate' => true, 'reason' => 'demo'],
            ],
            'warnings' => ['demo data'],
            'overall_confidence' => 91.2,
        ]);
    }

    /** @param array<string,mixed> $normalized @return array<string,mixed> */
    private static function applyReferenceNormalizers(array $normalized): array
    {
        if (class_exists('PrescriptionReferenceRuleService')) {
            foreach ([['patient','birth_date'], ['prescription','issued_on'], ['prescription','received_on'], ['prescription','expires_on']] as [$section, $key]) {
                $raw = (string)($normalized[$section][$key] ?? '');
                if ($raw === '') {
                    continue;
                }
                $date = PrescriptionReferenceRuleService::normalizeDate($raw);
                if (is_string($date['normalized'] ?? null) && $date['normalized'] !== '') {
                    $normalized[$section][$key] = (string)$date['normalized'];
                }
                if (!empty($date['needs_human_check'])) {
                    $normalized[$section]['needs_human_check'] = true;
                    $normalized['warnings'][] = ($section . '.' . $key . ': ' . (string)($date['message'] ?? '日付要確認'));
                }
            }
            if ((string)($normalized['insurance']['insurance_no'] ?? '') !== '') {
                $code = PrescriptionReferenceRuleService::validateCode('insurance_no', (string)$normalized['insurance']['insurance_no']);
                if (!empty($code['valid'])) {
                    $normalized['insurance']['insurance_no'] = (string)$code['digits'];
                } else {
                    $normalized['insurance']['needs_human_check'] = true;
                    $normalized['warnings'][] = '保険者番号: ' . (string)($code['message'] ?? '形式要確認');
                }
            }
            if ((string)($normalized['medical_institution']['code'] ?? '') !== '') {
                $code = PrescriptionReferenceRuleService::validateCode('medical_institution_code', (string)$normalized['medical_institution']['code']);
                if (!empty($code['valid'])) {
                    $normalized['medical_institution']['code'] = (string)$code['digits'];
                } else {
                    $normalized['medical_institution']['needs_human_check'] = true;
                    $normalized['warnings'][] = '医療機関コード: ' . (string)($code['message'] ?? '形式要確認');
                }
            }
        }
        $normalized['warnings'] = array_values(array_unique(array_filter(array_map('strval', (array)($normalized['warnings'] ?? [])))));
        return $normalized;
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
            $doseText = trim((string)($med['dose_text'] ?? ''));
            $usageText = trim((string)($med['usage_text'] ?? ''));
            $amountText = trim((string)($med['amount_text'] ?? ''));
            if ($rawDrugText === '') {
                $rawDrugText = implode("
", array_values(array_filter([$drugName, $genericName, $brandName, $doseText, $usageText, $amountText], static fn($v) => trim((string)$v) !== '')));
            }
            $usageSupplement = self::extractMedicationUsageSupplement($rawDrugText, $drugName, $usageText);
            if ($usageSupplement !== '') {
                $usageText = self::appendUniqueMedicationText($usageText, $usageSupplement);
            }
            $out[] = [
                'drug_name' => $drugName,
                'generic_name' => $genericName,
                'brand_name' => $brandName,
                'raw_drug_text' => $rawDrugText,
                'name_relation' => $relation,
                'dose_text' => $doseText,
                'usage_text' => $usageText,
                'days_count' => is_numeric($med['days_count'] ?? null) ? (int)$med['days_count'] : null,
                'amount_text' => $amountText,
                'confidence' => self::normalizeConfidencePercent($med['confidence'] ?? 0.0),
                'needs_human_check' => (bool)($med['needs_human_check'] ?? true),
                'reason' => (string)($med['reason'] ?? ''),
            ];
        }
        return $out;
    }

    private static function appendUniqueMedicationText(string $base, string $addition): string
    {
        $base = trim($base);
        $addition = trim($addition);
        if ($base === '' || $addition === '') {
            return $base !== '' ? $base : $addition;
        }
        $baseCompact = preg_replace('/\s+/u', '', $base) ?? $base;
        $additionCompact = preg_replace('/\s+/u', '', $addition) ?? $addition;
        if ($additionCompact !== '' && str_contains($baseCompact, $additionCompact)) {
            return $base;
        }
        return $base . ' ' . $addition;
    }

    private static function extractMedicationUsageSupplement(string $rawDrugText, string $drugName, string $currentUsage): string
    {
        $lines = preg_split('/\R+/u', trim($rawDrugText)) ?: [];
        $out = [];
        $drugCompact = preg_replace('/\s+/u', '', $drugName) ?? $drugName;
        $usageCompact = preg_replace('/\s+/u', '', $currentUsage) ?? $currentUsage;
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '') {
                continue;
            }
            $lineCompact = preg_replace('/\s+/u', '', $line) ?? $line;
            if ($drugCompact !== '' && $lineCompact === $drugCompact) {
                continue;
            }
            if ($usageCompact !== '' && str_contains($usageCompact, $lineCompact)) {
                continue;
            }
            if (preg_match('/(頭|頭部|額|顔|頬|口唇|首|胸|腹|背|腕|手|指|足|脚|陰部|患部|乾燥部位|かゆい所|湿疹部位|外用部位|右眼|左眼|両眼|鼻|耳|口腔|舌下|部位|塗布|塗擦|貼付|点眼|点耳|噴霧|吸入|うがい|含嗽|1\s*日|１\s*日|1\s*回|１\s*回|分\s*\d+|毎食|朝|昼|夕|就寝|寝る前|食前|食後|頓服|必要時|疼痛時)/u', $line)) {
                $out[] = $line;
            }
        }
        return implode(' ', array_values(array_unique($out)));
    }

    /** @return array<int,array<string,mixed>> */
    private static function normalizeFormFields(array $normalized): array
    {
        $fields = [];
        foreach (($normalized['form_fields'] ?? []) as $field) {
            if (!is_array($field) || self::isLearningOnlyFormField($field)) {
                continue;
            }
            // 処方薬は medications の専用カードで修正・保存するため、form_fields 側には重複表示しない。
            if ((string)($field['field_group'] ?? '') === 'medication') {
                continue;
            }
            $fields[] = self::normalizeFormField($field);
        }

        $auto = [
            ['patient_name', '氏名', 'patient', (string)($normalized['patient']['name'] ?? ''), 'person_name', '患者欄', $normalized['patient']['confidence'] ?? 0, true],
            ['patient_kana', 'フリガナ/ふりがな', 'patient', (string)($normalized['patient']['kana'] ?? ''), 'text', '患者欄', $normalized['patient']['confidence'] ?? 0, false],
            ['patient_birth_date', '生年月日', 'patient', (string)($normalized['patient']['birth_date'] ?? ''), 'date', '患者欄', $normalized['patient']['confidence'] ?? 0, true],
            ['patient_gender', '性別', 'patient', (string)($normalized['patient']['gender'] ?? ''), 'text', '患者欄', $normalized['patient']['confidence'] ?? 0, true],
            ['insurance_no', '保険者番号', 'insurance', (string)($normalized['insurance']['insurance_no'] ?? ''), 'code', '保険欄', $normalized['insurance']['confidence'] ?? 0, true],
            ['insured_symbol_number', '被保険者証・被保険者手帳の記号・番号', 'insurance', (string)($normalized['insurance']['insured_symbol_number'] ?? ''), 'code', '保険欄', $normalized['insurance']['confidence'] ?? 0, true],
            ['copay_rate', '負担割合', 'insurance', (string)($normalized['insurance']['copay_rate'] ?? ''), 'text', '保険欄', $normalized['insurance']['confidence'] ?? 0, false],
            ['issued_on', '交付年月日', 'prescription', (string)($normalized['prescription']['issued_on'] ?? ''), 'date', '処方箋欄', $normalized['prescription']['confidence'] ?? 0, true],
            ['received_on', '受付年月日', 'prescription', (string)($normalized['prescription']['received_on'] ?? ''), 'date', '処方箋欄', $normalized['prescription']['confidence'] ?? 0, false],
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

        // 処方薬情報は medications 配列の専用UIで扱う。form_fields へ複製しない。

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
            return ($groupOrder[$a['field_group']] ?? 99) <=> ($groupOrder[$b['field_group']] ?? 99);
        });

        return $out;
    }

    /** @param array<string,mixed> $field */
    private static function isLearningOnlyFormField(array $field): bool
    {
        $key = mb_strtolower((string)($field['field_key'] ?? ''));
        $label = mb_strtolower((string)($field['field_label'] ?? ''));
        $reason = mb_strtolower((string)($field['reason'] ?? ''));
        $text = $key . ' ' . $label . ' ' . $reason;
        foreach (['raw_drug_text', '薬品名元テキスト', '元テキスト', 'generic_name', '一般名候補', 'brand_name', '商品名候補', 'relation_type', '薬品名の関係', 'drug_name_relation', 'name_relation', '辞書候補'] as $needle) {
            if (str_contains($text, mb_strtolower($needle))) {
                return true;
            }
        }
        return false;
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
            'source_section' => mb_substr((string)($field['source_section'] ?? ''), 0, 160),
            'confidence' => self::normalizeConfidencePercent($field['confidence'] ?? 0.0),
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

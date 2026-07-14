<?php
declare(strict_types=1);

/**
 * 低価格モデル向けの単一目的パイプライン。
 *
 * 画像に対する処理は次の2つだけに分離する。
 * 1) 画像上の文字をそのままOCRする。
 * 2) 個人値を含めず、帳票テンプレート構造だけを取得する。
 *
 * 患者・保険・公費・医療機関・薬剤等の項目化は、OCR結果を根拠に
 * 「1 APIリクエスト = 1項目」のテキストタスクとして並列実行する。
 */
final class PrescriptionSingleTaskExtractionService
{
    /**
     * @return array<string,mixed>
     */
    public function extract(OpenAiPrescriptionClient $client, string $imagePath, string $mimeType, ?array $templateHint = null): array
    {
        $ocr = $client->extractOcrFromImage($imagePath, $mimeType, $templateHint);
        try {
            $template = $client->extractTemplateFromImage($imagePath, $mimeType);
        } catch (Throwable $e) {
            $template = [
                'raw' => ['error' => $e->getMessage(), 'stage' => 'template_structure'],
                'template' => [
                    'page_orientation' => 'unknown',
                    'template_kind' => 'unknown',
                    'fixed_labels' => [],
                    'sections' => [],
                    'frame_notes' => [],
                    'warnings' => ['template_structure_error: ' . $e->getMessage()],
                    'overall_confidence' => 0,
                    'privacy_level' => 'fixed_labels_and_geometry_only_no_values',
                    'status' => 'candidate_error',
                ],
                'model' => 'error',
            ];
        }
        $tasks = $this->buildTasks($ocr['structured_json']);
        $taskBatch = $client->extractSingleTaskFieldsFromOcr($tasks);
        $normalized = $this->mergeTaskResults(
            is_array($taskBatch['results'] ?? null) ? $taskBatch['results'] : [],
            is_array($ocr['structured_json'] ?? null) ? $ocr['structured_json'] : [],
            is_array($template['template'] ?? null) ? $template['template'] : []
        );
        $taskErrors = is_array($taskBatch['errors'] ?? null) ? $taskBatch['errors'] : [];
        if ($taskErrors) {
            $normalized['_single_task_errors'] = $taskErrors;
            foreach ($taskErrors as $taskId => $message) {
                $normalized['warnings'][] = '単一タスク ' . $taskId . ': ' . (string)$message;
            }
            $normalized['warnings'] = array_values(array_unique(array_filter(array_map('strval', (array)$normalized['warnings']))));
        }

        $tierSummary = OpenAiPrescriptionClient::modelTierSummary('low');
        return [
            'raw' => [
                'pipeline_version' => 'single_task_image_json_v1',
                'pipeline_mode' => 'single_task',
                'model_tier' => $tierSummary,
                'ocr_raw_response' => $ocr['raw'] ?? [],
                'template_raw_response' => $template['raw'] ?? [],
                'single_task_raw_responses' => $taskBatch['raw_responses'] ?? [],
                'single_task_errors' => $taskBatch['errors'] ?? [],
                'usage_summary' => $this->usageSummary([
                    $ocr['raw'] ?? [],
                    $template['raw'] ?? [],
                    ...array_values((array)($taskBatch['raw_responses'] ?? [])),
                ]),
            ],
            'ocr_raw_text' => (string)($ocr['raw_text'] ?? ''),
            'ocr_structured_json' => $ocr['structured_json'] ?? [],
            'template_observation' => $template['template'] ?? [],
            'single_task_results' => $taskBatch['results'] ?? [],
            'prescription_item_json' => $normalized,
            'normalized' => $normalized,
            'model' => (string)($taskBatch['model'] ?? $ocr['model'] ?? 'gpt-4o-mini'),
            'models' => [
                'tier' => $tierSummary['tier'],
                'tier_label' => $tierSummary['label'],
                'ocr' => (string)($ocr['model'] ?? 'gpt-4o-mini'),
                'template' => (string)($template['model'] ?? 'gpt-4o-mini'),
                'structure' => (string)($taskBatch['model'] ?? 'gpt-4o-mini'),
                'mapping' => 'php_single_task_merge',
            ],
        ];
    }

    /**
     * @param array<string,mixed> $ocrStructured
     * @return array<int,array<string,mixed>>
     */
    private function buildTasks(array $ocrStructured): array
    {
        $defs = self::taskDefinitions();
        $tasks = [];
        foreach ($defs as $def) {
            $def['evidence'] = $this->buildEvidence($ocrStructured, $def);
            $tasks[] = $def;
        }
        return $tasks;
    }

    /** @return array<int,array<string,mixed>> */
    public static function taskDefinitions(): array
    {
        return [
            self::scalar('patient.name', '患者氏名', 'patient', 'person_name', '患者本人の氏名は何と印字されていますか。患者欄の氏名だけを返してください。医師名や医療機関名は返さないでください。', ['氏名', '患者氏名', '患者名'], ['患者欄']),
            self::scalar('patient.kana', 'フリガナ/ふりがな', 'patient', 'text', '患者氏名のフリガナまたはふりがなは何と印字されていますか。カタカナ・ひらがなを画像どおり返してください。', ['フリガナ', 'ふりがな', 'ふり仮名', 'カナ', 'かな'], ['患者欄']),
            self::scalar('patient.birth_date', '生年月日', 'patient', 'date', '患者の生年月日は何と印字されていますか。和暦を勝手に西暦へ変換せず、画像上の元表記をそのまま返してください。', ['生年月日', '誕生日', '明治', '大正', '昭和', '平成', '令和'], ['患者欄']),
            self::scalar('patient.gender', '性別', 'patient', 'text', '患者の性別欄は何と表示または選択されていますか。男性・女性・不明のいずれかを返し、推測しないでください。', ['性別', '男', '女'], ['患者欄']),
            self::scalar('patient.address', '患者住所', 'patient', 'text', '患者本人の住所が印字されている場合、その住所だけを返してください。医療機関所在地は返さないでください。', ['患者住所', '住所'], ['患者欄']),
            self::scalar('patient.age', '年齢', 'patient', 'number', '患者の年齢が明示されている場合、その年齢表記だけを返してください。生年月日から計算しないでください。', ['年齢', '歳'], ['患者欄']),

            self::scalar('insurance.insurance_no', '保険者番号', 'insurance', 'code', '保険者番号欄に印字された値は何ですか。公費負担者番号、受給者番号、記号番号は返さないでください。見えた数字を追加・削除せずそのまま返してください。', ['保険者番号'], ['保険欄']),
            self::scalar('insurance.insured_symbol_number', '被保険者記号・番号', 'insurance', 'code', '被保険者証の記号・番号欄に印字された値は何ですか。公費の受給者番号は返さないでください。', ['記号・番号', '記号番号', '被保険者証', '被保険者手帳'], ['保険欄']),
            self::scalar('insurance.copay_rate', '負担割合', 'insurance', 'text', '患者の負担割合が明示されている場合、その表記だけを返してください。高7・高8等の区分から推測しないでください。', ['負担割合', '割', '高7', '高8'], ['保険欄']),
            self::scalar('public_expense.payer_no', '公費負担者番号', 'public_expense', 'code', '公費負担者番号欄に印字された値は何ですか。保険者番号や受給者番号は返さないでください。', ['公費負担者番号', '公費負担番号'], ['公費欄', '保険欄']),
            self::scalar('public_expense.beneficiary_no', '公費受給者番号', 'public_expense', 'code', '公費負担医療の受給者番号欄に印字された値は何ですか。被保険者の記号番号は返さないでください。', ['受給者番号', '公費受給者番号', '公費負担医療'], ['公費欄', '保険欄']),

            self::scalar('prescription.issued_on', '交付年月日', 'prescription', 'date', '処方箋の交付年月日は何と印字されていますか。和暦を変換せず元表記を返してください。', ['交付年月日', '発行年月日', '処方箋交付年月日'], ['処方箋欄', '上部']),
            self::scalar('prescription.received_on', '受付年月日', 'prescription', 'date', '受付年月日または受付日は何と印字されていますか。交付年月日と混同せず、和暦は元表記のまま返してください。', ['受付年月日', '受付日'], ['処方箋欄', '薬局記入欄']),
            self::scalar('prescription.expires_on', '処方箋使用期間', 'prescription', 'date', '処方箋の使用期間または有効期限が明示されている場合、その表記だけを返してください。交付日から計算しないでください。', ['使用期間', '有効期限', '有効期間'], ['処方箋欄']),

            self::scalar('medical_institution.code', '医療機関コード', 'medical_institution', 'code', '保険医療機関コードまたは医療機関等コード欄の値は何ですか。住所の番地や電話番号は返さないでください。', ['医療機関コード', '医療機関等コード', '保険医療機関コード'], ['医療機関欄']),
            self::scalar('medical_institution.name', '保険医療機関名', 'medical_institution', 'text', '処方箋を発行した保険医療機関の名称は何ですか。薬局名は返さないでください。', ['保険医療機関', '医療機関名', '病院', '医院', 'クリニック'], ['医療機関欄']),
            self::scalar('medical_institution.address', '保険医療機関所在地', 'medical_institution', 'text', '処方箋を発行した医療機関の所在地は何ですか。患者住所は返さないでください。', ['所在地', '医療機関住所', '保険医療機関'], ['医療機関欄']),
            self::scalar('medical_institution.phone', '医療機関電話番号', 'medical_institution', 'text', '処方箋を発行した医療機関の電話番号は何ですか。患者電話番号や薬局電話番号は返さないでください。', ['電話', 'TEL', 'Tel'], ['医療機関欄']),
            self::scalar('medical_institution.doctor_name', '保険医氏名', 'medical_institution', 'person_name', '処方した保険医の氏名は何ですか。患者氏名は返さないでください。', ['保険医氏名', '医師氏名', '医師名', '担当医'], ['医療機関欄']),

            self::scalar('substitution.change_disallowed', '変更不可', 'prescription', 'boolean', '後発医薬品への変更不可欄にチェック、レ点、丸、×、署名等がありますか。ある場合は「有」、何もなければ「無」、画像で判断できなければ「判定不能」と返してください。', ['変更不可', '医療上必要', '後発品変更不可'], ['処方欄', '備考欄']),
            self::scalar('substitution.patient_request', '患者希望', 'prescription', 'boolean', '患者希望欄にチェック、レ点、丸、×等がありますか。ある場合は「有」、何もなければ「無」、画像で判断できなければ「判定不能」と返してください。', ['患者希望', '先発希望'], ['処方欄', '備考欄']),
            self::scalar('substitution.doctor_signature_or_seal', '保険医署名・記名押印', 'medical_institution', 'boolean', '保険医の署名または記名押印が確認できますか。確認できれば「有」、何もなければ「無」、画像で判断できなければ「判定不能」と返してください。', ['署名', '記名押印', '押印', '保険医'], ['医療機関欄', '処方欄']),

            [
                'task_id' => 'medications',
                'response_type' => 'medications',
                'field_key' => 'medications',
                'field_label' => '処方薬情報',
                'field_group' => 'medication',
                'value_type' => 'drug',
                'question' => '処方薬欄に印字された薬剤情報を、上から下の印字順で抽出してください。薬品名、用量、用法、日数、総量、使用部位や条件（例: 頭に、乾燥部位に、1日1～2回塗布）を省略せず、原文の並びを維持してください。画像にない一般名・商品名は作らないでください。',
                'keywords' => ['処方', 'Rp', 'Ｒｐ', '錠', 'カプセル', '散', 'g', 'mL', '塗布', '点眼', '日分'],
                'sections' => ['処方欄', '薬剤欄'],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function scalar(string $key, string $label, string $group, string $valueType, string $question, array $keywords, array $sections): array
    {
        return [
            'task_id' => str_replace('.', '_', $key),
            'response_type' => 'scalar',
            'field_key' => $key,
            'field_label' => $label,
            'field_group' => $group,
            'value_type' => $valueType,
            'question' => $question,
            'keywords' => $keywords,
            'sections' => $sections,
        ];
    }

    /**
     * @param array<string,mixed> $ocr
     * @param array<string,mixed> $task
     * @return array<string,mixed>
     */
    private function buildEvidence(array $ocr, array $task): array
    {
        $lines = [];
        foreach ((array)($ocr['lines'] ?? []) as $i => $line) {
            if (!is_array($line)) {
                continue;
            }
            $text = trim((string)($line['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $lines[] = [
                'line_no' => is_numeric($line['line_no'] ?? null) ? (int)$line['line_no'] : $i + 1,
                'source_section' => trim((string)($line['source_section'] ?? '')),
                'text' => $text,
                'confidence' => is_numeric($line['confidence'] ?? null) ? (float)$line['confidence'] : null,
            ];
        }

        $selectedIndexes = [];
        $keywords = array_values(array_filter(array_map('strval', (array)($task['keywords'] ?? []))));
        $sections = array_values(array_filter(array_map('strval', (array)($task['sections'] ?? []))));
        foreach ($lines as $i => $line) {
            $hit = false;
            foreach ($keywords as $keyword) {
                if ($keyword !== '' && mb_stripos((string)$line['text'], $keyword) !== false) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                foreach ($sections as $section) {
                    if ($section !== '' && mb_stripos((string)$line['source_section'], $section) !== false) {
                        $hit = true;
                        break;
                    }
                }
            }
            if ($hit) {
                foreach ([$i - 1, $i, $i + 1] as $candidate) {
                    if (isset($lines[$candidate])) {
                        $selectedIndexes[$candidate] = true;
                    }
                }
            }
        }

        $selected = [];
        foreach (array_keys($selectedIndexes) as $i) {
            $selected[] = $lines[(int)$i];
        }
        if (!$selected) {
            $fallbackMax = ($task['response_type'] ?? '') === 'medications' ? 80 : 35;
            $selected = array_slice($lines, 0, $fallbackMax);
        }

        $blocks = [];
        foreach ((array)($ocr['blocks'] ?? []) as $block) {
            if (!is_array($block)) {
                continue;
            }
            $section = trim((string)($block['source_section'] ?? ''));
            $text = trim((string)($block['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $use = false;
            foreach ($sections as $wanted) {
                if ($wanted !== '' && mb_stripos($section, $wanted) !== false) {
                    $use = true;
                    break;
                }
            }
            if ($use || (($task['response_type'] ?? '') === 'medications' && mb_stripos($section, '処方') !== false)) {
                $blocks[] = ['source_section' => $section, 'text' => $text];
            }
        }

        $maxChars = ($task['response_type'] ?? '') === 'medications'
            ? max(3000, (int)app_config('prescription_single_task_analysis.medication_evidence_max_chars', 12000))
            : max(800, (int)app_config('prescription_single_task_analysis.field_evidence_max_chars', 4500));
        $rawTextFallback = '';
        if ((!$selected && !$blocks) || ($task['response_type'] ?? '') === 'medications') {
            $rawTextFallback = trim((string)($ocr['raw_text'] ?? ''));
        }
        $payload = [
            'lines' => $selected,
            'blocks' => $blocks,
            'raw_text_fallback' => $rawTextFallback,
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
        if (mb_strlen($json) > $maxChars) {
            $json = mb_substr($json, 0, $maxChars);
            return ['truncated_json' => $json, 'truncated' => true];
        }
        return $payload + ['truncated' => false];
    }

    /**
     * @param array<string,array<string,mixed>> $results
     * @param array<string,mixed> $ocr
     * @param array<string,mixed> $template
     * @return array<string,mixed>
     */
    private function mergeTaskResults(array $results, array $ocr, array $template): array
    {
        $normalized = OpenAiPrescriptionClient::blankNormalized();
        $normalized['public_expense'] = ['payer_no' => '', 'beneficiary_no' => '', 'confidence' => 0.0, 'needs_human_check' => false];
        $normalized['substitution'] = [
            'change_disallowed' => false,
            'patient_request' => false,
            'doctor_signature_or_seal' => false,
            'mark_check_detected' => false,
            'mark_x_detected' => false,
            'confidence' => 0.0,
            'needs_human_check' => false,
        ];
        $normalized['form_fields'] = [];
        $normalized['warnings'] = array_values(array_map('strval', (array)($ocr['warnings'] ?? [])));
        $confidenceValues = [];

        $definitions = [];
        foreach (self::taskDefinitions() as $def) {
            $definitions[(string)$def['task_id']] = $def;
        }

        foreach ($definitions as $taskId => $def) {
            $result = is_array($results[$taskId] ?? null) ? $results[$taskId] : [];
            if (($def['response_type'] ?? '') === 'medications') {
                $normalized['medications'] = $this->normalizeMedicationResult($result);
                if (is_numeric($result['confidence'] ?? null)) {
                    $confidenceValues[] = $this->confidence((float)$result['confidence']);
                }
                if (!empty($result['reason'])) {
                    $normalized['warnings'][] = '処方薬情報: ' . (string)$result['reason'];
                }
                continue;
            }

            $value = trim((string)($result['value'] ?? ''));
            $found = !empty($result['found']);
            if (!$found && $value !== '') {
                $found = true;
            }
            $confidence = $this->confidence($result['confidence'] ?? 0);
            if ($confidence > 0) {
                $confidenceValues[] = $confidence;
            }
            $needsCheck = !empty($result['needs_human_check']) || !$found || $value === '' || $confidence < 75.0;
            $key = (string)$def['field_key'];
            if (str_starts_with($key, 'substitution.')) {
                $boolValue = $this->booleanValue($value);
                $this->setPath($normalized, $key, $boolValue);
                if ($value === '判定不能') {
                    $needsCheck = true;
                }
            } else {
                $this->setPath($normalized, $key, $value);
            }
            $section = explode('.', $key)[0] ?? '';
            if (isset($normalized[$section]) && is_array($normalized[$section])) {
                $normalized[$section]['confidence'] = max((float)($normalized[$section]['confidence'] ?? 0), $confidence);
                $normalized[$section]['needs_human_check'] = !empty($normalized[$section]['needs_human_check']) || $needsCheck;
            }
            $normalized['form_fields'][] = [
                'field_key' => $key,
                'field_label' => (string)$def['field_label'],
                'field_group' => (string)$def['field_group'],
                'value' => str_starts_with($key, 'substitution.') ? ($this->booleanValue($value) ? '有' : '無') : $value,
                'value_type' => (string)$def['value_type'],
                'source_section' => trim((string)($result['source_section'] ?? '')),
                'confidence' => $confidence,
                'needs_human_check' => $needsCheck,
                'include_default' => true,
                'output_candidate' => true,
                'reason' => trim((string)($result['reason'] ?? 'single_task_extraction')),
                'raw_text' => trim((string)($result['raw_text'] ?? '')),
                'source_line_numbers' => array_values(array_map('intval', (array)($result['source_line_numbers'] ?? []))),
                'source' => 'single_task_ocr_text',
            ];
        }

        $normalized['overall_confidence'] = $confidenceValues
            ? round(array_sum($confidenceValues) / count($confidenceValues), 2)
            : 0.0;
        $normalized['_template_observation'] = $template;
        $normalized['_single_task_results'] = $results;
        $normalized['_single_task_pipeline'] = [
            'enabled' => true,
            'schema_version' => 'single_task_image_json_v1',
            'task_count' => count($definitions),
            'task_mode' => 'one_request_one_field',
            'image_tasks' => ['ocr_transcription', 'template_structure'],
            'field_tasks_source' => 'ocr_evidence_text',
        ];
        $normalized['warnings'] = array_values(array_unique(array_filter(array_map('strval', $normalized['warnings']))));
        return $normalized;
    }

    /** @return array<int,array<string,mixed>> */
    private function normalizeMedicationResult(array $result): array
    {
        $items = [];
        foreach ((array)($result['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $drug = trim((string)($item['drug_name'] ?? ''));
            $raw = trim((string)($item['raw_drug_text'] ?? ''));
            if ($drug === '' && $raw === '') {
                continue;
            }
            $days = $item['days_count'] ?? null;
            $items[] = [
                'drug_name' => $drug,
                'generic_name' => trim((string)($item['generic_name'] ?? '')),
                'brand_name' => trim((string)($item['brand_name'] ?? '')),
                'raw_drug_text' => $raw,
                'name_relation' => in_array((string)($item['name_relation'] ?? ''), ['single', 'generic_brand_pair', 'multiple_candidates', 'unknown'], true) ? (string)$item['name_relation'] : 'unknown',
                'dose_text' => trim((string)($item['dose_text'] ?? '')),
                'usage_text' => trim((string)($item['usage_text'] ?? '')),
                'days_count' => is_numeric($days) ? (int)$days : null,
                'amount_text' => trim((string)($item['amount_text'] ?? '')),
                'confidence' => $this->confidence($item['confidence'] ?? 0),
                'needs_human_check' => !empty($item['needs_human_check']),
                'reason' => trim((string)($item['reason'] ?? 'single_task_medication')),
            ];
        }
        return $items;
    }

    private function booleanValue(string $value): bool
    {
        $value = mb_strtolower(trim($value));
        return in_array($value, ['有', 'あり', 'true', '1', 'yes', 'チェックあり', '確認あり'], true);
    }

    private function confidence(mixed $value): float
    {
        if (!is_numeric($value)) {
            return 0.0;
        }
        $confidence = (float)$value;
        if ($confidence >= 0.0 && $confidence <= 1.0) {
            $confidence *= 100.0;
        }
        return round(max(0.0, min(100.0, $confidence)), 2);
    }

    /** @param array<string,mixed> $array */
    private function setPath(array &$array, string $path, mixed $value): void
    {
        $parts = explode('.', $path);
        $cursor =& $array;
        foreach ($parts as $index => $part) {
            if ($index === count($parts) - 1) {
                $cursor[$part] = $value;
                return;
            }
            if (!isset($cursor[$part]) || !is_array($cursor[$part])) {
                $cursor[$part] = [];
            }
            $cursor =& $cursor[$part];
        }
    }

    /** @param array<int,mixed> $responses @return array<string,int> */
    private function usageSummary(array $responses): array
    {
        $summary = ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0, 'request_count' => 0];
        foreach ($responses as $response) {
            if (!is_array($response) || !$response) {
                continue;
            }
            $summary['request_count']++;
            $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];
            $summary['input_tokens'] += (int)($usage['input_tokens'] ?? 0);
            $summary['output_tokens'] += (int)($usage['output_tokens'] ?? 0);
            $summary['total_tokens'] += (int)($usage['total_tokens'] ?? 0);
        }
        return $summary;
    }
}

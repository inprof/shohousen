<?php
declare(strict_types=1);

final class PrescriptionKnowledgeService
{
    /**
     * 拠点別テンプレートを検索する。
     * 本番運用ではAI自己評価ではなく、同一拠点で人間確認が蓄積されたテンプレートだけを優先する。
     *
     * @param array<string,mixed>|null $detected
     */
    public function findTemplate(?string $layoutFingerprint = null, ?array $detected = null): ?array
    {
        if (!$layoutFingerprint) {
            return null;
        }

        $sql = 'SELECT * FROM prescription_templates
                WHERE is_active = 1
                  AND layout_fingerprint = :layout_fingerprint
                  AND (
                    scope_type = "global"
                    OR company_uid = :company_uid
                    OR branch_uid = :branch_uid
                  )
                ORDER BY
                  CASE scope_type WHEN "branch" THEN 1 WHEN "company" THEN 2 ELSE 3 END,
                  COALESCE(template_score, 0) DESC,
                  success_count DESC,
                  COALESCE(avg_correction_rate, 100) ASC,
                  id DESC
                LIMIT 1';
        try {
            $stmt = Db::knowledge()->prepare($sql);
            $stmt->execute([
                ':layout_fingerprint' => $layoutFingerprint,
                ':company_uid' => current_company_uid(),
                ':branch_uid' => current_branch_uid(),
            ]);
            $row = $stmt->fetch();
            if (!$row) {
                return null;
            }
            $row['field_map'] = json_decode((string)($row['field_map_json'] ?? '{}'), true) ?: [];
            $row['match_score'] = $this->scoreTemplateForDetected($row, $detected ?: []);
            return $row;
        } catch (Throwable $e) {
            $this->logLearningError('find_template', $e, ['layout_fingerprint' => $layoutFingerprint]);
            return null;
        }
    }

    /** @param array<string,mixed> $detected */
    private function scoreTemplateForDetected(array $template, array $detected): float
    {
        $score = is_numeric($template['template_score'] ?? null) ? (float)$template['template_score'] : 50.0;
        $correction = is_numeric($template['avg_correction_rate'] ?? null) ? (float)$template['avg_correction_rate'] : null;
        if ($correction !== null) {
            $score -= min(25.0, $correction * 0.35);
        }
        if (($template['layout_fingerprint'] ?? '') !== '' && ($template['layout_fingerprint'] ?? '') === ($detected['layout_fingerprint'] ?? '')) {
            $score += 12.0;
        }
        $success = (int)($template['success_count'] ?? $template['sample_count'] ?? 0);
        $score += min(18.0, $success * 3.0);
        return round(max(0.0, min(98.0, $score)), 2);
    }

    /** @param array<string,mixed> $detected */
    public function saveTemplateMatchLog(int $parseJobId, int $tenantId, ?int $templateId, ?float $score, int $detectionMs, string $result): void
    {
        try {
            $stmt = Db::knowledge()->prepare('INSERT INTO prescription_template_match_logs
                (parse_job_id, company_uid, branch_uid, tenant_id, matched_template_id, match_score, detection_ms, result, created_at)
                VALUES (:parse_job_id, :company_uid, :branch_uid, :tenant_id, :matched_template_id, :match_score, :detection_ms, :result, NOW())');
            $stmt->execute([
                ':parse_job_id' => $parseJobId,
                ':company_uid' => current_company_uid(),
                ':branch_uid' => current_branch_uid(),
                ':tenant_id' => $tenantId,
                ':matched_template_id' => $templateId,
                ':match_score' => $score,
                ':detection_ms' => $detectionMs,
                ':result' => in_array($result, ['matched', 'unknown', 'fallback', 'failed'], true) ? $result : 'unknown',
            ]);
        } catch (Throwable $e) {
            $this->logLearningError('template_match_log', $e, ['parse_job_id' => $parseJobId, 'tenant_id' => $tenantId]);
        }
    }

    /**
     * AI解析前/解析後のレイアウト候補を保存する。
     * $countObservation=false の場合は、同一取り込み内のAI解析後プロファイル追記として扱い、match_countは増やさない。
     *
     * @param array<string,mixed> $detected
     */
    public function saveTemplateCandidate(int $parseJobId, int $tenantId, string $fingerprint, array $detected, bool $countObservation = true): void
    {
        if ($fingerprint === '') {
            return;
        }
        try {
            $matchUpdate = $countObservation ? 'match_count = match_count + 1,' : '';
            $stmt = Db::knowledge()->prepare('INSERT INTO prescription_template_candidates
                (company_uid, branch_uid, tenant_id, parse_job_id, detected_fingerprint, ai_field_map_json, human_fixed_field_map_json, match_count, status, created_at, updated_at)
                VALUES (:company_uid, :branch_uid, :tenant_id, :parse_job_id, :detected_fingerprint, :ai_field_map_json, NULL, 1, "candidate", NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    tenant_id = VALUES(tenant_id),
                    parse_job_id = VALUES(parse_job_id),
                    ai_field_map_json = VALUES(ai_field_map_json),
                    ' . $matchUpdate . '
                    updated_at = NOW()');
            $stmt->execute([
                ':company_uid' => current_company_uid(),
                ':branch_uid' => current_branch_uid(),
                ':tenant_id' => $tenantId,
                ':parse_job_id' => $parseJobId,
                ':detected_fingerprint' => $fingerprint,
                ':ai_field_map_json' => json_encode($detected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            $pdo = Db::knowledge();
            if (Db::columnExists($pdo, 'prescription_template_candidates', 'ai_layout_profile_json')) {
                $profile = is_array($detected['ai_layout_profile'] ?? null) ? $detected['ai_layout_profile'] : null;
                $update = $pdo->prepare('UPDATE prescription_template_candidates
                    SET ai_layout_profile_json = :ai_layout_profile_json,
                        last_parse_job_id = :last_parse_job_id,
                        updated_at = NOW()
                    WHERE company_uid = :company_uid
                      AND branch_uid = :branch_uid
                      AND detected_fingerprint = :detected_fingerprint
                    LIMIT 1');
                $update->execute([
                    ':ai_layout_profile_json' => $profile ? json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                    ':last_parse_job_id' => $parseJobId,
                    ':company_uid' => current_company_uid(),
                    ':branch_uid' => current_branch_uid(),
                    ':detected_fingerprint' => $fingerprint,
                ]);
            }
        } catch (Throwable $e) {
            $this->logLearningError('template_candidate_save', $e, ['parse_job_id' => $parseJobId, 'fingerprint' => $fingerprint]);
        }
    }

    /** @return array<int, array<string,mixed>> */
    public function findCorrectionRules(string $fieldType, string $value): array
    {
        if ($value === '') {
            return [];
        }
        $sql = 'SELECT * FROM prescription_auto_correction_rules
                WHERE is_active = 1
                  AND field_type = :field_type
                  AND wrong_value = :wrong_value
                  AND (scope_type = "global" OR company_uid = :company_uid OR branch_uid = :branch_uid)
                ORDER BY
                  CASE scope_type WHEN "branch" THEN 1 WHEN "company" THEN 2 ELSE 3 END,
                  precision_rate DESC, support_count DESC
                LIMIT 5';
        try {
            $stmt = Db::knowledge()->prepare($sql);
            $stmt->execute([
                ':field_type' => $fieldType,
                ':wrong_value' => $value,
                ':company_uid' => current_company_uid(),
                ':branch_uid' => current_branch_uid(),
            ]);
            return $stmt->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<int, array<string,mixed>> */
    public function findDrugCandidates(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }
        $rows = [];
        if (class_exists('DrugDictionaryService')) {
            foreach (DrugDictionaryService::findCandidates($value, 8) as $candidate) {
                $rows[] = $candidate;
            }
        }
        try {
            if (Db::tableExists(Db::knowledge(), 'drug_aliases') && Db::tableExists(Db::knowledge(), 'drug_master')) {
                $stmt = Db::knowledge()->prepare('SELECT dm.drug_name, da.alias_name, da.alias_type, 100 AS score
                    FROM drug_aliases da
                    INNER JOIN drug_master dm ON dm.id = da.drug_master_id
                    WHERE da.alias_name = :value OR dm.drug_name = :value
                    LIMIT 5');
                $stmt->execute([':value' => $value]);
                $rows = array_merge($rows, $stmt->fetchAll());
            }



            if (Db::tableExists(Db::knowledge(), 'drug_name_relation_preferences')) {
                $like = '%' . $value . '%';
                $stmt = Db::knowledge()->prepare('SELECT display_drug_name AS drug_name,
                        CONCAT_WS(" / ", NULLIF(generic_name, ""), NULLIF(brand_name, "")) AS alias_name,
                        "generic_brand_pair" AS alias_type,
                        LEAST(100, 60 + confirmed_count * 5 + observed_count) AS score
                    FROM drug_name_relation_preferences
                    WHERE company_uid = :company_uid
                      AND branch_uid = :branch_uid
                      AND (display_drug_name LIKE :like OR generic_name LIKE :like OR brand_name LIKE :like)
                    ORDER BY confirmed_count DESC, observed_count DESC, updated_at DESC
                    LIMIT 5');
                $stmt->execute([
                    ':company_uid' => current_company_uid(),
                    ':branch_uid' => current_branch_uid(),
                    ':like' => $like,
                ]);
                $rows = array_merge($rows, $stmt->fetchAll());
            }
        } catch (Throwable) {
            return $rows;
        }

        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            $key = (string)($row['yj_code'] ?? '') . '|' . (string)($row['drug_name'] ?? '') . '|' . (string)($row['alias_name'] ?? '');
            if ($key === '||' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if (!isset($row['score'])) {
                $row['score'] = 70;
            }
            $out[] = $row;
        }
        usort($out, static fn(array $a, array $b): int => ((float)($b['score'] ?? 0) <=> (float)($a['score'] ?? 0)));
        return array_slice($out, 0, 8);
    }

    public function upsertCorrectionRule(string $fieldType, string $wrongValue, string $correctValue, mixed $confidence = null): void
    {
        $wrongValue = trim($wrongValue);
        $correctValue = trim($correctValue);
        if ($wrongValue === '' || $correctValue === '' || $this->sameCorrectionText($wrongValue, $correctValue)) {
            return;
        }
        // 患者・保険など個人性/請求影響が強いものは、自動補正ルールには昇格しない。
        // raw_drug_text は補助学習用の原文で、改行差分や処方ブロック全体を拾いやすいため自動補正ルール化しない。
        if (!in_array($fieldType, ['drug_name', 'drug_generic_name', 'drug_brand_name', 'usage_text', 'medical_institution_name'], true)) {
            return;
        }
        if (!$this->isSafeAutoCorrectionRule($fieldType, $wrongValue, $correctValue)) {
            return;
        }

        $precision = $this->initialCorrectionPrecision($fieldType, $wrongValue, $correctValue, $confidence);
        $minScore = $this->initialCorrectionMinScore($precision);

        // 1回の修正だけで precision_rate=100 / active にしない。
        // 3回以上同じ修正が出た時点で、OpenAIへの補助ヒント/候補として使える状態にする。
        $sql = 'INSERT INTO prescription_auto_correction_rules
                (company_uid, branch_uid, scope_type, field_type, wrong_value, correct_value, support_count, success_count, failure_count, precision_rate, min_score, evaluation_status, is_active, created_at, updated_at)
                VALUES (:company_uid, :branch_uid, "branch", :field_type, :wrong_value, :correct_value, 1, 1, 0, :precision_rate, :min_score, "candidate", 0, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    support_count = support_count + 1,
                    success_count = success_count + 1,
                    precision_rate = LEAST(98.00, ((COALESCE(precision_rate, 0) * support_count) + VALUES(precision_rate)) / GREATEST(support_count + 1, 1) + CASE WHEN (support_count + 1) >= 3 THEN 8.00 ELSE 0.00 END),
                    min_score = GREATEST(65.00, LEAST(COALESCE(min_score, 95.00), VALUES(min_score), 95.00 - (support_count + 1) * 5.00)),
                    evaluation_status = CASE WHEN (support_count + 1) >= 3 THEN "active" ELSE "candidate" END,
                    is_active = CASE WHEN (support_count + 1) >= 3 THEN 1 ELSE 0 END,
                    updated_at = NOW()';
        try {
            $stmt = Db::knowledge()->prepare($sql);
            $stmt->execute([
                ':company_uid' => current_company_uid(),
                ':branch_uid' => current_branch_uid(),
                ':field_type' => $fieldType,
                ':wrong_value' => mb_substr($wrongValue, 0, 255),
                ':correct_value' => mb_substr($correctValue, 0, 255),
                ':precision_rate' => $precision,
                ':min_score' => $minScore,
            ]);
        } catch (Throwable) {
        }
    }

    private function sameCorrectionText(string $wrongValue, string $correctValue): bool
    {
        $normalize = static function (string $value): string {
            $value = str_replace(["\r\n", "\r"], "\n", trim($value));
            $value = preg_replace('/[ \t　]+/u', ' ', $value) ?? $value;
            return preg_replace('/\n+/u', "\n", $value) ?? $value;
        };
        return $normalize($wrongValue) === $normalize($correctValue);
    }

    private function isSafeAutoCorrectionRule(string $fieldType, string $wrongValue, string $correctValue): bool
    {
        $text = $wrongValue . "\n" . $correctValue;
        if (mb_strlen($wrongValue) > 120 || mb_strlen($correctValue) > 120) {
            return false;
        }
        if (in_array($fieldType, ['drug_name', 'drug_generic_name', 'drug_brand_name'], true)) {
            // 薬品名欄に用法や改行付き処方ブロックが混入したものは補正ルール化しない。
            if (str_contains($correctValue, "\n") || str_contains($wrongValue, "\n")) {
                return false;
            }
            if (preg_match('/(朝食後|昼食後|夕食後|毎食|就寝|起床|分\s*\d+|\d+\s*[×xX]\s*|\d+\s*日分|用法)/u', $text)) {
                return false;
            }
        }
        if ($fieldType === 'usage_text' && mb_strlen($correctValue) > 80) {
            return false;
        }
        return true;
    }

    private function initialCorrectionPrecision(string $fieldType, string $wrongValue, string $correctValue, mixed $confidence): float
    {
        $conf = is_numeric($confidence) ? $this->normalizeConfidencePercent((float)$confidence) : null;
        $score = match ($fieldType) {
            'drug_name' => 58.0,
            'drug_generic_name', 'drug_brand_name' => 54.0,
            'usage_text' => 52.0,
            'medical_institution_name' => 56.0,
            default => 50.0,
        };
        if ($conf !== null) {
            $score += ($conf - 50.0) * 0.18;
        }
        $maxLen = max(mb_strlen($wrongValue), mb_strlen($correctValue), 1);
        $distance = levenshtein(mb_substr($wrongValue, 0, 255), mb_substr($correctValue, 0, 255));
        $ratio = min(1.0, $distance / $maxLen);
        if ($ratio <= 0.20) {
            $score += 8.0;
        } elseif ($ratio >= 0.70) {
            $score -= 10.0;
        }
        return round(max(35.0, min(90.0, $score)), 2);
    }

    private function initialCorrectionMinScore(float $precision): float
    {
        return round(max(65.0, min(98.0, 102.0 - ($precision * 0.45))), 2);
    }

    /**
     * 人間が「必要/不要」を選んだ動的項目を補助学習DBへ蓄積する。
     * 患者個人値は集計しすぎると個人情報リスクがあるため、値そのものは選択された項目だけ保存する。
     * 後続では branch_field_preferences を使って、拠点ごとの初期チェック状態を育てる。
     *
     * @param array<int,array<string,mixed>> $rows
     */
    public function saveFieldObservations(?int $parseJobId, int $tenantId, int $prescriptionId, array $rows): void
    {
        if (!$rows) {
            return;
        }

        try {
            if (Db::tableExists(Db::knowledge(), 'prescription_field_observations')) {
                $stmt = Db::knowledge()->prepare('INSERT INTO prescription_field_observations
                    (company_uid, branch_uid, tenant_id, parse_job_id, prescription_id, field_key, field_label, field_group, field_value, source_ai_value, source_section, confidence, needs_human_check, is_selected, include_for_output, display_order, created_at)
                    VALUES
                    (:company_uid, :branch_uid, :tenant_id, :parse_job_id, :prescription_id, :field_key, :field_label, :field_group, :field_value, :source_ai_value, :source_section, :confidence, :needs_human_check, :is_selected, :include_for_output, :display_order, NOW())');

                foreach ($rows as $row) {
                    $risk = $this->fieldRiskLevel((string)($row['field_group'] ?? 'other'), (string)($row['field_key'] ?? ''), (string)($row['field_label'] ?? ''));
                    $stmt->execute([
                        ':company_uid' => current_company_uid(),
                        ':branch_uid' => current_branch_uid(),
                        ':tenant_id' => $tenantId,
                        ':parse_job_id' => $parseJobId,
                        ':prescription_id' => $prescriptionId,
                        ':field_key' => $row['field_key'],
                        ':field_label' => $row['field_label'],
                        ':field_group' => $row['field_group'],
                        ':field_value' => $this->learningSample((string)($row['field_value'] ?? ''), $risk),
                        ':source_ai_value' => $this->learningSample((string)($row['source_ai_value'] ?? ''), $risk),
                        ':source_section' => $row['source_section'],
                        ':confidence' => $row['confidence'],
                        ':needs_human_check' => !empty($row['needs_human_check']) ? 1 : 0,
                        ':is_selected' => !empty($row['is_selected']) ? 1 : 0,
                        ':include_for_output' => !empty($row['include_for_output']) ? 1 : 0,
                        ':display_order' => (int)$row['display_order'],
                    ]);
                }
            }

            if (Db::tableExists(Db::knowledge(), 'prescription_branch_field_preferences')) {
                $pref = Db::knowledge()->prepare('INSERT INTO prescription_branch_field_preferences
                    (company_uid, branch_uid, field_key, field_label, field_group, include_default, selected_count, unselected_count, last_value_sample, last_seen_at, created_at, updated_at)
                    VALUES
                    (:company_uid, :branch_uid, :field_key, :field_label, :field_group, :include_default, :selected_count, :unselected_count, :last_value_sample, NOW(), NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                        field_label = VALUES(field_label),
                        field_group = VALUES(field_group),
                        selected_count = selected_count + VALUES(selected_count),
                        unselected_count = unselected_count + VALUES(unselected_count),
                        include_default = CASE
                            WHEN (selected_count + VALUES(selected_count)) >= GREATEST(3, (unselected_count + VALUES(unselected_count)) * 2) THEN 1
                            WHEN (unselected_count + VALUES(unselected_count)) >= GREATEST(3, (selected_count + VALUES(selected_count)) * 2) THEN 0
                            ELSE include_default
                        END,
                        last_value_sample = VALUES(last_value_sample),
                        last_seen_at = NOW(),
                        updated_at = NOW()');

                foreach ($rows as $row) {
                    $selected = !empty($row['is_selected']);
                    $pref->execute([
                        ':company_uid' => current_company_uid(),
                        ':branch_uid' => current_branch_uid(),
                        ':field_key' => $row['field_key'],
                        ':field_label' => $row['field_label'],
                        ':field_group' => $row['field_group'],
                        ':include_default' => $selected ? 1 : 0,
                        ':selected_count' => $selected ? 1 : 0,
                        ':unselected_count' => $selected ? 0 : 1,
                        ':last_value_sample' => mb_substr($this->learningSample((string)($row['field_value'] ?? ''), $this->fieldRiskLevel((string)($row['field_group'] ?? 'other'), (string)($row['field_key'] ?? ''), (string)($row['field_label'] ?? ''))), 0, 255),
                    ]);
                }
            }

            // 使用項目選択はOCR補正学習とは別だが、拠点ごとの運用学習としてスコア化する。
            $this->saveFieldLearningScores($rows);
        } catch (Throwable) {
            // 補助学習DBへの保存失敗で処方箋確定保存を止めない。
        }
    }

    /**
     * 選択・修正結果を「重み」相当のスコアへ変換して蓄積する。
     * 実際のAIモデルの重みではなく、拠点ごとの項目採用傾向・修正傾向を数値化する。
     *
     * @param array<int,array<string,mixed>> $rows
     */
    private function saveFieldLearningScores(array $rows): void
    {
        if (!$rows || !Db::tableExists(Db::knowledge(), 'prescription_field_learning_scores')) {
            return;
        }

        try {
            $stmt = Db::knowledge()->prepare('INSERT INTO prescription_field_learning_scores
                (company_uid, branch_uid, field_key, field_label, field_group, score_total, score_count, avg_score,
                 selected_count, unselected_count, edited_count, empty_count, last_confidence, last_ai_value_sample,
                 last_final_value_sample, last_action_type, last_seen_at, created_at, updated_at)
                VALUES
                (:company_uid, :branch_uid, :field_key, :field_label, :field_group, :score, 1, :score,
                 :selected_count, :unselected_count, :edited_count, :empty_count, :last_confidence, :last_ai_value_sample,
                 :last_final_value_sample, :last_action_type, NOW(), NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    field_label = VALUES(field_label),
                    field_group = VALUES(field_group),
                    score_total = score_total + VALUES(score_total),
                    score_count = score_count + 1,
                    avg_score = score_total / GREATEST(score_count, 1),
                    selected_count = selected_count + VALUES(selected_count),
                    unselected_count = unselected_count + VALUES(unselected_count),
                    edited_count = edited_count + VALUES(edited_count),
                    empty_count = empty_count + VALUES(empty_count),
                    last_confidence = VALUES(last_confidence),
                    last_ai_value_sample = VALUES(last_ai_value_sample),
                    last_final_value_sample = VALUES(last_final_value_sample),
                    last_action_type = VALUES(last_action_type),
                    last_seen_at = NOW(),
                    updated_at = NOW()');

            foreach ($rows as $row) {
                $ai = trim((string)($row['source_ai_value'] ?? ''));
                $final = trim((string)($row['field_value'] ?? ''));
                $selected = !empty($row['is_selected']);
                $edited = $ai !== '' && $final !== '' && $ai !== $final;
                $empty = $final === '';
                $confidence = is_numeric($row['confidence'] ?? null) ? (float)$row['confidence'] : null;
                $score = $this->fieldLearningScore($selected, $edited, $empty, !empty($row['include_for_output']), !empty($row['needs_human_check']), $confidence);
                $actionType = !$selected ? 'unselected' : ($empty ? 'empty' : ($edited ? 'edited' : ($ai === '' && $final !== '' ? 'added' : 'confirmed')));

                $stmt->execute([
                    ':company_uid' => current_company_uid(),
                    ':branch_uid' => current_branch_uid(),
                    ':field_key' => $row['field_key'],
                    ':field_label' => $row['field_label'],
                    ':field_group' => $row['field_group'],
                    ':score' => $score,
                    ':selected_count' => $selected ? 1 : 0,
                    ':unselected_count' => $selected ? 0 : 1,
                    ':edited_count' => $edited ? 1 : 0,
                    ':empty_count' => $empty ? 1 : 0,
                    ':last_confidence' => $confidence,
                    ':last_ai_value_sample' => mb_substr($this->learningSample($ai, $this->fieldRiskLevel((string)($row['field_group'] ?? 'other'), (string)($row['field_key'] ?? ''), (string)($row['field_label'] ?? ''))), 0, 255),
                    ':last_final_value_sample' => mb_substr($this->learningSample($final, $this->fieldRiskLevel((string)($row['field_group'] ?? 'other'), (string)($row['field_key'] ?? ''), (string)($row['field_label'] ?? ''))), 0, 255),
                    ':last_action_type' => $actionType,
                ]);
            }
        } catch (Throwable) {
        }
    }

    private function fieldLearningScore(bool $selected, bool $edited, bool $empty, bool $includeForOutput, bool $needsHumanCheck, ?float $confidence): float
    {
        $conf = $this->normalizeConfidencePercent($confidence);
        // 使用項目選択は「OCR精度」ではなく「拠点運用」のスコア。
        // 選ばれる頻度・出力候補・空欄・修正有無を分けて評価し、全部100/80に張り付かせない。
        $score = 45.0 + (($conf ?? 50.0) - 50.0) * 0.15;
        $score += $selected ? 22.0 : -14.0;
        $score += $includeForOutput ? 10.0 : 0.0;
        $score += $edited ? 6.0 : 0.0;
        $score += $empty ? -22.0 : 0.0;
        $score += $needsHumanCheck ? -6.0 : 0.0;
        return round(max(0.0, min(100.0, $score)), 2);
    }

    /**
     * 拠点で過去に選ばれた項目の初期チェック設定を取得する。
     *
     * @return array<string,bool>
     */
    public function branchFieldPreferenceMap(): array
    {
        try {
            if (!Db::tableExists(Db::knowledge(), 'prescription_branch_field_preferences')) {
                return [];
            }
            $stmt = Db::knowledge()->prepare('SELECT field_key, include_default
                FROM prescription_branch_field_preferences
                WHERE company_uid = :company_uid AND branch_uid = :branch_uid');
            $stmt->execute([
                ':company_uid' => current_company_uid(),
                ':branch_uid' => current_branch_uid(),
            ]);
            $map = [];
            foreach ($stmt->fetchAll() as $row) {
                $map[(string)$row['field_key']] = (bool)$row['include_default'];
            }
            return $map;
        } catch (Throwable) {
            return [];
        }
    }


    /**
     * 薬品名・一般名・商品名の人間修正結果を補助学習DBへ蓄積する。
     * 患者情報とは切り離し、薬品名同士の紐づけ候補として扱う。
     *
     * @param array<int,array<string,mixed>> $rows
     */
    public function saveDrugNameLearningEvents(?int $parseJobId, int $tenantId, ?int $prescriptionId, array $rows): void
    {
        if (!$rows) {
            return;
        }

        try {
            $eventStmt = null;
            if (Db::tableExists(Db::knowledge(), 'drug_name_relation_observations')) {
                $eventStmt = Db::knowledge()->prepare('INSERT INTO drug_name_relation_observations
                    (company_uid, branch_uid, tenant_id, parse_job_id, prescription_id, medication_sort_order,
                     ai_drug_name, final_drug_name, ai_generic_name, final_generic_name, ai_brand_name, final_brand_name,
                     ai_raw_drug_text, final_raw_drug_text, relation_type, action_type, created_at)
                    VALUES
                    (:company_uid, :branch_uid, :tenant_id, :parse_job_id, :prescription_id, :medication_sort_order,
                     :ai_drug_name, :final_drug_name, :ai_generic_name, :final_generic_name, :ai_brand_name, :final_brand_name,
                     :ai_raw_drug_text, :final_raw_drug_text, :relation_type, :action_type, NOW())');
            }

            $prefStmt = null;


            if (Db::tableExists(Db::knowledge(), 'drug_name_relation_preferences')) {
                $prefStmt = Db::knowledge()->prepare('INSERT INTO drug_name_relation_preferences
                    (company_uid, branch_uid, pair_key, generic_name, brand_name, display_drug_name, raw_example,
                     observed_count, confirmed_count, edited_count, last_seen_at, created_at, updated_at)
                    VALUES
                    (:company_uid, :branch_uid, :pair_key, :generic_name, :brand_name, :display_drug_name, :raw_example,
                     1, :confirmed_count, :edited_count, NOW(), NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                        generic_name = CASE WHEN VALUES(generic_name) <> "" THEN VALUES(generic_name) ELSE generic_name END,
                        brand_name = CASE WHEN VALUES(brand_name) <> "" THEN VALUES(brand_name) ELSE brand_name END,
                        display_drug_name = CASE WHEN VALUES(display_drug_name) <> "" THEN VALUES(display_drug_name) ELSE display_drug_name END,
                        raw_example = CASE WHEN VALUES(raw_example) <> "" THEN VALUES(raw_example) ELSE raw_example END,
                        observed_count = observed_count + 1,
                        confirmed_count = confirmed_count + VALUES(confirmed_count),
                        edited_count = edited_count + VALUES(edited_count),
                        last_seen_at = NOW(),
                        updated_at = NOW()');
            }

            foreach ($rows as $row) {
                $finalDrug = trim((string)($row['final_drug_name'] ?? ''));
                $finalGeneric = trim((string)($row['final_generic_name'] ?? ''));
                $finalBrand = trim((string)($row['final_brand_name'] ?? ''));
                $finalRaw = trim((string)($row['final_raw_drug_text'] ?? ''));
                $aiDrug = trim((string)($row['ai_drug_name'] ?? ''));
                $aiGeneric = trim((string)($row['ai_generic_name'] ?? ''));
                $aiBrand = trim((string)($row['ai_brand_name'] ?? ''));
                $aiRaw = trim((string)($row['ai_raw_drug_text'] ?? ''));
                $relationType = (string)($row['relation_type'] ?? 'unknown');
                if (!in_array($relationType, ['single','generic_brand_pair','multiple_candidates','unknown'], true)) {
                    $relationType = 'unknown';
                }
                $actionType = (string)($row['action_type'] ?? 'confirmed');
                if (!in_array($actionType, ['confirmed','edited','merged','deleted','added'], true)) {
                    $actionType = 'edited';
                }

                if ($eventStmt) {
                    $eventStmt->execute([
                        ':company_uid' => current_company_uid(),
                        ':branch_uid' => current_branch_uid(),
                        ':tenant_id' => $tenantId,
                        ':parse_job_id' => $parseJobId,
                        ':prescription_id' => $prescriptionId,
                        ':medication_sort_order' => (int)($row['sort_order'] ?? 0),
                        ':ai_drug_name' => $aiDrug,
                        ':final_drug_name' => $finalDrug,
                        ':ai_generic_name' => $aiGeneric,
                        ':final_generic_name' => $finalGeneric,
                        ':ai_brand_name' => $aiBrand,
                        ':final_brand_name' => $finalBrand,
                        ':ai_raw_drug_text' => $aiRaw,
                        ':final_raw_drug_text' => $finalRaw,
                        ':relation_type' => $relationType,
                        ':action_type' => $actionType,
                    ]);
                }

                $displayDrug = $finalDrug !== '' ? $finalDrug : ($finalBrand !== '' ? $finalBrand : $finalGeneric);
                $pairKey = $this->drugPairKey($finalGeneric, $finalBrand, $displayDrug);
                if ($prefStmt && $pairKey !== '') {
                    $prefStmt->execute([
                        ':company_uid' => current_company_uid(),
                        ':branch_uid' => current_branch_uid(),
                        ':pair_key' => $pairKey,
                        ':generic_name' => $finalGeneric,
                        ':brand_name' => $finalBrand,
                        ':display_drug_name' => $displayDrug,
                        ':raw_example' => mb_substr($finalRaw, 0, 1000),
                        ':confirmed_count' => $actionType === 'confirmed' ? 1 : 0,
                        ':edited_count' => $actionType !== 'confirmed' ? 1 : 0,
                    ]);
                }

                $this->upsertDrugMasterAndAliases($displayDrug, $finalGeneric, $finalBrand, $finalRaw);
                $this->saveDrugDictionaryLearningForRow($parseJobId, $tenantId, $prescriptionId, $row);
            }
        } catch (Throwable) {
            // 補助学習DBの未反映・一時不調で処方箋確定保存を止めない。
        }
    }


    /**
     * 結果確認画面で人間が修正を確定した時点のAI値/修正後値を補助学習DBへ保存する。
     * 使用項目の選択結果とは切り離し、OCR精度改善用の訂正データとして扱う。
     * 患者名・保険番号など個人性の高い項目は、生値ではなくマスク値/傾向だけ保存する。
     *
     * @param array<int,array<string,mixed>> $rows
     */
    public function saveConfirmedCorrectionLearning(?int $parseJobId, int $tenantId, array $rows): void
    {
        if (!$rows) {
            return;
        }

        try {
            $context = $this->learningContextForParseJob($parseJobId, $tenantId);
            $eventStmt = null;
            if (Db::tableExists(Db::knowledge(), 'prescription_confirmed_correction_events')) {
                $eventStmt = Db::knowledge()->prepare('INSERT INTO prescription_confirmed_correction_events
                    (company_uid, branch_uid, tenant_id, parse_job_id, field_key, field_label, field_group,
                     source_ai_value, final_value, normalized_ai_value, normalized_final_value, correction_type,
                     correction_score, confidence, needs_human_check, sample_risk_level, prompt_hint, content_hash, created_at)
                    VALUES
                    (:company_uid, :branch_uid, :tenant_id, :parse_job_id, :field_key, :field_label, :field_group,
                     :source_ai_value, :final_value, :normalized_ai_value, :normalized_final_value, :correction_type,
                     :correction_score, :confidence, :needs_human_check, :sample_risk_level, :prompt_hint, :content_hash, NOW())
                    ON DUPLICATE KEY UPDATE
                        correction_score = VALUES(correction_score),
                        confidence = VALUES(confidence),
                        needs_human_check = VALUES(needs_human_check),
                        prompt_hint = VALUES(prompt_hint)');
            }

            $scoreStmt = null;
            if (Db::tableExists(Db::knowledge(), 'prescription_confirmed_correction_scores')) {
                $scoreStmt = Db::knowledge()->prepare('INSERT INTO prescription_confirmed_correction_scores
                    (company_uid, branch_uid, field_key, field_label, field_group, sample_risk_level,
                     observed_count, edited_count, added_count, confirmed_count, empty_count,
                     score_total, avg_score, last_ai_value_sample, last_final_value_sample, last_correction_type,
                     prompt_hint, last_seen_at, created_at, updated_at)
                    VALUES
                    (:company_uid, :branch_uid, :field_key, :field_label, :field_group, :sample_risk_level,
                     1, :edited_count, :added_count, :confirmed_count, :empty_count,
                     :score, :score, :last_ai_value_sample, :last_final_value_sample, :last_correction_type,
                     :prompt_hint, NOW(), NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                        field_label = VALUES(field_label),
                        field_group = VALUES(field_group),
                        sample_risk_level = VALUES(sample_risk_level),
                        observed_count = observed_count + 1,
                        edited_count = edited_count + VALUES(edited_count),
                        added_count = added_count + VALUES(added_count),
                        confirmed_count = confirmed_count + VALUES(confirmed_count),
                        empty_count = empty_count + VALUES(empty_count),
                        score_total = score_total + VALUES(score_total),
                        avg_score = (score_total + VALUES(score_total)) / GREATEST(observed_count + 1, 1),
                        last_ai_value_sample = VALUES(last_ai_value_sample),
                        last_final_value_sample = VALUES(last_final_value_sample),
                        last_correction_type = VALUES(last_correction_type),
                        prompt_hint = VALUES(prompt_hint),
                        last_seen_at = NOW(),
                        updated_at = NOW()');
            }

            foreach ($rows as $row) {
                $fieldKey = $this->normalizeFieldKey((string)($row['field_key'] ?? $row['key'] ?? 'field'));
                $fieldLabel = trim((string)($row['field_label'] ?? $row['label'] ?? $fieldKey));
                $fieldGroup = trim((string)($row['field_group'] ?? $row['group'] ?? 'other')) ?: 'other';
                if (!in_array($fieldGroup, ['patient','insurance','public_expense','prescription','medical_institution','medication','pharmacy','note','qr','other'], true)) {
                    $fieldGroup = 'other';
                }

                $aiRaw = trim((string)($row['source_ai_value'] ?? $row['ai_value'] ?? ''));
                $finalRaw = trim((string)($row['field_value'] ?? $row['value'] ?? ''));
                if ($aiRaw === '' && $finalRaw === '') {
                    continue;
                }

                $normalizedAi = $this->normalizeLearningValue($aiRaw);
                $normalizedFinal = $this->normalizeLearningValue($finalRaw);
                $riskLevel = $this->fieldRiskLevel($fieldGroup, $fieldKey, $fieldLabel);
                $correctionType = $this->correctionType($normalizedAi, $normalizedFinal);
                $confidence = is_numeric($row['confidence'] ?? null) ? $this->normalizeConfidencePercent((float)$row['confidence']) : null;
                $needsHumanCheck = !empty($row['needs_human_check']) || !empty($row['needs']);
                $score = $this->confirmedCorrectionScore($correctionType, $confidence, $needsHumanCheck, $fieldGroup);
                $aiSample = $this->learningSample($aiRaw, $riskLevel);
                $finalSample = $this->learningSample($finalRaw, $riskLevel);
                $promptHint = $this->correctionPromptHint($fieldLabel, $fieldGroup, $fieldKey, $aiSample, $finalSample, $correctionType, $riskLevel);
                $contentHash = sha1(current_company_uid() . '|' . current_branch_uid() . '|' . (string)$parseJobId . '|' . $fieldKey . '|' . $normalizedAi . '|' . $normalizedFinal . '|' . $correctionType);

                if ($eventStmt && $this->confirmedCorrectionEventExists($contentHash)) {
                    continue;
                }

                if ($eventStmt) {
                    $eventStmt->execute([
                        ':company_uid' => current_company_uid(),
                        ':branch_uid' => current_branch_uid(),
                        ':tenant_id' => $tenantId,
                        ':parse_job_id' => $parseJobId,
                        ':field_key' => $fieldKey,
                        ':field_label' => mb_substr($fieldLabel !== '' ? $fieldLabel : $fieldKey, 0, 160),
                        ':field_group' => $fieldGroup,
                        ':source_ai_value' => $aiSample,
                        ':final_value' => $finalSample,
                        ':normalized_ai_value' => mb_substr($normalizedAi, 0, 255),
                        ':normalized_final_value' => mb_substr($normalizedFinal, 0, 255),
                        ':correction_type' => $correctionType,
                        ':correction_score' => $score,
                        ':confidence' => $confidence,
                        ':needs_human_check' => $needsHumanCheck ? 1 : 0,
                        ':sample_risk_level' => $riskLevel,
                        ':prompt_hint' => $promptHint,
                        ':content_hash' => $contentHash,
                    ]);
                }

                if ($scoreStmt) {
                    $scoreStmt->execute([
                        ':company_uid' => current_company_uid(),
                        ':branch_uid' => current_branch_uid(),
                        ':field_key' => $fieldKey,
                        ':field_label' => mb_substr($fieldLabel !== '' ? $fieldLabel : $fieldKey, 0, 160),
                        ':field_group' => $fieldGroup,
                        ':sample_risk_level' => $riskLevel,
                        ':edited_count' => $correctionType === 'edited' ? 1 : 0,
                        ':added_count' => $correctionType === 'added' ? 1 : 0,
                        ':confirmed_count' => $correctionType === 'confirmed' ? 1 : 0,
                        ':empty_count' => $correctionType === 'emptied' ? 1 : 0,
                        ':score' => $score,
                        ':last_ai_value_sample' => mb_substr($aiSample, 0, 255),
                        ':last_final_value_sample' => mb_substr($finalSample, 0, 255),
                        ':last_correction_type' => $correctionType,
                        ':prompt_hint' => $promptHint,
                    ]);
                    $this->refreshConfirmedCorrectionRates($fieldKey);
                }

                $this->saveLayoutFieldLearningScore($context, $fieldKey, $fieldLabel, $fieldGroup, $correctionType, $confidence, $needsHumanCheck, $aiRaw, $finalRaw);
                $this->saveVisualTextLearningScore($context, $tenantId, $parseJobId, $fieldKey, $fieldLabel, $fieldGroup, (string)($row['value_type'] ?? 'unknown'), $correctionType, $confidence, $needsHumanCheck, $aiRaw, $finalRaw);

                // OCR補正ルールに昇格してよい低リスク項目だけ、次回AI解析前の候補として使う。
                if (in_array($fieldGroup, ['medication','medical_institution'], true) && $normalizedAi !== '' && $normalizedFinal !== '' && $normalizedAi !== $normalizedFinal) {
                    $this->upsertCorrectionRule($fieldKey, $aiRaw, $finalRaw, $confidence);
                }
            }
        } catch (Throwable) {
            // 補助学習DBの保存失敗で確認画面遷移を止めない。
        }
    }

    /**
     * 修正確定後の項目一覧から、拠点別レイアウトテンプレート候補をスコア化する。
     * ここでは値そのものではなく、項目構成・出現順・修正率だけを保存する。
     *
     * @param array<int,array<string,mixed>> $selectedFields
     */
    public function saveLayoutFieldLearning(?int $parseJobId, int $tenantId, array $selectedFields): void
    {
        // 既存呼び出し互換用。実体はテンプレート候補の人間確認スコア化。
        $this->saveLayoutTemplateLearning($parseJobId, $tenantId, $selectedFields);
    }

    /** @param array<int,array<string,mixed>> $selectedFields */
    public function saveLayoutTemplateLearning(?int $parseJobId, int $tenantId, array $selectedFields): void
    {
        if (!$parseJobId || !$selectedFields || !Db::tableExists(Db::knowledge(), 'prescription_template_candidates')) {
            return;
        }
        try {
            $context = $this->learningContextForParseJob($parseJobId, $tenantId);
            $fingerprint = (string)($context['layout_fingerprint'] ?? 'unknown');
            if ($fingerprint === '' || $fingerprint === 'unknown') {
                return;
            }

            $profile = $this->buildHumanLayoutProfile($selectedFields);
            if ((int)($profile['field_count'] ?? 0) <= 0) {
                return;
            }

            $metrics = $this->layoutProfileMetrics($profile);
            $score = $this->layoutTemplateStabilityScore(
                (int)($metrics['observed_count'] ?? 0),
                (int)($metrics['edited_count'] ?? 0),
                (int)($metrics['added_count'] ?? 0),
                (int)($metrics['empty_count'] ?? 0),
                (int)($metrics['confirmed_count'] ?? 0)
            );

            $pdo = Db::knowledge();
            $this->saveTemplateCandidate($parseJobId, $tenantId, $fingerprint, [
                'layout_fingerprint' => $fingerprint,
                'features' => $context,
                'human_layout_profile_pending' => true,
            ], false);

            if (!Db::columnExists($pdo, 'prescription_template_candidates', 'human_confirmed_count')) {
                // SQL未適用でも処方箋保存は止めない。ログだけ残す。
                $this->logLearningError('layout_template_learning_schema', new RuntimeException('prescription_template_candidates human_* columns are missing'), [
                    'parse_job_id' => $parseJobId,
                    'tenant_id' => $tenantId,
                    'layout_fingerprint' => $fingerprint,
                ]);
                return;
            }

            $stmt = $pdo->prepare('UPDATE prescription_template_candidates
                SET human_fixed_field_map_json = :human_fixed_field_map_json,
                    layout_profile_json = :layout_profile_json,
                    human_confirmed_count = human_confirmed_count + :confirmed_count,
                    human_edited_count = human_edited_count + :edited_count,
                    human_added_count = human_added_count + :added_count,
                    human_empty_count = human_empty_count + :empty_count,
                    correction_rate = :correction_rate,
                    stability_score = :stability_score,
                    field_count = :field_count,
                    last_parse_job_id = :last_parse_job_id,
                    updated_at = NOW()
                WHERE company_uid = :company_uid
                  AND branch_uid = :branch_uid
                  AND detected_fingerprint = :detected_fingerprint
                LIMIT 1');
            $stmt->execute([
                ':human_fixed_field_map_json' => json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':layout_profile_json' => json_encode($profile + ['score_metrics' => $metrics], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':confirmed_count' => (int)$metrics['confirmed_count'],
                ':edited_count' => (int)$metrics['edited_count'],
                ':added_count' => (int)$metrics['added_count'],
                ':empty_count' => (int)$metrics['empty_count'],
                ':correction_rate' => (float)$metrics['correction_rate'],
                ':stability_score' => $score,
                ':field_count' => (int)$profile['field_count'],
                ':last_parse_job_id' => $parseJobId,
                ':company_uid' => current_company_uid(),
                ':branch_uid' => current_branch_uid(),
                ':detected_fingerprint' => $fingerprint,
            ]);

            $this->promoteTemplateCandidateIfReady($fingerprint);
        } catch (Throwable $e) {
            $this->logLearningError('layout_template_learning', $e, ['parse_job_id' => $parseJobId, 'tenant_id' => $tenantId]);
        }
    }

    /** @param array<int,array<string,mixed>> $selectedFields */
    private function buildHumanLayoutProfile(array $selectedFields): array
    {
        $fields = [];
        $seen = [];
        foreach ($selectedFields as $idx => $row) {
            $fieldKey = $this->normalizeFieldKey((string)($row['field_key'] ?? $row['key'] ?? 'field'));
            if ($fieldKey === '') {
                continue;
            }
            $fieldGroup = trim((string)($row['field_group'] ?? $row['group'] ?? 'other')) ?: 'other';
            if (!in_array($fieldGroup, ['patient','insurance','public_expense','prescription','medical_institution','medication','pharmacy','note','qr','other'], true)) {
                $fieldGroup = 'other';
            }
            $dedupeKey = $fieldGroup . ':' . $fieldKey;
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;
            $ai = trim((string)($row['source_ai_value'] ?? ''));
            $final = trim((string)($row['field_value'] ?? ''));
            $correctionType = $this->correctionType($this->normalizeLearningValue($ai), $this->normalizeLearningValue($final));
            $fields[] = [
                'field_key' => $fieldKey,
                'field_label' => mb_substr(trim((string)($row['field_label'] ?? $fieldKey)), 0, 80),
                'field_group' => $fieldGroup,
                'source_section' => mb_substr(trim((string)($row['source_section'] ?? '')), 0, 80),
                'display_order' => is_numeric($row['display_order'] ?? null) ? (int)$row['display_order'] : $idx,
                'is_selected' => !empty($row['is_selected']),
                'include_for_output' => !empty($row['include_for_output']),
                'needs_human_check' => !empty($row['needs_human_check']),
                'correction_type' => $correctionType,
                'confidence_bucket' => $this->confidenceBucket(is_numeric($row['confidence'] ?? null) ? (float)$row['confidence'] : null),
            ];
        }
        usort($fields, static fn(array $a, array $b): int => ((int)($a['display_order'] ?? 0) <=> (int)($b['display_order'] ?? 0)));
        $sequence = array_map(static fn(array $f): string => $f['field_group'] . ':' . $f['field_key'], $fields);
        return [
            'profile_version' => 'human_field_profile_v1',
            'field_count' => count($fields),
            'field_sequence_hash' => sha1(implode('|', $sequence)),
            'fields' => $fields,
            'generated_at' => date('c'),
        ];
    }

    /** @param array<string,mixed> $profile */
    private function layoutProfileMetrics(array $profile): array
    {
        $metrics = ['observed_count' => 0, 'edited_count' => 0, 'added_count' => 0, 'empty_count' => 0, 'confirmed_count' => 0, 'correction_rate' => 0.0];
        foreach (($profile['fields'] ?? []) as $field) {
            if (!is_array($field) || empty($field['is_selected'])) {
                continue;
            }
            $metrics['observed_count']++;
            $type = (string)($field['correction_type'] ?? 'confirmed');
            if ($type === 'edited') {
                $metrics['edited_count']++;
            } elseif ($type === 'added') {
                $metrics['added_count']++;
            } elseif ($type === 'emptied') {
                $metrics['empty_count']++;
            } else {
                $metrics['confirmed_count']++;
            }
        }
        $bad = $metrics['edited_count'] + $metrics['added_count'] + $metrics['empty_count'];
        $metrics['correction_rate'] = $metrics['observed_count'] > 0 ? round(($bad / $metrics['observed_count']) * 100, 2) : 0.0;
        return $metrics;
    }

    private function layoutTemplateStabilityScore(int $observed, int $edited, int $added, int $empty, int $confirmed): float
    {
        if ($observed <= 0) {
            return 0.0;
        }
        $bad = $edited + $added + $empty;
        $correctionRate = ($bad / max(1, $observed)) * 100;
        $confirmedRate = ($confirmed / max(1, $observed)) * 100;
        // これはAIの読取信頼度ではなく、テンプレートとして次回補助に使う安定度。
        $score = 18.0;
        $score += min(32.0, $observed * 2.0);
        $score += min(30.0, $confirmedRate * 0.30);
        $score -= min(35.0, $correctionRate * 0.45);
        if ($observed >= 8 && $correctionRate <= 35.0) {
            $score += 10.0;
        }
        return round(max(0.0, min(95.0, $score)), 2);
    }

    private function promoteTemplateCandidateIfReady(string $fingerprint): void
    {
        try {
            $pdo = Db::knowledge();
            if (!Db::columnExists($pdo, 'prescription_template_candidates', 'human_confirmed_count')) {
                return;
            }
            $stmt = $pdo->prepare('SELECT * FROM prescription_template_candidates
                WHERE company_uid = :company_uid
                  AND branch_uid = :branch_uid
                  AND detected_fingerprint = :detected_fingerprint
                  AND status IN ("candidate", "approved")
                LIMIT 1');
            $stmt->execute([
                ':company_uid' => current_company_uid(),
                ':branch_uid' => current_branch_uid(),
                ':detected_fingerprint' => $fingerprint,
            ]);
            $candidate = $stmt->fetch();
            if (!$candidate) {
                return;
            }

            $humanTotal = (int)($candidate['human_confirmed_count'] ?? 0) + (int)($candidate['human_edited_count'] ?? 0) + (int)($candidate['human_added_count'] ?? 0) + (int)($candidate['human_empty_count'] ?? 0);
            $score = is_numeric($candidate['stability_score'] ?? null) ? (float)$candidate['stability_score'] : 0.0;
            $correctionRate = is_numeric($candidate['correction_rate'] ?? null) ? (float)$candidate['correction_rate'] : 100.0;
            $fieldCount = (int)($candidate['field_count'] ?? 0);

            // 本番運用向け: 1回だけの読み取りでは昇格しない。最低3回相当の人間確認を要求する。
            if ($humanTotal < 3 || $fieldCount < 5 || $score < 55.0 || $correctionRate > 75.0) {
                return;
            }

            $templateKey = 'branch_' . preg_replace('/[^a-zA-Z0-9_]+/', '_', current_branch_uid()) . '_fp_' . substr($fingerprint, 0, 12);
            $profile = json_decode((string)($candidate['layout_profile_json'] ?? $candidate['human_fixed_field_map_json'] ?? '{}'), true) ?: [];
            $fieldMap = [
                'template_source' => 'auto_promoted_from_human_review',
                'layout_fingerprint' => $fingerprint,
                'template_score' => $score,
                'correction_rate' => $correctionRate,
                'sample_count' => $humanTotal,
                'profile' => $profile,
            ];

            $insert = $pdo->prepare('INSERT INTO prescription_templates
                (company_uid, branch_uid, tenant_id, scope_type, medical_institution_key, template_key, display_name, version_label,
                 paper_orientation, layout_fingerprint, field_map_json, match_threshold, success_count, failure_count,
                 avg_correction_rate, is_active, created_at, updated_at)
                VALUES
                (:company_uid, :branch_uid, :tenant_id, "branch", NULL, :template_key, :display_name, "v1",
                 "unknown", :layout_fingerprint, :field_map_json, 65.00, :success_count, 0,
                 :avg_correction_rate, 1, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    field_map_json = VALUES(field_map_json),
                    success_count = GREATEST(success_count, VALUES(success_count)),
                    avg_correction_rate = VALUES(avg_correction_rate),
                    is_active = 1,
                    updated_at = NOW(),
                    id = LAST_INSERT_ID(id)');
            $insert->execute([
                ':company_uid' => current_company_uid(),
                ':branch_uid' => current_branch_uid(),
                ':tenant_id' => $candidate['tenant_id'] ?? null,
                ':template_key' => $templateKey,
                ':display_name' => '拠点別処方箋テンプレート ' . substr($fingerprint, 0, 8),
                ':layout_fingerprint' => $fingerprint,
                ':field_map_json' => json_encode($fieldMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':success_count' => $humanTotal,
                ':avg_correction_rate' => $correctionRate,
            ]);
            $templateId = (int)$pdo->lastInsertId();

            if ($templateId > 0 && Db::columnExists($pdo, 'prescription_templates', 'template_score')) {
                $extra = $pdo->prepare('UPDATE prescription_templates
                    SET source_candidate_id = :source_candidate_id,
                        approval_mode = "auto",
                        template_score = :template_score,
                        sample_count = :sample_count,
                        last_seen_at = NOW()
                    WHERE id = :id');
                $extra->execute([
                    ':source_candidate_id' => (int)$candidate['id'],
                    ':template_score' => $score,
                    ':sample_count' => $humanTotal,
                    ':id' => $templateId,
                ]);
            }

            $candidateSql = 'UPDATE prescription_template_candidates
                SET status = "approved", approved_template_id = :approved_template_id, updated_at = NOW()';
            if (Db::columnExists($pdo, 'prescription_template_candidates', 'approved_at')) {
                $candidateSql .= ', approved_at = COALESCE(approved_at, NOW())';
            }
            $candidateSql .= ' WHERE id = :id';
            $update = $pdo->prepare($candidateSql);
            $update->execute([':approved_template_id' => $templateId ?: null, ':id' => (int)$candidate['id']]);
        } catch (Throwable $e) {
            $this->logLearningError('template_candidate_promote', $e, ['fingerprint' => $fingerprint]);
        }
    }


    /**
     * OCR処理の各段階を補助学習DBへ保存する。
     * 患者情報を含むため公開ファイルには出さず、補助学習DBの trace テーブルで管理する。
     *
     * @param array<string,mixed>|array<int,mixed> $payload
     * @param array<string,mixed> $meta
     */
    public function savePipelineTrace(int $tenantId, int $parseJobId, ?int $prescriptionId, string $stage, string $sourceKind, array $payload, array $meta = []): void
    {
        if (!Db::tableExists(Db::knowledge(), 'prescription_ocr_pipeline_traces')) {
            return;
        }
        try {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($json) || $json === '') {
                $json = '{}';
            }
            $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $stmt = Db::knowledge()->prepare('INSERT INTO prescription_ocr_pipeline_traces
                (company_uid, branch_uid, tenant_id, parse_job_id, prescription_id, stage, source_kind, model_name,
                 layout_fingerprint, quality_bucket, payload_hash, payload_bytes, payload_json, meta_json, created_at)
                VALUES
                (:company_uid, :branch_uid, :tenant_id, :parse_job_id, :prescription_id, :stage, :source_kind, :model_name,
                 :layout_fingerprint, :quality_bucket, :payload_hash, :payload_bytes, :payload_json, :meta_json, NOW())');
            $stmt->execute([
                ':company_uid' => current_company_uid(),
                ':branch_uid' => current_branch_uid(),
                ':tenant_id' => $tenantId,
                ':parse_job_id' => $parseJobId,
                ':prescription_id' => $prescriptionId,
                ':stage' => mb_substr($stage, 0, 64),
                ':source_kind' => in_array($sourceKind, ['read','write'], true) ? $sourceKind : 'read',
                ':model_name' => mb_substr((string)($meta['model_name'] ?? app_config('openai.model', '')), 0, 120),
                ':layout_fingerprint' => mb_substr((string)($meta['layout_fingerprint'] ?? ''), 0, 191) ?: null,
                ':quality_bucket' => mb_substr((string)($meta['quality_bucket'] ?? ''), 0, 64) ?: null,
                ':payload_hash' => hash('sha256', $json),
                ':payload_bytes' => strlen($json),
                ':payload_json' => $json,
                ':meta_json' => is_string($metaJson) && $metaJson !== '' ? $metaJson : null,
            ]);
        } catch (Throwable $e) {
            $this->logLearningError('pipeline_trace_save', $e, [
                'parse_job_id' => $parseJobId,
                'stage' => $stage,
            ]);
        }
    }

    private function confidenceBucket(?float $confidence): string
    {
        $confidence = $this->normalizeConfidencePercent($confidence);
        if ($confidence === null) {
            return 'unknown';
        }
        if ($confidence < 25) {
            return '<25';
        }
        if ($confidence < 50) {
            return '25-49';
        }
        if ($confidence < 75) {
            return '50-74';
        }
        return '75-100';
    }

    /**
     * 補助学習DBに蓄積された人間修正傾向をOpenAIの読み取りプロンプトへ渡す短いヒントにする。
     */
    public function buildOpenAiLearningHints(string $layoutFingerprint = '', int $limit = 18): string
    {
        $hints = [];
        if (class_exists('DrugDictionaryService')) {
            $hints[] = '- 辞書方針: ' . DrugDictionaryService::promptPolicyText();
        }
        try {
            if (Db::tableExists(Db::knowledge(), 'prescription_confirmed_correction_scores')) {
                $hasUseForPrompt = Db::columnExists(Db::knowledge(), 'prescription_confirmed_correction_scores', 'use_for_prompt');
                $hasPromptWeight = Db::columnExists(Db::knowledge(), 'prescription_confirmed_correction_scores', 'prompt_weight');
                $where = $hasUseForPrompt
                    ? 'AND use_for_prompt = 1'
                    : 'AND observed_count >= 3 AND (edited_count + added_count + empty_count) >= 2';
                $order = $hasPromptWeight
                    ? 'prompt_weight DESC, edited_count DESC, observed_count DESC, last_seen_at DESC'
                    : 'edited_count DESC, avg_score DESC, observed_count DESC, last_seen_at DESC';
                $stmt = Db::knowledge()->prepare('SELECT field_label, field_group, last_correction_type, prompt_hint, avg_score, edited_count, added_count, empty_count, observed_count
                    FROM prescription_confirmed_correction_scores
                    WHERE company_uid = :company_uid
                      AND branch_uid = :branch_uid
                      AND prompt_hint IS NOT NULL
                      AND prompt_hint <> ""
                      ' . $where . '
                    ORDER BY ' . $order . '
                    LIMIT :limit');
                $stmt->bindValue(':company_uid', current_company_uid());
                $stmt->bindValue(':branch_uid', current_branch_uid());
                $stmt->bindValue(':limit', max(1, min(50, $limit)), PDO::PARAM_INT);
                $stmt->execute();
                foreach ($stmt->fetchAll() as $row) {
                    $hints[] = '- ' . (string)$row['prompt_hint'];
                }
            }

            if (Db::tableExists(Db::knowledge(), 'prescription_layout_field_learning_scores')) {
                $stmt = Db::knowledge()->prepare('SELECT field_label, field_group, correction_rate, miss_rate, overdetect_rate, observed_count
                    FROM prescription_layout_field_learning_scores
                    WHERE company_uid = :company_uid
                      AND branch_uid = :branch_uid
                      AND observed_count >= 3
                    ORDER BY correction_rate DESC, miss_rate DESC, overdetect_rate DESC, observed_count DESC, last_seen_at DESC
                    LIMIT 6');
                $stmt->execute([
                    ':company_uid' => current_company_uid(),
                    ':branch_uid' => current_branch_uid(),
                ]);
                foreach ($stmt->fetchAll() as $row) {
                    $label = (string)($row['field_label'] ?? '項目');
                    $hints[] = '- レイアウト傾向: ' . $label . ' はこの拠点/帳票で修正率 ' . round((float)($row['correction_rate'] ?? 0), 1) . '%。小さい文字・行分割・空欄/過検出を確認する。';
                }
            }

            if (Db::tableExists(Db::knowledge(), 'prescription_image_quality_learning_scores')) {
                $stmt = Db::knowledge()->prepare('SELECT quality_bucket, issue_flags, correction_rate, observed_count
                    FROM prescription_image_quality_learning_scores
                    WHERE company_uid = :company_uid
                      AND branch_uid = :branch_uid
                      AND observed_count >= 3
                    ORDER BY correction_rate DESC, observed_count DESC, last_seen_at DESC
                    LIMIT 4');
                $stmt->execute([
                    ':company_uid' => current_company_uid(),
                    ':branch_uid' => current_branch_uid(),
                ]);
                foreach ($stmt->fetchAll() as $row) {
                    $hints[] = '- 画像品質傾向: ' . (string)($row['quality_bucket'] ?? 'unknown') . ' / ' . (string)($row['issue_flags'] ?? '') . ' では誤読が増えやすい。日付・数量・保険番号・薬品行の分離を慎重に行う。';
                }
            }

            if (Db::tableExists(Db::knowledge(), 'prescription_visual_text_learning_scores')) {
                $sql = 'SELECT field_label, field_group, value_type, text_style, quality_bucket, blur_bucket, brightness_bucket, contrast_bucket, correction_rate, miss_rate, overdetect_rate, observed_count
                    FROM prescription_visual_text_learning_scores
                    WHERE company_uid = :company_uid
                      AND branch_uid = :branch_uid
                      AND observed_count >= 3';
                $params = [':company_uid' => current_company_uid(), ':branch_uid' => current_branch_uid()];
                if ($layoutFingerprint !== '' && Db::columnExists(Db::knowledge(), 'prescription_visual_text_learning_scores', 'layout_fingerprint')) {
                    $sql .= ' AND (layout_fingerprint = :layout_fingerprint OR layout_fingerprint = "unknown")';
                    $params[':layout_fingerprint'] = $layoutFingerprint;
                }
                $sql .= ' ORDER BY correction_rate DESC, miss_rate DESC, overdetect_rate DESC, observed_count DESC, last_seen_at DESC LIMIT 6';
                $stmt = Db::knowledge()->prepare($sql);
                $stmt->execute($params);
                foreach ($stmt->fetchAll() as $row) {
                    $label = (string)($row['field_label'] ?? '文字項目');
                    $hints[] = '- 文字品質傾向: ' . $label . ' は ' . (string)($row['text_style'] ?? 'unknown') . ' / ' . (string)($row['quality_bucket'] ?? 'unknown') . ' / ぼけ=' . (string)($row['blur_bucket'] ?? 'unknown') . ' で修正率 ' . round((float)($row['correction_rate'] ?? 0), 1) . '%。手書き・小さい文字・にじみ・薄い印字の場合は候補として残し要確認にする。';
                }
            }

            if (Db::tableExists(Db::knowledge(), 'drug_name_relation_preferences')) {
                $stmt = Db::knowledge()->prepare('SELECT generic_name, brand_name, display_drug_name, confirmed_count, edited_count
                    FROM drug_name_relation_preferences
                    WHERE company_uid = :company_uid
                      AND branch_uid = :branch_uid
                    ORDER BY confirmed_count DESC, edited_count DESC, last_seen_at DESC
                    LIMIT 8');
                $stmt->execute([
                    ':company_uid' => current_company_uid(),
                    ':branch_uid' => current_branch_uid(),
                ]);
                foreach ($stmt->fetchAll() as $row) {
                    $generic = trim((string)($row['generic_name'] ?? ''));
                    $brand = trim((string)($row['brand_name'] ?? ''));
                    $display = trim((string)($row['display_drug_name'] ?? ''));
                    if ($generic !== '' || $brand !== '' || $display !== '') {
                        $hints[] = '- 薬品名: 一般名「' . $generic . '」と商品名「' . $brand . '」は、同一処方ブロックでは代表名「' . $display . '」にまとめる候補として扱う。';
                    }
                }
            }
        } catch (Throwable) {
            return '';
        }

        if (!$hints) {
            return '';
        }
        return "
過去の人間修正から得た補助学習ヒント（自動確定せず、読み取り時の注意として使う）:
" . implode("
", array_slice(array_values(array_unique($hints)), 0, $limit + 12));
    }

    /**
     * 撮影画像の品質を、後続の誤読率分析に使えるように補助学習DBへ保存する。
     * 画像そのものは保存せず、解像度・比率・サイズ・品質バケットだけを保存する。
     * @param array<string,mixed> $stored
     * @param array<string,mixed> $layoutMeta
     */
    public function saveImageQualityLearning(?int $parseJobId, int $tenantId, array $stored, array $layoutMeta = []): void
    {
        if (!Db::tableExists(Db::knowledge(), 'prescription_image_quality_learning_scores')) {
            return;
        }
        try {
            $width = (int)($stored['width'] ?? 0);
            $height = (int)($stored['height'] ?? 0);
            $size = (int)($stored['size'] ?? $stored['file_size_bytes'] ?? 0);
            $bucket = $this->imageQualityBucket($width, $height, $size);
            $flags = $this->imageQualityIssueFlags($width, $height, $size);
            $layoutFingerprint = (string)($layoutMeta['layout_fingerprint'] ?? 'unknown');
            $stmt = Db::knowledge()->prepare('INSERT INTO prescription_image_quality_learning_scores
                (company_uid, branch_uid, quality_bucket, issue_flags, layout_fingerprint, observed_count, correction_count, correction_rate, last_width, last_height, last_file_size_bytes, last_parse_job_id, last_seen_at, created_at, updated_at)
                VALUES
                (:company_uid, :branch_uid, :quality_bucket, :issue_flags, :layout_fingerprint, 1, 0, 0.00, :last_width, :last_height, :last_file_size_bytes, :last_parse_job_id, NOW(), NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    observed_count = observed_count + 1,
                    correction_rate = (correction_count / GREATEST(observed_count + 1, 1)) * 100,
                    layout_fingerprint = VALUES(layout_fingerprint),
                    last_width = VALUES(last_width),
                    last_height = VALUES(last_height),
                    last_file_size_bytes = VALUES(last_file_size_bytes),
                    last_parse_job_id = VALUES(last_parse_job_id),
                    last_seen_at = NOW(),
                    updated_at = NOW()');
            $stmt->execute([
                ':company_uid' => current_company_uid(),
                ':branch_uid' => current_branch_uid(),
                ':quality_bucket' => $bucket,
                ':issue_flags' => $flags,
                ':layout_fingerprint' => $layoutFingerprint,
                ':last_width' => $width ?: null,
                ':last_height' => $height ?: null,
                ':last_file_size_bytes' => $size ?: null,
                ':last_parse_job_id' => $parseJobId,
            ]);
            $this->updateImageQualityDetailIfSupported($parseJobId, $stored, $layoutMeta);
        } catch (Throwable) {
        }
    }

    /** @return array<string,mixed> */
    private function learningContextForParseJob(?int $parseJobId, int $tenantId): array
    {
        $context = [
            'layout_fingerprint' => 'unknown',
            'quality_bucket' => 'unknown',
            'quality_issue_flags' => '',
        ];
        if (!$parseJobId) {
            return $context;
        }
        try {
            $stmt = Db::branch()->prepare('SELECT q.width, q.height, q.file_size_bytes, q.paper_detect_score, j.source_type
                FROM prescription_parse_jobs j
                LEFT JOIN prescription_capture_quality q ON q.parse_job_id = j.id
                WHERE j.tenant_id = :tenant_id AND j.id = :id
                ORDER BY q.id DESC
                LIMIT 1');
            $stmt->execute([':tenant_id' => $tenantId, ':id' => $parseJobId]);
            $row = $stmt->fetch() ?: [];
            $width = (int)($row['width'] ?? 0);
            $height = (int)($row['height'] ?? 0);
            $size = (int)($row['file_size_bytes'] ?? 0);
            $features = (new PrescriptionTemplateDetector())->featuresFromDimensions($width, $height, '', $size);
            $context['layout_fingerprint'] = hash('sha256', json_encode($features, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $context['quality_bucket'] = $this->imageQualityBucket($width, $height, $size);
            $context['quality_issue_flags'] = $this->imageQualityIssueFlags($width, $height, $size);
            try {
                if (Db::tableExists(Db::knowledge(), 'prescription_image_quality_learning_scores')) {
                    $q = Db::knowledge()->prepare('SELECT quality_bucket, issue_flags, brightness_bucket, contrast_bucket, blur_bucket, ink_bleed_risk, estimated_text_size_bucket
                        FROM prescription_image_quality_learning_scores
                        WHERE company_uid = :company_uid AND branch_uid = :branch_uid AND last_parse_job_id = :parse_job_id
                        ORDER BY last_seen_at DESC LIMIT 1');
                    $q->execute([':company_uid' => current_company_uid(), ':branch_uid' => current_branch_uid(), ':parse_job_id' => $parseJobId]);
                    $qr = $q->fetch() ?: [];
                    if ($qr) {
                        $context['quality_bucket'] = (string)($qr['quality_bucket'] ?? $context['quality_bucket']);
                        $flags = array_filter([
                            (string)($qr['issue_flags'] ?? ''),
                            (string)($qr['brightness_bucket'] ?? ''),
                            (string)($qr['contrast_bucket'] ?? ''),
                            (string)($qr['blur_bucket'] ?? ''),
                            (string)($qr['ink_bleed_risk'] ?? ''),
                            (string)($qr['estimated_text_size_bucket'] ?? ''),
                        ], static fn($v) => $v !== '' && $v !== 'unknown' && $v !== 'normal');
                        if ($flags) {
                            $context['quality_issue_flags'] = implode(',', array_unique($flags));
                        }
                    }
                }
            } catch (Throwable) {
            }
        } catch (Throwable) {
        }
        return $context;
    }

    private function saveLayoutFieldLearningScore(array $context, string $fieldKey, string $fieldLabel, string $fieldGroup, string $correctionType, ?float $confidence, bool $needsHumanCheck, string $aiRaw, string $finalRaw): void
    {
        if (!Db::tableExists(Db::knowledge(), 'prescription_layout_field_learning_scores')) {
            return;
        }
        try {
            $layoutFingerprint = (string)($context['layout_fingerprint'] ?? 'unknown');
            $qualityBucket = (string)($context['quality_bucket'] ?? 'unknown');
            $edited = $correctionType === 'edited' ? 1 : 0;
            $added = $correctionType === 'added' ? 1 : 0;
            $emptied = $correctionType === 'emptied' ? 1 : 0;
            $confirmed = $correctionType === 'confirmed' ? 1 : 0;
            $score = $this->confirmedCorrectionScore($correctionType, $confidence, $needsHumanCheck, $fieldGroup);
            $stmt = Db::knowledge()->prepare('INSERT INTO prescription_layout_field_learning_scores
                (company_uid, branch_uid, layout_fingerprint, quality_bucket, field_key, field_label, field_group,
                 observed_count, edited_count, added_count, empty_count, confirmed_count, score_total, avg_score,
                 correction_rate, miss_rate, overdetect_rate, last_ai_value_sample, last_final_value_sample, last_seen_at, created_at, updated_at)
                VALUES
                (:company_uid, :branch_uid, :layout_fingerprint, :quality_bucket, :field_key, :field_label, :field_group,
                 1, :edited_count, :added_count, :empty_count, :confirmed_count, :score, :score,
                 :correction_rate, :miss_rate, :overdetect_rate, :last_ai_value_sample, :last_final_value_sample, NOW(), NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    field_label = VALUES(field_label),
                    field_group = VALUES(field_group),
                    observed_count = observed_count + 1,
                    edited_count = edited_count + VALUES(edited_count),
                    added_count = added_count + VALUES(added_count),
                    empty_count = empty_count + VALUES(empty_count),
                    confirmed_count = confirmed_count + VALUES(confirmed_count),
                    score_total = score_total + VALUES(score_total),
                    avg_score = (score_total + VALUES(score_total)) / GREATEST(observed_count + 1, 1),
                    correction_rate = ((edited_count + VALUES(edited_count) + added_count + VALUES(added_count) + empty_count + VALUES(empty_count)) / GREATEST(observed_count + 1, 1)) * 100,
                    miss_rate = ((added_count + VALUES(added_count)) / GREATEST(observed_count + 1, 1)) * 100,
                    overdetect_rate = ((empty_count + VALUES(empty_count)) / GREATEST(observed_count + 1, 1)) * 100,
                    last_ai_value_sample = VALUES(last_ai_value_sample),
                    last_final_value_sample = VALUES(last_final_value_sample),
                    last_seen_at = NOW(),
                    updated_at = NOW()');
            $stmt->execute([
                ':company_uid' => current_company_uid(),
                ':branch_uid' => current_branch_uid(),
                ':layout_fingerprint' => $layoutFingerprint,
                ':quality_bucket' => $qualityBucket,
                ':field_key' => $fieldKey,
                ':field_label' => mb_substr($fieldLabel !== '' ? $fieldLabel : $fieldKey, 0, 160),
                ':field_group' => $fieldGroup,
                ':edited_count' => $edited,
                ':added_count' => $added,
                ':empty_count' => $emptied,
                ':confirmed_count' => $confirmed,
                ':score' => $score,
                ':correction_rate' => ($edited + $added + $emptied) ? 100.0 : 0.0,
                ':miss_rate' => $added ? 100.0 : 0.0,
                ':overdetect_rate' => $emptied ? 100.0 : 0.0,
                ':last_ai_value_sample' => mb_substr($this->learningSample($aiRaw, $this->fieldRiskLevel($fieldGroup, $fieldKey, $fieldLabel)), 0, 255),
                ':last_final_value_sample' => mb_substr($this->learningSample($finalRaw, $this->fieldRiskLevel($fieldGroup, $fieldKey, $fieldLabel)), 0, 255),
            ]);
        } catch (Throwable $e) {
            $this->logLearningError('layout_field_learning_score', $e, [
                'field_key' => $fieldKey,
                'field_group' => $fieldGroup,
                'layout_fingerprint' => (string)($context['layout_fingerprint'] ?? 'unknown'),
            ]);
        }
    }

    private function refreshConfirmedCorrectionRates(string $fieldKey): void
    {
        try {
            if (!Db::tableExists(Db::knowledge(), 'prescription_confirmed_correction_scores')) {
                return;
            }
            $pdo = Db::knowledge();
            foreach (['accuracy_rate','correction_rate','miss_rate','overdetect_rate','prompt_weight','use_for_prompt'] as $column) {
                if (!Db::columnExists($pdo, 'prescription_confirmed_correction_scores', $column)) {
                    return;
                }
            }
            $stmt = $pdo->prepare('UPDATE prescription_confirmed_correction_scores
                SET accuracy_rate = (confirmed_count / GREATEST(observed_count, 1)) * 100,
                    correction_rate = ((edited_count + added_count + empty_count) / GREATEST(observed_count, 1)) * 100,
                    miss_rate = (added_count / GREATEST(observed_count, 1)) * 100,
                    overdetect_rate = (empty_count / GREATEST(observed_count, 1)) * 100,
                    prompt_weight = CASE
                        WHEN observed_count < 3 THEN 0
                        ELSE LEAST(100, ((edited_count + added_count + empty_count) / GREATEST(observed_count, 1)) * 70 + LEAST(30, observed_count * 3))
                    END,
                    use_for_prompt = CASE
                        WHEN observed_count >= 3 AND (edited_count + added_count + empty_count) >= 2 THEN 1
                        ELSE 0
                    END
                WHERE company_uid = :company_uid AND branch_uid = :branch_uid AND field_key = :field_key');
            $stmt->execute([
                ':company_uid' => current_company_uid(),
                ':branch_uid' => current_branch_uid(),
                ':field_key' => $fieldKey,
            ]);
        } catch (Throwable) {
        }
    }

    private function imageQualityBucket(int $width, int $height, int $fileSizeBytes): string
    {
        $long = max($width, $height);
        $short = min($width, $height);
        if ($long <= 0 || $short <= 0) {
            return 'unknown';
        }
        $ratio = round($long / max(1, $short), 2);
        $res = $long < 1400 ? 'low_res' : ($long < 2400 ? 'mid_res' : 'high_res');
        $shape = ($ratio >= 1.30 && $ratio <= 1.55) ? 'a4_like' : ($ratio < 1.30 ? 'square_like' : 'long_or_cropped');
        $size = $fileSizeBytes > 5 * 1024 * 1024 ? 'large_file' : ($fileSizeBytes < 250 * 1024 ? 'small_file' : 'normal_file');
        return $res . ':' . $shape . ':' . $size;
    }

    private function imageQualityIssueFlags(int $width, int $height, int $fileSizeBytes): string
    {
        $flags = [];
        $long = max($width, $height);
        $short = min($width, $height);
        if ($long > 0 && $long < 1400) {
            $flags[] = 'low_resolution';
        }
        if ($long > 0 && $short > 0) {
            $ratio = $long / max(1, $short);
            if ($ratio < 1.25 || $ratio > 1.65) {
                $flags[] = 'non_a4_ratio_or_cropped';
            }
        }
        if ($fileSizeBytes > 5 * 1024 * 1024) {
            $flags[] = 'too_large';
        }
        return implode(',', $flags);
    }

    private function normalizeConfidencePercent(?float $confidence): ?float
    {
        if ($confidence === null) {
            return null;
        }
        // OpenAI側が 0.85 のような0-1系で返す場合と、85のような%系で返す場合を両方吸収する。
        if ($confidence >= 0.0 && $confidence <= 1.0) {
            return round($confidence * 100.0, 2);
        }
        return round(max(0.0, min(100.0, $confidence)), 2);
    }

    private function confirmedCorrectionEventExists(string $contentHash): bool
    {
        if (!Db::tableExists(Db::knowledge(), 'prescription_confirmed_correction_events')) {
            return false;
        }
        try {
            $stmt = Db::knowledge()->prepare('SELECT id FROM prescription_confirmed_correction_events WHERE content_hash = :content_hash LIMIT 1');
            $stmt->execute([':content_hash' => $contentHash]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    private function normalizeFieldKey(string $key): string
    {
        return preg_replace('/[^a-zA-Z0-9_.-]+/', '_', trim($key)) ?: 'field';
    }

    private function normalizeLearningValue(string $value): string
    {
        $value = mb_convert_kana(trim($value), 'asKV', 'UTF-8');
        $value = preg_replace('/\s+/u', '', $value) ?? $value;
        return mb_strtolower($value);
    }

    private function correctionType(string $normalizedAi, string $normalizedFinal): string
    {
        if ($normalizedAi === '' && $normalizedFinal !== '') {
            return 'added';
        }
        if ($normalizedAi !== '' && $normalizedFinal === '') {
            return 'emptied';
        }
        if ($normalizedAi === $normalizedFinal) {
            return 'confirmed';
        }
        return 'edited';
    }

    private function fieldRiskLevel(string $fieldGroup, string $fieldKey, string $fieldLabel): string
    {
        $text = $fieldGroup . ' ' . $fieldKey . ' ' . $fieldLabel;
        foreach (['patient', 'insurance', 'public_expense'] as $group) {
            if ($fieldGroup === $group) {
                return 'high';
            }
        }
        foreach (['患者', '氏名', 'フリガナ', '生年月日', '保険者番号', '記号', '番号', '公費'] as $word) {
            if (str_contains($text, $word)) {
                return 'high';
            }
        }
        return in_array($fieldGroup, ['medication','medical_institution','prescription'], true) ? 'medium' : 'low';
    }

    private function learningSample(string $value, string $riskLevel): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if ($riskLevel === 'high') {
            return '[masked len=' . mb_strlen($value) . ' hash=' . substr(sha1($value), 0, 10) . ']';
        }
        return mb_substr($value, 0, 1000);
    }

    private function confirmedCorrectionScore(string $correctionType, ?float $confidence, bool $needsHumanCheck, string $fieldGroup): float
    {
        $conf = $this->normalizeConfidencePercent($confidence);
        // ここは「正しさ」ではなく「次回補助に使う価値」のスコア。
        // confirmed は精度が高い証拠、edited/added/emptied は改善すべき誤り傾向として評価する。
        $score = match ($correctionType) {
            'edited' => 72.0,
            'added' => 66.0,
            'emptied' => 58.0,
            'confirmed' => 38.0,
            default => 45.0,
        };
        if ($conf !== null) {
            $score += ($conf - 50.0) * 0.18;
        }
        $score += $needsHumanCheck ? 8.0 : 0.0;
        $score += in_array($fieldGroup, ['medication','medical_institution'], true) ? 8.0 : 0.0;
        return round(max(0.0, min(100.0, $score)), 2);
    }

    private function correctionPromptHint(string $fieldLabel, string $fieldGroup, string $fieldKey, string $aiSample, string $finalSample, string $correctionType, string $riskLevel): string
    {
        $label = $fieldLabel !== '' ? $fieldLabel : $fieldKey;
        if ($riskLevel === 'high') {
            return $label . 'は個人情報のため値を推測せず、桁数・位置・空欄判定を重視する。過去に人間修正が発生しているため要確認。';
        }
        return match ($correctionType) {
            'edited' => $label . 'はAI値「' . $aiSample . '」が人間確認で「' . $finalSample . '」へ修正された傾向がある。類似表記では候補として注意する。',
            'added' => $label . 'はAIが見落としやすく、人間が「' . $finalSample . '」を追加した実績がある。帳票上の空欄・小さい文字も確認する。',
            'emptied' => $label . 'はAIが過検出しやすい。値が不明な場合は空欄で返す。',
            default => $label . 'は人間確認でAI値がそのまま確認された実績がある。',
        };
    }

    private function drugPairKey(string $genericName, string $brandName, string $displayDrugName): string
    {
        $parts = array_map([$this, 'normalizeDrugKeyPart'], [$genericName, $brandName, $displayDrugName]);
        $joined = implode('|', array_filter($parts, static fn($v) => $v !== ''));
        return $joined === '' ? '' : sha1($joined);
    }

    private function normalizeDrugKeyPart(string $value): string
    {
        $value = mb_convert_kana(trim($value), 'asKV', 'UTF-8');
        $value = mb_strtolower($value);
        $value = preg_replace('/\s+/u', '', $value) ?? $value;
        $value = preg_replace('/[【】\[\]（）()「」『』]/u', '', $value) ?? $value;
        return $value;
    }

    private function upsertDrugMasterAndAliases(string $displayDrugName, string $genericName, string $brandName, string $rawDrugText): void
    {
        $displayDrugName = trim($displayDrugName);
        if ($displayDrugName === '' || !Db::tableExists(Db::knowledge(), 'drug_master')) {
            return;
        }

        try {
            $normalized = $this->normalizeDrugKeyPart($displayDrugName);
            $stmt = Db::knowledge()->prepare('INSERT INTO drug_master
                (drug_name, normalized_name, is_active, created_at, updated_at)
                VALUES (:drug_name, :normalized_name, 1, NOW(), NOW())
                ON DUPLICATE KEY UPDATE normalized_name = VALUES(normalized_name), updated_at = NOW(), id = LAST_INSERT_ID(id)');
            $stmt->execute([':drug_name' => $displayDrugName, ':normalized_name' => $normalized]);
            $drugMasterId = (int)Db::knowledge()->lastInsertId();
            if ($drugMasterId <= 0) {
                $sel = Db::knowledge()->prepare('SELECT id FROM drug_master WHERE drug_name = :drug_name LIMIT 1');
                $sel->execute([':drug_name' => $displayDrugName]);
                $drugMasterId = (int)$sel->fetchColumn();
            }
            if ($drugMasterId <= 0 || !Db::tableExists(Db::knowledge(), 'drug_aliases')) {
                return;
            }

            $aliasStmt = Db::knowledge()->prepare('INSERT INTO drug_aliases
                (drug_master_id, alias_name, alias_type, created_at)
                VALUES (:drug_master_id, :alias_name, :alias_type, NOW())
                ON DUPLICATE KEY UPDATE alias_type = VALUES(alias_type)');

            $aliases = [];
            if ($genericName !== '' && $genericName !== $displayDrugName) {
                $aliases[$genericName] = 'generic_name';
            }
            if ($brandName !== '' && $brandName !== $displayDrugName) {
                $aliases[$brandName] = 'brand_name';
            }
            foreach (preg_split('/\R/u', $rawDrugText) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '' && $line !== $displayDrugName && !isset($aliases[$line])) {
                    $aliases[$line] = 'raw_drug_line';
                }
            }

            foreach ($aliases as $aliasName => $aliasType) {
                $aliasStmt->execute([
                    ':drug_master_id' => $drugMasterId,
                    ':alias_name' => mb_substr((string)$aliasName, 0, 255),
                    ':alias_type' => $aliasType,
                ]);
            }
        } catch (Throwable) {
        }
    }



    /**
     * 確定薬品名を外部辞書（YJ/HOT9/一般名コード）に照合して、モデルを替えても使える薬品辞書学習として保存する。
     * @param array<string,mixed> $row
     */
    private function saveDrugDictionaryLearningForRow(?int $parseJobId, int $tenantId, ?int $prescriptionId, array $row): void
    {
        if (!class_exists('DrugDictionaryService') || !DrugDictionaryService::available()) {
            return;
        }
        if (!Db::tableExists(Db::knowledge(), 'prescription_drug_dictionary_candidate_events')) {
            return;
        }
        try {
            $match = DrugDictionaryService::matchConfirmedMedication($row);
            $best = is_array($match['best'] ?? null) ? $match['best'] : [];
            $candidates = is_array($match['candidates'] ?? null) ? $match['candidates'] : [];
            if (!$best && !$candidates) {
                return;
            }
            $finalDrug = trim((string)($row['final_drug_name'] ?? ''));
            $finalGeneric = trim((string)($row['final_generic_name'] ?? ''));
            $finalBrand = trim((string)($row['final_brand_name'] ?? ''));
            $aiDrug = trim((string)($row['ai_drug_name'] ?? ''));
            $actionType = (string)($row['action_type'] ?? 'confirmed');
            if (!in_array($actionType, ['confirmed','edited','merged','deleted','added'], true)) {
                $actionType = 'edited';
            }
            $stmt = Db::knowledge()->prepare('INSERT INTO prescription_drug_dictionary_candidate_events
                (company_uid, branch_uid, tenant_id, parse_job_id, prescription_id, medication_sort_order,
                 ai_drug_name, final_drug_name, final_generic_name, final_brand_name,
                 selected_yj_code, selected_hot9_code, selected_generic_code, selected_generic_name,
                 dictionary_score, relation_confidence, action_type, query_text, candidate_json, created_at)
                VALUES
                (:company_uid, :branch_uid, :tenant_id, :parse_job_id, :prescription_id, :medication_sort_order,
                 :ai_drug_name, :final_drug_name, :final_generic_name, :final_brand_name,
                 :selected_yj_code, :selected_hot9_code, :selected_generic_code, :selected_generic_name,
                 :dictionary_score, :relation_confidence, :action_type, :query_text, :candidate_json, NOW())');
            $stmt->execute([
                ':company_uid' => current_company_uid(),
                ':branch_uid' => current_branch_uid(),
                ':tenant_id' => $tenantId,
                ':parse_job_id' => $parseJobId,
                ':prescription_id' => $prescriptionId,
                ':medication_sort_order' => (int)($row['sort_order'] ?? 0),
                ':ai_drug_name' => mb_substr($aiDrug, 0, 255),
                ':final_drug_name' => mb_substr($finalDrug, 0, 255),
                ':final_generic_name' => mb_substr($finalGeneric, 0, 255),
                ':final_brand_name' => mb_substr($finalBrand, 0, 255),
                ':selected_yj_code' => (string)($best['yj_code'] ?? ''),
                ':selected_hot9_code' => (string)($best['hot9_code'] ?? ''),
                ':selected_generic_code' => (string)($best['generic_code'] ?? ''),
                ':selected_generic_name' => mb_substr((string)($best['generic_name'] ?? ''), 0, 255),
                ':dictionary_score' => is_numeric($best['score'] ?? null) ? (float)$best['score'] : null,
                ':relation_confidence' => (string)($best['relation_confidence'] ?? ''),
                ':action_type' => $actionType,
                ':query_text' => mb_substr((string)($match['query'] ?? ''), 0, 255),
                ':candidate_json' => json_encode($candidates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            if (Db::tableExists(Db::knowledge(), 'prescription_drug_dictionary_learning_scores')) {
                $scoreStmt = Db::knowledge()->prepare('INSERT INTO prescription_drug_dictionary_learning_scores
                    (company_uid, branch_uid, dictionary_key, yj_code, hot9_code, generic_code, generic_name,
                     observed_count, confirmed_count, edited_count, merged_count, avg_dictionary_score, last_query_text, last_seen_at, created_at, updated_at)
                    VALUES
                    (:company_uid, :branch_uid, :dictionary_key, :yj_code, :hot9_code, :generic_code, :generic_name,
                     1, :confirmed_count, :edited_count, :merged_count, :avg_dictionary_score, :last_query_text, NOW(), NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                        observed_count = observed_count + 1,
                        confirmed_count = confirmed_count + VALUES(confirmed_count),
                        edited_count = edited_count + VALUES(edited_count),
                        merged_count = merged_count + VALUES(merged_count),
                        avg_dictionary_score = ((avg_dictionary_score * (observed_count - 1)) + VALUES(avg_dictionary_score)) / GREATEST(observed_count, 1),
                        generic_name = CASE WHEN VALUES(generic_name) <> "" THEN VALUES(generic_name) ELSE generic_name END,
                        last_query_text = VALUES(last_query_text),
                        last_seen_at = NOW(),
                        updated_at = NOW()');
                $genericCode = (string)($best['generic_code'] ?? '');
                $yjCode = (string)($best['yj_code'] ?? '');
                $dictionaryKey = $genericCode !== '' ? 'generic:' . $genericCode : ($yjCode !== '' ? 'yj:' . $yjCode : 'name:' . sha1($finalDrug . '|' . $finalGeneric . '|' . $finalBrand));
                $scoreStmt->execute([
                    ':company_uid' => current_company_uid(),
                    ':branch_uid' => current_branch_uid(),
                    ':dictionary_key' => $dictionaryKey,
                    ':yj_code' => $yjCode,
                    ':hot9_code' => (string)($best['hot9_code'] ?? ''),
                    ':generic_code' => $genericCode,
                    ':generic_name' => mb_substr((string)($best['generic_name'] ?? ''), 0, 255),
                    ':confirmed_count' => $actionType === 'confirmed' ? 1 : 0,
                    ':edited_count' => $actionType === 'edited' || $actionType === 'added' ? 1 : 0,
                    ':merged_count' => $actionType === 'merged' || (string)($row['relation_type'] ?? '') === 'generic_brand_pair' ? 1 : 0,
                    ':avg_dictionary_score' => is_numeric($best['score'] ?? null) ? (float)$best['score'] : 0.0,
                    ':last_query_text' => mb_substr((string)($match['query'] ?? ''), 0, 255),
                ]);
            }
        } catch (Throwable) {
        }
    }

    /**
     * 文字品質・手書き/印字/小さい文字/ぼけ/にじみ等と修正結果を紐づけて保存する。
     */
    private function saveVisualTextLearningScore(array $context, int $tenantId, ?int $parseJobId, string $fieldKey, string $fieldLabel, string $fieldGroup, string $valueType, string $correctionType, ?float $confidence, bool $needsHumanCheck, string $aiRaw, string $finalRaw): void
    {
        if (!Db::tableExists(Db::knowledge(), 'prescription_visual_text_learning_scores')) {
            return;
        }
        try {
            $layoutFingerprint = (string)($context['layout_fingerprint'] ?? 'unknown');
            $qualityBucket = (string)($context['quality_bucket'] ?? 'unknown');
            $issueFlags = (string)($context['quality_issue_flags'] ?? '');
            $features = $this->visualTextFeatures($fieldKey, $fieldLabel, $fieldGroup, $valueType, $confidence, $needsHumanCheck, $aiRaw, $finalRaw, $qualityBucket, $issueFlags);
            $edited = $correctionType === 'edited' ? 1 : 0;
            $added = $correctionType === 'added' ? 1 : 0;
            $emptied = $correctionType === 'emptied' ? 1 : 0;
            $confirmed = $correctionType === 'confirmed' ? 1 : 0;

            if (Db::tableExists(Db::knowledge(), 'prescription_visual_text_learning_events')) {
                $eventStmt = Db::knowledge()->prepare('INSERT INTO prescription_visual_text_learning_events
                    (company_uid, branch_uid, tenant_id, parse_job_id, layout_fingerprint, quality_bucket, issue_flags,
                     field_key, field_label, field_group, value_type, text_style, print_type, estimated_text_size_bucket,
                     blur_bucket, brightness_bucket, contrast_bucket, correction_type, confidence, needs_human_check,
                     source_ai_value_sample, final_value_sample, visual_features_json, created_at)
                    VALUES
                    (:company_uid, :branch_uid, :tenant_id, :parse_job_id, :layout_fingerprint, :quality_bucket, :issue_flags,
                     :field_key, :field_label, :field_group, :value_type, :text_style, :print_type, :estimated_text_size_bucket,
                     :blur_bucket, :brightness_bucket, :contrast_bucket, :correction_type, :confidence, :needs_human_check,
                     :source_ai_value_sample, :final_value_sample, :visual_features_json, NOW())');
                $risk = $this->fieldRiskLevel($fieldGroup, $fieldKey, $fieldLabel);
                $eventStmt->execute([
                    ':company_uid' => current_company_uid(),
                    ':branch_uid' => current_branch_uid(),
                    ':tenant_id' => $tenantId,
                    ':parse_job_id' => $parseJobId,
                    ':layout_fingerprint' => $layoutFingerprint,
                    ':quality_bucket' => $qualityBucket,
                    ':issue_flags' => $issueFlags,
                    ':field_key' => mb_substr($fieldKey, 0, 160),
                    ':field_label' => mb_substr($fieldLabel !== '' ? $fieldLabel : $fieldKey, 0, 160),
                    ':field_group' => $fieldGroup,
                    ':value_type' => mb_substr($valueType, 0, 64),
                    ':text_style' => $features['text_style'],
                    ':print_type' => $features['print_type'],
                    ':estimated_text_size_bucket' => $features['estimated_text_size_bucket'],
                    ':blur_bucket' => $features['blur_bucket'],
                    ':brightness_bucket' => $features['brightness_bucket'],
                    ':contrast_bucket' => $features['contrast_bucket'],
                    ':correction_type' => $correctionType,
                    ':confidence' => $confidence,
                    ':needs_human_check' => $needsHumanCheck ? 1 : 0,
                    ':source_ai_value_sample' => mb_substr($this->learningSample($aiRaw, $risk), 0, 255),
                    ':final_value_sample' => mb_substr($this->learningSample($finalRaw, $risk), 0, 255),
                    ':visual_features_json' => json_encode($features, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            }

            $stmt = Db::knowledge()->prepare('INSERT INTO prescription_visual_text_learning_scores
                (company_uid, branch_uid, layout_fingerprint, quality_bucket, field_key, field_label, field_group, value_type,
                 text_style, print_type, estimated_text_size_bucket, blur_bucket, brightness_bucket, contrast_bucket,
                 observed_count, edited_count, added_count, empty_count, confirmed_count, correction_rate, miss_rate, overdetect_rate,
                 last_issue_flags, last_seen_at, created_at, updated_at)
                VALUES
                (:company_uid, :branch_uid, :layout_fingerprint, :quality_bucket, :field_key, :field_label, :field_group, :value_type,
                 :text_style, :print_type, :estimated_text_size_bucket, :blur_bucket, :brightness_bucket, :contrast_bucket,
                 1, :edited_count, :added_count, :empty_count, :confirmed_count, :correction_rate, :miss_rate, :overdetect_rate,
                 :last_issue_flags, NOW(), NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    field_label = VALUES(field_label),
                    field_group = VALUES(field_group),
                    observed_count = observed_count + 1,
                    edited_count = edited_count + VALUES(edited_count),
                    added_count = added_count + VALUES(added_count),
                    empty_count = empty_count + VALUES(empty_count),
                    confirmed_count = confirmed_count + VALUES(confirmed_count),
                    correction_rate = ((edited_count + VALUES(edited_count) + added_count + VALUES(added_count) + empty_count + VALUES(empty_count)) / GREATEST(observed_count + 1, 1)) * 100,
                    miss_rate = ((added_count + VALUES(added_count)) / GREATEST(observed_count + 1, 1)) * 100,
                    overdetect_rate = ((empty_count + VALUES(empty_count)) / GREATEST(observed_count + 1, 1)) * 100,
                    last_issue_flags = VALUES(last_issue_flags),
                    last_seen_at = NOW(),
                    updated_at = NOW()');
            $stmt->execute([
                ':company_uid' => current_company_uid(),
                ':branch_uid' => current_branch_uid(),
                ':layout_fingerprint' => $layoutFingerprint,
                ':quality_bucket' => $qualityBucket,
                ':field_key' => mb_substr($fieldKey, 0, 160),
                ':field_label' => mb_substr($fieldLabel !== '' ? $fieldLabel : $fieldKey, 0, 160),
                ':field_group' => $fieldGroup,
                ':value_type' => mb_substr($valueType, 0, 64),
                ':text_style' => $features['text_style'],
                ':print_type' => $features['print_type'],
                ':estimated_text_size_bucket' => $features['estimated_text_size_bucket'],
                ':blur_bucket' => $features['blur_bucket'],
                ':brightness_bucket' => $features['brightness_bucket'],
                ':contrast_bucket' => $features['contrast_bucket'],
                ':edited_count' => $edited,
                ':added_count' => $added,
                ':empty_count' => $emptied,
                ':confirmed_count' => $confirmed,
                ':correction_rate' => ($edited + $added + $emptied) ? 100.0 : 0.0,
                ':miss_rate' => $added ? 100.0 : 0.0,
                ':overdetect_rate' => $emptied ? 100.0 : 0.0,
                ':last_issue_flags' => mb_substr($issueFlags, 0, 500),
            ]);
        } catch (Throwable) {
        }
    }

    /** @return array<string,string> */
    private function visualTextFeatures(string $fieldKey, string $fieldLabel, string $fieldGroup, string $valueType, ?float $confidence, bool $needsHumanCheck, string $aiRaw, string $finalRaw, string $qualityBucket, string $issueFlags): array
    {
        $text = mb_strtolower($fieldKey . ' ' . $fieldLabel . ' ' . $valueType . ' ' . $issueFlags . ' ' . $aiRaw, 'UTF-8');
        $textStyle = 'unknown';
        if (str_contains($text, '手書') || str_contains($text, '署名') || str_contains($text, 'サイン')) {
            $textStyle = 'handwritten_possible';
        } elseif (str_contains($text, 'qr') || str_contains($text, 'コード')) {
            $textStyle = 'machine_code';
        } elseif ($fieldGroup === 'medication' && ($needsHumanCheck || ($confidence !== null && $confidence < 75))) {
            $textStyle = 'printed_or_handwritten_uncertain';
        } else {
            $textStyle = 'printed_possible';
        }

        $printType = str_contains($textStyle, 'handwritten') ? 'handwritten' : (str_contains($textStyle, 'printed') ? 'printed' : 'unknown');
        $estimated = str_contains($issueFlags, 'small_text') || str_contains($qualityBucket, 'low_res') ? 'small_text_risk' : 'unknown';
        if ($estimated === 'unknown' && $confidence !== null && $confidence < 70) {
            $estimated = 'small_or_unclear_risk';
        }
        $blur = str_contains($issueFlags, 'blur') ? 'blur_risk' : 'unknown';
        $brightness = str_contains($issueFlags, 'brightness_dark') ? 'dark' : (str_contains($issueFlags, 'brightness_too_bright') ? 'too_bright' : 'unknown');
        $contrast = str_contains($issueFlags, 'low_contrast') ? 'low_contrast' : 'unknown';
        $bleed = str_contains($issueFlags, 'bleed') || str_contains($issueFlags, 'shadow') ? 'bleed_or_shadow_risk' : 'unknown';

        return [
            'text_style' => $textStyle,
            'print_type' => $printType,
            'estimated_text_size_bucket' => $estimated,
            'blur_bucket' => $blur,
            'brightness_bucket' => $brightness,
            'contrast_bucket' => $contrast,
            'bleed_bucket' => $bleed,
            'field_risk' => $this->fieldRiskLevel($fieldGroup, $fieldKey, $fieldLabel),
        ];
    }

    /**
     * 画像品質学習テーブルに追加カラムがある場合、明るさ・ぼけ・補正プロファイルなども保存する。
     * @param array<string,mixed> $stored
     * @param array<string,mixed> $layoutMeta
     */
    private function updateImageQualityDetailIfSupported(?int $parseJobId, array $stored, array $layoutMeta): void
    {
        $quality = is_array($stored['quality_analysis'] ?? null) ? $stored['quality_analysis'] : (is_array($layoutMeta['quality_analysis'] ?? null) ? $layoutMeta['quality_analysis'] : []);
        if (!$quality || !Db::tableExists(Db::knowledge(), 'prescription_image_quality_learning_scores')) {
            return;
        }
        $pdo = Db::knowledge();
        foreach (['brightness_bucket','contrast_bucket','blur_bucket','ink_bleed_risk','preprocess_profile'] as $column) {
            if (!Db::columnExists($pdo, 'prescription_image_quality_learning_scores', $column)) {
                return;
            }
        }
        try {
            $bucket = $this->imageQualityBucket((int)($stored['width'] ?? 0), (int)($stored['height'] ?? 0), (int)($stored['size'] ?? $stored['file_size_bytes'] ?? 0));
            $stmt = $pdo->prepare('UPDATE prescription_image_quality_learning_scores
                SET brightness_bucket = :brightness_bucket,
                    contrast_bucket = :contrast_bucket,
                    blur_bucket = :blur_bucket,
                    ink_bleed_risk = :ink_bleed_risk,
                    estimated_text_size_bucket = :estimated_text_size_bucket,
                    preprocess_profile = :preprocess_profile,
                    quality_json = :quality_json,
                    updated_at = NOW()
                WHERE company_uid = :company_uid
                  AND branch_uid = :branch_uid
                  AND quality_bucket = :quality_bucket
                  AND last_parse_job_id = :parse_job_id');
            $stmt->execute([
                ':brightness_bucket' => (string)($quality['brightness_bucket'] ?? 'unknown'),
                ':contrast_bucket' => (string)($quality['contrast_bucket'] ?? 'unknown'),
                ':blur_bucket' => (string)($quality['blur_bucket'] ?? 'unknown'),
                ':ink_bleed_risk' => (string)($quality['ink_bleed_risk'] ?? 'unknown'),
                ':estimated_text_size_bucket' => (string)($quality['estimated_text_size_bucket'] ?? 'unknown'),
                ':preprocess_profile' => (string)($quality['preprocess_profile'] ?? 'none'),
                ':quality_json' => json_encode($quality, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':company_uid' => current_company_uid(),
                ':branch_uid' => current_branch_uid(),
                ':quality_bucket' => $bucket,
                ':parse_job_id' => $parseJobId,
            ]);
        } catch (Throwable) {
        }
    }


    /** @param array<string,mixed> $context */
    private function logLearningError(string $area, Throwable $e, array $context = []): void
    {
        try {
            if (!Db::tableExists(Db::knowledge(), 'prescription_learning_error_logs')) {
                return;
            }
            $stmt = Db::knowledge()->prepare('INSERT INTO prescription_learning_error_logs
                (company_uid, branch_uid, area, error_class, error_message, context_json, created_at)
                VALUES (:company_uid, :branch_uid, :area, :error_class, :error_message, :context_json, NOW())');
            $stmt->execute([
                ':company_uid' => current_company_uid(),
                ':branch_uid' => current_branch_uid(),
                ':area' => mb_substr($area, 0, 120),
                ':error_class' => mb_substr(get_class($e), 0, 160),
                ':error_message' => mb_substr($e->getMessage(), 0, 1000),
                ':context_json' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable) {
            // ログ保存に失敗しても本処理は止めない。
        }
    }

}

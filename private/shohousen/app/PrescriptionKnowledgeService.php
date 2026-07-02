<?php
declare(strict_types=1);

final class PrescriptionKnowledgeService
{
    public function findTemplate(?string $layoutFingerprint = null): ?array
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
                  success_count DESC,
                  avg_correction_rate ASC,
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
            return $row;
        } catch (Throwable) {
            // レンタルSVでknowledge DB未適用の間もOCR本体を止めない。
            return null;
        }
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
        } catch (Throwable) {
            // ナレッジDB未準備時はログだけ失敗扱いで処理継続する。
        }
    }

    /** @param array<string,mixed> $detected */
    public function saveTemplateCandidate(int $parseJobId, int $tenantId, string $fingerprint, array $detected): void
    {
        if ($fingerprint === '') {
            return;
        }
        try {
            $stmt = Db::knowledge()->prepare('INSERT INTO prescription_template_candidates
                (company_uid, branch_uid, tenant_id, parse_job_id, detected_fingerprint, ai_field_map_json, human_fixed_field_map_json, match_count, status, created_at, updated_at)
                VALUES (:company_uid, :branch_uid, :tenant_id, :parse_job_id, :detected_fingerprint, :ai_field_map_json, NULL, 1, "candidate", NOW(), NOW())
                ON DUPLICATE KEY UPDATE match_count = match_count + 1, updated_at = NOW()');
            $stmt->execute([
                ':company_uid' => current_company_uid(),
                ':branch_uid' => current_branch_uid(),
                ':tenant_id' => $tenantId,
                ':parse_job_id' => $parseJobId,
                ':detected_fingerprint' => $fingerprint,
                ':ai_field_map_json' => json_encode($detected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable) {
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
            $key = (string)($row['drug_name'] ?? '') . '|' . (string)($row['alias_name'] ?? '');
            if ($key === '|' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $row;
        }
        return array_slice($out, 0, 8);
    }

    public function upsertCorrectionRule(string $fieldType, string $wrongValue, string $correctValue): void
    {
        $wrongValue = trim($wrongValue);
        $correctValue = trim($correctValue);
        if ($wrongValue === '' || $correctValue === '' || $wrongValue === $correctValue) {
            return;
        }
        // 患者・保険など個人性/請求影響が強いものは、自動補正ルールには昇格しない。
        if (!in_array($fieldType, ['drug_name', 'drug_generic_name', 'drug_brand_name', 'drug_raw_text', 'usage_text', 'medical_institution_name'], true)) {
            return;
        }

        // 1回の修正だけで precision_rate=100 / active にしない。
        // 3回以上同じ修正が出た時点で、OpenAIへの補助ヒント/候補として使える状態にする。
        $sql = 'INSERT INTO prescription_auto_correction_rules
                (company_uid, branch_uid, scope_type, field_type, wrong_value, correct_value, support_count, success_count, failure_count, precision_rate, min_score, is_active, created_at, updated_at)
                VALUES (:company_uid, :branch_uid, "branch", :field_type, :wrong_value, :correct_value, 1, 1, 0, 55.00, 95.00, 0, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    support_count = support_count + 1,
                    success_count = success_count + 1,
                    precision_rate = LEAST(98.00, 50.00 + (support_count + 1) * 8.00),
                    min_score = GREATEST(65.00, 95.00 - (support_count + 1) * 5.00),
                    is_active = CASE WHEN (support_count + 1) >= 3 THEN 1 ELSE is_active END,
                    updated_at = NOW()';
        try {
            $stmt = Db::knowledge()->prepare($sql);
            $stmt->execute([
                ':company_uid' => current_company_uid(),
                ':branch_uid' => current_branch_uid(),
                ':field_type' => $fieldType,
                ':wrong_value' => $wrongValue,
                ':correct_value' => $correctValue,
            ]);
        } catch (Throwable) {
        }
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
                    $stmt->execute([
                        ':company_uid' => current_company_uid(),
                        ':branch_uid' => current_branch_uid(),
                        ':tenant_id' => $tenantId,
                        ':parse_job_id' => $parseJobId,
                        ':prescription_id' => $prescriptionId,
                        ':field_key' => $row['field_key'],
                        ':field_label' => $row['field_label'],
                        ':field_group' => $row['field_group'],
                        ':field_value' => $row['field_value'],
                        ':source_ai_value' => $row['source_ai_value'],
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
                        ':last_value_sample' => mb_substr((string)($row['field_value'] ?? ''), 0, 255),
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
                    ':last_ai_value_sample' => mb_substr($ai, 0, 255),
                    ':last_final_value_sample' => mb_substr($final, 0, 255),
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

                // OCR補正ルールに昇格してよい低リスク項目だけ、次回AI解析前の候補として使う。
                if (in_array($fieldGroup, ['medication','medical_institution'], true) && $normalizedAi !== '' && $normalizedFinal !== '' && $normalizedAi !== $normalizedFinal) {
                    $this->upsertCorrectionRule($fieldKey, $aiRaw, $finalRaw);
                }
            }
        } catch (Throwable) {
            // 補助学習DBの保存失敗で確認画面遷移を止めない。
        }
    }

    /**
     * 補助学習DBに蓄積された人間修正傾向をOpenAIの読み取りプロンプトへ渡す短いヒントにする。
     */
    public function buildOpenAiLearningHints(int $limit = 18): string
    {
        $hints = [];
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
            $features = (new PrescriptionTemplateDetector())->featuresFromDimensions($width, $height, '');
            $context['layout_fingerprint'] = hash('sha256', json_encode($features, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $context['quality_bucket'] = $this->imageQualityBucket($width, $height, $size);
            $context['quality_issue_flags'] = $this->imageQualityIssueFlags($width, $height, $size);
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
        } catch (Throwable) {
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


}

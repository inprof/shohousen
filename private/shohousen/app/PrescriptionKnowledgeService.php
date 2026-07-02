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
        // 患者・保険など個人性/請求影響が強いものはナレッジDBへ昇格しない。
        if (!in_array($fieldType, ['drug_name', 'drug_generic_name', 'drug_brand_name', 'drug_raw_text', 'usage_text', 'medical_institution_name'], true)) {
            return;
        }
        $sql = 'INSERT INTO prescription_auto_correction_rules
                (company_uid, branch_uid, scope_type, field_type, wrong_value, correct_value, support_count, success_count, failure_count, precision_rate, is_active, created_at, updated_at)
                VALUES (:company_uid, :branch_uid, "branch", :field_type, :wrong_value, :correct_value, 1, 1, 0, 100.00, 1, NOW(), NOW())
                ON DUPLICATE KEY UPDATE support_count = support_count + 1, success_count = success_count + 1, precision_rate = (success_count / GREATEST(support_count, 1)) * 100, updated_at = NOW()';
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
        $score = $confidence !== null ? max(0.0, min(100.0, $confidence)) : 50.0;
        $score += $selected ? 18.0 : -18.0;
        $score += $includeForOutput ? 8.0 : 0.0;
        $score += $edited ? 10.0 : 0.0;
        $score += $empty ? -25.0 : 0.0;
        $score += $needsHumanCheck ? -4.0 : 0.0;
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
    public function saveDrugNameLearningEvents(?int $parseJobId, int $tenantId, int $prescriptionId, array $rows): void
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

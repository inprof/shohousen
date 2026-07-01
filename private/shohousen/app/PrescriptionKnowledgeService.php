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
        try {
            $stmt = Db::knowledge()->prepare('SELECT dm.drug_name, da.alias_name
                FROM drug_aliases da
                INNER JOIN drug_master dm ON dm.id = da.drug_master_id
                WHERE da.alias_name = :value OR dm.drug_name = :value
                LIMIT 5');
            $stmt->execute([':value' => $value]);
            return $stmt->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }

    public function upsertCorrectionRule(string $fieldType, string $wrongValue, string $correctValue): void
    {
        $wrongValue = trim($wrongValue);
        $correctValue = trim($correctValue);
        if ($wrongValue === '' || $correctValue === '' || $wrongValue === $correctValue) {
            return;
        }
        // 患者・保険など個人性/請求影響が強いものはナレッジDBへ昇格しない。
        if (!in_array($fieldType, ['drug_name', 'usage_text', 'medical_institution_name'], true)) {
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
        } catch (Throwable) {
            // 補助学習DBへの保存失敗で処方箋確定保存を止めない。
        }
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


}

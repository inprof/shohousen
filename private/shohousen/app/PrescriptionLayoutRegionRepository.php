<?php
declare(strict_types=1);

/**
 * 補助学習DB(inprof3_assistantdata)に承認済みとして保存された、項目ごとの画像上の候補領域を取得する。
 * company DB は親会社DB、branch/tenant DB は拠点DBなので、テンプレート共有用の座標は knowledge DB だけを見る。
 */
final class PrescriptionLayoutRegionRepository
{
    /** @return array<string,mixed>|null */
    public function findApprovedRegion(string $fieldKey, array $context = []): ?array
    {
        $fieldKey = $this->canonicalKey($fieldKey);
        if ($fieldKey === '') {
            return null;
        }

        try {
            $pdo = Db::knowledge();
            if (Db::tableExists($pdo, 'prescription_global_template_regions')) {
                $row = $this->findFromGlobalRegions($pdo, $fieldKey, $context);
                if ($row) {
                    return $row;
                }
            }
            // 互換: 以前の差分SQLを適用済みの場合。
            if (Db::tableExists($pdo, 'prescription_layout_field_regions')) {
                $row = $this->findFromLegacyRegions($pdo, $fieldKey, $context);
                if ($row) {
                    return $row;
                }
            }
        } catch (Throwable) {
            return null;
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    private function findFromGlobalRegions(PDO $pdo, string $fieldKey, array $context): ?array
    {
        $templateHash = $this->contextString($context, ['template_hash', 'detected_template_hash', 'layout_fingerprint']);
        $lineSignature = $this->contextString($context, ['line_signature']);
        $labelSignature = $this->contextString($context, ['label_signature']);

        $where = ['field_key = :field_key', 'enabled = 1', 'status = "approved"'];
        $params = [':field_key' => $fieldKey];
        if ($templateHash !== '') {
            $where[] = '(template_hash = :template_hash OR template_hash IS NULL OR template_hash = "")';
            $params[':template_hash'] = $templateHash;
        }
        if ($lineSignature !== '') {
            $where[] = '(line_signature = :line_signature OR line_signature IS NULL OR line_signature = "")';
            $params[':line_signature'] = $lineSignature;
        }
        if ($labelSignature !== '') {
            $where[] = '(label_signature = :label_signature OR label_signature IS NULL OR label_signature = "")';
            $params[':label_signature'] = $labelSignature;
        }

        $sql = 'SELECT *, "prescription_global_template_regions" AS source_table
            FROM prescription_global_template_regions
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY
                CASE WHEN template_hash = :exact_template_hash THEN 0 ELSE 1 END,
                confidence_score DESC,
                sample_count DESC,
                updated_at DESC,
                id DESC
            LIMIT 1';
        $params[':exact_template_hash'] = $templateHash;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return is_array($row) ? $this->normalizeRegion($row) : null;
    }

    /** @return array<string,mixed>|null */
    private function findFromLegacyRegions(PDO $pdo, string $fieldKey, array $context): ?array
    {
        $templateId = $this->contextString($context, ['template_id']);
        $where = ['field_key = :field_key', 'enabled = 1'];
        $params = [':field_key' => $fieldKey];
        if ($templateId !== '') {
            $where[] = '(template_id = :template_id OR template_id IS NULL OR template_id = 0)';
            $params[':template_id'] = $templateId;
        }
        $stmt = $pdo->prepare('SELECT *, "prescription_layout_field_regions" AS source_table
            FROM prescription_layout_field_regions
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY confidence_score DESC, updated_at DESC, id DESC
            LIMIT 1');
        $stmt->execute($params);
        $row = $stmt->fetch();
        return is_array($row) ? $this->normalizeRegion($row) : null;
    }

    /** @return array<string,mixed> */
    private function normalizeRegion(array $row): array
    {
        return [
            'field_key' => $this->canonicalKey((string)($row['field_key'] ?? '')),
            'field_label' => (string)($row['field_label'] ?? $row['label_text'] ?? ''),
            'x_ratio' => $this->ratio($row['x_ratio'] ?? null),
            'y_ratio' => $this->ratio($row['y_ratio'] ?? null),
            'w_ratio' => $this->ratio($row['w_ratio'] ?? null),
            'h_ratio' => $this->ratio($row['h_ratio'] ?? null),
            'margin_ratio' => $this->ratio($row['margin_ratio'] ?? 0.01),
            'confidence_score' => is_numeric($row['confidence_score'] ?? null) ? (float)$row['confidence_score'] : null,
            'sample_count' => is_numeric($row['sample_count'] ?? null) ? (int)$row['sample_count'] : null,
            'source_table' => (string)($row['source_table'] ?? ''),
        ];
    }

    private function ratio(mixed $value): float
    {
        if (!is_numeric($value)) {
            return 0.0;
        }
        return max(0.0, min(1.0, (float)$value));
    }

    private function contextString(array $context, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string)($context[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function canonicalKey(string $key): string
    {
        $key = str_replace('-', '_', trim($key));
        return match ($key) {
            'birth_date', 'patient_birth_date', 'patient.birth_date' => 'patient.birth_date',
            'insurance_no', 'insurer_number', 'insurance.insurance_no' => 'insurance.insurance_no',
            'insured_symbol_number', 'insurance_symbol_number', 'insurance.insured_symbol_number' => 'insurance.insured_symbol_number',
            'public_payer_no', 'public_expense_payer_no', 'public_expense.payer_no' => 'public_expense.payer_no',
            'public_beneficiary_no', 'beneficiary_no', 'public_expense_beneficiary_no', 'public_expense.beneficiary_no' => 'public_expense.beneficiary_no',
            'issued_on', 'prescription_issued_on', 'prescription.issued_on' => 'prescription.issued_on',
            'received_on', 'prescription_received_on', 'prescription.received_on' => 'prescription.received_on',
            'expires_on', 'prescription_expires_on', 'prescription.expires_on' => 'prescription.expires_on',
            'medical_institution_code', 'medical_institution_code_7', 'medical_institution.code' => 'medical_institution.code',
            default => preg_replace('/[^a-zA-Z0-9_.]+/', '_', $key) ?: '',
        };
    }
}

<?php
declare(strict_types=1);

final class PrescriptionQrService
{
    /**
     * MVP用のQR中間データ。
     * 本番のJAHIS QR規格マッピングは、この関数の内部を規格項目に合わせて差し替える。
     */
    public function buildPayload(array $prescription): string
    {
        $lines = [];
        $lines[] = 'SHOHOUSEN-MVP-QR';
        $lines[] = 'RECEPTION_NO=' . (string)$prescription['reception_no'];
        $lines[] = 'PATIENT_NAME=' . (string)$prescription['patient_name'];
        $lines[] = 'BIRTH_DATE=' . (string)$prescription['birth_date'];
        $lines[] = 'INSURANCE_NO=' . (string)$prescription['insurance_no'];
        $lines[] = 'SYMBOL_NUMBER=' . (string)$prescription['insured_symbol_number'];
        $lines[] = 'ISSUED_ON=' . (string)$prescription['issued_on'];
        $lines[] = 'MEDICAL_NAME=' . (string)$prescription['medical_name'];
        foreach (($prescription['medications'] ?? []) as $i => $med) {
            $n = $i + 1;
            $lines[] = 'MED' . $n . '_DRUG=' . (string)$med['drug_name'];
            $lines[] = 'MED' . $n . '_USAGE=' . (string)$med['usage_text'];
            $lines[] = 'MED' . $n . '_DAYS=' . (string)$med['days_count'];
            $lines[] = 'MED' . $n . '_AMOUNT=' . (string)$med['amount_text'];
        }

        foreach (($prescription['selected_fields'] ?? []) as $field) {
            if (empty($field['include_for_output'])) {
                continue;
            }
            $key = strtoupper(preg_replace('/[^A-Za-z0-9_]+/', '_', (string)$field['field_key']));
            $key = trim($key, '_');
            if ($key === '') {
                continue;
            }
            $lines[] = 'FIELD_' . $key . '=' . str_replace(["\r", "\n"], ' ', (string)($field['field_value'] ?? ''));
        }

        return implode("\n", $lines);
    }

    public function persistPayload(int $tenantId, int $prescriptionId): string
    {
        $prescription = get_prescription($tenantId, $prescriptionId);
        if (!$prescription) {
            throw new RuntimeException('QR生成対象の処方箋が見つかりません。');
        }
        $payload = $this->buildPayload($prescription);
        $where = 'id = :id';
        $params = [':payload' => $payload, ':id' => $prescriptionId];
        if (Db::columnExists(Db::branch(), 'prescriptions', 'tenant_id')) {
            $where .= ' AND tenant_id = :tenant_id';
            $params[':tenant_id'] = $tenantId;
        }
        $stmt = Db::branch()->prepare('UPDATE prescriptions SET qr_payload = :payload, updated_at = NOW() WHERE ' . $where);
        $stmt->execute($params);

        $parseJobId = (int)($prescription['parse_job_id'] ?? 0) ?: null;
        (new PrescriptionIoDebugService())->saveSnapshot($tenantId, $parseJobId, $prescriptionId, 'qr_payload', '書き出し後: QR中間データ', $payload, [
            'content_type' => 'text',
        ]);
        (new PrescriptionKnowledgeService())->savePipelineTrace($tenantId, $parseJobId ?? 0, $prescriptionId, 'qr_payload', 'write', ['payload' => $payload], [
            'content_type' => 'text',
        ]);
        return $payload;
    }
}

<?php
declare(strict_types=1);

final class PrescriptionTemplateDetector
{
    /**
     * 本番運用用のレイアウト特徴量。
     * 個人情報・処方内容そのものはfingerprintに入れず、帳票形状・解像度・A4比率などの
     * テンプレート判定に必要な非個人情報だけで粗いfingerprintを作る。
     *
     * 注意:
     * - ここはAI解析前に使うため、画像から安定して取れる特徴に限定する。
     * - 見出し/項目の並びはAI解析後・人間修正後に別途layout profileとして保存する。
     *
     * @param array{width:?int,height:?int,mime_type?:string,sha256?:string,size?:int,file_size_bytes?:int} $stored
     * @return array<string,mixed>
     */
    public function detectFromStored(array $stored): array
    {
        $width = (int)($stored['width'] ?? 0);
        $height = (int)($stored['height'] ?? 0);
        $mime = (string)($stored['mime_type'] ?? '');
        $size = (int)($stored['size'] ?? $stored['file_size_bytes'] ?? 0);
        $features = $this->featuresFromDimensions($width, $height, $mime, $size);
        $orientation = (string)($features['orientation'] ?? 'unknown');
        $fingerprint = hash('sha256', json_encode($features, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [
            'layout_fingerprint' => $fingerprint,
            'features' => $features,
            'paper_orientation' => $orientation,
            'match_score' => null,
            'profile_version' => 'layout_v2',
        ];
    }

    /**
     * 画像そのものや個人情報を使わず、帳票レイアウトの粗い特徴だけを作る。
     * MIME種別は jpeg/png の違いでfingerprintが割れないよう image/pdf/unknown 程度に丸める。
     *
     * @return array<string,string>
     */
    public function featuresFromDimensions(int $width, int $height, string $mime = '', int $fileSizeBytes = 0): array
    {
        $orientation = 'unknown';
        if ($width > 0 && $height > 0) {
            $orientation = $height >= $width ? 'portrait' : 'landscape';
        }

        $paperShape = 'unknown';
        $aspectBucket = 'unknown';
        if ($width > 0 && $height > 0) {
            $ratio = round(max($width, $height) / max(1, min($width, $height)), 3);
            $aspectBucket = $this->ratioBucket($ratio);
            if ($ratio >= 1.30 && $ratio <= 1.55) {
                $paperShape = 'a4_like';
            } elseif ($ratio < 1.30) {
                $paperShape = 'square_like';
            } else {
                $paperShape = 'long_or_cropped';
            }
        }

        $long = max($width, $height);
        $short = min($width, $height);

        return [
            'profile_version' => 'layout_v2',
            'orientation' => $orientation,
            'paper_shape_bucket' => $paperShape,
            'aspect_ratio_bucket' => $aspectBucket,
            'resolution_bucket' => $this->resolutionBucket($long, $short),
            'long_side_bucket' => $this->bucket($long, [1200, 1600, 2200, 3000, 4200]),
            'short_side_bucket' => $this->bucket($short, [800, 1200, 1600, 2200, 3000]),
            'file_size_bucket' => $this->fileSizeBucket($fileSizeBytes),
        ];
    }

    /**
     * AI解析後の項目配列から、個人情報の値を含まないレイアウトプロファイルを作る。
     * 次回プロンプトに渡す対象は「項目名/分類/出現順/セクション名」だけに限定する。
     *
     * @param array<string,mixed> $normalized
     * @return array<string,mixed>
     */
    public function fieldProfileFromNormalized(array $normalized): array
    {
        $fields = [];
        $i = 0;
        foreach (($normalized['form_fields'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = $this->normalizeFieldKey((string)($row['field_key'] ?? $row['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $group = (string)($row['field_group'] ?? $row['group'] ?? 'other');
            $fields[] = [
                'field_key' => $key,
                'field_label' => mb_substr(trim((string)($row['field_label'] ?? $row['label'] ?? $key)), 0, 80),
                'field_group' => $this->normalizeFieldGroup($group),
                'value_type' => mb_substr(trim((string)($row['value_type'] ?? 'unknown')), 0, 40),
                'source_section' => mb_substr(trim((string)($row['source_section'] ?? '')), 0, 80),
                'display_order' => $i++,
            ];
        }

        $medicationCount = 0;
        $medicationShape = [
            'has_drug_name' => false,
            'has_dose_text' => false,
            'has_usage_text' => false,
            'has_days_count' => false,
            'has_amount_text' => false,
        ];
        foreach (($normalized['medications'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $medicationCount++;
            foreach ($medicationShape as $k => $_) {
                $field = str_replace('has_', '', $k);
                if (trim((string)($row[$field] ?? '')) !== '') {
                    $medicationShape[$k] = true;
                }
            }
        }

        $sequence = array_map(static fn(array $f): string => $f['field_group'] . ':' . $f['field_key'], $fields);
        return [
            'profile_version' => 'field_profile_v1',
            'field_count' => count($fields),
            'field_sequence_hash' => sha1(implode('|', $sequence)),
            'fields' => $fields,
            'medication_shape' => $medicationShape + ['count' => $medicationCount],
        ];
    }

    /** @param array<int,int> $thresholds */
    private function bucket(int $value, array $thresholds): string
    {
        if ($value <= 0) {
            return 'unknown';
        }
        foreach ($thresholds as $threshold) {
            if ($value <= $threshold) {
                return '<=' . $threshold;
            }
        }
        return '>' . end($thresholds);
    }

    private function ratioBucket(float $ratio): string
    {
        if ($ratio <= 0) {
            return 'unknown';
        }
        if ($ratio < 1.25) {
            return '<1.25';
        }
        if ($ratio < 1.30) {
            return '1.25-1.30';
        }
        if ($ratio <= 1.38) {
            return '1.30-1.38';
        }
        if ($ratio <= 1.48) {
            return '1.39-1.48';
        }
        if ($ratio <= 1.58) {
            return '1.49-1.58';
        }
        return '>1.58';
    }

    private function resolutionBucket(int $long, int $short): string
    {
        if ($long <= 0 || $short <= 0) {
            return 'unknown';
        }
        if ($long < 1400 || $short < 900) {
            return 'low_res';
        }
        if ($long < 2600 || $short < 1700) {
            return 'mid_res';
        }
        return 'high_res';
    }

    private function fileSizeBucket(int $bytes): string
    {
        if ($bytes <= 0) {
            return 'unknown';
        }
        if ($bytes < 300 * 1024) {
            return '<300KB';
        }
        if ($bytes < 900 * 1024) {
            return '300-900KB';
        }
        if ($bytes < 2 * 1024 * 1024) {
            return '900KB-2MB';
        }
        if ($bytes < 5 * 1024 * 1024) {
            return '2-5MB';
        }
        return '>5MB';
    }

    private function mimeFamily(string $mime): string
    {
        $mime = strtolower($mime);
        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }
        if ($mime === 'application/pdf') {
            return 'pdf';
        }
        return 'unknown';
    }

    private function normalizeFieldKey(string $key): string
    {
        $key = preg_replace('/[^a-zA-Z0-9_.-]+/', '_', trim($key)) ?? '';
        return trim($key, '_');
    }

    private function normalizeFieldGroup(string $group): string
    {
        $allowed = ['patient','insurance','public_expense','prescription','medical_institution','medication','pharmacy','note','qr','other'];
        return in_array($group, $allowed, true) ? $group : 'other';
    }
}

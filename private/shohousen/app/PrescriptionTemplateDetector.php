<?php
declare(strict_types=1);

final class PrescriptionTemplateDetector
{
    /**
     * MVP用のレイアウト特徴量。
     * 個人情報・処方内容は含めず、画像の形状/向き/サイズ区分だけでfingerprintを作る。
     * 将来は罫線位置、見出し位置、QR位置などを追加する。
     *
     * @param array{width:?int,height:?int,mime_type?:string,sha256?:string,size?:int} $stored
     * @return array<string,mixed>
     */
    public function detectFromStored(array $stored): array
    {
        $width = (int)($stored['width'] ?? 0);
        $height = (int)($stored['height'] ?? 0);
        $mime = (string)($stored['mime_type'] ?? '');
        $features = $this->featuresFromDimensions($width, $height, $mime);
        $fingerprint = hash('sha256', json_encode($features, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [
            'layout_fingerprint' => $fingerprint,
            'features' => $features,
            'paper_orientation' => $orientation,
            'match_score' => null,
        ];
    }


    /**
     * 画像そのものや個人情報を使わず、帳票レイアウトの粗い特徴だけを作る。
     * @return array<string,string>
     */
    public function featuresFromDimensions(int $width, int $height, string $mime = ''): array
    {
        $orientation = 'unknown';
        if ($width > 0 && $height > 0) {
            $orientation = $height >= $width ? 'portrait' : 'landscape';
        }

        $ratioBucket = 'unknown';
        if ($width > 0 && $height > 0) {
            $ratio = round(max($width, $height) / max(1, min($width, $height)), 2);
            if ($ratio >= 1.30 && $ratio <= 1.55) {
                $ratioBucket = 'a4_like';
            } elseif ($ratio < 1.30) {
                $ratioBucket = 'square_like';
            } else {
                $ratioBucket = 'long_or_cropped';
            }
        }

        return [
            'orientation' => $orientation,
            'ratio_bucket' => $ratioBucket,
            'long_side_bucket' => $this->bucket(max($width, $height), [1200, 1600, 2200, 3000, 4200]),
            'short_side_bucket' => $this->bucket(min($width, $height), [800, 1200, 1600, 2200, 3000]),
            'mime' => $mime,
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
}

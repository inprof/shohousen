<?php
declare(strict_types=1);

final class PrescriptionImagePreprocessor
{
    /** @return array{storage_file_id:int,path:string,mime_type:string,width:?int,height:?int,size:int,sha256:string,original_filename:string} */
    public function storeUploadedFile(array $file, array $user): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('処方箋画像のアップロードに失敗しました。');
        }
        $tmp = (string)$file['tmp_name'];
        $mime = self::detectMime($tmp);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new RuntimeException('対応ファイルは JPG / PNG / WEBP です。PDFは画像化後に取り込んでください。');
        }

        $baseDir = rtrim((string)app_config('storage.prescription_dir', dirname(__DIR__) . '/storage/prescriptions'), '/');
        $companyUid = current_company_uid();
        $branchUid = current_branch_uid();
        $ym = date('Y/m');
        $dir = $baseDir . '/' . $companyUid . '/' . $branchUid . '/' . $ym;
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('保存先フォルダを作成できません。');
        }

        $ext = match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
        $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $path = $dir . '/' . $filename;
        if (!move_uploaded_file($tmp, $path)) {
            throw new RuntimeException('処方箋画像の保存に失敗しました。');
        }

        [$width, $height] = self::imageSize($path);
        $size = filesize($path) ?: 0;
        $sha = hash_file('sha256', $path) ?: '';
        $original = mb_substr((string)($file['name'] ?? ''), 0, 255);

        $stmt = Db::branch()->prepare('INSERT INTO storage_files
            (company_uid, branch_uid, tenant_id, file_type, original_filename, stored_path, mime_type, file_size_bytes, sha256_hash, created_by, created_at)
            VALUES (:company_uid, :branch_uid, :tenant_id, :file_type, :original_filename, :stored_path, :mime_type, :file_size_bytes, :sha256_hash, :created_by, NOW())');
        $stmt->execute([
            ':company_uid' => $companyUid,
            ':branch_uid' => $branchUid,
            ':tenant_id' => (int)$user['tenant_id'],
            ':file_type' => 'prescription_original',
            ':original_filename' => $original,
            ':stored_path' => $path,
            ':mime_type' => $mime,
            ':file_size_bytes' => $size,
            ':sha256_hash' => $sha,
            ':created_by' => (int)$user['id'],
        ]);

        return [
            'storage_file_id' => (int)Db::branch()->lastInsertId(),
            'path' => $path,
            'mime_type' => $mime,
            'width' => $width,
            'height' => $height,
            'size' => $size,
            'sha256' => $sha,
            'original_filename' => $original,
        ];
    }


    /**
     * OCR補助用に、画像品質・文字読み取りに関係する特徴量を抽出する。
     * フォント種別そのものは画像だけでは断定しないが、解像度・明るさ・コントラスト・ぼけ・にじみリスクを保存する。
     * @param array<string,mixed> $stored
     * @return array<string,mixed>
     */
    public function analyzeStoredFile(array $stored): array
    {
        $path = (string)($stored['path'] ?? '');
        $width = (int)($stored['width'] ?? 0);
        $height = (int)($stored['height'] ?? 0);
        $fileSize = (int)($stored['size'] ?? $stored['file_size_bytes'] ?? 0);
        $long = max($width, $height);
        $short = min($width, $height);
        $ratio = ($long > 0 && $short > 0) ? round($long / max(1, $short), 3) : 0.0;

        $analysis = [
            'width' => $width ?: null,
            'height' => $height ?: null,
            'file_size_bytes' => $fileSize ?: null,
            'long_side' => $long ?: null,
            'short_side' => $short ?: null,
            'aspect_ratio' => $ratio ?: null,
            'resolution_bucket' => self::resolutionBucket($long),
            'paper_shape_bucket' => self::shapeBucket($ratio),
            'brightness_avg' => null,
            'brightness_bucket' => 'unknown',
            'contrast_stddev' => null,
            'contrast_bucket' => 'unknown',
            'edge_strength' => null,
            'blur_bucket' => 'unknown',
            'ink_bleed_risk' => 'unknown',
            'estimated_text_size_bucket' => 'unknown',
            'print_type' => 'unknown',
            'font_family_guess' => 'unknown',
            'issue_flags' => [],
            'preprocess_recommended' => false,
            'preprocess_profile' => 'none',
        ];

        if ($long > 0 && $long < 1400) {
            $analysis['issue_flags'][] = 'low_resolution';
        }
        if ($ratio > 0 && ($ratio < 1.25 || $ratio > 1.65)) {
            $analysis['issue_flags'][] = 'non_a4_ratio_or_cropped';
        }
        if ($fileSize > 0 && $fileSize < 180 * 1024) {
            $analysis['issue_flags'][] = 'small_file_high_compression_risk';
        }

        $stats = $path !== '' ? self::sampleImageStats($path) : [];
        if ($stats) {
            $brightness = (float)($stats['brightness_avg'] ?? 0);
            $contrast = (float)($stats['contrast_stddev'] ?? 0);
            $edge = (float)($stats['edge_strength'] ?? 0);
            $darkRatio = (float)($stats['dark_ratio'] ?? 0);
            $lightRatio = (float)($stats['light_ratio'] ?? 0);

            $analysis['brightness_avg'] = round($brightness, 2);
            $analysis['contrast_stddev'] = round($contrast, 2);
            $analysis['edge_strength'] = round($edge, 2);
            $analysis['brightness_bucket'] = $brightness < 85 ? 'dark' : ($brightness > 210 ? 'too_bright' : 'normal');
            $analysis['contrast_bucket'] = $contrast < 28 ? 'low_contrast' : ($contrast > 82 ? 'high_contrast' : 'normal');
            $analysis['blur_bucket'] = $edge < 7 ? 'blur_or_low_edge' : ($edge < 14 ? 'slightly_blur' : 'sharp_enough');
            $analysis['ink_bleed_risk'] = ($darkRatio > 0.42 || ($contrast > 88 && $edge < 12)) ? 'possible_bleed_or_shadow' : 'normal';

            if ($analysis['brightness_bucket'] !== 'normal') {
                $analysis['issue_flags'][] = 'brightness_' . $analysis['brightness_bucket'];
            }
            if ($analysis['contrast_bucket'] === 'low_contrast') {
                $analysis['issue_flags'][] = 'low_contrast';
            }
            if ($analysis['blur_bucket'] !== 'sharp_enough') {
                $analysis['issue_flags'][] = $analysis['blur_bucket'];
            }
            if ($analysis['ink_bleed_risk'] !== 'normal') {
                $analysis['issue_flags'][] = $analysis['ink_bleed_risk'];
            }
            if ($lightRatio > 0.88 && $contrast < 35) {
                $analysis['issue_flags'][] = 'thin_or_faint_text_risk';
            }
        }

        $analysis['estimated_text_size_bucket'] = $long > 0
            ? ($long < 1600 ? 'small_text_risk' : ($long < 2600 ? 'normal' : 'large_enough'))
            : 'unknown';
        if ($analysis['estimated_text_size_bucket'] === 'small_text_risk') {
            $analysis['issue_flags'][] = 'small_text_risk';
        }

        $analysis['issue_flags'] = array_values(array_unique(array_filter($analysis['issue_flags'])));
        $analysis['preprocess_recommended'] = (bool)$analysis['issue_flags'];
        $analysis['preprocess_profile'] = $analysis['preprocess_recommended']
            ? self::preprocessProfile($analysis)
            : 'none';

        return $analysis;
    }

    /**
     * 低解像度・暗い・低コントラスト・ぼけ等の場合、OpenAIへ渡すOCR補助画像を生成する。
     * 元画像はそのまま保存し、補助画像は別ファイルとして保存する。
     * @param array<string,mixed> $stored
     * @param array<string,mixed> $user
     * @param array<string,mixed> $quality
     * @return array<string,mixed>|null
     */
    public function createOcrAssistImage(array $stored, array $user, array $quality): ?array
    {
        if (empty($quality['preprocess_recommended']) && !(bool)app_config('ocr.always_make_assist_image', false)) {
            return null;
        }
        if (!extension_loaded('gd')) {
            return null;
        }

        $path = (string)($stored['path'] ?? '');
        if ($path === '' || !is_file($path)) {
            return null;
        }
        $src = self::loadGdImage($path, (string)($stored['mime_type'] ?? ''));
        if (!$src) {
            return null;
        }

        $srcW = imagesx($src);
        $srcH = imagesy($src);
        if ($srcW <= 0 || $srcH <= 0) {
            imagedestroy($src);
            return null;
        }

        $long = max($srcW, $srcH);
        $scale = 1.0;
        if ($long < 1800) {
            $scale = min(2.0, 2400 / max(1, $long));
        } elseif ($long > 3400) {
            $scale = 3400 / $long;
        }
        $dstW = max(1, (int)round($srcW * $scale));
        $dstH = max(1, (int)round($srcH * $scale));
        $dst = imagecreatetruecolor($dstW, $dstH);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);

        $brightnessBucket = (string)($quality['brightness_bucket'] ?? 'unknown');
        if ($brightnessBucket === 'dark') {
            imagefilter($dst, IMG_FILTER_BRIGHTNESS, 12);
        } elseif ($brightnessBucket === 'too_bright') {
            imagefilter($dst, IMG_FILTER_BRIGHTNESS, -8);
        }
        if (($quality['contrast_bucket'] ?? '') === 'low_contrast') {
            imagefilter($dst, IMG_FILTER_CONTRAST, -18);
        } else {
            imagefilter($dst, IMG_FILTER_CONTRAST, -8);
        }
        if (function_exists('imageconvolution')) {
            @imageconvolution($dst, [[0, -1, 0], [-1, 5, -1], [0, -1, 0]], 1, 0);
        }

        $dir = dirname($path);
        $assistPath = $dir . '/' . pathinfo($path, PATHINFO_FILENAME) . '_ocr_assist.jpg';
        if (!imagejpeg($dst, $assistPath, 92)) {
            imagedestroy($src);
            imagedestroy($dst);
            return null;
        }
        imagedestroy($src);
        imagedestroy($dst);

        [$width, $height] = self::imageSize($assistPath);
        $size = filesize($assistPath) ?: 0;
        $sha = hash_file('sha256', $assistPath) ?: '';

        try {
            $stmt = Db::branch()->prepare('INSERT INTO storage_files
                (company_uid, branch_uid, tenant_id, file_type, original_filename, stored_path, mime_type, file_size_bytes, sha256_hash, created_by, created_at)
                VALUES (:company_uid, :branch_uid, :tenant_id, :file_type, :original_filename, :stored_path, :mime_type, :file_size_bytes, :sha256_hash, :created_by, NOW())');
            $stmt->execute([
                ':company_uid' => current_company_uid(),
                ':branch_uid' => current_branch_uid(),
                ':tenant_id' => (int)$user['tenant_id'],
                ':file_type' => 'prescription_ocr_assist',
                ':original_filename' => mb_substr((string)($stored['original_filename'] ?? 'ocr_assist.jpg'), 0, 255),
                ':stored_path' => $assistPath,
                ':mime_type' => 'image/jpeg',
                ':file_size_bytes' => $size,
                ':sha256_hash' => $sha,
                ':created_by' => (int)$user['id'],
            ]);
            $storageFileId = (int)Db::branch()->lastInsertId();
        } catch (Throwable) {
            $storageFileId = 0;
        }

        return [
            'storage_file_id' => $storageFileId,
            'path' => $assistPath,
            'mime_type' => 'image/jpeg',
            'width' => $width,
            'height' => $height,
            'size' => $size,
            'sha256' => $sha,
            'original_filename' => (string)($stored['original_filename'] ?? ''),
            'quality_analysis' => $quality,
            'preprocess_profile' => (string)($quality['preprocess_profile'] ?? 'assist'),
        ];
    }

    /** @return array<string,mixed> */
    private static function sampleImageStats(string $path): array
    {
        if (!extension_loaded('gd')) {
            return [];
        }
        $src = self::loadGdImage($path, self::detectMime($path));
        if (!$src) {
            return [];
        }
        $srcW = imagesx($src);
        $srcH = imagesy($src);
        $max = 220;
        $scale = min(1.0, $max / max(1, max($srcW, $srcH)));
        $w = max(1, (int)round($srcW * $scale));
        $h = max(1, (int)round($srcH * $scale));
        $img = imagecreatetruecolor($w, $h);
        imagecopyresampled($img, $src, 0, 0, 0, 0, $w, $h, $srcW, $srcH);
        imagedestroy($src);

        $values = [];
        $sum = 0.0;
        $dark = 0;
        $light = 0;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($img, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $gray = (int)round($r * 0.299 + $g * 0.587 + $b * 0.114);
                $values[$y][$x] = $gray;
                $sum += $gray;
                if ($gray < 70) {
                    $dark++;
                }
                if ($gray > 215) {
                    $light++;
                }
            }
        }
        $count = max(1, $w * $h);
        $avg = $sum / $count;
        $variance = 0.0;
        $edge = 0.0;
        $edgeCount = 0;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $v = $values[$y][$x];
                $variance += ($v - $avg) ** 2;
                if ($x + 1 < $w) {
                    $edge += abs($v - $values[$y][$x + 1]);
                    $edgeCount++;
                }
                if ($y + 1 < $h) {
                    $edge += abs($v - $values[$y + 1][$x]);
                    $edgeCount++;
                }
            }
        }
        imagedestroy($img);
        return [
            'brightness_avg' => $avg,
            'contrast_stddev' => sqrt($variance / $count),
            'edge_strength' => $edge / max(1, $edgeCount),
            'dark_ratio' => $dark / $count,
            'light_ratio' => $light / $count,
        ];
    }

    private static function loadGdImage(string $path, string $mime)
    {
        return match ($mime) {
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => @imagecreatefromjpeg($path),
        } ?: false;
    }

    private static function resolutionBucket(int $longSide): string
    {
        if ($longSide <= 0) return 'unknown';
        if ($longSide < 1400) return 'low_res';
        if ($longSide < 2400) return 'mid_res';
        return 'high_res';
    }

    private static function shapeBucket(float $ratio): string
    {
        if ($ratio <= 0) return 'unknown';
        if ($ratio >= 1.30 && $ratio <= 1.55) return 'a4_like';
        if ($ratio < 1.30) return 'square_like';
        return 'long_or_cropped';
    }

    /** @param array<string,mixed> $analysis */
    private static function preprocessProfile(array $analysis): string
    {
        $profiles = [];
        if (($analysis['resolution_bucket'] ?? '') === 'low_res') $profiles[] = 'upscale';
        if (($analysis['brightness_bucket'] ?? '') !== 'normal') $profiles[] = 'brightness';
        if (($analysis['contrast_bucket'] ?? '') === 'low_contrast') $profiles[] = 'contrast';
        if (($analysis['blur_bucket'] ?? '') !== 'sharp_enough') $profiles[] = 'sharpen';
        if (($analysis['ink_bleed_risk'] ?? '') !== 'normal') $profiles[] = 'bleed_shadow_check';
        return $profiles ? implode('+', array_unique($profiles)) : 'none';
    }

    /** @return array{0:?int,1:?int} */
    public static function imageSize(string $path): array
    {
        $size = @getimagesize($path);
        if (!is_array($size)) {
            return [null, null];
        }
        return [(int)$size[0], (int)$size[1]];
    }

    public static function detectMime(string $path): string
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        return (string)$finfo->file($path);
    }
}

<?php
declare(strict_types=1);

/**
 * y_r07 + HOT9 + 一般名処方マスタを正規化した薬品辞書参照サービス。
 *
 * この辞書はモデル固有の重みではなく、処方箋OCR結果を補助する外部ナレッジとして使う。
 * 低性能モデルから高性能モデルへ切り替えても、同じ補助学習データを継続利用できるようにする。
 */
final class DrugDictionaryService
{
    private const DATA_DIR = __DIR__ . '/../data/drug_dictionary_normalized_20260703';

    /** @var array<string,array<string,mixed>> */
    private static array $productCacheByYj = [];

    /** @var array<string,array<string,mixed>> */
    private static array $productCacheById = [];

    /** @var array<string,array<string,mixed>>|null */
    private static ?array $summary = null;

    public static function available(): bool
    {
        return is_file(self::DATA_DIR . '/aliases.jsonl') && is_file(self::DATA_DIR . '/product_index.jsonl');
    }

    /** @return array<string,mixed> */
    public static function summary(): array
    {
        if (self::$summary !== null) {
            return self::$summary;
        }
        $path = self::DATA_DIR . '/summary.json';
        if (!is_file($path)) {
            self::$summary = [];
            return self::$summary;
        }
        $json = json_decode((string)file_get_contents($path), true);
        self::$summary = is_array($json) ? $json : [];
        return self::$summary;
    }

    public static function normalizeText(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $value = mb_convert_kana($value, 'asKV', 'UTF-8');
        $value = str_replace(['【般】', '[般]', '般）', '般:', '般名'], ['般', '般', '般', '般', '般'], $value);
        $value = str_replace(['ｍｇ', 'ＭＧ', 'mg.', 'MG.'], ['mg', 'mg', 'mg', 'mg'], $value);
        $value = preg_replace('/[\s　\t\r\n]+/u', '', $value) ?? $value;
        $value = preg_replace('/[（）()「」『』【】\[\]{}]/u', '', $value) ?? $value;
        return mb_strtolower($value, 'UTF-8');
    }

    private static function normalizeLoose(string $value): string
    {
        $value = self::normalizeText($value);
        // OCR/手書きで揺れやすい記号・英数字を候補比較用に寄せる。
        $value = strtr($value, [
            '0' => 'o',
            'Ｏ' => 'o',
            '０' => 'o',
            'Ⅰ' => 'i',
            'ｌ' => 'l',
            'ー' => '-',
            '－' => '-',
            '―' => '-',
            'ｰ' => '-',
            '％' => '%',
        ]);
        return $value;
    }

    /**
     * OCRで読んだ薬品名・一般名・商品名から辞書候補を返す。
     *
     * @return array<int,array<string,mixed>>
     */
    public static function findCandidates(string $text, int $limit = 8): array
    {
        $text = trim($text);
        if ($text === '' || !self::available()) {
            return [];
        }

        $normalized = self::normalizeText($text);
        $loose = self::normalizeLoose($text);
        if ($normalized === '') {
            return [];
        }

        $matches = [];
        $aliasPath = self::DATA_DIR . '/aliases.jsonl';
        $fh = @fopen($aliasPath, 'rb');
        if (!$fh) {
            return [];
        }

        while (($line = fgets($fh)) !== false) {
            $row = json_decode($line, true);
            if (!is_array($row)) {
                continue;
            }
            $alias = trim((string)($row['alias'] ?? ''));
            $aliasNormalized = trim((string)($row['alias_normalized'] ?? ''));
            if ($aliasNormalized === '') {
                $aliasNormalized = self::normalizeText($alias);
            } else {
                $aliasNormalized = self::normalizeText($aliasNormalized);
            }
            if ($aliasNormalized === '') {
                continue;
            }

            $score = self::matchScore($normalized, $loose, $aliasNormalized);
            if ($score <= 0) {
                continue;
            }

            $targetCode = (string)($row['target_code'] ?? '');
            $productId = (string)($row['product_id'] ?? '');
            $key = ($targetCode !== '' ? $targetCode : $productId) . '|' . (string)($row['generic_code'] ?? '') . '|' . (string)($row['alias_type'] ?? '');
            if (!isset($matches[$key]) || $score > (float)($matches[$key]['score'] ?? 0)) {
                $matches[$key] = [
                    'alias' => $alias,
                    'alias_normalized' => $aliasNormalized,
                    'alias_type' => (string)($row['alias_type'] ?? ''),
                    'target_code' => $targetCode,
                    'product_id' => $productId,
                    'generic_code' => (string)($row['generic_code'] ?? ''),
                    'score' => $score,
                ];
            }

            if (count($matches) > max($limit * 8, 80)) {
                // 完全一致候補が十分ある場合は早めに止める。曖昧候補しかない場合は最後まで見る。
                $hasExact = false;
                foreach ($matches as $m) {
                    if ((float)$m['score'] >= 98.0) {
                        $hasExact = true;
                        break;
                    }
                }
                if ($hasExact) {
                    break;
                }
            }
        }
        fclose($fh);

        if (!$matches) {
            return [];
        }
        usort($matches, static fn(array $a, array $b): int => ((float)($b['score'] ?? 0) <=> (float)($a['score'] ?? 0)));
        $matches = array_slice($matches, 0, max($limit * 3, 12));

        $results = [];
        foreach ($matches as $match) {
            $product = [];
            if ((string)$match['target_code'] !== '') {
                $product = self::productByYjCode((string)$match['target_code']);
            }
            if (!$product && (string)$match['product_id'] !== '') {
                $product = self::productById((string)$match['product_id']);
            }

            $drugName = (string)($product['drug_name'] ?? $product['display_name'] ?? $match['alias'] ?? '');
            $genericName = (string)($product['generic_name'] ?? '');
            $genericCode = (string)($product['generic_code'] ?? $match['generic_code'] ?? '');
            $confidence = self::confidenceLabel((float)$match['score'], (string)($product['generic_relation_match_type'] ?? ''));

            $results[] = [
                'drug_name' => $drugName,
                'display_name' => (string)($product['display_name'] ?? $drugName),
                'alias_name' => (string)$match['alias'],
                'alias_type' => (string)$match['alias_type'],
                'score' => (float)$match['score'],
                'candidate_source' => 'hot_yj_generic_dictionary',
                'yj_code' => (string)($product['yj_code'] ?? $match['target_code'] ?? ''),
                'yj_prefix8' => (string)($product['yj_prefix8'] ?? ''),
                'yj_prefix9' => (string)($product['yj_prefix9'] ?? ''),
                'yj_suffix3' => (string)($product['yj_suffix3'] ?? ''),
                'hot9_code' => (string)($product['hot9_code'] ?? ''),
                'hot7_code' => (string)($product['hot7_code'] ?? ''),
                'generic_code' => $genericCode,
                'generic_prefix8' => (string)($product['generic_prefix8'] ?? ($genericCode !== '' ? substr($genericCode, 0, 8) : '')),
                'generic_prefix9' => (string)($product['generic_prefix9'] ?? ($genericCode !== '' ? substr($genericCode, 0, 9) : '')),
                'generic_name' => $genericName,
                'generic_name_plain' => (string)($product['generic_name_plain'] ?? preg_replace('/^【般】/u', '', $genericName)),
                'brand_class' => (string)($product['brand_class'] ?? 'unknown'),
                'unit' => (string)($product['unit'] ?? ''),
                'manufacturer' => (string)($product['manufacturer'] ?? ''),
                'seller' => (string)($product['seller'] ?? ''),
                'relation_confidence' => $confidence,
                'relation_match_type' => (string)($product['generic_relation_match_type'] ?? ''),
                'reason' => self::candidateReason($match, $product, $confidence),
            ];
        }

        usort($results, static fn(array $a, array $b): int => ((float)$b['score'] <=> (float)$a['score']));

        $seen = [];
        $out = [];
        foreach ($results as $row) {
            $key = (string)($row['yj_code'] ?? '') . '|' . (string)($row['generic_code'] ?? '') . '|' . (string)($row['drug_name'] ?? '');
            if ($key === '||' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $row;
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    private static function matchScore(string $normalizedInput, string $looseInput, string $aliasNormalized): float
    {
        if ($normalizedInput === $aliasNormalized) {
            return 100.0;
        }
        $aliasLoose = self::normalizeLoose($aliasNormalized);
        if ($looseInput !== '' && $looseInput === $aliasLoose) {
            return 97.0;
        }

        $inputLen = mb_strlen($normalizedInput);
        $aliasLen = mb_strlen($aliasNormalized);
        if ($inputLen >= 4 && $aliasLen >= 4) {
            if (str_contains($aliasNormalized, $normalizedInput)) {
                return min(94.0, 78.0 + ($inputLen / max(1, $aliasLen)) * 18.0);
            }
            if (str_contains($normalizedInput, $aliasNormalized)) {
                return min(90.0, 74.0 + ($aliasLen / max(1, $inputLen)) * 16.0);
            }
            if ($aliasLoose !== '' && str_contains($aliasLoose, $looseInput)) {
                return 84.0;
            }
            if ($looseInput !== '' && str_contains($looseInput, $aliasLoose)) {
                return 80.0;
            }
        }

        // 規格違いを含む薬品名の先頭一致を候補として拾う。短い文字列では誤候補が増えるため弱め。
        if ($inputLen >= 6 && $aliasLen >= 6) {
            $prefix = mb_substr($normalizedInput, 0, min(6, $inputLen));
            if ($prefix !== '' && str_starts_with($aliasNormalized, $prefix)) {
                return 68.0;
            }
        }
        return 0.0;
    }

    /** @return array<string,mixed> */
    public static function productByYjCode(string $yjCode): array
    {
        $yjCode = trim($yjCode);
        if ($yjCode === '') {
            return [];
        }
        if (isset(self::$productCacheByYj[$yjCode])) {
            return self::$productCacheByYj[$yjCode];
        }
        return self::scanProductIndex(static fn(array $row): bool => (string)($row['yj_code'] ?? '') === $yjCode, $yjCode, 'yj');
    }

    /** @return array<string,mixed> */
    public static function productById(string $productId): array
    {
        $productId = trim($productId);
        if ($productId === '') {
            return [];
        }
        if (isset(self::$productCacheById[$productId])) {
            return self::$productCacheById[$productId];
        }
        return self::scanProductIndex(static fn(array $row): bool => (string)($row['product_id'] ?? '') === $productId, $productId, 'id');
    }

    /** @return array<string,mixed> */
    private static function scanProductIndex(callable $predicate, string $cacheKey, string $cacheType): array
    {
        $path = self::DATA_DIR . '/product_index.jsonl';
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            return [];
        }
        while (($line = fgets($fh)) !== false) {
            $row = json_decode($line, true);
            if (!is_array($row)) {
                continue;
            }
            if ($predicate($row)) {
                fclose($fh);
                if ($cacheType === 'yj') {
                    self::$productCacheByYj[$cacheKey] = $row;
                } else {
                    self::$productCacheById[$cacheKey] = $row;
                }
                if (!empty($row['yj_code'])) {
                    self::$productCacheByYj[(string)$row['yj_code']] = $row;
                }
                if (!empty($row['product_id'])) {
                    self::$productCacheById[(string)$row['product_id']] = $row;
                }
                return $row;
            }
        }
        fclose($fh);
        return [];
    }

    private static function confidenceLabel(float $score, string $matchType): string
    {
        if ($score >= 97.0 && $matchType === 'exact_yj9_to_generic') {
            return 'high';
        }
        if ($score >= 90.0) {
            return 'medium_high';
        }
        if ($score >= 78.0) {
            return 'medium';
        }
        return 'low';
    }

    /** @param array<string,mixed> $match @param array<string,mixed> $product */
    private static function candidateReason(array $match, array $product, string $confidence): string
    {
        $aliasType = (string)($match['alias_type'] ?? '');
        $yj = (string)($product['yj_code'] ?? $match['target_code'] ?? '');
        $generic = (string)($product['generic_name'] ?? '');
        $hot9 = (string)($product['hot9_code'] ?? '');
        $parts = ['辞書照合: ' . ($aliasType !== '' ? $aliasType : 'alias') . '一致'];
        if ($yj !== '') {
            $parts[] = 'YJ=' . $yj;
        }
        if ($hot9 !== '') {
            $parts[] = 'HOT9=' . $hot9;
        }
        if ($generic !== '') {
            $parts[] = '一般名=' . $generic;
        }
        $parts[] = '信頼=' . $confidence;
        return implode(' / ', $parts);
    }

    /**
     * 人間確定後の薬品名と辞書を照合し、補助学習DBに保存する候補情報を返す。
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function matchConfirmedMedication(array $row): array
    {
        $texts = [
            (string)($row['final_drug_name'] ?? ''),
            (string)($row['final_brand_name'] ?? ''),
            (string)($row['final_generic_name'] ?? ''),
            (string)($row['ai_drug_name'] ?? ''),
            (string)($row['ai_raw_drug_text'] ?? ''),
        ];
        foreach ($texts as $text) {
            $candidates = self::findCandidates($text, 5);
            if ($candidates) {
                return [
                    'query' => $text,
                    'best' => $candidates[0],
                    'candidates' => $candidates,
                ];
            }
        }
        return ['query' => '', 'best' => [], 'candidates' => []];
    }

    public static function promptPolicyText(): string
    {
        $summary = self::summary();
        $counts = is_array($summary['counts'] ?? null) ? $summary['counts'] : [];
        $productCount = (int)($counts['product_index_rows'] ?? 0);
        $genericGroupCount = (int)($counts['merged_generic_groups'] ?? 0);
        $prefix = self::available()
            ? '薬品辞書あり（商品/販売名 約' . $productCount . '件、一般名グループ 約' . $genericGroupCount . '件）。'
            : '薬品辞書未配置。';
        return $prefix . '薬品名が読みにくい場合は推測で確定せず、raw_drug_textへ見えた文字を残し、drug_name/generic_name/brand_nameは候補として分離する。一般名コードとYJ/HOT9の紐づけは後処理辞書で候補提示するため、画像上の薬品行・規格・用法・日数を混ぜずに分ける。';
    }
}

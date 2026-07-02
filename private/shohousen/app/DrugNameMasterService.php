<?php
declare(strict_types=1);

/**
 * 一般名処方マスタ参照サービス。
 *
 * OpenAI APIはサーバー上のPDFを直接参照できないため、PDFから抽出したJSONを
 * 軽量辞書として参照する。ここでは主に「この文字列は一般名処方名/成分名らしい」
 * という判定に使い、商品名との確定紐づけは人間確認・補助学習DBへ委ねる。
 */
final class DrugNameMasterService
{
    private const DEFAULT_JSON = __DIR__ . '/../data/generic_name_master_210618.json';

    /** @var array<int,array<string,string>>|null */
    private static ?array $entries = null;

    /** @return array<int,array<string,string>> */
    private static function entries(): array
    {
        if (self::$entries !== null) {
            return self::$entries;
        }
        self::$entries = [];
        if (!is_file(self::DEFAULT_JSON)) {
            return self::$entries;
        }
        $json = json_decode((string)file_get_contents(self::DEFAULT_JSON), true);
        $rows = is_array($json['entries'] ?? null) ? $json['entries'] : [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string)($row['generic_prescription_name'] ?? ''));
            $ingredient = trim((string)($row['ingredient_name'] ?? ''));
            if ($name === '' && $ingredient === '') {
                continue;
            }
            self::$entries[] = [
                'code' => (string)($row['code'] ?? ''),
                'category' => (string)($row['category'] ?? ''),
                'generic_prescription_name' => $name,
                'ingredient_name' => $ingredient,
                'spec' => (string)($row['spec'] ?? ''),
            ];
        }
        return self::$entries;
    }

    private static function normalizeText(string $value): string
    {
        $value = mb_convert_kana($value, 'asKV', 'UTF-8');
        $value = str_replace(['【般】', '[般]', '般）', '般:'], '般', $value);
        $value = preg_replace('/\s+/u', '', $value) ?? $value;
        return mb_strtolower($value, 'UTF-8');
    }

    /**
     * 与えられた薬品名関連テキストに含まれる一般名処方マスタ候補を返す。
     *
     * @return array<int,array<string,mixed>>
     */
    public static function findGenericCandidates(string $text, int $limit = 5): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        $normalizedText = self::normalizeText($text);
        $results = [];
        foreach (self::entries() as $entry) {
            $genericName = self::normalizeText((string)$entry['generic_prescription_name']);
            $ingredient = self::normalizeText((string)$entry['ingredient_name']);
            $score = 0;
            if ($genericName !== '' && str_contains($normalizedText, $genericName)) {
                $score = 100;
            } elseif ($ingredient !== '' && str_contains($normalizedText, $ingredient)) {
                $score = 80;
            } else {
                // 規格違い・空白違いを拾いやすくするため、短すぎない薬効成分名だけ緩く見る。
                if ($ingredient !== '' && mb_strlen($ingredient) >= 5) {
                    $needle = mb_substr($ingredient, 0, 5);
                    if (str_contains($normalizedText, $needle)) {
                        $score = 55;
                    }
                }
            }
            if ($score <= 0) {
                continue;
            }
            $results[] = $entry + ['match_score' => $score];
        }
        usort($results, static fn(array $a, array $b): int => ((int)$b['match_score'] <=> (int)$a['match_score']));
        return array_slice($results, 0, $limit);
    }

    /**
     * OpenAI結果の薬品行に、一般名処方マスタの候補を補助的に付与する。
     * 確定はせず、人間確認しやすい候補として整形する。
     *
     * @param array<string,mixed> $med
     * @return array<string,mixed>
     */
    public static function enrichMedication(array $med): array
    {
        $text = implode("\n", array_filter([
            (string)($med['raw_drug_text'] ?? ''),
            (string)($med['drug_name'] ?? ''),
            (string)($med['generic_name'] ?? ''),
            (string)($med['brand_name'] ?? ''),
        ], static fn($v) => trim($v) !== ''));
        $candidates = self::findGenericCandidates($text, 3);
        if (!$candidates) {
            return $med;
        }
        $best = $candidates[0];
        if (trim((string)($med['generic_name'] ?? '')) === '') {
            $med['generic_name'] = (string)($best['generic_prescription_name'] ?? '');
        }
        if (($med['name_relation'] ?? 'unknown') === 'unknown' && trim((string)($med['drug_name'] ?? '')) !== '' && trim((string)($med['generic_name'] ?? '')) !== '') {
            $med['name_relation'] = 'generic_brand_pair';
        }
        $med['_generic_master_candidates'] = $candidates;
        return $med;
    }
}

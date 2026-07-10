-- phpMyAdmin SQL Dump
-- version 5.2.1-1.el8.remi
-- https://www.phpmyadmin.net/
--
-- ホスト: localhost
-- 生成日時: 2026 年 7 月 10 日 14:38
-- サーバのバージョン： 10.5.27-MariaDB-log
-- PHP のバージョン: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- データベース: `inprof3_assistantdata`
--

-- --------------------------------------------------------

--
-- テーブルの構造 `drug_aliases`
--

CREATE TABLE `drug_aliases` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `drug_master_id` bigint(20) UNSIGNED NOT NULL,
  `alias_name` varchar(255) NOT NULL,
  `alias_type` varchar(60) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- テーブルの構造 `drug_master`
--

CREATE TABLE `drug_master` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `drug_code` varchar(80) DEFAULT NULL,
  `drug_name` varchar(255) NOT NULL,
  `normalized_name` varchar(255) DEFAULT NULL,
  `manufacturer` varchar(160) DEFAULT NULL,
  `unit` varchar(40) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- テーブルの構造 `drug_name_relation_observations`
--

CREATE TABLE `drug_name_relation_observations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_uid` varchar(80) NOT NULL,
  `branch_uid` varchar(80) NOT NULL,
  `tenant_id` bigint(20) UNSIGNED DEFAULT NULL,
  `parse_job_id` bigint(20) UNSIGNED DEFAULT NULL,
  `prescription_id` bigint(20) UNSIGNED DEFAULT NULL,
  `medication_sort_order` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `ai_drug_name` varchar(255) DEFAULT NULL,
  `final_drug_name` varchar(255) DEFAULT NULL,
  `ai_generic_name` varchar(255) DEFAULT NULL,
  `final_generic_name` varchar(255) DEFAULT NULL,
  `ai_brand_name` varchar(255) DEFAULT NULL,
  `final_brand_name` varchar(255) DEFAULT NULL,
  `ai_raw_drug_text` text DEFAULT NULL,
  `final_raw_drug_text` text DEFAULT NULL,
  `relation_type` varchar(60) NOT NULL DEFAULT 'unknown',
  `action_type` varchar(60) NOT NULL DEFAULT 'confirmed',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- テーブルの構造 `drug_name_relation_preferences`
--

CREATE TABLE `drug_name_relation_preferences` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_uid` varchar(80) NOT NULL,
  `branch_uid` varchar(80) NOT NULL,
  `pair_key` char(40) NOT NULL,
  `generic_name` varchar(255) NOT NULL DEFAULT '',
  `brand_name` varchar(255) NOT NULL DEFAULT '',
  `display_drug_name` varchar(255) NOT NULL DEFAULT '',
  `raw_example` text DEFAULT NULL,
  `observed_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `confirmed_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `edited_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `last_seen_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- テーブルの構造 `knowledge_branch_refs`
--

CREATE TABLE `knowledge_branch_refs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_uid` varchar(80) NOT NULL,
  `branch_uid` varchar(80) NOT NULL,
  `display_name` varchar(160) DEFAULT NULL,
  `app_branch_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- テーブルの構造 `knowledge_company_refs`
--

CREATE TABLE `knowledge_company_refs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_uid` varchar(80) NOT NULL,
  `display_name` varchar(160) DEFAULT NULL,
  `app_company_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- テーブルの構造 `knowledge_import_logs`
--

CREATE TABLE `knowledge_import_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `import_type` varchar(80) NOT NULL,
  `source_name` varchar(255) DEFAULT NULL,
  `status` varchar(40) NOT NULL,
  `detail_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- テーブルの構造 `knowledge_versions`
--

CREATE TABLE `knowledge_versions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `version_key` varchar(120) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- テーブルの構造 `prescription_auto_correction_rules`
--

CREATE TABLE `prescription_auto_correction_rules` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_uid` varchar(80) DEFAULT NULL,
  `branch_uid` varchar(80) DEFAULT NULL,
  `scope_type` enum('branch','company','global') NOT NULL DEFAULT 'branch',
  `field_type` varchar(80) NOT NULL,
  `wrong_value` varchar(255) NOT NULL,
  `correct_value` varchar(255) NOT NULL,
  `support_count` int(11) NOT NULL DEFAULT 1,
  `success_count` int(11) NOT NULL DEFAULT 0,
  `failure_count` int(11) NOT NULL DEFAULT 0,
  `precision_rate` decimal(6,2) DEFAULT NULL,
  `min_score` decimal(6,2) NOT NULL DEFAULT 80.00,
  `evaluation_status` enum('candidate','active','disabled') NOT NULL DEFAULT 'candidate',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- テーブルのデータのダンプ `prescription_auto_correction_rules`
--

INSERT INTO `prescription_auto_correction_rules` (`id`, `company_uid`, `branch_uid`, `scope_type`, `field_type`, `wrong_value`, `correct_value`, `support_count`, `success_count`, `failure_count`, `precision_rate`, `min_score`, `evaluation_status`, `is_active`, `created_at`, `updated_at`) VALUES
(7, 'cmp_0001', 'br_0001', 'branch', 'drug_name', 'ペリル酸類似物質', 'ペリル類似物質外用液0.3%(水性)', 1, 1, 0, 54.30, 77.57, 'candidate', 0, '2026-07-03 17:07:03', '2026-07-03 17:07:03'),
(8, 'cmp_0001', 'br_0001', 'branch', 'usage_text', '1日に1〜2回塗布', '頭に1日に1〜2回塗布', 1, 1, 0, 58.30, 75.77, 'candidate', 0, '2026-07-03 17:07:03', '2026-07-03 17:07:03'),
(9, 'cmp_0001', 'br_0001', 'branch', 'drug_name', 'ペリル酸類似物質', 'ヘパリン類似物質外用液0.3%(乳剤性)', 1, 1, 0, 54.30, 77.57, 'candidate', 0, '2026-07-03 17:07:03', '2026-07-03 17:07:03'),
(10, 'cmp_0001', 'br_0001', 'branch', 'usage_text', '1日に2〜3回塗布', '乾燥部位に1日2〜3回', 1, 1, 0, 48.30, 80.27, 'candidate', 0, '2026-07-03 17:07:03', '2026-07-03 17:07:03');

-- --------------------------------------------------------

--
-- テーブルの構造 `prescription_branch_field_preferences`
--

CREATE TABLE `prescription_branch_field_preferences` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_uid` varchar(80) NOT NULL,
  `branch_uid` varchar(80) NOT NULL,
  `field_key` varchar(120) NOT NULL,
  `field_label` varchar(160) NOT NULL,
  `field_group` enum('patient','insurance','public_expense','prescription','medical_institution','medication','pharmacy','note','qr','other') NOT NULL DEFAULT 'other',
  `include_default` tinyint(1) NOT NULL DEFAULT 0,
  `selected_count` int(11) NOT NULL DEFAULT 0,
  `unselected_count` int(11) NOT NULL DEFAULT 0,
  `last_value_sample` varchar(255) DEFAULT NULL,
  `last_seen_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- テーブルの構造 `prescription_canonical_fields`
--

CREATE TABLE `prescription_canonical_fields` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `field_key` varchar(120) NOT NULL,
  `display_name` varchar(160) NOT NULL,
  `field_group` varchar(80) NOT NULL,
  `data_type` varchar(40) NOT NULL DEFAULT 'string',
  `is_array` tinyint(1) NOT NULL DEFAULT 0,
  `risk_level` enum('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `synonyms_json` longtext DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- テーブルの構造 `prescription_confirmed_correction_events`
--

CREATE TABLE `prescription_confirmed_correction_events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_uid` varchar(80) NOT NULL,
  `branch_uid` varchar(80) NOT NULL,
  `tenant_id` bigint(20) UNSIGNED DEFAULT NULL,
  `parse_job_id` bigint(20) UNSIGNED DEFAULT NULL,
  `field_key` varchar(120) NOT NULL,
  `field_label` varchar(160) NOT NULL,
  `field_group` enum('patient','insurance','public_expense','prescription','medical_institution','medication','pharmacy','note','qr','other') NOT NULL DEFAULT 'other',
  `source_ai_value` text DEFAULT NULL,
  `final_value` text DEFAULT NULL,
  `normalized_ai_value` varchar(255) DEFAULT NULL,
  `normalized_final_value` varchar(255) DEFAULT NULL,
  `correction_type` enum('confirmed','edited','added','emptied') NOT NULL DEFAULT 'confirmed',
  `correction_score` decimal(6,2) NOT NULL DEFAULT 0.00,
  `confidence` decimal(6,2) DEFAULT NULL,
  `needs_human_check` tinyint(1) NOT NULL DEFAULT 0,
  `sample_risk_level` enum('low','medium','high') NOT NULL DEFAULT 'medium',
  `prompt_hint` text DEFAULT NULL,
  `content_hash` char(40) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- テーブルの構造 `prescription_confirmed_correction_scores`
--

CREATE TABLE `prescription_confirmed_correction_scores` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_uid` varchar(80) NOT NULL,
  `branch_uid` varchar(80) NOT NULL,
  `field_key` varchar(120) NOT NULL,
  `field_label` varchar(160) NOT NULL,
  `field_group` enum('patient','insurance','public_expense','prescription','medical_institution','medication','pharmacy','note','qr','other') NOT NULL DEFAULT 'other',
  `sample_risk_level` enum('low','medium','high') NOT NULL DEFAULT 'medium',
  `observed_count` int(11) NOT NULL DEFAULT 0,
  `edited_count` int(11) NOT NULL DEFAULT 0,
  `added_count` int(11) NOT NULL DEFAULT 0,
  `confirmed_count` int(11) NOT NULL DEFAULT 0,
  `empty_count` int(11) NOT NULL DEFAULT 0,
  `score_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `avg_score` decimal(6,2) NOT NULL DEFAULT 0.00,
  `accuracy_rate` decimal(6,2) NOT NULL DEFAULT 0.00,
  `correction_rate` decimal(6,2) NOT NULL DEFAULT 0.00,
  `miss_rate` decimal(6,2) NOT NULL DEFAULT 0.00,
  `overdetect_rate` decimal(6,2) NOT NULL DEFAULT 0.00,
  `prompt_weight` decimal(6,2) NOT NULL DEFAULT 0.00,
  `use_for_prompt` tinyint(1) NOT NULL DEFAULT 0,
  `last_ai_value_sample` varchar(255) DEFAULT NULL,
  `last_final_value_sample` varchar(255) DEFAULT NULL,
  `last_correction_type` enum('confirmed','edited','added','emptied') DEFAULT NULL,
  `prompt_hint` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_seen_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- テーブルの構造 `prescription_correction_rule_events`
--

CREATE TABLE `prescription_correction_rule_events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `rule_id` bigint(20) UNSIGNED NOT NULL,
  `parse_job_id` bigint(20) UNSIGNED DEFAULT NULL,
  `field_path` varchar(255) DEFAULT NULL,
  `applied_value` text DEFAULT NULL,
  `final_value` text DEFAULT NULL,
  `was_correct` tinyint(1) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- テーブルの構造 `prescription_drug_dictionary_candidate_events`
--

CREATE TABLE `prescription_drug_dictionary_candidate_events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_uid` varchar(64) NOT NULL,
  `branch_uid` varchar(64) NOT NULL,
  `tenant_id` bigint(20) UNSIGNED DEFAULT NULL,
  `parse_job_id` bigint(20) UNSIGNED DEFAULT NULL,
  `prescription_id` bigint(20) UNSIGNED DEFAULT NULL,
  `medication_sort_order` int(11) DEFAULT NULL,
  `ai_drug_name` varchar(255) DEFAULT NULL,
  `final_drug_name` varchar(255) DEFAULT NULL,
  `final_generic_name` varchar(255) DEFAULT NULL,
  `final_brand_name` varchar(255) DEFAULT NULL,
  `selected_yj_code` varchar(32) DEFAULT NULL,
  `selected_hot9_code` varchar(32) DEFAULT NULL,
  `selected_generic_code` varchar(32) DEFAULT NULL,
  `selected_generic_name` varchar(255) DEFAULT NULL,
  `dictionary_score` decimal(6,2) DEFAULT NULL,
  `relation_confidence` varchar(64) DEFAULT NULL,
  `action_type` varchar(32) NOT NULL DEFAULT 'confirmed',
  `query_text` varchar(255) DEFAULT NULL,
  `candidate_json` mediumtext DEFAULT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- テーブルの構造 `prescription_drug_dictionary_learning_scores`
--

CREATE TABLE `prescription_drug_dictionary_learning_scores` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_uid` varchar(64) NOT NULL,
  `branch_uid` varchar(64) NOT NULL,
  `dictionary_key` varchar(160) NOT NULL,
  `yj_code` varchar(32) DEFAULT NULL,
  `hot9_code` varchar(32) DEFAULT NULL,
  `generic_code` varchar(32) DEFAULT NULL,
  `generic_name` varchar(255) DEFAULT NULL,
  `observed_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `confirmed_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `edited_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `merged_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `avg_dictionary_score` decimal(6,2) NOT NULL DEFAULT 0.00,
  `last_query_text` varchar(255) DEFAULT NULL,
  `last_seen_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- テーブルの構造 `prescription_field_learning_scores`
--

CREATE TABLE `prescription_field_learning_scores` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_uid` varchar(80) NOT NULL,
  `branch_uid` varchar(80) NOT NULL,
  `field_key` varchar(120) NOT NULL,
  `field_label` varchar(160) NOT NULL,
  `field_group` enum('patient','insurance','public_expense','prescription','medical_institution','medication','pharmacy','note','qr','other') NOT NULL DEFAULT 'other',
  `score_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `score_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `avg_score` decimal(6,2) NOT NULL DEFAULT 0.00,
  `selected_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `unselected_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `edited_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `empty_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `last_confidence` decimal(6,2) DEFAULT NULL,
  `last_ai_value_sample` varchar(255) DEFAULT NULL,
  `last_final_value_sample` varchar(255) DEFAULT NULL,
  `last_action_type` enum('confirmed','edited','added','unselected','empty') NOT NULL DEFAULT 'confirmed',
  `last_seen_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- テーブルの構造 `prescription_field_observations`
--

CREATE TABLE `prescription_field_observations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_uid` varchar(80) NOT NULL,
  `branch_uid` varchar(80) NOT NULL,
  `tenant_id` bigint(20) UNSIGNED DEFAULT NULL,
  `parse_job_id` bigint(20) UNSIGNED DEFAULT NULL,
  `prescription_id` bigint(20) UNSIGNED DEFAULT NULL,
  `field_key` varchar(120) NOT NULL,
  `field_label` varchar(160) NOT NULL,
  `field_group` enum('patient','insurance','public_expense','prescription','medical_institution','medication','pharmacy','note','qr','other') NOT NULL DEFAULT 'other',
  `field_value` text DEFAULT NULL,
  `source_ai_value` text DEFAULT NULL,
  `source_section` varchar(160) DEFAULT NULL,
  `confidence` decimal(6,2) DEFAULT NULL,
  `needs_human_check` tinyint(1) NOT NULL DEFAULT 0,
  `is_selected` tinyint(1) NOT NULL DEFAULT 0,
  `include_for_output` tinyint(1) NOT NULL DEFAULT 0,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- テーブルの構造 `prescription_image_quality_learning_scores`
--

CREATE TABLE `prescription_image_quality_learning_scores` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_uid` varchar(80) NOT NULL,
  `branch_uid` varchar(80) NOT NULL,
  `quality_bucket` varchar(120) NOT NULL,
  `issue_flags` varchar(255) NOT NULL DEFAULT '',
  `brightness_bucket` varchar(80) DEFAULT NULL,
  `contrast_bucket` varchar(80) DEFAULT NULL,
  `blur_bucket` varchar(80) DEFAULT NULL,
  `ink_bleed_risk` varchar(80) DEFAULT NULL,
  `estimated_text_size_bucket` varchar(80) DEFAULT NULL,
  `preprocess_profile` varchar(160) DEFAULT NULL,
  `quality_json` text DEFAULT NULL,
  `layout_fingerprint` char(64) DEFAULT NULL,
  `observed_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `correction_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `correction_rate` decimal(6,2) NOT NULL DEFAULT 0.00,
  `last_width` int(11) DEFAULT NULL,
  `last_height` int(11) DEFAULT NULL,
  `last_file_size_bytes` bigint(20) UNSIGNED DEFAULT NULL,
  `last_parse_job_id` bigint(20) UNSIGNED DEFAULT NULL,
  `last_seen_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- テーブルの構造 `prescription_layout_field_learning_scores`
--

CREATE TABLE `prescription_layout_field_learning_scores` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_uid` varchar(80) NOT NULL,
  `branch_uid` varchar(80) NOT NULL,
  `tenant_id` bigint(20) UNSIGNED DEFAULT NULL,
  `layout_fingerprint` char(64) NOT NULL,
  `quality_bucket` varchar(80) NOT NULL DEFAULT 'unknown',
  `field_key` varchar(120) NOT NULL,
  `field_label` varchar(160) NOT NULL,
  `field_group` enum('patient','insurance','public_expense','prescription','medical_institution','medication','pharmacy','note','qr','other') NOT NULL DEFAULT 'other',
  `source_section` varchar(160) DEFAULT NULL,
  `observed_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `edited_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `added_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `empty_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `confirmed_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `score_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `avg_score` decimal(6,2) NOT NULL DEFAULT 0.00,
  `correction_rate` decimal(6,2) NOT NULL DEFAULT 0.00,
  `miss_rate` decimal(6,2) NOT NULL DEFAULT 0.00,
  `overdetect_rate` decimal(6,2) NOT NULL DEFAULT 0.00,
  `last_ai_value_sample` varchar(255) DEFAULT NULL,
  `last_final_value_sample` varchar(255) DEFAULT NULL,
  `confidence_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `confidence_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `avg_confidence` decimal(6,2) NOT NULL DEFAULT 0.00,
  `display_order_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `display_order_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `avg_display_order` decimal(8,2) NOT NULL DEFAULT 0.00,
  `last_seen_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- テーブルの構造 `prescription_learning_error_logs`
--

CREATE TABLE `prescription_learning_error_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_uid` varchar(80) NOT NULL,
  `branch_uid` varchar(80) NOT NULL,
  `area` varchar(120) NOT NULL,
  `error_class` varchar(160) DEFAULT NULL,
  `error_message` varchar(1000) DEFAULT NULL,
  `context_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- テーブルの構造 `prescription_ocr_pipeline_traces`
--

CREATE TABLE `prescription_ocr_pipeline_traces` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_uid` varchar(64) NOT NULL,
  `branch_uid` varchar(64) NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `parse_job_id` bigint(20) UNSIGNED NOT NULL,
  `prescription_id` bigint(20) UNSIGNED DEFAULT NULL,
  `stage` varchar(64) NOT NULL COMMENT 'image_preprocess/openai_raw_response/openai_normalized_before_correction/normalized_after_correction/write_confirmed_payload',
  `source_kind` varchar(64) NOT NULL COMMENT 'read/write',
  `model_name` varchar(120) DEFAULT NULL,
  `layout_fingerprint` varchar(191) DEFAULT NULL,
  `quality_bucket` varchar(64) DEFAULT NULL,
  `payload_hash` char(64) NOT NULL,
  `payload_bytes` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `payload_json` mediumtext NOT NULL,
  `meta_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta_json`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- テーブルの構造 `prescription_output_field_mappings`
--

CREATE TABLE `prescription_output_field_mappings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `output_template_id` bigint(20) UNSIGNED NOT NULL,
  `output_field_id` bigint(20) UNSIGNED DEFAULT NULL,
  `canonical_field_key` varchar(120) NOT NULL,
  `mapping_type` enum('auto','manual','rule') NOT NULL DEFAULT 'auto',
  `confidence` decimal(6,2) NOT NULL DEFAULT 0.00,
  `transform_rule_json` longtext DEFAULT NULL,
  `confirmed_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `confirmed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- テーブルの構造 `prescription_output_mapping_feedback`
--

CREATE TABLE `prescription_output_mapping_feedback` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_uid` varchar(80) NOT NULL,
  `branch_uid` varchar(80) DEFAULT NULL,
  `output_label` varchar(160) NOT NULL,
  `guessed_canonical_field_key` varchar(120) DEFAULT NULL,
  `final_canonical_field_key` varchar(120) NOT NULL,
  `was_correct` tinyint(1) NOT NULL DEFAULT 0,
  `confidence_before` decimal(6,2) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- テーブルの構造 `prescription_output_templates`
--

CREATE TABLE `prescription_output_templates` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_uid` varchar(80) DEFAULT NULL,
  `branch_uid` varchar(80) DEFAULT NULL,
  `template_name` varchar(160) NOT NULL,
  `output_type` enum('form','csv','json','qr','pdf','text') NOT NULL,
  `scope_type` enum('branch','company','global','default') NOT NULL DEFAULT 'branch',
  `template_body` mediumtext DEFAULT NULL,
  `template_schema_json` longtext DEFAULT NULL,
  `version_label` varchar(40) NOT NULL DEFAULT 'v1',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- テーブルの構造 `prescription_output_template_fields`
--

CREATE TABLE `prescription_output_template_fields` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `output_template_id` bigint(20) UNSIGNED NOT NULL,
  `output_field_key` varchar(160) NOT NULL,
  `output_label` varchar(160) NOT NULL,
  `output_order` int(11) NOT NULL DEFAULT 0,
  `required` tinyint(1) NOT NULL DEFAULT 0,
  `format_rule` varchar(160) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- テーブルの構造 `prescription_reparse_evaluations`
--

CREATE TABLE `prescription_reparse_evaluations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_uid` varchar(64) NOT NULL,
  `branch_uid` varchar(64) NOT NULL,
  `tenant_id` bigint(20) UNSIGNED NOT NULL,
  `parse_job_id` bigint(20) UNSIGNED NOT NULL,
  `prescription_id` bigint(20) UNSIGNED NOT NULL,
  `model_name` varchar(120) DEFAULT NULL,
  `total_scored_fields` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `matched_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `mismatch_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `missing_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `extra_ai_field_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `match_rate` decimal(6,2) NOT NULL DEFAULT 0.00,
  `payload_json` longtext DEFAULT NULL,
  `created_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- テーブルの構造 `prescription_rule_learning_scores`
--

CREATE TABLE `prescription_rule_learning_scores` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_uid` varchar(32) NOT NULL,
  `branch_uid` varchar(32) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `rule_code` varchar(80) NOT NULL,
  `severity` enum('info','warning','danger','block') NOT NULL DEFAULT 'info',
  `trigger_count` int(11) NOT NULL DEFAULT 0,
  `inquiry_count` int(11) NOT NULL DEFAULT 0,
  `qr_block_count` int(11) NOT NULL DEFAULT 0,
  `last_parse_job_id` bigint(20) UNSIGNED DEFAULT NULL,
  `last_seen_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- テーブルの構造 `prescription_templates`
--

CREATE TABLE `prescription_templates` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_uid` varchar(80) DEFAULT NULL,
  `branch_uid` varchar(80) DEFAULT NULL,
  `tenant_id` bigint(20) UNSIGNED DEFAULT NULL,
  `source_candidate_id` bigint(20) UNSIGNED DEFAULT NULL,
  `approval_mode` varchar(20) NOT NULL DEFAULT 'manual',
  `scope_type` enum('branch','company','global','default') NOT NULL DEFAULT 'branch',
  `medical_institution_key` varchar(160) DEFAULT NULL,
  `template_key` varchar(120) NOT NULL,
  `display_name` varchar(160) NOT NULL,
  `version_label` varchar(40) NOT NULL DEFAULT 'v1',
  `paper_orientation` enum('portrait','landscape','unknown') NOT NULL DEFAULT 'unknown',
  `layout_fingerprint` char(64) NOT NULL,
  `field_map_json` longtext NOT NULL,
  `match_threshold` decimal(6,2) NOT NULL DEFAULT 85.00,
  `template_score` decimal(6,2) DEFAULT NULL,
  `sample_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `success_count` int(11) NOT NULL DEFAULT 0,
  `failure_count` int(11) NOT NULL DEFAULT 0,
  `avg_parse_ms` int(11) DEFAULT NULL,
  `avg_correction_rate` decimal(6,2) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `last_seen_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- テーブルの構造 `prescription_template_candidates`
--

CREATE TABLE `prescription_template_candidates` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_uid` varchar(80) NOT NULL,
  `branch_uid` varchar(80) NOT NULL,
  `tenant_id` bigint(20) UNSIGNED DEFAULT NULL,
  `parse_job_id` bigint(20) UNSIGNED NOT NULL,
  `detected_fingerprint` char(64) NOT NULL,
  `ai_field_map_json` longtext DEFAULT NULL,
  `ai_layout_profile_json` longtext DEFAULT NULL,
  `human_fixed_field_map_json` longtext DEFAULT NULL,
  `layout_profile_json` longtext DEFAULT NULL,
  `match_count` int(11) NOT NULL DEFAULT 1,
  `human_confirmed_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `human_edited_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `human_added_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `human_empty_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `correction_rate` decimal(6,2) DEFAULT NULL,
  `stability_score` decimal(6,2) DEFAULT NULL,
  `field_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `last_parse_job_id` bigint(20) UNSIGNED DEFAULT NULL,
  `status` enum('candidate','approved','rejected','merged') NOT NULL DEFAULT 'candidate',
  `approved_template_id` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- テーブルのデータのダンプ `prescription_template_candidates`
--

INSERT INTO `prescription_template_candidates` (`id`, `company_uid`, `branch_uid`, `tenant_id`, `parse_job_id`, `detected_fingerprint`, `ai_field_map_json`, `ai_layout_profile_json`, `human_fixed_field_map_json`, `layout_profile_json`, `match_count`, `human_confirmed_count`, `human_edited_count`, `human_added_count`, `human_empty_count`, `correction_rate`, `stability_score`, `field_count`, `last_parse_job_id`, `status`, `approved_template_id`, `approved_at`, `created_at`, `updated_at`) VALUES
(1, 'cmp_0001', 'br_0001', 1, 25, 'c3e9fc63f617296f5e0efef58f7e33f7a972829ddcebdfd48d4738ec0cd38aa9', '{\"layout_fingerprint\":\"c3e9fc63f617296f5e0efef58f7e33f7a972829ddcebdfd48d4738ec0cd38aa9\",\"features\":{\"orientation\":\"portrait\",\"ratio_bucket\":\"a4_like\",\"long_side_bucket\":\"<=2200\",\"short_side_bucket\":\"<=2200\",\"mime\":\"image/jpeg\"},\"paper_orientation\":\"portrait\",\"match_score\":null,\"quality_analysis\":{\"width\":1650,\"height\":2200,\"file_size_bytes\":560922,\"long_side\":2200,\"short_side\":1650,\"aspect_ratio\":1.3329999999999999626965063725947402417659759521484375,\"resolution_bucket\":\"mid_res\",\"paper_shape_bucket\":\"a4_like\",\"brightness_avg\":163.259999999999990905052982270717620849609375,\"brightness_bucket\":\"normal\",\"contrast_stddev\":43.7999999999999971578290569595992565155029296875,\"contrast_bucket\":\"normal\",\"edge_strength\":9.1099999999999994315658113919198513031005859375,\"blur_bucket\":\"slightly_blur\",\"ink_bleed_risk\":\"normal\",\"estimated_text_size_bucket\":\"normal\",\"print_type\":\"unknown\",\"font_family_guess\":\"unknown\",\"issue_flags\":[\"slightly_blur\"],\"preprocess_recommended\":true,\"preprocess_profile\":\"sharpen\"},\"ocr_assist\":{\"storage_file_id\":27,\"preprocess_profile\":\"sharpen\",\"width\":1650,\"height\":2200}}', NULL, NULL, NULL, 2, 0, 0, 0, 0, NULL, 20.00, 0, NULL, 'candidate', NULL, NULL, '2026-07-03 15:59:02', '2026-07-03 18:03:26'),
(3, 'cmp_0001', 'br_0001', 1, 27, '6094d1509a44c0e76cfbe255632e2d81bfddedf6776fbeefdc80a6c555fd7eaa', '{\"layout_fingerprint\":\"6094d1509a44c0e76cfbe255632e2d81bfddedf6776fbeefdc80a6c555fd7eaa\",\"features\":{\"profile_version\":\"layout_v2\",\"orientation\":\"portrait\",\"paper_shape_bucket\":\"a4_like\",\"aspect_ratio_bucket\":\"1.30-1.38\",\"resolution_bucket\":\"mid_res\",\"long_side_bucket\":\"<=2200\",\"short_side_bucket\":\"<=2200\",\"file_size_bucket\":\"300-900KB\"},\"paper_orientation\":\"portrait\",\"match_score\":null,\"profile_version\":\"layout_v2\",\"quality_analysis\":{\"width\":1650,\"height\":2200,\"file_size_bytes\":901166,\"long_side\":2200,\"short_side\":1650,\"aspect_ratio\":1.3329999999999999626965063725947402417659759521484375,\"resolution_bucket\":\"mid_res\",\"paper_shape_bucket\":\"a4_like\",\"brightness_avg\":170.099999999999994315658113919198513031005859375,\"brightness_bucket\":\"normal\",\"contrast_stddev\":34.03999999999999914734871708787977695465087890625,\"contrast_bucket\":\"normal\",\"edge_strength\":8.67999999999999971578290569595992565155029296875,\"blur_bucket\":\"slightly_blur\",\"ink_bleed_risk\":\"normal\",\"estimated_text_size_bucket\":\"normal\",\"print_type\":\"unknown\",\"font_family_guess\":\"unknown\",\"issue_flags\":[\"slightly_blur\"],\"preprocess_recommended\":true,\"preprocess_profile\":\"sharpen\"},\"ocr_assist\":{\"storage_file_id\":31,\"preprocess_profile\":\"sharpen\",\"width\":1650,\"height\":2200},\"ai_layout_profile\":{\"profile_version\":\"field_profile_v1\",\"field_count\":21,\"field_sequence_hash\":\"38255eae5b3338c30548c5ee46541ac2e365acb5\",\"fields\":[{\"field_key\":\"field_79329b8922a5\",\"field_label\":\"患者氏名\",\"field_group\":\"patient\",\"value_type\":\"person_name\",\"source_section\":\"患者欄\",\"display_order\":0},{\"field_key\":\"field_ac354d7920e3\",\"field_label\":\"生年月日\",\"field_group\":\"patient\",\"value_type\":\"date\",\"source_section\":\"患者欄\",\"display_order\":1},{\"field_key\":\"patient_name\",\"field_label\":\"氏名\",\"field_group\":\"patient\",\"value_type\":\"person_name\",\"source_section\":\"患者欄\",\"display_order\":2},{\"field_key\":\"patient_kana\",\"field_label\":\"フリガナ\",\"field_group\":\"patient\",\"value_type\":\"text\",\"source_section\":\"患者欄\",\"display_order\":3},{\"field_key\":\"patient_birth_date\",\"field_label\":\"生年月日\",\"field_group\":\"patient\",\"value_type\":\"date\",\"source_section\":\"患者欄\",\"display_order\":4},{\"field_key\":\"patient_gender\",\"field_label\":\"性別\",\"field_group\":\"patient\",\"value_type\":\"text\",\"source_section\":\"患者欄\",\"display_order\":5},{\"field_key\":\"field_d85fe9046c6c\",\"field_label\":\"公費負担者番号\",\"field_group\":\"insurance\",\"value_type\":\"text\",\"source_section\":\"上部左\",\"display_order\":6},{\"field_key\":\"field_db38e3365cd2\",\"field_label\":\"公費負担医療の受給者番号\",\"field_group\":\"insurance\",\"value_type\":\"text\",\"source_section\":\"上部右\",\"display_order\":7},{\"field_key\":\"insurance_no\",\"field_label\":\"保険者番号\",\"field_group\":\"insurance\",\"value_type\":\"code\",\"source_section\":\"保険欄\",\"display_order\":8},{\"field_key\":\"insured_symbol_number\",\"field_label\":\"被保険者証・被保険者手帳の記号・番号\",\"field_group\":\"insurance\",\"value_type\":\"code\",\"source_section\":\"保険欄\",\"display_order\":9},{\"field_key\":\"copay_rate\",\"field_label\":\"負担割合\",\"field_group\":\"insurance\",\"value_type\":\"text\",\"source_section\":\"保険欄\",\"display_order\":10},{\"field_key\":\"field_2c04e015cfdc\",\"field_label\":\"交付年月日\",\"field_group\":\"prescription\",\"value_type\":\"date\",\"source_section\":\"処方欄\",\"display_order\":11},{\"field_key\":\"field_ed5c3aab6a2b\",\"field_label\":\"処方箋使用期間\",\"field_group\":\"prescription\",\"value_type\":\"date\",\"source_section\":\"処方欄\",\"display_order\":12},{\"field_key\":\"issued_on\",\"field_label\":\"交付年月日\",\"field_group\":\"prescription\",\"value_type\":\"date\",\"source_section\":\"処方箋欄\",\"display_order\":13},{\"field_key\":\"expires_on\",\"field_label\":\"処方箋の使用期間\",\"field_group\":\"prescription\",\"value_type\":\"date\",\"source_section\":\"処方箋欄\",\"display_order\":14},{\"field_key\":\"field_2a5aeb723f02\",\"field_label\":\"医療機関名\",\"field_group\":\"medical_institution\",\"value_type\":\"text\",\"source_section\":\"医療機関欄\",\"display_order\":15},{\"field_key\":\"field_e1e3ba162806\",\"field_label\":\"保険医氏名\",\"field_group\":\"medical_institution\",\"value_type\":\"person_name\",\"source_section\":\"医療機関欄\",\"display_order\":16},{\"field_key\":\"medical_institution_code\",\"field_label\":\"医療機関コード\",\"field_group\":\"medical_institution\",\"value_type\":\"code\",\"source_section\":\"医療機関欄\",\"display_order\":17},{\"field_key\":\"medical_institution_name\",\"field_label\":\"保険医療機関の名称\",\"field_group\":\"medical_institution\",\"value_type\":\"text\",\"source_section\":\"医療機関欄\",\"display_order\":18},{\"field_key\":\"doctor_name\",\"field_label\":\"保険医氏名\",\"field_group\":\"medical_institution\",\"value_type\":\"person_name\",\"source_section\":\"医療機関欄\",\"display_order\":19},{\"field_key\":\"medical_institution_phone\",\"field_label\":\"電話番号\",\"field_group\":\"medical_institution\",\"value_type\":\"text\",\"source_section\":\"医療機関欄\",\"display_order\":20}],\"medication_shape\":{\"has_drug_name\":true,\"has_dose_text\":true,\"has_usage_text\":true,\"has_days_count\":true,\"has_amount_text\":true,\"count\":1}}}', NULL, NULL, NULL, 1, 0, 0, 0, 0, NULL, NULL, 0, NULL, 'candidate', NULL, NULL, '2026-07-04 09:02:37', '2026-07-04 09:03:02'),
(5, 'cmp_0001', 'br_0001', 1, 36, 'c8aa6be5c7e1dfa046edecc901e669fd99fb43d2e69118446a04ffa69cb423ae', '{\"layout_fingerprint\":\"c8aa6be5c7e1dfa046edecc901e669fd99fb43d2e69118446a04ffa69cb423ae\",\"features\":{\"profile_version\":\"layout_v2\",\"orientation\":\"portrait\",\"paper_shape_bucket\":\"a4_like\",\"aspect_ratio_bucket\":\"1.30-1.38\",\"resolution_bucket\":\"low_res\",\"long_side_bucket\":\"<=1200\",\"short_side_bucket\":\"<=1200\",\"file_size_bucket\":\"<300KB\"},\"paper_orientation\":\"portrait\",\"match_score\":null,\"profile_version\":\"layout_v2\",\"quality_analysis\":{\"width\":900,\"height\":1200,\"file_size_bytes\":264734,\"long_side\":1200,\"short_side\":900,\"aspect_ratio\":1.3329999999999999626965063725947402417659759521484375,\"resolution_bucket\":\"low_res\",\"paper_shape_bucket\":\"a4_like\",\"brightness_avg\":175.44999999999998863131622783839702606201171875,\"brightness_bucket\":\"normal\",\"contrast_stddev\":32.25,\"contrast_bucket\":\"normal\",\"edge_strength\":8.769999999999999573674358543939888477325439453125,\"blur_bucket\":\"slightly_blur\",\"ink_bleed_risk\":\"normal\",\"estimated_text_size_bucket\":\"small_text_risk\",\"print_type\":\"unknown\",\"font_family_guess\":\"unknown\",\"issue_flags\":[\"low_resolution\",\"slightly_blur\",\"small_text_risk\"],\"preprocess_recommended\":true,\"preprocess_profile\":\"upscale+sharpen\"},\"ocr_assist\":{\"storage_file_id\":49,\"preprocess_profile\":\"upscale+sharpen\",\"width\":1800,\"height\":2400},\"ai_layout_profile\":{\"profile_version\":\"field_profile_v1\",\"field_count\":25,\"field_sequence_hash\":\"838643436c1ffcaddf36cfe92923e01149d831cf\",\"fields\":[{\"field_key\":\"patient.name\",\"field_label\":\"氏名\",\"field_group\":\"patient\",\"value_type\":\"person_name\",\"source_section\":\"患者欄\",\"display_order\":0},{\"field_key\":\"patient.birth_date\",\"field_label\":\"生年月日\",\"field_group\":\"patient\",\"value_type\":\"date\",\"source_section\":\"患者欄\",\"display_order\":1},{\"field_key\":\"patient.gender\",\"field_label\":\"性別\",\"field_group\":\"patient\",\"value_type\":\"text\",\"source_section\":\"患者欄\",\"display_order\":2},{\"field_key\":\"patient_category\",\"field_label\":\"区分\",\"field_group\":\"patient\",\"value_type\":\"text\",\"source_section\":\"患者欄\",\"display_order\":3},{\"field_key\":\"patient.kana\",\"field_label\":\"フリガナ\",\"field_group\":\"patient\",\"value_type\":\"text\",\"source_section\":\"患者欄\",\"display_order\":4},{\"field_key\":\"insurance.insurance_no\",\"field_label\":\"保険者番号\",\"field_group\":\"insurance\",\"value_type\":\"code\",\"source_section\":\"上部右\",\"display_order\":5},{\"field_key\":\"insurance.insured_symbol_number\",\"field_label\":\"被保険者証・被保険者手帳の記号・番号\",\"field_group\":\"insurance\",\"value_type\":\"text\",\"source_section\":\"上部右\",\"display_order\":6},{\"field_key\":\"insurance.copay_rate\",\"field_label\":\"負担割合\",\"field_group\":\"insurance\",\"value_type\":\"text\",\"source_section\":\"保険欄\",\"display_order\":7},{\"field_key\":\"public_expense.payer_number\",\"field_label\":\"公費負担者番号\",\"field_group\":\"public_expense\",\"value_type\":\"code\",\"source_section\":\"上部左\",\"display_order\":8},{\"field_key\":\"public_expense.beneficiary_number\",\"field_label\":\"公費負担医療の受給者番号\",\"field_group\":\"public_expense\",\"value_type\":\"code\",\"source_section\":\"上部左\",\"display_order\":9},{\"field_key\":\"prescription.issued_on\",\"field_label\":\"交付年月日\",\"field_group\":\"prescription\",\"value_type\":\"date\",\"source_section\":\"上部左下\",\"display_order\":10},{\"field_key\":\"prescription.expires_on\",\"field_label\":\"処方せんの使用期間\",\"field_group\":\"prescription\",\"value_type\":\"date\",\"source_section\":\"上部右下\",\"display_order\":11},{\"field_key\":\"change_not_allowed\",\"field_label\":\"変更不可\",\"field_group\":\"prescription\",\"value_type\":\"boolean\",\"source_section\":\"処方欄上部\",\"display_order\":12},{\"field_key\":\"insurance_doctor_signature\",\"field_label\":\"保険医署名\",\"field_group\":\"prescription\",\"value_type\":\"person_name\",\"source_section\":\"備考欄上部\",\"display_order\":13},{\"field_key\":\"medical_institution.doctor_name\",\"field_label\":\"保険医療機関の所在地及び名称\",\"field_group\":\"medical_institution\",\"value_type\":\"person_name\",\"source_section\":\"医療機関欄\",\"display_order\":14},{\"field_key\":\"medical_institution.phone\",\"field_label\":\"電話番号\",\"field_group\":\"medical_institution\",\"value_type\":\"text\",\"source_section\":\"医療機関欄\",\"display_order\":15},{\"field_key\":\"prefecture_number\",\"field_label\":\"都道府県番号\",\"field_group\":\"medical_institution\",\"value_type\":\"code\",\"source_section\":\"医療機関欄\",\"display_order\":16},{\"field_key\":\"medical_fee_table_number\",\"field_label\":\"点数表番号\",\"field_group\":\"medical_institution\",\"value_type\":\"code\",\"source_section\":\"医療機関欄\",\"display_order\":17},{\"field_key\":\"medical_institution.code\",\"field_label\":\"医療機関コード\",\"field_group\":\"medical_institution\",\"value_type\":\"code\",\"source_section\":\"医療機関欄\",\"display_order\":18},{\"field_key\":\"dispensed_on\",\"field_label\":\"調剤済年月日\",\"field_group\":\"pharmacy\",\"value_type\":\"date\",\"source_section\":\"下部左\",\"display_order\":19},{\"field_key\":\"pharmacy_location_name_pharmacist\",\"field_label\":\"保険薬局の所在地及び名称・保険薬剤師名\",\"field_group\":\"pharmacy\",\"value_type\":\"text\",\"source_section\":\"下部左\",\"display_order\":20},{\"field_key\":\"change_not_allowed_instruction\",\"field_label\":\"変更不可欄説明\",\"field_group\":\"note\",\"value_type\":\"text\",\"source_section\":\"処方欄上部\",\"display_order\":21},{\"field_key\":\"signature_instruction\",\"field_label\":\"保険医署名欄説明\",\"field_group\":\"note\",\"value_type\":\"text\",\"source_section\":\"備考欄上部\",\"display_order\":22},{\"field_key\":\"remarks\",\"field_label\":\"備考\",\"field_group\":\"note\",\"value_type\":\"text\",\"source_section\":\"備考欄\",\"display_order\":23},{\"field_key\":\"qr_presence\",\"field_label\":\"QR有無\",\"field_group\":\"qr\",\"value_type\":\"boolean\",\"source_section\":\"全体\",\"display_order\":24}],\"medication_shape\":{\"has_drug_name\":true,\"has_dose_text\":true,\"has_usage_text\":true,\"has_days_count\":true,\"has_amount_text\":true,\"count\":2}}}', NULL, NULL, NULL, 9, 0, 0, 0, 0, NULL, NULL, 0, NULL, 'candidate', NULL, NULL, '2026-07-04 09:10:58', '2026-07-08 11:54:37');

-- --------------------------------------------------------

--
-- テーブルの構造 `prescription_template_match_logs`
--

CREATE TABLE `prescription_template_match_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `parse_job_id` bigint(20) UNSIGNED NOT NULL,
  `company_uid` varchar(80) NOT NULL,
  `branch_uid` varchar(80) NOT NULL,
  `tenant_id` bigint(20) UNSIGNED DEFAULT NULL,
  `matched_template_id` bigint(20) UNSIGNED DEFAULT NULL,
  `match_score` decimal(6,2) DEFAULT NULL,
  `detection_ms` int(11) DEFAULT NULL,
  `result` enum('matched','unknown','fallback','failed') NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- テーブルのデータのダンプ `prescription_template_match_logs`
--

INSERT INTO `prescription_template_match_logs` (`id`, `parse_job_id`, `company_uid`, `branch_uid`, `tenant_id`, `matched_template_id`, `match_score`, `detection_ms`, `result`, `created_at`) VALUES
(1, 25, 'cmp_0001', 'br_0001', 1, NULL, NULL, 1, 'unknown', '2026-07-03 15:59:02'),
(2, 26, 'cmp_0001', 'br_0001', 1, NULL, NULL, 0, 'unknown', '2026-07-03 16:55:48'),
(3, 27, 'cmp_0001', 'br_0001', 1, NULL, NULL, 1, 'unknown', '2026-07-04 09:02:37'),
(4, 28, 'cmp_0001', 'br_0001', 1, NULL, NULL, 1, 'unknown', '2026-07-04 09:10:58'),
(5, 29, 'cmp_0001', 'br_0001', 1, NULL, NULL, 4, 'unknown', '2026-07-06 17:27:52'),
(6, 30, 'cmp_0001', 'br_0001', 1, NULL, NULL, 1, 'unknown', '2026-07-06 17:33:51'),
(7, 31, 'cmp_0001', 'br_0001', 1, NULL, NULL, 1, 'unknown', '2026-07-06 17:43:10'),
(8, 32, 'cmp_0001', 'br_0001', 1, NULL, NULL, 1, 'unknown', '2026-07-06 17:44:18'),
(9, 33, 'cmp_0001', 'br_0001', 1, NULL, NULL, 1, 'unknown', '2026-07-06 17:47:16'),
(10, 34, 'cmp_0001', 'br_0001', 1, NULL, NULL, 1, 'unknown', '2026-07-08 11:04:45'),
(11, 35, 'cmp_0001', 'br_0001', 1, NULL, NULL, 1, 'unknown', '2026-07-08 11:33:30'),
(12, 36, 'cmp_0001', 'br_0001', 1, NULL, NULL, 1, 'unknown', '2026-07-08 11:52:42');

-- --------------------------------------------------------

--
-- テーブルの構造 `prescription_visual_text_learning_events`
--

CREATE TABLE `prescription_visual_text_learning_events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_uid` varchar(64) NOT NULL,
  `branch_uid` varchar(64) NOT NULL,
  `tenant_id` bigint(20) UNSIGNED DEFAULT NULL,
  `parse_job_id` bigint(20) UNSIGNED DEFAULT NULL,
  `layout_fingerprint` char(64) NOT NULL DEFAULT 'unknown',
  `quality_bucket` varchar(160) NOT NULL DEFAULT 'unknown',
  `issue_flags` varchar(500) DEFAULT NULL,
  `field_key` varchar(160) NOT NULL,
  `field_label` varchar(160) DEFAULT NULL,
  `field_group` varchar(64) NOT NULL DEFAULT 'other',
  `value_type` varchar(64) NOT NULL DEFAULT 'unknown',
  `text_style` varchar(80) NOT NULL DEFAULT 'unknown',
  `print_type` varchar(40) NOT NULL DEFAULT 'unknown',
  `estimated_text_size_bucket` varchar(80) NOT NULL DEFAULT 'unknown',
  `blur_bucket` varchar(80) NOT NULL DEFAULT 'unknown',
  `brightness_bucket` varchar(80) NOT NULL DEFAULT 'unknown',
  `contrast_bucket` varchar(80) NOT NULL DEFAULT 'unknown',
  `correction_type` varchar(32) NOT NULL,
  `confidence` decimal(6,2) DEFAULT NULL,
  `needs_human_check` tinyint(1) NOT NULL DEFAULT 0,
  `source_ai_value_sample` varchar(255) DEFAULT NULL,
  `final_value_sample` varchar(255) DEFAULT NULL,
  `visual_features_json` text DEFAULT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- テーブルの構造 `prescription_visual_text_learning_scores`
--

CREATE TABLE `prescription_visual_text_learning_scores` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_uid` varchar(64) NOT NULL,
  `branch_uid` varchar(64) NOT NULL,
  `layout_fingerprint` char(64) NOT NULL DEFAULT 'unknown',
  `quality_bucket` varchar(160) NOT NULL DEFAULT 'unknown',
  `field_key` varchar(160) NOT NULL,
  `field_label` varchar(160) DEFAULT NULL,
  `field_group` varchar(64) NOT NULL DEFAULT 'other',
  `value_type` varchar(64) NOT NULL DEFAULT 'unknown',
  `text_style` varchar(80) NOT NULL DEFAULT 'unknown',
  `print_type` varchar(40) NOT NULL DEFAULT 'unknown',
  `estimated_text_size_bucket` varchar(80) NOT NULL DEFAULT 'unknown',
  `blur_bucket` varchar(80) NOT NULL DEFAULT 'unknown',
  `brightness_bucket` varchar(80) NOT NULL DEFAULT 'unknown',
  `contrast_bucket` varchar(80) NOT NULL DEFAULT 'unknown',
  `observed_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `edited_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `added_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `empty_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `confirmed_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `correction_rate` decimal(6,2) NOT NULL DEFAULT 0.00,
  `miss_rate` decimal(6,2) NOT NULL DEFAULT 0.00,
  `overdetect_rate` decimal(6,2) NOT NULL DEFAULT 0.00,
  `last_issue_flags` varchar(500) DEFAULT NULL,
  `last_seen_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- テーブルの構造 `system_migrations`
--

CREATE TABLE `system_migrations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `migration_name` varchar(255) NOT NULL,
  `executed_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- テーブルのデータのダンプ `system_migrations`
--

INSERT INTO `system_migrations` (`id`, `migration_name`, `executed_at`) VALUES
(1, '20260630_002_knowledge_prescription_mvp.sql', '2026-06-30 14:40:21'),
(2, '20260701_002_knowledge_dynamic_field_learning.sql', '2026-07-01 13:21:09'),
(3, '20260702_001_knowledge_field_learning_scores.sql', '2026-07-02 12:17:35'),
(4, '20260702_002_knowledge_confirmed_correction_learning.sql', '2026-07-02 14:34:13'),
(5, '20260702_003_knowledge_layout_usage_score_fix.sql', '2026-07-02 16:36:04'),
(6, '20260702_004_knowledge_growth_learning_engine.sql', '2026-07-02 17:58:12'),
(7, '20260703_knowledge_layout_template_production.sql', '2026-07-03 18:03:26'),
(8, '20260704_knowledge_json_score_runtime', '2026-07-04 17:25:30');

--
-- ダンプしたテーブルのインデックス
--

--
-- テーブルのインデックス `drug_aliases`
--
ALTER TABLE `drug_aliases`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_drug_alias` (`alias_name`,`drug_master_id`),
  ADD KEY `idx_drug_alias_name` (`alias_name`);

--
-- テーブルのインデックス `drug_master`
--
ALTER TABLE `drug_master`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_drug_master_name` (`drug_name`),
  ADD KEY `idx_drug_master_normalized` (`normalized_name`);

--
-- テーブルのインデックス `drug_name_relation_observations`
--
ALTER TABLE `drug_name_relation_observations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_drug_relation_branch` (`company_uid`,`branch_uid`,`created_at`),
  ADD KEY `idx_drug_relation_parse_job` (`parse_job_id`),
  ADD KEY `idx_drug_relation_final_drug` (`final_drug_name`),
  ADD KEY `idx_drug_relation_generic_brand` (`final_generic_name`,`final_brand_name`);

--
-- テーブルのインデックス `drug_name_relation_preferences`
--
ALTER TABLE `drug_name_relation_preferences`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_drug_relation_pref` (`company_uid`,`branch_uid`,`pair_key`),
  ADD KEY `idx_drug_relation_pref_names` (`generic_name`,`brand_name`),
  ADD KEY `idx_drug_relation_pref_display` (`display_drug_name`);

--
-- テーブルのインデックス `knowledge_branch_refs`
--
ALTER TABLE `knowledge_branch_refs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_knowledge_branch_uid` (`company_uid`,`branch_uid`);

--
-- テーブルのインデックス `knowledge_company_refs`
--
ALTER TABLE `knowledge_company_refs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_knowledge_company_uid` (`company_uid`);

--
-- テーブルのインデックス `knowledge_import_logs`
--
ALTER TABLE `knowledge_import_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_knowledge_import_logs_type` (`import_type`,`created_at`);

--
-- テーブルのインデックス `knowledge_versions`
--
ALTER TABLE `knowledge_versions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_knowledge_version_key` (`version_key`);

--
-- テーブルのインデックス `prescription_auto_correction_rules`
--
ALTER TABLE `prescription_auto_correction_rules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_correction_rule` (`scope_type`,`company_uid`,`branch_uid`,`field_type`,`wrong_value`,`correct_value`),
  ADD KEY `idx_correction_rules_lookup` (`field_type`,`wrong_value`,`is_active`);

--
-- テーブルのインデックス `prescription_branch_field_preferences`
--
ALTER TABLE `prescription_branch_field_preferences`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_branch_field_pref` (`company_uid`,`branch_uid`,`field_key`),
  ADD KEY `idx_branch_field_pref_include` (`company_uid`,`branch_uid`,`include_default`),
  ADD KEY `idx_branch_field_pref_group` (`company_uid`,`branch_uid`,`field_group`);

--
-- テーブルのインデックス `prescription_canonical_fields`
--
ALTER TABLE `prescription_canonical_fields`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_canonical_field_key` (`field_key`);

--
-- テーブルのインデックス `prescription_confirmed_correction_events`
--
ALTER TABLE `prescription_confirmed_correction_events`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_confirmed_correction_content` (`content_hash`),
  ADD KEY `idx_confirmed_correction_scope` (`company_uid`,`branch_uid`,`field_key`),
  ADD KEY `idx_confirmed_correction_parse_job` (`parse_job_id`),
  ADD KEY `idx_confirmed_correction_type` (`correction_type`);

--
-- テーブルのインデックス `prescription_confirmed_correction_scores`
--
ALTER TABLE `prescription_confirmed_correction_scores`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_confirmed_correction_score` (`company_uid`,`branch_uid`,`field_key`),
  ADD KEY `idx_confirmed_score_priority` (`company_uid`,`branch_uid`,`edited_count`,`avg_score`),
  ADD KEY `idx_confirmed_score_group` (`field_group`);

--
-- テーブルのインデックス `prescription_correction_rule_events`
--
ALTER TABLE `prescription_correction_rule_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_rule_events_rule` (`rule_id`),
  ADD KEY `idx_rule_events_parse_job` (`parse_job_id`);

--
-- テーブルのインデックス `prescription_drug_dictionary_candidate_events`
--
ALTER TABLE `prescription_drug_dictionary_candidate_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_branch_created` (`company_uid`,`branch_uid`,`created_at`),
  ADD KEY `idx_parse_job` (`parse_job_id`),
  ADD KEY `idx_yj_code` (`selected_yj_code`),
  ADD KEY `idx_hot9_code` (`selected_hot9_code`),
  ADD KEY `idx_generic_code` (`selected_generic_code`);

--
-- テーブルのインデックス `prescription_drug_dictionary_learning_scores`
--
ALTER TABLE `prescription_drug_dictionary_learning_scores`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_company_branch_dictionary` (`company_uid`,`branch_uid`,`dictionary_key`),
  ADD KEY `idx_generic_code` (`generic_code`),
  ADD KEY `idx_yj_code` (`yj_code`),
  ADD KEY `idx_last_seen` (`last_seen_at`);

--
-- テーブルのインデックス `prescription_field_learning_scores`
--
ALTER TABLE `prescription_field_learning_scores`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_field_learning_scope` (`company_uid`,`branch_uid`,`field_key`),
  ADD KEY `idx_field_learning_group` (`field_group`),
  ADD KEY `idx_field_learning_score` (`avg_score`),
  ADD KEY `idx_field_learning_seen` (`last_seen_at`);

--
-- テーブルのインデックス `prescription_field_observations`
--
ALTER TABLE `prescription_field_observations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pfo_branch_key` (`company_uid`,`branch_uid`,`field_key`),
  ADD KEY `idx_pfo_job` (`parse_job_id`),
  ADD KEY `idx_pfo_prescription` (`prescription_id`),
  ADD KEY `idx_pfo_selected` (`company_uid`,`branch_uid`,`include_for_output`);

--
-- テーブルのインデックス `prescription_image_quality_learning_scores`
--
ALTER TABLE `prescription_image_quality_learning_scores`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_quality_bucket` (`company_uid`,`branch_uid`,`quality_bucket`,`issue_flags`),
  ADD KEY `idx_quality_rate` (`company_uid`,`branch_uid`,`correction_rate`,`observed_count`);

--
-- テーブルのインデックス `prescription_layout_field_learning_scores`
--
ALTER TABLE `prescription_layout_field_learning_scores`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_layout_field_scope` (`company_uid`,`branch_uid`,`layout_fingerprint`,`field_key`),
  ADD KEY `idx_layout_scope_seen` (`company_uid`,`branch_uid`,`layout_fingerprint`,`last_seen_at`),
  ADD KEY `idx_layout_field_group` (`field_group`,`field_key`);

--
-- テーブルのインデックス `prescription_learning_error_logs`
--
ALTER TABLE `prescription_learning_error_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_learning_error_scope` (`company_uid`,`branch_uid`,`created_at`),
  ADD KEY `idx_learning_error_area` (`area`,`created_at`);

--
-- テーブルのインデックス `prescription_ocr_pipeline_traces`
--
ALTER TABLE `prescription_ocr_pipeline_traces`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_trace_job_stage` (`parse_job_id`,`stage`),
  ADD KEY `idx_trace_scope_stage` (`company_uid`,`branch_uid`,`source_kind`,`stage`),
  ADD KEY `idx_trace_layout` (`layout_fingerprint`),
  ADD KEY `idx_trace_created` (`created_at`);

--
-- テーブルのインデックス `prescription_output_field_mappings`
--
ALTER TABLE `prescription_output_field_mappings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_output_mappings_template` (`output_template_id`),
  ADD KEY `idx_output_mappings_canonical` (`canonical_field_key`);

--
-- テーブルのインデックス `prescription_output_mapping_feedback`
--
ALTER TABLE `prescription_output_mapping_feedback`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_mapping_feedback_scope` (`company_uid`,`branch_uid`),
  ADD KEY `idx_mapping_feedback_label` (`output_label`);

--
-- テーブルのインデックス `prescription_output_templates`
--
ALTER TABLE `prescription_output_templates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_output_templates_scope` (`company_uid`,`branch_uid`,`scope_type`);

--
-- テーブルのインデックス `prescription_output_template_fields`
--
ALTER TABLE `prescription_output_template_fields`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_output_fields_template` (`output_template_id`);

--
-- テーブルのインデックス `prescription_reparse_evaluations`
--
ALTER TABLE `prescription_reparse_evaluations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_branch_created` (`company_uid`,`branch_uid`,`created_at`),
  ADD KEY `idx_parse_job` (`parse_job_id`),
  ADD KEY `idx_prescription` (`prescription_id`),
  ADD KEY `idx_match_rate` (`match_rate`);

--
-- テーブルのインデックス `prescription_rule_learning_scores`
--
ALTER TABLE `prescription_rule_learning_scores`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_rule_scope` (`company_uid`,`branch_uid`,`tenant_id`,`rule_code`,`severity`),
  ADD KEY `idx_rule_score` (`rule_code`,`trigger_count`,`inquiry_count`,`qr_block_count`),
  ADD KEY `idx_rule_scope_recent` (`company_uid`,`branch_uid`,`last_seen_at`);

--
-- テーブルのインデックス `prescription_templates`
--
ALTER TABLE `prescription_templates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_prescription_template_key` (`scope_type`,`template_key`,`version_label`),
  ADD KEY `idx_prescription_templates_fingerprint` (`layout_fingerprint`),
  ADD KEY `idx_prescription_templates_scope` (`company_uid`,`branch_uid`,`scope_type`);

--
-- テーブルのインデックス `prescription_template_candidates`
--
ALTER TABLE `prescription_template_candidates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_template_candidate_scope_fingerprint` (`company_uid`,`branch_uid`,`detected_fingerprint`),
  ADD KEY `idx_template_candidates_status` (`company_uid`,`branch_uid`,`status`);

--
-- テーブルのインデックス `prescription_template_match_logs`
--
ALTER TABLE `prescription_template_match_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_template_match_logs_job` (`parse_job_id`),
  ADD KEY `idx_template_match_logs_scope` (`company_uid`,`branch_uid`,`created_at`);

--
-- テーブルのインデックス `prescription_visual_text_learning_events`
--
ALTER TABLE `prescription_visual_text_learning_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_branch_created` (`company_uid`,`branch_uid`,`created_at`),
  ADD KEY `idx_parse_job` (`parse_job_id`),
  ADD KEY `idx_visual_bucket` (`company_uid`,`branch_uid`,`layout_fingerprint`,`quality_bucket`),
  ADD KEY `idx_field` (`company_uid`,`branch_uid`,`field_key`);

--
-- テーブルのインデックス `prescription_visual_text_learning_scores`
--
ALTER TABLE `prescription_visual_text_learning_scores`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_visual_score` (`company_uid`,`branch_uid`,`layout_fingerprint`,`quality_bucket`,`field_key`,`value_type`,`text_style`,`estimated_text_size_bucket`,`blur_bucket`,`brightness_bucket`,`contrast_bucket`) USING HASH,
  ADD KEY `idx_company_branch_rate` (`company_uid`,`branch_uid`,`correction_rate`,`observed_count`),
  ADD KEY `idx_last_seen` (`last_seen_at`);

--
-- テーブルのインデックス `system_migrations`
--
ALTER TABLE `system_migrations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_system_migrations_name` (`migration_name`);

--
-- ダンプしたテーブルの AUTO_INCREMENT
--

--
-- テーブルの AUTO_INCREMENT `drug_aliases`
--
ALTER TABLE `drug_aliases`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- テーブルの AUTO_INCREMENT `drug_master`
--
ALTER TABLE `drug_master`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- テーブルの AUTO_INCREMENT `drug_name_relation_observations`
--
ALTER TABLE `drug_name_relation_observations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- テーブルの AUTO_INCREMENT `drug_name_relation_preferences`
--
ALTER TABLE `drug_name_relation_preferences`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- テーブルの AUTO_INCREMENT `knowledge_branch_refs`
--
ALTER TABLE `knowledge_branch_refs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- テーブルの AUTO_INCREMENT `knowledge_company_refs`
--
ALTER TABLE `knowledge_company_refs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- テーブルの AUTO_INCREMENT `knowledge_import_logs`
--
ALTER TABLE `knowledge_import_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- テーブルの AUTO_INCREMENT `knowledge_versions`
--
ALTER TABLE `knowledge_versions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- テーブルの AUTO_INCREMENT `prescription_auto_correction_rules`
--
ALTER TABLE `prescription_auto_correction_rules`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- テーブルの AUTO_INCREMENT `prescription_branch_field_preferences`
--
ALTER TABLE `prescription_branch_field_preferences`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- テーブルの AUTO_INCREMENT `prescription_canonical_fields`
--
ALTER TABLE `prescription_canonical_fields`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- テーブルの AUTO_INCREMENT `prescription_confirmed_correction_events`
--
ALTER TABLE `prescription_confirmed_correction_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- テーブルの AUTO_INCREMENT `prescription_confirmed_correction_scores`
--
ALTER TABLE `prescription_confirmed_correction_scores`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- テーブルの AUTO_INCREMENT `prescription_correction_rule_events`
--
ALTER TABLE `prescription_correction_rule_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- テーブルの AUTO_INCREMENT `prescription_drug_dictionary_candidate_events`
--
ALTER TABLE `prescription_drug_dictionary_candidate_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- テーブルの AUTO_INCREMENT `prescription_drug_dictionary_learning_scores`
--
ALTER TABLE `prescription_drug_dictionary_learning_scores`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- テーブルの AUTO_INCREMENT `prescription_field_learning_scores`
--
ALTER TABLE `prescription_field_learning_scores`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- テーブルの AUTO_INCREMENT `prescription_field_observations`
--
ALTER TABLE `prescription_field_observations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- テーブルの AUTO_INCREMENT `prescription_image_quality_learning_scores`
--
ALTER TABLE `prescription_image_quality_learning_scores`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- テーブルの AUTO_INCREMENT `prescription_layout_field_learning_scores`
--
ALTER TABLE `prescription_layout_field_learning_scores`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- テーブルの AUTO_INCREMENT `prescription_learning_error_logs`
--
ALTER TABLE `prescription_learning_error_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- テーブルの AUTO_INCREMENT `prescription_ocr_pipeline_traces`
--
ALTER TABLE `prescription_ocr_pipeline_traces`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- テーブルの AUTO_INCREMENT `prescription_output_field_mappings`
--
ALTER TABLE `prescription_output_field_mappings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- テーブルの AUTO_INCREMENT `prescription_output_mapping_feedback`
--
ALTER TABLE `prescription_output_mapping_feedback`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- テーブルの AUTO_INCREMENT `prescription_output_templates`
--
ALTER TABLE `prescription_output_templates`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- テーブルの AUTO_INCREMENT `prescription_output_template_fields`
--
ALTER TABLE `prescription_output_template_fields`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- テーブルの AUTO_INCREMENT `prescription_reparse_evaluations`
--
ALTER TABLE `prescription_reparse_evaluations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- テーブルの AUTO_INCREMENT `prescription_rule_learning_scores`
--
ALTER TABLE `prescription_rule_learning_scores`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- テーブルの AUTO_INCREMENT `prescription_templates`
--
ALTER TABLE `prescription_templates`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- テーブルの AUTO_INCREMENT `prescription_template_candidates`
--
ALTER TABLE `prescription_template_candidates`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- テーブルの AUTO_INCREMENT `prescription_template_match_logs`
--
ALTER TABLE `prescription_template_match_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- テーブルの AUTO_INCREMENT `prescription_visual_text_learning_events`
--
ALTER TABLE `prescription_visual_text_learning_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- テーブルの AUTO_INCREMENT `prescription_visual_text_learning_scores`
--
ALTER TABLE `prescription_visual_text_learning_scores`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- テーブルの AUTO_INCREMENT `system_migrations`
--
ALTER TABLE `system_migrations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

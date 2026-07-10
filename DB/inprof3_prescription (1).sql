-- phpMyAdmin SQL Dump
-- version 5.2.1-1.el8.remi
-- https://www.phpmyadmin.net/
--
-- ホスト: localhost
-- 生成日時: 2026 年 7 月 10 日 14:39
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
-- データベース: `inprof3_prescription`
--

-- --------------------------------------------------------

--
-- テーブルの構造 `admin_branch_db_assignments`
--

CREATE TABLE `admin_branch_db_assignments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` bigint(20) UNSIGNED NOT NULL,
  `location_id` bigint(20) UNSIGNED NOT NULL,
  `company_uid` varchar(80) NOT NULL,
  `branch_uid` varchar(80) NOT NULL,
  `branch_code` varchar(80) DEFAULT NULL,
  `branch_name` varchar(160) NOT NULL,
  `branch_db_suffix` char(4) NOT NULL,
  `branch_db_name` varchar(128) NOT NULL,
  `connection_key` varchar(80) DEFAULT NULL,
  `status` enum('active','inactive','maintenance') NOT NULL DEFAULT 'active',
  `memo` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- テーブルのデータのダンプ `admin_branch_db_assignments`
--

INSERT INTO `admin_branch_db_assignments` (`id`, `tenant_id`, `location_id`, `company_uid`, `branch_uid`, `branch_code`, `branch_name`, `branch_db_suffix`, `branch_db_name`, `connection_key`, `status`, `memo`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 'cmp_0001', 'br_0001', 'main', '本店', '0001', 'inprof3_tenants0001', 'tenant_0001', 'active', '20260630_004で既存locationsから自動生成', '2026-07-01 10:25:39', '2026-07-01 10:25:39'),
(2, 1, 2, 'cmp_0001', 'br_0002', 'branch-a', '支店A', '0002', 'inprof3_tenants0002', 'demo_location_002', 'inactive', '20260630_004で既存locationsから自動生成', '2026-07-01 10:25:39', '2026-07-01 10:25:39');

-- --------------------------------------------------------

--
-- テーブルの構造 `admin_company_db_assignments`
--

CREATE TABLE `admin_company_db_assignments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` bigint(20) UNSIGNED NOT NULL,
  `company_uid` varchar(80) NOT NULL,
  `company_code` varchar(80) DEFAULT NULL,
  `company_name` varchar(160) NOT NULL,
  `company_db_suffix` char(4) NOT NULL,
  `company_db_name` varchar(128) NOT NULL,
  `status` enum('active','inactive','maintenance') NOT NULL DEFAULT 'active',
  `memo` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- テーブルのデータのダンプ `admin_company_db_assignments`
--

INSERT INTO `admin_company_db_assignments` (`id`, `tenant_id`, `company_uid`, `company_code`, `company_name`, `company_db_suffix`, `company_db_name`, `status`, `memo`, `created_at`, `updated_at`) VALUES
(1, 1, 'cmp_0001', 'demo-tenant', 'ファーマ薬局グループ', '0001', 'inprof3_company0001', 'active', '20260630_004で既存tenantsから自動生成', '2026-07-01 10:25:39', '2026-07-01 10:25:39');

-- --------------------------------------------------------

--
-- テーブルの構造 `admin_login_codes`
--

CREATE TABLE `admin_login_codes` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `code_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- テーブルのデータのダンプ `admin_login_codes`
--

INSERT INTO `admin_login_codes` (`id`, `user_id`, `code_hash`, `expires_at`, `used_at`, `created_at`) VALUES
(1, 1, '$2y$10$kjLI.bu.moinY28btKViuODanreYpL2DZE/KIaikuH7ZVtAsVhA9e', '2026-06-24 14:50:35', '2026-06-24 14:40:42', '2026-06-24 14:40:35'),
(2, 1, '$2y$10$YYDp3Rbq9acjuTauj5SPfONfHza0NfBykc6pVWWvlMYR0N7kjqftG', '2026-06-24 14:59:49', '2026-06-24 14:49:53', '2026-06-24 14:49:49'),
(3, 1, '$2y$10$bt9QBnTPyBAF.tENEyg6X.EfpvXfoa0XorbCOJT.sLtXK9nE2ntUK', '2026-06-24 17:16:14', NULL, '2026-06-24 17:06:14'),
(4, 1, '$2y$10$vm1SVyoHyaMD7GjB9KwZG.cxa7mJVCgchqOetezdFupUUNGseZ6i6', '2026-06-24 17:18:51', '2026-06-24 17:08:58', '2026-06-24 17:08:51'),
(5, 1, '$2y$10$l3Fnoz2l71u7yBOdv2uv.eEvJIlpQVg9A4G3iXIRNrH7vVQdnchqS', '2026-06-24 17:19:41', '2026-06-24 17:09:48', '2026-06-24 17:09:41'),
(6, 1, '$2y$10$CGYti81ff0DF97izy9qB.e3rIV9EErHr2i5DYtpzfy0jiYgFvybqK', '2026-06-25 16:03:02', '2026-06-25 15:53:09', '2026-06-25 15:53:02'),
(7, 1, '$2y$10$ApgJ0NmS6YXnLgdZdfPISueAzyW9I28ZybymtAcDy2CPlrGwkGe3y', '2026-06-26 11:25:53', '2026-06-26 11:15:59', '2026-06-26 11:15:53'),
(8, 1, '$2y$10$2WoDX0aPYgCDAmE3oQr.iezK1oa0EuJFEkcI9X3MJOa10Q2C55U86', '2026-07-01 10:10:25', '2026-07-01 10:00:37', '2026-07-01 10:00:25'),
(9, 1, '$2y$10$uGSzKyXLYgs1jgt2vu16S.LcroCAyKJYEfMZ8x4nFlueheIFaMnEe', '2026-07-01 10:30:33', '2026-07-01 10:20:37', '2026-07-01 10:20:33'),
(10, 1, '$2y$10$oTDI4S.KW09/eC/4Dzv7uulBSCv0LHJN6tNAE.PYnItvhpLqIUMTa', '2026-07-02 09:06:42', '2026-07-02 08:56:52', '2026-07-02 08:56:42'),
(11, 1, '$2y$10$TQv.46kkHO2htLY4QJ21IukTZwqimaySDPxFRQ0YRfg6JmxFde1zS', '2026-07-02 09:07:50', NULL, '2026-07-02 08:57:50'),
(12, 1, '$2y$10$IMHb4jA5WCDc4rOgb.ctjueh.P717x/LHS8.ALW/b.mVyq/i7wfmi', '2026-07-02 09:08:44', '2026-07-02 08:58:49', '2026-07-02 08:58:44');

-- --------------------------------------------------------

--
-- テーブルの構造 `drug_aliases`
--

CREATE TABLE `drug_aliases` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `drug_id` bigint(20) UNSIGNED NOT NULL,
  `alias_name` varchar(160) NOT NULL,
  `alias_type` enum('generic','product','kana','short','ocr') NOT NULL DEFAULT 'product',
  `priority` int(11) NOT NULL DEFAULT 100,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- テーブルのデータのダンプ `drug_aliases`
--

INSERT INTO `drug_aliases` (`id`, `drug_id`, `alias_name`, `alias_type`, `priority`, `created_at`) VALUES
(1, 1, 'アムロジピン', 'short', 10, '2026-06-24 11:15:38'),
(2, 1, 'ノルバスク', 'product', 20, '2026-06-24 11:15:38'),
(3, 1, 'アムロジン', 'product', 20, '2026-06-24 11:15:38'),
(4, 2, 'アンブロキソール', 'generic', 10, '2026-06-24 11:15:38'),
(5, 2, 'ムコソルバン', 'short', 10, '2026-06-24 11:15:38'),
(6, 3, 'ロキソプロフェン', 'generic', 10, '2026-06-24 11:15:38'),
(7, 3, 'ロキソニン', 'short', 10, '2026-06-24 11:15:38');

-- --------------------------------------------------------

--
-- テーブルの構造 `drug_master`
--

CREATE TABLE `drug_master` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `yj_code` varchar(32) DEFAULT NULL,
  `hot_code` varchar(32) DEFAULT NULL,
  `receipt_code` varchar(32) DEFAULT NULL,
  `generic_name` varchar(160) NOT NULL,
  `product_name` varchar(160) NOT NULL,
  `maker_name` varchar(120) DEFAULT NULL,
  `strength` varchar(80) DEFAULT NULL,
  `dosage_form` varchar(80) DEFAULT NULL,
  `unit` varchar(40) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- テーブルのデータのダンプ `drug_master`
--

INSERT INTO `drug_master` (`id`, `yj_code`, `hot_code`, `receipt_code`, `generic_name`, `product_name`, `maker_name`, `strength`, `dosage_form`, `unit`, `is_active`, `created_at`, `updated_at`) VALUES
(1, '2171022F3010', NULL, NULL, 'アムロジピンベシル酸塩', 'アムロジピンOD錠5mg', 'デモ製薬', '5mg', 'OD錠', '錠', 1, '2026-06-24 11:15:38', '2026-06-24 11:15:38'),
(2, '2239001F1010', NULL, NULL, 'アンブロキソール塩酸塩', 'ムコソルバン錠15mg', 'デモ製薬', '15mg', '錠', '錠', 1, '2026-06-24 11:15:38', '2026-06-24 11:15:38'),
(3, '1149019F1560', NULL, NULL, 'ロキソプロフェンナトリウム水和物', 'ロキソニン錠60mg', 'デモ製薬', '60mg', '錠', '錠', 1, '2026-06-24 11:15:38', '2026-06-24 11:15:38');

-- --------------------------------------------------------

--
-- テーブルの構造 `features`
--

CREATE TABLE `features` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `feature_key` varchar(64) NOT NULL,
  `name` varchar(80) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `icon` varchar(16) DEFAULT NULL,
  `route_path` varchar(120) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 100,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- テーブルのデータのダンプ `features`
--

INSERT INTO `features` (`id`, `feature_key`, `name`, `description`, `icon`, `route_path`, `sort_order`, `is_active`, `created_at`) VALUES
(1, 'prescription_scan', '処方箋読込', 'カメラまたはファイルから処方箋を読み込みます', 'RX', '/prescription_scan.php', 10, 1, '2026-06-24 11:15:38'),
(2, 'reception_list', '受付データ一覧', '受付済みデータを検索・確認します', 'LIST', '/receptions.php', 20, 1, '2026-06-24 11:15:38'),
(3, 'inventory', '在庫管理', '薬品在庫を確認します', 'INV', '#', 30, 1, '2026-06-24 11:15:38'),
(4, 'contraindication_check', '禁忌チェック', '禁忌・重複薬を確認します', 'CHK', '#', 40, 1, '2026-06-24 11:15:38'),
(5, 'management_report', '経営レポート', '処方箋枚数・薬価ベース集計', 'REP', '#', 50, 1, '2026-06-24 11:15:38'),
(6, 'patient_survey', '患者アンケート', '新患アンケート入力', 'FORM', '#', 60, 1, '2026-06-24 11:15:38'),
(7, 'completion_photo', '完了時写真確認', '投薬完了後の写真管理', 'CAM', '#', 70, 1, '2026-06-24 11:15:38'),
(8, 'visit_search', '外出・薬局検索', '訪問先で履歴検索します', 'MAP', '#', 80, 1, '2026-06-24 11:15:38');

-- --------------------------------------------------------

--
-- テーブルの構造 `locations`
--

CREATE TABLE `locations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` bigint(20) UNSIGNED NOT NULL,
  `location_code` varchar(32) NOT NULL,
  `name` varchar(120) NOT NULL,
  `status` enum('active','suspended','closed') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- テーブルのデータのダンプ `locations`
--

INSERT INTO `locations` (`id`, `tenant_id`, `location_code`, `name`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, 'main', '本店', 'active', '2026-06-24 11:15:38', '2026-06-24 11:15:38'),
(2, 1, 'branch-a', '支店A', 'active', '2026-06-24 11:15:38', '2026-06-24 11:15:38');

-- --------------------------------------------------------

--
-- テーブルの構造 `location_features`
--

CREATE TABLE `location_features` (
  `location_id` bigint(20) UNSIGNED NOT NULL,
  `feature_id` bigint(20) UNSIGNED NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `enabled_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- テーブルのデータのダンプ `location_features`
--

INSERT INTO `location_features` (`location_id`, `feature_id`, `is_enabled`, `enabled_at`) VALUES
(1, 1, 1, '2026-06-24 11:15:38'),
(1, 2, 1, '2026-06-24 11:15:38'),
(1, 3, 1, '2026-06-24 11:15:38'),
(1, 4, 1, '2026-06-24 11:15:38'),
(1, 5, 1, '2026-06-24 11:15:38'),
(1, 6, 1, '2026-06-24 11:15:38'),
(1, 7, 1, '2026-06-24 11:15:38'),
(1, 8, 1, '2026-06-24 11:15:38');

-- --------------------------------------------------------

--
-- テーブルの構造 `main_audit_logs`
--

CREATE TABLE `main_audit_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` bigint(20) UNSIGNED DEFAULT NULL,
  `location_id` bigint(20) UNSIGNED DEFAULT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `action` varchar(80) NOT NULL,
  `target_table` varchar(80) DEFAULT NULL,
  `target_id` bigint(20) UNSIGNED DEFAULT NULL,
  `detail_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`detail_json`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- テーブルの構造 `shared_inventory`
--

CREATE TABLE `shared_inventory` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `location_id` bigint(20) UNSIGNED NOT NULL,
  `drug_id` bigint(20) UNSIGNED DEFAULT NULL,
  `public_drug_name` varchar(160) NOT NULL,
  `public_quantity` decimal(12,2) NOT NULL DEFAULT 0.00,
  `unit` varchar(40) DEFAULT NULL,
  `expires_on` date DEFAULT NULL,
  `can_share` tinyint(1) NOT NULL DEFAULT 0,
  `last_synced_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- テーブルの構造 `super_admin_users`
--

CREATE TABLE `super_admin_users` (
  `id` int(10) UNSIGNED NOT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `name` varchar(100) NOT NULL,
  `status` enum('active','disabled') NOT NULL DEFAULT 'active',
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- テーブルのデータのダンプ `super_admin_users`
--

INSERT INTO `super_admin_users` (`id`, `email`, `password_hash`, `name`, `status`, `last_login_at`, `created_at`, `updated_at`) VALUES
(1, 'superadmin@pharma.local', '$2y$12$h0Z7jTo1aoNBcu3suUNHYeAh/zk7y714Zve3/zEMTVXwcdGAqIGc6', 'superAdmin', 'active', '2026-07-02 11:04:29', '2026-06-24 15:57:51', '2026-07-02 11:04:29');

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
(1, '20260630_004_admin_saas_assignments.sql', '2026-07-01 10:25:39');

-- --------------------------------------------------------

--
-- テーブルの構造 `tenants`
--

CREATE TABLE `tenants` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_code` varchar(32) NOT NULL,
  `name` varchar(120) NOT NULL,
  `plan_name` varchar(60) NOT NULL DEFAULT 'demo',
  `status` enum('trial','active','suspended','cancelled') NOT NULL DEFAULT 'trial',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- テーブルのデータのダンプ `tenants`
--

INSERT INTO `tenants` (`id`, `tenant_code`, `name`, `plan_name`, `status`, `created_at`, `updated_at`) VALUES
(1, 'demo-tenant', 'ファーマ薬局グループ', 'demo', 'trial', '2026-06-24 11:15:38', '2026-06-24 11:15:38');

-- --------------------------------------------------------

--
-- テーブルの構造 `tenant_db_connections`
--

CREATE TABLE `tenant_db_connections` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` bigint(20) UNSIGNED NOT NULL,
  `location_id` bigint(20) UNSIGNED NOT NULL,
  `connection_key` varchar(64) NOT NULL,
  `db_name_note` varchar(120) DEFAULT NULL,
  `status` enum('active','maintenance','disabled') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- テーブルのデータのダンプ `tenant_db_connections`
--

INSERT INTO `tenant_db_connections` (`id`, `tenant_id`, `location_id`, `connection_key`, `db_name_note`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 'tenant_0001', '本店用の拠点DB。config.php の tenant_db_connections.demo_location_001 と一致させる', 'active', '2026-06-24 11:15:38', '2026-06-24 11:30:59'),
(2, 1, 2, 'demo_location_002', '支店A用。DBを追加したらconfig.phpに同じキーを追加する', 'disabled', '2026-06-24 11:15:38', '2026-06-24 11:15:38');

-- --------------------------------------------------------

--
-- テーブルの構造 `tenant_db_pool`
--

CREATE TABLE `tenant_db_pool` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `connection_key` varchar(64) NOT NULL,
  `db_name` varchar(120) NOT NULL,
  `db_host_note` varchar(120) DEFAULT NULL,
  `status` enum('available','assigned','maintenance','retired') NOT NULL DEFAULT 'available',
  `assigned_tenant_id` bigint(20) UNSIGNED DEFAULT NULL,
  `assigned_location_id` bigint(20) UNSIGNED DEFAULT NULL,
  `assigned_at` datetime DEFAULT NULL,
  `last_initialized_at` datetime DEFAULT NULL,
  `last_reset_at` datetime DEFAULT NULL,
  `memo` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- テーブルのデータのダンプ `tenant_db_pool`
--

INSERT INTO `tenant_db_pool` (`id`, `connection_key`, `db_name`, `db_host_note`, `status`, `assigned_tenant_id`, `assigned_location_id`, `assigned_at`, `last_initialized_at`, `last_reset_at`, `memo`, `created_at`, `updated_at`) VALUES
(1, 'tenant_0001', 'inprof3_tenants0001', 'config.php の tenant_db_connections[tenant_0001] を参照', 'available', NULL, NULL, NULL, NULL, NULL, '手動作成済み空DB。利用時に管理画面から割当する。', '2026-06-24 14:32:01', '2026-06-24 14:32:01');

-- --------------------------------------------------------

--
-- テーブルの構造 `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` bigint(20) UNSIGNED NOT NULL,
  `role` enum('admin','pharmacy_user') NOT NULL DEFAULT 'pharmacy_user',
  `name` varchar(80) NOT NULL,
  `email` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `status` enum('active','locked','deleted') NOT NULL DEFAULT 'active',
  `otp_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- テーブルのデータのダンプ `users`
--

INSERT INTO `users` (`id`, `tenant_id`, `role`, `name`, `email`, `password_hash`, `status`, `otp_enabled`, `last_login_at`, `created_at`, `updated_at`) VALUES
(1, 1, 'admin', '山田 太郎', 'admin@pharma.local', '$2y$12$h0Z7jTo1aoNBcu3suUNHYeAh/zk7y714Zve3/zEMTVXwcdGAqIGc6', 'active', 1, '2026-07-02 08:58:49', '2026-06-24 11:15:38', '2026-07-02 08:58:49'),
(2, 1, '', '山田 太郎', 'demo.user@pharma.local', '$2y$12$h0Z7jTo1aoNBcu3suUNHYeAh/zk7y714Zve3/zEMTVXwcdGAqIGc6', 'active', 0, '2026-07-10 10:10:25', '2026-06-24 11:15:38', '2026-07-10 10:10:25');

-- --------------------------------------------------------

--
-- テーブルの構造 `user_locations`
--

CREATE TABLE `user_locations` (
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `location_id` bigint(20) UNSIGNED NOT NULL,
  `role_at_location` enum('manager','staff','readonly') NOT NULL DEFAULT 'staff',
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('active','disabled') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- テーブルのデータのダンプ `user_locations`
--

INSERT INTO `user_locations` (`user_id`, `location_id`, `role_at_location`, `is_default`, `status`, `created_at`) VALUES
(1, 1, 'manager', 1, 'active', '2026-06-24 11:15:38'),
(1, 2, 'manager', 0, 'active', '2026-06-24 11:15:38'),
(2, 1, 'staff', 1, 'active', '2026-06-24 11:15:38');

--
-- ダンプしたテーブルのインデックス
--

--
-- テーブルのインデックス `admin_branch_db_assignments`
--
ALTER TABLE `admin_branch_db_assignments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_branch_uid` (`branch_uid`),
  ADD UNIQUE KEY `uq_branch_location_id` (`location_id`),
  ADD KEY `idx_company_branch` (`company_uid`,`branch_uid`),
  ADD KEY `idx_branch_status` (`status`),
  ADD KEY `idx_branch_db_name` (`branch_db_name`);

--
-- テーブルのインデックス `admin_company_db_assignments`
--
ALTER TABLE `admin_company_db_assignments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_company_uid` (`company_uid`),
  ADD UNIQUE KEY `uq_company_tenant_id` (`tenant_id`),
  ADD KEY `idx_company_status` (`status`),
  ADD KEY `idx_company_db_name` (`company_db_name`);

--
-- テーブルのインデックス `admin_login_codes`
--
ALTER TABLE `admin_login_codes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_admin_login_codes_user` (`user_id`,`expires_at`);

--
-- テーブルのインデックス `drug_aliases`
--
ALTER TABLE `drug_aliases`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_drug_aliases_alias` (`alias_name`),
  ADD KEY `fk_drug_aliases_drug` (`drug_id`);

--
-- テーブルのインデックス `drug_master`
--
ALTER TABLE `drug_master`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_drug_master_generic` (`generic_name`),
  ADD KEY `idx_drug_master_product` (`product_name`),
  ADD KEY `idx_drug_master_yj` (`yj_code`);

--
-- テーブルのインデックス `features`
--
ALTER TABLE `features`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_features_key` (`feature_key`);

--
-- テーブルのインデックス `locations`
--
ALTER TABLE `locations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_locations_tenant_code` (`tenant_id`,`location_code`);

--
-- テーブルのインデックス `location_features`
--
ALTER TABLE `location_features`
  ADD PRIMARY KEY (`location_id`,`feature_id`),
  ADD KEY `fk_location_features_feature` (`feature_id`);

--
-- テーブルのインデックス `main_audit_logs`
--
ALTER TABLE `main_audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_main_audit_logs_tenant_created` (`tenant_id`,`created_at`),
  ADD KEY `fk_main_audit_logs_location` (`location_id`),
  ADD KEY `fk_main_audit_logs_user` (`user_id`);

--
-- テーブルのインデックス `shared_inventory`
--
ALTER TABLE `shared_inventory`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_shared_inventory_location` (`location_id`),
  ADD KEY `idx_shared_inventory_drug` (`drug_id`);

--
-- テーブルのインデックス `super_admin_users`
--
ALTER TABLE `super_admin_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_super_admin_users_email` (`email`);

--
-- テーブルのインデックス `system_migrations`
--
ALTER TABLE `system_migrations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_system_migrations_name` (`migration_name`);

--
-- テーブルのインデックス `tenants`
--
ALTER TABLE `tenants`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_tenants_tenant_code` (`tenant_code`);

--
-- テーブルのインデックス `tenant_db_connections`
--
ALTER TABLE `tenant_db_connections`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_tenant_db_connections_location` (`location_id`),
  ADD UNIQUE KEY `uq_tenant_db_connections_key` (`connection_key`),
  ADD KEY `fk_tenant_db_connections_tenant` (`tenant_id`);

--
-- テーブルのインデックス `tenant_db_pool`
--
ALTER TABLE `tenant_db_pool`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_tenant_db_pool_connection_key` (`connection_key`),
  ADD UNIQUE KEY `uq_tenant_db_pool_db_name` (`db_name`),
  ADD KEY `idx_tenant_db_pool_status` (`status`),
  ADD KEY `idx_tenant_db_pool_assigned_location` (`assigned_location_id`),
  ADD KEY `idx_tenant_db_pool_assigned_tenant` (`assigned_tenant_id`);

--
-- テーブルのインデックス `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_users_email` (`email`),
  ADD KEY `idx_users_tenant_id` (`tenant_id`);

--
-- テーブルのインデックス `user_locations`
--
ALTER TABLE `user_locations`
  ADD PRIMARY KEY (`user_id`,`location_id`),
  ADD KEY `idx_user_locations_location` (`location_id`);

--
-- ダンプしたテーブルの AUTO_INCREMENT
--

--
-- テーブルの AUTO_INCREMENT `admin_branch_db_assignments`
--
ALTER TABLE `admin_branch_db_assignments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- テーブルの AUTO_INCREMENT `admin_company_db_assignments`
--
ALTER TABLE `admin_company_db_assignments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- テーブルの AUTO_INCREMENT `admin_login_codes`
--
ALTER TABLE `admin_login_codes`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- テーブルの AUTO_INCREMENT `drug_aliases`
--
ALTER TABLE `drug_aliases`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- テーブルの AUTO_INCREMENT `drug_master`
--
ALTER TABLE `drug_master`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- テーブルの AUTO_INCREMENT `features`
--
ALTER TABLE `features`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- テーブルの AUTO_INCREMENT `locations`
--
ALTER TABLE `locations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- テーブルの AUTO_INCREMENT `main_audit_logs`
--
ALTER TABLE `main_audit_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- テーブルの AUTO_INCREMENT `shared_inventory`
--
ALTER TABLE `shared_inventory`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- テーブルの AUTO_INCREMENT `super_admin_users`
--
ALTER TABLE `super_admin_users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- テーブルの AUTO_INCREMENT `system_migrations`
--
ALTER TABLE `system_migrations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- テーブルの AUTO_INCREMENT `tenants`
--
ALTER TABLE `tenants`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- テーブルの AUTO_INCREMENT `tenant_db_connections`
--
ALTER TABLE `tenant_db_connections`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- テーブルの AUTO_INCREMENT `tenant_db_pool`
--
ALTER TABLE `tenant_db_pool`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- テーブルの AUTO_INCREMENT `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- ダンプしたテーブルの制約
--

--
-- テーブルの制約 `admin_login_codes`
--
ALTER TABLE `admin_login_codes`
  ADD CONSTRAINT `fk_admin_login_codes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- テーブルの制約 `drug_aliases`
--
ALTER TABLE `drug_aliases`
  ADD CONSTRAINT `fk_drug_aliases_drug` FOREIGN KEY (`drug_id`) REFERENCES `drug_master` (`id`);

--
-- テーブルの制約 `locations`
--
ALTER TABLE `locations`
  ADD CONSTRAINT `fk_locations_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`);

--
-- テーブルの制約 `location_features`
--
ALTER TABLE `location_features`
  ADD CONSTRAINT `fk_location_features_feature` FOREIGN KEY (`feature_id`) REFERENCES `features` (`id`),
  ADD CONSTRAINT `fk_location_features_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`);

--
-- テーブルの制約 `main_audit_logs`
--
ALTER TABLE `main_audit_logs`
  ADD CONSTRAINT `fk_main_audit_logs_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`),
  ADD CONSTRAINT `fk_main_audit_logs_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  ADD CONSTRAINT `fk_main_audit_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- テーブルの制約 `shared_inventory`
--
ALTER TABLE `shared_inventory`
  ADD CONSTRAINT `fk_shared_inventory_drug` FOREIGN KEY (`drug_id`) REFERENCES `drug_master` (`id`),
  ADD CONSTRAINT `fk_shared_inventory_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`);

--
-- テーブルの制約 `tenant_db_connections`
--
ALTER TABLE `tenant_db_connections`
  ADD CONSTRAINT `fk_tenant_db_connections_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`),
  ADD CONSTRAINT `fk_tenant_db_connections_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`);

--
-- テーブルの制約 `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`);

--
-- テーブルの制約 `user_locations`
--
ALTER TABLE `user_locations`
  ADD CONSTRAINT `fk_user_locations_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`),
  ADD CONSTRAINT `fk_user_locations_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

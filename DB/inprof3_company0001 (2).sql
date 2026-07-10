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
-- データベース: `inprof3_company0001`
--

-- --------------------------------------------------------

--
-- テーブルの構造 `company_branch_refs`
--

CREATE TABLE `company_branch_refs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_uid` varchar(80) NOT NULL,
  `branch_uid` varchar(80) NOT NULL,
  `branch_code` varchar(80) DEFAULT NULL,
  `branch_name` varchar(160) NOT NULL,
  `branch_db_suffix` char(4) NOT NULL,
  `branch_db_name` varchar(128) NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('active','inactive','maintenance') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- テーブルのデータのダンプ `company_branch_refs`
--

INSERT INTO `company_branch_refs` (`id`, `company_uid`, `branch_uid`, `branch_code`, `branch_name`, `branch_db_suffix`, `branch_db_name`, `is_default`, `status`, `created_at`, `updated_at`) VALUES
(1, 'cmp_0001', 'br_0001', 'main', '本店', '0001', 'inprof3_tenants0001', 1, 'active', '2026-07-01 10:26:45', '2026-07-01 10:26:45'),
(2, 'cmp_0001', 'br_0002', 'branch-a', '支店A', '0002', 'inprof3_tenants0002', 0, 'inactive', '2026-07-01 10:26:45', '2026-07-01 10:26:45');

-- --------------------------------------------------------

--
-- テーブルの構造 `company_profile`
--

CREATE TABLE `company_profile` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_uid` varchar(80) NOT NULL,
  `tenant_id` bigint(20) UNSIGNED DEFAULT NULL,
  `company_code` varchar(80) DEFAULT NULL,
  `company_name` varchar(160) NOT NULL,
  `company_db_suffix` char(4) NOT NULL,
  `company_db_name` varchar(128) NOT NULL,
  `plan_name` varchar(60) NOT NULL DEFAULT 'demo',
  `status` enum('trial','active','suspended','cancelled') NOT NULL DEFAULT 'trial',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- テーブルのデータのダンプ `company_profile`
--

INSERT INTO `company_profile` (`id`, `company_uid`, `tenant_id`, `company_code`, `company_name`, `company_db_suffix`, `company_db_name`, `plan_name`, `status`, `created_at`, `updated_at`) VALUES
(1, 'cmp_0001', 1, 'demo-tenant', 'ファーマ薬局グループ', '0001', 'inprof3_company0001', 'demo', 'trial', '2026-07-01 10:26:45', '2026-07-01 10:26:45');

-- --------------------------------------------------------

--
-- テーブルの構造 `company_settings`
--

CREATE TABLE `company_settings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_uid` varchar(80) NOT NULL,
  `setting_key` varchar(120) NOT NULL,
  `setting_value_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`setting_value_json`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- テーブルのデータのダンプ `company_settings`
--

INSERT INTO `company_settings` (`id`, `company_uid`, `setting_key`, `setting_value_json`, `created_at`, `updated_at`) VALUES
(1, 'cmp_0001', 'saas_db_policy', '{\"company_db_pattern\": \"inprof3_company0001\", \"branch_db_pattern\": \"inprof3_tenants0001\"}', '2026-07-01 10:26:45', '2026-07-01 10:26:45');

-- --------------------------------------------------------

--
-- テーブルの構造 `company_user_refs`
--

CREATE TABLE `company_user_refs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_uid` varchar(80) NOT NULL,
  `admin_user_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `email` varchar(190) NOT NULL,
  `role` varchar(40) NOT NULL DEFAULT 'pharmacy_user',
  `status` enum('active','locked','deleted') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
(1, '20260630_005_company_saas_base.sql', '2026-07-01 10:26:45');

--
-- ダンプしたテーブルのインデックス
--

--
-- テーブルのインデックス `company_branch_refs`
--
ALTER TABLE `company_branch_refs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_company_branch` (`company_uid`,`branch_uid`),
  ADD KEY `idx_branch_uid` (`branch_uid`),
  ADD KEY `idx_branch_status` (`status`);

--
-- テーブルのインデックス `company_profile`
--
ALTER TABLE `company_profile`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_company_uid` (`company_uid`),
  ADD KEY `idx_company_status` (`status`);

--
-- テーブルのインデックス `company_settings`
--
ALTER TABLE `company_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_company_setting` (`company_uid`,`setting_key`);

--
-- テーブルのインデックス `company_user_refs`
--
ALTER TABLE `company_user_refs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_company_admin_user` (`company_uid`,`admin_user_id`),
  ADD KEY `idx_company_email` (`company_uid`,`email`);

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
-- テーブルの AUTO_INCREMENT `company_branch_refs`
--
ALTER TABLE `company_branch_refs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- テーブルの AUTO_INCREMENT `company_profile`
--
ALTER TABLE `company_profile`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- テーブルの AUTO_INCREMENT `company_settings`
--
ALTER TABLE `company_settings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- テーブルの AUTO_INCREMENT `company_user_refs`
--
ALTER TABLE `company_user_refs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- テーブルの AUTO_INCREMENT `system_migrations`
--
ALTER TABLE `system_migrations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

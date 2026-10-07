-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Oct 07, 2026 at 10:57 AM
-- Server version: 11.4.12-MariaDB-ubu2204
-- PHP Version: 8.4.25

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `data_12steptoolkit`
--

-- --------------------------------------------------------

--
-- Table structure for table `accounts`
--

CREATE TABLE `accounts` (
  `id` int(11) NOT NULL,
  `verified` int(11) NOT NULL DEFAULT 0,
  `verificationcode` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone_number` varchar(20) NOT NULL,
  `hashed_password` varchar(256) NOT NULL,
  `password` varchar(20) NOT NULL,
  `password_set` varchar(20) NOT NULL,
  `accounttype` int(11) NOT NULL DEFAULT 1 COMMENT '1 = aa, 2 = na',
  `sobrietydate` varchar(30) NOT NULL,
  `sobrietytime` varchar(5) NOT NULL,
  `timestamp` varchar(100) NOT NULL,
  `devicetype` int(1) NOT NULL DEFAULT 1 COMMENT '1=Apple, 2=Android, 3=Web',
  `software_version` int(2) NOT NULL DEFAULT 1,
  `last_login_tstamp` int(11) NOT NULL DEFAULT 0,
  `sociallogin` int(1) NOT NULL COMMENT '1 = fb, 2 = google',
  `lastlogin` varchar(100) NOT NULL,
  `logincount` int(11) NOT NULL,
  `fbid` varchar(40) NOT NULL,
  `googleid` varchar(40) NOT NULL,
  `appleid` varchar(255) NOT NULL DEFAULT '',
  `dailymoney` int(11) NOT NULL DEFAULT 0,
  `dailyunits` int(11) NOT NULL DEFAULT 0,
  `currency` int(11) NOT NULL DEFAULT 0,
  `free_upgrade` int(1) NOT NULL DEFAULT 0 COMMENT 'Free Upgrade From FB Email Glitch',
  `step2` varchar(11) NOT NULL,
  `step3` varchar(11) NOT NULL,
  `step6` varchar(11) NOT NULL,
  `step7` varchar(11) NOT NULL,
  `newsletter_subscribed` int(1) NOT NULL DEFAULT 0 COMMENT '0 = Not Subscribed, 1 = Subscribed',
  `nickname` varchar(50) NOT NULL DEFAULT ' ',
  `icon` int(2) NOT NULL DEFAULT 0,
  `hp` int(2) NOT NULL DEFAULT 0,
  `fcm_token` varchar(255) NOT NULL,
  `subscribed` int(1) NOT NULL DEFAULT 0,
  `ip_address` varchar(30) NOT NULL DEFAULT '0',
  `created_timestamp` int(11) NOT NULL,
  `on_boarding_completed_timestamp` int(11) NOT NULL,
  `vcode_expires` int(11) NOT NULL DEFAULT current_timestamp(),
  `created` timestamp NOT NULL DEFAULT current_timestamp(),
  `modified` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00' ON UPDATE current_timestamp(),
  `deletion_timestamp` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `account_details`
--

CREATE TABLE `account_details` (
  `id` int(11) NOT NULL,
  `accountid` int(11) NOT NULL,
  `phonenumber` varchar(20) NOT NULL DEFAULT '',
  `countrycode` varchar(4) NOT NULL DEFAULT '',
  `country` varchar(150) NOT NULL,
  `lastseen` int(11) NOT NULL DEFAULT 0,
  `accept_new_sponsees` varchar(5) NOT NULL DEFAULT 'false',
  `age` int(2) NOT NULL DEFAULT -1,
  `gender` int(2) NOT NULL DEFAULT -1,
  `profession` int(2) NOT NULL DEFAULT -1,
  `about` varchar(100) NOT NULL,
  `created` datetime NOT NULL DEFAULT current_timestamp(),
  `modified` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `accept_new_sponsor` int(1) NOT NULL DEFAULT 0,
  `accept_new_chat` int(1) NOT NULL DEFAULT 0,
  `notification_morning` varchar(5) NOT NULL DEFAULT '07:30',
  `notification_night` varchar(5) NOT NULL DEFAULT '22:00',
  `notification_hourly_start` varchar(5) NOT NULL DEFAULT '08:00',
  `notification_hourly_end` varchar(5) NOT NULL DEFAULT '21:00',
  `timezone` varchar(64) NOT NULL,
  `language` varchar(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `amends`
--

CREATE TABLE `amends` (
  `id` int(11) NOT NULL,
  `accountid` int(11) NOT NULL,
  `amendstitle` varchar(50) NOT NULL,
  `amendsfor` varchar(1000) NOT NULL,
  `amendsdone` int(11) NOT NULL DEFAULT 0 COMMENT '0 = No, 1 = Yes',
  `amendsdate` varchar(20) NOT NULL,
  `timestamp` varchar(100) NOT NULL,
  `amendsnotes` varchar(1000) NOT NULL,
  `tstamp` bigint(20) NOT NULL DEFAULT 0,
  `reviewed` int(1) NOT NULL DEFAULT 0,
  `created` timestamp NOT NULL DEFAULT current_timestamp(),
  `modified` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `appsettings`
--

CREATE TABLE `appsettings` (
  `id` int(11) NOT NULL,
  `MAX_TITLE` int(6) NOT NULL,
  `MAX_TITLE_PRO` int(6) NOT NULL,
  `MAX_DESCRIPTION` int(6) NOT NULL,
  `MAX_DESCRIPTION_PRO` int(6) NOT NULL,
  `MAX_NOTES` int(6) NOT NULL,
  `MAX_NOTES_PRO` int(6) NOT NULL,
  `MAX_MEETING_SEARCHES` int(6) NOT NULL,
  `MAX_MEETING_SEARCHES_PRO` int(11) NOT NULL,
  `MAX_GROUPS_FREE` int(1) NOT NULL,
  `TIME_BETWEEN_ADS` int(2) NOT NULL,
  `TAPS_BETWEEN_ADS` int(3) NOT NULL,
  `TIME_BETWEEN_APP_OPEN` int(4) NOT NULL,
  `SPONSOR_COUNT` int(11) NOT NULL,
  `SALE_TITLE` varchar(50) NOT NULL,
  `SALE_START` int(11) NOT NULL,
  `SALE_END` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `app_settings`
--

CREATE TABLE `app_settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(50) NOT NULL,
  `version_added` varchar(10) NOT NULL,
  `description` varchar(200) NOT NULL,
  `setting_value` int(11) NOT NULL,
  `setting_description` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `blocked_users`
--

CREATE TABLE `blocked_users` (
  `id` int(11) NOT NULL,
  `blocker_id` int(11) NOT NULL,
  `blocked_id` int(11) NOT NULL,
  `reason` text DEFAULT NULL,
  `reason_code` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `modified` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `builds`
--

CREATE TABLE `builds` (
  `id` int(11) NOT NULL,
  `build_type` int(1) NOT NULL COMMENT '1 = iOS, 2 = Android',
  `version_name` text NOT NULL DEFAULT '\'\'',
  `version_code` int(11) NOT NULL,
  `expires` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `comments`
--

CREATE TABLE `comments` (
  `id` int(11) NOT NULL,
  `thread_id` int(11) NOT NULL DEFAULT 0,
  `accountid` int(11) NOT NULL DEFAULT 0,
  `sponsorid` int(11) NOT NULL DEFAULT 0,
  `step` int(11) NOT NULL DEFAULT 0,
  `recordid` int(11) NOT NULL DEFAULT 0,
  `byid` int(11) NOT NULL DEFAULT 0,
  `comment` varchar(2000) NOT NULL,
  `tstamp` bigint(20) NOT NULL DEFAULT 0,
  `deleted` int(1) NOT NULL DEFAULT 0,
  `seen` int(11) NOT NULL DEFAULT 0,
  `time_sent` datetime NOT NULL,
  `modified` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `comment_reactions`
--

CREATE TABLE `comment_reactions` (
  `id` int(11) NOT NULL,
  `comment_id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `reaction` varchar(50) NOT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `modified` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `comment_receipts`
--

CREATE TABLE `comment_receipts` (
  `id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `comment_id` int(11) NOT NULL,
  `time_delivered` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `time_read` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `modified` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `comment_stars`
--

CREATE TABLE `comment_stars` (
  `id` int(11) NOT NULL,
  `comment_id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `modified` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `comment_threads`
--

CREATE TABLE `comment_threads` (
  `id` int(11) NOT NULL,
  `title` varchar(100) NOT NULL DEFAULT ' ',
  `description` varchar(255) NOT NULL DEFAULT ' ',
  `icon` int(11) NOT NULL DEFAULT 0,
  `created_by` int(11) NOT NULL,
  `created` int(11) NOT NULL DEFAULT 0,
  `is_group` tinyint(1) NOT NULL DEFAULT 0,
  `is_public` tinyint(1) NOT NULL DEFAULT 0,
  `accepting_new_members` tinyint(1) NOT NULL DEFAULT 1,
  `max_members` smallint(5) UNSIGNED NOT NULL DEFAULT 100,
  `is_muted` tinyint(1) NOT NULL DEFAULT 0,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `modified` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `comment_thread_subscribers`
--

CREATE TABLE `comment_thread_subscribers` (
  `id` int(11) NOT NULL,
  `thread_id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `subscribed_at` int(11) DEFAULT NULL,
  `is_subscribed` tinyint(1) NOT NULL DEFAULT 1,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `is_admin` tinyint(1) NOT NULL DEFAULT 0,
  `is_typing` tinyint(1) NOT NULL DEFAULT 0,
  `last_typing_at` datetime DEFAULT NULL,
  `is_muted` tinyint(1) NOT NULL DEFAULT 0,
  `modified` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `comment_thread_subscriber_history`
--

CREATE TABLE `comment_thread_subscriber_history` (
  `id` int(11) NOT NULL,
  `thread_id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `is_subscribed` tinyint(1) NOT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `changed_tstamp` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `devices`
--

CREATE TABLE `devices` (
  `id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL DEFAULT 0,
  `device_type` int(11) NOT NULL COMMENT '1 =Apple, 2 = Android, 3 = Web',
  `fcm_code` varchar(200) NOT NULL,
  `login_count` int(11) NOT NULL DEFAULT 0,
  `random_device_token` varchar(50) NOT NULL,
  `random_device_token_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `fcm_updated_time_stamp` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `fcm_update_count` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `gratitudes`
--

CREATE TABLE `gratitudes` (
  `id` int(11) NOT NULL,
  `accountid` int(11) NOT NULL,
  `description` varchar(5000) NOT NULL,
  `timestamp` varchar(100) NOT NULL,
  `tstamp` bigint(11) NOT NULL,
  `created` timestamp NOT NULL DEFAULT current_timestamp(),
  `modified` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00' ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `group_invites`
--

CREATE TABLE `group_invites` (
  `id` int(11) NOT NULL,
  `thread_id` int(11) NOT NULL,
  `created_by` int(11) NOT NULL,
  `code` char(32) NOT NULL,
  `role` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `max_uses` int(10) UNSIGNED NOT NULL DEFAULT 10,
  `uses` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `expires_at` datetime NOT NULL,
  `revoked` tinyint(1) NOT NULL DEFAULT 0,
  `created` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `icons`
--

CREATE TABLE `icons` (
  `id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `image_url` varchar(255) NOT NULL,
  `created` datetime NOT NULL DEFAULT current_timestamp(),
  `modified` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `install_secrets`
--

CREATE TABLE `install_secrets` (
  `id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `device_id` varchar(128) NOT NULL,
  `secret` binary(32) NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created` datetime NOT NULL DEFAULT current_timestamp(),
  `modified` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventories`
--

CREATE TABLE `inventories` (
  `id` int(4) NOT NULL,
  `accountid` int(11) NOT NULL DEFAULT 1,
  `inventoryforstep` int(1) NOT NULL DEFAULT 10 COMMENT '4 or 10',
  `invtype` int(4) NOT NULL DEFAULT 1 COMMENT '1 - resentment, 2 - fear, 3 - harm, 4- sex',
  `invtitle` varchar(100) NOT NULL,
  `invdescription` varchar(2000) NOT NULL,
  `affectsmyint` varchar(100) NOT NULL,
  `affectsmy` varchar(100) NOT NULL,
  `myfault` varchar(2000) NOT NULL,
  `shared` int(1) NOT NULL DEFAULT 0 COMMENT 'Step 4 Shared? 0 = No, 1 = Yes',
  `shareddate` varchar(20) NOT NULL COMMENT 'Step 4 - Date shared with Sponsor',
  `apologyowed` int(1) NOT NULL DEFAULT 0 COMMENT 'Step 10 Apology Owed? 0 = No, 1 = Yes',
  `apologydone` int(1) NOT NULL DEFAULT 0 COMMENT '0 = no, 1 = yes',
  `apologydate` varchar(20) NOT NULL COMMENT 'Step 10 - Date apologies if apology owed',
  `apologynotes` varchar(2000) NOT NULL,
  `timestamp` varchar(100) NOT NULL,
  `tstamp` bigint(20) NOT NULL DEFAULT 0,
  `reviewed` int(1) NOT NULL DEFAULT 0,
  `created` timestamp NOT NULL DEFAULT current_timestamp(),
  `modified` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `journals`
--

CREATE TABLE `journals` (
  `id` int(11) NOT NULL,
  `accountid` int(11) NOT NULL,
  `description` varchar(5000) NOT NULL,
  `timestamp` varchar(100) NOT NULL,
  `tstamp` bigint(11) NOT NULL,
  `created` timestamp NOT NULL DEFAULT current_timestamp(),
  `modified` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lastseen_requests`
--

CREATE TABLE `lastseen_requests` (
  `id` int(11) NOT NULL,
  `accountid` int(11) NOT NULL,
  `forid` int(11) NOT NULL,
  `timestamp` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `meeting_locations`
--

CREATE TABLE `meeting_locations` (
  `id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `location_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `mornings`
--

CREATE TABLE `mornings` (
  `id` int(11) NOT NULL,
  `accountid` int(11) NOT NULL DEFAULT 0,
  `icons` varchar(255) NOT NULL DEFAULT '1',
  `q2` int(1) NOT NULL DEFAULT 0,
  `q3` int(1) NOT NULL DEFAULT 0,
  `q4` int(1) NOT NULL DEFAULT 0,
  `q5` int(1) NOT NULL DEFAULT 0,
  `q6_notes` varchar(2000) NOT NULL DEFAULT '',
  `tstamp` bigint(20) NOT NULL DEFAULT 0,
  `created` timestamp NOT NULL DEFAULT current_timestamp(),
  `modified` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `nights`
--

CREATE TABLE `nights` (
  `id` int(11) NOT NULL,
  `accountid` int(11) NOT NULL,
  `sw1` varchar(3) NOT NULL,
  `sw2` varchar(3) NOT NULL,
  `sw3` varchar(3) NOT NULL,
  `sw4` varchar(3) NOT NULL,
  `sw5` varchar(3) NOT NULL,
  `sw6` varchar(3) NOT NULL,
  `sw7` varchar(3) NOT NULL,
  `sw9` varchar(3) NOT NULL,
  `sw10` varchar(3) NOT NULL,
  `sw11` varchar(3) NOT NULL DEFAULT 'No',
  `sw12` varchar(3) NOT NULL DEFAULT 'No',
  `desc1` varchar(2000) NOT NULL,
  `desc2` varchar(2000) NOT NULL,
  `desc3` varchar(2000) NOT NULL,
  `desc4` varchar(2000) NOT NULL,
  `desc5` varchar(2000) NOT NULL,
  `desc6` varchar(2000) NOT NULL,
  `desc7` varchar(2000) NOT NULL,
  `desc8` varchar(2000) NOT NULL,
  `desc9` varchar(2000) NOT NULL,
  `desc10` varchar(2000) NOT NULL,
  `desc11` varchar(2000) NOT NULL,
  `desc12` varchar(2000) NOT NULL,
  `timestamp` varchar(100) NOT NULL,
  `thedate` varchar(50) NOT NULL,
  `tstamp` bigint(20) NOT NULL DEFAULT 0,
  `tdate` bigint(20) NOT NULL DEFAULT 0,
  `reviewed` int(1) NOT NULL DEFAULT 0,
  `created` timestamp NOT NULL DEFAULT current_timestamp(),
  `modified` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00' ON UPDATE current_timestamp(),
  `for_date` bigint(20) NOT NULL COMMENT 'Added 2025_01_02 for Web\r\n'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notification_texts`
--

CREATE TABLE `notification_texts` (
  `id` int(11) NOT NULL,
  `type` enum('MORNING','NIGHT','HOURLY') NOT NULL,
  `language` varchar(10) NOT NULL DEFAULT 'en',
  `description` text NOT NULL,
  `description_hash` binary(16) GENERATED ALWAYS AS (unhex(md5(`description`))) STORED,
  `last_used` datetime DEFAULT NULL,
  `times_used` int(11) NOT NULL DEFAULT 0,
  `created` datetime NOT NULL DEFAULT current_timestamp(),
  `modified` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` int(11) NOT NULL,
  `accountid` int(11) NOT NULL,
  `orderid` varchar(30) NOT NULL,
  `timestamp` varchar(100) NOT NULL,
  `sku` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(10) UNSIGNED NOT NULL,
  `account_id` int(10) UNSIGNED NOT NULL,
  `reset_code` char(32) NOT NULL,
  `expires_at` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `quotes`
--

CREATE TABLE `quotes` (
  `id` int(3) NOT NULL,
  `description` varchar(2000) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reminder_subscriptions`
--

CREATE TABLE `reminder_subscriptions` (
  `id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `type` enum('MORNING','NIGHT','HOURLY') NOT NULL,
  `hhmm` char(5) NOT NULL,
  `days_mask` tinyint(3) UNSIGNED NOT NULL DEFAULT 127,
  `timezone` varchar(64) NOT NULL,
  `language` varchar(10) NOT NULL DEFAULT 'en',
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `snoozed_until` datetime DEFAULT NULL,
  `last_fired_at` datetime DEFAULT NULL,
  `next_fire_at` datetime NOT NULL,
  `created` datetime NOT NULL DEFAULT current_timestamp(),
  `modified` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reported_users`
--

CREATE TABLE `reported_users` (
  `id` int(11) NOT NULL,
  `reporter_id` int(11) NOT NULL,
  `reported_id` int(11) DEFAULT NULL,
  `reported_thread_id` int(11) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `reason_code` int(11) NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `admin_notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reviewed`
--

CREATE TABLE `reviewed` (
  `id` int(11) NOT NULL,
  `inventory_id` int(11) NOT NULL DEFAULT 0,
  `sponsorid` int(11) NOT NULL DEFAULT 0,
  `type` int(11) NOT NULL DEFAULT 0,
  `tstamp` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sale`
--

CREATE TABLE `sale` (
  `id` int(11) NOT NULL,
  `sale_title` varchar(100) NOT NULL,
  `sale_start_ts` bigint(20) NOT NULL,
  `sale_end_ts` bigint(20) NOT NULL,
  `sale_start_dt` datetime NOT NULL,
  `sale_end_dt` datetime NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created` timestamp NULL DEFAULT current_timestamp(),
  `modified` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `sample`
--

CREATE TABLE `sample` (
  `invtype` int(4) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `serverstatus`
--

CREATE TABLE `serverstatus` (
  `id` int(11) NOT NULL,
  `MaintenanceMode` int(11) NOT NULL COMMENT '0 = No, 1 = Yes',
  `MaintenanceNotice` varchar(200) NOT NULL,
  `latestversion` int(4) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sponsee_orders`
--

CREATE TABLE `sponsee_orders` (
  `id` int(11) NOT NULL,
  `accountid` int(11) NOT NULL,
  `orderid` varchar(30) NOT NULL,
  `tstamp` bigint(100) NOT NULL,
  `sku` varchar(50) NOT NULL,
  `months` int(2) NOT NULL DEFAULT 0,
  `quantity` int(2) NOT NULL DEFAULT 0,
  `price` varchar(100) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sponsee_order_users`
--

CREATE TABLE `sponsee_order_users` (
  `id` int(11) NOT NULL,
  `sponsee_order_id` int(11) NOT NULL DEFAULT 0,
  `sponseeid` int(11) NOT NULL DEFAULT 0,
  `tstamp` bigint(20) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sponsors`
--

CREATE TABLE `sponsors` (
  `id` int(11) NOT NULL,
  `sponsorid` int(11) NOT NULL,
  `sponseeid` int(11) NOT NULL,
  `status` int(1) NOT NULL DEFAULT 0 COMMENT '0 = pending, 1 = accepted, 2 = rejected, 3 = deleted, 4 = blocked',
  `relationship_direction` int(1) NOT NULL COMMENT '1 - sponsor to sponsee, 2 - sponsee to sponsor',
  `requested_tstamp` bigint(20) NOT NULL DEFAULT 0,
  `accepted_tstamp` bigint(20) NOT NULL DEFAULT 0,
  `rejected_tstamp` bigint(20) NOT NULL DEFAULT 0 COMMENT 'also used to signify accepted by id if status = 0',
  `deleted_tstamp` bigint(20) NOT NULL DEFAULT 0,
  `blocked_tstamp` bigint(20) NOT NULL DEFAULT 0,
  `rejected_by` int(11) NOT NULL DEFAULT 0,
  `blocked_by` int(11) NOT NULL DEFAULT 0,
  `modified` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `step12`
--

CREATE TABLE `step12` (
  `id` int(11) NOT NULL,
  `accountid` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `subscription_orders`
--

CREATE TABLE `subscription_orders` (
  `id` int(11) NOT NULL,
  `accountid` int(11) NOT NULL,
  `orderid` varchar(30) NOT NULL,
  `tstamp` bigint(100) NOT NULL,
  `sku` varchar(50) NOT NULL,
  `months` int(2) NOT NULL DEFAULT 0,
  `quantity` int(2) NOT NULL DEFAULT 0,
  `price` varchar(100) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_online_notifications`
--

CREATE TABLE `user_online_notifications` (
  `id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `sent_to_account_id` int(11) NOT NULL,
  `last_sent_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `accounts`
--
ALTER TABLE `accounts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_accounts_onboarding_created` (`on_boarding_completed_timestamp`,`created_timestamp`),
  ADD KEY `ix_accounts_vcode_expiry` (`vcode_expires`),
  ADD KEY `ix_accounts_created_device` (`created`,`devicetype`);

--
-- Indexes for table `account_details`
--
ALTER TABLE `account_details`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_accountid` (`accountid`),
  ADD KEY `ix_lastseen` (`lastseen`),
  ADD KEY `ix_language` (`language`);

--
-- Indexes for table `amends`
--
ALTER TABLE `amends`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `appsettings`
--
ALTER TABLE `appsettings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `app_settings`
--
ALTER TABLE `app_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `blocked_users`
--
ALTER TABLE `blocked_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_block_pair` (`blocker_id`,`blocked_id`),
  ADD KEY `ix_blocker` (`blocker_id`),
  ADD KEY `ix_blocked` (`blocked_id`);

--
-- Indexes for table `builds`
--
ALTER TABLE `builds`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `comments`
--
ALTER TABLE `comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_comments_thread_tstamp` (`thread_id`,`tstamp`),
  ADD KEY `ix_comments_account_tstamp` (`accountid`,`tstamp`),
  ADD KEY `ix_comments_recordid` (`recordid`),
  ADD KEY `ix_comments_byid` (`byid`),
  ADD KEY `ix_comments_seen` (`seen`),
  ADD KEY `ix_c_account_sponsor_by_seen_step` (`accountid`,`sponsorid`,`byid`,`seen`,`step`),
  ADD KEY `ix_comments_thread_time` (`thread_id`,`time_sent` DESC);

--
-- Indexes for table `comment_reactions`
--
ALTER TABLE `comment_reactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_comment_reaction` (`comment_id`,`account_id`,`reaction`),
  ADD KEY `idx_comment` (`comment_id`),
  ADD KEY `idx_account` (`account_id`);

--
-- Indexes for table `comment_receipts`
--
ALTER TABLE `comment_receipts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_receipt_account_comment` (`account_id`,`comment_id`),
  ADD KEY `ix_receipts_comment` (`comment_id`),
  ADD KEY `ix_receipts_account_delivered` (`account_id`,`time_delivered`);

--
-- Indexes for table `comment_stars`
--
ALTER TABLE `comment_stars`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_star_account_comment` (`account_id`,`comment_id`),
  ADD KEY `ix_stars_comment` (`comment_id`);

--
-- Indexes for table `comment_threads`
--
ALTER TABLE `comment_threads`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_threads_created_by` (`created_by`),
  ADD KEY `ix_threads_is_group_public` (`is_group`,`is_public`);

--
-- Indexes for table `comment_thread_subscribers`
--
ALTER TABLE `comment_thread_subscribers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_thread_account` (`thread_id`,`account_id`),
  ADD KEY `ix_subscribers_account` (`account_id`),
  ADD KEY `ix_subscribers_active` (`account_id`,`is_deleted`,`is_subscribed`);

--
-- Indexes for table `comment_thread_subscriber_history`
--
ALTER TABLE `comment_thread_subscriber_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_hist_thread` (`thread_id`),
  ADD KEY `ix_hist_account` (`account_id`);

--
-- Indexes for table `devices`
--
ALTER TABLE `devices`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `gratitudes`
--
ALTER TABLE `gratitudes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_gratitudes_account_time` (`accountid`,`tstamp` DESC);

--
-- Indexes for table `group_invites`
--
ALTER TABLE `group_invites`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_group_invites_code` (`code`),
  ADD KEY `ix_group_invites_thread` (`thread_id`),
  ADD KEY `ix_group_invites_creator` (`created_by`);

--
-- Indexes for table `icons`
--
ALTER TABLE `icons`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_icons_account_url` (`account_id`,`image_url`),
  ADD KEY `ix_icons_account` (`account_id`);

--
-- Indexes for table `install_secrets`
--
ALTER TABLE `install_secrets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_account_device` (`account_id`,`device_id`),
  ADD KEY `ix_install_secrets_account` (`account_id`);

--
-- Indexes for table `inventories`
--
ALTER TABLE `inventories`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `journals`
--
ALTER TABLE `journals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_journals_account_time` (`accountid`,`tstamp` DESC);

--
-- Indexes for table `lastseen_requests`
--
ALTER TABLE `lastseen_requests`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `meeting_locations`
--
ALTER TABLE `meeting_locations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `mornings`
--
ALTER TABLE `mornings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `nights`
--
ALTER TABLE `nights`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_nights_account_date` (`accountid`,`tdate` DESC);

--
-- Indexes for table `notification_texts`
--
ALTER TABLE `notification_texts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_type_lang_hash` (`type`,`language`,`description_hash`),
  ADD KEY `ix_pick_order` (`type`,`language`,`times_used`,`last_used`),
  ADD KEY `ix_language` (`language`);
ALTER TABLE `notification_texts` ADD FULLTEXT KEY `ft_description` (`description`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `reset_code` (`reset_code`),
  ADD KEY `account_id` (`account_id`);

--
-- Indexes for table `reminder_subscriptions`
--
ALTER TABLE `reminder_subscriptions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_account_type_hhmm` (`account_id`,`type`,`hhmm`),
  ADD KEY `idx_due` (`enabled`,`next_fire_at`),
  ADD KEY `idx_account` (`account_id`),
  ADD KEY `idx_due_lang` (`enabled`,`next_fire_at`,`language`);

--
-- Indexes for table `reported_users`
--
ALTER TABLE `reported_users`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_reporter_id` (`reporter_id`),
  ADD KEY `idx_reported_id` (`reported_id`),
  ADD KEY `idx_reported_thread` (`reported_thread_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `reviewed`
--
ALTER TABLE `reviewed`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sale`
--
ALTER TABLE `sale`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `serverstatus`
--
ALTER TABLE `serverstatus`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sponsee_orders`
--
ALTER TABLE `sponsee_orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_sponsee_orders_tstamp` (`tstamp`);

--
-- Indexes for table `sponsee_order_users`
--
ALTER TABLE `sponsee_order_users`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sponsors`
--
ALTER TABLE `sponsors`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_sponsors_sponseeid` (`sponseeid`),
  ADD KEY `ix_sponsors_sponsorid` (`sponsorid`),
  ADD KEY `ix_sponsors_status` (`status`),
  ADD KEY `ix_sponsors_pair` (`sponsorid`,`sponseeid`),
  ADD KEY `ix_sponsors_lookup_active` (`sponseeid`,`status`),
  ADD KEY `ix_sponsors_lookup_sponsor` (`sponsorid`,`status`);

--
-- Indexes for table `step12`
--
ALTER TABLE `step12`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `subscription_orders`
--
ALTER TABLE `subscription_orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_subscription_orders_tstamp` (`tstamp`);

--
-- Indexes for table `user_online_notifications`
--
ALTER TABLE `user_online_notifications`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `accounts`
--
ALTER TABLE `accounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `account_details`
--
ALTER TABLE `account_details`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `amends`
--
ALTER TABLE `amends`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `appsettings`
--
ALTER TABLE `appsettings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `app_settings`
--
ALTER TABLE `app_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `blocked_users`
--
ALTER TABLE `blocked_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `builds`
--
ALTER TABLE `builds`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `comments`
--
ALTER TABLE `comments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `comment_receipts`
--
ALTER TABLE `comment_receipts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `comment_stars`
--
ALTER TABLE `comment_stars`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `comment_threads`
--
ALTER TABLE `comment_threads`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `comment_thread_subscribers`
--
ALTER TABLE `comment_thread_subscribers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `comment_thread_subscriber_history`
--
ALTER TABLE `comment_thread_subscriber_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `devices`
--
ALTER TABLE `devices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `gratitudes`
--
ALTER TABLE `gratitudes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `group_invites`
--
ALTER TABLE `group_invites`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `icons`
--
ALTER TABLE `icons`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `install_secrets`
--
ALTER TABLE `install_secrets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventories`
--
ALTER TABLE `inventories`
  MODIFY `id` int(4) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `journals`
--
ALTER TABLE `journals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lastseen_requests`
--
ALTER TABLE `lastseen_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `meeting_locations`
--
ALTER TABLE `meeting_locations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `mornings`
--
ALTER TABLE `mornings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `nights`
--
ALTER TABLE `nights`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notification_texts`
--
ALTER TABLE `notification_texts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reminder_subscriptions`
--
ALTER TABLE `reminder_subscriptions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reported_users`
--
ALTER TABLE `reported_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sale`
--
ALTER TABLE `sale`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `serverstatus`
--
ALTER TABLE `serverstatus`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sponsee_orders`
--
ALTER TABLE `sponsee_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sponsee_order_users`
--
ALTER TABLE `sponsee_order_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sponsors`
--
ALTER TABLE `sponsors`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `step12`
--
ALTER TABLE `step12`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `subscription_orders`
--
ALTER TABLE `subscription_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_online_notifications`
--
ALTER TABLE `user_online_notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `account_details`
--
ALTER TABLE `account_details`
  ADD CONSTRAINT `fk_account_details_account` FOREIGN KEY (`accountid`) REFERENCES `accounts` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `blocked_users`
--
ALTER TABLE `blocked_users`
  ADD CONSTRAINT `fk_blocked_users_blocked` FOREIGN KEY (`blocked_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_blocked_users_blocker` FOREIGN KEY (`blocker_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `comment_receipts`
--
ALTER TABLE `comment_receipts`
  ADD CONSTRAINT `fk_receipts_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_receipts_comment` FOREIGN KEY (`comment_id`) REFERENCES `comments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `comment_stars`
--
ALTER TABLE `comment_stars`
  ADD CONSTRAINT `fk_stars_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_stars_comment` FOREIGN KEY (`comment_id`) REFERENCES `comments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `comment_threads`
--
ALTER TABLE `comment_threads`
  ADD CONSTRAINT `fk_threads_creator` FOREIGN KEY (`created_by`) REFERENCES `accounts` (`id`);

--
-- Constraints for table `comment_thread_subscribers`
--
ALTER TABLE `comment_thread_subscribers`
  ADD CONSTRAINT `fk_subscribers_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_subscribers_thread` FOREIGN KEY (`thread_id`) REFERENCES `comment_threads` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `comment_thread_subscriber_history`
--
ALTER TABLE `comment_thread_subscriber_history`
  ADD CONSTRAINT `fk_hist_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_hist_thread` FOREIGN KEY (`thread_id`) REFERENCES `comment_threads` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `group_invites`
--
ALTER TABLE `group_invites`
  ADD CONSTRAINT `fk_group_invites_creator` FOREIGN KEY (`created_by`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_group_invites_thread` FOREIGN KEY (`thread_id`) REFERENCES `comment_threads` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `icons`
--
ALTER TABLE `icons`
  ADD CONSTRAINT `fk_icons_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `install_secrets`
--
ALTER TABLE `install_secrets`
  ADD CONSTRAINT `fk_install_secrets_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `reminder_subscriptions`
--
ALTER TABLE `reminder_subscriptions`
  ADD CONSTRAINT `fk_reminders_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `reported_users`
--
ALTER TABLE `reported_users`
  ADD CONSTRAINT `fk_reports_reported_thread` FOREIGN KEY (`reported_thread_id`) REFERENCES `comment_threads` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_reports_reported_user` FOREIGN KEY (`reported_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_reports_reporter` FOREIGN KEY (`reporter_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

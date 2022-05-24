-- phpMyAdmin SQL Dump
-- version 5.1.1
-- https://www.phpmyadmin.net/
--
-- Host: mariadb_cms:3306
-- Generation Time: Apr 22, 2022 at 03:25 PM
-- Server version: 10.6.5-MariaDB-1:10.6.5+maria~focal
-- PHP Version: 7.4.20

SET
    SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET
    time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT = @@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS = @@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION = @@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `vdm_io`
--

-- --------------------------------------------------------

--
-- Table structure for table `kumwe_item`
--

CREATE TABLE `kumwe_item`
(
    `id`               int(10) UNSIGNED                                       NOT NULL,
    `title`            varchar(255) COLLATE utf8mb4_unicode_ci                NOT NULL DEFAULT '',
    `alias`            varchar(400) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
    `introtext`        mediumtext COLLATE utf8mb4_unicode_ci                  NOT NULL,
    `fulltext`         mediumtext COLLATE utf8mb4_unicode_ci                  NOT NULL,
    `state`            tinyint(4)                                             NOT NULL DEFAULT 0,
    `created`          datetime                                               NOT NULL,
    `created_by`       int(10) UNSIGNED                                       NOT NULL DEFAULT 0,
    `created_by_alias` varchar(255) COLLATE utf8mb4_unicode_ci                NOT NULL DEFAULT '',
    `modified`         datetime                                               NOT NULL,
    `modified_by`      int(10) UNSIGNED                                       NOT NULL DEFAULT 0,
    `checked_out`      int(10) UNSIGNED                                                DEFAULT NULL,
    `checked_out_time` datetime                                                        DEFAULT NULL,
    `publish_up`       datetime                                                        DEFAULT NULL,
    `publish_down`     datetime                                                        DEFAULT NULL,
    `version`          int(10) UNSIGNED                                       NOT NULL DEFAULT 1,
    `ordering`         int(11)                                                NOT NULL DEFAULT 0,
    `metakey`          text COLLATE utf8mb4_unicode_ci                                 DEFAULT NULL,
    `metadesc`         text COLLATE utf8mb4_unicode_ci                        NOT NULL,
    `hits`             int(10) UNSIGNED                                       NOT NULL DEFAULT 0,
    `metadata`         text COLLATE utf8mb4_unicode_ci                        NOT NULL,
    `params`           text COLLATE utf8mb4_unicode_ci                        NOT NULL,
    `featured`         tinyint(3) UNSIGNED                                    NOT NULL DEFAULT 0 COMMENT 'Set if article is featured.'
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `kumwe_menu`
--

CREATE TABLE `kumwe_menu`
(
    `id`               int(11)                                  NOT NULL,
    `title`            varchar(255) COLLATE utf8mb4_unicode_ci  NOT NULL COMMENT 'The display title of the menu item.',
    `alias`            varchar(400) COLLATE utf8mb4_unicode_ci  NOT NULL COMMENT 'The SEF alias of the menu item.',
    `path`             varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'The computed path of the menu item based on the alias field.',
    `published`        tinyint(4)                               NOT NULL DEFAULT 0 COMMENT 'The published state of the menu link.',
    `parent_id`        int(10) UNSIGNED                         NOT NULL DEFAULT 1 COMMENT 'The parent menu item in the menu tree.',
    `level`            int(10) UNSIGNED                         NOT NULL DEFAULT 0 COMMENT 'The relative level in the tree.',
    `item_id`          int(10) UNSIGNED                         NOT NULL DEFAULT 0 COMMENT 'FK to kumwe_item.id',
    `checked_out`      int(10) UNSIGNED                                  DEFAULT NULL COMMENT 'FK to kumwe_users.id',
    `checked_out_time` datetime                                          DEFAULT NULL COMMENT 'The time the menu item was checked out.',
    `params`           text COLLATE utf8mb4_unicode_ci          NOT NULL COMMENT 'JSON encoded data for the menu item.',
    `lft`              int(11)                                  NOT NULL DEFAULT 0 COMMENT 'Nested set lft.',
    `rgt`              int(11)                                  NOT NULL DEFAULT 0 COMMENT 'Nested set rgt.',
    `home`             tinyint(3) UNSIGNED                      NOT NULL DEFAULT 0 COMMENT 'Indicates if this menu item is the home or default page.',
    `publish_up`       datetime                                          DEFAULT NULL,
    `publish_down`     datetime                                          DEFAULT NULL
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `kumwe_session`
--

CREATE TABLE `kumwe_session`
(
    `session_id` varbinary(192) NOT NULL,
    `guest`      tinyint(3) UNSIGNED                     DEFAULT 1,
    `time`       int(11)        NOT NULL                 DEFAULT 0,
    `data`       mediumtext COLLATE utf8mb4_unicode_ci   DEFAULT NULL,
    `userid`     int(11)                                 DEFAULT 0,
    `username`   varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT ''
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `kumwe_usergroups`
--

CREATE TABLE `kumwe_usergroups`
(
    `id`     int(10) UNSIGNED                        NOT NULL COMMENT 'Primary Key',
    `title`  varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
    `params` text COLLATE utf8mb4_unicode_ci         NOT NULL
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

--
-- Dumping data for table `kumwe_usergroups`
--

INSERT INTO `kumwe_usergroups` (`id`, `title`, `params`)
VALUES (1, 'Administrator',
        '[{\"area\":\"user\",\"access\":\"CRUD\"},{\"area\":\"usergroup\",\"access\":\"CRUD\"},{\"area\":\"menu\",\"access\":\"CRUD\"},{\"area\":\"item\",\"access\":\"CRUD\"}]'),
       (2, 'Manager',
        '[{\"area\":\"user\",\"access\":\"CR\"},{\"area\":\"usergroup\",\"access\":\"\"},{\"area\":\"menu\",\"access\":\"CRU\"},{\"area\":\"item\",\"access\":\"CRU\"}]'),
       (3, 'Editor',
        '[{\"area\":\"user\",\"access\":\"\"},{\"area\":\"usergroup\",\"access\":\"\"},{\"area\":\"menu\",\"access\":\"\"},{\"area\":\"item\",\"access\":\"CRU\"}]');

-- --------------------------------------------------------

--
-- Table structure for table `kumwe_users`
--

CREATE TABLE `kumwe_users`
(
    `id`            int(11)                                 NOT NULL,
    `name`          varchar(400) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
    `username`      varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
    `email`         varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
    `password`      varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
    `block`         tinyint(4)                              NOT NULL DEFAULT 0,
    `sendEmail`     tinyint(4)                                       DEFAULT 0,
    `registerDate`  datetime                                NOT NULL,
    `lastvisitDate` datetime                                         DEFAULT NULL,
    `activation`    varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
    `params`        text COLLATE utf8mb4_unicode_ci         NOT NULL,
    `lastResetTime` datetime                                         DEFAULT NULL COMMENT 'Date of last password reset',
    `resetCount`    int(11)                                 NOT NULL DEFAULT 0 COMMENT 'Count of password resets since lastResetTime',
    `requireReset`  tinyint(4)                              NOT NULL DEFAULT 0 COMMENT 'Require user to reset password on next login'
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `kumwe_user_usergroup_map`
--

CREATE TABLE `kumwe_user_usergroup_map`
(
    `user_id`  int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Foreign Key to kumwe_users.id',
    `group_id` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Foreign Key to kumwe_usergroups.id'
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `kumwe_item`
--
ALTER TABLE `kumwe_item`
    ADD PRIMARY KEY (`id`),
    ADD KEY `idx_checkout` (`checked_out`),
    ADD KEY `idx_state` (`state`),
    ADD KEY `idx_createdby` (`created_by`),
    ADD KEY `idx_alias` (`alias`(191));

--
-- Indexes for table `kumwe_menu`
--
ALTER TABLE `kumwe_menu`
    ADD PRIMARY KEY (`id`),
    ADD KEY `idx_item` (`item_id`),
    ADD KEY `idx_left_right` (`lft`, `rgt`),
    ADD KEY `idx_alias` (`alias`(100)),
    ADD KEY `idx_path` (`path`(100));

--
-- Indexes for table `kumwe_session`
--
ALTER TABLE `kumwe_session`
    ADD PRIMARY KEY (`session_id`),
    ADD KEY `userid` (`userid`),
    ADD KEY `time` (`time`),
    ADD KEY `guest` (`guest`);

--
-- Indexes for table `kumwe_usergroups`
--
ALTER TABLE `kumwe_usergroups`
    ADD PRIMARY KEY (`id`),
    ADD UNIQUE KEY `idx_usergroup_title_lookup` (`title`);

--
-- Indexes for table `kumwe_users`
--
ALTER TABLE `kumwe_users`
    ADD PRIMARY KEY (`id`),
    ADD UNIQUE KEY `idx_username` (`username`),
    ADD KEY `idx_name` (`name`(100)),
    ADD KEY `idx_block` (`block`),
    ADD KEY `email` (`email`);

--
-- Indexes for table `kumwe_user_usergroup_map`
--
ALTER TABLE `kumwe_user_usergroup_map`
    ADD PRIMARY KEY (`user_id`, `group_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `kumwe_item`
--
ALTER TABLE `kumwe_item`
    MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `kumwe_menu`
--
ALTER TABLE `kumwe_menu`
    MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,
    AUTO_INCREMENT = 102;

--
-- AUTO_INCREMENT for table `kumwe_usergroups`
--
ALTER TABLE `kumwe_usergroups`
    MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Primary Key',
    AUTO_INCREMENT = 3;

--
-- AUTO_INCREMENT for table `kumwe_users`
--
ALTER TABLE `kumwe_users`
    MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT = @OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS = @OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION = @OLD_COLLATION_CONNECTION */;
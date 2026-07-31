SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

CREATE TABLE `cloud_accounts` (
                                  `id` int(11) NOT NULL,
                                  `uid` int(11) NOT NULL,
                                  `type` varchar(255) DEFAULT NULL,
                                  `user_id` varchar(255) DEFAULT NULL,
                                  `data` text,
                                  `timing` varchar(255) DEFAULT NULL,
                                  `cooling` varchar(255) DEFAULT NULL,
                                  `addtime` datetime DEFAULT NULL,
                                  `state` tinyint NOT NULL DEFAULT '1',
                                  `zid` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cloud_captcha` (
                                 `id` int(11) NOT NULL,
                                 `type` int(1) DEFAULT '1' COMMENT '类型',
                                 `code` varchar(64) DEFAULT NULL COMMENT '验证码',
                                 `send` varchar(20) NOT NULL COMMENT '收件人',
                                 `time` varchar(255) NOT NULL COMMENT '添加时间',
                                 `ip` varchar(20) NOT NULL COMMENT 'ip地址',
                                 `status` int(11) NOT NULL DEFAULT '0' COMMENT '状态'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cloud_configs` (
                                 `k` varchar(255) NOT NULL DEFAULT '',
                                 `v` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `cloud_configs` (`k`, `v`) VALUES
                                           ('vip_price_1', '1'),
                                           ('vip_price_2', '3'),
                                           ('vip_price_3', '5'),
                                           ('vip_price_4', '8'),
                                           ('vip_price_5', '0.1'),
                                           ('vip_price_6', '0.2'),
                                           ('quota_price_1', '1'),
                                           ('quota_price_2', '3'),
                                           ('quota_price_3', '5'),
                                           ('quota_price_4', '8'),
                                           ('agent_price_1', '10'),
                                           ('agent_price_2', '20'),
                                           ('agent_price_3', '50'),
                                           ('agent_give_z_1', '9'),
                                           ('agent_give_z_2', '8'),
                                           ('agent_give_z_3', '7'),
                                           ('site_price_1', '15'),
                                           ('site_price_2', '30'),
                                           ('site_price_3', '50'),
                                           ('site_price_4', '80'),
                                           ('login_system_type', '2'),
                                           ('reExecute_time', '300'),
                                           ('interval', '50'),
                                           ('reg_close', '0'),
                                           ('reg_iplimit', '0'),
                                           ('site_url', ''),
                                           ('OrderPlacementMethod','0');

CREATE TABLE `cloud_info` (
                              `sysid` int(11) NOT NULL,
                              `last` datetime DEFAULT NULL,
                              `times` bigint unsigned NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `cloud_info`(`sysid`, `last`) VALUES
    ('100', NULL);

CREATE TABLE `cloud_jobs` (
                              `id` int(11) NOT NULL,
                              `uid` int(11) DEFAULT NULL,
                              `type` varchar(255) DEFAULT NULL,
                              `user_id` varchar(255) DEFAULT NULL,
                              `do` varchar(255) DEFAULT NULL,
                              `data` text,
                              `state` tinyint NOT NULL DEFAULT '0',
                              `nextExecute` bigint unsigned NOT NULL DEFAULT '0',
                              `lastExecute` datetime DEFAULT NULL,
                              `zid` int(11) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cloud_kms` (
                             `id` int(11) NOT NULL,
                             `uid` int(11) DEFAULT NULL,
                             `type` varchar(255) DEFAULT NULL,
                             `km` varchar(255) DEFAULT NULL,
                             `value` varchar(255) DEFAULT NULL,
                             `useid` int(11) DEFAULT '0',
                             `addtime` varchar(255) DEFAULT NULL,
                             `usetime` datetime DEFAULT NULL,
                             `zid` int(11) NOT NULL DEFAULT '1'
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE `cloud_notice` (
                                `id` int(11) NOT NULL,
                                `type` int(11) DEFAULT '1',
                                `title` varchar(255) DEFAULT '',
                                `content` text,
                                `alert` int(11) DEFAULT '0',
                                `sort` int(11) DEFAULT '0',
                                `addtime` varchar(255) DEFAULT NULL,
                                `zid` int(11) DEFAULT '1'
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE `cloud_order` (
                               `trade_no` varchar(64) NOT NULL,
                               `type` varchar(20) DEFAULT NULL,
                               `uid` int(11) DEFAULT NULL,
                               `orderid` varchar(64) DEFAULT NULL,
                               `time` datetime DEFAULT NULL,
                               `name` varchar(64) DEFAULT NULL,
                               `money` decimal(10,2) NOT NULL DEFAULT '0.00',
                               `status` int(1) NOT NULL DEFAULT '0',
                               `zid` int(11) NOT NULL DEFAULT '1'
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE `cloud_pays` (
                              `id` int(11) NOT NULL,
                              `uid` int(11) NOT NULL,
                              `qq` char(20) DEFAULT NULL,
                              `orderid` char(64) DEFAULT NULL,
                              `addtime` datetime DEFAULT NULL,
                              `endtime` datetime DEFAULT NULL,
                              `name` char(64) DEFAULT NULL,
                              `money` decimal(6,2) NOT NULL DEFAULT '0.00',
                              `type` varchar(10) DEFAULT NULL,
                              `shop` varchar(225) DEFAULT NULL,
                              `shopid` int(11) NOT NULL DEFAULT '0',
                              `data` text NULL,
                              `status` tinyint(3) NOT NULL DEFAULT '0',
                              `zid` int(11) NOT NULL DEFAULT '1'
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE `cloud_tasks` (
                               `id` int(11) NOT NULL,
                               `type` varchar(64) DEFAULT NULL,
                               `name` varchar(255) DEFAULT NULL,
                               `describe` varchar(255) DEFAULT NULL,
                               `icon` varchar(255) DEFAULT NULL,
                               `execute_name` varchar(64) DEFAULT NULL,
                               `execute_url` varchar(255) DEFAULT NULL,
                               `execute_rate` int unsigned NOT NULL DEFAULT 86400,
                               `more` int(11) DEFAULT NULL,
                               `state` int(11) DEFAULT '1',
                               `vip` int(11) DEFAULT NULL,
                               `time` varchar(255) DEFAULT NULL,
                               `order` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `cloud_tasks` (`id`, `type`, `name`, `describe`, `icon`, `execute_name`, `execute_url`, `execute_rate`, `more`, `state`, `vip`, `time`, `order`) VALUES
                                                                                                                                                                 (1, 'netease', '每日签到', '网页和安卓签到得云贝', 'si si-music-tone-alt', 'sign', NULL, '86400', 0, 1, 1, '2022-01-01 00:00:00', 1),
                                                                                                                                                                 (2, 'netease', '每日登录', '升级必备任务', 'si si-flag', 'login_work', NULL, '86400', 0, 1, 1, '2022-01-01 00:00:00', 2),
                                                                                                                                                                 (3, 'netease', '音乐人任务', '音乐人必备任务', 'si si-heart', 'musician_task', NULL, '86400', 1, 1, 1, '2022-01-01 00:00:00', 3),
                                                                                                                                                                 (4, 'netease', '合伙人评分', '合伙人必备任务', 'si si-trophy', 'evaluate', NULL, '86400', 1, 1, 1, '2022-01-01 00:00:00', 4),
                                                                                                                                                                 (5, 'netease', '每日300首', '升级必备任务', 'si si-earphones', 'daka_new', NULL, '86400', 1, 1, 1, '2022-01-01 00:00:00', 5),
                                                                                                                                                                 (6, 'bilibili', '漫画任务', '漫画签到、分享', 'si si-drawer', 'manga', NULL, '86400', 0, 1, 1, '2022-01-01 00:00:00', 4),
                                                                                                                                                                 (7, 'bilibili', '每日礼包', '双端领取日常/周常礼包', 'si si-present', 'dailybag', NULL, '86400', 0, 1, 1, '2022-01-01 00:00:00', 5),
                                                                                                                                                                 (8, 'bilibili', '双端心跳', '双端心跳 (姥爷直播经验)', 'si si-heart', 'doubleheart', NULL, '86400', 0, 1, 1, '2022-01-01 00:00:00', 6),
                                                                                                                                                                 (9, 'bilibili', '友爱社签到', '主站任务', 'si si-trophy', 'groupsignIn', NULL, '86400', 0, 1, 1, '2022-01-01 00:00:00', 7),
                                                                                                                                                                 (10, 'bilibili', '心跳礼物', '日常心跳每日礼包礼物', 'si si-heart', 'giftheart', NULL, '86400', 0, 1, 1, '2022-01-01 00:00:00', 8),
                                                                                                                                                                 (11, 'bilibili', '直播任务', '直播每日任务(签到、观看)', 'si si-screen-desktop', 'dailytask', NULL, '86400', 0, 1, 1, '2022-01-01 00:00:00', 9),
                                                                                                                                                                 (12, 'bilibili', '兑换硬币', '银瓜子兑换硬币', 'si si-game-controller', 'silver2coin', NULL, '86400', 0, 1, 1, '2022-01-01 00:00:00', 10),
                                                                                                                                                                 (13, 'bilibili', '每日观看', '观看视频（主站任务）', 'si si-camcorder', 'watchaid', NULL, '86400', 0, 1, 1, '2022-01-01 00:00:00', 2),
                                                                                                                                                                 (15, 'bilibili', '全局配置', '全局直播间ID配置', 'si si-compass', 'globalroom', NULL, '86400', 0, 1, 0, '2022-01-01 00:00:00', 1),
                                                                                                                                                                 (16, 'bilibili', '每日分享', '分享视频（主站任务）', 'si si-paper-plane', 'shareaid', NULL, '86400', 0, 1, 1, '2022-01-01 00:00:00', 3),
                                                                                                                                                                 (17, 'bilibili', '每日投币', '投币视频（主站任务）', 'si si-badge', 'coinadd', NULL, '86400', 1, 1, 1, '2022-01-01 00:00:00', 4),
                                                                                                                                                                 (18, 'netease', '云贝任务', '完成云贝中心多项任务', 'si si-fire', 'yunbei_task', NULL, '86400', 0, 1, 1, '2022-01-01 00:00:00', 6),
                                                                                                                                                                 (19, 'netease', 'VIP成长任务', '黑胶乐签、VIP听歌与成长值领取', 'si si-diamond', 'vip_growth_task', NULL, '86400', 0, 1, 0, '2026-07-31 00:00:00', 7),
                                                                                                                                                                 (34,'heybox', '每日签到', '小黑盒APP每日签到任务', 'si si-rocket', 'sign', NULL, '86400', 0, 1, 1, '2022-01-01 00:00:00', 1);

CREATE TABLE `cloud_task_logs` (
                                   `id` int(11) NOT NULL,
                                   `type` varchar(255) DEFAULT NULL,
                                   `user_id` varchar(255) DEFAULT NULL,
                                   `do` text,
                                   `response` text,
                                   `addtime` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cloud_users` (
                               `uid` int(11) NOT NULL,
                               `web_id` int(11) DEFAULT NULL,
                               `username` varchar(255) NOT NULL,
                               `password` varchar(255) NOT NULL,
                               `token` varchar(255) DEFAULT NULL,
                               `qq` varchar(255) DEFAULT NULL,
                               `mail` varchar(255) DEFAULT NULL,
                               `nickname` varchar(255) DEFAULT NULL,
                               `money` decimal(10,2) DEFAULT '0.00',
                               `quota` int(11) DEFAULT '0',
                               `vip_start` date DEFAULT NULL,
                               `vip_end` date DEFAULT NULL,
                               `agent` int(11) DEFAULT NULL,
                               `power` int(11) DEFAULT NULL,
                               `login_ip` varchar(255) DEFAULT NULL,
                               `login_city` varchar(255) DEFAULT NULL,
                               `login_time` varchar(255) DEFAULT NULL,
                               `sid` text,
                               `state` tinyint NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cloud_weblist` (
                                 `web_id` int(11) NOT NULL COMMENT '站点编号',
                                 `sup_id` int(11) NOT NULL DEFAULT '0' COMMENT '上级ID',
                                 `user_id` int(11) NOT NULL DEFAULT '1' COMMENT '用户ID',
                                 `user_qq` varchar(255) DEFAULT NULL COMMENT '联系QQ',
                                 `mail` varchar(20) DEFAULT NULL,
                                 `webname` varchar(255) DEFAULT NULL,
                                 `title` varchar(20) DEFAULT NULL,
                                 `domain` varchar(255) DEFAULT NULL,
                                 `domain2` varchar(255) DEFAULT NULL,
                                 `keywords` varchar(255) DEFAULT NULL,
                                 `description` varchar(255) DEFAULT NULL,
                                 `qun` varchar(225) DEFAULT NULL,
                                 `start_time` date DEFAULT NULL COMMENT '开始时间',
                                 `end_time` date DEFAULT NULL COMMENT '结束时间',
                                 `icp` varchar(255) DEFAULT NULL,
                                 `index_template` VARCHAR(20) NOT NULL DEFAULT 'default',
                                 `login_template` VARCHAR(20) NOT NULL DEFAULT 'default',
                                 `index_bg` varchar(255) NOT NULL DEFAULT '/static/media/photos/background.jpg' COMMENT '首页背景',
                                 `index_mode` int(11) NULL,
                                 `index_url` varchar(64) NULL,
                                 `status` int(11) NOT NULL DEFAULT '1' COMMENT '运营状态',
                                 `prefix` varchar(225) DEFAULT NULL COMMENT '表',
                                 `web_key` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


ALTER TABLE `cloud_accounts`
    ADD PRIMARY KEY (`id`),
    ADD UNIQUE KEY `udx_accounts_identity` (`uid`,`type`(32),`user_id`(128)),
    ADD KEY `idx_accounts_user` (`uid`,`type`(32),`state`,`id`),
    ADD KEY `idx_accounts_lookup` (`type`(32),`user_id`(128),`state`),
    ADD KEY `idx_accounts_site` (`zid`,`id`);

ALTER TABLE `cloud_captcha`
    ADD PRIMARY KEY (`id`);

ALTER TABLE `cloud_configs`
    ADD PRIMARY KEY (`k`);

ALTER TABLE `cloud_info`
    ADD PRIMARY KEY (`sysid`);

ALTER TABLE `cloud_jobs`
    ADD PRIMARY KEY (`id`),
    ADD UNIQUE KEY `udx_jobs_identity` (`uid`,`type`(32),`user_id`(128),`do`(64)),
    ADD KEY `idx_jobs_due` (`type`(32),`state`,`nextExecute`,`id`),
    ADD KEY `idx_jobs_account` (`uid`,`type`(32),`user_id`(128),`state`),
    ADD KEY `idx_jobs_lookup` (`type`(32),`user_id`(128),`do`(64),`state`);

ALTER TABLE `cloud_kms`
    ADD PRIMARY KEY (`id`);

ALTER TABLE `cloud_notice`
    ADD PRIMARY KEY (`id`) USING BTREE;

ALTER TABLE `cloud_order`
    ADD PRIMARY KEY (`trade_no`);

ALTER TABLE `cloud_pays`
    ADD PRIMARY KEY (`id`);

ALTER TABLE `cloud_tasks`
    ADD PRIMARY KEY (`id`),
    ADD UNIQUE KEY `udx_type_execute_name` (`type`,`execute_name`);

ALTER TABLE `cloud_task_logs`
    ADD PRIMARY KEY (`id`),
    ADD KEY `idx_task_logs_account` (`type`(32),`user_id`(128),`addtime`,`id`),
    ADD KEY `idx_task_logs_retention` (`addtime`,`id`);

ALTER TABLE `cloud_users`
    ADD PRIMARY KEY (`uid`),
    ADD UNIQUE KEY `udx_users_username` (`username`),
    ADD KEY `idx_users_site_state` (`web_id`,`state`,`uid`),
    ADD KEY `idx_users_qq` (`qq`(64)),
    ADD KEY `idx_users_mail` (`mail`(128));

ALTER TABLE `cloud_weblist`
    ADD PRIMARY KEY (`web_id`),
    ADD KEY `idx_weblist_domain` (`domain`(128)),
    ADD KEY `idx_weblist_domain2` (`domain2`(128));


ALTER TABLE `cloud_accounts`
    MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cloud_captcha`
    MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cloud_jobs`
    MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cloud_kms`
    MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cloud_notice`
    MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cloud_pays`
    MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cloud_tasks`
    MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cloud_task_logs`
    MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cloud_users`
    MODIFY `uid` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cloud_weblist`
    MODIFY `web_id` int(11) NOT NULL AUTO_INCREMENT COMMENT '站点编号';
COMMIT;
SET AUTOCOMMIT = 1;

DROP TABLE IF EXISTS `file_info`;
CREATE TABLE `file_info` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    `project_id` INTEGER NOT NULL,
    `file_id` INTEGER NOT NULL,
    `file_size` INTEGER NOT NULL,
    `file_name` TEXT NOT NULL COLLATE NOCASE
);
CREATE UNIQUE INDEX `file_info_id` on `file_info` (`project_id`,`file_id`);

DROP TABLE IF EXISTS `project_info`;
CREATE TABLE `project_info` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    `project_id` INTEGER NOT NULL,
    `project_name` TEXT NOT NULL COLLATE NOCASE
);
CREATE UNIQUE INDEX `project_info_id` on `project_info` (`project_id`);

DROP TABLE IF EXISTS `system`;
CREATE TABLE `system` (
    `type` TEXT NOT NULL COLLATE NOCASE,
    `name` TEXT NOT NULL COLLATE NOCASE,
    `value` TEXT NOT NULL,
    primary key (`type`,`name`)
);

REPLACE INTO `system` (`type`,`name`,`value`) VALUES ('system','data_version','1');
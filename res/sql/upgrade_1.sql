ALTER TABLE `file_info` ADD COLUMN `file_size` INTEGER NOT NULL DEFAULT '0';

REPLACE INTO `system` (`type`,`name`,`value`) VALUES ('system','data_version','2');
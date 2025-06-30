<?php

/**
	Cleaning up obsolete backups for HejBit Decentralised Backup.
 **/

// Custom tables used exclusively by this plugin and for infrequent operations (backup)
// phpcs:disable WordPress.DB

// Fixes a security issue
if (!defined('ABSPATH')) {
    exit();
}



$upload_dir = wp_upload_dir();
$hejbit_upload_dir = $upload_dir['basedir'] . '/hejbit-backups/';

// Cleaning residual files (if they exist)
$filesInFtp = glob($hejbit_upload_dir . "hejbitSave_*");
foreach ($filesInFtp as $file) {
    wp_delete_file($file);
}

// phpcs:enable WordPress.DB
?>
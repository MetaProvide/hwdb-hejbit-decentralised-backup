<?php

/**
	Merge the files to be backed up into a single Zip for HejBit Decentralised Backup.
 **/

// Custom tables used exclusively by this plugin and for infrequent operations (backup)
// phpcs:disable WordPress.DB

// Fixes a security issue
if (!defined('ABSPATH')) {
	exit();
}

// Create the Zip archive
$zipMerge = new ZipArchive();

$upload_dir = wp_upload_dir();
$hejbit_upload_dir = $upload_dir['basedir'] . '/hejbit-backups/';

if (!file_exists($hejbit_upload_dir . "hejbitSave_final.zip")) {

	$zipMerge->open($hejbit_upload_dir . "hejbitSave_final.zip", ZipArchive::CREATE);
} else {

	$zipMerge->open($hejbit_upload_dir . "hejbitSave_final.zip");
};

// Add additional files to the Zip archive
$sqlFiles = glob($hejbit_upload_dir . "*.sql");

// SQL addition loop, used for OthersApps
foreach ($sqlFiles as $sqlFile) {
	$sqlFilename = basename($sqlFile);
	$zipMerge->addFile($sqlFile, $sqlFilename);
};

// Add config file for WordPress only if database-only mode is not active
if (get_option("hejbit_db_only_dlwcloud") !== "true") {

	$zipMerge->addFile(ABSPATH . "wp-config.php", "wordpress/wp-config.php");

	// Check if .htaccess exists before trying to add it
	if (file_exists(ABSPATH . ".htaccess")) {
		$zipMerge->addFile(ABSPATH . ".htaccess", "wordpress/.htaccess");
	}
};

$zipMerge->close();

foreach ($sqlFiles as $sqlFile) {
	$sqlFilename = basename($sqlFile);
	wp_delete_file($sqlFilename);
};

// Update the backup status in the database
$datafinish = array(
	"status" => 3
);
$wherefinish = array("finish" => 0);
$wpdb->update($wpdb->prefix . 'hejbit_saveInProgress', $datafinish, $wherefinish);

// Schedule the next step of the backup process
wp_schedule_single_event(time(), 'hejbit_SaveInProgress');

// phpcs:enable WordPress.DB
?>
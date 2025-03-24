<?php

/**
	Merge the files to be backed up into a single Zip for HejBit Decentralised Backup.
**/

if( !defined( 'ABSPATH' ) ){ exit(); }

// Create the Zip archive
$zipMerge = new ZipArchive();

if ( !file_exists( ABSPATH . "hejbitSave_final.zip" ) ){
	
	$zipMerge->open(ABSPATH . "hejbitSave_final.zip", ZipArchive::CREATE);
	
}else{
	
	$zipMerge->open(ABSPATH . "hejbitSave_final.zip");

};

// Add additional files to the Zip archive
$sqlFiles = glob(ABSPATH . "*.sql");

// SQL addition loop, used for OthersApps
foreach ($sqlFiles as $sqlFile) {
	$sqlFilename = basename($sqlFile);
	$zipMerge->addFile($sqlFile, $sqlFilename);
};

// Add config file for WordPress only if database-only mode is not active
if( get_option("db_only_dlwcloud") !== "true"){
	
	$zipMerge->addFile(ABSPATH . "wp-config.php", "wordpress/wp-config.php");
	$zipMerge->addFile(ABSPATH . ".htaccess", "wordpress/.htaccess");

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
$wherefinish = array( "finish" => 0 );
$wpdb->update( $wpdb->prefix.'hejbit_saveInProgress' , $datafinish, $wherefinish );

// Schedule the next step of the backup process
wp_schedule_single_event(time(),'hejbit_SaveInProgress');

?>

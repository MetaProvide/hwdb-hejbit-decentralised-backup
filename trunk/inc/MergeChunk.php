<?php

/**
	The query gathers all the pieces of the tar (chunks) into a tar file in NextCloud for HejBit Decentralised Backup.
 **/

// Custom tables used exclusively by this plugin and for infrequent operations (backup)
// phpcs:disable WordPress.DB

// Fixes a security issue
if (!defined('ABSPATH')) {
	exit();
}

// Check the destination folder
// Retrieves the folders from the path into an array
$tab_dir = explode('/', get_option('hejbit_folder_dlwcloud'));
$directoryPath = '';

// For each folder in the path
foreach ($tab_dir as $dir) {

	if (!empty($dir)) {

		// Adds the folder to the path. This variable allows checking each folder one by one with its path
		$directoryPath .= '/' . $dir;

		// Prepares the request arguments
		$args = array(
			// Order to retrieve
			'method' => 'GET',
			'timeout' => 30,
			'redirection' => 5,
			'httpversion' => '1.0',
			'blocking' => true,
			'headers' => array(
				// NextCloud username and password
				'Authorization' => 'Basic ' . base64_encode(get_option("hejbit_login_dlwcloud") . ":" . get_option("hejbit_pass_dlwcloud")),
			),
			'body' => array(),
			'cookies' => array()
		);

		// Sending the request
		$resGetUserDestination = wp_remote_request(get_option('hejbit_url_dlwcloud') . '/remote.php/dav/files/' . get_option('hejbit_login_dlwcloud') . $directoryPath, $args);

		// If the user's path does not exist
		if ($resGetUserDestination["response"]["code"] == 404) {

			// Prepare the request arguments
			$args = array(
				// Order to create a folder
				'method' => 'MKCOL',
				'timeout' => 30,
				'redirection' => 5,
				'httpversion' => '1.0',
				'blocking' => true,
				'headers' => array(
					// NextCloud username and password
					'Authorization' => 'Basic ' . base64_encode(get_option("hejbit_login_dlwcloud") . ":" .
						get_option("hejbit_pass_dlwcloud")),
				),
				'body' => array(),
				'cookies' => array()
			);

			// Sending the request
			$resCreateDestination = wp_remote_request(get_option('hejbit_url_dlwcloud') . '/remote.php/dav/files/' .
				get_option('hejbit_login_dlwcloud') . $directoryPath, $args);
		};
	};
};


// Prepare the headers
$finalName = "hejbit_save_" . hejbit_save_to_nextcloud::getDomain() . "_" . gmdate('YmdHis');
$destination = get_option('hejbit_url_dlwcloud') . '/remote.php/dav/files/' . get_option('hejbit_login_dlwcloud') . get_option('hejbit_folder_dlwcloud') . $finalName . ".zip";

$headers = array(
	'content-type'  => 'application/binary',
	// Login and password entered in the fields
	'Authorization' => 'Basic ' . base64_encode(get_option("hejbit_login_dlwcloud") . ":" . get_option("hejbit_pass_dlwcloud")),
	// Will be sent to the destination
	'Destination'   => $destination,
);

// Prepare the arguments
$args = array(
	// Query that changes location
	'method' => 'MOVE',
	'timeout' => 30,
	'redirection' => 5,
	'httpversion' => '1.0',
	'blocking' => true,
	'headers' => $headers,
	'body' => array(),
	'cookies' => array()
);

// Sends the request (Gather the chunks in 'Destination')
wp_remote_request(get_option('hejbit_url_dlwcloud') . '/remote.php/dav/uploads/' . get_option('hejbit_login_dlwcloud') . '/' . $inProgress['uuid'] . "/.file", $args);

// Changes the backup status according to the database-only option
$datafinish = array(
	"name" => $finalName,
	"finish" => 1
);
$wherefinish = array("finish" => 0);
$wpdb->update($wpdb->prefix . 'hejbit_saveInProgress', $datafinish, $wherefinish);

// Log successful completion
hejbit_save_to_nextcloud::log('Backup completed successfully', 'SUCCESS', 'MERGE_CHUNK', array(
    'filename' => $finalName . '.zip',
    'destination' => get_option('hejbit_folder_dlwcloud')
));

$info = "Your site backup is complete and decentralized on your Hejbit folder!";
$this->sendInfo("SUCCESS", $info);

// Cleaning up the backups
include plugin_dir_path(__FILE__) . 'CleanSave.php';

// phpcs:enable WordPress.DB
?>
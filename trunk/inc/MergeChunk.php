<?php

/**
	
	The query gathers all the pieces of the tar (chunks) into a tar file in NextCloud

**/ 


// Fix a security issue mentioned in the email
if(!defined( 'ABSPATH' )){exit();}

// Check the destination folder
// Retrieves the folders from the path into an array
$tab_dir = explode('/', get_option('folder_dlwcloud'));
$directoryPath = '';

// For each folder in the path
foreach ($tab_dir as $dir){
	
	if( !empty( $dir ) ){

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
			'Authorization' => 'Basic ' . base64_encode(get_option("login_dlwcloud") . ":" . get_option("pass_dlwcloud")),
		   ),
		  'body' => array(),
		  'cookies' => array()
		);

		// Sending the request
		$resGetUserDestination = wp_remote_request( get_option('url_dlwcloud').'/remote.php/dav/files/'.get_option('login_dlwcloud').$directoryPath, $args );

		// If the user's path does not exist
		if($resGetUserDestination["response"]["code"] == 404){

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
				'Authorization' => 'Basic ' . base64_encode(get_option("login_dlwcloud") . ":" . 
				get_option("pass_dlwcloud")),
			   ),
			  'body' => array(),
			  'cookies' => array()
			);

			// Sending the request
			$resCreateDestination = wp_remote_request(get_option('url_dlwcloud').'/remote.php/dav/files/'.
			get_option('login_dlwcloud').$directoryPath, $args );
				
		};
		
	};
	
};


// Prepare the headers
$finalName = "sts_save_" . sts_save_to_nextcloud::getDomain() . "_" . date('YmdHis') ;
$destination = get_option('url_dlwcloud').'/remote.php/dav/files/'.get_option('login_dlwcloud').get_option('folder_dlwcloud') . $finalName . ".zip";

$headers = array(
 	'content-type'  => 'application/binary',
  	// Login and password entered in the fields
  	'Authorization' => 'Basic ' . base64_encode(get_option("login_dlwcloud") . ":" . get_option("pass_dlwcloud")),
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
wp_remote_request(get_option('url_dlwcloud').'/remote.php/dav/uploads/' . get_option('login_dlwcloud'). '/' . $inProgress['uuid'] . "/.file", $args );	

// Changes the backup status according to the database-only option
$datafinish = array(
				"name" => $finalName,
				"finish" => 1
			  );
$wherefinish = array("finish" => 0);
$wpdb->update($wpdb->prefix.'sts_saveInProgress', $datafinish, $wherefinish);					  

$info= "Your site backup is complete and decentralized on your Hejbit folder!"; 
$this->sendInfo("SUCCESS",$info);

// Cleaning up the backups
include ('CleanSave.php');

?>

<?php 
	
/**
	This file allows sending file chunks to NextCloud for HejBit Decentralised Backup.
**/

// Fix a security issue mentioned in the email
if( !defined( 'ABSPATH' ) ){ exit(); }

// Opening the zip
$handle = fopen(ABSPATH . "hejbitSave_final.zip", 'rb');

// Read the next chunk of the file
fseek( $handle , intval( $inProgress['fileNumber'] ) );

$memoryFree = hejbit_save_to_nextcloud::hejbit_get_memory();

$thisChunk = fread($handle, ( $memoryFree ) );

// While the file is not completely read
if ( !empty( $thisChunk ) ){

	// Prepare the headers
	$headers = array(
	  'content-type'  => 'application/binary',
	  // NextCloud username and password
	  'Authorization' => 'Basic ' . base64_encode(get_option("login_dlwcloud") . ":" . get_option("pass_dlwcloud")),
	);
				
	// Prepare the arguments
	$args = array(
	  // Prepare a request to upload a file chunk
	  'method' => 'PUT',
	  'timeout' => 30,
	  'redirection' => 5,
	  'httpversion' => '1.0',
	  'blocking' => true,
	  'headers' => $headers,
	  'body' => $thisChunk,
	  'cookies' => array(),
	);

	// Sends the request (creates the chunk file in the UUID folder)
	$firstBit = str_pad( $inProgress['fileNumber'], 15, '0', STR_PAD_LEFT );
	$lastBit =  str_pad( ( $inProgress['fileNumber'] + $memoryFree ), 15, '0', STR_PAD_LEFT);
	
	$resSendChunk = wp_remote_request(get_option('url_dlwcloud').'/remote.php/dav/uploads/' . get_option('login_dlwcloud'). '/' . $inProgress['uuid'] ."/".$firstBit."-".$lastBit, $args);
	
	fclose($handle);

	// Update the database with the new file number
	$data = array( "fileNumber"  => ( $inProgress['fileNumber'] + $memoryFree ) );
	$where = array("finish" => 0 );
	$wpdb->update($wpdb->prefix.'hejbit_saveInProgress', $data, $where);	
					
	// Restart the cron and exit
	wp_schedule_single_event(time(),'hejbit_SaveInProgress');
		
	exit();
		
};

fclose($handle);

// Change the backup status
$datafinish = array(
				"status" => 4,
				"fileNumber"  => 0
			  );
$wherefinish = array( "finish" => 0 );
$wpdb->update( $wpdb->prefix.'hejbit_saveInProgress' , $datafinish, $wherefinish );

// Start the next step
wp_schedule_single_event(time(),'hejbit_SaveInProgress');

?>

<?php 
	
/**
	Cleaning up obsolete backups for HejBit WordPress Decentralised Backup.
**/

// Fixes a security issue mentioned in the email
if( !defined( 'ABSPATH' ) ){ exit(); }

// Selecting the names of the backups to delete
$sql = "SELECT name,id_zip,uuid FROM " . $wpdb->prefix . "hejbit_saveInProgress WHERE finish = '1' ORDER BY id_zip DESC LIMIT ".get_option("nb_save_dlwcloud").",10";
$result = $wpdb->get_results($sql);

foreach ($result as $save){

		// Prepares the headers
		$headers = array(
		  // NextCloud username and password
		  'Authorization' => 'Basic ' . base64_encode(get_option("login_dlwcloud") . ":" . get_option("pass_dlwcloud")),
		);
		
		// Prepare the arguments
		$args = array(
		  // Deletion request
		  'method' => 'DELETE',
		  'timeout' => 30,
		  'redirection' => 5,
		  'httpversion' => '1.0',
		  'blocking' => true,
		  'headers' => $headers,
		  'body' => array(),
		  'cookies' => array()
		);
		
		// Deletion of the Nextcloud zip file if it exists
		wp_remote_request(get_option('url_dlwcloud').'/remote.php/dav/files/'.get_option('login_dlwcloud').get_option('folder_dlwcloud') . $save->name . ".zip", $args );
	
		// Deletion of the chunk folder on Nextcloud if it exists
		wp_remote_request(get_option('url_dlwcloud').'/remote.php/dav/uploads/' . get_option('login_dlwcloud').'/' . $save->uuid, $args );		
			
		// Deletion of the database
		$where = array("id_zip" => $save->id_zip);
		$wpdb->delete($wpdb->prefix.'hejbit_saveInProgress', $where);
	
};

// Cleaning residual files (if they exist)
$filesInFtp = glob(ABSPATH . "hejbitSave_*");
foreach($filesInFtp as $file){ 	unlink($file);	};

?>

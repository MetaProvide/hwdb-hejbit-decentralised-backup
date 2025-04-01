<?php 
	
/**
	Cleaning up obsolete backups for HejBit Decentralised Backup.
**/

// Custom tables used exclusively by this plugin and for infrequent operations (backup)
// phpcs:disable WordPress.DB

// Fixes a security issue
if( !defined( 'ABSPATH' ) ){ exit(); }

// Get the dynamic limit value from options and cast it to an integer
$nb_save_limit = (int) get_option("nb_save_dlwcloud");

// Use direct method to get results with explicit SQL preparation
$result = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT name, id_zip, uuid 
         FROM {$wpdb->prefix}hejbit_saveInProgress 
         WHERE finish = %d 
         ORDER BY id_zip DESC 
         LIMIT %d, 10",
        1,           // finish condition
        $nb_save_limit // offset and limit
    )
);

foreach ($result as $save) {
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
    wp_remote_request(
        get_option('url_dlwcloud') . '/remote.php/dav/files/' . 
        get_option('login_dlwcloud') . 
        get_option('folder_dlwcloud') . 
        $save->name . ".zip", 
        $args 
    );

    // Deletion of the chunk folder on Nextcloud if it exists
    wp_remote_request(
        get_option('url_dlwcloud') . '/remote.php/dav/uploads/' . 
        get_option('login_dlwcloud') . '/' . 
        $save->uuid, 
        $args 
    );
    
    // Deletion of the database entry
    $wpdb->delete(
        $wpdb->prefix . 'hejbit_saveInProgress', 
        array("id_zip" => $save->id_zip)
    );
}

// Cleaning residual files (if they exist)
$filesInFtp = glob(ABSPATH . "hejbitSave_*");
foreach ($filesInFtp as $file) { 
    wp_delete_file($file);
}

// phpcs:enable WordPress.DB
?>
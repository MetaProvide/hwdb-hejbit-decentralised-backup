<?php

/**
    This file allows sending file chunks to NextCloud for HejBit Decentralised Backup.
**/

// Custom tables used exclusively by this plugin and for infrequent operations (backup)
// phpcs:disable WordPress.DB

// Fixes a security issue
if( !defined( 'ABSPATH' ) ){ exit(); }

// Initialize WP_Filesystem
if ( ! function_exists( 'WP_Filesystem' ) ) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
}
WP_Filesystem();

// Use the global $wp_filesystem object
global $wp_filesystem;

$upload_dir = wp_upload_dir();
$hejbit_upload_dir = $upload_dir['basedir'] . '/hejbit-backups/';

// File path
$file_path = $hejbit_upload_dir . "hejbitSave_final.zip";

// Check if file exists
if ( ! $wp_filesystem->exists( $file_path ) ) {
    exit( 'File does not exist.' );
}

// Get file size
$file_size = $wp_filesystem->size( $file_path );

// Read the next chunk of the file
$memoryFree = hejbit_save_to_nextcloud::hejbit_get_memory();
$file_number = intval( $inProgress['fileNumber'] );

// Check if we've reached the end of the file
if ( $file_number >= $file_size ) {
    // File completely read - move to next status (MergeChunk)
    $datafinish = array(
        "status" => 4,
        "fileNumber" => 0
    );
    $wherefinish = array( "finish" => 0 );
    $wpdb->update( $wpdb->prefix.'hejbit_saveInProgress', $datafinish, $wherefinish );
    
    // Start the next step
    wp_schedule_single_event(time(),'hejbit_SaveInProgress');
    exit();
}

// Read chunk using WP_Filesystem
$thisChunk = $wp_filesystem->get_contents( 
    $file_path, 
    false, 
    array(
        'offset' => $file_number,
        'length' => $memoryFree
    )
);

// While the file is not completely read
if ( !empty( $thisChunk ) ){
    // Prepare the headers
    $headers = array(
        'content-type'  => 'application/binary',
        // NextCloud username and password
        'Authorization' => 'Basic ' . base64_encode(get_option("hejbit_login_dlwcloud") . ":" . get_option("hejbit_pass_dlwcloud")),
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
    
    $resSendChunk = wp_remote_request(
        get_option('hejbit_url_dlwcloud').'/remote.php/dav/uploads/' . get_option('hejbit_login_dlwcloud'). '/' . $inProgress['uuid'] ."/".$firstBit."-".$lastBit, 
        $args
    );

    // Update the database with the new file number
    $data = array( "fileNumber"  => ( $inProgress['fileNumber'] + $memoryFree ) );
    $where = array("finish" => 0 );
    $wpdb->update($wpdb->prefix.'hejbit_saveInProgress', $data, $where);    
                    
    // Restart the cron and exit
    wp_schedule_single_event(time(),'hejbit_SaveInProgress');
        
    exit();
}

// Start the next step if no chunk was read
wp_schedule_single_event(time(),'hejbit_SaveInProgress');

// phpcs:enable WordPress.DB
?>
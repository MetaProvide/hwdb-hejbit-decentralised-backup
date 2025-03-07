<?php

/**
	Creation of partial Zip files for HejBit WordPress Decentralised Backup.
**/
if( !defined( 'ABSPATH' ) ){ exit(); }

// Listing of files to back up with exclusion of cache folders
$content_file = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator(ABSPATH . "wp-content/", RecursiveDirectoryIterator::SKIP_DOTS),
        function ($file, $key, $iterator) {
            // Check if the file is a directory and if it contains 'cache'
            return !$file->isDir() || strpos($file->getRealPath(), 'wp-content/cache') === false;
        }
    ),
    RecursiveIteratorIterator::LEAVES_ONLY
);				
				
// File counter
$fileNumber = 1;
$size = 0;

// Zip creation
$zip = new ZipArchive();

if ( !file_exists( ABSPATH . "hejbitSave_final.zip" ) ){
	
	$zip->open(ABSPATH . "hejbitSave_final.zip", ZipArchive::CREATE);
	
}else{
	
	$zip->open(ABSPATH . "hejbitSave_final.zip");

};

$memoryFree = hejbit_save_to_nextcloud::hejbit_get_memory();

foreach($content_file as $name => $file) {	

	// Compare the counter with the current fileNumber
	if( $fileNumber > $inProgress['fileNumber'] )	{
		
		$filePathName = $file->getPathname();
		
		// Skip the folders (automatically added)
		if ( !$file->isDir() ) {

			// Get real and relative path for current file
			$filePath = $file->getRealPath();
			$relativePath = substr($filePath, strlen(ABSPATH . "wp-content/"));
			
			// Adds the file size to the variable
			$size += filesize($filePath);
			
			if ( $size < $memoryFree ) {
				
				try {
					// Potentially problematic code
					$zip->addFile($filePath, "wordpress/wp-content/" . $relativePath);

				} catch (Exception $e) {
					// Exception handling

					// Build the error file path in the same location as the original file
					$errorFilePath = "wordpress/wp-content/" . $relativePath . "_erreur.txt";

					// Add the empty error file to the zip with the corresponding path
					$zip->addFromString($errorFilePath, '');
				};	
				
			}else{
				
				$zip->close();

				// Updating new data
				$data = array( "fileNumber"  => $fileNumber );
				$where = array("finish" => 0 );

				// Execute the query
				$wpdb->update($wpdb->prefix.'hejbit_saveInProgress', $data, $where);
						
				// Launch the next ZIP
				wp_schedule_single_event(time(),'hejbit_SaveInProgress');
						
				// End of the script before cron restart to avoid timeout
				exit();
				
			};
		};
	};
	
	// Increment the counter
	$fileNumber++;	

};

$zip->close();

//Change the backup status
$datafinish = array(
				"status" => 2,
				"fileNumber"  => 0 
			  );
$wherefinish = array( "finish" => 0 );
$wpdb->update( $wpdb->prefix.'hejbit_saveInProgress' , $datafinish, $wherefinish );

// Launch the next step
wp_schedule_single_event(time(),'hejbit_SaveInProgress');
?>

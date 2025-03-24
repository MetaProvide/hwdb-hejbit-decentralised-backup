<?php

/**
	This file is used to create a file on the FTP containing the database script for HejBit Decentralised Backup.
**/

// Fixes a security issue mentioned in the email
if( !defined( 'ABSPATH' ) ){ exit(); }

// Configuration others APPS
$OA_SQL = array(
	array("NameApp"=>"Wordpress","DB_HOST"=>DB_HOST,"DB_USER"=>DB_USER,"DB_PASSWORD"=>DB_PASSWORD,"DB_NAME"=>DB_NAME,"Prefix"=>$wpdb->prefix),
);

// OTHER APPS
// Including an OtherApps.php file
$otherSqlFiles = glob(PLUGIN_PATH_HEJBIT.'inc/OthersApps_*.php');
foreach ($otherSqlFiles as $sqlFile) {
    include $sqlFile;
}

foreach ( $OA_SQL as $thisDB ) {
	
	// Fixes a security issue mentioned in the email
	if( !defined( 'ABSPATH' ) ){ exit(); }

	// Database connection
	$mysqli = new mysqli($thisDB['DB_HOST'], $thisDB['DB_USER'], $thisDB['DB_PASSWORD'], $thisDB['DB_NAME']);

	// Retrieving all the tables from the database
	$tables = array();
	$showTables = $mysqli->query("SHOW TABLES LIKE '" . $thisDB['Prefix'] . "%'");

	while ($row = $showTables->fetch_array()) {
		$tables[] = $row[0];
	}

	// Create and open the backup file on the server in binary write mode
	$dbfile = ABSPATH . "hejbitSave_DB_".$thisDB['NameApp'].".sql";
	$handle = fopen($dbfile, "wb");

	fwrite($handle, "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n/*!40101 SET NAMES utf8mb4 */;\n\n");

	// For each table
	foreach ($tables as $table) {
		
		// If the table exists, we delete it
		fwrite($handle, "DROP TABLE IF EXISTS $table;\n");
		
		// Retrieve the table creation script
		$createTable = $mysqli->query("SHOW CREATE TABLE $table");
		$row2 = $createTable->fetch_row();
		fwrite($handle, $row2[1] . ";\n\n");
		
		// Prepare the SELECT query to retrieve the table data
		$selectAllFromTable = $mysqli->prepare("SELECT * FROM $table");
		$selectAllFromTable->execute();
		$result = $selectAllFromTable->get_result();
		
		$count=1;
		
		// Loop through the records
		while ($row = $result->fetch_assoc()) {
			
			$values = array();
			
			// Escape values and format them for the SQL script
			foreach ($row as $columnName => $value) {
				
				if ( !empty($value)){
					// Determine the data type
					if (preg_match('/[^\x20-\x7E]/', $value)) {
						$values[] = "0x".bin2hex($value);
					}else{
						$values[] = "'" . $mysqli->real_escape_string($value) . "'";
					}
				}
			}
			

			if ($count==1){
				
				fwrite($handle, "INSERT INTO $table (" . implode(", ", array_keys($row)) . ") VALUES\n(" . implode(", ", $values) . ")");

			}else{
				
				fwrite($handle, ",\n(" . implode(", ", $values) . ")");

			}
			$count++;
		}

		fwrite($handle, ";\n\n");
	}

	// Close the file
	fclose($handle);
};

// Change the backup status based on the 'database only' checkbox
if( get_option("db_only_dlwcloud") == "true"){
	
	$datafinish = array(
		"status" => 2,
		"fileNumber"  => 0
	);
	
}else{
	
	$datafinish = array(
		"status" => 1,
		"fileNumber"  => 0
	);
}

$wherefinish = array("finish" => 0);
$wpdb->update($wpdb->prefix.'hejbit_saveInProgress', $datafinish, $wherefinish);

// Launch the next step
wp_schedule_single_event(time(), 'hejbit_SaveInProgress');
?>

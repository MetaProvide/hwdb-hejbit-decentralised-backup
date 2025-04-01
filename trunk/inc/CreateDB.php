<?php

/**
    This file is used to create a file on the FTP containing the database script for HejBit Decentralised Backup.
**/

// Fixes a security issue mentioned in the email
if( !defined( 'ABSPATH' ) ){ exit(); }

// Load the WordPress filesystem
global $wp_filesystem;

if (empty($wp_filesystem)) {
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    WP_Filesystem();
}

// Configuration others APPS
$OA_SQL = array(
    array(
        "NameApp"   => "Wordpress",
        "DB_HOST"   => DB_HOST,
        "DB_USER"   => DB_USER,
        "DB_PASSWORD" => DB_PASSWORD,
        "DB_NAME"   => DB_NAME,
        "Prefix"    => $wpdb->prefix,
    ),
);

// OTHER APPS
// Including an OtherApps.php file
$otherSqlFiles = glob(PLUGIN_PATH_HEJBIT . 'inc/OthersApps_*.php');
foreach ($otherSqlFiles as $sqlFile) {
    include $sqlFile;
}

foreach ($OA_SQL as $thisDB) {

    // Fixes a security issue mentioned in the email
    if( !defined( 'ABSPATH' ) ){ exit(); }

    // Retrieving all the tables from the database using prepared SQL
    $tables = $wpdb->get_col(
        $wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $thisDB['Prefix'] . '%'
        )
    );

    // Create the backup file
    $dbfile = ABSPATH . "hejbitSave_DB_" . $thisDB['NameApp'] . ".sql";

    // Open the file using WP_Filesystem
    $file_handle = $wp_filesystem->put_contents($dbfile, '', FS_CHMOD_FILE);

    // Write the initial database settings to the file
    $initial_sql = "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n";
    $initial_sql .= "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n";
    $initial_sql .= "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n";
    $initial_sql .= "/*!40101 SET NAMES utf8mb4 */;\n\n";

    $wp_filesystem->put_contents($dbfile, $initial_sql, FS_CHMOD_FILE | FILE_APPEND);

    // For each table
    foreach ($tables as $table) {

        // If the table exists, we delete it
        $drop_table_sql = "DROP TABLE IF EXISTS $table;\n";
        $wp_filesystem->put_contents($dbfile, $drop_table_sql, FS_CHMOD_FILE | FILE_APPEND);

        // Retrieve the table creation script
        $createTable = $wpdb->get_row("SHOW CREATE TABLE `" . esc_sql($table) . "`", ARRAY_N);
        $wp_filesystem->put_contents($dbfile, $createTable[1] . ";\n\n", FS_CHMOD_FILE | FILE_APPEND);

        // Retrieve the table data
        $results = $wpdb->get_results("SELECT * FROM `" . esc_sql($table) . "`", ARRAY_A);

        $count = 1;

        // Loop through the records
        foreach ($results as $row) {

            $values = array();

            // Escape values and format them for the SQL script
            foreach ($row as $columnName => $value) {

                if (!empty($value)) {
                    // Determine the data type
                    if (preg_match('/[^\x20-\x7E]/', $value)) {
                        $values[] = "0x" . bin2hex($value);
                    } else {
                        $values[] = "'" . esc_sql($value) . "'";
                    }
                }
            }

            if ($count == 1) {
                $insert_sql = "INSERT INTO $table (" . implode(", ", array_keys($row)) . ") VALUES\n(" . implode(", ", $values) . ")";
            } else {
                $insert_sql = ",\n(" . implode(", ", $values) . ")";
            }

            $wp_filesystem->put_contents($dbfile, $insert_sql, FS_CHMOD_FILE | FILE_APPEND);
            $count++;
        }

        $wp_filesystem->put_contents($dbfile, ";\n\n", FS_CHMOD_FILE | FILE_APPEND);
    }
}

// Change the backup status based on the 'database only' checkbox
if (get_option("db_only_dlwcloud") == "true") {

    $datafinish = array(
        "status" => 2,
        "fileNumber" => 0
    );

} else {

    $datafinish = array(
        "status" => 1,
        "fileNumber" => 0
    );
}

$wherefinish = array("finish" => 0);
$wpdb->update($wpdb->prefix . 'hejbit_saveInProgress', $datafinish, $wherefinish);

// Launch the next step
wp_schedule_single_event(time(), 'hejbit_SaveInProgress');

?>
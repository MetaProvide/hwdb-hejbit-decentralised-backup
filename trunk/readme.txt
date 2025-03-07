=== HejBit WordPress Decentralised Backup ===
Contributors: app.hebjit.com , metaprovide.org
Tags: hejbit, nextcloud, save, webdav, hwdb, swarm, backup, decentralised
Requires at least: 7.0
Requires PHP: 7.3
Tested up to: 6.5
Stable tag: 2.4.7
License: GPLv2
License URI: http://www.gnu.org/licenses/gpl-2.0.html


== Description ==

This plugin is a simple and effective tool to backup your WordPress site and its database directly to your Hejbit Folder on Nextcloud account. With it, you can define the parameters to create regular copies of your site, including the frequency and time of the operation.

Once you have configured the parameters, the plugin will automatically run in the background to create backups of your site and its database according to your schedule. Backups are stored directly on your Nextcloud account, allowing you to access them from anywhere, at any time.

This plugin is easy to install and configure, and it offers a complete backup solution to protect your site from data loss in the event of a technical problem or hacking. By choosing this plugin, you can have peace of mind knowing that your site is safe.

WARNING: WARNING: Automatic restoration of backups is not yet possible. It must be done manually by replacing the files on the hosting and restoring the database(DB).


== Installation ==

Install HejBit WordPress Decentralised Backup from the Wordpress store and activate it
Configure the settings in the menu 'Save To Nextcloud' -> 'Backup'

The "Save schedule" button schedules your next backup.
The "Make a backup now" button starts the backup

Advanced options:
- Set the number of backups to keep (up to 10)
- Enable automatic update blocking: the core, plugins and themes being tagged to be updated automatically, will only be updated after a scheduled backup in order to prevent it from being polluted by a faulty plugin. Manual updates are still possible.

Several steps are required, and each step depends on the maximum memory allocated by your hosting. As a result, the process may take a long time (a few hours for a 10GB site with 64MB of memory):

1- DB extraction
2- Zip of the wp-content folder
3- Addition of the .htaccess, wp-config and DB files to the Zip
4- Sending the zip following the Chunck method on the Nextcloud space
5- Reassembling the chunks and moving them to the final Nextcloud folder
6- Cleaning of residual files on the hosting and deletion of obsolete backups on Nextcloud

API, Two access points are available:
/wp-json/HejBit-WordPress-Decentralised-Backup/saves to retrieve the number of active backups and their name, as well as the next backup
/wp-json/HejBit-WordPress-Decentralised-Backup/param to retrieve the backup frequency, the day, the time and the number to keep

Disable the plugin: delete the schedules
Delete the plugin : remove tables and options

== Links ==
https://app.hejbit.com/

== Acknowledgements ==
This plugin would not have been possible without the solid foundation laid by Dave Delalleau. His work on the Save to Nextcloud plugin (https://wordpress.org/plugins/stn-save-to-Nextcloud/#developers) has been instrumental in shaping this project. We deeply appreciate his contributions and effort in making website backups more accessible and reliable. Thank you, Dave, for your dedication and hard work!

We would also like to acknowledge the Nextcloud community for their continued efforts in building a robust, open-source platform for file storage and collaboration. Their commitment to privacy, security, and decentralization has made it possible for projects like this to thrive. The flexibility and reliability of Nextcloud ensure that users have full control over their data, making it an ideal solution for secure backups.
<?php
/**
 * MyBB Debug Bar - Uninstall Script
 *
 * Handles database table removal, template cleanup,
 * and settings deletion for the MyBB Debug Bar plugin.
 */

if(!defined('IN_MYBB'))
{
    die('Direct initialization of this file is not allowed.');
}

if(!defined("PLUGINLIBRARY"))
{
    define("PLUGINLIBRARY", MYBB_ROOT . 'inc/plugins/pluginlibrary.php');
}

/**
 * Run uninstallation tasks for the MyBB Debug Bar plugin.
 *
 * Drops the debug log table, removes settings, templates, and stylesheet.
 *
 * @return void
 */
function mybb_debugbar_uninstall_run()
{
    global $db;

    // Drop debug log table
    if($db->table_exists("mybb_debugbar_logs"))
    {
        $db->drop_table("mybb_debugbar_logs");
    }

    // Remove settings
    if(file_exists(PLUGINLIBRARY))
    {
        require_once PLUGINLIBRARY;
        $PL = new PluginLibrary();
        $PL->settings_delete("mybb_debugbar");
    }

    // Remove stylesheet
    if(file_exists(PLUGINLIBRARY))
    {
        require_once PLUGINLIBRARY;
        $PL = new PluginLibrary();
        $PL->stylesheet_delete('mybb_debugbar');
    }

    // Remove templates
    require_once MYBB_ROOT . "inc/plugins/MyBB_DebugBar/install.php";
    mybb_debugbar_remove_templates();

    // Remove template group
    $db->delete_query("templategroups", "prefix='debugbar'");
}

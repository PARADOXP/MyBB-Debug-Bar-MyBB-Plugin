<?php
/**
 * MyBB Debug Bar - Install Script
 *
 * Handles database table creation, template installation,
 * and settings insertion for the MyBB Debug Bar plugin.
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
 * Run installation tasks for the MyBB Debug Bar plugin.
 *
 * Creates the database table, inserts templates, and adds settings.
 *
 * @return void
 */
function mybb_debugbar_install_run()
{
    global $db;

    // Load and execute schema from database.sql
    $schemaFile = MYBB_ROOT . 'inc/plugins/MyBB_DebugBar/database.sql';
    if(file_exists($schemaFile))
    {
        $sql = @file_get_contents($schemaFile);
        if($sql !== false)
        {
            $sql = str_replace('{TABLE_PREFIX}', $db->table_prefix, $sql);
            $statements = array_filter(array_map('trim', explode(';', $sql)));
            foreach($statements as $statement)
            {
                if($statement !== '')
                {
                    $db->write_query($statement);
                }
            }
        }
    }

    // Install settings via PluginLibrary
    if(file_exists(PLUGINLIBRARY))
    {
        require_once PLUGINLIBRARY;
        $PL = new PluginLibrary();
        $settingsFile = MYBB_ROOT . 'inc/plugins/MyBB_DebugBar/settings.json';
        if(file_exists($settingsFile))
        {
            $json = @file_get_contents($settingsFile);
            $data = @json_decode($json, true);
            if(is_array($data) && isset($data['group']) && isset($data['settings']) && is_array($data['settings']))
            {
                $group = $data['group'];
                $name = !empty($group['name']) ? $group['name'] : 'mybb_debugbar';
                $title = !empty($group['title']) ? $group['title'] : 'MyBB Debug Bar Settings';
                $description = !empty($group['description']) ? $group['description'] : '';
                $PL->settings($name, $title, $description, $data['settings']);
            }
        }
    }
}

/**
 * Run activation tasks for the MyBB Debug Bar plugin.
 *
 * Rebuilds settings, installs CSS stylesheet, and inserts templates.
 *
 * @return void
 */
function mybb_debugbar_activate_run()
{
    global $db;

    // Rebuild settings
    if(function_exists('rebuild_settings') === false)
    {
        require_once MYBB_ROOT . 'inc/functions.php';
    }
    rebuild_settings();

    // Install CSS stylesheet
    if(file_exists(PLUGINLIBRARY))
    {
        require_once PLUGINLIBRARY;
        $PL = new PluginLibrary();
        $css_path = MYBB_ROOT . 'inc/plugins/MyBB_DebugBar/Assets/mybb_debugbar.css';
        if(file_exists($css_path))
        {
            $css = @file_get_contents($css_path);
            if($css !== false)
            {
                $PL->stylesheet('mybb_debugbar', $css);
            }
        }
    }

    // Install templates
    mybb_debugbar_install_templates();
}

/**
 * Install all template files from the Templates/ directory into the database.
 *
 * Uses PluginLibrary when available, otherwise falls back to direct DB inserts.
 *
 * @return void
 */
function mybb_debugbar_install_templates()
{
    global $db;

    if(file_exists(PLUGINLIBRARY))
    {
        require_once PLUGINLIBRARY;
        $PL = new PluginLibrary();

        $basePath = MYBB_ROOT . 'inc/plugins/MyBB_DebugBar/Templates/';

        $template_files = array(
            'icon'           => 'debugbar_icon.html',
            'panel'          => 'debugbar_panel.html',
            'section_errors' => 'debugbar_section_errors.html',
            'section_error_item'  => 'debugbar_section_error_item.html',
            'section_console'     => 'debugbar_section_console.html',
            'section_console_item' => 'debugbar_section_console_item.html',
            'section_info'        => 'debugbar_section_info.html',
            'no_errors'           => 'debugbar_no_errors.html',
            'section_debug'       => 'debugbar_section_debug.html',
            'section_debug_item'  => 'debugbar_section_debug_item.html',
            'section_debug_empty' => 'debugbar_section_debug_empty.html',
        );

        $template_list = array();
        foreach($template_files as $key => $file)
        {
            $path = $basePath . $file;
            if(file_exists($path))
            {
                $template_list[$key] = file_get_contents($path);
            }
        }

        if($template_list)
        {
            $PL->templates('debugbar', 'MyBB Debug Bar', $template_list);
        }
    }
    else
    {
        mybb_debugbar_install_templates_fallback();
    }
}

/**
 * Fallback template installation without PluginLibrary.
 *
 * Creates template group and inserts templates directly into the database.
 *
 * @return void
 */
function mybb_debugbar_install_templates_fallback()
{
    global $db;

    // Create template group if it does not exist
    $query = $db->simple_select('templategroups', 'gid', "prefix='debugbar'");
    if(!$db->fetch_field($query, 'gid'))
    {
        $templategroup = array(
            "prefix" => "debugbar",
            "title"  => "MyBB Debug Bar",
        );
        $db->insert_query("templategroups", $templategroup);
    }

    $templates_dir = MYBB_ROOT . 'inc/plugins/MyBB_DebugBar/Templates/';
    if(!is_dir($templates_dir))
    {
        return;
    }

    $dir = opendir($templates_dir);
    while(($file = readdir($dir)) !== false)
    {
        if(preg_match('#\.html$#i', $file))
        {
            $template_name = str_replace('.html', '', $file);
            $template_content = file_get_contents($templates_dir . $file);

            $query = $db->simple_select("templates", "tid", "title='" . $db->escape_string($template_name) . "' AND sid='-2'");
            if($db->num_rows($query) > 0)
            {
                $db->update_query("templates", array('template' => $db->escape_string($template_content)), "title='" . $db->escape_string($template_name) . "' AND sid='-2'");
            }
            else
            {
                $db->insert_query("templates", array(
                    'title'    => $db->escape_string($template_name),
                    'template' => $db->escape_string($template_content),
                    'sid'      => -2,
                    'version'  => '1827',
                    'status'   => '',
                    'dateline' => TIME_NOW
                ));
            }
        }
    }
    closedir($dir);
}

/**
 * Remove all plugin templates from the database.
 *
 * @return void
 */
function mybb_debugbar_remove_templates()
{
    if(file_exists(PLUGINLIBRARY))
    {
        require_once PLUGINLIBRARY;
        $PL = new PluginLibrary();
        $PL->templates_delete('debugbar', true);
    }
    else
    {
        global $db;

        $templates_dir = MYBB_ROOT . 'inc/plugins/MyBB_DebugBar/Templates/';
        if(!is_dir($templates_dir))
        {
            return;
        }

        $dir = opendir($templates_dir);
        while(($file = readdir($dir)) !== false)
        {
            if(preg_match('#\.html$#i', $file))
            {
                $template_name = str_replace('.html', '', $file);
                $db->delete_query("templates", "title='" . $db->escape_string($template_name) . "' AND sid='-2'");
            }
        }
        closedir($dir);
    }
}

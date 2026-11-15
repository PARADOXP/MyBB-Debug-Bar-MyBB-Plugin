<?php
/**
 * MyBB Debug Bar
 *
 * Injects a floating debug icon on every page (frontend and ACP).
 * Displays PHP errors, console output, custom debug data, DB query
 * count, execution time, memory usage, user info, and MyBB version.
 * Provides debugbar_debug() function for developers to push custom
 * debug entries with pre-defined slot types.
 *
 * @package MyBB_DebugBar
 * @author  MyBB Debug Bar Team
 * @version 1.1.0
 * @license GPL-3.0
 */

// Disallow direct access to this file for security reasons
if(!defined("IN_MYBB"))
{
    die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

if(!defined("PLUGINLIBRARY"))
{
    define("PLUGINLIBRARY", MYBB_ROOT . "inc/plugins/pluginlibrary.php");
}

// Load admin module (defines admin hook handler functions)
require_once MYBB_ROOT . "inc/plugins/MyBB_DebugBar/Admin/module.php";

/**
 * Return plugin information for the MyBB plugin manager.
 *
 * @return array Plugin info array with name, description, author, etc.
 */
function mybb_debugbar_info()
{
    return array(
        "name"          => "MyBB Debug Bar",
        "description"   => "Adds a floating debug panel showing PHP errors, console output, custom debug data, DB queries, memory usage, and system info on every page.",
        "website"       => "https://mybbhub.shop",
        "author"        => "PARADOX",
        "authorsite"    => "https://community.mybb.com/user-134311.html",
        "version"       => "1.1.0",
        "codename"      => "mybb_debugbar",
        "guid"          => "",
        "compatibility" => "18*",
    );
}

/**
 * Plugin activation handler.
 *
 * Calls install_run for database and settings,
 * then activate_run for templates and assets.
 *
 * @return void
 */
function mybb_debugbar_activate()
{
    global $db;

    require_once MYBB_ROOT . "inc/plugins/MyBB_DebugBar/install.php";
    mybb_debugbar_install_run();
    mybb_debugbar_activate_run();

    if(function_exists('rebuild_settings') === false)
    {
        require_once MYBB_ROOT . 'inc/functions.php';
    }
    rebuild_settings();
}

/**
 * Plugin deactivation handler.
 *
 * Removes templates from the database.
 *
 * @return void
 */
function mybb_debugbar_deactivate()
{
    require_once MYBB_ROOT . "inc/plugins/MyBB_DebugBar/install.php";
    mybb_debugbar_remove_templates();
}

/**
 * Plugin uninstall handler.
 *
 * Drops database table, removes settings, templates, and stylesheet.
 *
 * @return void
 */
function mybb_debugbar_uninstall()
{
    global $db;

    require_once MYBB_ROOT . "inc/plugins/MyBB_DebugBar/uninstall.php";
    mybb_debugbar_uninstall_run();
}

/**
 * Check if the plugin is installed.
 *
 * @return bool True if the debug log table exists.
 */
function mybb_debugbar_is_installed()
{
    global $db;

    return $db->table_exists("mybb_debugbar_logs");
}

// ============================================================
// HOOKS
// ============================================================

$plugins->add_hook("global_start", "mybb_debugbar_global_start");
$plugins->add_hook("pre_output_page", "mybb_debugbar_output_page");
$plugins->add_hook("admin_load", "mybb_debugbar_admin_load");
$plugins->add_hook("admin_config_menu", "mybb_debugbar_admin_menu");
$plugins->add_hook("admin_config_action_handler", "mybb_debugbar_admin_action_handler");

// Start error capture early in admin area to catch pre-admin_load hook errors
if(defined('IN_ADMINCP'))
{
    global $mybb;
    if(isset($mybb->settings['mybb_debugbar_enabled'])
        && $mybb->settings['mybb_debugbar_enabled'] == 1)
    {
        require_once MYBB_ROOT . "inc/plugins/MyBB_DebugBar/Functions/capture_functions.php";
        debugbar_start_capture();
    }
}

// ============================================================
// HOOK FUNCTIONS
// ============================================================

/**
 * Initialize error capturing on global_start.
 *
 * Loads capture functions and starts the error handler.
 * Also evaluates custom debug code from settings.
 *
 * @return void
 */
function mybb_debugbar_global_start()
{
    global $mybb;

    if(!isset($mybb->settings['mybb_debugbar_enabled'])
        || $mybb->settings['mybb_debugbar_enabled'] != 1)
    {
        return;
    }

    require_once MYBB_ROOT . "inc/plugins/MyBB_DebugBar/Functions/capture_functions.php";
    debugbar_start_capture();

    // Execute custom debug code from settings
    mybb_debugbar_eval_custom_code();
}

/**
 * Evaluate custom debug PHP code from plugin settings.
 *
 * Reads the debug_custom_code setting and evaluates it as PHP.
 * This allows developers to add debugbar_debug() calls directly
 * from the ACP settings page without modifying plugin files.
 *
 * @return void
 */
function mybb_debugbar_eval_custom_code()
{
    global $mybb, $db, $templates, $lang, $theme, $session, $plugins;

    if(!isset($mybb->settings['mybb_debugbar_enabled'])
        || $mybb->settings['mybb_debugbar_enabled'] != 1)
    {
        return;
    }

    $custom_code = isset($mybb->settings['mybb_debugbar_debug_custom_code'])
        ? trim($mybb->settings['mybb_debugbar_debug_custom_code']) : '';

    if($custom_code === '')
    {
        return;
    }

    // Guard: only admins can see debug bar anyway, but be safe
    $allowed_groups = isset($mybb->settings['mybb_debugbar_allowed_groups'])
        ? $mybb->settings['mybb_debugbar_allowed_groups'] : '4';

    if($allowed_groups !== '')
    {
        $groups_array = array_map('intval', explode(',', $allowed_groups));
        $usergroup = isset($mybb->user['usergroup']) ? (int)$mybb->user['usergroup'] : 0;
        $displaygroup = isset($mybb->user['displaygroup']) ? (int)$mybb->user['displaygroup'] : 0;
        $effective_group = $displaygroup > 0 ? $displaygroup : $usergroup;

        if(!in_array($effective_group, $groups_array))
        {
            return;
        }
    }

    // Evaluate custom code.
    // Strip comment/blank lines but preserve multi-line expressions intact.
    $lines = explode("\n", $custom_code);
    $normalized = '';
    foreach($lines as $line)
    {
        $trimmed = trim($line);
        if($trimmed === '' || $trimmed === ';' || substr($trimmed, 0, 1) === '#'
            || substr($trimmed, 0, 2) === '//' || substr($trimmed, 0, 1) === ';')
        {
            continue;
        }
        $normalized .= $line . "\n";
    }
    if($normalized === '')
    {
        return;
    }
    $error_level = error_reporting(0);
    $last_error = error_get_last();
    $eval_result = @eval($normalized);
    $new_error = error_get_last();
    if($new_error !== $last_error && isset($new_error['type'])
        && in_array($new_error['type'], array(E_PARSE, E_COMPILE_ERROR, E_ERROR, E_USER_ERROR)))
    {
        debugbar_log('Custom Code Error: ' . $new_error['message'] . ' (line ' . $new_error['line'] . ')', 'error');
    }
    error_reporting($error_level);
}

/**
 * Inject the debug bar into the page output on output_page.
 *
 * Loads assets (CSS/JS), renders the debug bar HTML, and
 * inserts it before the closing </body> tag.
 *
 * @param string &$page The full page HTML output.
 * @return void
 */
function mybb_debugbar_output_page(&$page)
{
    global $mybb;

    if(!isset($mybb->settings['mybb_debugbar_enabled'])
        || $mybb->settings['mybb_debugbar_enabled'] != 1)
    {
        return;
    }

    $allowed_groups = isset($mybb->settings['mybb_debugbar_allowed_groups'])
        ? $mybb->settings['mybb_debugbar_allowed_groups'] : '4';

    if($allowed_groups !== '')
    {
        $groups_array = explode(',', $allowed_groups);
        $groups_array = array_map('intval', $groups_array);
        $usergroup = isset($mybb->user['usergroup']) ? (int)$mybb->user['usergroup'] : 0;
        $displaygroup = isset($mybb->user['displaygroup']) ? (int)$mybb->user['displaygroup'] : 0;
        $effective_group = $displaygroup > 0 ? $displaygroup : $usergroup;

        if(!in_array($effective_group, $groups_array))
        {
            return;
        }
    }

    // Load render functions
    require_once MYBB_ROOT . "inc/plugins/MyBB_DebugBar/Functions/render_functions.php";

    // Inject CSS and JS into <head> (headerinclude already resolved at this point)
    $css_url = $mybb->asset_url . "/inc/plugins/MyBB_DebugBar/Assets/mybb_debugbar.css";
    $js_url = $mybb->asset_url . "/inc/plugins/MyBB_DebugBar/Assets/mybb_debugbar.js";

    $drag_enabled = isset($mybb->settings['mybb_debugbar_drag_enabled'])
        && $mybb->settings['mybb_debugbar_drag_enabled'] == '1' ? '1' : '0';

    $js_features = array(
        'events'      => isset($mybb->settings['mybb_debugbar_js_events']) && $mybb->settings['mybb_debugbar_js_events'] == '1',
        'ajax'        => isset($mybb->settings['mybb_debugbar_js_ajax']) && $mybb->settings['mybb_debugbar_js_ajax'] == '1',
        'js_errors'   => isset($mybb->settings['mybb_debugbar_js_errors']) && $mybb->settings['mybb_debugbar_js_errors'] == '1',
        'timers'      => isset($mybb->settings['mybb_debugbar_js_timers']) && $mybb->settings['mybb_debugbar_js_timers'] == '1',
        'storage'     => isset($mybb->settings['mybb_debugbar_js_storage']) && $mybb->settings['mybb_debugbar_js_storage'] == '1',
        'performance' => isset($mybb->settings['mybb_debugbar_js_performance']) && $mybb->settings['mybb_debugbar_js_performance'] == '1',
    );

    $assets_html = "\n<link rel=\"stylesheet\" type=\"text/css\" href=\"{$css_url}\" />\n";
    $assets_html .= "<script type=\"text/javascript\">var debugbar_drag_enabled = {$drag_enabled}; var debugbar_js_features = " . json_encode($js_features) . ";</script>\n";
    $assets_html .= "<script type=\"text/javascript\" src=\"{$js_url}\"></script>\n";

    $page = str_replace('</head>', $assets_html . '</head>', $page);

    // Render and inject the debug bar
    $debugbar_html = debugbar_render();

    if(!empty($debugbar_html))
    {
        $page = str_replace('</body>', $debugbar_html . "\n</body>", $page);
    }
}
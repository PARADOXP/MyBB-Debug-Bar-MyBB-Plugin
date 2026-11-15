<?php
/**
 * MyBB Debug Bar - Render Functions
 *
 * Renders the complete debug bar HTML, including error lists,
 * console output, and system information panels.
 */

if(!defined('IN_MYBB'))
{
    die('Direct initialization of this file is not allowed.');
}

/**
 * Render the complete MyBB Debug Bar HTML.
 *
 * Assembles the debug bar icon, panel, and all section content
 * using MyBB templates. Passes in available data from globals.
 *
 * @return string The full debug bar HTML to inject into the page.
 */
function debugbar_render()
{
    global $mybb, $db, $templates, $lang;

    // Check if debug bar is enabled
    if(!isset($mybb->settings['mybb_debugbar_enabled'])
        || $mybb->settings['mybb_debugbar_enabled'] != 1)
    {
        return '';
    }

    // Check user permission
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
            return '';
        }
    }

    // Prepare error data
    $errors = isset($GLOBALS['debugbar_errors']) ? $GLOBALS['debugbar_errors'] : array();
    $console = isset($GLOBALS['debugbar_console']) ? $GLOBALS['debugbar_console'] : array();
    $error_count = count($errors);

    // Build errors section
    $errors_html = debugbar_render_errors($errors);

    // Check if console tab is enabled in settings
    $show_console_setting = !isset($mybb->settings['mybb_debugbar_show_console'])
        || $mybb->settings['mybb_debugbar_show_console'] == 1;

    // Build console section
    $console_html = '';

    if($show_console_setting)
    {
        $console_html = debugbar_render_console($console);

        if(empty($console_html))
        {
            $console_html = '<div class="debugbar-section"><h3 class="debugbar-section-title debugbar-section-title-console">Console Output</h3><div class="debugbar-section-body" id="debugbar-console-body"><div class="debugbar-no-errors" id="debugbar-console-empty">Waiting for JavaScript console output...</div></div></div>';
        }
    }

    // Build info section
    $info_html = debugbar_render_info();

    // Build debug section
    $debug_data = isset($GLOBALS['debugbar_debug']) ? $GLOBALS['debugbar_debug'] : array();
    $debug_count = count($debug_data);

    // Show debug tab if there are entries, regardless of settings
    // If no entries, only show if both show_debug and show_debug_always are enabled
    $show_debug_tab = $debug_count > 0
        || (
            (!isset($mybb->settings['mybb_debugbar_show_debug'])
                || $mybb->settings['mybb_debugbar_show_debug'] == 1)
            && isset($mybb->settings['mybb_debugbar_show_debug_always'])
            && $mybb->settings['mybb_debugbar_show_debug_always'] == 1
        );

    // Build debug section
    $debug_html = '';
    if($show_debug_tab)
    {
        $debug_html = debugbar_render_debug($debug_data);
    }

    // Determine tab visibility
    $show_errors_tab = $error_count > 0;
    $show_console_tab = $show_console_setting;

    // Render the icon
    $badge_class = $error_count > 0 ? 'debugbar-badge-error' : 'debugbar-badge-ok';
    $icon_template = $templates->get('debugbar_icon');
    eval("\$debugbar_icon = \"{$icon_template}\";");

    // Build tab buttons HTML: Errors, Info, Debug, Console
    $tab_buttons = '';

    $first_tab = 'info';
    if($show_errors_tab)
    {
        $first_tab = 'errors';
    }

    if($show_errors_tab)
    {
        $tab_buttons .= '<button class="debugbar-tab' . ($first_tab === 'errors' ? ' debugbar-tab-active' : '') . '" data-tab="errors">Errors (' . $error_count . ')</button>';
    }

    $tab_buttons .= '<button class="debugbar-tab' . ($first_tab === 'info' ? ' debugbar-tab-active' : '') . '" data-tab="info">Info</button>';

    if($show_debug_tab)
    {
        $debug_label = $debug_count > 0 ? 'Debug (' . $debug_count . ')' : 'Debug';
        $tab_buttons .= '<button class="debugbar-tab' . ($first_tab === 'debug' ? ' debugbar-tab-active' : '') . '" data-tab="debug">' . $debug_label . '</button>';
    }

    if($show_console_tab)
    {
        $console_count = count($console);
        $console_label = $console_count > 0 ? 'Console (' . $console_count . ')' : 'Console';
        $tab_buttons .= '<button class="debugbar-tab' . ($first_tab === 'console' ? ' debugbar-tab-active' : '') . '" data-tab="console">' . $console_label . '</button>';
    }

    // Build content panes
    $pane_errors_class = $first_tab === 'errors' ? 'debugbar-pane-active' : '';
    $pane_info_class = $first_tab === 'info' ? 'debugbar-pane-active' : '';
    $pane_debug_class = $first_tab === 'debug' ? 'debugbar-pane-active' : '';
    $pane_console_class = $first_tab === 'console' ? 'debugbar-pane-active' : '';

    // Render the panel
    $panel_template = $templates->get('debugbar_panel');
    eval("\$debugbar_panel = \"{$panel_template}\";");

    return $debugbar_icon . $debugbar_panel;
}

/**
 * Render the errors section of the debug bar.
 *
 * Iterates through captured PHP errors and renders each one.
 * Falls back to a "no errors" template if none exist.
 *
 * @param array $errors Array of captured error entries.
 * @return string       The rendered errors section HTML.
 */
function debugbar_render_errors($errors)
{
    global $templates;

    $error_count = count($errors);

    if(empty($errors))
    {
        $section_template = $templates->get('debugbar_no_errors');
        eval("\$html = \"{$section_template}\";");
        return $html;
    }

    $items = '';
    foreach($errors as $error)
    {
        $error_type_label = debugbar_get_error_type_label($error['type']);
        $error_message = htmlspecialchars_uni($error['message']);
        $error_file = htmlspecialchars_uni($error['file']);
        $error_line = (int)$error['line'];

        // Build trace HTML
        $trace_html = '';
        if(!empty($error['trace']))
        {
            $trace_parts = array();
            foreach($error['trace'] as $idx => $frame)
            {
                $frame_file = isset($frame['file']) ? $frame['file'] : '[internal]';
                $frame_line = isset($frame['line']) ? $frame['line'] : 0;
                $frame_function = isset($frame['function']) ? $frame['function'] : '';
                $trace_parts[] = '#' . $idx . ' ' . $frame_file . '(' . $frame_line . '): ' . $frame_function;
            }
            $escaped_trace = htmlspecialchars_uni(implode("\n", $trace_parts));
            $trace_html = '<pre class="debugbar-error-trace">' . $escaped_trace . '</pre>';
        }

        $item_template = $templates->get('debugbar_section_error_item');
        eval("\$items .= \"{$item_template}\";");
    }

    $section_template = $templates->get('debugbar_section_errors');
    eval("\$html = \"{$section_template}\";");
    return $html;
}

/**
 * Render the console section of the debug bar.
 *
 * Iterates through custom console log entries and renders each one.
 *
 * @param array $console Array of console log entries.
 * @return string        The rendered console section HTML.
 */
function debugbar_render_console($console)
{
    global $templates;

    if(empty($console))
    {
        return '';
    }

    $items = '';
    foreach($console as $entry)
    {
        $console_label = htmlspecialchars_uni($entry['label']);
        $console_message = htmlspecialchars_uni($entry['message']);

        $item_template = $templates->get('debugbar_section_console_item');
        eval("\$items .= \"{$item_template}\";");
    }

    $section_template = $templates->get('debugbar_section_console');
    eval("\$html = \"{$section_template}\";");
    return $html;
}

/**
 * Render the info section of the debug bar.
 *
 * Displays system information including page URL, MyBB version,
 * database query count, execution time, and memory usage.
 *
 * @return string The rendered info section HTML.
 */
function debugbar_render_info()
{
    global $mybb, $db, $templates;

    // Build info items
    $info_items = array();

    // Page URL
    $page_url = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'Unknown';
    $info_items[] = array(
        'label' => 'Page URL',
        'value' => htmlspecialchars_uni($page_url),
    );

    // MyBB Version
    $mybb_version = isset($mybb->version) ? $mybb->version : 'Unknown';
    $info_items[] = array(
        'label' => 'MyBB Version',
        'value' => htmlspecialchars_uni($mybb_version),
    );

    // Current User
    $username = 'Guest';
    if(isset($mybb->user['username']) && $mybb->user['username'] !== '')
    {
        $username = $mybb->user['username'];
    }
    $uid = isset($mybb->user['uid']) ? (int)$mybb->user['uid'] : 0;
    $info_items[] = array(
        'label' => 'Current User',
        'value' => htmlspecialchars_uni($username) . ' (UID: ' . $uid . ')',
    );

    // Usergroup
    $usergroup = isset($mybb->user['usergroup']) ? (int)$mybb->user['usergroup'] : 0;
    $info_items[] = array(
        'label' => 'Usergroup',
        'value' => $usergroup,
    );

    // DB Query Count
    $show_queries = !isset($mybb->settings['mybb_debugbar_show_queries'])
        || $mybb->settings['mybb_debugbar_show_queries'] == 1;
    if($show_queries && isset($db) && is_object($db))
    {
        $query_count = isset($db->query_count) ? (int)$db->query_count : 0;
        $info_items[] = array(
            'label' => 'DB Queries',
            'value' => $query_count,
        );
    }

    // Execution Time
    $start_time = isset($GLOBALS['debugbar_start_time']) ? $GLOBALS['debugbar_start_time'] : microtime(true);
    $exec_time = number_format(microtime(true) - $start_time, 4);
    $info_items[] = array(
        'label' => 'Execution Time',
        'value' => $exec_time . ' seconds',
    );

    // Memory Usage
    $show_memory = !isset($mybb->settings['mybb_debugbar_show_memory'])
        || $mybb->settings['mybb_debugbar_show_memory'] == 1;
    if($show_memory && function_exists('memory_get_peak_usage'))
    {
        $memory_bytes = memory_get_peak_usage(true);
        $memory = debugbar_format_bytes($memory_bytes);
        $info_items[] = array(
            'label' => 'Memory Usage',
            'value' => $memory,
        );
    }

    // PHP Version
    $info_items[] = array(
        'label' => 'PHP Version',
        'value' => phpversion(),
    );

    // Build items HTML
    $items = '';
    foreach($info_items as $item)
    {
        $item_label = $item['label'];
        $item_value = $item['value'];
        $section_template = $templates->get('debugbar_section_info');
        eval("\$items .= \"{$section_template}\";");
    }

    return $items;
}

/**
 * Render the debug section of the debug bar.
 *
 * Iterates through captured debug entries and renders each one
 * based on its slot type. Falls back to an empty/instruction template
 * if no entries exist.
 *
 * @param array $debug Array of debug entries.
 * @return string      The rendered debug section HTML.
 */
function debugbar_render_debug($debug)
{
    global $templates;

    if(empty($debug))
    {
        $section_template = $templates->get('debugbar_section_debug_empty');
        eval("\$html = \"{$section_template}\";");
        return $html;
    }

    $slot_labels = array(
        // Event slots
        'click'      => 'Click',
        'dblclick'   => 'DblClick',
        'hover'      => 'Hover',
        'mousemove'  => 'MouseMove',
        'mouse'      => 'Mouse',
        'touch'      => 'Touch',
        'scroll'     => 'Scroll',
        'resize'     => 'Resize',
        'focus'      => 'Focus',
        'keyboard'   => 'Keyboard',
        'form'       => 'Form',
        'drag'       => 'Drag',
        'animation'  => 'Animation',
        'transition' => 'Transition',
        'input'      => 'Input',
        // DOM/Style slots
        'style'      => 'Style',
        'html'       => 'HTML',
        'dom'        => 'DOM',
        'selector'   => 'Selector',
        'layout'     => 'Layout',
        'attr'       => 'Attr',
        'class'      => 'Class',
        'mutation'   => 'Mutation',
        // Network slots
        'ajax'       => 'AJAX',
        'fetch'      => 'Fetch',
        'network'    => 'Network',
        'api'        => 'API',
        'websocket'  => 'WebSocket',
        // JS Runtime slots
        'js_error'   => 'JSError',
        'js_log'     => 'JSLog',
        'timer'      => 'Timer',
        'promise'    => 'Promise',
        'storage'    => 'Storage',
        'console'    => 'Console',
        'worker'     => 'Worker',
        // PHP Advanced slots
        'file'       => 'File',
        'include'    => 'Include',
        'method'     => 'Method',
        'filter'     => 'Filter',
        'cache'      => 'Cache',
        'redirect'   => 'Redirect',
        'email'      => 'Email',
        'permission' => 'Permission',
        'upload'     => 'Upload',
        'image'      => 'Image',
        'locale'     => 'Locale',
        'date'       => 'Date',
        'math'       => 'Math',
        // Config/System slots
        'config'     => 'Config',
        'constant'   => 'Constant',
        'header'     => 'Header',
        'cookie'     => 'Cookie',
        'env'        => 'Env',
        'server'     => 'Server',
        // Performance slots
        'memory'     => 'Memory',
        'query'      => 'Query',
        'profile'    => 'Profile',
        'trace'      => 'Trace',
        'counter'    => 'Counter',
        // Basic slots
        'variable'   => 'Variable',
        'array'      => 'Array',
        'object'     => 'Object',
        'sql'        => 'SQL',
        'hook'       => 'Hook',
        'template'   => 'Template',
        'request'    => 'Request',
        'session'    => 'Session',
        'benchmark'  => 'Benchmark',
        'custom'     => 'Custom',
    );

    $items = '';
    $debug_count = count($debug);
    foreach($debug as $entry)
    {
        $debug_key = htmlspecialchars_uni($entry['key']);
        $debug_slot = htmlspecialchars_uni($entry['slot']);
        $debug_slot_label = isset($slot_labels[$entry['slot']]) ? $slot_labels[$entry['slot']] : 'Custom';
        $debug_file = htmlspecialchars_uni($entry['file']);
        $debug_line = (int)$entry['line'];
        $debug_data = htmlspecialchars_uni($entry['data']);

        $item_template = $templates->get('debugbar_section_debug_item');
        eval("\$items .= \"{$item_template}\";");
    }

    $section_template = $templates->get('debugbar_section_debug');
    eval("\$html = \"{$section_template}\";");
    return $html;
}

/**
 * Format bytes into a human-readable string.
 *
 * Converts a byte count into KB, MB, or GB as appropriate.
 *
 * @param int $bytes    The number of bytes.
 * @param int $precision The number of decimal places.
 * @return string       Formatted size string (e.g., "2.5 MB").
 */
function debugbar_format_bytes($bytes, $precision = 2)
{
    if($bytes === 0)
    {
        return '0 B';
    }

    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $exp = floor(log($bytes, 1024));
    $exp = min($exp, count($units) - 1);

    $value = $bytes / pow(1024, $exp);

    return number_format($value, $precision, '.', '') . ' ' . $units[$exp];
}

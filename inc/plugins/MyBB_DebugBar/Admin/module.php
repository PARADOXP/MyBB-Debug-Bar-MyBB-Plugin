<?php
/**
 * MyBB Debug Bar - Admin Module
 *
 * Provides ACP integration: configuration page for debug bar settings,
 * and hooks to inject the debug bar into Admin Control Panel pages.
 */

if(!defined('IN_MYBB'))
{
    die('Direct initialization of this file is not allowed.');
}

/**
 * Add the MyBB Debug Bar entry to the Admin CP Configuration menu.
 *
 * @param array &$sub_menu The admin menu items array.
 * @return void
 */
function mybb_debugbar_admin_menu(&$sub_menu)
{
    $sub_menu[] = array(
        'id'    => 'mybb_debugbar',
        'title' => 'MyBB Debug Bar',
        'link'  => 'index.php?module=config-mybb_debugbar',
    );
}

/**
 * Register the action handler for the debug bar admin module.
 *
 * @param array &$actions The actions array.
 * @return void
 */
function mybb_debugbar_admin_action_handler(&$actions)
{
    $actions['mybb_debugbar'] = array(
        'active' => 'mybb_debugbar',
        'file'   => '',
    );
}

/**
 * Handle admin_load for MyBB Debug Bar.
 *
 * If the current module is the config page, renders the settings form.
 * Otherwise, injects the debug bar into all ACP pages.
 *
 * @return void
 */
function mybb_debugbar_admin_load()
{
    global $mybb, $db, $page, $lang;

    if(isset($mybb->input['module']) && $mybb->input['module'] === 'config-mybb_debugbar')
    {
        mybb_debugbar_admin_config_page();
    }
    else
    {
        mybb_debugbar_admin_inject();
    }
}

/**
 * Render the MyBB Debug Bar configuration page in the ACP.
 *
 * Displays a form for all plugin settings.
 *
 * @return void
 */
function mybb_debugbar_admin_config_page()
{
    global $mybb, $db, $page, $lang;

    $page->add_breadcrumb_item('MyBB Debug Bar', 'index.php?module=config-mybb_debugbar');

    if($mybb->request_method == 'post')
    {
        if(!verify_post_check($mybb->get_input('my_post_key')))
        {
            flash_message($lang->invalid_post_verify_key2, 'error');
            admin_redirect('index.php?module=config-mybb_debugbar');
        }

        $allowed_groups_val = '';
        if(isset($mybb->input['allowed_groups']) && is_array($mybb->input['allowed_groups']))
        {
            $allowed_groups_val = implode(',', array_map('intval', $mybb->input['allowed_groups']));
        }

        $updated_settings = array(
            'mybb_debugbar_enabled'    => $mybb->get_input('enabled', MyBB::INPUT_INT),
            'mybb_debugbar_allowed_groups'   => $allowed_groups_val,
            'mybb_debugbar_show_queries' => $mybb->get_input('show_queries', MyBB::INPUT_INT),
            'mybb_debugbar_show_memory'  => $mybb->get_input('show_memory', MyBB::INPUT_INT),
            'mybb_debugbar_show_console'  => $mybb->get_input('show_console', MyBB::INPUT_INT),
            'mybb_debugbar_show_debug'  => $mybb->get_input('show_debug', MyBB::INPUT_INT),
            'mybb_debugbar_show_debug_always'  => $mybb->get_input('show_debug_always', MyBB::INPUT_INT),
            'mybb_debugbar_log_to_db'    => $mybb->get_input('log_to_db', MyBB::INPUT_INT),
            'mybb_debugbar_drag_enabled'    => $mybb->get_input('drag_enabled', MyBB::INPUT_INT),
            'mybb_debugbar_js_events'    => $mybb->get_input('js_events', MyBB::INPUT_INT),
            'mybb_debugbar_js_ajax'      => $mybb->get_input('js_ajax', MyBB::INPUT_INT),
            'mybb_debugbar_js_errors'    => $mybb->get_input('js_errors', MyBB::INPUT_INT),
            'mybb_debugbar_js_timers'    => $mybb->get_input('js_timers', MyBB::INPUT_INT),
            'mybb_debugbar_js_storage'   => $mybb->get_input('js_storage', MyBB::INPUT_INT),
            'mybb_debugbar_js_performance' => $mybb->get_input('js_performance', MyBB::INPUT_INT),
            'mybb_debugbar_debug_custom_code'    => isset($mybb->input['debug_custom_code']) ? $mybb->input['debug_custom_code'] : '',
        );

        foreach($updated_settings as $key => $value)
        {
            if($key === 'mybb_debugbar_debug_custom_code')
            {
                continue;
            }
            $db->update_query('settings', array('value' => $db->escape_string($value)), "name = '" . $db->escape_string($key) . "'");
        }

        // Handle custom code separately using direct SQL with mysql_real_escape_string
        $code_value = isset($mybb->input['debug_custom_code']) ? $mybb->input['debug_custom_code'] : '';
        // Escape quotes for SQL using str_replace as ultimate fallback
        $safe_value = str_replace("'", "\\'", str_replace("\\", "\\\\", $code_value));
        $code_check = $db->simple_select('settings', 'sid', "name = 'mybb_debugbar_debug_custom_code'");
        if($db->num_rows($code_check) > 0)
        {
            $db->write_query("UPDATE " . $db->table_prefix . "settings SET value='" . $safe_value . "' WHERE name='mybb_debugbar_debug_custom_code'");
        }
        else
        {
            $gid_query = $db->simple_select('settinggroups', 'gid', "name = 'mybb_debugbar'");
            $gid = $db->fetch_field($gid_query, 'gid');
            $db->write_query("INSERT INTO " . $db->table_prefix . "settings (name, title, description, optionscode, value, disporder, gid) VALUES ('mybb_debugbar_debug_custom_code','mybb_debugbar_debug_custom_code','','phptextarea','" . $safe_value . "',16," . (int)$gid . ")");
        }

        if(function_exists('rebuild_settings'))
        {
            rebuild_settings();
        }

        // Invalidate OPcache so the next request sees the new settings.php
        if(function_exists('opcache_invalidate'))
        {
            opcache_invalidate(MYBB_ROOT . 'inc/settings.php', true);
        }

        flash_message('MyBB Debug Bar settings updated successfully.', 'success');
        admin_redirect('index.php?module=config-mybb_debugbar');
    }

    $page->output_header('MyBB Debug Bar');

    $form = new Form('index.php?module=config-mybb_debugbar', 'post');
    echo $form->generate_hidden_field('my_post_key', $mybb->post_code);

    $form_container = new FormContainer('MyBB Debug Bar Settings');
    $form_container->output_row(
        'Enable Debug Bar',
        'Enable or disable the MyBB Debug Bar on all pages.',
        $form->generate_yes_no_radio('enabled', (int)$mybb->settings['mybb_debugbar_enabled'], true)
    );
    $allowed_sel = $mybb->settings['mybb_debugbar_allowed_groups'];
    $allowed_sel = $allowed_sel !== '' ? explode(',', $allowed_sel) : array();
    $form_container->output_row(
        'Allowed Usergroups',
        'Select which usergroups can see the debug bar on the frontend.',
        $form->generate_group_select('allowed_groups[]', $allowed_sel, array('id' => 'allowed_groups', 'multiple' => true, 'size' => 12))
    );
    $form_container->output_row(
        'Show DB Query Count',
        'Display the number of database queries executed on the current page.',
        $form->generate_yes_no_radio('show_queries', (int)$mybb->settings['mybb_debugbar_show_queries'], true)
    );
    $form_container->output_row(
        'Show Memory Usage',
        'Display PHP memory usage for the current page.',
        $form->generate_yes_no_radio('show_memory', (int)$mybb->settings['mybb_debugbar_show_memory'], true)
    );
    $form_container->output_row(
        'Show Console Tab',
        'Show a Console tab that captures JavaScript console.log/warn/error calls and PHP debugbar_log() output.',
        $form->generate_yes_no_radio('show_console', (int)$mybb->settings['mybb_debugbar_show_console'], true)
    );
    $form_container->output_row(
        'Show Debug Tab',
        'Enable the Debug tab that displays output from debugbar_debug() calls made by plugins and developers.',
        $form->generate_yes_no_radio('show_debug', (int)$mybb->settings['mybb_debugbar_show_debug'], true)
    );
    $form_container->output_row(
        'Show Debug Tab Always',
        'When enabled, the Debug tab will always be shown in the panel. When disabled, it only appears when debugbar_debug() has been called by a plugin or custom code.',
        $form->generate_yes_no_radio('show_debug_always', (int)$mybb->settings['mybb_debugbar_show_debug_always'], true)
    );
    $form_container->output_row(
        'Log Errors to DB',
        'When enabled, PHP errors captured by the debug bar will be logged to the database for later review.',
        $form->generate_yes_no_radio('log_to_db', (int)$mybb->settings['mybb_debugbar_log_to_db'], true)
    );
    $form_container->output_row(
        'Enable Draggable Icon',
        'Allow the debug icon to be dragged and repositioned on the page. When disabled, the icon stays in its default position.',
        $form->generate_yes_no_radio('drag_enabled', (int)$mybb->settings['mybb_debugbar_drag_enabled'], true)
    );

    // JS Feature settings
    $form_container->output_row(
        '<strong>JavaScript Debug Features</strong>',
        'Enable or disable client-side debugging features. These are off by default. Enable only what you need to investigate.',
        '<div style="color: #666; font-style: italic; padding: 4px 0;">Enable JS features below to auto-capture browser-side events, network requests, and errors.</div>'
    );

    $form_container->output_row(
        'JS Event Auto-Capture',
        'Auto-capture DOM events (click, dblclick, mousemove, hover/touch, scroll, resize, focus, keyboard, form submit/change/input, drag, animation, transition). Mousemove throttled to 500ms.',
        $form->generate_yes_no_radio('js_events', isset($mybb->settings['mybb_debugbar_js_events']) ? (int)$mybb->settings['mybb_debugbar_js_events'] : 0, true)
    );
    $form_container->output_row(
        'JS AJAX/Fetch Interception',
        'Intercept XMLHttpRequest and fetch() calls. Shows URL, method, status, duration, and response body in the Debug tab.',
        $form->generate_yes_no_radio('js_ajax', isset($mybb->settings['mybb_debugbar_js_ajax']) ? (int)$mybb->settings['mybb_debugbar_js_ajax'] : 0, true)
    );
    $form_container->output_row(
        'JS Error Capture',
        'Capture JavaScript runtime errors (window.onerror) and unhandled Promise rejections.',
        $form->generate_yes_no_radio('js_errors', isset($mybb->settings['mybb_debugbar_js_errors']) ? (int)$mybb->settings['mybb_debugbar_js_errors'] : 0, true)
    );
    $form_container->output_row(
        'JS Timer Capture',
        'Capture setTimeout() and setInterval() calls with delay and ID.',
        $form->generate_yes_no_radio('js_timers', isset($mybb->settings['mybb_debugbar_js_timers']) ? (int)$mybb->settings['mybb_debugbar_js_timers'] : 0, true)
    );
    $form_container->output_row(
        'JS Storage Capture',
        'Capture localStorage/sessionStorage changes across browser tabs (storage event).',
        $form->generate_yes_no_radio('js_storage', isset($mybb->settings['mybb_debugbar_js_storage']) ? (int)$mybb->settings['mybb_debugbar_js_storage'] : 0, true)
    );
    $form_container->output_row(
        'JS Performance Monitor',
        'Monitor memory usage (snapshot every 10s via performance.memory API) and network connection info (Network Information API).',
        $form->generate_yes_no_radio('js_performance', isset($mybb->settings['mybb_debugbar_js_performance']) ? (int)$mybb->settings['mybb_debugbar_js_performance'] : 0, true)
    );

    // Custom debug code textarea — code editor with syntax highlighting
    $raw_code_value = isset($mybb->settings['mybb_debugbar_debug_custom_code'])
        ? $mybb->settings['mybb_debugbar_debug_custom_code'] : '';
    $debug_code_value = htmlspecialchars(htmlspecialchars_decode($raw_code_value, ENT_QUOTES));

    $code_editor_html = <<<'CODEHTML'
<style>
.debug-code-wrap {
    border: 1px solid #3c3c3c; border-radius: 4px; overflow: hidden; background: #1e1e1e;
}
.debug-code-header {
    background: #252526; padding: 4px 10px; border-bottom: 1px solid #3c3c3c;
    font-size: 11px; color: #969696; display: flex; justify-content: space-between; align-items: center;
}
.debug-code-body {
    display: flex; min-height: 200px; max-height: 500px; overflow: hidden;
}
.debug-code-gutter {
    background: #252526; color: #858585; text-align: right; padding: 8px 6px 8px 0;
    user-select: none; width: 36px; flex-shrink: 0;
    font-family: Consolas, Monaco, "Courier New", monospace; font-size: 13px; line-height: 1.5;
    border-right: 1px solid #3c3c3c; overflow: hidden; white-space: pre;
}
.debug-code-editor {
    flex: 1; position: relative; overflow: hidden;
}
.debug-code-highlight {
    position: absolute; top: 0; left: 0; right: 0; bottom: 0; margin: 0; padding: 8px 12px;
    background: transparent; color: #d4d4d4;
    font-family: Consolas, Monaco, "Courier New", monospace; font-size: 13px; line-height: 1.5;
    white-space: pre-wrap; word-wrap: break-word; pointer-events: none; overflow: hidden;
    border: none; z-index: 1;
}
.debug-code-textarea {
    display: block; width: 100%; height: 100%; min-height: 200px;
    background: transparent; color: transparent; caret-color: #d4d4d4;
    border: none; outline: none; resize: vertical;
    padding: 8px 12px; font-family: Consolas, Monaco, "Courier New", monospace;
    font-size: 13px; line-height: 1.5; white-space: pre-wrap; word-wrap: break-word;
    overflow: auto; tab-size: 4; -moz-tab-size: 4;
    position: relative; z-index: 2;
}
.debug-code-textarea:focus { outline: none; box-shadow: inset 0 0 0 1px #007acc; }
</style>
CODEHTML;
    $code_editor_html .= '<div class="debug-code-wrap">';
    $code_editor_html .= '<div class="debug-code-header"><span>debug_custom_code.php</span><span style="color:#6a9955;">PHP</span></div>';
    $code_editor_html .= '<div class="debug-code-body">';
    $code_editor_html .= '<div class="debug-code-gutter" id="debug-code-gutter">1</div>';
    $code_editor_html .= '<div class="debug-code-editor">';
    $code_editor_html .= '<pre class="debug-code-highlight" id="debug-code-highlight"><code id="debug-code-code"></code></pre>';
    $code_editor_html .= '<textarea class="debug-code-textarea" id="debug_custom_code" name="debug_custom_code" spellcheck="false" autocomplete="off" autocorrect="off" autocapitalize="off" oninput="updateDebugCodeHighlight()" onscroll="syncDebugCodeScroll()">' . $debug_code_value . '</textarea>';
    $code_editor_html .= '</div></div></div>';
    $code_editor_html .= '<div style="margin-top: 6px; display: flex; gap: 8px; align-items: center;">';
    $code_editor_html .= '<button type="button" onclick="clearDebugCode()" style="padding: 5px 14px; background: #c0392b; color: #fff; border: none; border-radius: 3px; cursor: pointer; font-size: 12px; font-weight: bold;">Clear Code</button>';
    $code_editor_html .= '<span style="color: #888; font-size: 11px;">Then click <strong>Save Settings</strong> to apply.</span>';
    $code_editor_html .= '</div>';

    $form_container->output_row(
        'Custom Debug Code (PHP)',
        'Write PHP code to execute debugbar_debug() calls without editing plugin files. '
        . 'Each statement should end with a semicolon. '
        . 'Available slots: <strong>variable</strong>, <strong>array</strong>, <strong>object</strong>, '
        . '<strong>sql</strong>, <strong>hook</strong>, <strong>template</strong>, <strong>request</strong>, '
        . '<strong>session</strong>, <strong>benchmark</strong>, <strong>custom</strong>.<br><br>'
        . '<strong>Example:</strong><br>'
        . '<code>debugbar_debug(\'My Variable\', $mybb->settings, \'array\');</code><br>'
        . '<code>debugbar_debug(\'Current User\', $mybb->user, \'array\');</code><br>'
        . '<code>debugbar_debug(\'Memory\', memory_get_usage(), \'variable\');</code>',
        $code_editor_html
    );

    // Debug function usage info
    $form_container->output_row(
        '<strong>debugbar_debug() Function Reference</strong>',
        '',
        '<div style="background: #f5f5f5; border: 1px solid #ddd; padding: 12px; border-radius: 4px; font-family: monospace; font-size: 13px; line-height: 1.6;">'
        . '<strong>Function:</strong><br>'
        . '<code>debugbar_debug($key, $data, $slot = \'custom\')</code><br><br>'
        . '<strong>Parameters:</strong><br>'
        . '- <strong>$key</strong> (string) - Label/identifier for the debug entry<br>'
        . '- <strong>$data</strong> (mixed) - The data to debug (string, array, object, etc.)<br>'
        . '- <strong>$slot</strong> (string) - Slot type (optional, default: \'custom\')<br><br>'
        . '<strong>JS Side</strong> — Use <code>debugbarDebug(key, data, slot)</code> in browser console or scripts.<br>'
        . 'DOM helpers: <code>debugbarInspectElement(selector)</code>, <code>debugbarGetStyles()</code>, <code>debugbarGetLayout()</code>, <code>debugbarGetHTML()</code>, <code>debugbarQueryDOM()</code>.<br><br>'
        . '<strong style="color: #e74c3c;">EVENT SLOTS</strong> — Pass structured data {type, target, x, y, button, altKey, etc.}<br>'
        . '<table style="width:100%; border-collapse:collapse; font-size:12px;">'
        . '<tr style="background:#eee;"><td style="padding:3px 6px; border:1px solid #ddd;"><strong>Slot</strong></td><td style="padding:3px 6px; border:1px solid #ddd;"><strong>Description</strong></td><td style="padding:3px 6px; border:1px solid #ddd;"><strong>Example Data</strong></td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">click</td><td style="padding:3px 6px; border:1px solid #ddd;">Click event</td><td style="padding:3px 6px; border:1px solid #ddd;">{type, target, x, y, button}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">dblclick</td><td style="padding:3px 6px; border:1px solid #ddd;">Double-click event</td><td style="padding:3px 6px; border:1px solid #ddd;">{type, target, x, y}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">hover</td><td style="padding:3px 6px; border:1px solid #ddd;">Mouse enter/leave</td><td style="padding:3px 6px; border:1px solid #ddd;">{type, target, x, y}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">mousemove</td><td style="padding:3px 6px; border:1px solid #ddd;">Mouse movement</td><td style="padding:3px 6px; border:1px solid #ddd;">{x, y, movementX, movementY, target}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">mouse</td><td style="padding:3px 6px; border:1px solid #ddd;">Generic mouse event</td><td style="padding:3px 6px; border:1px solid #ddd;">{type, target, x, y, button, buttons}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">touch</td><td style="padding:3px 6px; border:1px solid #ddd;">Touch event</td><td style="padding:3px 6px; border:1px solid #ddd;">{type, target, touches, x, y, force}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">scroll</td><td style="padding:3px 6px; border:1px solid #ddd;">Scroll position</td><td style="padding:3px 6px; border:1px solid #ddd;">{scrollX, scrollY, target}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">resize</td><td style="padding:3px 6px; border:1px solid #ddd;">Window resize</td><td style="padding:3px 6px; border:1px solid #ddd;">{width, height, innerW, innerH}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">focus</td><td style="padding:3px 6px; border:1px solid #ddd;">Focus/blur event</td><td style="padding:3px 6px; border:1px solid #ddd;">{type, target, tagName, id}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">keyboard</td><td style="padding:3px 6px; border:1px solid #ddd;">Keyboard event</td><td style="padding:3px 6px; border:1px solid #ddd;">{type, key, code, altKey, ctrlKey, shiftKey}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">form</td><td style="padding:3px 6px; border:1px solid #ddd;">Form submit/change</td><td style="padding:3px 6px; border:1px solid #ddd;">{type, action, method, fields}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">input</td><td style="padding:3px 6px; border:1px solid #ddd;">Input/change event</td><td style="padding:3px 6px; border:1px solid #ddd;">{target, value, oldValue, inputType}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">drag</td><td style="padding:3px 6px; border:1px solid #ddd;">Drag and drop</td><td style="padding:3px 6px; border:1px solid #ddd;">{type, source, target, x, y, effect}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">animation</td><td style="padding:3px 6px; border:1px solid #ddd;">CSS animation</td><td style="padding:3px 6px; border:1px solid #ddd;">{type, name, elapsed, target}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">transition</td><td style="padding:3px 6px; border:1px solid #ddd;">CSS transition</td><td style="padding:3px 6px; border:1px solid #ddd;">{type, property, duration, delay}</td></tr>'
        . '</table><br>'
        . '<strong style="color: #9b59b6;">DOM/STYLE SLOTS</strong><br>'
        . '<table style="width:100%; border-collapse:collapse; font-size:12px;">'
        . '<tr style="background:#eee;"><td style="padding:3px 6px; border:1px solid #ddd;"><strong>Slot</strong></td><td style="padding:3px 6px; border:1px solid #ddd;"><strong>Description</strong></td><td style="padding:3px 6px; border:1px solid #ddd;"><strong>Example Data</strong></td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">style</td><td style="padding:3px 6px; border:1px solid #ddd;">Computed CSS styles</td><td style="padding:3px 6px; border:1px solid #ddd;">{element, styles: {prop: val}}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">html</td><td style="padding:3px 6px; border:1px solid #ddd;">HTML content</td><td style="padding:3px 6px; border:1px solid #ddd;">String or {element, html}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">dom</td><td style="padding:3px 6px; border:1px solid #ddd;">DOM node details</td><td style="padding:3px 6px; border:1px solid #ddd;">{tag, id, class, children, depth}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">selector</td><td style="padding:3px 6px; border:1px solid #ddd;">Query selector results</td><td style="padding:3px 6px; border:1px solid #ddd;">{selector, count, elements[]}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">layout</td><td style="padding:3px 6px; border:1px solid #ddd;">Element measurements</td><td style="padding:3px 6px; border:1px solid #ddd;">{element, width, height, top, left, bottom, right}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">attr</td><td style="padding:3px 6px; border:1px solid #ddd;">Element attributes</td><td style="padding:3px 6px; border:1px solid #ddd;">{element, attributes: {name: val}}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">class</td><td style="padding:3px 6px; border:1px solid #ddd;">CSS class operation</td><td style="padding:3px 6px; border:1px solid #ddd;">{element, action, class, classList[]}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">mutation</td><td style="padding:3px 6px; border:1px solid #ddd;">DOM mutation</td><td style="padding:3px 6px; border:1px solid #ddd;">{type, target, added, removed, attribute}</td></tr>'
        . '</table><br>'
        . '<strong style="color: #2ecc71;">NETWORK SLOTS</strong><br>'
        . '<table style="width:100%; border-collapse:collapse; font-size:12px;">'
        . '<tr style="background:#eee;"><td style="padding:3px 6px; border:1px solid #ddd;"><strong>Slot</strong></td><td style="padding:3px 6px; border:1px solid #ddd;"><strong>Description</strong></td><td style="padding:3px 6px; border:1px solid #ddd;"><strong>Example Data</strong></td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">ajax</td><td style="padding:3px 6px; border:1px solid #ddd;">XMLHttpRequest debug</td><td style="padding:3px 6px; border:1px solid #ddd;">{url, method, status, duration, response}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">fetch</td><td style="padding:3px 6px; border:1px solid #ddd;">fetch() API debug</td><td style="padding:3px 6px; border:1px solid #ddd;">{url, method, status, duration, response}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">network</td><td style="padding:3px 6px; border:1px solid #ddd;">Network info</td><td style="padding:3px 6px; border:1px solid #ddd;">{online, type, downlink, rtt}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">api</td><td style="padding:3px 6px; border:1px solid #ddd;">API endpoint call</td><td style="padding:3px 6px; border:1px solid #ddd;">{endpoint, params, result}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">websocket</td><td style="padding:3px 6px; border:1px solid #ddd;">WebSocket events</td><td style="padding:3px 6px; border:1px solid #ddd;">{url, type, data, code}</td></tr>'
        . '</table><br>'
        . '<strong style="color: #e67e22;">JS RUNTIME SLOTS</strong><br>'
        . '<table style="width:100%; border-collapse:collapse; font-size:12px;">'
        . '<tr style="background:#eee;"><td style="padding:3px 6px; border:1px solid #ddd;"><strong>Slot</strong></td><td style="padding:3px 6px; border:1px solid #ddd;"><strong>Description</strong></td><td style="padding:3px 6px; border:1px solid #ddd;"><strong>Example Data</strong></td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">js_error</td><td style="padding:3px 6px; border:1px solid #ddd;">JS error details</td><td style="padding:3px 6px; border:1px solid #ddd;">{message, stack, line, col, file}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">js_log</td><td style="padding:3px 6px; border:1px solid #ddd;">JS custom log</td><td style="padding:3px 6px; border:1px solid #ddd;">Any string or data</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">timer</td><td style="padding:3px 6px; border:1px solid #ddd;">setTimeout/setInterval</td><td style="padding:3px 6px; border:1px solid #ddd;">{type, id, delay, executions}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">promise</td><td style="padding:3px 6px; border:1px solid #ddd;">Promise state</td><td style="padding:3px 6px; border:1px solid #ddd;">{status, value, reason, duration}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">storage</td><td style="padding:3px 6px; border:1px solid #ddd;">Web storage</td><td style="padding:3px 6px; border:1px solid #ddd;">{source, action, key, value, oldValue}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">console</td><td style="padding:3px 6px; border:1px solid #ddd;">Console API</td><td style="padding:3px 6px; border:1px solid #ddd;">{method, args[]}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">worker</td><td style="padding:3px 6px; border:1px solid #ddd;">Web Worker</td><td style="padding:3px 6px; border:1px solid #ddd;">{type, data, error}</td></tr>'
        . '</table><br>'
        . '<strong style="color: #1abc9c;">PHP ADVANCED SLOTS</strong><br>'
        . '<table style="width:100%; border-collapse:collapse; font-size:12px;">'
        . '<tr style="background:#eee;"><td style="padding:3px 6px; border:1px solid #ddd;"><strong>Slot</strong></td><td style="padding:3px 6px; border:1px solid #ddd;"><strong>Description</strong></td><td style="padding:3px 6px; border:1px solid #ddd;"><strong>Example Data</strong></td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">file</td><td style="padding:3px 6px; border:1px solid #ddd;">File operations</td><td style="padding:3px 6px; border:1px solid #ddd;">{path, mode, result, size}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">include</td><td style="padding:3px 6px; border:1px solid #ddd;">Included files</td><td style="padding:3px 6px; border:1px solid #ddd;">Array of file paths</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">method</td><td style="padding:3px 6px; border:1px solid #ddd;">Method call debug</td><td style="padding:3px 6px; border:1px solid #ddd;">{class, method, args[], result, duration}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">filter</td><td style="padding:3px 6px; border:1px solid #ddd;">Input filtering</td><td style="padding:3px 6px; border:1px solid #ddd;">{input, filter, result, rules}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">cache</td><td style="padding:3px 6px; border:1px solid #ddd;">Cache operations</td><td style="padding:3px 6px; border:1px solid #ddd;">{key, action, hit, ttl, size}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">redirect</td><td style="padding:3px 6px; border:1px solid #ddd;">Redirect debug</td><td style="padding:3px 6px; border:1px solid #ddd;">{url, code, headers, from}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">email</td><td style="padding:3px 6px; border:1px solid #ddd;">Email debug</td><td style="padding:3px 6px; border:1px solid #ddd;">{to, subject, from, headers, sent}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">permission</td><td style="padding:3px 6px; border:1px solid #ddd;">Permission check</td><td style="padding:3px 6px; border:1px solid #ddd;">{resource, user, granted, action}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">upload</td><td style="padding:3px 6px; border:1px solid #ddd;">File upload</td><td style="padding:3px 6px; border:1px solid #ddd;">{name, size, type, tmp, error, valid}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">image</td><td style="padding:3px 6px; border:1px solid #ddd;">Image processing</td><td style="padding:3px 6px; border:1px solid #ddd;">{file, width, height, format, size}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">locale</td><td style="padding:3px 6px; border:1px solid #ddd;">Locale/i18n debug</td><td style="padding:3px 6px; border:1px solid #ddd;">{locale, string, translation, domain}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">date</td><td style="padding:3px 6px; border:1px solid #ddd;">Date/time ops</td><td style="padding:3px 6px; border:1px solid #ddd;">{format, input, output, timezone}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">math</td><td style="padding:3px 6px; border:1px solid #ddd;">Calculations</td><td style="padding:3px 6px; border:1px solid #ddd;">{operation, input, output, precision}</td></tr>'
        . '</table><br>'
        . '<strong style="color: #636e72;">CONFIG/SYSTEM SLOTS</strong><br>'
        . '<table style="width:100%; border-collapse:collapse; font-size:12px;">'
        . '<tr style="background:#eee;"><td style="padding:3px 6px; border:1px solid #ddd;"><strong>Slot</strong></td><td style="padding:3px 6px; border:1px solid #ddd;"><strong>Description</strong></td><td style="padding:3px 6px; border:1px solid #ddd;"><strong>Example Data</strong></td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">config</td><td style="padding:3px 6px; border:1px solid #ddd;">Config values</td><td style="padding:3px 6px; border:1px solid #ddd;">Associative array</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">constant</td><td style="padding:3px 6px; border:1px solid #ddd;">PHP constants</td><td style="padding:3px 6px; border:1px solid #ddd;">{NAME: value} or string constant name</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">header</td><td style="padding:3px 6px; border:1px solid #ddd;">HTTP headers</td><td style="padding:3px 6px; border:1px solid #ddd;">{name, value, action, code}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">cookie</td><td style="padding:3px 6px; border:1px solid #ddd;">Cookie data</td><td style="padding:3px 6px; border:1px solid #ddd;">{name, value, action, expires, path}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">env</td><td style="padding:3px 6px; border:1px solid #ddd;">Env variables</td><td style="padding:3px 6px; border:1px solid #ddd;">Associative array</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">server</td><td style="padding:3px 6px; border:1px solid #ddd;">$_SERVER data</td><td style="padding:3px 6px; border:1px solid #ddd;">Associative array</td></tr>'
        . '</table><br>'
        . '<strong style="color: #fd79a8;">PERFORMANCE SLOTS</strong><br>'
        . '<table style="width:100%; border-collapse:collapse; font-size:12px;">'
        . '<tr style="background:#eee;"><td style="padding:3px 6px; border:1px solid #ddd;"><strong>Slot</strong></td><td style="padding:3px 6px; border:1px solid #ddd;"><strong>Description</strong></td><td style="padding:3px 6px; border:1px solid #ddd;"><strong>Example Data</strong></td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">memory</td><td style="padding:3px 6px; border:1px solid #ddd;">Memory usage</td><td style="padding:3px 6px; border:1px solid #ddd;">{usage, peak, limit, delta} or numeric bytes</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">query</td><td style="padding:3px 6px; border:1px solid #ddd;">DB query with timing</td><td style="padding:3px 6px; border:1px solid #ddd;">{query, time, rows} or string SQL</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">profile</td><td style="padding:3px 6px; border:1px solid #ddd;">Code profiling</td><td style="padding:3px 6px; border:1px solid #ddd;">{label, start, memory, delta_mem}</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">trace</td><td style="padding:3px 6px; border:1px solid #ddd;">Backtrace</td><td style="padding:3px 6px; border:1px solid #ddd;">Array of backtrace frames</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">counter</td><td style="padding:3px 6px; border:1px solid #ddd;">Increment counter</td><td style="padding:3px 6px; border:1px solid #ddd;">{name, value, delta} or numeric</td></tr>'
        . '</table><br>'
        . '<strong style="color: #636e72;">BASIC SLOTS (Original)</strong><br>'
        . '<table style="width:100%; border-collapse:collapse; font-size:12px;">'
        . '<tr style="background:#eee;"><td style="padding:3px 6px; border:1px solid #ddd;"><strong>Slot</strong></td><td style="padding:3px 6px; border:1px solid #ddd;"><strong>Description</strong></td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">variable</td><td style="padding:3px 6px; border:1px solid #ddd;">Dump a scalar variable</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">array</td><td style="padding:3px 6px; border:1px solid #ddd;">Formatted key =&gt; value pairs</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">object</td><td style="padding:3px 6px; border:1px solid #ddd;">Object dump with class name</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">sql</td><td style="padding:3px 6px; border:1px solid #ddd;">SQL query string</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">hook</td><td style="padding:3px 6px; border:1px solid #ddd;">Hook call data</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">template</td><td style="padding:3px 6px; border:1px solid #ddd;">Template variable dumps</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">request</td><td style="padding:3px 6px; border:1px solid #ddd;">$_GET/$_POST/$_REQUEST</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">session</td><td style="padding:3px 6px; border:1px solid #ddd;">$_SESSION data</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">benchmark</td><td style="padding:3px 6px; border:1px solid #ddd;">Timing (pass microtime(true))</td></tr>'
        . '<tr><td style="padding:3px 6px; border:1px solid #ddd;">custom</td><td style="padding:3px 6px; border:1px solid #ddd;">print_r dump (default)</td></tr>'
        . '</table><br>'
        . '<strong>Examples:</strong><br>'
        . '<code>debugbar_debug(\'DB Query\', \'SELECT * FROM users\', \'sql\');</code><br>'
        . '<code>debugbar_debug(\'User Data\', $user_info, \'array\');</code><br>'
        . '<code>$start = microtime(true); // ... code ... debugbar_debug(\'Loop Time\', $start, \'benchmark\');</code><br>'
        . '<code>debugbar_debug(\'Click Event\', array(\'type\'=>\'click\', \'x\'=>100, \'y\'=>200), \'click\');</code><br>'
        . '<code>debugbar_debug(\'Memory\', memory_get_usage(), \'memory\');</code><br>'
        . '<code>debugbar_debug(\'Cache Get\', array(\'key\'=>\'users\', \'action\'=>\'get\', \'hit\'=>true), \'cache\');</code><br>'
        . '<strong>JS Side:</strong> <code>debugbarDebug(\'Click\', event, \'click\');</code> or use <code>debugbarInspectElement(\'#header\');</code>'
        . '</div>'
    );

    $form_container->end();

    $buttons = array();
    $buttons[] = $form->generate_submit_button('Save Settings');
    $form->output_submit_wrapper($buttons);
    $form->end();

    echo <<<'JSHTML'
<script>
function highlightPHP(code) {
    code = code.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    code = code.replace(/(\/\/.*)/g, '<span style="color:#6a9955;">$1</span>');
    code = code.replace(/(\/\*[\s\S]*?\*\/)/g, '<span style="color:#6a9955;">$1</span>');
    code = code.replace(/(#[^\n]*)/g, '<span style="color:#6a9955;">$1</span>');
    code = code.replace(/(["'])(?:(?!\1|\\).|\\.)*\1/g, '<span style="color:#ce9178;">$1</span>');
    code = code.replace(/\b(\d+\.?\d*)\b/g, '<span style="color:#b5cea8;">$1</span>');
    code = code.replace(/(\$[a-zA-Z_]\w*)/g, '<span style="color:#9cdcfe;">$1</span>');
    var kw = 'abstract|and|array|as|break|callable|case|catch|class|clone|const|continue|declare|default|die|do|echo|else|elseif|empty|enddeclare|endfor|endforeach|endif|endswitch|endwhile|eval|exit|extends|final|finally|fn|for|foreach|function|global|goto|if|implements|include|include_once|instanceof|insteadof|interface|isset|list|match|namespace|new|or|print|private|protected|public|readonly|require|require_once|return|static|switch|throw|trait|try|unset|use|var|while|xor|yield|true|false|null|int|float|bool|string|void|never|self|parent';
    code = code.replace(new RegExp('\\b(' + kw + ')\\b', 'gi'), '<span style="color:#569cd6;">$1</span>');
    return code;
}
function updateDebugCodeHighlight() {
    var ta = document.getElementById('debug_custom_code');
    var code = document.getElementById('debug-code-code');
    var gutter = document.getElementById('debug-code-gutter');
    if (!ta || !code || !gutter) return;
    var val = ta.value;
    code.innerHTML = highlightPHP(val) + '\n';
    var lines = val.split('\n');
    var nums = '';
    for (var i = 1; i <= lines.length; i++) { nums += i + '\n'; }
    gutter.textContent = nums;
}
function syncDebugCodeScroll() {
    var ta = document.getElementById('debug_custom_code');
    var hl = document.getElementById('debug-code-highlight');
    var gutter = document.getElementById('debug-code-gutter');
    if (!ta || !hl || !gutter) return;
    hl.scrollTop = ta.scrollTop;
    hl.scrollLeft = ta.scrollLeft;
    gutter.scrollTop = ta.scrollTop;
}
function clearDebugCode() {
    var ta = document.getElementById('debug_custom_code');
    if (ta) { ta.value = ''; updateDebugCodeHighlight(); }
}
setTimeout(updateDebugCodeHighlight, 50);
</script>
JSHTML;

    $page->output_footer();
    exit;
}

/**
 * Inject the debug bar HTML/CSS/JS into Admin CP pages.
 *
 * Uses output buffering to capture the page output and inject
 * the debug bar before the closing </body> tag.
 *
 * @return void
 */
function mybb_debugbar_admin_inject()
{
    global $mybb, $db;

    if(!isset($mybb->settings['mybb_debugbar_enabled'])
        || $mybb->settings['mybb_debugbar_enabled'] != 1)
    {
        return;
    }

    $allowed_groups = isset($mybb->settings['mybb_debugbar_allowed_groups'])
        ? $mybb->settings['mybb_debugbar_allowed_groups'] : '4';

    if($allowed_groups !== '')
    {
        $groups_array = array_map('intval', explode(',', $allowed_groups));
        $admin_usergroup = isset($mybb->user['usergroup']) ? (int)$mybb->user['usergroup'] : 0;
        $admin_displaygroup = isset($mybb->user['displaygroup']) ? (int)$mybb->user['displaygroup'] : 0;
        $effective_group = $admin_displaygroup > 0 ? $admin_displaygroup : $admin_usergroup;

        if(!in_array($effective_group, $groups_array))
        {
            return;
        }
    }

    // Start error capture if not already started
    if(!isset($GLOBALS['debugbar_errors']))
    {
        require_once MYBB_ROOT . 'inc/plugins/MyBB_DebugBar/Functions/capture_functions.php';
        debugbar_start_capture();
    }

    // Use output buffering to inject debug bar into admin pages
    ob_start(function($buffer) use ($mybb) {
        // Never touch file downloads or non-HTML responses (e.g. theme export).
        // Injecting here corrupts the response body and breaks the Content-Length
        // header that was already sent, truncating the downloaded file.
        // HTTP uses the LAST Content-Type header when multiple are present,
        // so track the most recent one.
        $last_content_type = '';
        foreach(headers_list() as $header)
        {
            if(stripos($header, 'Content-Type:') === 0
                || stripos($header, 'content-type:') === 0)
            {
                $last_content_type = trim(substr($header, 13));
            }
        }

        if($last_content_type !== ''
            && stripos($last_content_type, 'text/html') === false)
        {
            return $buffer;
        }

        $asset_url = isset($mybb->asset_url) ? $mybb->asset_url : '';
        $css_url = $asset_url . '/inc/plugins/MyBB_DebugBar/Assets/mybb_debugbar.css';
        $js_url = $asset_url . '/inc/plugins/MyBB_DebugBar/Assets/mybb_debugbar.js';

        // Build debug bar HTML manually (no template system in ACP)
        $errors = isset($GLOBALS['debugbar_errors']) ? $GLOBALS['debugbar_errors'] : array();
        $console = isset($GLOBALS['debugbar_console']) ? $GLOBALS['debugbar_console'] : array();
        $debug_data = isset($GLOBALS['debugbar_debug']) ? $GLOBALS['debugbar_debug'] : array();
        $error_count = count($errors);
        $debug_count = count($debug_data);

        $badge_class = $error_count > 0 ? 'debugbar-badge-error' : 'debugbar-badge-ok';

        $debugbar_html = '';

        // Icon
        $debugbar_html .= '<div id="debugbar-toggle" data-errors="' . $error_count . '">';
        $debugbar_html .= '<span id="debugbar-toggle-icon">&gt;_</span>';
        $debugbar_html .= '<span id="debugbar-badge" class="' . $badge_class . '">' . $error_count . '</span>';
        $debugbar_html .= '</div>';

        // Check console setting
        $show_console_tab = !isset($mybb->settings['mybb_debugbar_show_console'])
            || $mybb->settings['mybb_debugbar_show_console'] == 1;

        $show_errors_tab = ($error_count > 0);

        // Show debug tab if there are entries, regardless of settings
        // If no entries, only show if both show_debug and show_debug_always are enabled
        $show_debug_tab = $debug_count > 0
            || (
                (!isset($mybb->settings['mybb_debugbar_show_debug'])
                    || $mybb->settings['mybb_debugbar_show_debug'] == 1)
                && isset($mybb->settings['mybb_debugbar_show_debug_always'])
                && $mybb->settings['mybb_debugbar_show_debug_always'] == 1
            );

        $first_tab = 'info';
        if($show_errors_tab) $first_tab = 'errors';

        // Tab buttons: Errors, Info, Debug, Console
        $tab_buttons = '';
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

        // Errors pane
        $errors_html = '';
        if(!empty($errors))
        {
            $errors_html .= '<div class="debugbar-section"><h3 class="debugbar-section-title debugbar-section-title-errors">PHP Errors (' . $error_count . ')</h3><div class="debugbar-section-body">';
            foreach($errors as $error)
            {
                $type_label = debugbar_get_error_type_label($error['type']);
                $message = htmlspecialchars($error['message']);
                $file = htmlspecialchars($error['file']);
                $line = (int)$error['line'];

                $trace_html = '';
                if(!empty($error['trace']))
                {
                    $trace_parts = array();
                    foreach($error['trace'] as $idx => $frame)
                    {
                        $f = isset($frame['file']) ? $frame['file'] : '[internal]';
                        $l = isset($frame['line']) ? $frame['line'] : 0;
                        $fn = isset($frame['function']) ? $frame['function'] : '';
                        $trace_parts[] = '#' . $idx . ' ' . $f . '(' . $l . '): ' . $fn;
                    }
                    $trace_html = '<pre class="debugbar-error-trace">' . htmlspecialchars(implode("\n", $trace_parts)) . '</pre>';
                }

                $errors_html .= '<div class="debugbar-error-item">';
                $errors_html .= '<div class="debugbar-error-header"><span class="debugbar-error-type">' . $type_label . '</span><span class="debugbar-error-location">' . $file . ':' . $line . '</span></div>';
                $errors_html .= '<div class="debugbar-error-message">' . $message . '</div>';
                $errors_html .= $trace_html . '</div>';
            }
            $errors_html .= '</div></div>';
        }
        else
        {
            $errors_html .= '<div class="debugbar-section"><h3 class="debugbar-section-title debugbar-section-title-errors">PHP Errors</h3><div class="debugbar-section-body"><div class="debugbar-no-errors">No PHP errors detected on this page.</div></div></div>';
        }

        // Console pane
        $console_html = '';

        if($show_console_setting)
        {
            if(!empty($console))
            {
                $console_html .= '<div class="debugbar-section"><h3 class="debugbar-section-title debugbar-section-title-console">Console Output</h3><div class="debugbar-section-body">';
                foreach($console as $entry)
                {
                    $label = htmlspecialchars($entry['label']);
                    $msg = htmlspecialchars($entry['message']);
                    $console_html .= '<div class="debugbar-console-item debugbar-console-' . $label . '">';
                    $console_html .= '<span class="debugbar-console-label">[' . $label . ']</span>';
                    $console_html .= '<span class="debugbar-console-message">' . $msg . '</span></div>';
                }
                $console_html .= '</div></div>';
            }
            else
            {
                $console_html .= '<div class="debugbar-section"><h3 class="debugbar-section-title debugbar-section-title-console">Console Output</h3><div class="debugbar-section-body" id="debugbar-console-body"><div class="debugbar-no-errors" id="debugbar-console-empty">Waiting for JavaScript console output...</div></div></div>';
            }
        }

        // Info pane
        $info_items = mybb_debugbar_admin_info_items();
        $info_html = '';
        foreach($info_items as $item)
        {
            $info_html .= '<div class="debugbar-info-item">';
            $info_html .= '<span class="debugbar-info-label">' . htmlspecialchars($item['label']) . '</span>';
            $info_html .= '<span class="debugbar-info-value">' . $item['value'] . '</span></div>';
        }

        // Debug pane
        $debug_html = '';
        if($show_debug_tab)
        {
            $slot_labels = array(
                'click'      => 'Click', 'dblclick' => 'DblClick', 'hover' => 'Hover',
                'mousemove'  => 'MouseMove', 'mouse' => 'Mouse', 'touch' => 'Touch',
                'scroll'     => 'Scroll', 'resize' => 'Resize', 'focus' => 'Focus',
                'keyboard'   => 'Keyboard', 'form' => 'Form', 'drag' => 'Drag',
                'animation'  => 'Animation', 'transition' => 'Transition', 'input' => 'Input',
                'style'      => 'Style', 'html' => 'HTML', 'dom' => 'DOM',
                'selector'   => 'Selector', 'layout' => 'Layout', 'attr' => 'Attr',
                'class'      => 'Class', 'mutation' => 'Mutation',
                'ajax'       => 'AJAX', 'fetch' => 'Fetch', 'network' => 'Network',
                'api'        => 'API', 'websocket' => 'WebSocket',
                'js_error'   => 'JSError', 'js_log' => 'JSLog', 'timer' => 'Timer',
                'promise'    => 'Promise', 'storage' => 'Storage', 'console' => 'Console',
                'worker'     => 'Worker',
                'file'       => 'File', 'include' => 'Include', 'method' => 'Method',
                'filter'     => 'Filter', 'cache' => 'Cache', 'redirect' => 'Redirect',
                'email'      => 'Email', 'permission' => 'Permission', 'upload' => 'Upload',
                'image'      => 'Image', 'locale' => 'Locale', 'date' => 'Date',
                'math'       => 'Math',
                'config'     => 'Config', 'constant' => 'Constant', 'header' => 'Header',
                'cookie'     => 'Cookie', 'env' => 'Env', 'server' => 'Server',
                'memory'     => 'Memory', 'query' => 'Query', 'profile' => 'Profile',
                'trace'      => 'Trace', 'counter' => 'Counter',
                'variable'   => 'Variable', 'array' => 'Array', 'object' => 'Object',
                'sql'        => 'SQL', 'hook' => 'Hook', 'template' => 'Template',
                'request'    => 'Request', 'session' => 'Session', 'benchmark' => 'Benchmark',
                'custom'     => 'Custom',
            );

            if(!empty($debug_data))
            {
                $debug_html .= '<div class="debugbar-section"><h3 class="debugbar-section-title debugbar-section-title-debug">Debug Output (' . $debug_count . ')</h3><div class="debugbar-section-body">';
                foreach($debug_data as $entry)
                {
                    $slot_label = isset($slot_labels[$entry['slot']]) ? $slot_labels[$entry['slot']] : 'Custom';
                    $debug_html .= '<div class="debugbar-debug-item debugbar-debug-' . $entry['slot'] . '">';
                    $debug_html .= '<div class="debugbar-debug-header">';
                    $debug_html .= '<span class="debugbar-debug-key">' . htmlspecialchars($entry['key']) . '</span>';
                    $debug_html .= '<span class="debugbar-debug-slot">' . $slot_label . '</span>';
                    $debug_html .= '<span class="debugbar-debug-location">' . htmlspecialchars($entry['file']) . ':' . (int)$entry['line'] . '</span>';
                    $debug_html .= '</div>';
                    $debug_html .= '<div class="debugbar-debug-data">' . htmlspecialchars($entry['data']) . '</div>';
                    $debug_html .= '</div>';
                }
                $debug_html .= '</div></div>';
            }
            else
            {
                $debug_html .= '<div class="debugbar-section"><h3 class="debugbar-section-title debugbar-section-title-debug">Debug Output</h3><div class="debugbar-section-body"><div class="debugbar-no-debug">No debug entries. Call debugbar_debug() in your code to add entries here.</div></div></div>';
            }
        }

        // Panel
        $debugbar_html .= '<div id="debugbar-panel" class="debugbar-closed">';
        $debugbar_html .= '<div id="debugbar-panel-header"><span id="debugbar-panel-title">MyBB Debug Bar</span>';
        $debugbar_html .= '<span id="debugbar-copy-btn" title="Copy tab content">';
        $debugbar_html .= '<svg id="debugbar-copy-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>';
        $debugbar_html .= '<svg id="debugbar-copied-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none"><polyline points="20 6 9 17 4 12"></polyline></svg>';
        $debugbar_html .= '</span>';
        $debugbar_html .= '<span id="debugbar-panel-close" onclick="debugbarToggle()">&times;</span></div>';
        $debugbar_html .= '<div id="debugbar-panel-tabs">' . $tab_buttons . '</div>';
        $debugbar_html .= '<div id="debugbar-panel-content">';
        $debugbar_html .= '<div id="debugbar-pane-errors" class="debugbar-pane' . ($first_tab === 'errors' ? ' debugbar-pane-active' : '') . '">' . $errors_html . '</div>';
        $debugbar_html .= '<div id="debugbar-pane-info" class="debugbar-pane' . ($first_tab === 'info' ? ' debugbar-pane-active' : '') . '">' . $info_html . '</div>';
        $debugbar_html .= '<div id="debugbar-pane-debug" class="debugbar-pane' . ($first_tab === 'debug' ? ' debugbar-pane-active' : '') . '">' . $debug_html . '</div>';
        $debugbar_html .= '<div id="debugbar-pane-console" class="debugbar-pane' . ($first_tab === 'console' ? ' debugbar-pane-active' : '') . '">' . $console_html . '</div>';
        $debugbar_html .= '</div></div>';

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

        $assets_html = '<link rel="stylesheet" type="text/css" href="' . $css_url . '" />' . "\n";
        $assets_html .= '<script>var debugbar_drag_enabled = ' . $drag_enabled . '; var debugbar_js_features = ' . json_encode($js_features) . ';</script>' . "\n";
        $assets_html .= '<script type="text/javascript" src="' . $js_url . '"></script>' . "\n";

        $buffer = str_replace('</head>', $assets_html . '</head>', $buffer);
        $buffer = str_replace('</body>', $debugbar_html . "\n" . '</body>', $buffer);

        return $buffer;
    });
}

/**
 * Build info items for the ACP debug bar.
 *
 * Gathers system information for display in the Info tab.
 *
 * @return array Array of info items with 'label' and 'value' keys.
 */
function mybb_debugbar_admin_info_items()
{
    global $mybb, $db;

    $items = array();

    $page_url = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'Unknown';
    $items[] = array('label' => 'Page URL', 'value' => htmlspecialchars($page_url));

    $mybb_version = isset($mybb->version) ? $mybb->version : 'Unknown';
    $items[] = array('label' => 'MyBB Version', 'value' => htmlspecialchars($mybb_version));

    $username = 'Guest';
    if(isset($mybb->user['username']) && $mybb->user['username'] !== '')
    {
        $username = $mybb->user['username'];
    }
    $uid = isset($mybb->user['uid']) ? (int)$mybb->user['uid'] : 0;
    $items[] = array('label' => 'Current User', 'value' => htmlspecialchars($username) . ' (UID: ' . $uid . ')');

    $usergroup = isset($mybb->user['usergroup']) ? (int)$mybb->user['usergroup'] : 0;
    $items[] = array('label' => 'Usergroup', 'value' => $usergroup);

    $show_queries = !isset($mybb->settings['mybb_debugbar_show_queries'])
        || $mybb->settings['mybb_debugbar_show_queries'] == 1;
    if($show_queries && isset($db) && is_object($db))
    {
        $query_count = isset($db->query_count) ? (int)$db->query_count : 0;
        $items[] = array('label' => 'DB Queries', 'value' => $query_count);
    }

    $start_time = isset($GLOBALS['debugbar_start_time']) ? $GLOBALS['debugbar_start_time'] : microtime(true);
    $exec_time = number_format(microtime(true) - $start_time, 4);
    $items[] = array('label' => 'Execution Time', 'value' => $exec_time . ' seconds');

    $show_memory = !isset($mybb->settings['mybb_debugbar_show_memory'])
        || $mybb->settings['mybb_debugbar_show_memory'] == 1;
    if($show_memory && function_exists('memory_get_peak_usage'))
    {
        $memory_bytes = memory_get_peak_usage(true);
        $items[] = array('label' => 'Memory Usage', 'value' => mybb_debugbar_format_bytes($memory_bytes));
    }

    $items[] = array('label' => 'PHP Version', 'value' => phpversion());

    return $items;
}

/**
 * Format bytes into a human-readable string.
 *
 * @param int $bytes    The number of bytes.
 * @param int $precision The number of decimal places.
 * @return string       Formatted size string.
 */
function mybb_debugbar_format_bytes($bytes, $precision = 2)
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

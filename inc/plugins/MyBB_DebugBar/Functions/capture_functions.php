<?php
/**
 * MyBB Debug Bar - Error Capture Functions
 *
 * Provides error handling, shutdown handler, console logging,
 * and human-readable error type labels for the debug bar.
 */

if(!defined('IN_MYBB'))
{
    die('Direct initialization of this file is not allowed.');
}

/**
 * Start capturing PHP errors for the debug bar.
 *
 * Installs a custom error handler and a shutdown handler.
 * Initializes the global error and console arrays.
 *
 * @return void
 */
function debugbar_start_capture()
{
    // Record start time for execution time calculation
    if(!isset($GLOBALS['debugbar_start_time']))
    {
        $GLOBALS['debugbar_start_time'] = microtime(true);
    }

    // Initialize error and console storage
    if(!isset($GLOBALS['debugbar_errors']))
    {
        $GLOBALS['debugbar_errors'] = array();
    }
    if(!isset($GLOBALS['debugbar_console']))
    {
        $GLOBALS['debugbar_console'] = array();
    }

    // Set custom error handler and store the previous one
    if(!isset($GLOBALS['debugbar_previous_error_handler']))
    {
        $GLOBALS['debugbar_previous_error_handler'] = set_error_handler('debugbar_error_handler');
    }
}

/**
 * Custom error handler for the MyBB Debug Bar.
 *
 * Captures PHP errors, warnings, notices, and other diagnostic messages.
 * Stores them in $GLOBALS['debugbar_errors'] with full detail.
 *
 * @param int    $errno   The level of the error raised.
 * @param string $errstr  The error message.
 * @param string $errfile The filename where the error was raised.
 * @param int    $errline The line number where the error was raised.
 * @return bool           Passes to previous handler or returns false.
 */
function debugbar_error_handler($errno, $errstr, $errfile, $errline)
{
    global $mybb, $db;
    static $in_handler = false;

    if ($in_handler) {
        return false;
    }
    $in_handler = true;

    // Respect error_reporting settings unless it's a fatal error
    $fatal_types = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
    
    // Always capture the error for the debug bar, even if MyBB normally suppresses it
    // This makes the debug bar more "enhanced and advanced" as requested.
    $error = array(
        'type'    => $errno,
        'message' => $errstr,
        'file'    => $errfile,
        'line'    => $errline,
        'trace'   => array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 1),
    );

    $GLOBALS['debugbar_errors'][] = $error;

    // Optionally log to database
    if(isset($mybb->settings['mybb_debugbar_log_to_db'])
        && $mybb->settings['mybb_debugbar_log_to_db'] == 1
        && isset($db)
        && $db->table_exists("mybb_debugbar_logs"))
    {
        $uid = isset($mybb->user['uid']) ? (int)$mybb->user['uid'] : 0;
        $page_url = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $type_label = debugbar_get_error_type_label($errno);

        $log_entry = array(
            'uid'           => $uid,
            'page_url'      => $db->escape_string($page_url),
            'error_type'    => $db->escape_string($type_label),
            'error_message' => $db->escape_string($errstr),
            'error_file'    => $db->escape_string($errfile),
            'error_line'    => (int)$errline,
            'timestamp'     => TIME_NOW,
        );

        $db->insert_query("mybb_debugbar_logs", $log_entry);
    }

    // Pass the error to the previous handler (MyBB's default handler)
    // CRITICAL: Do NOT pass back to MyBB if we are currently inside an output buffer callback
    // MyBB's error handler calls ob_start() internally which causes a fatal crash.
    if(isset($GLOBALS['debugbar_previous_error_handler']) && is_callable($GLOBALS['debugbar_previous_error_handler']))
    {
        // Simple heuristic: if we are deep in buffering, it's safer to just log and skip MyBB's fragile handler
        if(ob_get_level() > 1) {
            $in_handler = false;
            return true; // We handled it
        }

        try {
            $result = call_user_func($GLOBALS['debugbar_previous_error_handler'], $errno, $errstr, $errfile, $errline);
        } catch (Throwable $e) {
            $result = false;
        }
        $in_handler = false;
        return $result;
    }

    $in_handler = false;
    return false;
}

/**
 * Shutdown handler for the MyBB Debug Bar.
 *
 * Captures fatal errors using error_get_last() and stores them.
 *
 * @return void
 */
function debugbar_shutdown_handler()
{
    $last_error = error_get_last();
    if($last_error !== null)
    {
        $fatal_types = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_CORE_WARNING,
                             E_COMPILE_ERROR, E_COMPILE_WARNING);

        if(in_array($last_error['type'], $fatal_types))
        {
            $GLOBALS['debugbar_errors'][] = array(
                'type'    => $last_error['type'],
                'message' => $last_error['message'],
                'file'    => $last_error['file'],
                'line'    => $last_error['line'],
                'trace'   => array(),
            );
        }
    }
}

/**
 * Log a custom message to the debug bar console.
 *
 * This is a public helper function that plugins and themes can use
 * to output diagnostic information to the debug bar's Console tab.
 *
 * @param string $message The message to log.
 * @param string $label   Optional label/type for the log entry (default: 'log').
 *                        Common values: 'log', 'info', 'warning', 'error', 'sql'.
 * @return void
 */
function debugbar_log($message, $label = 'log')
{
    if(!isset($GLOBALS['debugbar_console']))
    {
        $GLOBALS['debugbar_console'] = array();
    }

    $GLOBALS['debugbar_console'][] = array(
        'label'   => $label,
        'message' => $message,
        'time'    => microtime(true),
    );
}

/**
 * Push a debug entry to the debug bar's Debug tab.
 *
 * This is a public helper function that plugins and developers can use
 * to output diagnostic information to the debug bar's Debug tab.
 * Supports 50+ pre-defined slots/keys for comprehensive debugging.
 *
 * == EVENT SLOTS (Client-side JS events, pass structured data) ==
 *   'click'     - Click event data {target, x, y, button, timestamp}
 *   'dblclick'  - Double-click event data
 *   'hover'     - Hover/mouseenter event data {element, position}
 *   'mousemove' - Mouse movement data {x, y, target, movement}
 *   'mouse'     - Generic mouse event wrapper
 *   'touch'     - Touch event data {touches, target, x, y, force}
 *   'scroll'    - Scroll position {scrollX, scrollY, target}
 *   'resize'    - Window resize {width, height}
 *   'focus'     - Focus/blur event {element, type}
 *   'keyboard'  - Keyboard event {key, code, alt, ctrl, shift}
 *   'form'      - Form events {action, fields, values}
 *   'drag'      - Drag and drop {source, target, x, y}
 *   'animation' - CSS animation events {name, type, elapsed}
 *   'transition' - CSS transition events {property, duration, delay}
 *   'input'     - Input/change events {element, value, oldValue}
 *
 * == DOM/STYLE SLOTS ==
 *   'style'     - CSS computed styles of an element
 *   'html'      - HTML structure (innerHTML/outerHTML)
 *   'dom'       - DOM node details {tag, id, class, children}
 *   'selector'  - DOM query selector results
 *   'layout'    - Element layout {width, height, top, left, rect}
 *   'attr'      - Element attributes list
 *   'class'     - CSS class list manipulation debug
 *   'mutation'  - DOM mutation observer data
 *
 * == NETWORK SLOTS ==
 *   'ajax'      - AJAX request/response {url, method, status, data}
 *   'fetch'     - Fetch API request {url, method, headers, body}
 *   'network'   - Network info {online, type, latency}
 *   'api'       - API endpoint call {endpoint, params, result}
 *   'websocket' - WebSocket events {url, type, data}
 *
 * == JS RUNTIME SLOTS ==
 *   'js_error'  - JavaScript error {message, stack, line, col}
 *   'js_log'    - JavaScript custom log entry
 *   'timer'     - setTimeout/setInterval {id, delay, type}
 *   'promise'   - Promise state {status, value, reason}
 *   'storage'   - Web storage {key, value, action, source}
 *   'console'   - Console API interception data
 *   'worker'    - Web Worker messages
 *
 * == PHP ADVANCED SLOTS ==
 *   'file'      - File operations {path, mode, result, size}
 *   'include'   - Included files list
 *   'method'    - Method/function call {class, method, args, result}
 *   'filter'    - Input filtering {input, filter, result}
 *   'cache'     - Cache operations {key, action, hit, ttl}
 *   'redirect'  - Redirect/header debug {url, code, headers}
 *   'email'     - Email debug {to, subject, headers}
 *   'permission' - Permission checks {user, resource, granted}
 *   'upload'    - File upload debug {name, size, type, tmp}
 *   'image'     - Image processing {file, width, height, format}
 *   'locale'    - Locale/i18n {locale, translation, domain}
 *   'date'      - Date/time operations {format, input, output}
 *   'math'      - Math/calculation {operation, input, output}
 *
 * == CONFIG/SYSTEM SLOTS ==
 *   'config'    - Configuration values
 *   'constant'  - PHP constant values
 *   'header'    - HTTP headers {name, value, action}
 *   'cookie'    - Cookie data {name, value, action}
 *   'env'       - Environment variables
 *   'server'    - $_SERVER data
 *
 * == PERFORMANCE SLOTS ==
 *   'memory'    - Memory usage at a point {usage, peak, limit}
 *   'query'     - DB query with timing {query, time, rows}
 *   'profile'   - Code profiling section {label, duration, memory}
 *   'trace'     - Full execution backtrace
 *   'counter'   - Increment counter {name, value, delta}
 *
 * == BASIC SLOTS ==
 *   'variable'  - Dump a variable value (strings, numbers)
 *   'array'     - Formatted array key => value pairs
 *   'object'    - Object dump with class name
 *   'sql'       - SQL query string
 *   'hook'      - Hook call data
 *   'template'  - Template variables
 *   'request'   - $_GET/$_POST/$_REQUEST data
 *   'session'   - $_SESSION data
 *   'benchmark' - Timing benchmark
 *   'custom'    - Generic print_r dump (default)
 *
 * @param string $key   A label/identifier for the debug entry.
 * @param mixed  $data  The data to debug (string, array, object, etc.).
 * @param string $slot  Pre-defined slot type (default: 'custom').
 *                      See docblock above for full slot list.
 * @return void
 */
function debugbar_debug($key, $data = null, $slot = 'custom')
{
    if(!isset($GLOBALS['debugbar_debug']))
    {
        $GLOBALS['debugbar_debug'] = array();
    }

    $allowed_slots = array(
        // Event slots
        'click', 'dblclick', 'hover', 'mousemove', 'mouse', 'touch',
        'scroll', 'resize', 'focus', 'keyboard', 'form', 'drag',
        'animation', 'transition', 'input',
        // DOM/Style slots
        'style', 'html', 'dom', 'selector', 'layout', 'attr', 'class', 'mutation',
        // Network slots
        'ajax', 'fetch', 'network', 'api', 'websocket',
        // JS Runtime slots
        'js_error', 'js_log', 'timer', 'promise', 'storage', 'console', 'worker',
        // PHP Advanced slots
        'file', 'include', 'method', 'filter', 'cache', 'redirect', 'email',
        'permission', 'upload', 'image', 'locale', 'date', 'math',
        // Config/System slots
        'config', 'constant', 'header', 'cookie', 'env', 'server',
        // Performance slots
        'memory', 'query', 'profile', 'trace', 'counter',
        // Basic slots
        'variable', 'array', 'object', 'sql', 'hook', 'template',
        'request', 'session', 'benchmark', 'custom',
    );
    if(!in_array($slot, $allowed_slots))
    {
        $slot = 'custom';
    }

    $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
    $caller = isset($backtrace[1]) ? $backtrace[1] : (isset($backtrace[0]) ? $backtrace[0] : array());
    $caller_file = isset($caller['file']) ? $caller['file'] : '[internal]';
    $caller_line = isset($caller['line']) ? $caller['line'] : 0;

    $formatted = debugbar_format_debug_data($data, $slot);

    $GLOBALS['debugbar_debug'][] = array(
        'key'       => $key,
        'slot'      => $slot,
        'data'      => $formatted,
        'raw_data'  => $data,
        'file'      => $caller_file,
        'line'      => $caller_line,
        'time'      => microtime(true),
    );
}

/**
 * Format debug data based on slot type for display.
 *
 * Handles 50+ slot types with specialized formatting for each category.
 *
 * @param mixed  $data The raw debug data.
 * @param string $slot The slot type.
 * @return string Formatted debug data string.
 */
function debugbar_format_debug_data($data, $slot)
{
    $formatted = debugbar_format_structured($data, $slot);
    if($formatted !== null)
    {
        return $formatted;
    }

    return print_r($data, true);
}

/**
 * Attempt structured formatting for a given slot type.
 *
 * Returns null if the slot does not match any known structured formatter,
 * allowing debugbar_format_debug_data to fall back to print_r.
 *
 * @param mixed  $data The raw debug data.
 * @param string $slot The slot type.
 * @return string|null Formatted string or null to fall back.
 */
function debugbar_format_structured($data, $slot)
{
    // ========== BASIC SLOTS ==========
    if($slot === 'variable')
    {
        if(is_bool($data)) return $data ? 'true' : 'false';
        if(is_null($data)) return 'null';
        if(is_scalar($data)) return (string)$data;
        return print_r($data, true);
    }

    if($slot === 'array')
    {
        return debugbar_format_array_debug($data);
    }

    if($slot === 'object')
    {
        if(is_object($data))
        {
            $output = 'Class: ' . get_class($data) . "\n";
            $output .= print_r($data, true);
            return $output;
        }
        return print_r($data, true);
    }

    if($slot === 'sql' || $slot === 'query')
    {
        if(is_string($data)) return $data;
        if(is_array($data))
        {
            $parts = array();
            foreach($data as $k => $v)
            {
                $parts[] = $k . ': ' . (is_scalar($v) ? $v : print_r($v, true));
            }
            return implode("\n", $parts);
        }
        return null;
    }

    if($slot === 'hook')
    {
        if(is_string($data)) return 'Hook: ' . $data;
        if(is_array($data))
        {
            $parts = array();
            foreach($data as $k => $v)
            {
                $parts[] = $k . ': ' . (is_scalar($v) ? (is_bool($v) ? ($v ? 'true' : 'false') : (is_null($v) ? 'null' : $v)) : '(' . gettype($v) . ')');
            }
            return implode("\n", $parts);
        }
        return null;
    }

    if($slot === 'template')
    {
        if(is_array($data))
        {
            $parts = array();
            foreach($data as $k => $v)
            {
                if(is_scalar($v) || is_null($v))
                {
                    if(is_null($v)) $v = 'null';
                    elseif(is_bool($v)) $v = $v ? 'true' : 'false';
                    $parts[] = '$' . $k . ' = ' . $v;
                }
                else
                {
                    $parts[] = '$' . $k . ' = (' . gettype($v) . ')';
                }
            }
            return implode("\n", $parts);
        }
        return null;
    }

    if($slot === 'request')
    {
        if(is_array($data))
        {
            $parts = array();
            foreach($data as $k => $v)
            {
                $val = (is_array($v)) ? print_r($v, true) : (is_scalar($v) ? (is_bool($v) ? ($v ? 'true' : 'false') : (is_null($v) ? 'null' : $v)) : '(' . gettype($v) . ')');
                $parts[] = '$_' . strtoupper($k) . ': ' . $val;
            }
            return implode("\n", $parts);
        }
        return null;
    }

    if($slot === 'session')
    {
        return debugbar_format_array_debug($data);
    }

    if($slot === 'benchmark' || $slot === 'profile')
    {
        if(is_numeric($data))
        {
            $elapsed = number_format(microtime(true) - $data, 6);
            return 'Elapsed: ' . $elapsed . ' seconds';
        }
        if(is_array($data) && isset($data['start']))
        {
            $elapsed = number_format(microtime(true) - $data['start'], 6);
            $label = isset($data['label']) ? $data['label'] : 'Profile';
            $mem = isset($data['memory']) ? ', Memory: ' . debugbar_format_profile_memory($data['memory']) : '';
            $delta = isset($data['delta_mem']) ? ', Delta: ' . debugbar_format_profile_memory($data['delta_mem']) : '';
            return $label . ': ' . $elapsed . 's' . $mem . $delta;
        }
        return null;
    }

    // ========== EVENT SLOTS ==========
    if(in_array($slot, array('click', 'dblclick', 'mouse', 'mousemove', 'hover')))
    {
        if(is_array($data))
        {
            $type = isset($data['type']) ? $data['type'] : $slot;
            $target = isset($data['target']) ? $data['target'] : 'N/A';
            $x = isset($data['x']) ? $data['x'] : 'N/A';
            $y = isset($data['y']) ? $data['y'] : 'N/A';
            $parts = array('Event: ' . $type, 'Target: ' . $target, 'X: ' . $x, 'Y: ' . $y);
            if(isset($data['button'])) $parts[] = 'Button: ' . $data['button'];
            if(isset($data['movementX'])) $parts[] = 'MovX: ' . $data['movementX'];
            if(isset($data['movementY'])) $parts[] = 'MovY: ' . $data['movementY'];
            if(isset($data['altKey'])) $parts[] = 'Alt: ' . ($data['altKey'] ? 'yes' : 'no');
            if(isset($data['ctrlKey'])) $parts[] = 'Ctrl: ' . ($data['ctrlKey'] ? 'yes' : 'no');
            if(isset($data['shiftKey'])) $parts[] = 'Shift: ' . ($data['shiftKey'] ? 'yes' : 'no');
            if(isset($data['timestamp'])) $parts[] = 'Time: ' . $data['timestamp'];
            return implode("\n", $parts);
        }
        return 'Event Data: ' . print_r($data, true);
    }

    if($slot === 'touch')
    {
        if(is_array($data))
        {
            $parts = array('Event: ' . (isset($data['type']) ? $data['type'] : 'touch'));
            $parts[] = 'Target: ' . (isset($data['target']) ? $data['target'] : 'N/A');
            $parts[] = 'Touches: ' . (isset($data['touches']) ? $data['touches'] : '0');
            if(isset($data['x'])) $parts[] = 'X: ' . $data['x'];
            if(isset($data['y'])) $parts[] = 'Y: ' . $data['y'];
            if(isset($data['force'])) $parts[] = 'Force: ' . $data['force'];
            if(isset($data['radiusX'])) $parts[] = 'RadiusX: ' . $data['radiusX'];
            if(isset($data['radiusY'])) $parts[] = 'RadiusY: ' . $data['radiusY'];
            return implode("\n", $parts);
        }
        return null;
    }

    if($slot === 'scroll')
    {
        if(is_array($data))
        {
            $parts = array('Scroll Position');
            $parts[] = 'ScrollX: ' . (isset($data['scrollX']) ? $data['scrollX'] : '0');
            $parts[] = 'ScrollY: ' . (isset($data['scrollY']) ? $data['scrollY'] : '0');
            if(isset($data['target'])) $parts[] = 'Target: ' . $data['target'];
            if(isset($data['scrollWidth'])) $parts[] = 'ScrollW: ' . $data['scrollWidth'];
            if(isset($data['scrollHeight'])) $parts[] = 'ScrollH: ' . $data['scrollHeight'];
            return implode("\n", $parts);
        }
        return null;
    }

    if($slot === 'resize')
    {
        if(is_array($data))
        {
            $parts = array('Window Resize');
            $parts[] = 'Width: ' . (isset($data['width']) ? $data['width'] : 'N/A');
            $parts[] = 'Height: ' . (isset($data['height']) ? $data['height'] : 'N/A');
            if(isset($data['innerWidth'])) $parts[] = 'InnerW: ' . $data['innerWidth'];
            if(isset($data['innerHeight'])) $parts[] = 'InnerH: ' . $data['innerHeight'];
            if(isset($data['outerWidth'])) $parts[] = 'OuterW: ' . $data['outerWidth'];
            if(isset($data['outerHeight'])) $parts[] = 'OuterH: ' . $data['outerHeight'];
            return implode("\n", $parts);
        }
        return null;
    }

    if($slot === 'focus')
    {
        if(is_array($data))
        {
            $parts = array('Focus Event');
            $parts[] = 'Type: ' . (isset($data['type']) ? $data['type'] : 'N/A');
            $parts[] = 'Target: ' . (isset($data['target']) ? $data['target'] : 'N/A');
            $parts[] = 'Tag: ' . (isset($data['tagName']) ? $data['tagName'] : 'N/A');
            if(isset($data['id'])) $parts[] = 'ID: ' . $data['id'];
            if(isset($data['relatedTarget'])) $parts[] = 'Related: ' . $data['relatedTarget'];
            return implode("\n", $parts);
        }
        return null;
    }

    if($slot === 'keyboard')
    {
        if(is_array($data))
        {
            $parts = array('Keyboard Event');
            $parts[] = 'Type: ' . (isset($data['type']) ? $data['type'] : 'N/A');
            $parts[] = 'Key: ' . (isset($data['key']) ? $data['key'] : 'N/A');
            $parts[] = 'Code: ' . (isset($data['code']) ? $data['code'] : 'N/A');
            $parts[] = 'Alt: ' . (isset($data['altKey']) && $data['altKey'] ? 'yes' : 'no');
            $parts[] = 'Ctrl: ' . (isset($data['ctrlKey']) && $data['ctrlKey'] ? 'yes' : 'no');
            $parts[] = 'Shift: ' . (isset($data['shiftKey']) && $data['shiftKey'] ? 'yes' : 'no');
            $parts[] = 'Meta: ' . (isset($data['metaKey']) && $data['metaKey'] ? 'yes' : 'no');
            if(isset($data['repeat'])) $parts[] = 'Repeat: ' . ($data['repeat'] ? 'yes' : 'no');
            if(isset($data['location'])) $parts[] = 'Location: ' . $data['location'];
            return implode("\n", $parts);
        }
        return null;
    }

    if($slot === 'form')
    {
        if(is_array($data))
        {
            $parts = array('Form Event');
            $parts[] = 'Type: ' . (isset($data['type']) ? $data['type'] : 'N/A');
            $parts[] = 'Action: ' . (isset($data['action']) ? $data['action'] : 'N/A');
            $parts[] = 'Method: ' . (isset($data['method']) ? $data['method'] : 'N/A');
            if(isset($data['fields']) && is_array($data['fields']))
            {
                $parts[] = 'Fields:';
                foreach($data['fields'] as $f => $v)
                {
                    $parts[] = '  ' . $f . ' = ' . (is_scalar($v) ? $v : print_r($v, true));
                }
            }
            if(isset($data['valid'])) $parts[] = 'Valid: ' . ($data['valid'] ? 'yes' : 'no');
            return implode("\n", $parts);
        }
        return null;
    }

    if($slot === 'drag')
    {
        if(is_array($data))
        {
            $parts = array('Drag Event');
            $parts[] = 'Type: ' . (isset($data['type']) ? $data['type'] : 'N/A');
            $parts[] = 'Source: ' . (isset($data['source']) ? $data['source'] : 'N/A');
            $parts[] = 'Target: ' . (isset($data['target']) ? $data['target'] : 'N/A');
            if(isset($data['x'])) $parts[] = 'X: ' . $data['x'];
            if(isset($data['y'])) $parts[] = 'Y: ' . $data['y'];
            if(isset($data['effect'])) $parts[] = 'Effect: ' . $data['effect'];
            if(isset($data['data'])) $parts[] = 'Data: ' . $data['data'];
            return implode("\n", $parts);
        }
        return null;
    }

    if(in_array($slot, array('animation', 'transition')))
    {
        if(is_array($data))
        {
            $type = $slot === 'animation' ? 'Animation' : 'Transition';
            $parts = array($type . ' Event');
            $parts[] = 'Type: ' . (isset($data['type']) ? $data['type'] : 'N/A');
            $parts[] = 'Name: ' . (isset($data['name']) ? $data['name'] : 'N/A');
            if(isset($data['elapsed'])) $parts[] = 'Elapsed: ' . $data['elapsed'] . 's';
            if(isset($data['property'])) $parts[] = 'Property: ' . $data['property'];
            if(isset($data['duration'])) $parts[] = 'Duration: ' . $data['duration'] . 's';
            if(isset($data['delay'])) $parts[] = 'Delay: ' . $data['delay'] . 's';
            if(isset($data['target'])) $parts[] = 'Target: ' . $data['target'];
            return implode("\n", $parts);
        }
        return null;
    }

    if($slot === 'input')
    {
        if(is_array($data))
        {
            $parts = array('Input Event');
            $parts[] = 'Target: ' . (isset($data['target']) ? $data['target'] : 'N/A');
            $parts[] = 'Value: ' . (isset($data['value']) ? $data['value'] : 'N/A');
            if(isset($data['oldValue'])) $parts[] = 'Old: ' . $data['oldValue'];
            if(isset($data['inputType'])) $parts[] = 'InputType: ' . $data['inputType'];
            if(isset($data['selectionStart'])) $parts[] = 'SelStart: ' . $data['selectionStart'];
            if(isset($data['selectionEnd'])) $parts[] = 'SelEnd: ' . $data['selectionEnd'];
            return implode("\n", $parts);
        }
        return null;
    }

    // ========== DOM/STYLE SLOTS ==========
    if($slot === 'style')
    {
        if(is_array($data))
        {
            $parts = array('Computed Styles');
            $parts[] = 'Element: ' . (isset($data['element']) ? $data['element'] : 'N/A');
            if(isset($data['styles']) && is_array($data['styles']))
            {
                foreach($data['styles'] as $prop => $val)
                {
                    $parts[] = '  ' . $prop . ': ' . $val;
                }
            }
            return implode("\n", $parts);
        }
        return null;
    }

    if($slot === 'html')
    {
        if(is_string($data))
        {
            $len = strlen($data);
            $preview = $len > 2000 ? substr($data, 0, 2000) . "\n... [truncated, total {$len} chars]" : $data;
            return $preview;
        }
        if(is_array($data) && isset($data['html']))
        {
            $html = $data['html'];
            $len = strlen($html);
            $preview = $len > 2000 ? substr($html, 0, 2000) . "\n... [truncated, total {$len} chars]" : $html;
            $label = isset($data['element']) ? 'Element: ' . $data['element'] . "\n" : '';
            return $label . $preview;
        }
        return null;
    }

    if($slot === 'dom')
    {
        if(is_array($data))
        {
            $parts = array('DOM Node');
            $parts[] = 'Tag: ' . (isset($data['tag']) ? $data['tag'] : 'N/A');
            if(isset($data['id'])) $parts[] = 'ID: ' . $data['id'];
            if(isset($data['class'])) $parts[] = 'Class: ' . $data['class'];
            if(isset($data['children'])) $parts[] = 'Children: ' . $data['children'];
            if(isset($data['depth'])) $parts[] = 'Depth: ' . $data['depth'];
            if(isset($data['text'])) $parts[] = 'Text: ' . (strlen($data['text']) > 200 ? substr($data['text'], 0, 200) . '...' : $data['text']);
            if(isset($data['parent'])) $parts[] = 'Parent: ' . $data['parent'];
            if(isset($data['index'])) $parts[] = 'Index: ' . $data['index'];
            return implode("\n", $parts);
        }
        return null;
    }

    if($slot === 'selector')
    {
        if(is_array($data))
        {
            $parts = array('Selector Query');
            $parts[] = 'Selector: ' . (isset($data['selector']) ? $data['selector'] : 'N/A');
            $parts[] = 'Matches: ' . (isset($data['count']) ? $data['count'] : '0');
            if(isset($data['first'])) $parts[] = 'First: ' . $data['first'];
            if(isset($data['elements']) && is_array($data['elements']))
            {
                foreach($data['elements'] as $i => $el)
                {
                    $parts[] = '  [' . $i . '] ' . $el;
                }
            }
            return implode("\n", $parts);
        }
        return null;
    }

    if($slot === 'layout')
    {
        if(is_array($data))
        {
            $parts = array('Element Layout');
            $parts[] = 'Element: ' . (isset($data['element']) ? $data['element'] : 'N/A');
            $parts[] = 'Width: ' . (isset($data['width']) ? $data['width'] . 'px' : 'N/A');
            $parts[] = 'Height: ' . (isset($data['height']) ? $data['height'] . 'px' : 'N/A');
            $parts[] = 'Top: ' . (isset($data['top']) ? $data['top'] . 'px' : 'N/A');
            $parts[] = 'Left: ' . (isset($data['left']) ? $data['left'] . 'px' : 'N/A');
            if(isset($data['bottom'])) $parts[] = 'Bottom: ' . $data['bottom'] . 'px';
            if(isset($data['right'])) $parts[] = 'Right: ' . $data['right'] . 'px';
            if(isset($data['offsetParent'])) $parts[] = 'OffsetParent: ' . $data['offsetParent'];
            if(isset($data['visible'])) $parts[] = 'Visible: ' . ($data['visible'] ? 'yes' : 'no');
            return implode("\n", $parts);
        }
        return null;
    }

    if($slot === 'attr')
    {
        if(is_array($data))
        {
            $parts = array('Element Attributes');
            $parts[] = 'Element: ' . (isset($data['element']) ? $data['element'] : 'N/A');
            if(isset($data['attributes']) && is_array($data['attributes']))
            {
                foreach($data['attributes'] as $name => $val)
                {
                    $parts[] = '  ' . $name . ' = "' . $val . '"';
                }
            }
            return implode("\n", $parts);
        }
        return null;
    }

    if($slot === 'class')
    {
        if(is_array($data))
        {
            $parts = array('CSS Class Operation');
            $parts[] = 'Element: ' . (isset($data['element']) ? $data['element'] : 'N/A');
            $parts[] = 'Action: ' . (isset($data['action']) ? $data['action'] : 'N/A');
            if(isset($data['class'])) $parts[] = 'Class: ' . $data['class'];
            if(isset($data['classList']) && is_array($data['classList']))
            {
                $parts[] = 'ClassList: ' . implode(', ', $data['classList']);
            }
            return implode("\n", $parts);
        }
        return null;
    }

    if($slot === 'mutation')
    {
        if(is_array($data))
        {
            $parts = array('DOM Mutation');
            $parts[] = 'Type: ' . (isset($data['type']) ? $data['type'] : 'N/A');
            $parts[] = 'Target: ' . (isset($data['target']) ? $data['target'] : 'N/A');
            if(isset($data['added'])) $parts[] = 'Added: ' . $data['added'];
            if(isset($data['removed'])) $parts[] = 'Removed: ' . $data['removed'];
            if(isset($data['attribute'])) $parts[] = 'Attribute: ' . $data['attribute'];
            if(isset($data['oldValue'])) $parts[] = 'OldValue: ' . $data['oldValue'];
            if(isset($data['newValue'])) $parts[] = 'NewValue: ' . $data['newValue'];
            return implode("\n", $parts);
        }
        return null;
    }

    // ========== NETWORK SLOTS ==========
    if(in_array($slot, array('ajax', 'fetch', 'api')))
    {
        if(is_array($data))
        {
            $label = $slot === 'ajax' ? 'AJAX' : ($slot === 'fetch' ? 'Fetch' : 'API');
            $parts = array($label . ' Request');
            $parts[] = 'URL: ' . (isset($data['url']) ? $data['url'] : 'N/A');
            $parts[] = 'Method: ' . (isset($data['method']) ? $data['method'] : 'N/A');
            if(isset($data['status'])) $parts[] = 'Status: ' . $data['status'];
            if(isset($data['statusText'])) $parts[] = 'StatusText: ' . $data['statusText'];
            if(isset($data['duration'])) $parts[] = 'Duration: ' . $data['duration'] . 'ms';
            if(isset($data['body'])) $parts[] = 'Body: ' . (is_string($data['body']) ? $data['body'] : print_r($data['body'], true));
            if(isset($data['response'])) $parts[] = 'Response: ' . (strlen(print_r($data['response'], true)) > 500 ? substr(print_r($data['response'], true), 0, 500) . '...' : print_r($data['response'], true));
            if(isset($data['headers']) && is_array($data['headers']))
            {
                $parts[] = 'Headers:';
                foreach($data['headers'] as $hk => $hv) $parts[] = '  ' . $hk . ': ' . $hv;
            }
            return implode("\n", $parts);
        }
        if(is_string($data)) return 'URL: ' . $data;
        return null;
    }

    if($slot === 'network')
    {
        if(is_array($data))
        {
            $parts = array('Network Info');
            $parts[] = 'Online: ' . (isset($data['online']) ? ($data['online'] ? 'yes' : 'no') : 'N/A');
            if(isset($data['type'])) $parts[] = 'Type: ' . $data['type'];
            if(isset($data['downlink'])) $parts[] = 'Downlink: ' . $data['downlink'] . ' Mbps';
            if(isset($data['rtt'])) $parts[] = 'RTT: ' . $data['rtt'] . ' ms';
            if(isset($data['latency'])) $parts[] = 'Latency: ' . $data['latency'] . ' ms';
            return implode("\n", $parts);
        }
        return null;
    }

    if($slot === 'websocket')
    {
        if(is_array($data))
        {
            $parts = array('WebSocket');
            $parts[] = 'URL: ' . (isset($data['url']) ? $data['url'] : 'N/A');
            $parts[] = 'Type: ' . (isset($data['type']) ? $data['type'] : 'N/A');
            if(isset($data['data'])) $parts[] = 'Data: ' . (strlen(print_r($data['data'], true)) > 300 ? substr(print_r($data['data'], true), 0, 300) . '...' : print_r($data['data'], true));
            if(isset($data['code'])) $parts[] = 'Code: ' . $data['code'];
            if(isset($data['reason'])) $parts[] = 'Reason: ' . $data['reason'];
            if(isset($data['readyState'])) $parts[] = 'ReadyState: ' . $data['readyState'];
            return implode("\n", $parts);
        }
        return null;
    }

    // ========== JS RUNTIME SLOTS ==========
    if($slot === 'js_error')
    {
        if(is_array($data))
        {
            $parts = array('JavaScript Error');
            $parts[] = 'Message: ' . (isset($data['message']) ? $data['message'] : 'N/A');
            if(isset($data['stack'])) $parts[] = 'Stack: ' . $data['stack'];
            if(isset($data['line'])) $parts[] = 'Line: ' . $data['line'];
            if(isset($data['col'])) $parts[] = 'Col: ' . $data['col'];
            if(isset($data['file'])) $parts[] = 'File: ' . $data['file'];
            if(isset($data['type'])) $parts[] = 'Type: ' . $data['type'];
            return implode("\n", $parts);
        }
        if(is_string($data)) return 'Error: ' . $data;
        return null;
    }

    if($slot === 'js_log')
    {
        return is_scalar($data) ? (string)$data : print_r($data, true);
    }

    if($slot === 'timer')
    {
        if(is_array($data))
        {
            $parts = array('Timer Event');
            $parts[] = 'Type: ' . (isset($data['type']) ? $data['type'] : 'N/A');
            $parts[] = 'ID: ' . (isset($data['id']) ? $data['id'] : 'N/A');
            if(isset($data['delay'])) $parts[] = 'Delay: ' . $data['delay'] . 'ms';
            if(isset($data['remaining'])) $parts[] = 'Remaining: ' . $data['remaining'] . 'ms';
            if(isset($data['executions'])) $parts[] = 'Executions: ' . $data['executions'];
            return implode("\n", $parts);
        }
        return null;
    }

    if($slot === 'promise')
    {
        if(is_array($data))
        {
            $parts = array('Promise');
            $parts[] = 'Status: ' . (isset($data['status']) ? $data['status'] : 'N/A');
            if(isset($data['value'])) $parts[] = 'Value: ' . (is_string($data['value']) ? $data['value'] : print_r($data['value'], true));
            if(isset($data['reason'])) $parts[] = 'Reason: ' . (is_string($data['reason']) ? $data['reason'] : print_r($data['reason'], true));
            if(isset($data['duration'])) $parts[] = 'Duration: ' . $data['duration'] . 'ms';
            return implode("\n", $parts);
        }
        return null;
    }

    if($slot === 'storage')
    {
        if(is_array($data))
        {
            $parts = array('Web Storage');
            $parts[] = 'Source: ' . (isset($data['source']) ? $data['source'] : 'N/A');
            $parts[] = 'Action: ' . (isset($data['action']) ? $data['action'] : 'N/A');
            if(isset($data['key'])) $parts[] = 'Key: ' . $data['key'];
            if(isset($data['value'])) $parts[] = 'Value: ' . (strlen($data['value']) > 200 ? substr($data['value'], 0, 200) . '...' : $data['value']);
            if(isset($data['oldValue'])) $parts[] = 'Old: ' . (strlen($data['oldValue']) > 200 ? substr($data['oldValue'], 0, 200) . '...' : $data['oldValue']);
            return implode("\n", $parts);
        }
        return null;
    }

    if($slot === 'console')
    {
        if(is_array($data))
        {
            $parts = array('Console API');
            $parts[] = 'Method: ' . (isset($data['method']) ? $data['method'] : 'N/A');
            $parts[] = 'Args: ' . (isset($data['args']) ? (is_array($data['args']) ? implode(', ', array_map(function($a){ return is_scalar($a) ? (string)$a : gettype($a); }, $data['args'])) : $data['args']) : 'N/A');
            return implode("\n", $parts);
        }
        return null;
    }

    if($slot === 'worker')
    {
        if(is_array($data))
        {
            $parts = array('Web Worker');
            $parts[] = 'Type: ' . (isset($data['type']) ? $data['type'] : 'N/A');
            $parts[] = 'Data: ' . (isset($data['data']) ? (strlen(print_r($data['data'], true)) > 300 ? substr(print_r($data['data'], true), 0, 300) . '...' : print_r($data['data'], true)) : 'N/A');
            if(isset($data['error'])) $parts[] = 'Error: ' . $data['error'];
            return implode("\n", $parts);
        }
        return null;
    }

    // ========== PHP ADVANCED SLOTS ==========
    if($slot === 'file')
    {
        if(is_array($data))
        {
            $parts = array('File Operation');
            $parts[] = 'Path: ' . (isset($data['path']) ? $data['path'] : 'N/A');
            $parts[] = 'Mode: ' . (isset($data['mode']) ? $data['mode'] : 'N/A');
            if(isset($data['result'])) $parts[] = 'Result: ' . (is_bool($data['result']) ? ($data['result'] ? 'success' : 'failed') : $data['result']);
            if(isset($data['size'])) $parts[] = 'Size: ' . $data['size'] . ' bytes';
            if(isset($data['exists'])) $parts[] = 'Exists: ' . ($data['exists'] ? 'yes' : 'no');
            if(isset($data['perms'])) $parts[] = 'Perms: ' . $data['perms'];
            if(isset($data['owner'])) $parts[] = 'Owner: ' . $data['owner'];
            return implode("\n", $parts);
        }
        if(is_string($data)) return 'File: ' . $data;
        return null;
    }

    if($slot === 'include')
    {
        if(is_array($data))
        {
            $parts = array('Included Files (' . count($data) . ')');
            foreach($data as $i => $file)
            {
                $parts[] = '  [' . $i . '] ' . $file;
            }
            return implode("\n", $parts);
        }
        if(is_string($data)) return 'Include: ' . $data;
        return null;
    }

    if($slot === 'method')
    {
        if(is_array($data))
        {
            $parts = array('Method Call');
            if(isset($data['class'])) $parts[] = 'Class: ' . $data['class'];
            $parts[] = 'Method: ' . (isset($data['method']) ? $data['method'] : 'N/A');
            if(isset($data['args']) && is_array($data['args']))
            {
                $parts[] = 'Args:';
                foreach($data['args'] as $i => $arg)
                {
                    $parts[] = '  [' . $i . '] ' . (is_scalar($arg) ? (string)$arg : gettype($arg));
                }
            }
            if(isset($data['result'])) $parts[] = 'Result: ' . (is_scalar($data['result']) ? (string)$data['result'] : gettype($data['result']));
            if(isset($data['duration'])) $parts[] = 'Duration: ' . $data['duration'] . 'ms';
            return implode("\n", $parts);
        }
        return null;
    }

    if($slot === 'filter')
    {
        if(is_array($data))
        {
            $parts = array('Input Filter');
            $parts[] = 'Input: ' . (isset($data['input']) ? $data['input'] : 'N/A');
            $parts[] = 'Filter: ' . (isset($data['filter']) ? $data['filter'] : 'N/A');
            if(isset($data['result'])) $parts[] = 'Result: ' . (is_bool($data['result']) ? ($data['result'] ? 'passed' : 'failed') : (is_scalar($data['result']) ? (string)$data['result'] : print_r($data['result'], true)));
            if(isset($data['rules'])) $parts[] = 'Rules: ' . (is_array($data['rules']) ? implode(', ', $data['rules']) : $data['rules']);
            return implode("\n", $parts);
        }
        return null;
    }

    if($slot === 'cache')
    {
        if(is_array($data))
        {
            $parts = array('Cache Operation');
            $parts[] = 'Key: ' . (isset($data['key']) ? $data['key'] : 'N/A');
            $parts[] = 'Action: ' . (isset($data['action']) ? $data['action'] : 'N/A');
            if(isset($data['hit'])) $parts[] = 'Hit: ' . ($data['hit'] ? 'yes' : 'no');
            if(isset($data['ttl'])) $parts[] = 'TTL: ' . $data['ttl'] . 's';
            if(isset($data['size'])) $parts[] = 'Size: ' . $data['size'] . ' bytes';
            if(isset($data['value'])) $parts[] = 'Value: ' . (is_scalar($data['value']) ? (string)$data['value'] : gettype($data['value']));
            return implode("\n", $parts);
        }
        return null;
    }

    if($slot === 'redirect')
    {
        if(is_array($data))
        {
            $parts = array('Redirect');
            $parts[] = 'URL: ' . (isset($data['url']) ? $data['url'] : 'N/A');
            if(isset($data['code'])) $parts[] = 'Code: ' . $data['code'];
            if(isset($data['headers']) && is_array($data['headers']))
            {
                $parts[] = 'Headers:';
                foreach($data['headers'] as $hk => $hv) $parts[] = '  ' . $hk . ': ' . $hv;
            }
            if(isset($data['from'])) $parts[] = 'From: ' . $data['from'];
            return implode("\n", $parts);
        }
        if(is_string($data)) return 'Redirect: ' . $data;
        return null;
    }

    if($slot === 'email')
    {
        if(is_array($data))
        {
            $parts = array('Email Debug');
            $parts[] = 'To: ' . (isset($data['to']) ? $data['to'] : 'N/A');
            $parts[] = 'Subject: ' . (isset($data['subject']) ? $data['subject'] : 'N/A');
            if(isset($data['from'])) $parts[] = 'From: ' . $data['from'];
            if(isset($data['headers']) && is_array($data['headers']))
            {
                foreach($data['headers'] as $hk => $hv) $parts[] = '  ' . $hk . ': ' . $hv;
            }
            if(isset($data['sent'])) $parts[] = 'Sent: ' . ($data['sent'] ? 'yes' : 'no');
            if(isset($data['error'])) $parts[] = 'Error: ' . $data['error'];
            if(isset($data['size'])) $parts[] = 'Size: ' . $data['size'] . ' bytes';
            return implode("\n", $parts);
        }
        return null;
    }

    if($slot === 'permission')
    {
        if(is_array($data))
        {
            $parts = array('Permission Check');
            $parts[] = 'Resource: ' . (isset($data['resource']) ? $data['resource'] : 'N/A');
            $parts[] = 'User: ' . (isset($data['user']) ? $data['user'] : 'N/A');
            $parts[] = 'Granted: ' . (isset($data['granted']) ? ($data['granted'] ? 'yes' : 'no') : 'N/A');
            if(isset($data['action'])) $parts[] = 'Action: ' . $data['action'];
            if(isset($data['reason'])) $parts[] = 'Reason: ' . $data['reason'];
            return implode("\n", $parts);
        }
        return null;
    }

    if($slot === 'upload')
    {
        if(is_array($data))
        {
            $parts = array('File Upload');
            $parts[] = 'Name: ' . (isset($data['name']) ? $data['name'] : 'N/A');
            $parts[] = 'Size: ' . (isset($data['size']) ? $data['size'] . ' bytes' : 'N/A');
            $parts[] = 'Type: ' . (isset($data['type']) ? $data['type'] : 'N/A');
            if(isset($data['tmp'])) $parts[] = 'Tmp: ' . $data['tmp'];
            if(isset($data['error'])) $parts[] = 'Error: ' . $data['error'];
            if(isset($data['valid'])) $parts[] = 'Valid: ' . ($data['valid'] ? 'yes' : 'no');
            if(isset($data['width'])) $parts[] = 'Width: ' . $data['width'] . 'px';
            if(isset($data['height'])) $parts[] = 'Height: ' . $data['height'] . 'px';
            return implode("\n", $parts);
        }
        return null;
    }

    if($slot === 'image')
    {
        if(is_array($data))
        {
            $parts = array('Image Processing');
            $parts[] = 'File: ' . (isset($data['file']) ? $data['file'] : 'N/A');
            if(isset($data['width'])) $parts[] = 'Width: ' . $data['width'] . 'px';
            if(isset($data['height'])) $parts[] = 'Height: ' . $data['height'] . 'px';
            if(isset($data['format'])) $parts[] = 'Format: ' . $data['format'];
            if(isset($data['size'])) $parts[] = 'Size: ' . $data['size'] . ' bytes';
            if(isset($data['ratio'])) $parts[] = 'Ratio: ' . $data['ratio'];
            if(isset($data['channels'])) $parts[] = 'Channels: ' . $data['channels'];
            if(isset($data['bits'])) $parts[] = 'Bits: ' . $data['bits'];
            if(isset($data['mime'])) $parts[] = 'MIME: ' . $data['mime'];
            return implode("\n", $parts);
        }
        return null;
    }

    if($slot === 'locale')
    {
        if(is_array($data))
        {
            $parts = array('Locale / i18n');
            $parts[] = 'Locale: ' . (isset($data['locale']) ? $data['locale'] : 'N/A');
            if(isset($data['string'])) $parts[] = 'String: ' . $data['string'];
            if(isset($data['translation'])) $parts[] = 'Translation: ' . $data['translation'];
            if(isset($data['domain'])) $parts[] = 'Domain: ' . $data['domain'];
            if(isset($data['found'])) $parts[] = 'Found: ' . ($data['found'] ? 'yes' : 'no');
            return implode("\n", $parts);
        }
        return null;
    }

    if($slot === 'date')
    {
        if(is_array($data))
        {
            $parts = array('Date/Time');
            $parts[] = 'Format: ' . (isset($data['format']) ? $data['format'] : 'N/A');
            $parts[] = 'Input: ' . (isset($data['input']) ? $data['input'] : 'N/A');
            $parts[] = 'Output: ' . (isset($data['output']) ? $data['output'] : 'N/A');
            if(isset($data['timezone'])) $parts[] = 'Timezone: ' . $data['timezone'];
            if(isset($data['timestamp'])) $parts[] = 'Timestamp: ' . $data['timestamp'];
            return implode("\n", $parts);
        }
        return null;
    }

    if($slot === 'math')
    {
        if(is_array($data))
        {
            $parts = array('Math / Calculation');
            $parts[] = 'Operation: ' . (isset($data['operation']) ? $data['operation'] : 'N/A');
            if(isset($data['input'])) $parts[] = 'Input: ' . (is_scalar($data['input']) ? (string)$data['input'] : print_r($data['input'], true));
            if(isset($data['output'])) $parts[] = 'Output: ' . (is_scalar($data['output']) ? (string)$data['output'] : print_r($data['output'], true));
            if(isset($data['precision'])) $parts[] = 'Precision: ' . $data['precision'];
            return implode("\n", $parts);
        }
        return null;
    }

    // ========== CONFIG/SYSTEM SLOTS ==========
    if($slot === 'config')
    {
        return debugbar_format_array_debug($data);
    }

    if($slot === 'constant')
    {
        if(is_array($data))
        {
            $parts = array('Constants');
            foreach($data as $name => $val)
            {
                $parts[] = '  ' . $name . ' => ' . (is_scalar($val) ? (string)$val : gettype($val));
            }
            return implode("\n", $parts);
        }
        if(is_string($data))
        {
            $val = defined($data) ? constant($data) : 'undefined';
            return $data . ' => ' . (is_scalar($val) ? (string)$val : gettype($val));
        }
        return null;
    }

    if($slot === 'header')
    {
        if(is_array($data))
        {
            $parts = array('HTTP Header');
            $parts[] = 'Name: ' . (isset($data['name']) ? $data['name'] : 'N/A');
            $parts[] = 'Value: ' . (isset($data['value']) ? $data['value'] : 'N/A');
            if(isset($data['action'])) $parts[] = 'Action: ' . $data['action'];
            if(isset($data['code'])) $parts[] = 'Code: ' . $data['code'];
            return implode("\n", $parts);
        }
        return null;
    }

    if($slot === 'cookie')
    {
        if(is_array($data))
        {
            $parts = array('Cookie');
            $parts[] = 'Name: ' . (isset($data['name']) ? $data['name'] : 'N/A');
            $parts[] = 'Value: ' . (isset($data['value']) ? $data['value'] : 'N/A');
            if(isset($data['action'])) $parts[] = 'Action: ' . $data['action'];
            if(isset($data['expires'])) $parts[] = 'Expires: ' . $data['expires'];
            if(isset($data['path'])) $parts[] = 'Path: ' . $data['path'];
            if(isset($data['domain'])) $parts[] = 'Domain: ' . $data['domain'];
            if(isset($data['secure'])) $parts[] = 'Secure: ' . ($data['secure'] ? 'yes' : 'no');
            if(isset($data['httponly'])) $parts[] = 'HttpOnly: ' . ($data['httponly'] ? 'yes' : 'no');
            return implode("\n", $parts);
        }
        return null;
    }

    if($slot === 'env')
    {
        return debugbar_format_array_debug($data);
    }

    if($slot === 'server')
    {
        return debugbar_format_array_debug($data);
    }

    // ========== PERFORMANCE SLOTS ==========
    if($slot === 'memory')
    {
        if(is_array($data))
        {
            $parts = array('Memory Usage');
            if(isset($data['usage'])) $parts[] = 'Usage: ' . debugbar_format_profile_memory($data['usage']);
            if(isset($data['peak'])) $parts[] = 'Peak: ' . debugbar_format_profile_memory($data['peak']);
            if(isset($data['limit'])) $parts[] = 'Limit: ' . $data['limit'];
            if(isset($data['delta'])) $parts[] = 'Delta: ' . debugbar_format_profile_memory($data['delta']);
            return implode("\n", $parts);
        }
        if(is_numeric($data))
        {
            return 'Memory: ' . debugbar_format_profile_memory($data) . ' (Peak: ' . debugbar_format_profile_memory(memory_get_peak_usage(true)) . ')';
        }
        return null;
    }

    if($slot === 'trace')
    {
        if(is_array($data))
        {
            $parts = array('Backtrace');
            foreach($data as $i => $frame)
            {
                $f = isset($frame['file']) ? $frame['file'] : '[internal]';
                $l = isset($frame['line']) ? $frame['line'] : 0;
                $fn = isset($frame['function']) ? $frame['function'] : '';
                $c = isset($frame['class']) ? $frame['class'] : '';
                $parts[] = '#' . $i . ' ' . $f . '(' . $l . '): ' . ($c ? $c . '->' : '') . $fn;
            }
            return implode("\n", $parts);
        }
        return null;
    }

    if($slot === 'counter')
    {
        if(is_array($data))
        {
            $parts = array('Counter');
            $parts[] = 'Name: ' . (isset($data['name']) ? $data['name'] : 'N/A');
            $parts[] = 'Value: ' . (isset($data['value']) ? $data['value'] : '0');
            if(isset($data['delta'])) $parts[] = 'Delta: ' . ($data['delta'] >= 0 ? '+' : '') . $data['delta'];
            if(isset($data['total'])) $parts[] = 'Total: ' . $data['total'];
            return implode("\n", $parts);
        }
        if(is_numeric($data)) return 'Count: ' . $data;
        return null;
    }

    return null;
}

/**
 * Format an array for debug display.
 *
 * @param mixed $data The data to format.
 * @return string Formatted string.
 */
function debugbar_format_array_debug($data)
{
    if(!is_array($data))
    {
        return print_r($data, true);
    }

    $parts = array();
    foreach($data as $k => $v)
    {
        if(is_scalar($v) || is_null($v))
        {
            if(is_null($v)) $v = 'null';
            elseif(is_bool($v)) $v = $v ? 'true' : 'false';
            $parts[] = '[' . $k . '] => ' . $v;
        }
        else
        {
            $parts[] = '[' . $k . '] => (' . gettype($v) . ')';
        }
    }
    return implode("\n", $parts);
}

/**
 * Format a memory value for profile display.
 *
 * @param int $bytes Memory in bytes.
 * @return string Formatted string.
 */
function debugbar_format_profile_memory($bytes)
{
    if($bytes === 0) return '0 B';
    $units = array('B', 'KB', 'MB', 'GB');
    $exp = min(floor(log(abs($bytes), 1024)), count($units) - 1);
    $value = $bytes / pow(1024, $exp);
    return number_format($value, 2, '.', '') . ' ' . $units[$exp];
}

/**
 * Get a human-readable label for a PHP error type constant.
 *
 * Maps PHP error level constants (E_ERROR, E_WARNING, E_NOTICE, etc.)
 * to their string representations.
 *
 * @param int $errno The PHP error level integer.
 * @return string    Human-readable error type label.
 */
function debugbar_get_error_type_label($errno)
{
    $types = array(
        E_ERROR             => 'Fatal Error',
        E_WARNING           => 'Warning',
        E_PARSE             => 'Parse Error',
        E_NOTICE            => 'Notice',
        E_CORE_ERROR        => 'Core Error',
        E_CORE_WARNING      => 'Core Warning',
        E_COMPILE_ERROR     => 'Compile Error',
        E_COMPILE_WARNING   => 'Compile Warning',
        E_USER_ERROR        => 'User Error',
        E_USER_WARNING      => 'User Warning',
        E_USER_NOTICE       => 'User Notice',
        E_STRICT            => 'Strict Standards',
        E_RECOVERABLE_ERROR => 'Recoverable Error',
        E_DEPRECATED        => 'Deprecated',
        E_USER_DEPRECATED   => 'User Deprecated',
    );

    return isset($types[$errno]) ? $types[$errno] : 'Unknown Error';
}

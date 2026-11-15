/**
 * MyBB Debug Bar - Client-side JavaScript
 *
 * Handles panel toggling, tab switching, keyboard shortcuts,
 * click-outside-to-close, console.log interception, event
 * auto-capture, DOM inspection, AJAX interception, and
 * JS debugging via debugbarDebug().
 */

var debugbar_is_open = false;
var debugbar_dragging = false;
var debugbar_drag_offset_x = 0;
var debugbar_drag_offset_y = 0;
var debugbar_drag_start_x = 0;
var debugbar_drag_start_y = 0;
var debugbar_console_queue = [];
var debugbar_debug_queue = [];
var debugbar_event_enabled = false;
var debugbar_ajax_enabled = false;
var debugbar_js_error_enabled = false;

/**
 * Toggle the debug bar panel open or closed.
 */
function debugbarToggle()
{
    var panel = document.getElementById('debugbar-panel');
    if(!panel) return;

    if(debugbar_is_open)
    {
        panel.className = 'debugbar-closed';
        debugbar_is_open = false;
    }
    else
    {
        var toggle = document.getElementById('debugbar-toggle');
        if(toggle && toggle.style.left && toggle.style.top)
        {
            var tLeft = parseInt(toggle.style.left, 10);
            var tTop = parseInt(toggle.style.top, 10);
            var tW = toggle.offsetWidth || 48;
            var tH = toggle.offsetHeight || 48;
            debugbarPositionPanel(tLeft, tTop, tW, tH);
        }
        panel.className = 'debugbar-open';
        debugbar_is_open = true;
    }
}

/**
 * Auto-open the debug bar panel if PHP errors were detected.
 */
function debugbarAutoOpen()
{
    var toggle = document.getElementById('debugbar-toggle');
    if(!toggle) return;

    var errorCount = parseInt(toggle.getAttribute('data-errors'), 10);
    if(errorCount > 0)
    {
        debugbarToggle();
    }
}

/**
 * Switch to a specific tab in the debug bar panel.
 *
 * @param {string} tabName - The tab identifier ('errors', 'debug', 'console', 'info').
 */
function debugbarSwitchTab(tabName)
{
    var tabs = document.querySelectorAll('.debugbar-tab');
    for(var i = 0; i < tabs.length; i++)
    {
        if(tabs[i].getAttribute('data-tab') === tabName)
        {
            tabs[i].className = 'debugbar-tab debugbar-tab-active';
        }
        else
        {
            tabs[i].className = 'debugbar-tab';
        }
    }

    var panes = document.querySelectorAll('.debugbar-pane');
    for(var i = 0; i < panes.length; i++)
    {
        if(panes[i].id === 'debugbar-pane-' + tabName)
        {
            panes[i].className = 'debugbar-pane debugbar-pane-active';
        }
        else
        {
            panes[i].className = 'debugbar-pane';
        }
    }
}

/**
 * Handle keyboard shortcuts.
 *
 * @param {KeyboardEvent} e
 */
function debugbarKeydown(e)
{
    if(e.key === 'Escape' && debugbar_is_open)
    {
        debugbarToggle();
    }
}

/**
 * Handle click outside the debug bar to close it.
 *
 * @param {MouseEvent} e
 */
function debugbarClickOutside(e)
{
    if(!debugbar_is_open) return;

    var panel = document.getElementById('debugbar-panel');
    var toggle = document.getElementById('debugbar-toggle');

    if(panel && toggle)
    {
        if(!panel.contains(e.target) && !toggle.contains(e.target))
        {
            debugbarToggle();
        }
    }
}

// ============================================================
// JS DEBUG API - Client-side debugbarDebug() function
// Mirrors the PHP debugbar_debug() API for client-side use.
// ============================================================

/**
 * JS equivalent of the PHP debugbar_debug() function.
 *
 * Pushes a debug entry to the Debug tab from client-side code.
 * Supports all the same slot types as the PHP version.
 *
 * @param {string} key   - Label/identifier for the debug entry.
 * @param {*}      data  - The data to debug.
 * @param {string} slot  - Slot type (default: 'custom').
 * @return {void}
 */
function debugbarDebug(key, data, slot)
{
    if(typeof slot === 'undefined') slot = 'custom';

    var entry = {
        key: key,
        slot: slot,
        data: typeof data === 'object' ? debugbarFormatData(data, slot) : String(data),
        file: 'client-side',
        line: 0,
        time: Date.now()
    };

    var debugPane = document.getElementById('debugbar-pane-debug');
    if(!debugPane)
    {
        debugbar_debug_queue.push(entry);
        return;
    }

    debugbarAddDebugEntry(entry);
}

/**
 * Format data for a specific slot type (JS version).
 *
 * @param {*}      data - The data to format.
 * @param {string} slot - The slot type.
 * @return {string} Formatted string.
 */
function debugbarFormatData(data, slot)
{
    if(data === null) return 'null';
    if(data === undefined) return 'undefined';

    switch(slot)
    {
        case 'variable':
            if(typeof data === 'boolean') return data ? 'true' : 'false';
            return String(data);

        case 'array':
        case 'config':
        case 'env':
        case 'server':
        case 'session':
        case 'request':
            if(typeof data === 'object')
            {
                var parts = [];
                for(var k in data)
                {
                    if(data.hasOwnProperty(k))
                    {
                        var v = data[k];
                        if(typeof v === 'object') v = '(' + (v.constructor ? v.constructor.name : 'Object') + ')';
                        else if(typeof v === 'function') v = '(function)';
                        else v = String(v);
                        parts.push('[' + k + '] => ' + v);
                    }
                }
                return parts.join('\n');
            }
            return String(data);

        case 'object':
            if(typeof data === 'object')
            {
                var name = data.constructor ? data.constructor.name : 'Object';
                return 'Class: ' + name + '\n' + JSON.stringify(data, null, 2);
            }
            return String(data);

        case 'click':
        case 'dblclick':
        case 'mouse':
        case 'mousemove':
        case 'hover':
            if(typeof data === 'object')
            {
                var parts = ['Event: ' + (data.type || slot)];
                parts.push('Target: ' + debugbarElDesc(data.target));
                parts.push('X: ' + (data.clientX !== undefined ? data.clientX : 'N/A'));
                parts.push('Y: ' + (data.clientY !== undefined ? data.clientY : 'N/A'));
                if(data.button !== undefined) parts.push('Button: ' + data.button);
                if(data.buttons !== undefined) parts.push('Buttons: ' + data.buttons);
                if(data.movementX !== undefined) parts.push('MovX: ' + data.movementX);
                if(data.movementY !== undefined) parts.push('MovY: ' + data.movementY);
                if(data.altKey !== undefined) parts.push('Alt: ' + (data.altKey ? 'yes' : 'no'));
                if(data.ctrlKey !== undefined) parts.push('Ctrl: ' + (data.ctrlKey ? 'yes' : 'no'));
                if(data.shiftKey !== undefined) parts.push('Shift: ' + (data.shiftKey ? 'yes' : 'no'));
                return parts.join('\n');
            }
            return String(data);

        case 'touch':
            if(typeof data === 'object')
            {
                var parts = ['Event: ' + (data.type || 'touch')];
                parts.push('Target: ' + debugbarElDesc(data.target));
                var touches = data.touches || data.changedTouches || [];
                parts.push('Touches: ' + touches.length);
                if(touches.length > 0)
                {
                    var t = touches[0];
                    parts.push('X: ' + (t.clientX || 'N/A'));
                    parts.push('Y: ' + (t.clientY || 'N/A'));
                    if(t.force !== undefined) parts.push('Force: ' + t.force.toFixed(2));
                    if(t.radiusX !== undefined) parts.push('RadiusX: ' + t.radiusX.toFixed(1));
                    if(t.radiusY !== undefined) parts.push('RadiusY: ' + t.radiusY.toFixed(1));
                }
                return parts.join('\n');
            }
            return String(data);

        case 'scroll':
            if(typeof data === 'object')
            {
                var parts = ['Scroll Position'];
                parts.push('ScrollX: ' + (data.scrollX || window.scrollX || 0));
                parts.push('ScrollY: ' + (data.scrollY || window.scrollY || 0));
                parts.push('Target: ' + debugbarElDesc(data.target));
                return parts.join('\n');
            }
            return String(data);

        case 'resize':
            var w = window.innerWidth;
            var h = window.innerHeight;
            return 'Window Resize\nWidth: ' + w + '\nHeight: ' + h + '\nOuterW: ' + window.outerWidth + '\nOuterH: ' + window.outerHeight;

        case 'focus':
            if(typeof data === 'object')
            {
                var parts = ['Focus Event'];
                parts.push('Type: ' + (data.type || 'N/A'));
                parts.push('Target: ' + debugbarElDesc(data.target));
                parts.push('Tag: ' + (data.target ? data.target.tagName || 'N/A' : 'N/A'));
                if(data.target && data.target.id) parts.push('ID: ' + data.target.id);
                return parts.join('\n');
            }
            return String(data);

        case 'keyboard':
            if(typeof data === 'object')
            {
                var parts = ['Keyboard Event'];
                parts.push('Type: ' + (data.type || 'N/A'));
                parts.push('Key: ' + (data.key || 'N/A'));
                parts.push('Code: ' + (data.code || 'N/A'));
                parts.push('Alt: ' + (data.altKey ? 'yes' : 'no'));
                parts.push('Ctrl: ' + (data.ctrlKey ? 'yes' : 'no'));
                parts.push('Shift: ' + (data.shiftKey ? 'yes' : 'no'));
                parts.push('Meta: ' + (data.metaKey ? 'yes' : 'no'));
                parts.push('Repeat: ' + (data.repeat ? 'yes' : 'no'));
                parts.push('Location: ' + (data.location !== undefined ? data.location : 'N/A'));
                return parts.join('\n');
            }
            return String(data);

        case 'form':
            if(typeof data === 'object' && data.target)
            {
                var el = data.target;
                var parts = ['Form Event'];
                parts.push('Type: ' + (data.type || 'N/A'));
                parts.push('Target: ' + debugbarElDesc(el));
                if(el.action) parts.push('Action: ' + el.action);
                if(el.method) parts.push('Method: ' + el.method.toUpperCase());
                if(el.elements)
                {
                    parts.push('Fields:');
                    for(var i = 0; i < el.elements.length; i++)
                    {
                        var f = el.elements[i];
                        if(f.name)
                        {
                            parts.push('  ' + f.name + ' = ' + (f.value || ''));
                        }
                    }
                }
                return parts.join('\n');
            }
            return String(data);

        case 'input':
            if(typeof data === 'object')
            {
                var parts = ['Input Event'];
                parts.push('Target: ' + debugbarElDesc(data.target));
                parts.push('Value: ' + (data.target ? data.target.value || '' : 'N/A'));
                parts.push('InputType: ' + (data.inputType || 'N/A'));
                if(data.target && data.target.selectionStart !== null)
                {
                    parts.push('SelStart: ' + data.target.selectionStart);
                    parts.push('SelEnd: ' + data.target.selectionEnd);
                }
                return parts.join('\n');
            }
            return String(data);

        case 'drag':
            if(typeof data === 'object')
            {
                var parts = ['Drag Event'];
                parts.push('Type: ' + (data.type || 'N/A'));
                parts.push('Source: ' + debugbarElDesc(data.target));
                parts.push('X: ' + (data.clientX || 'N/A'));
                parts.push('Y: ' + (data.clientY || 'N/A'));
                return parts.join('\n');
            }
            return String(data);

        case 'animation':
        case 'transition':
            if(typeof data === 'object')
            {
                var label = slot === 'animation' ? 'Animation' : 'Transition';
                var parts = [label + ' Event'];
                parts.push('Type: ' + (data.type || 'N/A'));
                parts.push('Name: ' + (data.animationName || data.propertyName || 'N/A'));
                parts.push('Elapsed: ' + (data.elapsedTime !== undefined ? data.elapsedTime + 's' : 'N/A'));
                parts.push('Target: ' + debugbarElDesc(data.target));
                return parts.join('\n');
            }
            return String(data);

        case 'ajax':
        case 'fetch':
        case 'api':
            if(typeof data === 'object')
            {
                var label = slot === 'ajax' ? 'AJAX' : (slot === 'fetch' ? 'Fetch' : 'API');
                var parts = [label + ' Request'];
                parts.push('URL: ' + (data.url || data.responseURL || 'N/A'));
                parts.push('Method: ' + (data.method || 'GET'));
                if(data.status) parts.push('Status: ' + data.status);
                if(data.duration) parts.push('Duration: ' + data.duration + 'ms');
                if(data.responseText)
                {
                    var rt = data.responseText;
                    parts.push('Response: ' + (rt.length > 500 ? rt.substring(0, 500) + '... [truncated]' : rt));
                }
                return parts.join('\n');
            }
            return String(data);

        case 'js_error':
            if(typeof data === 'object')
            {
                var parts = ['JavaScript Error'];
                parts.push('Message: ' + (data.message || 'N/A'));
                if(data.stack) parts.push('Stack: ' + data.stack);
                if(data.line) parts.push('Line: ' + data.line);
                if(data.col) parts.push('Col: ' + data.col);
                if(data.file) parts.push('File: ' + data.file);
                return parts.join('\n');
            }
            return String(data);

        case 'js_log':
            return typeof data === 'string' ? data : JSON.stringify(data, null, 2);

        case 'timer':
            if(typeof data === 'object')
            {
                var parts = ['Timer Event'];
                parts.push('Type: ' + (data.type || 'N/A'));
                parts.push('ID: ' + (data.id || 'N/A'));
                if(data.delay) parts.push('Delay: ' + data.delay + 'ms');
                return parts.join('\n');
            }
            return String(data);

        case 'promise':
            if(typeof data === 'object')
            {
                var parts = ['Promise'];
                parts.push('Status: ' + (data.status || 'N/A'));
                if(data.reason) parts.push('Reason: ' + (typeof data.reason === 'string' ? data.reason : JSON.stringify(data.reason)));
                if(data.value) parts.push('Value: ' + (typeof data.value === 'string' ? data.value : JSON.stringify(data.value)));
                return parts.join('\n');
            }
            return String(data);

        case 'storage':
            if(typeof data === 'object')
            {
                var parts = ['Web Storage'];
                parts.push('Source: ' + (data.source || 'N/A'));
                parts.push('Action: ' + (data.action || 'N/A'));
                if(data.key) parts.push('Key: ' + data.key);
                if(data.newValue) parts.push('Value: ' + (data.newValue.length > 200 ? data.newValue.substring(0, 200) + '...' : data.newValue));
                return parts.join('\n');
            }
            return String(data);

        case 'style':
            return debugbarFormatStyle(data);

        case 'layout':
            return debugbarFormatLayout(data);

        case 'dom':
            if(typeof data === 'object')
            {
                var el = data.target || data;
                var parts = ['DOM Node'];
                parts.push('Tag: ' + (el.tagName || 'N/A'));
                if(el.id) parts.push('ID: ' + el.id);
                if(el.className && typeof el.className === 'string') parts.push('Class: ' + el.className);
                if(el.children) parts.push('Children: ' + el.children.length);
                return parts.join('\n');
            }
            return String(data);

        case 'network':
            var nt = data || (navigator.connection || {});
            return 'Network Info\nOnline: ' + (navigator.onLine ? 'yes' : 'no')
                + '\nType: ' + (nt.effectiveType || 'N/A')
                + '\nDownlink: ' + (nt.downlink || 'N/A') + ' Mbps'
                + '\nRTT: ' + (nt.rtt || 'N/A') + ' ms';

        case 'memory':
            if(typeof data === 'object' && data.usage)
            {
                return 'Memory Usage\nUsage: ' + debugbarFormatBytes(data.usage)
                    + '\nPeak: ' + (data.peak ? debugbarFormatBytes(data.peak) : 'N/A')
                    + '\nLimit: ' + (data.limit || 'N/A');
            }
            if(typeof performance !== 'undefined' && performance.memory)
            {
                var mem = performance.memory;
                return 'Memory Usage\nUsage: ' + debugbarFormatBytes(mem.usedJSHeapSize)
                    + '\nPeak: ' + debugbarFormatBytes(mem.jsHeapSizeLimit)
                    + '\nTotal: ' + debugbarFormatBytes(mem.totalJSHeapSize);
            }
            return String(data);

        case 'counter':
            if(typeof data === 'object')
            {
                return 'Counter\nName: ' + (data.name || 'N/A')
                    + '\nValue: ' + (data.value || 0)
                    + '\nDelta: ' + (data.delta || 0);
            }
            return 'Count: ' + data;

        default:
            if(typeof data === 'object')
            {
                try { return JSON.stringify(data, null, 2); }
                catch(e) { return String(data); }
            }
            return String(data);
    }
}

/**
 * Format element description for debug output.
 *
 * @param {Element|null} el - The DOM element.
 * @return {string} Description string.
 */
function debugbarElDesc(el)
{
    if(!el) return 'null';
    if(el === document) return 'document';
    if(el === window) return 'window';
    var desc = el.tagName ? el.tagName.toLowerCase() : 'unknown';
    if(el.id) desc += '#' + el.id;
    if(el.className && typeof el.className === 'string' && el.className.trim())
    {
        desc += '.' + el.className.trim().split(/\s+/).join('.');
    }
    return desc;
}

/**
 * Format style data.
 *
 * @param {*} data - The element or style data.
 * @return {string} Formatted string.
 */
function debugbarFormatStyle(data)
{
    var el = data && data.target ? data.target : data;
    if(el && el.tagName)
    {
        var cs = window.getComputedStyle(el);
        var parts = ['Computed Styles for ' + debugbarElDesc(el)];
        var props = ['display', 'position', 'width', 'height', 'margin', 'padding',
                     'color', 'background', 'font-size', 'font-family', 'font-weight',
                     'border', 'opacity', 'visibility', 'overflow', 'z-index',
                     'transform', 'transition', 'animation', 'flex', 'grid'];
        for(var i = 0; i < props.length; i++)
        {
            if(cs[props[i]]) parts.push('  ' + props[i] + ': ' + cs[props[i]]);
        }
        return parts.join('\n');
    }
    if(typeof data === 'object' && data.styles)
    {
        var parts = ['Styles for ' + (data.element || 'N/A')];
        for(var k in data.styles)
        {
            parts.push('  ' + k + ': ' + data.styles[k]);
        }
        return parts.join('\n');
    }
    return String(data);
}

/**
 * Format layout data.
 *
 * @param {*} data - The element or layout data.
 * @return {string} Formatted string.
 */
function debugbarFormatLayout(data)
{
    var el = data && data.target ? data.target : data;
    if(el && el.getBoundingClientRect)
    {
        var r = el.getBoundingClientRect();
        return 'Element Layout for ' + debugbarElDesc(el)
            + '\nWidth: ' + Math.round(r.width) + 'px'
            + '\nHeight: ' + Math.round(r.height) + 'px'
            + '\nTop: ' + Math.round(r.top) + 'px'
            + '\nLeft: ' + Math.round(r.left) + 'px'
            + '\nBottom: ' + Math.round(r.bottom) + 'px'
            + '\nRight: ' + Math.round(r.right) + 'px'
            + '\nVisible: ' + (r.width > 0 && r.height > 0 ? 'yes' : 'no');
    }
    if(typeof data === 'object' && data.element)
    {
        var parts = ['Element Layout'];
        parts.push('Element: ' + data.element);
        if(data.width) parts.push('Width: ' + data.width + 'px');
        if(data.height) parts.push('Height: ' + data.height + 'px');
        if(data.top) parts.push('Top: ' + data.top + 'px');
        if(data.left) parts.push('Left: ' + data.left + 'px');
        return parts.join('\n');
    }
    return String(data);
}

/**
 * Format bytes to human-readable string.
 *
 * @param {number} bytes
 * @return {string}
 */
function debugbarFormatBytes(bytes)
{
    if(bytes === 0) return '0 B';
    var units = ['B', 'KB', 'MB', 'GB'];
    var exp = Math.min(Math.floor(Math.log(Math.abs(bytes)) / Math.log(1024)), units.length - 1);
    return (bytes / Math.pow(1024, exp)).toFixed(2) + ' ' + units[exp];
}

/**
 * Add a debug entry to the Debug tab pane.
 *
 * @param {Object} entry - The debug entry {key, slot, data, file, line}.
 */
function debugbarAddDebugEntry(entry)
{
    var debugPane = document.getElementById('debugbar-pane-debug');
    if(!debugPane) return;

    var section = debugPane.querySelector('.debugbar-section');
    if(!section)
    {
        var count = 1;
        debugPane.innerHTML = '<div class="debugbar-section">'
            + '<h3 class="debugbar-section-title debugbar-section-title-debug">Debug Output (<span class="debugbar-debug-count-js">1</span>)</h3>'
            + '<div class="debugbar-section-body" id="debugbar-debug-body-js">'
            + '</div></div>';
    }
    else
    {
        var countEl = debugPane.querySelector('.debugbar-debug-count-js');
        if(countEl)
        {
            var cur = parseInt(countEl.textContent, 10) || 0;
            countEl.textContent = cur + 1;
        }
    }

    var body = document.getElementById('debugbar-debug-body-js');
    if(!body)
    {
        body = debugPane.querySelector('.debugbar-section-body');
        if(!body) return;
        body.id = 'debugbar-debug-body-js';
    }

    var slotLabels = {
        click: 'Click', dblclick: 'DblClick', hover: 'Hover',
        mousemove: 'MouseMove', mouse: 'Mouse', touch: 'Touch',
        scroll: 'Scroll', resize: 'Resize', focus: 'Focus',
        keyboard: 'Keyboard', form: 'Form', drag: 'Drag',
        animation: 'Animation', transition: 'Transition', input: 'Input',
        style: 'Style', html: 'HTML', dom: 'DOM', selector: 'Selector',
        layout: 'Layout', attr: 'Attr', class: 'Class', mutation: 'Mutation',
        ajax: 'AJAX', fetch: 'Fetch', network: 'Network', api: 'API',
        websocket: 'WebSocket',
        js_error: 'JSError', js_log: 'JSLog', timer: 'Timer',
        promise: 'Promise', storage: 'Storage', console: 'Console', worker: 'Worker',
        file: 'File', include: 'Include', method: 'Method', filter: 'Filter',
        cache: 'Cache', redirect: 'Redirect', email: 'Email',
        permission: 'Permission', upload: 'Upload', image: 'Image',
        locale: 'Locale', date: 'Date', math: 'Math',
        config: 'Config', constant: 'Constant', header: 'Header',
        cookie: 'Cookie', env: 'Env', server: 'Server',
        memory: 'Memory', query: 'Query', profile: 'Profile',
        trace: 'Trace', counter: 'Counter',
        variable: 'Variable', array: 'Array', object: 'Object',
        sql: 'SQL', hook: 'Hook', template: 'Template',
        request: 'Request', session: 'Session', benchmark: 'Benchmark',
        custom: 'Custom'
    };

    var item = document.createElement('div');
    item.className = 'debugbar-debug-item debugbar-debug-' + entry.slot;
    var slotLabel = slotLabels[entry.slot] || 'Custom';
    var dataStr = typeof entry.data === 'string' ? entry.data : JSON.stringify(entry.data, null, 2);

    item.innerHTML = '<div class="debugbar-debug-header">'
        + '<span class="debugbar-debug-key">' + debugbarEscapeHtml(entry.key) + '</span>'
        + '<span class="debugbar-debug-slot">' + slotLabel + '</span>'
        + '<span class="debugbar-debug-location">client-side</span>'
        + '</div>'
        + '<div class="debugbar-debug-data">' + debugbarEscapeHtml(dataStr) + '</div>';

    body.insertBefore(item, body.firstChild);

    debugbarUpdateDebugCount(1);
}

/**
 * Update the debug tab badge count.
 *
 * @param {number} increment
 */
function debugbarUpdateDebugCount(increment)
{
    var tabs = document.querySelectorAll('.debugbar-tab');
    for(var i = 0; i < tabs.length; i++)
    {
        if(tabs[i].getAttribute('data-tab') === 'debug')
        {
            var match = tabs[i].textContent.match(/^Debug\s*\(?(\d*)\)?$/);
            if(match)
            {
                var current = parseInt(match[1], 10) || 0;
                var newCount = current + increment;
                tabs[i].textContent = newCount > 0 ? 'Debug (' + newCount + ')' : 'Debug';
            }
            else
            {
                tabs[i].textContent = 'Debug (' + increment + ')';
            }
            break;
        }
    }
}

// ============================================================
// EVENT AUTO-INTERCEPTION
// ============================================================

/**
 * Enable auto-capture of DOM events.
 *
 * Intercepts: click, dblclick, mousemove, mouseenter, mouseleave,
 * touchstart, touchend, scroll, resize, focus, blur, keydown, keyup,
 * submit, change, input, dragstart, dragend, drop, animationstart,
 * transitionstart
 */
function debugbarEnableEvents()
{
    if(debugbar_event_enabled) return;
    debugbar_event_enabled = true;

    var eventTypes = [
        'click', 'dblclick', 'mousemove',
        'touchstart', 'touchend',
        'scroll', 'focus', 'blur', 'keydown', 'keyup',
        'submit', 'change', 'input',
        'dragstart', 'dragend', 'drop',
        'animationstart', 'transitionstart'
    ];

    // Throttle mousemove to every 500ms
    var lastMouseMove = 0;

    for(var i = 0; i < eventTypes.length; i++)
    {
        (function(etype) {
            document.addEventListener(etype, function(e) {
                // Throttle mousemove
                if(etype === 'mousemove')
                {
                    var now = Date.now();
                    if(now - lastMouseMove < 500) return;
                    lastMouseMove = now;
                }

                // Map to slot
                var slotMap = {
                    click: 'click', dblclick: 'dblclick',
                    mousemove: 'mousemove',
                    mouseenter: 'hover', mouseleave: 'hover',
                    touchstart: 'touch', touchend: 'touch',
                    scroll: 'scroll', focus: 'focus', blur: 'focus',
                    keydown: 'keyboard', keyup: 'keyboard',
                    submit: 'form', change: 'form', input: 'input',
                    dragstart: 'drag', dragend: 'drag', drop: 'drag',
                    animationstart: 'animation',
                    transitionstart: 'transition'
                };
                var slot = slotMap[etype] || 'custom';
                debugbarDebug('[' + etype + '] ' + debugbarElDesc(e.target), e, slot);
            }, true);
        })(eventTypes[i]);
    }

    // Resize on window
    window.addEventListener('resize', function() {
        debugbarDebug('window resize', {width: window.innerWidth, height: window.innerHeight}, 'resize');
    });

    debugbarDebug('Event Capture', 'DOM event auto-capture started', 'js_log');
}

// ============================================================
// DOM INSPECTION HELPERS
// ============================================================

/**
 * Inspect a DOM element: shows its tag, id, classes, attributes,
 * computed styles, and layout.
 *
 * @param {string|Element} selector - CSS selector or DOM element.
 */
function debugbarInspectElement(selector)
{
    var el = typeof selector === 'string' ? document.querySelector(selector) : selector;
    if(!el)
    {
        debugbarDebug('Inspect Element', 'Element not found: ' + selector, 'js_error');
        return;
    }

    // Tag info
    debugbarDebug('DOM: ' + debugbarElDesc(el), {
        tag: el.tagName,
        id: el.id,
        class: el.className,
        children: el.children ? el.children.length : 0
    }, 'dom');

    // Layout
    debugbarDebug('Layout: ' + debugbarElDesc(el), el, 'layout');

    // Styles
    debugbarDebug('Styles: ' + debugbarElDesc(el), el, 'style');
}

/**
 * Get computed styles of an element.
 *
 * @param {string|Element} selector - CSS selector or DOM element.
 */
function debugbarGetStyles(selector)
{
    var el = typeof selector === 'string' ? document.querySelector(selector) : selector;
    if(!el)
    {
        debugbarDebug('Get Styles', 'Element not found: ' + selector, 'js_error');
        return;
    }
    debugbarDebug('Styles: ' + debugbarElDesc(el), el, 'style');
}

/**
 * Get layout measurements of an element.
 *
 * @param {string|Element} selector - CSS selector or DOM element.
 */
function debugbarGetLayout(selector)
{
    var el = typeof selector === 'string' ? document.querySelector(selector) : selector;
    if(!el)
    {
        debugbarDebug('Get Layout', 'Element not found: ' + selector, 'js_error');
        return;
    }
    debugbarDebug('Layout: ' + debugbarElDesc(el), el, 'layout');
}

/**
 * Get HTML content of an element.
 *
 * @param {string|Element} selector - CSS selector or DOM element.
 */
function debugbarGetHTML(selector)
{
    var el = typeof selector === 'string' ? document.querySelector(selector) : selector;
    if(!el)
    {
        debugbarDebug('Get HTML', 'Element not found: ' + selector, 'js_error');
        return;
    }
    var html = el.outerHTML || el.innerHTML || '';
    debugbarDebug('HTML: ' + debugbarElDesc(el), html, 'html');
}

/**
 * Query DOM and show results.
 *
 * @param {string} selector - CSS selector.
 */
function debugbarQueryDOM(selector)
{
    if(!selector || typeof selector !== 'string')
    {
        debugbarDebug('Query DOM', 'Invalid selector: ' + selector, 'js_error');
        return;
    }
    var results = document.querySelectorAll(selector);
    var elements = [];
    for(var i = 0; i < results.length; i++)
    {
        elements.push(debugbarElDesc(results[i]));
    }
    debugbarDebug('Query: ' + selector, {
        selector: selector,
        count: results.length,
        elements: elements
    }, 'selector');
}

// ============================================================
// AJAX / FETCH INTERCEPTION
// ============================================================

/**
 * Enable interception of AJAX (XMLHttpRequest) and Fetch requests.
 */
function debugbarEnableAjaxIntercept()
{
    if(debugbar_ajax_enabled) return;
    debugbar_ajax_enabled = true;

    // Intercept XMLHttpRequest
    var origOpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function(method, url, async, user, pass)
    {
        this._debugMethod = method;
        this._debugUrl = url;
        this._debugStart = Date.now();
        this.addEventListener('load', function() {
            debugbarDebug('[AJAX] ' + method + ' ' + url, {
                url: url,
                method: method,
                status: this.status,
                statusText: this.statusText,
                duration: Date.now() - this._debugStart,
                responseText: this.responseText || ''
            }, 'ajax');
        });
        this.addEventListener('error', function() {
            debugbarDebug('[AJAX Error] ' + method + ' ' + url, {
                url: url,
                method: method,
                status: this.status,
                duration: Date.now() - this._debugStart
            }, 'ajax');
        });
        return origOpen.apply(this, arguments);
    };

    // Intercept fetch
    if(typeof window.fetch === 'function')
    {
        var origFetch = window.fetch;
        window.fetch = function(input, init)
        {
            var url = typeof input === 'string' ? input : (input.url || '');
            var method = (init && init.method) || 'GET';
            var startTime = Date.now();

            return origFetch.apply(this, arguments).then(function(response) {
                var ct = response.headers.get('content-type') || '';
                response.clone().text().then(function(body) {
                    debugbarDebug('[Fetch] ' + method + ' ' + url, {
                        url: url,
                        method: method,
                        status: response.status,
                        duration: Date.now() - startTime,
                        responseText: ct.indexOf('json') >= 0 ? body.substring(0, 500) : body.substring(0, 200)
                    }, 'fetch');
                }).catch(function() {});
                return response;
            }).catch(function(err) {
                debugbarDebug('[Fetch Error] ' + method + ' ' + url, {
                    url: url,
                    method: method,
                    error: err.message,
                    duration: Date.now() - startTime
                }, 'fetch');
                throw err;
            });
        };
    }

    debugbarDebug('AJAX Intercept', 'AJAX/fetch capture started', 'js_log');
}

// ============================================================
// JS ERROR INTERCEPTION
// ============================================================

/**
 * Enable interception of JavaScript errors and unhandled promise rejections.
 */
function debugbarEnableJsErrorIntercept()
{
    if(debugbar_js_error_enabled) return;
    debugbar_js_error_enabled = true;

    // Global error handler
    window.addEventListener('error', function(e) {
        debugbarDebug('[JS Error] ' + (e.message || 'Unknown'), {
            message: e.message,
            file: e.filename,
            line: e.lineno,
            col: e.colno,
            stack: e.error && e.error.stack ? e.error.stack : 'N/A'
        }, 'js_error');
    });

    // Unhandled promise rejection
    window.addEventListener('unhandledrejection', function(e) {
        var reason = e.reason;
        debugbarDebug('[Promise Rejection]', {
            status: 'rejected',
            reason: reason && reason.message ? reason.message : (typeof reason === 'string' ? reason : JSON.stringify(reason)),
            stack: reason && reason.stack ? reason.stack : 'N/A'
        }, 'promise');
    });

    debugbarDebug('JSError Capture', 'JS error capture started', 'js_log');
}

// ============================================================
// TIMER INTERCEPTION
// ============================================================

/**
 * Enable interception of setTimeout/setInterval.
 */
function debugbarEnableTimerIntercept()
{
    var origSetTimeout = window.setTimeout;
    window.setTimeout = function(fn, delay) {
        var id = origSetTimeout(fn, delay);
        debugbarDebug('[Timer] setTimeout(' + delay + 'ms)', {
            type: 'setTimeout',
            id: id,
            delay: delay
        }, 'timer');
        return id;
    };

    var origSetInterval = window.setInterval;
    window.setInterval = function(fn, interval) {
        var id = origSetInterval(fn, interval);
        debugbarDebug('[Timer] setInterval(' + interval + 'ms)', {
            type: 'setInterval',
            id: id,
            delay: interval
        }, 'timer');
        return id;
    };

    debugbarDebug('Timer Capture', 'setTimeout/setInterval capture started', 'js_log');
}

// ============================================================
// STORAGE INTERCEPTION
// ============================================================

/**
 * Enable interception of localStorage/sessionStorage changes.
 */
function debugbarEnableStorageIntercept()
{
    window.addEventListener('storage', function(e) {
        debugbarDebug('[Storage] ' + e.key, {
            source: e.storageArea === localStorage ? 'localStorage' : 'sessionStorage',
            action: e.newValue === null ? 'remove' : 'set',
            key: e.key,
            newValue: e.newValue || '',
            oldValue: e.oldValue || ''
        }, 'storage');
    });

    debugbarDebug('Storage Capture', 'Web storage change capture started', 'js_log');
}

// ============================================================
// PERFORMANCE OBSERVERS
// ============================================================

/**
 * Enable performance monitoring (memory, navigation timing, etc.).
 */
function debugbarEnablePerformanceMonitor()
{
    // Memory snapshot every 10 seconds
    if(typeof performance !== 'undefined' && performance.memory)
    {
        setInterval(function() {
            var mem = performance.memory;
            debugbarDebug('[Memory] Snapshot', {
                usage: mem.usedJSHeapSize,
                peak: mem.jsHeapSizeLimit,
                limit: debugbarFormatBytes(mem.jsHeapSizeLimit),
                delta: mem.usedJSHeapSize - (mem._lastUsed || mem.usedJSHeapSize)
            }, 'memory');
            mem._lastUsed = mem.usedJSHeapSize;
        }, 10000);
    }

    // Network info
    if(navigator.connection)
    {
        debugbarDebug('[Network] Connection Info', navigator.connection, 'network');
        navigator.connection.addEventListener('change', function() {
            debugbarDebug('[Network] Changed', navigator.connection, 'network');
        });
    }

    debugbarDebug('Performance Monitor', 'Performance monitoring started', 'js_log');
}

// ============================================================
// CONSOLE OVERRIDE
// ============================================================

/**
 * Override native console methods to capture output in the debug bar.
 */
function debugbarOverrideConsole()
{
    if(typeof window.console === 'undefined') return;

    var originalLog = window.console.log.bind(window.console);
    var originalInfo = window.console.info.bind(window.console);
    var originalWarn = window.console.warn.bind(window.console);
    var originalError = window.console.error.bind(window.console);
    var originalDebug = window.console.debug.bind(window.console);

    window.console.log = function()
    {
        originalLog.apply(window.console, arguments);
        debugbarAddConsole('log', Array.prototype.slice.call(arguments));
    };

    window.console.info = function()
    {
        originalInfo.apply(window.console, arguments);
        debugbarAddConsole('info', Array.prototype.slice.call(arguments));
    };

    window.console.warn = function()
    {
        originalWarn.apply(window.console, arguments);
        debugbarAddConsole('warning', Array.prototype.slice.call(arguments));
    };

    window.console.error = function()
    {
        originalError.apply(window.console, arguments);
        debugbarAddConsole('error', Array.prototype.slice.call(arguments));
    };

    window.console.debug = function()
    {
        originalDebug.apply(window.console, arguments);
        debugbarAddConsole('log', Array.prototype.slice.call(arguments));
    };

    console.log('MyBB Debug Bar: console capture active');
}

// Override console immediately so inline scripts are captured
debugbarOverrideConsole();

/**
 * Add an entry to the debug bar's console pane.
 *
 * @param {string} label - The log level label.
 * @param {Array}  args  - The arguments passed to the console method.
 */
function debugbarAddConsole(label, args)
{
    var consolePane = document.getElementById('debugbar-pane-console');
    if(!consolePane)
    {
        debugbar_console_queue.push({label: label, args: args});
        return;
    }

    var section = consolePane.querySelector('.debugbar-section');
    if(!section)
    {
        var sectionHtml = ''
            + '<div class="debugbar-section">'
            + '<h3 class="debugbar-section-title debugbar-section-title-console">Console Output</h3>'
            + '<div class="debugbar-section-body" id="debugbar-console-body">'
            + '</div></div>';
        consolePane.innerHTML = sectionHtml;
        section = consolePane.querySelector('.debugbar-section');
    }

    var body = document.getElementById('debugbar-console-body');
    if(!body)
    {
        body = section.querySelector('.debugbar-section-body');
        if(!body) return;
        body.id = 'debugbar-console-body';
    }

    var placeholder = document.getElementById('debugbar-console-empty');
    if(placeholder)
    {
        placeholder.parentNode.removeChild(placeholder);
    }

    var parts = [];
    for(var i = 0; i < args.length; i++)
    {
        if(typeof args[i] === 'object')
        {
            try
            {
                parts.push(JSON.stringify(args[i], null, 2));
            }
            catch(e)
            {
                parts.push(String(args[i]));
            }
        }
        else
        {
            parts.push(String(args[i]));
        }
    }
    var message = parts.join(' ');

    var item = document.createElement('div');
    item.className = 'debugbar-console-item debugbar-console-' + label;
    item.innerHTML = '<span class="debugbar-console-label">[' + label.toUpperCase() + ']</span>'
        + '<span class="debugbar-console-message">' + debugbarEscapeHtml(message) + '</span>';

    body.insertBefore(item, body.firstChild);

    debugbarUpdateConsoleCount(1);
}

/**
 * Update the console tab badge count.
 *
 * @param {number} increment
 */
function debugbarUpdateConsoleCount(increment)
{
    var tabs = document.querySelectorAll('.debugbar-tab');
    for(var i = 0; i < tabs.length; i++)
    {
        if(tabs[i].getAttribute('data-tab') === 'console')
        {
            var match = tabs[i].textContent.match(/^Console\s*\(?(\d*)\)?$/);
            if(match)
            {
                var current = parseInt(match[1], 10) || 0;
                var newCount = current + increment;
                tabs[i].textContent = newCount > 0 ? 'Console (' + newCount + ')' : 'Console';
            }
            else
            {
                tabs[i].textContent = 'Console (' + increment + ')';
            }
            break;
        }
    }
}

/**
 * Escape HTML special characters.
 *
 * @param {string} str
 * @return {string}
 */
function debugbarEscapeHtml(str)
{
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

// ============================================================
// DRAG & POSITIONING
// ============================================================

/**
 * Start dragging the debug bar icon.
 *
 * @param {MouseEvent|TouchEvent} e
 */
function debugbarDragStart(e)
{
    var toggle = document.getElementById('debugbar-toggle');
    if(!toggle) return;

    var clientX, clientY;
    if(e.type === 'touchstart')
    {
        clientX = e.touches[0].clientX;
        clientY = e.touches[0].clientY;
    }
    else
    {
        clientX = e.clientX;
        clientY = e.clientY;
    }

    var rect = toggle.getBoundingClientRect();
    debugbar_drag_offset_x = clientX - rect.left;
    debugbar_drag_offset_y = clientY - rect.top;

    debugbar_dragging = true;
    debugbar_drag_start_x = clientX;
    debugbar_drag_start_y = clientY;

    toggle.style.bottom = 'auto';
    toggle.style.right = 'auto';
    toggle.style.top = rect.top + 'px';
    toggle.style.left = rect.left + 'px';

    toggle.classList.add('debugbar-dragging');
    var panel = document.getElementById('debugbar-panel');
    if(panel) panel.classList.add('debugbar-dragging');

    e.preventDefault();
}

/**
 * Position the panel smartly relative to the toggle icon.
 *
 * @param {number} toggleLeft
 * @param {number} toggleTop
 * @param {number} toggleW
 * @param {number} toggleH
 */
function debugbarPositionPanel(toggleLeft, toggleTop, toggleW, toggleH)
{
    var panel = document.getElementById('debugbar-panel');
    if(!panel) return;

    var panelW = panel.offsetWidth || 420;
    var panelH = panel.offsetHeight || 350;
    var margin = 12;
    var vw = window.innerWidth;
    var vh = window.innerHeight;
    var toggleCenterX = toggleLeft + toggleW / 2;
    var toggleCenterY = toggleTop + toggleH / 2;

    var panelLeft;
    if(toggleCenterX < vw / 2)
    {
        panelLeft = toggleLeft + toggleW + margin;
    }
    else
    {
        panelLeft = toggleLeft - panelW - margin;
    }

    panelLeft = Math.max(4, Math.min(panelLeft, vw - panelW - 4));

    var panelTop;
    if(toggleCenterY < vh / 2)
    {
        panelTop = toggleTop + toggleH + margin;
    }
    else
    {
        panelTop = toggleTop - panelH - margin;
    }

    panelTop = Math.max(4, Math.min(panelTop, vh - panelH - 4));

    panel.style.left = panelLeft + 'px';
    panel.style.top = panelTop + 'px';
    panel.style.bottom = 'auto';
    panel.style.right = 'auto';
}

function debugbarDragMove(e)
{
    if(!debugbar_dragging) return;

    var clientX, clientY;
    if(e.type === 'touchmove')
    {
        clientX = e.touches[0].clientX;
        clientY = e.touches[0].clientY;
    }
    else
    {
        clientX = e.clientX;
        clientY = e.clientY;
    }

    var toggle = document.getElementById('debugbar-toggle');
    if(!toggle) return;

    var newLeft = clientX - debugbar_drag_offset_x;
    var newTop = clientY - debugbar_drag_offset_y;

    var toggleW = toggle.offsetWidth;
    var toggleH = toggle.offsetHeight;
    newLeft = Math.max(0, Math.min(newLeft, window.innerWidth - toggleW));
    newTop = Math.max(0, Math.min(newTop, window.innerHeight - toggleH));

    toggle.style.left = newLeft + 'px';
    toggle.style.top = newTop + 'px';

    debugbarPositionPanel(newLeft, newTop, toggleW, toggleH);

    e.preventDefault();
}

/**
 * Stop dragging the debug bar icon.
 *
 * @param {MouseEvent|TouchEvent} e
 */
function debugbarDragEnd(e)
{
    if(!debugbar_dragging) return;
    debugbar_dragging = false;

    var toggleEl = document.getElementById('debugbar-toggle');
    var panelEl = document.getElementById('debugbar-panel');
    if(toggleEl) toggleEl.classList.remove('debugbar-dragging');
    if(panelEl) panelEl.classList.remove('debugbar-dragging');

    var toggle = document.getElementById('debugbar-toggle');
    if(!toggle) return;

    var clientX, clientY;
    if(e.type === 'touchend')
    {
        clientX = e.changedTouches[0].clientX;
        clientY = e.changedTouches[0].clientY;
    }
    else
    {
        clientX = e.clientX;
        clientY = e.clientY;
    }

    var dx = clientX - debugbar_drag_start_x;
    var dy = clientY - debugbar_drag_start_y;
    var distance = Math.sqrt(dx * dx + dy * dy);

    if(distance < 5)
    {
        debugbarToggle();
    }
    else
    {
        try
        {
            var pos = {
                top: toggle.style.top,
                left: toggle.style.left
            };
            localStorage.setItem('mybb_debugbar_pos', JSON.stringify(pos));
        }
        catch(ex) {}
    }
}

/**
 * Restore saved position on page load.
 */
function debugbarRestorePosition()
{
    try
    {
        var saved = localStorage.getItem('mybb_debugbar_pos');
        if(!saved) return;

        var pos = JSON.parse(saved);
        var toggle = document.getElementById('debugbar-toggle');
        if(!toggle) return;

        toggle.style.bottom = 'auto';
        toggle.style.right = 'auto';
        toggle.style.top = pos.top;
        toggle.style.left = pos.left;

        var topVal = parseInt(pos.top, 10);
        var leftVal = parseInt(pos.left, 10);
        var toggleW = toggle.offsetWidth || 48;
        var toggleH = toggle.offsetHeight || 48;
        debugbarPositionPanel(leftVal, topVal, toggleW, toggleH);
    }
    catch(ex) {}
}

/**
 * Copy the content of the currently active tab to the clipboard.
 */
function debugbarCopyTabContent()
{
    var activePane = document.querySelector('.debugbar-pane-active');
    if(!activePane) return;

    var text = activePane.textContent || activePane.innerText || '';
    text = text.trim();

    if(!text) return;

    if(typeof navigator.clipboard !== 'undefined' && navigator.clipboard.writeText)
    {
        navigator.clipboard.writeText(text).catch(function() {});
    }
    else
    {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch(ex) {}
        document.body.removeChild(ta);
    }

    var copyIcon = document.getElementById('debugbar-copy-icon');
    var copiedIcon = document.getElementById('debugbar-copied-icon');
    var copyBtn = document.getElementById('debugbar-copy-btn');
    if(copyBtn) copyBtn.classList.add('debugbar-copied');
    if(copyIcon) copyIcon.style.display = 'none';
    if(copiedIcon) copiedIcon.style.display = 'block';

    setTimeout(function()
    {
        if(copyBtn) copyBtn.classList.remove('debugbar-copied');
        if(copyIcon) copyIcon.style.display = 'block';
        if(copiedIcon) copiedIcon.style.display = 'none';
    }, 2000);
}

// ============================================================
// INITIALIZATION
// ============================================================

/**
 * Initialize the debug bar event listeners and start client-side features.
 *
 * Reads debugbar_js_features variable (set from PHP settings) to determine
 * which client-side features to enable.
 */
function debugbarInit()
{
    // Tab switching and copy button via event delegation
    document.addEventListener('click', function(e)
    {
        var target = e.target;
        if(target.classList.contains('debugbar-tab'))
        {
            var tabName = target.getAttribute('data-tab');
            if(tabName)
            {
                debugbarSwitchTab(tabName);
            }
        }

        var copyBtn = target.closest('#debugbar-copy-btn');
        if(copyBtn)
        {
            debugbarCopyTabContent();
        }
    });

    // Keyboard shortcut
    document.addEventListener('keydown', debugbarKeydown);

    // Click outside to close
    document.addEventListener('click', debugbarClickOutside);

    // Flush any console messages captured before the DOM was ready
    while(debugbar_console_queue.length > 0)
    {
        var item = debugbar_console_queue.shift();
        debugbarAddConsole(item.label, item.args);
    }

    // Flush any debug messages captured before the DOM was ready
    while(debugbar_debug_queue.length > 0)
    {
        var entry = debugbar_debug_queue.shift();
        debugbarAddDebugEntry(entry);
    }

    // Auto-open if errors exist
    debugbarAutoOpen();

    // Drag to reposition (only when enabled by setting)
    var dragEnabled = (typeof window.debugbar_drag_enabled === 'undefined'
        || window.debugbar_drag_enabled);
    var toggle = document.getElementById('debugbar-toggle');
    if(toggle)
    {
        if(dragEnabled)
        {
            toggle.addEventListener('mousedown', debugbarDragStart);
            toggle.addEventListener('touchstart', debugbarDragStart, {passive: false});
        }
        else
        {
            toggle.addEventListener('click', debugbarToggle);
        }
    }

    if(dragEnabled)
    {
        document.addEventListener('mousemove', debugbarDragMove);
        document.addEventListener('touchmove', debugbarDragMove, {passive: false});
        document.addEventListener('mouseup', debugbarDragEnd);
        document.addEventListener('touchend', debugbarDragEnd);
    }

    // Restore saved position
    debugbarRestorePosition();

    // Start client-side features based on settings
    var jsFeatures = window.debugbar_js_features || {};

    if(jsFeatures.events)
    {
        debugbarEnableEvents();
    }

    if(jsFeatures.ajax)
    {
        debugbarEnableAjaxIntercept();
    }

    if(jsFeatures.js_errors)
    {
        debugbarEnableJsErrorIntercept();
    }

    if(jsFeatures.timers)
    {
        debugbarEnableTimerIntercept();
    }

    if(jsFeatures.storage)
    {
        debugbarEnableStorageIntercept();
    }

    if(jsFeatures.performance)
    {
        debugbarEnablePerformanceMonitor();
    }
}

// Initialize on DOM ready
if(document.readyState === 'loading')
{
    document.addEventListener('DOMContentLoaded', debugbarInit);
}
else
{
    debugbarInit();
}

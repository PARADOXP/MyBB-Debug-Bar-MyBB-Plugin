# MyBB Debug Bar for MyBB 1.8

A floating debug panel for MyBB. It adds a small `>_` button to every page, front end and Admin CP, which opens a panel with captured PHP errors, console output, custom debug data, database query count, memory usage and system information.

- **Version:** 1.1.0
- **Codename:** `mybb_debugbar`
- **Compatibility:** MyBB 1.8.x (`18*`)
- **Author:** PARAD0X — [MyBB profile](https://community.mybb.com/user-134311.html)
- **License:** GPL-3.0
- **Dependencies:** PluginLibrary 13, bundled as `inc/plugins/pluginlibrary.php`

## Features

- Draggable `>_` icon with an error-count badge; position persisted in `localStorage`.
- Panel with four tabs: **Errors**, **Info**, **Debug**, **Console**.
- Captures PHP errors with type label, message, file, line and a backtrace.
- Info tab: page URL, MyBB version, current user and UID, usergroup, DB query count, execution time, peak memory, PHP version.
- Console tab: output from the PHP helper `debugbar_log()` and from intercepted `console.log/info/warn/error/debug` calls.
- Debug tab: output from `debugbar_debug()`, with around 70 named slots for events, DOM, network, runtime, PHP, config and performance data.
- Optional logging of captured PHP errors to a database table.
- Admin CP settings page with a PHP code box for ad-hoc `debugbar_debug()` calls.
- Works in both the user-facing board and the Admin CP, using separate renderers.

## Requirements

- MyBB 1.8.x.
- PHP 7.0 or newer (the code catches `Throwable` and uses `error_get_last()` semantics that assume PHP 7+).
- PluginLibrary 13.
- MySQL/MariaDB. The installer and schema use MySQL-only DDL (see defects).

## Installation

1. Copy the contents of this repository over the forum root.
2. Go to **Admin CP → Configuration → Plugins** and activate **MyBB Debug Bar**. There is no separate `_install()` function; the schema, settings and templates are created during activation.
3. Open **Configuration → MyBB Debug Bar** or **Configuration → Settings → MyBB Debug Bar Settings** to configure it.
4. Visit any board page or ACP page as a member of an allowed usergroup.

Activation creates the log table, registers the 16 settings and 11 templates through PluginLibrary, adds the stylesheet to the theme, and rebuilds settings. Deactivation removes only the templates. Uninstall drops the table and removes settings, stylesheet, templates and the template group.

There are no language files. Every string is hardcoded English in PHP, templates and JavaScript.

## How it works

### Front end

- `global_start` loads the capture functions, installs the error handler and evaluates the custom PHP setting.
- `pre_output_page` renders the panel, then injects the CSS link, a small feature-flag script, the JS file and the panel markup by replacing `</head>` and `</body>` in the finished page.

### Admin CP

- The main plugin file loads `Admin/module.php` unconditionally and starts error capture inline when `IN_ADMINCP` is defined.
- `admin_load` either renders the plugin's own settings page or installs an `ob_start()` callback that rewrites the ACP page buffer, adding assets and a hand-built copy of the panel before `</head>` and `</body>`.
- The ACP renderer does not use the registered templates; it rebuilds the same markup in PHP string concatenation. This is why the front end and the ACP can drift apart.

## Settings

Sixteen settings are registered in the `mybb_debugbar` group.

| Setting | Default | Purpose |
| --- | --- | --- |
| Enable Debug Bar | Yes | Master switch for capture, injection and the custom code evaluator. |
| Usergroups that can see the bar | `4` | `groupselect` list of allowed groups. Empty means everyone, including guests. |
| Show DB Query Count | Yes | Adds the query row to the Info tab. |
| Show Memory Usage | Yes | Adds the peak-memory row to the Info tab. |
| Show Console Tab | Yes | Shows the Console tab and pane. |
| Show Debug Tab | Yes | Enables the Debug tab. |
| Show Debug Tab Always | No | Keeps the Debug tab visible when there are no entries. |
| Log errors to DB | No | Inserts one row per captured error into the log table. |
| Enable Draggable Icon | Yes | Allows dragging the icon; position is saved in `localStorage`. |
| JS Event Auto-Capture | No | Attaches DOM event listeners and logs events into the Debug tab. |
| JS AJAX/Fetch Interception | No | Wraps `XMLHttpRequest` and `fetch()` and logs requests and responses. |
| JS Error Capture | No | Captures `window.onerror` and unhandled promise rejections. |
| JS Timer Capture | No | Wraps `setTimeout` and `setInterval`. |
| JS Storage Capture | No | Listens for `storage` events on `localStorage`/`sessionStorage`. |
| JS Performance Monitor | No | Polls `performance.memory` every 10 seconds and records connection info. |
| Custom Debug Code (PHP) | empty | PHP evaluated on every page for allowed groups. |

## Panel tabs

| Tab | Content |
| --- | --- |
| Errors | One block per captured PHP error: type label, `file:line`, escaped message, backtrace. Shows a "no errors" block when empty. |
| Info | Page URL, MyBB version, username + UID, usergroup id, query count, execution time, peak memory, PHP version. |
| Debug | Server entries from `debugbar_debug()` with key, slot label, caller file/line and formatted data; client entries with key, slot and value. |
| Console | Server entries from `debugbar_log()` and client entries from the intercepted console methods. |

## Helper API

### PHP

```php
debugbar_log($message, $label = 'log');
debugbar_debug($key, $data = null, $slot = 'custom');
```

`debugbar_log()` appends to the Console tab. Its label is free text but `log`, `info`, `warning`, `error` and `sql` match the existing CSS classes.

`debugbar_debug()` appends to the Debug tab. The slot selects a formatter in `debugbar_format_structured()`. Supported groups:

- Events: `click`, `dblclick`, `hover`, `mousemove`, `mouse`, `touch`, `scroll`, `resize`, `focus`, `keyboard`, `form`, `drag`, `animation`, `transition`, `input`.
- DOM/style: `style`, `html`, `dom`, `selector`, `layout`, `attr`, `class`, `mutation`.
- Network: `ajax`, `fetch`, `network`, `api`, `websocket`.
- JS runtime: `js_error`, `js_log`, `timer`, `promise`, `storage`, `console`, `worker`.
- PHP: `file`, `include`, `method`, `filter`, `cache`, `redirect`, `email`, `permission`, `upload`, `image`, `locale`, `date`, `math`.
- Config/system: `config`, `constant`, `header`, `cookie`, `env`, `server`.
- Performance: `memory`, `query`, `profile`, `trace`, `counter`.
- Basic: `variable`, `array`, `object`, `sql`, `hook`, `template`, `request`, `session`, `benchmark`, `custom`.

The custom code setting is the intended way to call these without editing plugin files.

### JavaScript

```js
debugbarDebug(key, data, slot);   // mirrors debugbar_debug()
debugbarToggle();                 // open/close the panel
debugbarOpen(tab);                // open a specific tab
debugbarClose();
```

Client-side entries are queued until the panel exists, then rendered with DOM APIs and escaped text.

## Database

`database.sql` creates one table:

```
{TABLE_PREFIX}mybb_debugbar_logs
  id             INT UNSIGNED AUTO_INCREMENT
  uid            INT UNSIGNED
  page_url       VARCHAR(255)
  error_type     VARCHAR(50)
  error_message  TEXT
  error_file     VARCHAR(255)
  error_line     INT UNSIGNED
  timestamp      INT UNSIGNED
```

Indexes: primary `id`, plus `uid` and `timestamp`. Engine is MyISAM.

Rows are written from the error handler only when **Log errors to DB** is enabled. There is no retention setting, no pruning task, no Admin CP viewer and no way to clear the table from the plugin.

## Templates

Eleven templates are registered under the `debugbar` prefix, producing database names `debugbar_icon`, `debugbar_panel`, `debugbar_section_errors`, `debugbar_section_error_item`, `debugbar_section_console`, `debugbar_section_console_item`, `debugbar_section_info`, `debugbar_no_errors`, `debugbar_section_debug`, `debugbar_section_debug_item` and `debugbar_section_debug_empty`.

The front-end renderer fetches each one with `$templates->get()`. None of the keys is added to `$templatelist`, so MyBB loads them individually on first use. The ACP renderer does not use them at all.

## Hooks

| Hook | Function | Purpose |
| --- | --- | --- |
| `global_start` | `mybb_debugbar_global_start` | Starts capture and evaluates the custom PHP code. |
| `pre_output_page` | `mybb_debugbar_output_page` | Renders the panel and injects assets/markup into the page. |
| `admin_load` | `mybb_debugbar_admin_load` | Routes the ACP settings page, otherwise injects the bar into the ACP buffer. |
| `admin_config_menu` | `mybb_debugbar_admin_menu` | Adds **MyBB Debug Bar** under Configuration. |
| `admin_config_action_handler` | `mybb_debugbar_admin_action_handler` | Declares the module action with an empty `file`. |

There is no `admin_config_permissions` hook and no entry in the Configuration permission list.

## Admin CP page

`index.php?module=config-mybb_debugbar` renders every setting as a custom form, including a `phptextarea` for the custom PHP code, and saves the rows directly:

- all scalar settings are updated with `$db->update_query()`;
- the custom code is escaped by hand with `str_replace()` and written with `$db->write_query()`;
- `rebuild_settings()` runs and OPcache is invalidated;
- the user is redirected with a flash message.

The same 16 settings also appear in the native settings page because PluginLibrary registers the group. Both pages write the same rows through different code paths.

## Repository layout

```
inc/
└── plugins/
    ├── mybb_debugbar.php
    ├── pluginlibrary.php
    └── MyBB_DebugBar/
        ├── Admin/module.php
        ├── Assets/mybb_debugbar.css
        ├── Assets/mybb_debugbar.js
        ├── Functions/capture_functions.php
        ├── Functions/render_functions.php
        ├── Templates/*.html          (11 templates)
        ├── database.sql
        ├── install.php
        ├── settings.json
        └── uninstall.php
```

No language files, no task file, no `admin/modules` file and no `jscripts` file exist for this plugin.

## Known defects and limitations

These are properties of the current source, documented rather than fixed.

### Security and authorisation

1. **The custom code box is a remote-code-execution feature.** `mybb_debugbar_eval_custom_code()` runs `eval()` on the `debug_custom_code` setting on every front-end request for every allowed group. The value is stored by the ACP settings page, not by a file edit. Parse errors are only partly detected by comparing `error_get_last()` before and after the call; runtime errors inside the code execute normally.
2. **No Admin CP permission is declared.** There is no `admin_config_permissions` hook, so MyBB's `check_admin_permissions()` has nothing to deny. Any administrator who can reach the Configuration module can open the page, change settings and store PHP for execution.
3. **Custom code is saved with hand-rolled escaping.** The page does `str_replace('\\', '\\\\')` then `str_replace("'", "\\'")` and passes the result into a raw `write_query()`. That bypasses `$db->escape_string()` and query logging, doubles every backslash in stored code, and corrupts code containing quotes, backslashes or NUL bytes.
4. **Group checks use the display group when one is set.** All four checks (`global_start`, `pre_output_page`, `admin_inject`, `render`) compute `displaygroup > 0 ? displaygroup : usergroup`. A user whose primary group is allowed but whose display group is not loses access, and additional usergroups are ignored entirely. Members of secondary allowed groups never see the bar.
5. **An empty group list means everyone, including guests.** That exposes file paths, PHP version, query counts and any custom debug data to anonymous visitors. Combined with a non-empty custom code setting, guests also trigger the `eval()`.
6. **The JS event capture records form values.** When **JS Event Auto-Capture** is enabled, the `form` and `input` formatters print every field name and its value (`f.name + ' = ' + f.value`), including password, password confirmation and token fields, into the panel DOM. The panel has no redaction.
7. **AJAX capture stores response bodies.** `XMLHttpRequest` stores the full `responseText`; `fetch()` stores the first 500 characters of JSON and the first 200 of anything else. Session tokens, private messages, moderation data and CSRF keys can end up in the Debug tab.
8. **Console capture cannot be turned off.** `debugbarOverrideConsole()` runs at script parse time, before settings are read, and replaces `console.log/info/warn/error/debug` unconditionally. Turning off the Console tab only hides the server-rendered pane; the override still runs and still queues entries.
9. **The panel leaks absolute paths and environment data.** Error file names and debug backtraces contain full server paths; the Info tab adds PHP version, user id, usergroup and the current URL. This is intended for administrators but is not rate-limited or permission-audited.

### Correctness and runtime

10. **The shutdown handler is never registered.** `debugbar_shutdown_handler()` exists and is documented as capturing fatal errors, but no `register_shutdown_function()` call exists anywhere in the plugin. Fatal errors, parse errors in included files and out-of-memory failures are never captured. The `$fatal_types` array inside `debugbar_error_handler()` is also unused.
11. **The error handler ignores `error_reporting()` and `@`.** Every notice, deprecation and suppressed warning is appended and displayed. On a typical 1.8 board with PHP 8 this floods the Errors tab with core deprecations that the board itself suppresses.
12. **Database logging can amplify an error storm.** For every captured error the handler runs `$db->table_exists("mybb_debugbar_logs")` and then an `insert_query()`. A failure while writing can raise further errors; the static `$in_handler` guard drops nested calls, but the original error still propagates and MyBB's handler still runs afterwards.
13. **Errors inside output buffers are swallowed.** When `ob_get_level() > 1`, the handler returns `true` and skips MyBB's own handler. Any error raised inside the ACP `ob_start()` callback, a theme buffer or another plugin's buffer therefore disappears from the normal error path and only appears in the panel.
14. **The ACP console pane checks the wrong variable.** `mybb_debugbar_admin_inject()` defines `$show_console_tab` (line 580) but tests `$show_console_setting` (line 658). PHP 8 emits an undefined-variable warning, the condition is false, and the console pane is always empty in the ACP while the Console tab button is still rendered.
15. **The Debug tab cannot be hidden once entries exist.** Both renderers set `$show_debug_tab = $debug_count > 0 || (...)`, so **Show Debug Tab** = No still shows the tab whenever a plugin has called `debugbar_debug()`.
16. **Execution time starts too late.** The timer begins in `debugbar_start_capture()` at `global_start` (or at plugin file load in the ACP), not at request start, so the reported value omits MyBB bootstrap, database connect and session work.
17. **Memory is peak, not current.** The Info tab reports `memory_get_peak_usage(true)` and labels it "Memory Usage"; it never shows current usage, so a page that allocated and freed a large buffer looks permanently heavy.
18. **The query count is incomplete.** It reads `$db->query_count`, which does not include `write_query()` calls (used by this plugin's installer and settings save) and excludes queries issued after `pre_output_page`.
19. **Injection replaces every `</head>` and `</body>`.** `str_replace()` is global. If the final HTML contains more than one literal occurrence of either tag, for example because a template or another plugin prints one inside a string or a buffered fragment, the assets and the panel are inserted at each occurrence, producing duplicate element ids and broken markup.
20. **Assets are duplicated.** Activation registers `mybb_debugbar.css` through `$PL->stylesheet()`, which attaches it to the theme, and the renderer also injects a direct `<link>` to `Assets/mybb_debugbar.css`. Browsers download and parse both copies.
21. **No cache busting.** Neither asset URL has a `?ver=` parameter. After an upgrade, browsers and proxies can keep serving the old CSS/JS until a hard refresh.
22. **The ACP injector rewrites every HTML buffer.** `ob_start()` is installed for every ACP request and the callback runs on the whole page, checking only the last `Content-Type` header. Compressed responses (`zlib.output_compression`, a gzip handler) and responses where `Content-Length` was already sent are not handled, and the callback adds measurable overhead to every admin page.
23. **There is no `_install()` function.** MyBB's plugin manager looks for `mybb_debugbar_install()`, which does not exist. Installation happens as a side effect of `_activate()`, which calls `mybb_debugbar_install_run()` every time the plugin is activated. The separate Install action therefore does not exist, and re-activating repeats schema and template work.
24. **Deactivation is partial.** `mybb_debugbar_deactivate()` removes templates only. Settings, the cached stylesheet, the log table and all rows, and the settings group remain; the ACP menu entry disappears only because it is registered at runtime.
25. **Uninstall depends on the plugin folder.** `uninstall.php` requires `install.php` to reach `mybb_debugbar_remove_templates()`. The fallback branch of that function re-reads `Templates/` from disk, so uninstall fails to remove templates if the folder was deleted first.
26. **The assets disappear on themes without a closing `</head>`.** If the final page does not contain the literal `</head>`, the CSS and JS are never inserted, but the panel markup is still inserted before `</body>`. The result is an unstyled panel whose JavaScript never runs, with no error message.
27. **The schema is MySQL-only.** `INT UNSIGNED`, `ENGINE=MyISAM` and the `KEY` clauses are MySQL syntax. The installer also splits `database.sql` on every `;`, so a semicolon inside a comment or default value breaks installation. PostgreSQL and SQLite boards cannot install it.
28. **Logged errors have no retention.** With **Log errors to DB** enabled, `mybb_debugbar_logs` grows without bound. No task prunes it, no ACP page reads it and no setting limits it.
29. **`admin_config_action_handler` declares an empty file.** `'file' => ''` means the router would `require` the Configuration module directory if `admin_load` ever failed to short-circuit. It works only because `admin_load` always renders or injects first.
30. **ACP routing compares the raw module string.** `mybb_debugbar_admin_load()` checks `$mybb->input['module'] === 'config-mybb_debugbar'`. Alternative routes such as `module=config&action=mybb_debugbar` are not recognised, so the settings page does not render and the bar is injected into the resulting page instead.

### Code quality, duplication and integration

31. **Two complete renderers.** `Functions/render_functions.php` renders from the 11 registered templates; `Admin/module.php` rebuilds the same icon, tabs, errors, console, info and debug markup as concatenated PHP strings. Roughly 300 lines are duplicated, and they have already diverged (defect 14).
32. **Three byte formatters and two slot-label maps.** `debugbar_format_bytes()` (front end), `mybb_debugbar_format_bytes()` (ACP) and `debugbar_format_profile_memory()` (capture) each implement the same conversion. The slot label map exists once in PHP and once in JavaScript, and the ACP has a third copy.
33. **Templates are fetched one by one.** The renderer calls `$templates->get()` for 11 templates and none of the keys appears in `$templatelist`, so uncached template loads run on every page that shows the bar.
34. **Missing templates fail silently.** `$templates->get()` returns an empty string when a template is absent (for example after a partial uninstall or a failed fallback install), and the renderer happily `eval()`s the empty string, producing an invisible panel with no diagnostic.
35. **No language files anywhere.** All setting titles, descriptions, tab labels, error labels, ACP headings, flash messages and JavaScript strings are English literals. The plugin cannot be translated without editing source, and it does not follow the project convention of front-end and admin `.lang.php` files.
36. **The ACP page contains a hand-rolled syntax highlighter.** `Admin/module.php` embeds a PHP highlighter, line-number gutter, scroll sync and copy shortcuts as inline JavaScript and CSS inside PHP `echo` statements, rather than an asset file or template.
37. **The custom code editor is a `phptextarea` setting with a second editor.** The native settings page renders it with MyBB's setting handler, while the custom ACP page renders its own editor and saves through different escaping, so the same value behaves differently depending on which page was used.
38. **Front-end and ACP assets load on every page for allowed users**, even while the panel is closed: a 24 KB stylesheet and a 53 KB script, uncompressed and unminified here. A minified CSS file exists only in the live board's cache, not in this repository.
39. **The client queues are unbounded.** `debugbar_console_queue` and `debugbar_debug_queue` are plain arrays that are pushed to and never trimmed or cleared. A long admin session with event or AJAX capture can grow memory and the DOM without limit.
40. **Event capture is very broad.** It attaches more than 20 document- and window-level listeners in the capture phase, including `mousemove` throttled to 500 ms, plus form, input, drag, animation and transition events. The performance cost and the privacy exposure both scale with page activity.
41. **Timer interception wraps every timer.** `setTimeout` and `setInterval` are replaced so each call produces a debug entry. Recursive or high-frequency timers generate an entry per tick.
42. **The performance monitor is browser-specific.** It relies on `performance.memory`, which only exists in Chromium-based browsers, and polls every 10 seconds. Other browsers silently produce nothing.
43. **Server and client entries are ordered differently.** PHP-rendered items are appended in call order; `debugbarAddConsole()` and `debugbarAddDebugEntry()` insert each new item before the first child, so client entries appear newest-first above the server entries.
44. **The console override announces itself.** `debugbarOverrideConsole()` calls `console.log('MyBB Debug Bar: console capture active')` after installing the wrappers, adding a synthetic entry to every captured console pane.
45. **`debugbarFormatBytes()` mishandles signs in JavaScript.** It computes the unit exponent from `Math.abs(bytes)` but divides the signed value, so a negative number formats with a negative magnitude.
46. **Escaping differs between the renderers.** The front end uses `htmlspecialchars_uni()` for messages, files and traces; the ACP uses `htmlspecialchars()` and prints `$item['value']` unescaped in the info loop. Current values are safe, but the two paths treat multibyte and future values differently.
47. **Info rows always include execution time and PHP version.** **Show DB Query Count** and **Show Memory Usage** only remove their own rows; there is no switch for the other fields.
48. **Panel pane order is fixed in the template.** The `debugbar_panel` template always emits errors, info, debug and console panes, even when a tab is disabled and its HTML is empty. The JS still queries and wires all four panes.
49. **Custom code is line-filtered, not parsed.** Comment stripping removes lines starting with `#`, `//` or `;` and concatenates the rest. A heredoc, a multi-line string or a `//` inside a string literal is mangled, and a trailing `?>` in the setting can close the eval context.
50. **`mybb_debugbar_is_installed()` checks only the log table.** If the table exists but settings or templates were removed, the plugin reports installed and MyBB skips the Install step; activation still reruns the installer, masking the inconsistency.
51. **Plugin metadata is inconsistent.** `_info()` credits `PARADOX` and the file docblock credits the "MyBB Debug Bar Team"; the website is `https://mybbhub.shop`, which is unrelated to the author profile link.
52. **The panel controls are not keyboard accessible.** The close and copy controls are `<span>` elements with `onclick` handlers and no `role` or `tabindex`, and the draggable icon has no keyboard alternative, so the panel cannot be fully operated without a mouse.

## Compatibility notes

- Tested against MyBB 1.8.41 with the default theme. The front-end injection assumes the page contains `</head>` and `</body>`; the ACP injection assumes an HTML response.
- The ACP settings page and the native settings page both write the same `settings` rows, so a change made in one is visible in the other after `rebuild_settings()` runs.
- `debugbar_debug()` is a global function name with no plugin prefix. Another plugin that defines a function of the same name causes a fatal redeclare.
- PluginLibrary 13 is bundled unmodified. Its header declares LGPL-3.0; the GPL-3.0 license in this repository covers the MyBB Debug Bar code only.

## License

GPL-3.0. See [LICENSE](LICENSE).

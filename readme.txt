=== Banka TMS App ===
Contributors: Copilot
Tags: dashboard, task management, users
Requires at least: 6.0
Tested up to: 6.5
Stable tag: 3.0.65

== Description ==

Banka TMS App is a WordPress plugin that creates an app-style dashboard experience with blank-template pages for tasks, users, vendors, and settings. It is designed as a starting point for recreating the Banka TMS workflow in WordPress.

== Installation ==

1. Upload the plugin folder to the /wp-content/plugins/ directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. The plugin will automatically create the app pages on activation.

== Usage ==

Visit the generated pages:

- /tms-dashboard/
- /tms-tasks/
- /tms-users/
- /tms-vendors/
- /tms-settings/

= 3.0.65 =
* Fixed Action Log owner names so task updates use the selected task owner instead of the task creator.
* Automatically corrects existing Action Log owner names from the current task owner, including Excel and PDF output.


= 2.5.9 =
* Standardised all TaskPro scheduling, reminders, logs and generated timestamps to India Standard Time (Asia/Kolkata).

= 3.0.29 =
* Fixed Project Details Action Log loading for ArchTMS-session users.
* Added the required unauthenticated WordPress AJAX route while retaining ArchTMS session validation.
* Prevented stale WordPress nonces from blocking valid ArchTMS sessions.


= 3.0.33 =
* Restored Tasks and Action Log template download/upload buttons.
* Added created_at to the Action Log import template and preserves imported timestamps.

= 3.0.34 =
* Removed Download Template and Upload Template buttons from Tasks and Action Log views.
* Kept task/action-log import handlers and created_at support unchanged.

= 3.0.35 =
* Fixed Action Log filters being reset by table pagination.
* Assignee, Owner, Project, Status, Search and Date filters now work together with pagination.

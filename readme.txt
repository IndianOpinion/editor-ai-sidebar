=== Editor AI Sidebar ===
Contributors: indianopinion
Tags: block editor, sidebar, ai, approval, editorial
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

One block-editor sidebar for AI actions. Each action calls a server endpoint and shows its result for approval before anything is written.

== Description ==

Editor AI Sidebar is a host. It puts one sidebar in the block editor, with one button per registered action, one way to show a result and one explicit approve step before anything is written. It makes no model calls of its own and sends nothing anywhere.

Provider plugins register actions through one PHP filter, `editor_ai_sidebar_actions`, and answer one REST contract that this plugin defines and enforces on the server:

* Nothing is written until an editor clicks Approve, and the server refuses an apply that does not match a result it issued to that user.
* Title, excerpt and content change only in the unsaved editor state, so the editor's undo works and the next save does not undo a server write.
* Long-running actions are queued and polled.
* Provider text is always shown as text, never as HTML.

With no actions installed it shows an empty state and does nothing else.

Written from scratch; not derived from any third-party plugin.

== Installation ==

1. Upload the `editor-ai-sidebar` folder to `/wp-content/plugins/` and activate it.
2. Activate a provider plugin, or try the examples shipped in the `examples` folder.
3. Open a post in the block editor and click "AI actions" in the toolbar.

To run it as a must-use plugin, see the README.

== Frequently Asked Questions ==

= Does it call an AI service? =

No. Providers do that. This plugin makes no outbound request.

= Where are the settings? =

Settings, Writing, "AI actions": switch the sidebar off, switch single actions off, and see rejected definitions.

= Who can approve a result? =

Only the user who ran the action, while the result lasts (30 minutes by default), and only with the action's apply capability.

== Changelog ==

= 0.1.0 =
* First release.

== Privacy ==

The plugin adds a suggested paragraph to the privacy policy guide: installed AI actions may send post content to the services their providers use; this plugin itself sends nothing anywhere. It keeps short-lived records of each suggestion in its own table and an event log of ids and codes.

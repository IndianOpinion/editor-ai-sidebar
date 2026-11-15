# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-11-15

### Added

- One document sidebar in the block editor, "AI actions", listing registered actions grouped as summary, headlines, meta, alt text, translation and other.
- The `editor_ai_sidebar_actions` registration filter with validation of every definition; broken definitions are dropped with a reason and the rest keep working.
- REST contract version 1 under `editor-ai-sidebar/v1`: list actions, run, poll and cancel queued jobs, apply and dismiss results. Every route sends `Cache-Control: no-store` and `X-Editor-AI-Sidebar-Contract: 1`.
- Server-checked approval: single-use result ids bound to user, post and action, with item and context hashes.
- Editor operations through the editor's data stores: set title, set excerpt, insert blocks, set a block attribute, set registered meta, copy.
- Server-mode applies with sync back to the editor's copy of meta, featured image and attachment alt text.
- Queued runs with polling, cancel, and resume after an editor reload.
- Write watch during a run, fatal-error guard, host rate limit per user and daily run cap per action.
- Settings under Settings, Writing; event log of ids and codes; WP-CLI `actions` and `log` commands; suggested privacy policy text.
- Example providers: a word count, and five canned actions covering every kind.

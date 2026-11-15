# Examples

Two provider plugins that exercise the contract end to end. Neither makes a
model call.

| Folder | What it shows |
|---|---|
| `word-count/` | The smallest provider: one harmless action, array form only, no dependency on the host's classes. Counts words and offers to copy the count. Writes nothing. |
| `canned-providers/` | One action per kind the sidebar was built for (summary, headlines, meta, alt text, translation), with canned results. Uses the value objects. The test suite loads it. |

To try one, copy its folder into `wp-content/plugins/` next to
`editor-ai-sidebar` and activate both.

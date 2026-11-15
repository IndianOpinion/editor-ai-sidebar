# Security policy

## Reporting a vulnerability

Email hello@indianopinion.org with "security: editor-ai-sidebar" in the subject. Please do not open a public issue for a vulnerability.

Include the version, what an attacker can do, and the steps to reproduce. A proof of concept helps.

We reply within 5 working days, agree a fix and a disclosure date with you, and credit you in the release notes unless you would rather not be named.

## Supported versions

| Version | Supported |
|---|---|
| 0.1.x | Yes |

## Scope

In scope: the approval guarantee (anything written without a server-verified approve), capability and nonce checks on the REST routes, output escaping in the sidebar and the settings screen, and leaks of post content or model output into logs or responses.

Out of scope: what a provider plugin does inside its own callbacks. A provider is PHP code running on the site with full access; this plugin cannot sandbox it.

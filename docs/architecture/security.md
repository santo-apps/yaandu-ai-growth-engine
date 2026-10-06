# Security and Trust Boundaries

## Tenant isolation

Resolve active tenant from authenticated membership, never from an unchecked request parameter. Apply tenant-scoped route binding, policies, and query scopes to every business record. A UUID is not authorization. Validate tenant consistency across every relationship before writes. Add tests for cross-tenant reads, updates, relationship injection, nested resources, exports, job payloads, and storage access. Queue jobs carry tenant IDs and re-establish scope explicitly. Use least-privilege database roles and private object storage with short-lived signed URLs issued only after authorization.

## Authentication, credentials, and auditing

Use Sanctum with secure cookies, CSRF protection, session rotation, throttling, and verified identity for browser sessions; use scoped, revocable tokens only for integrations that need them. Enforce role/permission policies for configuration changes and run cancellation. Encrypt secrets at rest through a managed secret store or Laravel encryption backed by a managed key; persist only secret references in `ai_model_configurations`. Never return secrets to Vue. Redact keys, authorization headers, cookies, session IDs, personal contact details, and raw page bodies from logs. Audit authentication, tenant membership, model/prompt/scoring configuration, exports, and agent actions in append-only `audit_logs`.

## Untrusted website content and prompt injection

Every page, metadata field, screenshot, document, and extracted contact is untrusted data. Delimit it as evidence, label its source, and tell models that embedded instructions have no authority. Keep system/developer policy outside retrieved content. Do not let page content select tools, providers, credentials, destinations, or tenant scope. Use constrained JSON outputs, schema validation, and deterministic server-side checks. Tool calls are allowlisted and authorized independently of model output. Truncate/normalize inputs, remove active markup, cap tokens, and retain references to raw evidence instead of passing entire pages when possible.

## SSRF and crawler policy

Accept only `http` and `https`; reject credentials in URLs, malformed/ambiguous hosts, unsupported ports, and non-public address ranges. Resolve DNS and validate every resolved address immediately before connection; repeat on redirects and block loopback, private, link-local, multicast, reserved, and cloud metadata ranges for IPv4 and IPv6. Prevent DNS rebinding by pinning validated resolution to the connection where supported. Disable redirects or validate each hop, cap redirect count, response bytes, decompression ratio, content types, time, depth, pages, and per-host concurrency. Never fetch arbitrary URLs from model output. Keep the browser sandboxed, egress-restricted, disposable, and without cloud credentials or host mounts. Use HTTP first and Playwright only for pages that require rendering. Respect robots.txt, applicable rate limits, terms/platform policies, and explicit deny lists. Never bypass CAPTCHA, authentication, or access controls. Make crawl identity and contact details clear where required by policy.

### Browser worker deployment gate

`deploy/kubernetes/browser-worker-cilium-network-policy.yaml` is the Cilium policy template. The intended deployment is one ephemeral capture Job per request in the dedicated `yaandu-browser` namespace, labeled `app.kubernetes.io/name: yaandu-browser-worker`. A trusted Laravel-side provisioner supplies the bounded target and pinned IP through the Job spec; the worker writes only to a one-object, short-lived S3-compatible presigned URL. It has no service account token, listeners, sidecars, host mounts, or cloud credentials. Cilium denies all ingress, host/remote-node, RFC1918/shared/private, link-local/metadata, IPv6 local, and common reserved egress ranges while permitting public Internet egress. Before deployment, add actual VPC, Kubernetes pod/service, Redis, PostgreSQL, Laravel, and other internal service CIDRs/IPs to `egressDeny`; validate against the installed Cilium version and verify public routes cannot reach internal services. An isolated pod has its own network namespace and no local listeners; Kubernetes NetworkPolicy does not filter a process's loopback traffic inside its own namespace. Policy application and the separate Job/provisioner path are **NOT DEPLOYED/NOT RUNTIME VERIFIED** here. The current PHP service launches Chromium as a child process in the Laravel worker, so `CRAWLER_PLAYWRIGHT_ENABLED` remains false until that code path is moved behind this isolation design. Browser request interception is defense in depth, not the network boundary.

## Contact data and retention

Collect only public business contact information relevant to the stated business use. Do not infer missing values or fabricate people/addresses. Persist per-field provenance, capture timestamp, extraction method, and confidence. Apply suppression checks before any future outreach and honor deletion/correction requests. Minimize retention, encrypt contact methods, restrict access, and avoid sending contact data to AI providers unless required and configured. Establish jurisdiction-specific privacy review before production outreach.

## Unsafe tool and queue execution

Workers use a minimal filesystem/network identity. Jobs validate payload shape and tenant ownership again at execution time. Tool inputs are schema-checked, commands are not assembled from model text, and browser actions are limited to navigation/read-only capture. Add queue timeouts, retry caps, uniqueness/locks, cancellation checks, and poison-job handling. Do not expose arbitrary code execution or SQL tools to agents.

## Sales execution controls

Outbound actions use an allowlisted provider interface, persisted idempotency keys, bounded queues, per-tenant and per-campaign limits, and a final suppression/state/window check at execution time. Store contact identifiers as keyed hashes in suppression records. Never put provider credentials or sensitive message bodies in queue logs or event metadata. Verify webhook signatures and enforce replay windows before applying delivery, bounce, complaint, or reply events. Escape template substitutions and reject control characters in email headers. Human takeover atomically stops pending automation. Proposal approval is authorized by tenant role and binds approval to the exact proposal version and pricing snapshot.

## Security verification gates

Before production, verify tenant isolation, SSRF/redirect/DNS protections, prompt injection handling, secret redaction, object authorization, authentication/session controls, dependency and container scanning, backup restoration, and audit immutability. This is a release checklist, not a substitute for a threat model review against the deployed environment.

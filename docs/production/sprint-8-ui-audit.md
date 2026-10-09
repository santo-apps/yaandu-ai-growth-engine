# Sprint 8 UI Audit and Modernization Notes

## Baseline findings

The original app shell and component styles used many one-off styles, small 8–11px text, a minimum 800px body width, low-contrast metadata, repeated borders, and mixed business/operations navigation. The Prospect 360 header emphasized an advisory score and status but did not give the human decision panel the strongest visual treatment. The pilot dashboard rendered workflow, human-decision and AI-advisory metrics in one undifferentiated grid. Tables had horizontal scrolling but inconsistent density. Loading/empty/error messages existed but used inconsistent styling. Some component-level styles remain legacy and should be migrated incrementally.

Screens covered by repository audit: login (inline in `App.vue`), Dashboard/Home, Prospects, Prospect 360, Website Intelligence/Evidence, Human Review/Service Selection/Priority, Campaign/Outreach, AI Configuration, ICP Configuration, Services & Pricing, Prompt Management, Setup, and operational pages. Forms and dialogs are distributed across workspace components. No inaccessible placeholder navigation was added.

## Shared system changes

`src/style.css` now defines color, spacing, radius and semantic tokens plus shared focus visibility, control sizing, surface, table, and responsive shell rules. The shell keeps manager-only setup and operations navigation role-gated. Human decision panels have a distinct blue human-owned surface; AI score and AI mode are explicitly advisory. Dashboard metrics are separated into pilot progress, human decisions, and AI advisory sections. At widths below 760px the sidebar becomes a sticky compact horizontal navigation; narrow tables preserve readable columns inside a bounded scroll region.

## Acceptance review checklist

- Verify at 1440, 1280, 768 and 390px: no page-level horizontal overflow, navigation does not cover content, tables scroll within their own frame, cards and modals remain inside viewport, and long URLs/text wrap safely.
- Check keyboard tab order and visible focus across shell, login, filters, decision controls and dialogs; verify field labels, semantic headings, and status text beyond color.
- Verify empty, loading, API failure and insufficient evidence states with synthetic/local test records. A screenshot alone is not evidence of backend behavior.
- Review screenshots only from synthetic data; do not commit browser profile, session, real prospect or sensitive screenshot artifacts.

Visual browser acceptance is an environment-driven check. If browser capture is unavailable or only the unauthenticated login can be rendered, report the page journey as NOT VERIFIED rather than claiming the full UI gate passed. Existing UI Playwright coverage is limited to a safe local capture script; it is not an end-to-end product acceptance suite.

## Known scope limitation

Sprint 8 does not replace every scoped component stylesheet with a shared component library, add a charting framework, or redesign every operational form. It establishes a coherent shell and foundational hierarchy while retaining existing routes and workflows. Follow-on UI work should extract shared accessible form/table/status components only when doing so does not alter sales or approval semantics.

## Sprint 8 deterministic browser check

`npm run test:ui` launches local Vite and Chromium, supplies only synthetic mocked API responses, checks page-level horizontal overflow and visible page headings at 1440, 1280, 768, and 390 pixels, and removes its temporary screenshots and browser profile afterward. It currently covers Home, Prospects, Prospect 360, Website Intelligence, Inbox, Pipeline, and Meetings. The UI run on 2026-10-10 passed all seven surfaces at all four widths with no page runtime exceptions. The Website Intelligence view is shared by Prospect 360 and the standalone route, presents structured summaries, evidence, technical findings, recommendations, and score factors, and asserts that raw JSON is not shown. It found a Prospect 360 overflow at tablet width; the tab strip now scrolls inside its own bounded region. A human visual inspection in the desktop browser could not be performed because the host reported that the Mac was locked. This synthetic check does not prove authenticated production API behavior, content quality, keyboard-only task completion, or deployment rendering.

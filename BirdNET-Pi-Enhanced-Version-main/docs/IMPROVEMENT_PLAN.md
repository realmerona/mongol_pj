# BirdNET-Pi Enhanced Version — UI/UX & Feature Improvement Plan

> **SUPERSEDED:** This was the initial audit/plan. The canonical, unified implementation
> plan is now [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md) (v2), which merges this
> document with the product-vision audit and adds the data-spine architecture, visit
> layer, and phased build order. Kept for reference.

*Drafted 2026-06-10. Grounded in a full review of the current codebase.*

---

## 1. What this project is (codebase review summary)

BirdNET-Pi Enhanced Version is a self-hosted, realtime acoustic bird classification station
for Raspberry Pi. The stack:

- **Capture/analysis (Python):** `scripts/birdnet_recording.sh` records continuously;
  `scripts/birdnet_analysis.py` watches `StreamData` via inotify and runs the BirdNET TFLite
  model (`scripts/utils/analysis.py`, `models.py`). Results flow through
  `scripts/utils/reporting.py` → SQLite (`scripts/birds.db`), extraction of clips +
  spectrogram PNGs, Apprise notifications (`utils/notifications.py`), BirdWeather, MQTT.
  Weather sync from Open-Meteo (`utils/weather.py`).
- **Web UI (PHP behind Caddy):** `homepage/index.php` wraps `homepage/views.php` in a
  full-page iframe; `views.php` renders the sidebar shell and includes per-view scripts:
  `overview.php`, `analytics.php`, `species.php`, `insights.php` (7 subviews), `play.php`
  (Recordings), `spectrogram.php`, `config.php`/`advanced.php` (Settings), plus embedded
  tools (Adminer, Tiny File Manager, GoTTY web terminal, phpSysInfo).
- **API:** `scripts/api.php` exposes a read-only `/api/v1/*` JSON API (system health,
  current weather, species list/search, analytics aggregates, recent detections, day
  timeline, eBird export preview).
- **Front-end assets:** local-only (no CDN): bundled Chart.js, custom audio player,
  `ui-helpers.js`, `dashboard-charts.js`, `timeline-view.js`, a 2,300-line `style.css`
  with CSS variables + dark theme.
- **Data:** single `detections` table (indexed on Date/Time/Sci_Name/Com_Name per
  `createdb.sh`), `weather` table, image cache table (`common.php`).

The Enhanced fork already modernized a lot (KPI cards, Chart.js analytics, Insights suite,
weather integration, dark mode). This plan is the next leap: fix the remaining structural
debt, make the daily experience feel like a polished native app, and add the features
birders keep wishing existed.

---

## 2. Who uses it and what they actually want

| Persona | Their question | Today's friction |
|---|---|---|
| **Daily checker** (phone, 30 sec) | "What's in my yard right now / today? Anything new?" | Overview is desktop-first; nav requires form submits; no installable app feel |
| **Curator / lister** | "Are these IDs right? What's my life list? Get it into eBird." | Correcting an ID is buried in Recordings; no way to review uncertain detections in bulk; no CSV export |
| **Analyst** | "Trends, seasonality, weather effects?" | Insights pages are heavy/slow on a Pi; charts scattered across Analytics vs Insights vs Stats |
| **Tinkerer / admin** | "Is my mic OK? Threshold right? Services healthy?" | Settings is one long form; no mic level test; restart-required changes aren't flagged |
| **Sharer / kiosk** | "Show this off at the nature center / kitchen tablet" | Kiosk view exists but is dated; no read-only guest mode toggle |

**The differentiating wish-list items** (things almost no competitor — Haikubox,
BirdWeather PUC, Merlin — offers in a self-hosted package): detection verification with
feedback into stats, locally-rare-bird alerts from eBird frequency data, a scrubbable
"day replay," a bird journal, and a year-end recap. All achievable with the current
PHP + SQLite + Python stack and data already collected.

---

## 3. Phase 1 — Foundation (architecture, performance, design system)

These unblock everything else and are mostly low-risk refactors.

### 1.1 Remove the iframe shell
`homepage/index.php` renders `views.php` inside a fixed full-page `<iframe>`. Navigation
reloads the whole iframe anyway, so the iframe provides nothing and costs a lot: broken
browser history/deep-linking/bookmarking, double scrollbars on some views, `window.top` /
`window.parent` hacks for theme sync and audio volume (`views.php`), and worse
accessibility. **Action:** make `index.php` render the shell directly (merge with
`views.php`), keep `?view=` URLs working, delete the cross-frame hacks.

### 1.2 Real navigation
The sidebar nav items are `<button type="submit">` elements inside a GET form
(`views.php`). Replace with real `<a href="?view=...">` links: middle-click/new-tab works,
URLs are honest, no hidden `subview` input juggling. Set a per-view `<title>` and add
breadcrumbs on detail pages.

### 1.3 Consolidate the design system
Every view carries large inline `<style>` blocks (`analytics.php` alone has ~600 lines,
full of `!important` fighting `style.css`). **Action:**
- Extract per-page CSS into `homepage/static/css/` modules loaded per view (still one
  HTTP/2 origin, all local).
- Define tokens once: spacing scale, radius, type scale, shadow levels, semantic colors
  (`--success/--warning/--danger`), confidence-badge colors (currently hardcoded hexes in
  `views.php` feed CSS).
- Replace emoji icons (🏠 📈 🐧 …) with a local inline-SVG sprite (crisp at all sizes,
  consistent across platforms, themeable via `currentColor`, screen-reader friendly).
  Keep it dependency-free — hand-pick ~30 icons (Lucide/Feather style, MIT) into one
  `icons.svg`.
- Add a hidden `?view=Styleguide` dev page showing every component (cards, badges,
  buttons, tables, toasts, skeletons) in light/dark.

### 1.4 Performance on the Pi
- **Cache expensive Insights queries.** `insights.php` (1,835 lines) recomputes
  seasonal/diversity/behavior aggregates on every load. Cache computed arrays as JSON in
  `/tmp/birdnet_insights_cache/` keyed by subview + `MAX(rowid)` of `detections`
  (invalidates automatically on new detection; weather subview keyed by latest weather
  row). Target: Insights loads in <1s warm instead of multi-second.
- **Bundle the Analytics API calls.** `dashboard-charts.js` fires ~8 separate
  `/api/v1/analytics/*` requests; add `/api/v1/analytics/bundle?days=N` returning them in
  one query pass.
- **HTTP caching:** far-future cache headers + content-hash query strings for static
  assets (the `?v=filemtime` pattern already exists — extend to all assets); gzip in the
  Caddyfile.
- Indexes are already good (`createdb.sh`); add `detections(Confidence)` partial index if
  the verification queue (3.3) filters on confidence ranges.

### 1.5 Accessibility + mobile base
- `aria-label` on all icon-only buttons; visible focus rings; `prefers-reduced-motion`
  guards on pulse/hover animations.
- Default theme from `prefers-color-scheme` when the user hasn't chosen (currently
  light unless localStorage says dark).
- **Bottom tab bar on mobile** (Overview / Live / Species / Recordings / More) — thumb
  reach beats the current hamburger drawer for the daily-checker persona.
- Touch targets ≥ 44px in feed lists and recording controls.

### 1.6 Make it a PWA
Add `manifest.webmanifest` + icons + a small service worker (cache-first for static
assets, network-first for pages/API). Users "install" their station on their phone home
screen; loads feel instant on LAN. Zero new dependencies — it's two small files.

---

## 4. Phase 2 — Core experience redesigns

### 2.1 Overview → a true "Today" dashboard
The Overview already has KPI cards and a species grid. Reshape it to answer the daily
checker's question in one glance, top to bottom:
1. **Hero strip:** current weather (exists in sidebar feed) + "Last heard: Carolina Wren,
   2 min ago, 94%" with photo and one-tap play of the clip.
2. **"What's new" callouts:** new species (first ever), first-of-season returns, and
   milestone toasts — the queries already exist in `insights.php` and
   `/api/v1/analytics/new_species`; surface them where people look daily.
3. KPI row (exists), then today's species grid with photos + hourly sparklines (exists
   via `ajax_chart_data`).
4. **System health pill** (exists on Tools via `ui-helpers.js` `loadSystemHealth`) — move
   a compact version into the header so a dead mic is noticed in hours, not days.

### 2.2 Unified Species Hub (one page per bird)
Species info is currently scattered: gallery in `species.php`, stats in `stats.php`,
daily chart in `todays_detections.php?comname=`, best recordings in `play.php`. Build a
single species detail route consolidating: photo header (image pipeline exists), key
stats (first/last heard, total, best confidence), **GitHub-style calendar heatmap** of
the year, hourly activity pattern, seasonal presence vs eBird expectation (logic exists
in `insights.php` + `get_seasonal_expected.py`), best + recent recordings inline, and
external links (Wikipedia / eBird / Xeno-canto search). Every species name anywhere in
the app links here.

### 2.3 Recordings Center
`play.php` (1,200 lines) works but is form-and-reload driven. Redesign as one filterable
view: species + date-range + confidence-range + verified-status filters; spectrogram
thumbnails (PNGs already generated next to clips); the custom audio player everywhere;
**bulk select** for delete/protect (both backend ops exist as single-file GET endpoints);
keyboard navigation (j/k to move, space to play, d to delete). Server-side pagination via
the existing query patterns.

### 2.4 Detection Verification Queue ⭐ (the killer feature)
A triage mode for data quality — the thing serious users wish every detector had:
- Queue = detections in an "uncertain" confidence band (configurable, e.g. 60–85%),
  newest first, one at a time: big spectrogram, audio (auto-plays), species photo, and
  actions **Confirm / Reassign / Delete** with keyboard shortcuts (Y / R / X, arrows).
- Reassign reuses the existing label-picker + rename flow in `play.php`
  (`changeDetection`).
- Schema: add nullable `Verified` column (`'yes'|'no'|NULL`) via the migration pattern in
  `update_birdnet_snippets.sh`; needs a small POST endpoint in `api.php` (first write
  endpoint — gate behind the existing `ensure_authenticated()`).
- Payoff loops: a "verified only" toggle on Analytics/Insights; per-species precision
  ("your Blue Jay IDs: 97% confirmed") on the Species Hub; suggested exclude-list
  candidates ("90% of your 'Common Nighthawk' IDs were rejected — exclude this species?")
  feeding the existing `exclude_species_list.txt` tooling.

### 2.5 Settings overhaul
`config.php` + `advanced.php` are long forms. Reorganize into tabs: **Station** (name,
location w/ privacy note), **Audio** (mic, gain, RTSP), **Model** (threshold, sensitivity,
species lists), **Integrations** (BirdWeather, MQTT, Flickr/Wikipedia images),
**Notifications**, **System**. Add: a settings search box; inline help text instead of
wiki links where possible; flag which fields require a service restart and offer one
"Apply & restart services" action (commands already whitelisted in `views.php`); test
buttons — "Send test notification" (exists, `send_test_notification.py`), plus **"Test
microphone"** (capture 3s via the existing recording tooling, show RMS level + mini
spectrogram so users can validate gain without SSH).

### 2.6 Global search / command palette
`Ctrl+K` (and a search icon on mobile): jump to species (uses existing
`/api/v1/species/search`), jump to views, find a setting. One small JS module; huge
perceived-quality win.

### 2.7 Unified "Live" view
Merge the floating live-audio panel, `spectrogram.php`, and recent detections into one
**Live** page: streaming spectrogram, audio controls, and a live ticker that overlays
detection labels as they happen (poll `/api/v1/detections/recent` every 15s — already
how the sidebar feed works). Add a full-screen mode: this becomes the "kitchen tablet /
nature center" display, replacing the dated Kiosk view with auto-rotating panels
(now hearing → today's top species → latest photo).

---

## 5. Phase 3 — New capabilities (all within the current stack)

### 3.1 Calendar heatmaps
GitHub-style year grid for overall activity and per species (SQL `GROUP BY Date` + CSS
grid; no new libs). Click a day → Day Replay.

### 3.2 Day Replay
`timeline-view.js` and `/api/v1/detections/timeline` (with 5-min clustering) already
exist. Polish into a scrubbable 24-hour bar: weather strip on top (hourly data exists),
detection clusters as colored blocks, click to hear the clip. "What happened at dawn
yesterday?" answered in 5 seconds.

### 3.3 Rare-bird & first-of-season alerts ⭐
`get_seasonal_expected.py` already pulls eBird expected-frequency by week. Use it at
report time (or in a periodic check) to flag detections that are **locally rare for the
current week** → "RARE" badge in the live feed + an opt-in Apprise alert ("Rare for your
area this week: Evening Grosbeak, 91%"). Same mechanism powers "first of season"
(species returning after N-week absence — `gone quiet`/`new arrivals` queries exist in
`insights.php`). No competitor's self-hosted product does this.

### 3.4 Bird Journal
A `notes` table (date, optional species, text). UI: "add note" on any day (Day Replay /
calendar) or species page; notes render in timelines and reports. Lets users record what
the mic can't ("pair nesting in the maple — confirmed visually"). Trivially cheap,
emotionally sticky.

### 3.5 Data export everywhere
- "Export CSV" button on any filtered list (Recordings, Species, Analytics date range) —
  a `format=csv` branch on existing queries.
- Life-list export (species, first/last date, total, best confidence).
- eBird export already rebuilt — add the verification filter (only export confirmed
  detections) once 2.4 lands.

### 3.6 Notification rules UI
Apprise supports 90+ services; the missing piece is a friendly rules screen: per-species
on/off, "only new/rare species," quiet hours, max N per species per day (partially exists
as config flags), daily-digest time. UI writes the same config the Python side
(`utils/notifications.py`) already reads, plus small rule checks there.

### 3.7 Guest mode & sharing
A "read-only guest access" toggle: unauthenticated visitors get dashboards/species/
recordings but no Tools (the `is_protected_view()` gate in `index.php` already does most
of this — formalize and document it). Plus a "share this detection" action that produces
a public-safe permalink (clip + spectrogram + species card).

### 3.8 "Your Year in Birds" recap
Spotify-Wrapped-style annual page from local data: total detections, species count, top
bird, rarest find, busiest day, dawn-chorus champion, month-by-month sparkline — shareable
as an image (render to canvas, download PNG). Pure fun, zero new infra, drives
word-of-mouth.

---

## 6. Phase 4 — Polish & first-run experience

- **Onboarding wizard:** first boot currently shows a red warning if lat/lon are unset
  (`views.php`). Replace with a 3-step guided setup: location (map-free lat/lon helper +
  privacy note), microphone check (3.5s test capture + level meter), notifications
  (optional). Each step uses existing backend pieces.
- **Empty states:** every chart/list needs a designed "no data yet" state for new
  installs ("Your station is listening — first birds usually appear within minutes").
- **Standardized toasts/errors:** extend `ui-helpers.js` `message()` into a toast system;
  use for all async actions (delete, verify, settings saved).
- **Loading skeletons** everywhere data is fetched (pattern exists in `ui-helpers.js`).
- **In-app explainers:** "ⓘ" on every analytic ("What is Shannon diversity?", "How is
  Yard Health scored?") — the eBird export page already proved this tooltip pattern.
- **Print/PDF stylesheet** for Reports (weekly report exists).
- **Testing/CI:** grow `tests/` beyond analysis/notifications; add PHP lint + a smoke
  test that hits every `?view=` and `/api/v1/*` route on a seeded test DB.

---

## 7. Prioritized roadmap

| Priority | Item | Impact | Effort |
|---|---|---|---|
| **Quick wins (do first)** | Real links instead of form-submit nav (1.2) | High | Low |
| | Drop the iframe (1.1) | High | Low-Med |
| | Insights query caching (1.4) | High (Pi speed) | Low |
| | Auto dark mode + a11y labels (1.5) | Med | Low |
| | CSV export buttons (3.5) | Med | Low |
| | System-health pill in header (2.1) | Med | Low |
| **Core wave** | Design-system consolidation + SVG icons (1.3) | High | Med |
| | Bottom mobile nav + PWA (1.5, 1.6) | High | Med |
| | "Today" dashboard reshape (2.1) | High | Med |
| | Species Hub (2.2) | High | Med |
| | Recordings Center (2.3) | High | Med-High |
| | **Verification Queue (2.4)** | **Very high — differentiator** | Med-High |
| **Feature wave** | Live view merge + kiosk 2.0 (2.7) | Med-High | Med |
| | Settings tabs + mic test (2.5) | Med-High | Med |
| | Rare-bird alerts (3.3) | **Very high — differentiator** | Med |
| | Calendar heatmaps + Day Replay polish (3.1, 3.2) | Med | Low-Med |
| | Command palette (2.6) | Med | Low |
| | Notification rules UI (3.6) | Med | Med |
| **Delight wave** | Bird Journal (3.4) | Med | Low |
| | Onboarding wizard + empty states (Phase 4) | High for new users | Med |
| | Guest mode / share links (3.7) | Med | Med |
| | Year in Birds recap (3.8) | Med (viral) | Med |

**Hard constraints honored throughout:** no CDNs or external runtime dependencies (the
Pi may be offline — everything stays locally bundled like Chart.js is today); SQLite +
PHP + Python stack unchanged; all heavy computation cached or pre-aggregated so a Pi 4
stays snappy; every external call (eBird, Open-Meteo, Wikipedia/Flickr) already exists in
the codebase — new features only reuse those pipelines.

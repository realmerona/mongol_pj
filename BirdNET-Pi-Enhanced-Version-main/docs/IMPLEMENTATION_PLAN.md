# BirdNET-Pi Enhanced Version — Unified Implementation Plan (v2)

*Drafted 2026-06-10. Supersedes `IMPROVEMENT_PLAN.md`. This is the merged plan: the
question-first product vision (Now / Timeline / Birds / Insights / Review / Settings),
code-grounded sequencing, and the synthesis features that emerged from combining both
audits — visit-level review, visit-based notifications, the learning station, and more.*

---

## 1. North star

> **A personal backyard bird observatory** that turns live AI detections into a
> beautiful, trustworthy, personal birding journal — and gets measurably smarter about
> *your* yard the longer it runs.

The app should answer, in order of how often users ask:

1. *What bird was that, just now?* → **Now**
2. *What happened today / yesterday?* → **Timeline**
3. *Tell me about this bird in my yard.* → **Birds**
4. *What changed? What's interesting?* → **Insights**
5. *Was that detection real? Can I trust my data?* → **Review**
6. *Is my station healthy? How do I configure it?* → **Settings / Station Doctor**

**Experience target (the "north-star screen"):**

> **Now Hearing: Carolina Wren** — 7:42 AM · 94% · light rain
> *This is your 12th Carolina Wren visit this week.* ▶ Play
>
> **Today's Story** — Quiet morning so far: 9 species, activity 28% below your 30-day
> average. One bird is new for the season.
>
> **Worth Reviewing** — Possible Barred Owl at 2:14 AM (76%). Rare for your region this
> week. Listen and confirm?

---

## 2. Hard constraints (honored in every phase)

- **Stack stays:** PHP + SQLite + Python + systemd + Caddy. No framework rewrite.
- **No CDNs / external runtime deps** — the Pi may be offline; everything bundled
  locally (as Chart.js already is).
- **Pi-class performance:** Now page < 1s warm on a Pi 4; no page > 1.5s warm. Heavy
  aggregates are cached, never recomputed per request.
- **Schema changes are additive only** — new tables, never altering `detections`
  (protects backup/restore, BirdWeather posting, and upstream merges). Migrations follow
  the existing `update_birdnet_snippets.sh` idempotent pattern.
- **License:** CC BY-NC-SA inherited from BirdNET — everything here improves the free,
  shared project; no commercial-product surface.

---

## 3. New information architecture

Question-first navigation replaces the technical-first nav:

| New section | Absorbs (current views) | Purpose |
|---|---|---|
| **Now** (default) | Overview, live audio panel, Spectrogram, Kiosk | Live home: hero detection, live spectrogram strip (tap → full-screen Live/kiosk mode), Today's Story, health pill |
| **Timeline** | Recordings (`play.php`), Todays Detections, Daily Charts | Scrubbable day replay built on visits; "All recordings" browser as a secondary tab |
| **Birds** | Species (`species.php`), Species Stats (`stats.php`) | Gallery + per-species detail pages (the personal bird encyclopedia) |
| **Insights** | Insights (`insights.php`), Analytics (`analytics.php`) | Plain-English intelligence cards; Analytics charts live on as a "Charts" tab |
| **Review** | *(new)* + the buried actions in `play.php` (change ID, delete) | Visit-level verification queue |
| **Settings** | Settings, Advanced, Tools, Services, System Controls | Tabbed: Station, Audio, Model & Species, Privacy, Notifications, Integrations, System. **Advanced Tools** tab keeps File Manager / Adminer / Web Terminal / raw service controls (de-emphasized, auth-gated as today) |

Rules:
- All nav items are real `<a href="?view=...">` links. Legacy `?view=` values 301/alias
  to new ones so bookmarks and the kiosk URL keep working.
- Every species name anywhere in the app links to its Birds detail page.
- Per-view `<title>`; breadcrumbs on detail pages.

---

## 4. The data spine (build once, everything consumes it)

Nearly every feature in this plan is a view over four primitives plus one bundle
endpoint. Build these first so each surface is rendering work, not query invention.

### 4.1 Visits (derived layer — the universal currency)

A **visit** = same `Sci_Name`, same day, successive detections ≤ N minutes apart
(default 5, configurable `VISIT_GAP_MINUTES`). This generalizes the clustering already
implemented in `/api/v1/detections/timeline` ([api.php](../scripts/api.php), 5-minute
windows, best-confidence per cluster).

- **v1:** pure query layer — a shared PHP function `get_visits($db, $filters)` in
  `common.php`, used by API and pages. No schema change.
- **v2 (when needed):** materialized `visits` cache table refreshed incrementally by
  `MAX(rowid)` watermark, for Pi Zero-class speed.
- Visit identity (for joins/links): `sci_name + date + first detection time`.
- Each visit exposes: species, start/end, detection count, max confidence, best clip
  `File_Name`, weather at start hour (join existing `weather` table).

**Why it's the spine:** notifications fire per visit (kills alert spam at the data
layer), Review judges per visit (one decision covers all member detections — 5–10×
less triage), Timeline renders visits, Today's Story counts visits, eBird export
estimates counts from visits.

### 4.2 `detection_reviews` (trust layer)

```sql
CREATE TABLE IF NOT EXISTS detection_reviews (
  id INTEGER PRIMARY KEY,
  file_name VARCHAR(100) NOT NULL UNIQUE,   -- join key; unique per detection in practice
  sci_name VARCHAR(100) NOT NULL,
  com_name VARCHAR(100) NOT NULL,
  date DATE NOT NULL,
  time TIME NOT NULL,
  status TEXT NOT NULL CHECK (status IN ('confirmed','false_positive','hidden','unsure')),
  reviewed_via TEXT,                         -- 'visit' | 'single'
  note TEXT,
  created_at TEXT DEFAULT (datetime('now','localtime'))
);
CREATE INDEX IF NOT EXISTS idx_reviews_file ON detection_reviews(file_name);
CREATE INDEX IF NOT EXISTS idx_reviews_sci_status ON detection_reviews(sci_name, status);
```

Reviewing a visit fans the status out to every member detection (`reviewed_via='visit'`).
`detections` is never altered.

### 4.3 `species_prefs` (one table powering favorites, muting, notify rules, crowns)

```sql
CREATE TABLE IF NOT EXISTS species_prefs (
  sci_name VARCHAR(100) PRIMARY KEY,
  com_name VARCHAR(100),
  favorite INTEGER NOT NULL DEFAULT 0,
  muted INTEGER NOT NULL DEFAULT 0,         -- suppress notifications, keep data
  notify_mode TEXT NOT NULL DEFAULT 'default',
    -- default | every_visit | first_daily | first_lifetime | rare_only | never
  custom_threshold FLOAT,                   -- NULL = global confidence threshold
  crowned_clip VARCHAR(100),                -- File_Name of user-chosen best recording
  updated_at TEXT DEFAULT (datetime('now','localtime'))
);
```

**Crowned clips auto-protect:** setting `crowned_clip` also appends the file (and its
`.png`) to `disk_check_exclude.txt` via the existing protect mechanism in
[play.php](../scripts/play.php) — best recordings survive disk purges with zero user
thought. Un-crowning removes the entry.

### 4.4 `notes` (journal layer — attaches to a day, a species, or a detection/visit)

```sql
CREATE TABLE IF NOT EXISTS notes (
  id INTEGER PRIMARY KEY,
  date DATE,                 -- nullable: day-level note
  sci_name VARCHAR(100),     -- nullable: species-level note
  file_name VARCHAR(100),    -- nullable: detection/visit-level note
  body TEXT NOT NULL,
  created_at TEXT DEFAULT (datetime('now','localtime'))
);
```

Notes render in Timeline, Birds detail, and Reports ("pair nesting in the maple —
confirmed visually").

### 4.5 API additions (`scripts/api.php`)

Read (GET):
- `/api/v1/dashboard/now` — single bundle: latest visit, today stats, story items,
  health summary, current weather, review-worthy flags. One round-trip for the Now page.
- `/api/v1/detections/visits?date=|days=|species=` — the visits layer.
- `/api/v1/species/detail?sci_name=` — everything the Birds detail page needs.
- `/api/v1/reviews/queue?filter=` — queue sections (see §7).
- `/api/v1/analytics/bundle?days=` — replaces ~8 separate dashboard calls.
- `/api/v1/station/doctor` — expanded health + diagnoses + suggested actions.
- `format=csv` branch on list endpoints (visits, species, detections).

Write (POST — first write endpoints; gated by existing `ensure_authenticated()`):
- `/api/v1/reviews` — `{visit | file_name, status, note}` with visit fan-out.
- `/api/v1/species/prefs` — favorite / mute / notify_mode / crown / threshold.
- `/api/v1/notes` — create/delete.

---

## 5. Phase 0 — Foundation (do first; everything depends on it)

**Goal:** honest URLs, one design system, Pi-fast pages. ~1–2 weeks of work.

1. **Remove the iframe shell.** Merge [index.php](../homepage/index.php) +
   [views.php](../homepage/views.php) into one direct-rendered layout. Keep `?view=`
   query routing. Delete the `window.top` / `window.parent` theme and audio hacks.
   *Why first:* species permalinks, review share-links, notification deep-links, and
   the PWA all require real URLs; every page built inside the iframe must be migrated
   later anyway.
2. **Real navigation.** Sidebar form-submit buttons → `<a>` links
   ([views.php:175-204](../homepage/views.php#L175-L204)). Middle-click, new-tab,
   bookmarks all work. Active state from URL (logic exists).
3. **Design system consolidation.** Extract per-page `<style>` blocks (analytics.php
   alone has ~600 lines with `!important` wars) into `homepage/static/css/` modules.
   Single token sheet: spacing, radius, type scale, shadows, semantic colors,
   confidence-badge colors (currently hardcoded hexes in the feed CSS). Local inline-SVG
   icon sprite (~30 MIT-licensed icons) replaces emoji nav icons. Hidden
   `?view=Styleguide` page renders every component in light/dark.
4. **Caching layer.** File-based JSON cache (`/tmp/birdnet_cache/`) keyed by
   `view + params + MAX(rowid) of detections` (weather views also key on latest weather
   row) — auto-invalidates on new detection. Apply to Insights subviews and the
   analytics bundle. Far-future cache headers + content-hash query strings for all
   static assets (extend the existing `?v=filemtime` pattern); gzip in the Caddyfile.
5. **Accessibility + theming base.** `aria-label` on icon buttons, visible focus rings,
   `prefers-reduced-motion` guards, default theme from `prefers-color-scheme` when user
   hasn't chosen, touch targets ≥ 44px.

**Done when:** no iframe; all nav is links; legacy URLs alias; Insights warm-loads
< 1.5s on Pi 4; styleguide page exists; Lighthouse a11y ≥ 90.

---

## 6. Phase 1 — The data spine

**Goal:** the four primitives + bundle endpoints, fully tested, before any new surface.

1. Migrations for `detection_reviews`, `species_prefs`, `notes` via the
   `update_birdnet_snippets.sh` idempotent pattern (`CREATE TABLE IF NOT EXISTS`).
2. `get_visits()` shared query function; port `/api/v1/detections/timeline` clustering
   to use it (single source of truth).
3. All API endpoints from §4.5, including the first auth-gated POST endpoints.
4. Seeded test database + smoke tests hitting every route; pytest coverage for the
   Python-side readers (notifications will consume `species_prefs` in Phase 4).

**Done when:** `/api/v1/dashboard/now` returns a complete payload < 500ms warm;
reviews fan out across visits correctly; crowning protects files from purge.

---

## 7. Phase 2 — Now, new navigation, Station Doctor

### 7.1 Navigation restructure
Ship the six-section IA (§3) with legacy aliases. Tools content moves to Settings →
Advanced Tools tab.

### 7.2 The Now page (new default view)
- **Now Hearing hero:** latest visit — bird photo (image pipeline exists), name +
  scientific name, confidence badge, time, weather at detection, context badge (*new
  today / new this season / first lifetime / rare / 12th visit this week*), audio
  player, spectrogram thumbnail. **Two actions only: ▶ Play and tap-through to detail.**
  A third contextual **Review this** button appears only when the detection is flagged
  review-worthy. (Glance here; decide in Review.)
- **Live strip:** compact live spectrogram + live audio toggle. Tap → **full-screen
  Live mode** with detection labels appearing in place as they fire (poll
  `detections/recent`, 15s) — this *is* the new kiosk display (auto-rotating: now
  hearing → today's top species → latest photo). Retires the dated Kiosk view.
- **Today's Story:** template-generated, **notability-gated** — only states deviations
  from this station's own baseline (volume vs 30-day average, new arrivals,
  first-of-season, unusual peak hour, rare visitor). On an unremarkable day it is one
  short line. No external AI; pure SQL over the spine + cache.
- **Today at a glance:** species grid with photos + hourly sparklines (exists via
  `ajax_chart_data` — restyle), recent visits list.
- **Health pill** in the header on every page (compact `loadSystemHealth` from
  [ui-helpers.js](../homepage/static/ui-helpers.js)) → links to Station Doctor.

### 7.3 Station Doctor (Settings → first tab, plus health-pill target)
- Expanded `/api/v1/station/doctor`: each check returns *status + plain-English
  diagnosis + suggested action*. Checks: recording service, analysis service, detection
  recency vs recording activity ("mic recording but nothing analyzed in 22 min"), disk
  (reuse `disk_usage.sh` thresholds), DB size, weather staleness, lat/lon set, password
  set, BirdWeather configured, notification test sent.
- **One-click fixes** mapped to the existing whitelisted commands
  ([views.php:664-704](../homepage/views.php#L664-L704)): restart recording / analysis /
  core services, sync weather, send test notification.
- **Test microphone:** 3s capture via existing recording tooling → RMS level meter +
  mini spectrogram. Validates gain without SSH.
- **Download support bundle:** wire up the existing `print_diagnostic_info.sh` /
  `dump_logs.sh` to a download button.

**Done when:** Now warm-loads < 1s on Pi 4; story only speaks when notable; a stopped
analysis service is visible on every page within 30s and fixable in two clicks.

---

## 8. Phase 3 — Review queue and Birds detail

### 8.1 Review (visit-level verification) ⭐ the trust feature
- **Queue sections:** first-lifetime species · region-rare this week (see §9.2) ·
  out-of-season · low-confidence band (configurable, default 60–85%) · species with
  historically poor precision (§9.3) · previously marked unsure.
- **Card = one visit:** big spectrogram of best clip, audio (auto-play optional),
  species photo, count ("4 detections, 7:12–7:15 AM"), confidence, **comparison strip:
  2–3 of your own previously-confirmed spectrograms of the same species** (verify by
  comparison — assets already exist as PNGs; teaches birding-by-ear with the user's own
  data).
- **Actions:** Confirm / Reassign (reuses the label-picker + rename flow from
  [play.php](../scripts/play.php) `changeDetection`) / Not this bird (false_positive) /
  Hide / Unsure. Keyboard: `Y / R / N / H / U`, arrows to navigate, space to replay.
  Progress: "12 of 48 visits reviewed."
- One decision fans out to all detections in the visit (`reviewed_via='visit'`).
- **Downstream:** "verified-only" toggle on Insights/Charts; reviewed false positives
  excluded from exports and story/insight generation; exclude-list suggestions ("90% of
  your Common Nighthawk IDs were rejected — exclude this species?") writing to the
  existing `exclude_species_list.txt` tooling.

### 8.2 Birds detail page (personal bird encyclopedia)
One route per species, consolidating what's scattered across `species.php`, `stats.php`,
`todays_detections.php?comname=`, `play.php`:
- Hero image + names + context badges; favorite ★ / mute 🔕 / notify mode (writes
  `species_prefs`).
- Key stats: first/last heard, total detections & visits, best confidence, station
  precision ("your Blue Jay IDs: 97% confirmed" — once review data exists).
- **GitHub-style calendar heatmap** of the year (SQL `GROUP BY Date` + CSS grid; click a
  day → Timeline). Hourly activity pattern. Seasonal presence vs eBird expectation
  (logic exists in `insights.php` + `get_seasonal_expected.py`).
- **Crowned best recording** (user-overridable crown action; auto-protected per §4.3),
  recent visits with players, notes field.
- External links (Wikipedia / eBird / Xeno-canto search) as secondary actions — the
  user's own yard data is the main event.

### 8.3 Timeline
- **Day Replay:** scrubbable 24-hour bar — weather strip on top (hourly data exists),
  visit blocks colored by species, click to play best clip. Builds on
  `timeline-view.js` + the visits endpoint. Swipe/arrow-key day navigation (pattern
  exists in views.php).
- **All recordings tab:** the power-user browser — filterable (species, date range,
  confidence, review status), spectrogram thumbnails, bulk select for delete/protect
  (backend ops exist as single-file endpoints), keyboard nav (j/k/space/d).

**Done when:** a 17-detection wren morning is one card, one listen, one keystroke;
every species name in the app links to its detail page; deleting/protecting works in
bulk.

---

## 9. Phase 4 — Intelligence (the learning station)

### 9.1 Insights v2 — plain-English cards
Rewrite Insights subviews as cards that each answer: *what happened · why it matters ·
the supporting data (mini chart) · what to explore next (link into Timeline/Birds)*.
Same notability gate as Today's Story. Existing computations (dawn chorus, nocturnal,
new arrivals, gone quiet, diversity, yard health, milestones) are kept — re-presented,
cached (Phase 0), and linked to underlying visits. Analytics' Chart.js dashboards
remain as the "Charts" tab with takeaway lines above each chart and click-through to
detections. Add compare mode (this week vs last, this year vs last). "ⓘ explain this
metric" tooltips (pattern proven on the eBird page).

### 9.2 Two-axis rarity ⭐
- **Yard-rare:** first in N days / few lifetime detections (queries exist in
  `insights.php`).
- **Region-rare:** eBird expected weekly frequency via the existing
  `get_seasonal_expected.py` cache — "rare for your area this week."
Badges in feeds/cards, queue routing (§8.1), and opt-in Apprise alert ("Rare for your
region: Evening Grosbeak, 91%"). Evaluated at report time in
`utils/reporting.py` / notifier, and on render in the API.

### 9.3 Per-species precision → adaptive behavior ⭐
From review history: `precision = confirmed / (confirmed + false_positive)` per species
(minimum sample size, e.g. n ≥ 10).
- Surface on Birds detail and Review.
- **Auto-trust** high-precision species (skip review queue routing).
- **Auto-route** low-precision species to Review regardless of confidence.
- Suggest per-species `custom_threshold` and exclude-list candidates.
No ML, no new infra — SQL over `detection_reviews`. This is the "station that learns
your yard" differentiator.

### 9.4 Notifications v2 (consume the spine)
- **Per-visit, not per-detection:** the notifier (Python `utils/notifications.py`,
  which already tracks `species_last_notified`) gains visit-gap logic — one notification
  per visit. Kills spam at the data layer.
- **`species_prefs.notify_mode` honored:** every_visit / first_daily / first_lifetime /
  rare_only / never, plus mute.
- **Global rules UI** (Settings → Notifications): quiet hours, daily digest time,
  morning summary / evening recap toggles (weekly report exists — add daily variants),
  rare-bird alerts (§9.2), **cadence-break alerts** ("Your cardinal usually visits every
  morning; not heard in 3 days" — anomaly on visit cadence, plain SQL).
- Test buttons per channel (send_test_notification.py exists).

### 9.5 eBird Checklist Assistant
Upgrade the rebuilt exporter (`ebird.php`): pre-export review screen (detections by
hour, low-confidence flags, false-positive exclusions automatic, region-rare flags
"eBird will ask for documentation"), per-species include/exclude checkboxes, saved
observer/protocol defaults, visit-informed count estimates, "ready to export" status
via the existing `/api/v1/exports/ebird/preview`.

**Done when:** notifications are things users *want* to read; rare flags match eBird
expectations; precision stats visibly change queue routing; exports are clean.

---

## 10. Phase 5 — Companion polish

1. **PWA:** `manifest.webmanifest` + icons + small service worker (cache-first static,
   network-first pages/API). Installable on the phone home screen; notification links
   deep-link into the app. (Enabled by Phase 0's honest URLs.)
2. **Bottom tab bar on mobile:** Now · Timeline · Birds · Review · More.
3. **Onboarding checklist** (replaces the red lat/lon warning in views.php): set
   password → set location (privacy note) → mic test (§7.3) → confirm first detection →
   notifications (optional) → BirdWeather (optional). Persistent "Finish setting up your
   station" card with completion score until done. Plus "what to expect in your first
   24 hours" explainer and per-Pi-model recommended settings.
4. **Privacy center** (Settings tab): plain-language explanation of the human-sound
   filtering that already exists in `utils/analysis.py`, current privacy threshold,
   clip-retention controls ("delete clips older than X days"), "hide location in
   share exports," local-only mode summary.
5. **Guest mode & share links:** formalize the existing `is_protected_view()` gate into
   a "read-only guest access" toggle (dashboards/Birds/Timeline public; Review/Settings
   authenticated). "Share this detection" → public-safe permalink (clip + spectrogram +
   species card, location hidden if privacy says so).
6. **Command palette:** `Ctrl+K` — jump to species (uses `/api/v1/species/search`),
   views, settings.
7. **"Your Year in Birds"** annual recap: totals, top bird, rarest find, busiest day,
   dawn-chorus champion, month sparklines — rendered to canvas, downloadable PNG. All
   local data; pure delight + word-of-mouth.
8. **Polish sweep:** designed empty states for every view ("Your station is listening —
   first birds usually appear within minutes"), standardized toast system (extend
   `ui-helpers.js message()`), loading skeletons everywhere (pattern exists), print
   stylesheet for Reports.

---

## 11. Cross-cutting standards

- **Performance budgets:** Now < 1s warm / < 2.5s cold (Pi 4); any API endpoint
  < 500ms warm; cache hit-rate visible in a debug header.
- **Every async action:** loading state → success toast / failure message with retry.
  Every destructive action: confirm dialog (dialog-polyfill already bundled).
- **Empty / error / loading states** are part of each feature's definition of done.
- **Testing:** seeded demo DB (also powers the styleguide and screenshots); route smoke
  tests (every `?view=` + every API route); pytest for visits math, notification rules,
  rarity flags, precision calc; PHP lint in CI.
- **Docs:** README screenshot refresh per phase; in-app "ⓘ" methodology notes.

---

## 12. Risks & mitigations

| Risk | Mitigation |
|---|---|
| Visit grouping feels wrong for some species (flocks, counter-singing males) | Configurable gap; show member detections on expand; visits are derived — raw data untouched |
| `File_Name` uniqueness assumption breaks | Verified by existing delete-by-File_Name logic; add UNIQUE index on `detection_reviews.file_name` and handle conflicts gracefully |
| Today's Story becomes wallpaper | Notability gate is a hard rule, reviewed in QA with the seeded DB's "boring day" fixture |
| Pi Zero 2 too slow for visits queries | Materialized visits cache table (v2 of §4.1) behind the same function signature |
| First write endpoints expand attack surface | Auth-gated via existing `ensure_authenticated()`; POST-only; CSRF token; no shell execution in any new endpoint |
| Nav restructure breaks bookmarks/kiosks | Legacy `?view=` aliases maintained indefinitely |
| Scope creep | The spine (Phase 1) is the contract: new feature ideas must consume existing primitives or wait |

---

## 13. Build order summary

```
Phase 0  Foundation        iframe out · real links · design system · caching · a11y
Phase 1  Data spine        visits · reviews · species_prefs · notes · bundle APIs
Phase 2  Now               new nav · Now Hearing · Today's Story · Live mode · Station Doctor
Phase 3  Trust & depth     visit-level Review · Birds detail · Timeline/Day Replay
Phase 4  Intelligence      Insights v2 · two-axis rarity · learning station · notifications v2 · eBird assistant
Phase 5  Companion         PWA · bottom nav · onboarding · privacy center · guest mode · palette · Year in Birds
```

Each phase ships independently and leaves the app fully working. The spine is the
contract that keeps the scope honest: every surface is a view over visits, reviews,
prefs, and notes.

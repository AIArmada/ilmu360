# ilmu360° — One-Page Executive Summary (Rewritten)

Date: 2026-04-26  
Method: Repository-first analysis (`routes`, `models`, `services`, `tests`, `configs`, docs) + explicit founder hypothesis exploration.

Update note: refreshed on 2026-04-28 to reflect stronger source/reference modeling across the product.

## What it is

ilmu360° is a trust-layer and coordination platform for Islamic knowledge gatherings: it helps people discover majlis, helps organizers publish and manage them, and helps moderators keep quality high.

## What it solves (core)

### 1) Discovery blind spot
Many people only attend what they already know (their usual mosque/surau circle) because discoverability is fragmented.

### 2) Coordination blind spot
Mosques/surau may have infrastructure, but event operations are often manual, local, and disconnected.

### 3) Trust blind spot
Without structure, attendees struggle to evaluate event relevance, freshness, and credibility.

### 4) Reach blind spot
Without strong mobile and notification loops, good events stay invisible beyond immediate communities.

## Evidence in repository

- Public discovery/search routes and pages: `routes/web.php`, `/majlis`, `/institusi`, `/penceramah`, `/rujukan`  
- Rich API discovery + catalogs + follows/saved searches/notifications: `routes/api.php`  
- Reference/source modeling now supports root books plus child parts (for example jilid/bahagian/volume), with family-aware search and event linking: `app/Models/Reference.php`, `SearchController.php`, `EventSearchService.php`  
- Event quality controls (moderation/review/report triage): admin API controllers + Filament resources + MCP tools  
- Mobile client readiness signals: `/api/v1/forms/mobile-telemetry`, `/api/v1/mobile/telemetry/events` in `routes/api.php`  
- Multilingual-at-event-data model: language catalogs and event language attributes (`CatalogController`, `Event` language handling)

## Evidence from live public messaging (`/tentang-kami`)

- Explicit problem statement: people miss majlis because info does not reach their phone in time, not because they do not care.
- Explicit anti-fragmentation framing: posters/hebahan are “berselerak” across channels and need one reference point.
- Explicit social-media pain framing: scattered posts, last-minute speaker/venue changes, and weak update propagation.
- Explicit infrastructure framing: mosque/surau facilities already exist; missing layer is coordinated information visibility.
- Explicit long-term impact framing: better information flow can re-activate underused surau and community participation.
- Explicit feature promises on-page: verified organizer/account framing, verification badges, public reporting, nearby discovery, reminder/notification behavior, and calendar/maps export assistance.

Reference evidence captured from page snapshot: `https://ilmu360.test/tentang-kami` (sections such as “Punca Rantaian Ini Bermasalah”, “Apa Kerja Kami Disini”, “Dampak Jangka Panjang”).

## Cultural change potential (go beyond the box)

This is the highest-upside framing:

1. **From “my local only” to “knowledge network mindset.”**  
	People can discover beyond one neighborhood mosque/surau and build broader learning paths.

2. **From isolated venues to connected civil infrastructure.**  
	Large existing mosque/surau infrastructure can become discoverable, coordinated community nodes for education, social cohesion, and ethical local uplift.

3. **From passive attendance to active invitation culture.**  
	Discovery + sharing + reminders can normalize “ajak orang ke majlis ilmu” as a repeat social behavior.

4. **From language barriers to multilingual access.**  
	Event-level language metadata enables broader matching by audience language, increasing reach across diverse communities.

5. **From one-off events to continuous habit loops.**  
	Mobile notifications, saved searches, follows, and calendars can turn majlis attendance into a sustained lifestyle rhythm.

6. **From underused assets to measurable societal return.**  
	If infrastructure is already there, the marginal unlock is better discovery, scheduling, trust, and attendance continuity.

## Spiritual and social value framing

ilmu360° is not only a product workflow; it can be an **amal multiplier**:

- helping one person find a majlis can create long-tail benefit in their practice and character  
- bringing friends/family/strangers to beneficial knowledge can compound into ongoing social good  
- if discovery and participation scale, potential impact scales from individual pahala/berkat narratives to community-wide transformation

> Note: spiritual reward is a faith-domain outcome and cannot be quantified by software metrics alone; the platform can only increase opportunity and access.

## Strategic bet

Turn ilmu360° from “event discovery app” into **knowledge-mobility infrastructure** for the ummah:

- discovery + trust + moderation (already strong)
- knowledge-source structure that can distinguish a whole book from a specific part/volume
- organizer operating workflows (needs simplification)
- mobile engagement loops (high leverage)
- recurring participation and invitation culture (behavioral moat)

## What to fix first

**Simplify one canonical organizer and attendee journey that reliably increases attendance quality and repeat participation.**

If that loop works, everything else (retention, monetization, defensibility) becomes much easier.

## 7-day focus

1. Reframe product narrative in all docs/UI around “knowledge mobility + infrastructure activation.”  
2. Prioritize friction removal in submit/publish/moderate flow.  
3. Build and track one hard KPI: repeat attendance driven by discovery + reminders.

## Brutal truth

ilmu360° can become culturally significant — but only if it proves it changes real behavior (not just lists events beautifully).

## Confidence

Medium-High for platform capability and architecture; Medium for societal-scale impact until behavior change is measured in production.
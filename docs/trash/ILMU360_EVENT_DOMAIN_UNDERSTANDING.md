# ilmu360° Event Domain Understanding

Updated: March 10, 2026  
Audience: Product, design, engineering, and AI agents working on event modeling and event-submission architecture.

---

## 1. Purpose Of This Document

This document records the current domain understanding of what a complete event means in ilmu360°.

It is intentionally based on:
- the actual public submit flow at `/hantar-majlis`
- the field semantics encoded in the current submit form
- the live user-facing structure of the wizard and review step
- explicit domain correction from the product owner about how advanced events behave in real majlis ilmu context

This document is not a database proposal.

This document is not a summary of the current implementation shortcuts.

This document is the domain anchor to use before designing future event architecture, especially advanced events.

---

## 2. Core Conclusion

In ilmu360°, an event is not merely a scheduled slot.

A complete event is a publishable Islamic knowledge or community unit with its own:
- identity
- timing meaning
- organizer meaning
- audience meaning
- location meaning
- teacher meaning
- knowledge context
- moderation context

This is critical because in ilmu360°, a child session under a larger program can itself be a complete event.

If a child session can differ in:
- title
- speakers
- timing
- space
- references
- description
- tags

then it is not just an occurrence in the thin technical sense.

It is a first-class child event under a parent event or umbrella program.

---

## 3. The Canonical Source Of Truth

The canonical baseline for what an event must be is the public submit flow at `/hantar-majlis`.

This is important for two reasons:

1. It already expresses the minimum product contract for a publishable ilmu360° event.
2. It is structured around real user comprehension, moderation needs, and public discovery needs.

Therefore:
- normal events define the minimum acceptable event contract
- advanced events must extend that contract, not bypass it

The mistake to avoid is treating advanced events as merely more complicated scheduling data.

In ilmu360°, advanced events are often umbrella programs with child events that are themselves event-complete.

---

## 4. What A Complete Event Means In ilmu360°

## 4.1 Identity

Every event must have a meaningful public identity.

At minimum, this includes:
- event title
- event type
- description or narrative context

The event title is not just a label.
It is a public-facing identity that users search, share, recognize, and remember.

The event type is not just a filter.
It places the event into a real Islamic or community activity category.

Current type groupings in the submit flow reflect this:
- Ilmu
- Ibadah
- Zikir & Doa
- Tilawah
- Komuniti
- Lain-lain

Examples:
- Kuliah / Ceramah
- Kelas / Daurah
- Forum
- Khutbah Jumaat
- Qiamullail
- Bacaan Yasin
- Iftar

This means an event in ilmu360° is understood first as a meaningful kind of gathering, not merely as a record with a date.

## 4.2 Time Identity

Every event must have a meaningful time identity.

In ilmu360°, time is not merely a timestamp.
It is often expressed in religiously meaningful language such as:
- Selepas Subuh
- Selepas Zuhur
- Sebelum Jumaat
- Selepas Jumaat
- Selepas Maghrib
- Selepas Tarawih

The current submit form proves that ilmu360° treats timing in two ways:
- exact clock time
- prayer-relative time

This has direct domain implications:
- prayer-relative timing is first-class, not an edge case
- event timing can be socially and religiously understood before it is technically normalized
- the chosen time expression is part of the event identity itself

Therefore, time in ilmu360° is not only about scheduling. It is part of how users comprehend the event.

## 4.3 Access And Participation Identity

Every event must answer how people participate.

The submit form requires or derives:
- format: physical, online, hybrid
- visibility: public, unlisted, private
- live link for online or hybrid participation

This means the event contract includes attendance mode as core meaning.

Format is not decorative. It changes:
- location requirements
- link requirements
- user expectations
- discoverability behavior

## 4.4 Audience Identity

Every event must answer who it is intended for.

The current public event contract includes:
- gender restriction
- age group
- children allowed
- Muslim-only suitability
- language

These are not side details.

They are critical decision-making fields for ilmu360° users because they help determine:
- suitability
- accessibility
- household attendance feasibility
- cultural and religious relevance

This means a complete event in ilmu360° includes explicit audience targeting.

## 4.5 Knowledge Identity

For ilmu360°, many events are knowledge-bearing units.

That means a complete event often needs to express what knowledge area it belongs to.

The public submit flow models this through:
- domain tags
- discipline tags
- source tags
- issue tags
- references

This is not generic tagging.

It is a deliberate knowledge structure intended to help users understand:
- what field of knowledge this event belongs to
- which sources it draws from
- what issues or themes it discusses
- which books or references are being taught or cited

This makes references and knowledge taxonomy central to the domain, especially for educational and lecture-based events.

## 4.6 Organizer Identity

Every event must answer who is responsible for it.

The public submit flow distinguishes:
- institution organizer
- speaker organizer

Organizer is a core identity field because it affects:
- trust
- moderation context
- ownership
- public attribution
- how users interpret the nature of the event

Organizer is not the same as venue or location.

The organizer answers who is behind the event, not necessarily where it happens.

## 4.7 Place Identity

Every physical or hybrid event must answer where it takes place.

The current event contract already recognizes multiple layers of place:
- institution
- venue
- space within an institution

This is especially important in ilmu360° because a session in:
- Dewan Utama
- Surau
- Bilik Kuliah
- ruang seminar tertentu

is a meaningfully different attendee experience even when the organizer is the same.

Therefore, place in this domain is not only geolocation. It is also contextual place identity.

## 4.8 Teacher Or Speaker Identity

Speakers are required in the current public flow.

That is not incidental. It means many ilmu360° events are teacher- or speaker-centric.

The list of speakers often materially defines what the event is.

If the speaker changes, the event may become meaningfully different, even if the umbrella program remains the same.

This is one of the strongest reasons advanced child sessions must often be modeled as child events rather than as anonymous occurrences.

## 4.8.1 Speaker-First But Not Speaker-Only

ilmu360° should remain speaker-first, because speakers are still one of the major core entities of the product.

That means:
- `Speaker` remains a first-class reusable profile entity
- speakers remain a major public discovery surface
- speakers are still expected in most events

However, ilmu360° events are not speaker-only.

Some events may also need to represent other event-level people roles such as:
- moderator
- person in charge
- imam
- khatib
- bilal

These roles should not become separate top-level public entity types by default.

Instead, the correct pattern is:
- keep `Speaker` as the reusable public person profile
- model event-specific roles at the event participation level

This allows ilmu360° to express:
- a speaker who is also moderator
- an imam who is a known speaker profile
- a bilal or person in charge who does not need a full public speaker profile

So the correct future direction is:
- `Speaker` remains the profile entity
- `EventKeyPerson` represents event involvement
- `EventKeyPersonRole` represents the role played in that event

This preserves speaker prominence without forcing every event-linked person into the speaker concept.

## 4.9 Media Identity

Media is optional in the public flow, but it still matters for public representation.

The current form supports:
- poster
- gallery
- AI extraction from poster, image, or PDF

Media is not what makes an event complete, but it can affect:
- recognition
- trust
- promotion
- preview quality

## 4.10 Submission And Moderation Identity

ilmu360° events are not raw content inserts.

They are moderated publication units.

The public flow includes:
- submitter information
- note to moderator
- review preview before submission
- moderation expectation messaging

This means a complete event in ilmu360° is understood not only as user content, but as something that must survive editorial and moderation review.

---

## 5. Field-By-Field Interpretation Of The Public Event Contract

This section treats the current `/hantar-majlis` flow as the canonical baseline for minimum event completeness.

## 5.1 Step 1: Maklumat Majlis

### Event Type
Meaning:
- what kind of Islamic or community gathering this is
- helps define user expectations and public search behavior

Why it matters:
- strongly shapes event identity
- not merely classification for backend filtering

### Title
Meaning:
- the main public identity of the event
- what users recognize, share, and search for

Why it matters:
- title is one of the strongest signals of whether two sessions are actually the same event or different child events

### Description
Meaning:
- narrative context
- subject matter
- what will be covered

Why it matters:
- often necessary to distinguish one child session from another within the same umbrella program

### Event Date
Meaning:
- the calendar identity of the event

Why it matters:
- a child session with a different date is already a distinct attendance unit
- but in ilmu360°, date difference alone does not necessarily create a separate child event; identity divergence matters too

### Prayer Time Or Custom Time
Meaning:
- the meaningful start expression of the event

Why it matters:
- this is part of how users mentally model attendance
- prayer-relative timing is particularly important in ilmu360°

### End Time
Meaning:
- optional expected completion time

Why it matters:
- useful operationally, but not always required to define the event identity

### Event Format
Meaning:
- physical, online, or hybrid

Why it matters:
- format changes location and participation expectations

### Visibility
Meaning:
- who can discover or access the event

Why it matters:
- affects publication and dissemination, not just backend access control

### Event URL
Meaning:
- canonical or supporting public link for more information

Why it matters:
- often helpful but not fundamental to event identity

### Live URL
Meaning:
- participation endpoint for online or hybrid events

Why it matters:
- can be essential for actual attendance

### Gender
Meaning:
- attendance suitability rule by gender

Why it matters:
- first-class access information in this domain

### Age Group
Meaning:
- who the event is intended for by age suitability

Why it matters:
- affects family attendance and interpretation of the event

### Children Allowed
Meaning:
- whether bringing children is appropriate

Why it matters:
- strong practical attendance signal
- partially derived from age group, but still meaningful as explicit event policy

### Muslim-Only
Meaning:
- whether attendance is restricted to Muslims

Why it matters:
- not cosmetic; directly affects access and expectation

### Language
Meaning:
- the language of delivery

Why it matters:
- especially important for multilingual Malaysian audience discovery and suitability

## 5.2 Step 2: Kategori & Bidang

### Domain Tags
Meaning:
- broad knowledge category

### Discipline Tags
Meaning:
- more specific field of study or subject matter

### Source Tags
Meaning:
- primary source tradition or textual basis

### Issue Tags
Meaning:
- public themes or topics for discovery

### References
Meaning:
- the books or materials actually being referenced

Why this whole step matters:
- this makes many ilmu360° events content-bearing teaching units, not generic meetups
- references can absolutely differ between child sessions under the same parent event

## 5.3 Step 3: Penganjur & Lokasi

### Organizer Type
Meaning:
- whether the event is fundamentally anchored to an institution or a speaker

### Organizer Institution / Organizer Speaker
Meaning:
- who owns or represents the event

### Location Same As Institution
Meaning:
- whether organizer place and event place are identical

### Location Type
Meaning:
- whether the place identity is institution-based or venue-based

### Location Institution / Venue
Meaning:
- where attendees go

### Space
Meaning:
- finer-grained place identity inside an institution

Why this step matters for advanced events:
- child sessions can share the same organizer while differing in space and location details
- that is a strong sign that advanced child sessions must be event-complete enough to carry their own place identity

## 5.4 Step 4: Penceramah & Media

### Speakers
Meaning:
- the actual teaching or presenting individuals

Why it matters:
- if speakers differ, the child session may be meaningfully different from other sessions under the same parent

### Poster
Meaning:
- primary public representation asset

### Gallery
Meaning:
- supporting visual context

## 5.5 Step 5: Semak & Hantar

### Review Preview
Meaning:
- the system already treats the form as a structured publication object with coherent sections

### Submitter Information
Meaning:
- provenance of submission

### Moderator Note
Meaning:
- editorial or moderation context

Why this matters:
- future advanced event architecture must preserve moderation clarity and not collapse multiple child sessions into a moderation-opaque blob

---

## 6. Simple Event Versus Advanced Event In ilmu360°

## 6.1 What A Simple Event Is

A simple event is an event where the public event contract maps to a single event unit.

This means there is effectively one public identity with one schedule expression and one main content unit.

Examples:
- one-off kuliah
- one-off forum
- one-off qiamullail session
- one-off iftar program

## 6.2 What An Advanced Event Is

An advanced event is not simply a more complicated schedule.

In ilmu360°, an advanced event is often an umbrella program containing multiple child event units.

Examples:
- a daurah with multiple topic segments
- a seminar series with weekly sessions
- a conference day with separate session tracks
- a course where each session has different speakers or references

The important rule is this:

If each child session can differ in major event-defining fields, then advanced means parent event plus child events, not parent event plus thin occurrences.

---

## 7. Correct Parent-Child Understanding For ilmu360°

## 7.1 Parent Event

The parent event is the umbrella program identity.

It represents:
- the main title or umbrella title
- the main organizer
- the umbrella narrative
- shared publication and moderation ownership
- default shared settings

Examples:
- Daurah Tafsir Surah Al-Kahfi
- Seminar Fiqh Wanita 2026
- Siri Halaqah Aqidah Ahli Sunnah

## 7.2 Child Event

The child event is a first-class session under that umbrella.

It may carry its own:
- title
- subtitle or topic focus
- date and time
- speakers
- space
- references
- tags
- media
- session-specific description
- session-specific registration behavior

This is the actual unit users may:
- discover
- save
- register for
- share
- attend

## 7.3 Why Child Event Is Not Just An Occurrence

An occurrence is too weak a concept if the child unit can vary materially in event-defining fields.

In ilmu360°, many child sessions are not merely repeated timestamps. They are distinct teaching or program units within one umbrella.

Therefore, the right model for ilmu360° is:
- simple event: one standalone event unit
- advanced event: one parent event containing child events

---

## 8. What Should Usually Stay On The Parent Event

These are the fields most likely to define the umbrella identity.

### Strong Parent Candidates
- organizer ownership
- umbrella title or main title
- parent-level visibility default
- parent-level moderation ownership
- umbrella summary
- parent-level poster or branding
- parent-level default audience assumptions
- parent-level default category assumptions

These are the fields that usually answer:
- what larger program is this
- who owns it
- what public umbrella identity does it belong to

---

## 9. What Child Events Must Be Able To Override

Based on the actual ilmu360° event contract, child events should be able to override almost all event-complete fields except those that must remain anchored to the umbrella program by product decision.

### Strong Child Override Candidates
- child title
- child description
- child date
- child timing expression
- child start and end time
- child format if necessary
- child location
- child space
- child speakers
- child references
- child taxonomy and issue tags
- child poster or session media
- child audience suitability
- child event URL or live URL
- child registration settings

This is the domain reason advanced events cannot be modeled as just recurrence or occurrence rows.

---

## 10. The Right Rule For Event Completeness

Use this rule going forward:

An event is complete in ilmu360° when it can stand on its own as a moderated, discoverable, attendable, and understandable unit.

If a child session meets that bar, it is a child event.

If it does not meet that bar and only differs by date or status, it may be a simpler occurrence-like record.

But for ilmu360°, many advanced sessions will meet that bar.

---

## 11. Decision Framework For Future Modeling

Before deciding whether something is a parent event with child events, ask these questions:

### 11.1 Title Divergence
- Does the child session need its own meaningful title?

If yes, treat it as a child event candidate.

### 11.2 Speaker Divergence
- Can the speaker set differ materially from sibling sessions?

If yes, treat it as a child event candidate.

### 11.3 Knowledge Divergence
- Can the references, subject focus, or taxonomy differ materially?

If yes, treat it as a child event candidate.

### 11.4 Location Divergence
- Can the room, space, or place differ materially?

If yes, treat it as a child event candidate.

### 11.5 Attendance Divergence
- Would a user reasonably want to save, register, or attend one session but not another?

If yes, treat it as a child event candidate.

### 11.6 Public Discovery Divergence
- Would a user benefit from discovering a child unit directly rather than only the umbrella program?

If yes, treat it as a child event candidate.

---

## 12. Revised Understanding Of “Advanced Event”

In ilmu360°, “advanced event” should mean one or both of the following:
- an umbrella event with child events
- an event with inheritance and override behavior between parent and child units

It should not be reduced to:
- a single event with many datetimes
- a recurrence rule only
- a session table that cannot carry event-complete identity

That would be too generic and would lose important domain truth.

---

## 13. Implication For Future Architecture Work

The next architecture should be designed from these principles:

1. Normal event remains the canonical minimum event contract.
2. Advanced event extends that contract through parent-child event structure.
3. Child events must be able to become event-complete units.
4. Inheritance should reduce duplication, but not erase child identity.
5. Moderation and discovery must remain clear at both parent and child levels.

This means the correct future question is not:
- how do we add recurrence to events?

The correct question is:
- how do we model umbrella programs and child events while preserving the complete ilmu360° event contract?

---

## 14. Final Working Definition

For ilmu360°, a complete event is:

a moderated, discoverable, organizer-owned Islamic knowledge or community unit with its own meaningful title, timing, audience, place, teachers, and knowledge context.

And for advanced events:

an advanced event is often an umbrella parent event whose child sessions may themselves be complete events that inherit some parent fields while overriding most of the rest.

That is the understanding future design work should start from.
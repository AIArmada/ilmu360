# Browse events

The event directory at `/majlis` lists approved public events and lets a visitor filter them by a search query, including a state where the query matches nothing.

## Sub-features

- `browse-open` opens the event directory from the site navigation or a direct URL.
- `browse-search` filters events by the `search` query parameter.
- `browse-match` shows the matching event.
- `browse-nonmatch` excludes events that do not match.
- `browse-clear` returns the unfiltered directory when the query is removed.

## How to get to it (user POV)

- Open `/majlis` directly, or choose `Majlis` in the site navigation.
- Type into the search field labelled by the directory and press Enter, or open `/majlis?search=<query>`.
- Clear the search field to return to the full list.

## Driving it with Pest agent

Preconditions:

- `.claude/skills/verify/bin/doctor.sh` prints `READY`.
- The public events `Zulfa Retreat` and `Qamar Gathering` exist, approved and public.
- Run the probe from the repository root.

- **Open a filtered directory.** Open `/majlis?search=Zulfa+Retreat`. Run `visit("/majlis?search=Zulfa+Retreat", ["timeout" => 30000])`. The directory heading `Temui majlis ilmu yang` is visible.
- **See the match.** Assert `Zulfa Retreat` is rendered. Run `->assertSee("Temui majlis ilmu yang")->assertSee("Zulfa Retreat")`. The matching card appears.
- **See the exclusion.** Assert `Qamar Gathering` is not rendered. Run `->assertDontSee("Qamar Gathering")`. The non-matching event is filtered out.
- **Proof.** Capture the result viewport. Run `->screenshot(fullPage: false, filename: "verify-browse")` and copy it to `storage/verify/browse-events/<timestamp>.png`.

Full probe:

```bash
vendor/bin/pest --agent='require base_path(".claude/skills/verify/bin/boot.php"); \Pest\Browser\Playwright\Playwright::setTimeout(30000); withGlobalOwnerContext(function (): void { foreach (["Zulfa Retreat", "Qamar Gathering"] as $title) { \App\Models\Event::factory()->create(["title" => $title, "status" => "approved", "visibility" => "public", "published_at" => now(), "starts_at" => now()->addDay(), "ends_at" => now()->addDay()->addHour()]); } }); visit("/majlis?search=Zulfa+Retreat", ["timeout" => 30000])->assertSee("Temui majlis ilmu yang")->assertSee("Zulfa Retreat")->assertDontSee("Qamar Gathering")->screenshot(fullPage: false, filename: "verify-browse");'
```

## Gotchas

- The tested path is a direct query URL. Typing into `input-id="event-search"` and pressing Enter also works interactively, but the URL is the stable handle for an automated probe.
- Both fixture events share the same timestamps and status; only the title distinguishes them.
- The empty state (`No events found`) sits below the fold and has not been assertable through `assertSee`. That path is unmapped rather than weakened.
- `assertDontSee` checks the rendered page; an event hidden behind pagination is not proof that filtering worked. Keep the fixture set small.
- The directory page is large. Use `fullPage: false` and the 30-second timeout.

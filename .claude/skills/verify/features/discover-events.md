# Discover events

The home page introduces ilmu360°, shows curated event sections, and lets a visitor type a query into the hero search and land on the matching results in the event directory.

## Sub-features

- `home-load` renders the home page for an anonymous visitor.
- `home-hero-search` accepts a query in the hero search field.
- `home-search-submit` submits the query and navigates to `/majlis`.
- `home-search-results` shows the matching event in the directory.

## How to get to it (user POV)

- Open `/` in a browser.
- Type a query such as an event title into the hero search field labelled `search`.
- Press the form's submit button.

## Driving it with Pest agent

Preconditions:

- `.claude/skills/verify/bin/doctor.sh` prints `READY`.
- The public event `Verify Home Event` exists, approved and public, starting tomorrow.
- Run the probe from the repository root.

- **Load the home page.** Open `/`. Run `visit("/", ["timeout" => 30000])->assertSee("Dekat dengan")`. The hero section is visible.
- **Search and submit.** Type `Verify Home Event` into `search` and submit the form. Run `->fill("search", "Verify Home Event")->click("button[type=submit]")`. The browser navigates to `/majlis`.
- **See the match.** Assert the directory shows the event. Run `->assertPathIs("/majlis")->assertSee("Verify Home Event")`. The matching event title is rendered.
- **Proof.** Capture the result viewport. Run `->screenshot(fullPage: false, filename: "verify-home-search")` and copy it to `storage/verify/discover-events/<timestamp>.png`.

Full probe:

```bash
vendor/bin/pest --agent='require base_path(".claude/skills/verify/bin/boot.php"); \Pest\Browser\Playwright\Playwright::setTimeout(30000); withGlobalOwnerContext(function (): void { \App\Models\Event::factory()->create(["title" => "Verify Home Event", "status" => "approved", "visibility" => "public", "published_at" => now(), "starts_at" => now()->addDay(), "ends_at" => now()->addDay()->addHour()]); }); visit("/", ["timeout" => 30000])->assertSee("Dekat dengan")->fill("search", "Verify Home Event")->click("button[type=submit]")->assertPathIs("/majlis")->assertSee("Verify Home Event")->screenshot(fullPage: false, filename: "verify-home-search");'
```

## Gotchas

- Fixtures must be created inside `withGlobalOwnerContext(...)`; otherwise the event factory throws `NoCurrentOwnerException`.
- The home page has more than one submit button. The tested selector `button[type=submit]` targets the hero form after the search field is filled.
- The search field is selected by its `name="search"`, not by placeholder text.
- A public listing needs `status = approved`, `visibility = public`, a past `published_at`, and future `starts_at`/`ends_at`. Defaults from the factory are not enough.
- Run the probe sequentially. Another browser probe running at the same time can make the page load or submit fail.

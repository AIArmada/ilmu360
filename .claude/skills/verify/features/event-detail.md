# Event detail

The event detail page at `/majlis/{slug}` shows one event's title, schedule, venue, and primary visitor actions such as adding it to a calendar and sharing it.

## Sub-features

- `detail-open` renders an approved public event by its slug.
- `detail-title` shows the event title as the page heading.
- `detail-calendar` offers the `Tambah ke Kalendar` action.
- `detail-share` offers the `Kongsi` action.

## How to get to it (user POV)

- Open `/majlis/{event-slug}` directly.
- Open the event's card in the `/majlis` directory (not yet independently driven; see Gotchas).

## Driving it with Pest agent

Preconditions:

- `.claude/skills/verify/bin/doctor.sh` prints `READY`.
- The public event `Verify Detail Event` exists, approved and public, starting tomorrow.
- Run the probe from the repository root.

- **Open the detail page.** Visit the event slug. Run `visit("/majlis/".$event->slug, ["timeout" => 30000])`. The page loads at the event URL.
- **See the identity.** Assert the title. Run `->assertSee("Verify Detail Event")`. The event heading is rendered.
- **See the calendar action.** Assert `Tambah ke Kalendar`. Run `->assertSee("Tambah ke Kalendar")`. The add-to-calendar control is visible.
- **See the share action.** Assert `Kongsi`. Run `->assertSee("Kongsi")`. The share control is visible.
- **Proof.** Capture the viewport. Run `->screenshot(fullPage: false, filename: "verify-event-detail")` and copy it to `storage/verify/event-detail/<timestamp>.png`.

Full probe:

```bash
vendor/bin/pest --agent='require base_path(".claude/skills/verify/bin/boot.php"); \Pest\Browser\Playwright\Playwright::setTimeout(30000); $event = withGlobalOwnerContext(fn () => \App\Models\Event::factory()->create(["title" => "Verify Detail Event", "status" => "approved", "visibility" => "public", "published_at" => now(), "starts_at" => now()->addDay(), "ends_at" => now()->addDay()->addHour()])); visit("/majlis/".$event->slug, ["timeout" => 30000])->assertSee("Verify Detail Event")->assertSee("Tambah ke Kalendar")->assertSee("Kongsi")->screenshot(fullPage: false, filename: "verify-event-detail");'
```

## Gotchas

- The event must be `approved`, `public`, and have a past `published_at`; otherwise the public route does not resolve it.
- `$event->slug` is assigned by the factory. Do not hardcode a slug.
- The title is also present in meta tags and share payloads, so `assertSee` alone does not prove the heading rendered. Pair it with the action assertions above.
- Clicking a card title in `/majlis` did not navigate in a probe (`text=` matched non-link content), so the directory-to-detail entry point is not mapped. Use the direct URL.
- The page is long. `fullPage: true` can take 30 seconds or more; keep `fullPage: false`.

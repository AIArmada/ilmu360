# Sign in

Sign-in lets a registered visitor authenticate at `/login` with email and password, then land on the authenticated dashboard.

## Sub-features

- `sign-in-open` renders the sign-in form.
- `sign-in-submit` accepts an email and password and authenticates the visitor.
- `sign-in-redirect` sends a successful sign-in to `/dashboard`.
- `sign-in-dashboard` shows the authenticated dashboard content.

## How to get to it (user POV)

- Open `/login` directly.
- Follow a protected action that redirects an anonymous visitor to the sign-in page.

## Driving it with Pest agent

Preconditions:

- `.claude/skills/verify/bin/doctor.sh` prints `READY`.
- A user exists with email `verify-login@example.test` and password `password`.
- Run the probe from the repository root.

- **Open the form.** Visit `/login`. Run `visit("/login", ["timeout" => 30000])->assertSee("Sign In")`. The sign-in form is visible.
- **Enter credentials.** Fill the email and password fields. Run `->fill("email", "verify-login@example.test")->fill("password", "password")`. Both fields accept the values.
- **Submit.** Click the form's submit button. Run `->click("button[type=submit]")`. The request is accepted.
- **Reach the dashboard.** Assert the redirect and dashboard content. Run `->assertPathIs("/dashboard")->assertSee("Majlis Saya")`. The authenticated dashboard is rendered.
- **Proof.** Capture the viewport. Run `->screenshot(fullPage: false, filename: "verify-sign-in")` and copy it to `storage/verify/sign-in/<timestamp>.png`.

Full probe:

```bash
vendor/bin/pest --agent='require base_path(".claude/skills/verify/bin/boot.php"); \Pest\Browser\Playwright\Playwright::setTimeout(30000); withGlobalOwnerContext(function (): void { \App\Models\User::factory()->create(["email" => "verify-login@example.test", "password" => bcrypt("password")]); }); visit("/login", ["timeout" => 30000])->assertSee("Sign In")->fill("email", "verify-login@example.test")->fill("password", "password")->click("button[type=submit]")->assertPathIs("/dashboard")->assertSee("Majlis Saya")->screenshot(fullPage: false, filename: "verify-sign-in");'
```

## Gotchas

- Create the user inside `withGlobalOwnerContext(...)`. The factory may otherwise fail owner resolution.
- Pass `bcrypt("password")`; the factory's default password is not the literal string `password`.
- The button text is `Sign In`, while the route is `/login`. The authenticated page has no literal `Dashboard` heading; assert `Majlis Saya` instead.
- A successful POST alone is not proof. Assert the `/dashboard` path and visible dashboard content.
- The login page has a single form submit button; `button[type=submit]` is the tested handle. Do not click a link labelled `Sign In` elsewhere.

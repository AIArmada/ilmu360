<?php

use AIArmada\Communications\Models\CommunicationDestination;
use App\Features\Identity\Api\AccountPreferences;
use App\Features\Identity\Api\PrayerType;
use App\Livewire\Pages\Dashboard\AccountSettings;
use App\Models\Institution;
use App\Models\User;
use App\Notifications\Auth\VerifyEmailNotification;
use App\Services\Notifications\NotificationSettingsManager;
use App\Support\Notifications\NotificationCatalog;
use Filament\Forms\Components\Select as FormSelect;
use Filament\Forms\Components\TextInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the account settings page with profile and notifications tabs', function () {
    expect(route('account-settings'))->toEndWith('/tetapan');

    $user = User::factory()->create();

    $response = $this->withSession(['locale' => 'en'])
        ->actingAs($user)
        ->get(route('account-settings'));

    $response->assertOk()
        ->assertSee('Account Settings')
        ->assertSee('Profile')
        ->assertSee('Notifications')
        ->assertSee('Profile Details')
        ->assertSee('New Password')
        ->assertSee('Confirm Password')
        ->assertDontSee('API Access')
        ->assertDontSee('Create Token')
        ->assertDontSee('Authorization: Bearer')
        ->assertDontSee('Device Preferences')
        ->assertDontSee('Show country selector on public search pages')
        ->assertDontSee('Changes to email or phone reset their verification status until they are confirmed again.')
        ->assertDontSee('Prayer institution preferences are private and only saved to your account for now.')
        ->assertSee('Prayer Institutions')
        ->assertSee('Daily Prayer Institutions')
        ->assertSee('Friday Prayer Institutions')
        ->assertSee('Save Account Settings')
        ->assertDontSee('Digest Preferences')
        ->assertDontSee('Save Preferences')
        ->assertSee('images/settings/account-settings-hero-desktop-v1.png', false)
        ->assertSee('images/settings/account-settings-hero-mobile-v1.webp', false)
        ->assertSee('fi-fo-phone-input', false);
});

it('uses the account settings title on the profile tab and hides the inbox link', function () {
    $user = User::factory()->create();

    $response = $this->withSession(['locale' => 'en'])
        ->actingAs($user)
        ->get(route('account-settings'));

    $response->assertOk()
        ->assertSee('<title>'.__('Account Settings').' - '.config('app.name').'</title>', false)
        ->assertSee('data-testid="account-settings-heading">'.__('Profile Details').'</h1>', false)
        ->assertSee(__('These details identify you across your account and public interactions.'))
        ->assertDontSee(__('notifications.pages.settings.description'))
        ->assertDontSee(__('notifications.pages.inbox.cta'));
});

it('shows the notifications heading and inbox link on the notifications tab', function () {
    $user = User::factory()->create();

    $response = $this->withSession(['locale' => 'en'])
        ->actingAs($user)
        ->get(route('account-settings', ['tab' => 'notifications']));

    $response->assertOk()
        ->assertSee('<title>'.__('Account Settings').' - '.config('app.name').'</title>', false)
        ->assertSee('data-testid="account-settings-heading">'.__('notifications.pages.settings.heading').'</h1>', false)
        ->assertSee(__('notifications.pages.settings.description'))
        ->assertSee(__('notifications.pages.inbox.cta'));
});

it('places the password fields side by side on desktop', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(AccountSettings::class)
        ->assertFormFieldExists('password', function (TextInput $input): bool {
            expect($input->getColumnSpan('default'))->toBe(1);

            return true;
        })
        ->assertFormFieldExists('password_confirmation', function (TextInput $input): bool {
            expect($input->getColumnSpan('default'))->toBe(1);

            return true;
        });
});

it('does not let the browser prefill the password fields', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(AccountSettings::class)
        ->assertFormFieldExists('password', function (TextInput $input): bool {
            expect($input->getAutocomplete())->toBe('new-password');

            return true;
        })
        ->assertFormFieldExists('password_confirmation', function (TextInput $input): bool {
            expect($input->getAutocomplete())->toBe('new-password');

            return true;
        });
});

it('renders the account settings profile tab in Malay with password labels and no helper callouts', function () {
    $user = User::factory()->create();

    $this->withSession(['locale' => 'ms'])
        ->actingAs($user)
        ->get(route('account-settings'))
        ->assertOk()
        ->assertSee('Tetapan Akaun')
        ->assertSee('Kata laluan baharu')
        ->assertSee('Sahkan kata laluan')
        ->assertSee('wire:target')
        ->assertDontSee('Jika e-mel atau nombor telefon diubah, status pengesahannya akan ditetapkan semula sehingga disahkan semula.')
        ->assertDontSee('Pilihan institusi solat adalah peribadi dan buat masa ini hanya disimpan pada akaun anda.')
        ->assertDontSee('API Access');
});

it('formats timezone options with offsets and timezone identifiers', function () {
    $user = User::factory()->create();

    $options = Livewire::actingAs($user)
        ->test(AccountSettings::class)
        ->instance()
        ->timezoneOptions();

    expect($options)->toHaveKey('Asia/Kuala_Lumpur')
        ->and($options['Asia/Kuala_Lumpur'])->toBe('Asia/Kuala_Lumpur (GMT+8)')
        ->and($options)->toHaveKey('UTC')
        ->and($options['UTC'])->toBe('UTC (GMT)');
});

it('renders the simplified notifications tab in Malay without leaking raw translation keys', function () {
    $user = User::factory()->create();

    $response = $this->withSession(['locale' => 'ms'])
        ->actingAs($user)
        ->get(route('account-settings', ['tab' => 'notifications']));

    $response->assertOk()
        ->assertSee('Tetapan Akaun')
        ->assertSee('Notifikasi')
        ->assertSee('Makluman wajib')
        ->assertSee('Saluran')
        ->assertSee('Majlis baharu untuk anda')
        ->assertSee('Majlis yang saya sertai')
        ->assertSee('Akan datang')
        ->assertDontSee('Pasang aplikasi')
        ->assertDontSee('Ringkasan komuniti')
        ->assertDontSee('Daftar masuk dibuka')
        ->assertSee('data-flux-switch', false)
        ->assertSee('data-flux-select-native', false)
        ->assertSee('data-flux-button', false)
        ->assertDontSee('Semua perubahan disimpan.')
        ->assertDontSee('Perubahan belum disimpan.')
        ->assertDontSee('WhatsApp')
        ->assertDontSee('Ikut tetapan kumpulan')
        ->assertDontSee('notifications.triggers.')
        ->assertDontSee('notifications.pages.');
});

it('has a translation for every notification trigger', function () {
    foreach (NotificationCatalog::triggers() as $trigger) {
        expect($trigger['label'])->not->toContain('notifications.')
            ->and($trigger['description'])->not->toContain('notifications.');
    }
});

it('updates account settings and resets verification when contact details change', function () {
    Notification::fake();

    $user = User::factory()->create([
        'name' => 'Old Name',
        'email' => 'old@example.test',
        'phone' => '+60111111111',
        'timezone' => 'Asia/Kuala_Lumpur',
        'email_verified_at' => now(),
        'phone_verified_at' => now(),
    ]);

    (new CommunicationDestination)->forceFill([
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->getKey(),
        'channel' => 'email',
        'address' => 'old@example.test',
        'external_id' => null,
    ])->save();
    session(['user_timezone' => 'Asia/Kuala_Lumpur']);

    Livewire::actingAs($user)
        ->test(AccountSettings::class)
        ->set('formData.name', 'Updated Name')
        ->set('formData.email', 'updated@example.test')
        ->set('formData.phone', '+60122222222')
        ->set('formData.timezone', 'Asia/Jakarta')
        ->set('formData.gender', 'female')
        ->set('formData.date_of_birth', '1990-05-15')
        ->call('saveAccountSettings')
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->name)->toBe('Updated Name')
        ->and($user->email)->toBe('updated@example.test')
        ->and($user->phone)->toBe('+60122222222')
        ->and($user->timezone)->toBe('Asia/Jakarta')
        ->and($user->gender)->toBe('female')
        ->and($user->date_of_birth?->format('Y-m-d'))->toBe('1990-05-15')
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->phone_verified_at)->toBeNull();

    expect(CommunicationDestination::query()
        ->where('recipient_type', $user->getMorphClass())
        ->where('recipient_id', $user->id)
        ->where('channel', 'email')
        ->pluck('address')
        ->all())->toBe(['updated@example.test']);

    $newDestination = CommunicationDestination::query()
        ->where('recipient_type', $user->getMorphClass())
        ->where('recipient_id', $user->id)
        ->where('channel', 'email')
        ->get()
        ->first(fn (CommunicationDestination $destination): bool => $destination->address === 'updated@example.test');

    expect($newDestination)->not->toBeNull()
        ->and($newDestination->status)->toBe('inactive');

    $notificationState = app(NotificationSettingsManager::class)->stateFor($user->fresh());

    expect($notificationState['settings']['timezone'])->toBe('Asia/Jakarta');

    Notification::assertSentTo($user->fresh(), VerifyEmailNotification::class);
});

it('shows a Filament flash notification after saving account settings', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(AccountSettings::class)
        ->set('formData.name', $user->name)
        ->set('formData.email', $user->email)
        ->call('saveAccountSettings')
        ->assertNotified();
});

it('updates the account password when the confirmation matches', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(AccountSettings::class)
        ->set('formData.password', 'new-password')
        ->set('formData.password_confirmation', 'new-password')
        ->call('saveAccountSettings')
        ->assertHasNoErrors();

    expect(Hash::check('new-password', $user->fresh()->password))->toBeTrue();
});

it('rejects password changes when the confirmation does not match', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(AccountSettings::class)
        ->set('formData.password', 'new-password')
        ->set('formData.password_confirmation', 'different-password')
        ->call('saveAccountSettings')
        ->assertHasErrors([
            'formData.password' => 'confirmed',
        ]);
});

it('saves simplified notification preferences from account settings', function () {
    $user = User::factory()->create([
        'email' => 'member@example.test',
        'email_verified_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(AccountSettings::class)
        ->set('tab', 'notifications')
        ->set('notificationSettingsState.channels', ['in_app', 'email', 'push'])
        ->set('notificationSettingsState.quiet_hours_start', '22:00')
        ->set('notificationSettingsState.quiet_hours_end', '07:00')
        ->set('quietHoursEnabled', true)
        ->set('notificationGroupsState.discovery.enabled', true)
        ->set('notificationGroupsState.discovery.cadence', 'weekly')
        ->set('notificationTriggersState.reminder_2_hours.enabled', false)
        ->call('saveNotificationPreferences')
        ->assertHasNoErrors();

    $state = app(NotificationSettingsManager::class)->stateFor($user->fresh());

    expect($state['settings']['channels'])
        ->toContain('in_app')
        ->toContain('email')
        ->toContain('push')
        ->and($state['settings']['quiet_hours_start'])->toBe('22:00:00')
        ->and($state['settings']['quiet_hours_end'])->toBe('07:00:00')
        ->and($state['families']['followed_content']['cadence'])->toBe('weekly')
        ->and($state['families']['saved_search_matches']['cadence'])->toBe('weekly')
        ->and($state['triggers']['reminder_2_hours']['enabled'])->toBeFalse();
});

it('leaves hidden notification preferences unchanged when saving', function () {
    $user = User::factory()->create();

    $manager = app(NotificationSettingsManager::class);
    $manager->save($user, [
        'families' => [
            'sharing' => ['enabled' => true],
        ],
        'triggers' => [
            'checkin_open' => ['enabled' => false],
        ],
    ]);

    Livewire::actingAs($user)
        ->test(AccountSettings::class)
        ->set('tab', 'notifications')
        ->call('saveNotificationPreferences')
        ->assertHasNoErrors();

    $state = $manager->stateFor($user->fresh());

    expect($state['families']['sharing']['enabled'])->toBeTrue()
        ->and($state['triggers']['checkin_open']['enabled'])->toBeFalse();
});

it('does not persist unsaved notification changes when saving profile details', function () {
    $user = User::factory()->create([
        'name' => 'Original Name',
        'email' => 'profile@example.test',
        'email_verified_at' => now(),
    ]);

    app(NotificationSettingsManager::class)->save($user, [
        'settings' => [
            'channels' => ['in_app', 'email'],
        ],
        'families' => [
            'event_updates' => [
                'enabled' => true,
                'cadence' => 'instant',
            ],
        ],
    ]);

    Livewire::actingAs($user)
        ->test(AccountSettings::class)
        ->set('tab', 'notifications')
        ->set('notificationSettingsState.channels', ['in_app', 'push'])
        ->set('notificationGroupsState.discovery.cadence', 'weekly')
        ->set('formData.name', 'Profile Saved Name')
        ->call('saveAccountSettings')
        ->assertHasNoErrors();

    $user->refresh();
    $state = app(NotificationSettingsManager::class)->stateFor($user);

    expect($user->name)->toBe('Profile Saved Name')
        ->and($state['settings']['channels'])->toBe(['in_app', 'email'])
        ->and($state['families']['followed_content']['cadence'])->not->toBe('weekly');
});

it('hides submission settings from attendees without organizer access', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('account-settings', ['tab' => 'notifications']))
        ->assertOk()
        ->assertDontSee('Sumbangan majlis');
});

it('removes a push destination from account settings', function () {
    $user = User::factory()->create();

    $destination = new CommunicationDestination;
    $destination->forceFill([
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->getKey(),
        'channel' => 'push',
        'address' => 'ios-remove-me',
        'external_id' => 'push-token',
        'status' => 'active',
    ])->save();

    Livewire::actingAs($user)
        ->test(AccountSettings::class)
        ->call('removePushDestination', $destination->id)
        ->assertHasNoErrors();

    expect(CommunicationDestination::query()->whereKey($destination->id)->exists())->toBeFalse();
});

it('requires at least one contact method on account settings', function () {
    $user = User::factory()->create([
        'email' => 'member@example.test',
        'phone' => '+60113334444',
    ]);

    Livewire::actingAs($user)
        ->test(AccountSettings::class)
        ->set('formData.email', '')
        ->set('formData.phone', '')
        ->call('saveAccountSettings')
        ->assertHasErrors([
            'formData.email' => 'required_without',
            'formData.phone' => 'required_without',
        ]);
});

it('searches both prayer institution selectors from the institution database', function () {
    $dailyInstitution = Institution::factory()->create([
        'name' => 'Masjid Searchable Daily',
        'status' => 'verified',
    ]);
    $fridayInstitution = Institution::factory()->create([
        'name' => 'Masjid Searchable Friday',
        'status' => 'verified',
    ]);
    $inactiveInstitution = Institution::factory()->create([
        'name' => 'Masjid Searchable Inactive',
        'status' => 'inactive',
    ]);
    $pendingInstitution = Institution::factory()->create([
        'name' => 'Masjid Searchable Pending',
        'status' => 'pending',
    ]);

    $user = User::factory()->create([
        'email' => 'member@example.test',
        'phone' => '+60113334444',
    ]);

    $component = Livewire::actingAs($user)->test(AccountSettings::class);

    foreach (['daily_prayer_institution_ids', 'friday_prayer_institution_ids'] as $field) {
        $component->assertFormFieldExists($field, function (FormSelect $select) use ($dailyInstitution, $fridayInstitution, $inactiveInstitution, $pendingInstitution): bool {
            $results = $select->getSearchResults('searchable');

            expect($select->isSearchable())->toBeTrue()
                ->and($select->isMultiple())->toBeTrue()
                ->and($select->hasDynamicSearchResults())->toBeTrue()
                ->and($results)->toHaveKey($dailyInstitution->id)
                ->and($results[$dailyInstitution->id])->toBe('Masjid Searchable Daily')
                ->and($results)->toHaveKey($fridayInstitution->id)
                ->and($results[$fridayInstitution->id])->toBe('Masjid Searchable Friday')
                ->and($results)->not->toHaveKey($inactiveInstitution->id)
                ->and($results)->not->toHaveKey($pendingInstitution->id);

            return true;
        });
    }
});

it('saves multiple prayer institution preferences and preserves contact verification when contact details are unchanged', function () {
    $dailyInstitution = Institution::factory()->create([
        'name' => 'Masjid Harian',
        'status' => 'verified',
    ]);
    $secondDailyInstitution = Institution::factory()->create([
        'name' => 'Surau Harian',
        'status' => 'verified',
    ]);
    $fridayInstitution = Institution::factory()->create([
        'name' => 'Masjid Jumaat',
        'status' => 'verified',
    ]);

    $user = User::factory()->create([
        'email' => 'member@example.test',
        'phone' => '+60113334444',
        'email_verified_at' => now(),
        'phone_verified_at' => now(),
    ]);

    $component = Livewire::actingAs($user)->test(AccountSettings::class);

    $component
        ->set('formData.daily_prayer_institution_ids', [$dailyInstitution->id, $secondDailyInstitution->id])
        ->set('formData.friday_prayer_institution_ids', [])
        ->call('saveAccountSettings')
        ->assertHasNoErrors();

    $user->refresh();

    expect(app(AccountPreferences::class)->read((string) $user->getKey())->dailyPrayerInstitutionIds)
        ->toContain($dailyInstitution->id)
        ->toContain($secondDailyInstitution->id)
        ->toHaveCount(2)
        ->and(count(app(AccountPreferences::class)->read((string) $user->getKey())->fridayPrayerInstitutionIds))->toBe(0)
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->phone_verified_at)->not->toBeNull();

    $component
        ->set('formData.daily_prayer_institution_ids', [])
        ->set('formData.friday_prayer_institution_ids', [$fridayInstitution->id])
        ->call('saveAccountSettings')
        ->assertHasNoErrors();

    $user->refresh();

    expect(count(app(AccountPreferences::class)->read((string) $user->getKey())->dailyPrayerInstitutionIds))->toBe(0)
        ->and(app(AccountPreferences::class)->read((string) $user->getKey())->fridayPrayerInstitutionIds)->toBe([$fridayInstitution->id]);

    $component
        ->set('formData.daily_prayer_institution_ids', [$dailyInstitution->id])
        ->set('formData.friday_prayer_institution_ids', [$dailyInstitution->id])
        ->call('saveAccountSettings')
        ->assertHasNoErrors();

    $user->refresh();

    expect(app(AccountPreferences::class)->read((string) $user->getKey())->dailyPrayerInstitutionIds)->toBe([$dailyInstitution->id])
        ->and(app(AccountPreferences::class)->read((string) $user->getKey())->fridayPrayerInstitutionIds)->toBe([$dailyInstitution->id]);

    $component
        ->set('formData.daily_prayer_institution_ids', [$dailyInstitution->id, $secondDailyInstitution->id])
        ->set('formData.friday_prayer_institution_ids', [$fridayInstitution->id])
        ->call('saveAccountSettings')
        ->assertHasNoErrors();

    $user->refresh();

    expect(app(AccountPreferences::class)->read((string) $user->getKey())->dailyPrayerInstitutionIds)
        ->toContain($dailyInstitution->id)
        ->toContain($secondDailyInstitution->id)
        ->toHaveCount(2)
        ->and(app(AccountPreferences::class)->read((string) $user->getKey())->fridayPrayerInstitutionIds)->toBe([$fridayInstitution->id]);

    $component
        ->set('formData.daily_prayer_institution_ids', [])
        ->set('formData.friday_prayer_institution_ids', [])
        ->call('saveAccountSettings')
        ->assertHasNoErrors();

    $user->refresh();

    expect(count(app(AccountPreferences::class)->read((string) $user->getKey())->dailyPrayerInstitutionIds))->toBe(0)
        ->and(count(app(AccountPreferences::class)->read((string) $user->getKey())->fridayPrayerInstitutionIds))->toBe(0)
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->phone_verified_at)->not->toBeNull();
});

it('rejects invalid prayer institution ids on account settings', function () {
    $user = User::factory()->create([
        'email' => 'member@example.test',
        'phone' => '+60113334444',
    ]);

    Livewire::actingAs($user)
        ->test(AccountSettings::class)
        ->set('formData.daily_prayer_institution_ids', [(string) str()->uuid()])
        ->call('saveAccountSettings')
        ->assertHasErrors(['formData.daily_prayer_institution_ids']);
});

it('rejects inactive or pending institutions for new prayer preferences', function () {
    $inactiveInstitution = Institution::factory()->create([
        'status' => 'inactive',
    ]);
    $pendingInstitution = Institution::factory()->create([
        'status' => 'pending',
    ]);

    $user = User::factory()->create([
        'email' => 'member@example.test',
        'phone' => '+60113334444',
    ]);

    Livewire::actingAs($user)
        ->test(AccountSettings::class)
        ->set('formData.daily_prayer_institution_ids', [$inactiveInstitution->id])
        ->call('saveAccountSettings')
        ->assertHasErrors(['formData.daily_prayer_institution_ids']);

    Livewire::actingAs($user)
        ->test(AccountSettings::class)
        ->set('formData.friday_prayer_institution_ids', [$pendingInstitution->id])
        ->call('saveAccountSettings')
        ->assertHasErrors(['formData.friday_prayer_institution_ids']);
});

it('allows stale saved prayer institution preferences to remain while saving unrelated profile changes', function () {
    $institution = Institution::factory()->create([
        'name' => 'Masjid Lama',
        'status' => 'verified',
    ]);

    $user = User::factory()->create([
        'name' => 'Original Name',
        'email' => 'member@example.test',
        'phone' => '+60113334444',
    ]);

    app(AccountPreferences::class)->syncPrayerInstitutions((string) $user->getKey(), PrayerType::Daily, [$institution->id]);
    app(AccountPreferences::class)->syncPrayerInstitutions((string) $user->getKey(), PrayerType::Friday, [$institution->id]);

    $institution->update([
        'status' => 'inactive',
    ]);

    Livewire::actingAs($user)
        ->test(AccountSettings::class)
        ->assertSet('formData.daily_prayer_institution_ids', [$institution->id])
        ->assertSet('formData.friday_prayer_institution_ids', [$institution->id])
        ->set('formData.name', 'Updated Name')
        ->call('saveAccountSettings')
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->name)->toBe('Updated Name')
        ->and(app(AccountPreferences::class)->read((string) $user->getKey())->dailyPrayerInstitutionIds)->toBe([$institution->id])
        ->and(app(AccountPreferences::class)->read((string) $user->getKey())->fridayPrayerInstitutionIds)->toBe([$institution->id]);
});

it('renders gender and date of birth fields in Malay', function () {
    $user = User::factory()->create();

    $this->withSession(['locale' => 'ms'])
        ->actingAs($user)
        ->get(route('account-settings'))
        ->assertOk()
        ->assertSee('Jantina')
        ->assertSee('Tarikh Lahir')
        ->assertSee('Lelaki')
        ->assertSee('Perempuan');
});

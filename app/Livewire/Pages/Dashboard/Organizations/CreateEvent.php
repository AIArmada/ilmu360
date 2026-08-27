<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Dashboard\Organizations;

use AIArmada\Events\Enums\RegistrationMode;
use AIArmada\Organizations\Models\Organization;
use AIArmada\Seating\Enums\SeatingMode;
use AIArmada\Ticketing\Enums\PricingMode;
use App\Actions\Organizations\CreateOrganizationEventAction;
use App\Contracts\EventCategoryCatalog;
use App\Enums\EventFormat;
use App\Enums\EventVisibility;
use App\Models\User;
use App\Support\Authz\OrganizationEventAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Create organization event')]
final class CreateEvent extends Component
{
    public string $organizationId = '';

    /** @var array<string, mixed> */
    public array $form = [];

    public int $activeStep = 1;

    /** @var array<string, string> */
    public array $eventCategoryOptions = [];

    public function mount(Organization $organization, EventCategoryCatalog $categoryCatalog): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        app(OrganizationEventAccess::class)->authorizeCreate($user, $organization);

        $this->organizationId = (string) $organization->getKey();
        $this->eventCategoryOptions = $categoryCatalog->options();

        $timezone = (string) config('app.default_user_timezone', 'Asia/Kuala_Lumpur');
        $startsAt = now($timezone)->addDays(7)->setTime(20, 0);

        $this->form = [
            'title' => '',
            'description' => '',
            'timezone' => $timezone,
            'starts_at' => $startsAt->format('Y-m-d\\TH:i'),
            'ends_at' => $startsAt->copy()->addHours(2)->format('Y-m-d\\TH:i'),
            'delivery_mode' => EventFormat::Physical->value,
            'visibility' => EventVisibility::Public->value,
            'registration_mode' => RegistrationMode::Required->value,
            'pricing_mode' => PricingMode::Free->value,
            'event_category_ids' => array_slice(array_keys($this->eventCategoryOptions), 0, 1),
            'tickets' => [$this->defaultTicket()],
            'seating' => [
                'mode' => SeatingMode::GeneralAdmission->value,
                'map_name' => 'Main seating map',
                'sections' => [$this->defaultSection()],
            ],
        ];
    }

    public function goToStep(int $step): void
    {
        $this->activeStep = max(1, min(3, $step));
    }

    public function nextStep(): void
    {
        $this->goToStep($this->activeStep + 1);
    }

    public function previousStep(): void
    {
        $this->goToStep($this->activeStep - 1);
    }

    public function addTicketType(): void
    {
        $this->form['tickets'][] = [
            'name' => '',
            'code' => '',
            'description' => '',
            'price' => '0.00',
            'quota' => '',
            'max_quantity' => '1',
            'seating_mode' => SeatingMode::None->value,
        ];
    }

    public function removeTicketType(int $index): void
    {
        if (count((array) ($this->form['tickets'] ?? [])) <= 1) {
            return;
        }

        array_splice($this->form['tickets'], $index, 1);
    }

    public function addSection(): void
    {
        $this->form['seating']['sections'][] = $this->defaultSection();
    }

    public function removeSection(int $index): void
    {
        if (count((array) ($this->form['seating']['sections'] ?? [])) <= 1) {
            return;
        }

        array_splice($this->form['seating']['sections'], $index, 1);
    }

    public function hasSeatingTicket(): bool
    {
        foreach ((array) ($this->form['tickets'] ?? []) as $ticket) {
            if (is_array($ticket) && (string) ($ticket['seating_mode'] ?? SeatingMode::None->value) !== SeatingMode::None->value) {
                return true;
            }
        }

        return false;
    }

    public function submit(CreateOrganizationEventAction $createOrganizationEvent): mixed
    {
        $validated = $this->validate($this->rules());
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $event = $createOrganizationEvent->handle(
            $user,
            $this->organization(),
            $validated['form'],
        );

        session()->flash('success', __('Event draft created.'));

        return redirect()->route('dashboard.organizations.show', $this->organization());
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        $ticketRules = [
            'form.tickets' => ['required', 'array', 'min:1', 'max:20'],
            'form.tickets.*.name' => ['required', 'string', 'max:120'],
            'form.tickets.*.code' => ['nullable', 'string', 'max:40'],
            'form.tickets.*.description' => ['nullable', 'string', 'max:1000'],
            'form.tickets.*.price' => ['required', 'regex:/^\\d+(?:\\.\\d{1,2})?$/'],
            'form.tickets.*.quota' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'form.tickets.*.max_quantity' => ['nullable', 'integer', 'min:1', 'max:100'],
            'form.tickets.*.seating_mode' => ['required', Rule::enum(SeatingMode::class)],
        ];

        $rules = [
            'form.title' => ['required', 'string', 'max:255'],
            'form.description' => ['nullable', 'string', 'max:10000'],
            'form.timezone' => ['required', 'timezone'],
            'form.starts_at' => ['required', 'date'],
            'form.ends_at' => ['required', 'date'],
            'form.delivery_mode' => ['required', Rule::enum(EventFormat::class)],
            'form.visibility' => ['required', Rule::in([EventVisibility::Public->value, EventVisibility::Unlisted->value, EventVisibility::Private->value])],
            'form.registration_mode' => ['required', Rule::enum(RegistrationMode::class)],
            'form.pricing_mode' => ['required', Rule::enum(PricingMode::class)],
            'form.event_category_ids' => ['required', 'array', 'min:1'],
            'form.event_category_ids.*' => ['uuid', Rule::in(array_keys($this->eventCategoryOptions))],
            ...$ticketRules,
        ];

        if ($this->hasSeatingTicket()) {
            $rules = [
                ...$rules,
                'form.seating.mode' => ['required', Rule::in([
                    SeatingMode::GeneralAdmission->value,
                    SeatingMode::Assigned->value,
                    SeatingMode::Hybrid->value,
                ])],
                'form.seating.map_name' => ['required', 'string', 'max:120'],
                'form.seating.sections' => ['required', 'array', 'min:1', 'max:50'],
                'form.seating.sections.*.name' => ['required', 'string', 'max:120'],
                'form.seating.sections.*.code' => ['nullable', 'string', 'max:20'],
                'form.seating.sections.*.capacity' => ['required', 'integer', 'min:1', 'max:1000000'],
                'form.seating.sections.*.rows' => ['required', 'integer', 'min:1', 'max:26'],
                'form.seating.sections.*.seats_per_row' => ['required', 'integer', 'min:1', 'max:1000'],
            ];
        }

        if (($this->form['pricing_mode'] ?? null) === PricingMode::Paid->value) {
            $rules['form.registration_mode'][] = Rule::notIn([RegistrationMode::None->value]);
        }

        return $rules;
    }

    public function render(): View
    {
        return view('livewire.pages.dashboard.organizations.create-event', [
            'organization' => $this->organization(),
            'eventFormatOptions' => collect(EventFormat::cases())->mapWithKeys(fn (EventFormat $format): array => [$format->value => $format->label()])->all(),
            'visibilityOptions' => [
                EventVisibility::Public->value => __('Public'),
                EventVisibility::Unlisted->value => __('Unlisted'),
                EventVisibility::Private->value => __('Private'),
            ],
            'registrationOptions' => collect(RegistrationMode::cases())->mapWithKeys(fn (RegistrationMode $mode): array => [$mode->value => $mode->label()])->all(),
            'pricingOptions' => collect(PricingMode::cases())->reject(fn (PricingMode $mode): bool => $mode === PricingMode::Mixed)->mapWithKeys(fn (PricingMode $mode): array => [$mode->value => $mode->label()])->all(),
            'seatingOptions' => collect(SeatingMode::cases())
                ->mapWithKeys(fn (SeatingMode $mode): array => [$mode->value => $mode->label()])
                ->all(),
        ]);
    }

    /** @return array<string, string> */
    private function defaultTicket(): array
    {
        return [
            'name' => 'General admission',
            'code' => 'GENERAL',
            'description' => '',
            'price' => '0.00',
            'quota' => '',
            'max_quantity' => '1',
            'seating_mode' => SeatingMode::None->value,
        ];
    }

    /** @return array<string, string> */
    private function defaultSection(): array
    {
        return [
            'name' => 'Main hall',
            'code' => 'MAIN',
            'capacity' => '100',
            'rows' => '10',
            'seats_per_row' => '10',
            'color' => '#0f766e',
        ];
    }

    private function organization(): Organization
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        /** @var Organization $organization */
        $organization = Organization::query()
            ->whereKey($this->organizationId)
            ->whereHas('members', fn ($query) => $query->whereKey($user->getKey()))
            ->firstOrFail();

        app(OrganizationEventAccess::class)->authorizeCreate($user, $organization);

        return $organization;
    }
}

<?php

namespace App\Livewire\Pages\Dashboard\Events;

use App\Actions\Events\CreateAdvancedParentProgramAction;
use App\Actions\Events\PrepareAdvancedParentProgramSubmissionAction;
use App\Actions\Events\ResolveAdvancedBuilderContextAction;
use App\Enums\EventFormat;
use App\Enums\EventType;
use App\Enums\EventVisibility;
use App\Enums\RegistrationMode;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

#[Layout('layouts.app')]
#[Title('Create Advanced Event')]
class CreateAdvanced extends Component
{
    /**
     * @var array<string, mixed>
     */
    public array $form = [];

    /**
     * @var array<string, string>
     */
    public array $institutionOptions = [];

    /**
     * @var array<string, string>
     */
    public array $speakerOptions = [];

    public int $activeStep = 1;

    public function mount(): void
    {
        abort_unless(auth()->check(), 403);

        $user = $this->currentUser();

        abort_unless($user instanceof User, 403);

        $requestedInstitutionId = request()->query('institution');
        $builderContext = app(ResolveAdvancedBuilderContextAction::class)->handle(
            $user,
            is_string($requestedInstitutionId) ? $requestedInstitutionId : null,
        );

        $this->institutionOptions = $builderContext['institution_options'];
        $this->speakerOptions = $builderContext['speaker_options'];

        abort_unless($this->hasBuilderAccess(), 403);

        $this->form = $builderContext['default_form'];
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

    public function applyTemplate(string $template): void
    {
        $timezone = (string) ($this->form['timezone'] ?? 'Asia/Kuala_Lumpur');
        $startsAt = now($timezone)->addDays(2)->setTime(20, 0);

        $templateState = match ($template) {
            'weekly_series' => [
                'title' => $this->form['title'] ?: __('Weekly Knowledge Series'),
                'description' => $this->form['description'] ?: __('A repeating program with one featured child event every week.'),
                'program_starts_at' => $startsAt->copy()->format('Y-m-d\TH:i'),
                'program_ends_at' => $startsAt->copy()->addWeeks(4)->format('Y-m-d\TH:i'),
            ],
            'weekend_intensive' => [
                'title' => $this->form['title'] ?: __('Weekend Intensive Program'),
                'description' => $this->form['description'] ?: __('A compact multi-day program across one focused weekend.'),
                'program_starts_at' => $startsAt->copy()->next('Friday')->setTime(20, 30)->format('Y-m-d\TH:i'),
                'program_ends_at' => $startsAt->copy()->next('Sunday')->setTime(12, 30)->format('Y-m-d\TH:i'),
            ],
            'ramadan_program' => [
                'title' => $this->form['title'] ?: __('Ramadan Companion Program'),
                'description' => $this->form['description'] ?: __('An umbrella program with nightly child events and lighter weekend highlights.'),
                'program_starts_at' => $startsAt->copy()->setTime(21, 15)->format('Y-m-d\TH:i'),
                'program_ends_at' => $startsAt->copy()->addDays(10)->setTime(22, 30)->format('Y-m-d\TH:i'),
            ],
            default => null,
        };

        if (! is_array($templateState)) {
            return;
        }

        $this->form['title'] = (string) $templateState['title'];
        $this->form['description'] = (string) $templateState['description'];
        $this->form['program_starts_at'] = $templateState['program_starts_at'];
        $this->form['program_ends_at'] = $templateState['program_ends_at'];
        $this->activeStep = 2;
    }

    public function updatedFormOrganizerType(string $value): void
    {
        if ($value === 'institution') {
            $organizerId = array_key_first($this->institutionOptions);
            $this->form['organizer_id'] = $organizerId;
            $this->form['location_institution_id'] = $organizerId;

            return;
        }

        $this->form['organizer_id'] = array_key_first($this->speakerOptions);

        if (! filled($this->form['location_institution_id'] ?? null)) {
            $this->form['location_institution_id'] = array_key_first($this->institutionOptions);
        }
    }

    public function submit(
        CreateAdvancedParentProgramAction $createAdvancedParentProgramAction,
        PrepareAdvancedParentProgramSubmissionAction $prepareAdvancedParentProgramSubmissionAction,
    ): mixed {
        $validated = $this->validate($this->rules());

        $user = $this->currentUser();

        abort_unless($user instanceof User, 403);

        $preparedSubmission = $prepareAdvancedParentProgramSubmissionAction->handle($user, $validated['form']);

        try {
            $parentEvent = $createAdvancedParentProgramAction->handle(
                $user,
                $validated['form'],
                $preparedSubmission['program_starts_at'],
                $preparedSubmission['program_ends_at'],
                $preparedSubmission['timezone'],
                $preparedSubmission['organizer_type'],
                $preparedSubmission['organizer_id'],
                $preparedSubmission['location_institution_id'],
            );
        } catch (Throwable $throwable) {
            report($throwable);

            $this->addError('form.title', __('The advanced event could not be created. Please try again.'));

            return null;
        }

        return redirect()->route('submit-event.create', ['parent' => $parentEvent->id]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'form.title' => ['required', 'string', 'max:255'],
            'form.description' => ['nullable', 'string'],
            'form.timezone' => ['required', 'string', 'max:64'],
            'form.program_starts_at' => ['required', 'date'],
            'form.program_ends_at' => ['required', 'date'],
            'form.organizer_type' => ['required', Rule::in(['institution', 'speaker'])],
            'form.organizer_id' => ['required', 'string'],
            'form.location_institution_id' => ['nullable', 'string'],
            'form.default_event_type' => ['required', Rule::in(array_column(EventType::cases(), 'value'))],
            'form.default_event_format' => ['required', Rule::in(array_column(EventFormat::cases(), 'value'))],
            'form.visibility' => ['required', Rule::in(array_column(EventVisibility::cases(), 'value'))],
            'form.registration_required' => ['required', 'boolean'],
            'form.registration_mode' => ['required', Rule::in(array_column(RegistrationMode::cases(), 'value'))],
        ];
    }

    protected function hasBuilderAccess(): bool
    {
        return $this->institutionOptions !== [] || $this->speakerOptions !== [];
    }

    protected function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * @return array<int, array{number: int, title: string, description: string}>
     */
    protected function stepOptions(): array
    {
        return [
            1 => ['number' => 1, 'title' => __('Program Identity'), 'description' => __('Name the umbrella program and ownership')],
            2 => ['number' => 2, 'title' => __('Program Defaults'), 'description' => __('Set timeframe, visibility, and registration defaults')],
            3 => ['number' => 3, 'title' => __('Review & Continue'), 'description' => __('Create the parent first, then add child events individually')],
        ];
    }

    /**
     * @return array<int, array{key: string, title: string, description: string, eyebrow: string}>
     */
    protected function templateOptions(): array
    {
        return [
            ['key' => 'weekly_series', 'title' => __('Weekly Series'), 'description' => __('Use one parent program for a weekly chain of child event submissions.'), 'eyebrow' => __('Series')],
            ['key' => 'weekend_intensive', 'title' => __('Weekend Intensive'), 'description' => __('Create one parent, then submit each child event separately under it.'), 'eyebrow' => __('Focused')],
            ['key' => 'ramadan_program', 'title' => __('Ramadan Program'), 'description' => __('Set up the parent first, then add nightly child events one by one.'), 'eyebrow' => __('Seasonal')],
        ];
    }

    public function render(): View
    {
        return view('livewire.pages.dashboard.events.create-advanced', [
            'institutionOptions' => $this->institutionOptions,
            'speakerOptions' => $this->speakerOptions,
            'eventTypeOptions' => collect(EventType::cases())->mapWithKeys(fn (EventType $type): array => [$type->value => $type->getLabel()])->all(),
            'eventFormatOptions' => collect(EventFormat::cases())->mapWithKeys(fn (EventFormat $format): array => [$format->value => $format->label()])->all(),
            'visibilityOptions' => collect(EventVisibility::cases())->mapWithKeys(fn (EventVisibility $visibility): array => [$visibility->value => $visibility->getLabel()])->all(),
            'stepOptions' => $this->stepOptions(),
            'templateOptions' => $this->templateOptions(),
        ]);
    }
}

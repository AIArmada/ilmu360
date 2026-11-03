<?php

declare(strict_types=1);

namespace App\Livewire\Pages\AppFeedback;

use App\Actions\AppFeedback\SubmitAppFeedbackAction;
use App\Enums\AppFeedbackCategory;
use App\Livewire\Concerns\InteractsWithToasts;
use App\Models\AppFeedback;
use App\Models\User;
use App\Support\AppFeedback\AppFeedbackInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Maklum Balas')]
class Index extends Component implements HasForms
{
    use InteractsWithForms;
    use InteractsWithToasts;
    use WithFileUploads;
    use WithPagination;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    #[Locked]
    public string $submissionKey = '';

    #[Url(as: 'lihat', except: null)]
    public ?string $selectedFeedbackId = null;

    public ?string $successMessage = null;

    public function mount(Request $request): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        $this->submissionKey = (string) Str::uuid();
        $this->appFeedbackForm()->fill($this->defaultFormState($request));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('app-feedback.form.heading'))
                    ->description(__('app-feedback.form.description'))
                    ->schema([
                        Select::make('category')
                            ->label(__('app-feedback.form.category'))
                            ->options(AppFeedbackCategory::options())
                            ->required(),
                        TextInput::make('title')
                            ->label(__('app-feedback.form.title'))
                            ->required()
                            ->minLength(3)
                            ->maxLength(180),
                        Textarea::make('description')
                            ->label(__('app-feedback.form.description_field'))
                            ->required()
                            ->minLength(10)
                            ->maxLength(5000)
                            ->rows(6)
                            ->helperText(__('app-feedback.form.description_help'))
                            ->columnSpanFull(),
                        Textarea::make('details.steps')
                            ->label(__('app-feedback.form.details_steps'))
                            ->maxLength(2000)
                            ->rows(4)
                            ->visible(fn (Get $get): bool => $get('category') === AppFeedbackCategory::Bug->value)
                            ->columnSpanFull(),
                        Textarea::make('details.expected')
                            ->label(__('app-feedback.form.details_expected'))
                            ->maxLength(2000)
                            ->rows(3)
                            ->visible(fn (Get $get): bool => $get('category') === AppFeedbackCategory::Bug->value)
                            ->columnSpanFull(),
                        Textarea::make('details.actual')
                            ->label(__('app-feedback.form.details_actual'))
                            ->maxLength(2000)
                            ->rows(3)
                            ->visible(fn (Get $get): bool => $get('category') === AppFeedbackCategory::Bug->value)
                            ->columnSpanFull(),
                        Textarea::make('details.problem')
                            ->label(__('app-feedback.form.details_problem'))
                            ->maxLength(2000)
                            ->rows(4)
                            ->visible(fn (Get $get): bool => in_array($get('category'), [AppFeedbackCategory::FeatureRequest->value, AppFeedbackCategory::UiUx->value, AppFeedbackCategory::Performance->value], true))
                            ->columnSpanFull(),
                        Textarea::make('details.suggestion')
                            ->label(__('app-feedback.form.details_suggestion'))
                            ->maxLength(2000)
                            ->rows(4)
                            ->visible(fn (Get $get): bool => in_array($get('category'), [AppFeedbackCategory::FeatureRequest->value, AppFeedbackCategory::UiUx->value], true))
                            ->columnSpanFull(),
                        TextInput::make('page_url')
                            ->label(__('app-feedback.form.page_url'))
                            ->maxLength(2048)
                            ->readOnly()
                            ->dehydrated()
                            ->helperText(__('app-feedback.form.page_url_help'))
                            ->columnSpanFull(),
                        Toggle::make('include_diagnostics')
                            ->label(__('app-feedback.form.diagnostics'))
                            ->helperText(__('app-feedback.form.diagnostics_help')),
                        SpatieMediaLibraryFileUpload::make('evidence')
                            ->label(__('app-feedback.form.evidence'))
                            ->helperText(__('app-feedback.form.evidence_help'))
                            ->collection('evidence')
                            ->disk('app_feedback_private')
                            ->visibility('private')
                            ->multiple()
                            ->maxFiles(8)
                            ->maxSize((int) ceil(((int) config('media-library.max_file_size', 10 * 1024 * 1024)) / 1024))
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                            ->customHeaders(['CacheControl' => 'private, no-store'])
                            ->storeFiles(false)
                            ->dehydrated()
                            ->saveRelationshipsUsing(null)
                            ->loadStateFromRelationshipsUsing(null)
                            ->previewable(false)
                            ->openable(false)
                            ->downloadable(false)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public function submit(SubmitAppFeedbackAction $submitAppFeedbackAction): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        $state = $this->appFeedbackForm()->getState();
        $state['submission_key'] = $this->submissionKey;

        try {
            $feedback = $submitAppFeedbackAction->handle($user, $state, request());
        } catch (AuthorizationException) {
            $this->addError('data.category', __('app-feedback.flash.blocked'));

            return;
        } catch (ValidationException $exception) {
            $this->mapValidationErrors($exception);

            return;
        }

        $this->successMessage = __('app-feedback.flash.submitted');
        $this->selectedFeedbackId = (string) $feedback->getKey();
        $this->submissionKey = (string) Str::uuid();
        $this->appFeedbackForm()->fill($this->defaultFormState(request()));
        $this->resetPage('feedback_page');
    }

    /**
     * @return LengthAwarePaginator<int, AppFeedback>
     */
    #[Computed]
    public function history(): LengthAwarePaginator
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return AppFeedback::query()
            ->where('reporter_id', $user->getKey())
            ->with('media')
            ->latest('created_at')
            ->paginate(perPage: 5, pageName: 'feedback_page');
    }

    #[Computed]
    public function selectedFeedback(): ?AppFeedback
    {
        if (! is_string($this->selectedFeedbackId) || $this->selectedFeedbackId === '') {
            return null;
        }

        if (! Str::isUuid($this->selectedFeedbackId)) {
            abort(404);
        }

        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return AppFeedback::query()
            ->where('reporter_id', $user->getKey())
            ->with('media')
            ->findOrFail($this->selectedFeedbackId);
    }

    public function render(): View
    {
        return view('livewire.pages.app-feedback.index', [
            'canSubmit' => Gate::allows('create', AppFeedback::class),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultFormState(Request $request): array
    {
        $dari = is_string($request->query('dari')) ? $request->query('dari') : null;

        return [
            'category' => AppFeedbackCategory::Bug->value,
            'title' => '',
            'description' => '',
            'page_url' => AppFeedbackInput::sanitizePageUrl($dari, $request)
                ?? AppFeedbackInput::sanitizePageUrl($request->headers->get('referer'), $request),
            'include_diagnostics' => false,
            'details' => [],
            'evidence' => [],
        ];
    }

    private function mapValidationErrors(ValidationException $exception): void
    {
        foreach ($exception->errors() as $key => $messages) {
            $field = match ($key) {
                'quota', 'submission_key' => 'data.category',
                default => str_starts_with($key, 'data.') ? $key : 'data.'.$key,
            };

            foreach ($messages as $message) {
                $this->addError($field, $message);
            }
        }
    }

    private function appFeedbackForm(): Schema
    {
        return $this->getForm('form') ?? throw new \RuntimeException('App feedback form is not available.');
    }
}

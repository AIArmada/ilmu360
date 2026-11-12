<?php

declare(strict_types=1);

namespace App\Features\Feedback\EntryPoints\Livewire\Pages\AppFeedback;

use App\Features\Feedback\Actions\PostAppFeedbackMessageAction;
use App\Features\Feedback\Actions\SubmitAppFeedbackAction;
use App\Features\Feedback\Enums\AppFeedbackCategory;
use App\Features\Feedback\Models\AppFeedback;
use App\Features\Feedback\Support\AppFeedbackInput;
use App\Platform\Media\MediaEvidence;
use App\Platform\Ui\InteractsWithToasts;
use App\Shared\Auth\AccountActor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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

    public ?string $formError = null;

    /** @var array<string, string> */
    public array $replyBodies = [];

    public function mount(Request $request): void
    {
        $user = auth()->user();

        abort_unless($user instanceof AccountActor, 403);

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
                            ->placeholder(__('app-feedback.form.category_placeholder'))
                            ->live()
                            ->required(),
                        TextInput::make('title')
                            ->label(__('app-feedback.form.title'))
                            ->placeholder(__('app-feedback.form.title_placeholder'))
                            ->required()
                            ->minLength(3)
                            ->maxLength(180),
                        Textarea::make('description')
                            ->label(__('app-feedback.form.description_field'))
                            ->placeholder(__('app-feedback.form.description_placeholder'))
                            ->required()
                            ->minLength(10)
                            ->maxLength(5000)
                            ->rows(4)
                            ->helperText(__('app-feedback.form.description_help'))
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make(__('app-feedback.form.additional_details'))
                    ->description(__('app-feedback.form.additional_details_help'))
                    ->collapsed()
                    ->visible(fn (Get $get): bool => ! $this->isCategory($get('category'), AppFeedbackCategory::Other))
                    ->schema([
                        Textarea::make('details.steps')
                            ->label(__('app-feedback.form.details_steps'))
                            ->placeholder(__('app-feedback.form.details_steps_placeholder'))
                            ->maxLength(2000)
                            ->rows(4)
                            ->visible(fn (Get $get): bool => $this->isCategory($get('category'), AppFeedbackCategory::Bug))
                            ->columnSpanFull(),
                        Textarea::make('details.expected')
                            ->label(__('app-feedback.form.details_expected'))
                            ->placeholder(__('app-feedback.form.details_expected_placeholder'))
                            ->maxLength(2000)
                            ->rows(3)
                            ->visible(fn (Get $get): bool => $this->isCategory($get('category'), AppFeedbackCategory::Bug)),
                        Textarea::make('details.actual')
                            ->label(__('app-feedback.form.details_actual'))
                            ->placeholder(__('app-feedback.form.details_actual_placeholder'))
                            ->maxLength(2000)
                            ->rows(3)
                            ->visible(fn (Get $get): bool => $this->isCategory($get('category'), AppFeedbackCategory::Bug)),
                        Textarea::make('details.problem')
                            ->label(__('app-feedback.form.details_problem'))
                            ->placeholder(__('app-feedback.form.details_problem_placeholder'))
                            ->maxLength(2000)
                            ->rows(4)
                            ->visible(fn (Get $get): bool => $this->isAnyCategory($get('category'), [AppFeedbackCategory::FeatureRequest, AppFeedbackCategory::UiUx, AppFeedbackCategory::Performance]))
                            ->columnSpanFull(),
                        Textarea::make('details.suggestion')
                            ->label(__('app-feedback.form.details_suggestion'))
                            ->placeholder(__('app-feedback.form.details_suggestion_placeholder'))
                            ->maxLength(2000)
                            ->rows(4)
                            ->visible(fn (Get $get): bool => $this->isAnyCategory($get('category'), [AppFeedbackCategory::FeatureRequest, AppFeedbackCategory::UiUx]))
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make(__('app-feedback.form.supporting_context'))
                    ->schema([
                        TextInput::make('page_url')
                            ->label(__('app-feedback.form.page_url'))
                            ->maxLength(2048)
                            ->dehydrated()
                            ->helperText(fn (): string => filled($this->data['page_url'] ?? null)
                                ? __('app-feedback.form.page_url_captured')
                                : __('app-feedback.form.page_url_unavailable'))
                            ->columnSpanFull(),
                        MediaEvidence::configureUpload(
                            SpatieMediaLibraryFileUpload::make(MediaEvidence::COLLECTION)
                                ->label(__('app-feedback.form.evidence'))
                                ->helperText(__('app-feedback.form.evidence_help', [
                                    'size' => (int) ceil(((int) config('media-library.max_file_size', 10 * 1024 * 1024)) / 1024 / 1024),
                                ])),
                            disk: MediaEvidence::PRIVATE_DISK,
                            visibility: 'private',
                            withConversion: false,
                        )
                            ->rule(MediaEvidence::extensionRule())
                            ->customHeaders(['CacheControl' => 'private, no-store'])
                            ->storeFiles(false)
                            ->dehydrated()
                            ->saveRelationshipsUsing(null)
                            ->loadStateFromRelationshipsUsing(null)
                            ->previewable()
                            ->imageEditor()
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

        abort_unless($user instanceof AccountActor, 403);

        $state = $this->appFeedbackForm()->getState();
        $state['submission_key'] = $this->submissionKey;
        $this->formError = null;

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
        $this->dispatch('app-feedback-submitted');
    }

    public function postReply(string $feedbackId, PostAppFeedbackMessageAction $postReply): void
    {
        $user = auth()->user();

        abort_unless($user instanceof AccountActor, 403);

        if (! Str::isUuid($feedbackId)) {
            abort(404);
        }

        $feedback = AppFeedback::query()
            ->where('reporter_id', $user->actorId())
            ->findOrFail($feedbackId);

        $body = trim((string) ($this->replyBodies[$feedbackId] ?? ''));

        try {
            $postReply->handle($user, $feedback, $body);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->addError("replyBodies.{$feedbackId}", is_string($message) ? $message : (string) $message);
                }
            }

            return;
        }

        unset($this->replyBodies[$feedbackId]);
        unset($this->history);
        unset($this->selectedFeedback);
    }

    /**
     * @return LengthAwarePaginator<int, AppFeedback>
     */
    #[Computed]
    public function history(): LengthAwarePaginator
    {
        $user = auth()->user();

        abort_unless($user instanceof AccountActor, 403);

        return AppFeedback::query()
            ->where('reporter_id', $user->actorId())
            ->with(['media', 'visibleMessages'])
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

        abort_unless($user instanceof AccountActor, 403);

        return AppFeedback::query()
            ->where('reporter_id', $user->actorId())
            ->with(['media', 'visibleMessages'])
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
            'details' => [],
            'evidence' => [],
        ];
    }

    private function isCategory(mixed $state, AppFeedbackCategory $category): bool
    {
        return $state === $category || $state === $category->value;
    }

    /**
     * @param  list<AppFeedbackCategory>  $categories
     */
    private function isAnyCategory(mixed $state, array $categories): bool
    {
        return array_any($categories, fn ($category) => $this->isCategory($state, $category));
    }

    private function mapValidationErrors(ValidationException $exception): void
    {
        foreach ($exception->errors() as $key => $messages) {
            if (in_array($key, ['quota', 'submission_key'], true)) {
                foreach ($messages as $message) {
                    $this->formError = is_string($message) ? $message : (string) $message;
                }

                continue;
            }

            $field = str_starts_with($key, 'data.') ? $key : 'data.'.$key;

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

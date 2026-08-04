<?php

namespace App\Livewire\Pages\Reports;

use App\Actions\Contributions\ResolveContributionSubjectAction;
use App\Actions\Reports\ResolveReporterFingerprintAction;
use App\Actions\Reports\ResolveReportFormContextAction;
use App\Actions\Reports\SubmitReportAction;
use App\Enums\ContributionSubjectType;
use App\Livewire\Concerns\InteractsWithToasts;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;

#[Layout('layouts.app')]
class Create extends Component implements HasForms
{
    use InteractsWithForms;
    use InteractsWithToasts;

    public Event|Institution|Reference|Person $entity;

    public string $subjectType;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var array{subject_label: string, subject_title: string, category_options: array<string, string>, redirect_url: string, default_category: string, profile_image_url: string|null} */
    public array $context = [
        'subject_label' => '',
        'subject_title' => '',
        'category_options' => [],
        'redirect_url' => '',
        'default_category' => '',
        'profile_image_url' => null,
    ];

    public function mount(
        string $subjectType,
        string $subjectId,
        ResolveContributionSubjectAction $resolveContributionSubjectAction,
        ResolveReportFormContextAction $resolveReportFormContextAction,
    ): void {
        $resolvedSubjectType = ContributionSubjectType::fromRouteSegment($subjectType);

        abort_unless($resolvedSubjectType instanceof ContributionSubjectType, 404);

        $this->subjectType = $resolvedSubjectType->value;
        $this->entity = $resolveContributionSubjectAction->handle($this->subjectType, $subjectId);
        $this->context = $resolveReportFormContextAction->handle($this->subjectType, $this->entity);

        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        if (! $user->canSubmitDirectoryFeedback()) {
            abort(403, $user->directoryFeedbackBanMessage());
        }

        if ($this->shouldRedirectToCanonicalSubjectUrl($resolvedSubjectType, $subjectId)) {
            $this->redirectRoute('reports.create', [
                'subjectType' => $resolvedSubjectType->publicRouteSegment(),
                'subjectId' => $this->canonicalSubjectId(),
            ], navigate: true);

            return;
        }

        $this->reportForm()->fill([
            'category' => $this->context['default_category'],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Report this :subject', ['subject' => strtolower($this->context['subject_label'])]))
                    ->description(__('Gunakan borang ini jika rekod palsu, tidak tepat, tidak selamat, atau mengelirukan. Laporan akan disemak oleh penyemak.'))
                    ->schema([
                        Select::make('category')
                            ->label(__('Jenis Isu'))
                            ->options($this->context['category_options'])
                            ->required(),
                        Textarea::make('description')
                            ->label(__('Butiran'))
                            ->rows(6)
                            ->maxLength(2000)
                            ->helperText(__('Tambah konteks jika isu tidak jelas.'))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public function submit(
        SubmitReportAction $submitReportAction,
        ResolveReporterFingerprintAction $resolveReporterFingerprintAction,
    ): void {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        if (! $user->canSubmitDirectoryFeedback()) {
            abort(403, $user->directoryFeedbackBanMessage());
        }

        $state = $this->reportForm()->getState();

        if (($state['category'] ?? null) === 'other' && blank($state['description'] ?? null)) {
            $this->addError('data.description', __('Sila terangkan isu supaya penyemak tahu perkara yang perlu disahkan.'));

            return;
        }

        try {
            $submitReportAction->handle(
                $this->entity,
                $this->subjectType,
                $user,
                $resolveReporterFingerprintAction->handle(request()),
                (string) $state['category'],
                filled($state['description'] ?? null) ? (string) $state['description'] : null,
                request(),
            );
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'duplicate_report') {
                throw $exception;
            }

            $this->addError('data.category', __('Anda sudah melaporkan rekod ini dalam tempoh 24 jam terakhir.'));

            return;
        }

        $this->redirect($this->context['redirect_url'], navigate: true);
    }

    public function rendering(object $view): void
    {
        if (method_exists($view, 'title')) {
            $view->title(__('Laporkan :subject', ['subject' => $this->context['subject_label']]).' - '.config('app.name'));
        }
    }

    protected function reportForm(): Schema
    {
        return $this->getForm('form') ?? throw new RuntimeException('Report form is not available.');
    }

    private function canonicalSubjectId(): string
    {
        return match (true) {
            $this->entity instanceof Institution => $this->entity->slug,
            $this->entity instanceof Person => $this->entity->slug,
            $this->entity instanceof Reference => $this->entity->slug,
            default => $this->entity->slug,
        };
    }

    private function shouldRedirectToCanonicalSubjectUrl(ContributionSubjectType $subjectType, string $subjectId): bool
    {
        $routeSubjectType = request()->route('subjectType');

        if (! is_string($routeSubjectType) || $routeSubjectType === '') {
            return false;
        }

        return $subjectType->publicRouteSegment() !== $routeSubjectType
            || $this->canonicalSubjectId() !== $subjectId;
    }
}

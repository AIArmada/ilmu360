<?php

namespace App\Livewire\Pages\Dashboard\Events;

use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Events\Enums\RegistrationMode;
use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use AIArmada\Seating\Enums\SeatingMode;
use AIArmada\Ticketing\Enums\PricingMode;
use App\Actions\Events\CreateManagedEventAction;
use App\Actions\Events\PrepareAdvancedParentProgramSubmissionAction;
use App\Actions\Events\ResolveAdvancedBuilderContextAction;
use App\Contracts\EventCategoryCatalog;
use App\Contracts\EventCategoryPolicyResolver;
use App\Contracts\SpaceEligibilityResolver;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventPrayerTime;
use App\Enums\EventTaxonomyCode;
use App\Enums\EventVisibility;
use App\Enums\RegistrationScope;
use App\Enums\TaxonomyTerm\DomainTermCode;
use App\Forms\Components\Select;
use App\Models\Reference;
use App\Models\User;
use App\Models\Venue;
use App\Support\Cache\SelectionCatalogCache;
use App\Support\Language\MalaysiaLanguageCatalog;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;
use Throwable;

#[Layout('layouts.app')]
#[Title('Create Advanced Event')]
class CreateAdvanced extends Component implements HasForms
{
    use InteractsWithForms;
    use WithFileUploads;

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
    public array $personOptions = [];

    #[Url(as: 'person', except: '')]
    public string $prefillPersonId = '';

    #[Locked]
    public string $contextInstitutionId = '';

    #[Locked]
    public string $contextPersonId = '';

    public ?TemporaryUploadedFile $cover = null;

    public ?TemporaryUploadedFile $poster = null;

    /** @var array<int, TemporaryUploadedFile> */
    public array $gallery = [];

    public function mount(): void
    {
        abort_unless(auth()->check(), 403);

        $user = $this->currentUser();

        abort_unless($user instanceof User, 403);

        $requestedInstitutionId = request()->query('institution');
        $requestedPersonId = request()->query('person');
        $builderContext = app(ResolveAdvancedBuilderContextAction::class)->handle(
            $user,
            is_string($requestedInstitutionId) ? $requestedInstitutionId : null,
            is_string($requestedPersonId) ? $requestedPersonId : null,
        );

        $this->institutionOptions = $builderContext['institution_options'];
        $this->personOptions = $builderContext['person_options'];
        $this->prefillPersonId = is_string($requestedPersonId)
            && array_key_exists($requestedPersonId, $this->personOptions)
            ? $requestedPersonId
            : '';

        abort_unless($this->hasBuilderAccess(), 403);

        $this->form = $builderContext['default_form'];
        $this->contextInstitutionId = is_string($requestedInstitutionId)
            && array_key_exists($requestedInstitutionId, $this->institutionOptions)
            ? $requestedInstitutionId
            : '';
        $this->contextPersonId = $this->contextInstitutionId === '' && $this->prefillPersonId !== ''
            ? $this->prefillPersonId
            : '';

        $this->applyContextDefaults();
        $this->form['pricing_mode'] = PricingMode::Free->value;
        $this->form['tickets'] = [$this->defaultTicket()];
        $this->form['seating'] = [
            'mode' => SeatingMode::GeneralAdmission->value,
            'map_name' => 'Main seating map',
            'sections' => [$this->defaultSection()],
        ];

        $this->eventForm()->fill($this->form);
    }

    protected function eventForm(): Schema
    {
        return $this->getForm('form') ?? throw new RuntimeException('Advanced event form is not available.');
    }

    public function form(Schema $schema): Schema
    {
        $contextIsLocked = $this->contextInstitutionId !== '' || $this->contextPersonId !== '';

        return $schema
            ->components([
                Wizard::make([
                    Step::make(__('Maklumat asas'))
                        ->description(__('Namakan majlis dan sahkan penganjur.'))
                        ->icon('heroicon-o-document-text')
                        ->schema([
                            Section::make(__('Tentang majlis ini'))
                                ->description(__('Mulakan dengan maklumat yang orang akan nampak dalam senarai majlis.'))
                                ->schema([
                                    TextInput::make('title')
                                        ->label(__('Tajuk majlis'))
                                        ->placeholder(__('Contoh: Kuliah Maghrib Mingguan'))
                                        ->required()
                                        ->maxLength(255)
                                        ->columnSpanFull(),
                                    RichEditor::make('description')
                                        ->label(__('Keterangan'))
                                        ->placeholder(__('Terangkan tujuan majlis dan apa yang akan peserta dapat.'))
                                        ->maxLength(5000)
                                        ->disableToolbarButtons(['table'])
                                        ->floatingToolbars([])
                                        ->columnSpanFull(),
                                ])
                                ->columns(2),
                            Section::make(__('Penganjur'))
                                ->description(__('Penganjur ini menentukan ruang kerja dan pemilikan majlis.'))
                                ->schema([
                                    Select::make('primary_organizer_id')
                                        ->label(__('Penganjur'))
                                        ->placeholder(__('Pilih penganjur'))
                                        ->options(fn (): array => $this->organizerOptions())
                                        ->searchable()
                                        ->preload()
                                        ->native(false)
                                        ->required()
                                        ->disabled($contextIsLocked)
                                        ->dehydrated()
                                        ->helperText($contextIsLocked
                                            ? __('Penganjur ditetapkan berdasarkan halaman yang anda buka.')
                                            : __('Pilih institusi atau penceramah yang anda uruskan.'))
                                        ->afterStateUpdatedJs(<<<'JS'
                                            const selected = String($state || '')
                                            const institutionIds = $get('__institution_ids') || []
                                            $set('primary_organizer_kind', institutionIds.includes(selected) ? 'institution' : 'person')
                                            if (institutionIds.includes(selected)) {
                                                $set('location_institution_id', selected)
                                            }
                                        JS)
                                        ->columnSpanFull(),
                                    Hidden::make('primary_organizer_kind')->dehydrated(),
                                    Select::make('visibility')
                                        ->label(__('Keterlihatan'))
                                        ->options($this->visibilityOptions())
                                        ->native(false)
                                        ->required()
                                        ->helperText(__('Pilih siapa yang boleh menemui majlis ini.')),
                                ])
                                ->columns(2),
                            Hidden::make('__institution_ids')
                                ->default(array_keys($this->institutionOptions))
                                ->dehydrated(false),
                        ]),
                    Step::make(__('Tarikh & butiran'))
                        ->description(__('Tetapkan program dan sesi pertama.'))
                        ->icon('heroicon-o-calendar-days')
                        ->schema([
                            Section::make(__('Tempoh keseluruhan program'))
                                ->description(__('Gunakan tempoh ini untuk menempatkan semua sesi yang akan anda tambah kemudian.'))
                                ->schema([
                                    DateTimePicker::make('program_starts_at')
                                        ->label(__('Mula program'))
                                        ->required()
                                        ->native()
                                        ->seconds(false)
                                        ->minutesStep(5)
                                        ->format('Y-m-d\TH:i'),
                                    DateTimePicker::make('program_ends_at')
                                        ->label(__('Tamat program'))
                                        ->required()
                                        ->native()
                                        ->seconds(false)
                                        ->minutesStep(5)
                                        ->format('Y-m-d\TH:i'),
                                    TextInput::make('timezone')
                                        ->label(__('Zon waktu'))
                                        ->helperText(__('Contoh: Asia/Kuala_Lumpur'))
                                        ->required()
                                        ->columnSpanFull(),
                                ])
                                ->columns(2),
                            Section::make(__('Sesi pertama'))
                                ->description(__('Ini ialah sesi pertama yang akan dipaparkan. Tambah sesi lain selepas majlis dicipta.'))
                                ->schema([
                                    Select::make('submission_country_id')
                                        ->label(__('Negara'))
                                        ->options(fn (): array => $this->countryOptions())
                                        ->searchable()
                                        ->preload()
                                        ->native(false)
                                        ->required(),
                                    DatePicker::make('event_date')
                                        ->label(__('Tarikh sesi pertama'))
                                        ->required()
                                        ->native()
                                        ->minDate(now()->startOfDay()),
                                    Select::make('prayer_time')
                                        ->label(__('Waktu mula'))
                                        ->options(fn (): array => $this->prayerTimeOptions())
                                        ->native(false)
                                        ->required()
                                        ->live()
                                        ->afterStateUpdatedJs(<<<'JS'
                                            if ($state !== 'lain_waktu') {
                                                $set('custom_time', null)
                                            }
                                        JS),
                                    TimePicker::make('custom_time')
                                        ->label(__('Masa mula'))
                                        ->helperText(__('Isi masa apabila memilih Lain waktu.'))
                                        ->native()
                                        ->seconds(false)
                                        ->minutesStep(5)
                                        ->visibleJs("\$get('prayer_time') === 'lain_waktu'")
                                        ->required(fn (Get $get): bool => $get('prayer_time') === EventPrayerTime::LainWaktu->value),
                                    TimePicker::make('end_time')
                                        ->label(__('Masa tamat'))
                                        ->helperText(__('Pilihan: anggaran masa majlis berakhir.'))
                                        ->native()
                                        ->seconds(false)
                                        ->minutesStep(5),
                                ])
                                ->columns(2),
                            Section::make(__('Jenis majlis'))
                                ->description(__('Pilih kategori dan cara majlis berlangsung.'))
                                ->schema([
                                    Select::make('default_event_category_ids')
                                        ->label(__('Kategori'))
                                        ->options(fn (): array => app(EventCategoryCatalog::class)->options())
                                        ->placeholder(__('Pilih satu atau lebih kategori'))
                                        ->multiple()
                                        ->live()
                                        ->searchable()
                                        ->preload()
                                        ->native(false)
                                        ->required()
                                        ->columnSpan(2),
                                    Select::make('default_event_format')
                                        ->label(__('Format'))
                                        ->options($this->eventFormatOptions())
                                        ->native(false)
                                        ->required(),
                                    TextInput::make('event_url')
                                        ->label(__('Pautan majlis / pendaftaran'))
                                        ->url()
                                        ->placeholder('https://')
                                        ->helperText(__('Pilihan: laman penerangan atau borang pendaftaran luar.')),
                                    TextInput::make('live_url')
                                        ->label(__('Pautan siaran langsung'))
                                        ->url()
                                        ->placeholder('https://')
                                        ->visibleJs("['online', 'hybrid'].includes(\$get('default_event_format'))"),
                                ])
                                ->columns(2),
                        ]),
                    Step::make(__('Sasaran & penemuan'))
                        ->description(__('Bantu orang yang betul menemui majlis ini.'))
                        ->icon('heroicon-o-users')
                        ->schema([
                            Section::make(__('Sasaran peserta'))
                                ->description(__('Maklumat ini digunakan pada carian dan halaman awam majlis.'))
                                ->schema([
                                    Select::make('domain_tags')
                                        ->label(__('Topik / bidang'))
                                        ->options(fn (): array => $this->tagOptions(EventTaxonomyCode::Domain))
                                        ->placeholder(__('Pilih bidang utama'))
                                        ->native(false)
                                        ->searchable()
                                        ->preload()
                                        ->required($this->tagOptions(EventTaxonomyCode::Domain) !== [])
                                        ->helperText(__('Agama & Kerohanian akan membuka pilihan topik dan rujukan tambahan.')),
                                    Select::make('gender')
                                        ->label(__('Jantina'))
                                        ->options($this->genderOptions())
                                        ->native(false)
                                        ->required(),
                                    Select::make('age_group')
                                        ->label(__('Kumpulan umur'))
                                        ->options($this->ageGroupOptions())
                                        ->multiple()
                                        ->closeOnSelect()
                                        ->native(false)
                                        ->required()
                                        ->helperText(__('Semua Peringkat Umur menggantikan empat kumpulan umur khusus.'))
                                        ->afterStateUpdatedJs(<<<'JS'
                                            const ageGroups = Array.isArray($state) ? $state : []
                                            const allAges = 'all_ages'
                                            const specificAgeGroups = ['adults', 'youth', 'children', 'warga_emas']
                                            let normalizedAgeGroups = ageGroups

                                            if (ageGroups.includes(allAges)) {
                                                normalizedAgeGroups = [allAges]
                                            } else if (specificAgeGroups.every((group) => ageGroups.includes(group))) {
                                                normalizedAgeGroups = [allAges]
                                            }

                                            if (JSON.stringify(normalizedAgeGroups) !== JSON.stringify(ageGroups)) {
                                                $set('age_group', normalizedAgeGroups)
                                            }

                                            if (normalizedAgeGroups.includes('children') || normalizedAgeGroups.includes(allAges)) {
                                                $set('children_allowed', true)
                                            }
                                        JS),
                                    Select::make('languages')
                                        ->label(__('Bahasa'))
                                        ->options(fn (): array => $this->languageOptions())
                                        ->multiple()
                                        ->searchable()
                                        ->preload()
                                        ->native(false)
                                        ->required()
                                        ->helperText(__('Pilih semua bahasa yang digunakan dalam majlis.'))
                                        ->columnSpanFull(),
                                    Toggle::make('children_allowed')
                                        ->label(__('Kanak-kanak dibenarkan'))
                                        ->helperText(__('Dikunci apabila kumpulan umur merangkumi kanak-kanak atau semua peringkat umur.'))
                                        ->inline(false)
                                        ->disabled(fn (Get $get): bool => $this->ageGroupsIncludeChildren($get('age_group')))
                                        ->extraAlpineAttributes([
                                            'x-bind:disabled' => <<<'JS'
                                                ($get('age_group') || []).includes('children') || ($get('age_group') || []).includes('all_ages')
                                            JS,
                                        ])
                                        ->dehydrated(),
                                    Toggle::make('is_muslim_only')
                                        ->label(__('Terbuka untuk Muslim sahaja'))
                                        ->helperText(__('Tandakan jika penyertaan terhad kepada Muslim.'))
                                        ->inline(false)
                                        ->visibleJs($this->religiousTopicVisibilityJs()),
                                ])
                                ->columns(2),
                            Section::make(__('Topik & rujukan'))
                                ->description(__('Pilihan ini hanya diperlukan untuk bidang Agama & Kerohanian.'))
                                ->visibleJs($this->religiousTopicVisibilityJs())
                                ->schema([
                                    Select::make('discipline_tags')
                                        ->label(__('Topik lebih khusus'))
                                        ->options(fn (): array => $this->tagOptions(EventTaxonomyCode::Discipline))
                                        ->multiple()
                                        ->searchable()
                                        ->preload()
                                        ->native(false),
                                    Select::make('source_tags')
                                        ->label(__('Sumber utama'))
                                        ->options(fn (): array => $this->tagOptions(EventTaxonomyCode::Source))
                                        ->multiple()
                                        ->searchable()
                                        ->preload()
                                        ->native(false),
                                    Select::make('issue_tags')
                                        ->label(__('Tema / isu'))
                                        ->options(fn (): array => $this->tagOptions(EventTaxonomyCode::Issue))
                                        ->multiple()
                                        ->searchable()
                                        ->preload()
                                        ->native(false),
                                    Select::make('references')
                                        ->label(__('Rujukan kitab / buku'))
                                        ->options(fn (): array => $this->referenceOptions())
                                        ->multiple()
                                        ->searchable()
                                        ->preload()
                                        ->native(false),
                                ])
                                ->columns(2),
                        ]),
                    Step::make(__('Lokasi & individu'))
                        ->description(__('Pilih tempat dan individu yang terlibat.'))
                        ->icon('heroicon-o-map-pin')
                        ->schema([
                            Section::make(__('Lokasi'))
                                ->description(__('Pilih institusi atau venue sebenar. Jangan risau jika penganjur ialah penceramah — lokasi boleh berbeza.'))
                                ->schema([
                                    Radio::make('location_type')
                                        ->label(__('Jenis lokasi'))
                                        ->options([
                                            'institution' => __('Institusi'),
                                            'venue' => __('Venue / tempat lain'),
                                        ])
                                        ->inline()
                                        ->default('institution')
                                        ->live(),
                                    Select::make('location_institution_id')
                                        ->label(__('Institusi'))
                                        ->options(fn (): array => $this->institutionOptions)
                                        ->searchable()
                                        ->preload()
                                        ->native(false)
                                        ->visibleJs("\$get('location_type') === 'institution'")
                                        ->required(fn (Get $get): bool => $get('location_type') === 'institution')
                                        ->disabled($this->contextInstitutionId !== '')
                                        ->dehydrated(),
                                    Select::make('location_venue_id')
                                        ->label(__('Venue'))
                                        ->options(fn (): array => $this->venueOptions())
                                        ->searchable()
                                        ->preload()
                                        ->native(false)
                                        ->visibleJs("\$get('location_type') === 'venue'")
                                        ->required(fn (Get $get): bool => $get('location_type') === 'venue')
                                        ->live(),
                                    Select::make('space_ids')
                                        ->label(__('Ruang / kawasan'))
                                        ->options(fn (Get $get): array => $this->spaceOptionsForState($get))
                                        ->multiple()
                                        ->searchable()
                                        ->preload()
                                        ->native(false)
                                        ->helperText(__('Pilihan: dewan, bilik, ruang solat, atau kawasan tertentu.'))
                                        ->columnSpanFull(),
                                ])
                                ->columns(2),
                            Section::make(__('Penceramah'))
                                ->description(__('Pilih semua penceramah yang perlu dipaparkan pada halaman majlis.'))
                                ->schema([
                                    Select::make('persons')
                                        ->label(__('Penceramah / individu terlibat'))
                                        ->options(fn (): array => $this->personOptions)
                                        ->multiple()
                                        ->searchable()
                                        ->preload()
                                        ->native(false)
                                        ->helperText(fn (Get $get): string => $this->categoriesRequirePersons($get('default_event_category_ids'))
                                            ? __('Sekurang-kurangnya seorang penceramah diperlukan untuk kategori ini.')
                                            : __('Kosongkan jika majlis ini tidak mempunyai penceramah khusus.'))
                                        ->required(fn (Get $get): bool => $this->categoriesRequirePersons($get('default_event_category_ids'))),
                                    Repeater::make('other_key_people')
                                        ->label(__('Peranan lain'))
                                        ->helperText(__('Tambahkan moderator, imam, khatib, bilal, atau PIC jika berkenaan.'))
                                        ->schema([
                                            Select::make('role_code')
                                                ->label(__('Peranan'))
                                                ->options(EventKeyPersonRole::nonSpeakerOptions())
                                                ->native(false)
                                                ->required(),
                                            Select::make('involveable_id')
                                                ->label(__('Pautkan profil penceramah'))
                                                ->options(fn (): array => $this->personOptions)
                                                ->searchable()
                                                ->preload()
                                                ->native(false)
                                                ->afterStateUpdatedJs(<<<'JS'
                                                    $set('display_name', null)
                                                    $set('involveable_type', $state ? 'person' : null)
                                                JS),
                                            Hidden::make('involveable_type')->dehydrated(),
                                            TextInput::make('display_name')
                                                ->label(__('Nama paparan'))
                                                ->maxLength(255)
                                                ->required(fn (Get $get): bool => blank($get('involveable_id')))
                                                ->disabled(fn (Get $get): bool => filled($get('involveable_id')))
                                                ->extraAlpineAttributes([
                                                    'x-bind:disabled' => <<<'JS'
                                                        Boolean($get('involveable_id'))
                                                    JS,
                                                    'x-bind:required' => <<<'JS'
                                                        ! Boolean($get('involveable_id'))
                                                    JS,
                                                ])
                                                ->dehydrated(fn (Get $get): bool => blank($get('involveable_id')))
                                                ->helperText(__('Isi nama jika tiada profil yang boleh dipautkan.')),
                                            Select::make('visibility')
                                                ->label(__('Keterlihatan'))
                                                ->options(['public' => __('Awam'), 'private' => __('Peribadi')])
                                                ->default('public')
                                                ->native(false)
                                                ->required(),
                                            Textarea::make('notes')
                                                ->label(__('Nota peranan'))
                                                ->rows(2)
                                                ->maxLength(500)
                                                ->columnSpanFull(),
                                        ])
                                        ->default([])
                                        ->generateUuidUsing(false)
                                        ->addActionLabel(__('Tambah peranan'))
                                        ->columns(2)
                                        ->columnSpanFull(),
                                ])
                                ->columns(2),
                            Section::make(__('Media'))
                                ->description(__('Tambah bahan yang membantu orang mengenali majlis ini.'))
                                ->schema([
                                    FileUpload::make('cover')
                                        ->label(__('Gambar cover'))
                                        ->image()
                                        ->imageEditor()
                                        ->imageAspectRatio('16:9')
                                        ->panelLayout('integrated')
                                        ->storeFiles(false)
                                        ->maxSize(10240)
                                        ->helperText(__('Nisbah 16:9 untuk paparan halaman majlis.')),
                                    FileUpload::make('poster')
                                        ->label(__('Poster hebahan'))
                                        ->image()
                                        ->imageEditor()
                                        ->imageAspectRatio('3:4')
                                        ->panelLayout('integrated')
                                        ->storeFiles(false)
                                        ->maxSize(10240)
                                        ->helperText(__('Nisbah 3:4 untuk WhatsApp dan media sosial.')),
                                    FileUpload::make('gallery')
                                        ->label(__('Galeri'))
                                        ->multiple()
                                        ->panelLayout('grid')
                                        ->storeFiles(false)
                                        ->maxFiles(10)
                                        ->image()
                                        ->imageEditor()
                                        ->maxSize(10240)
                                        ->helperText(__('Sehingga 10 gambar tambahan.'))
                                        ->columnSpanFull(),
                                ])
                                ->columns(2),
                        ]),
                    Step::make(__('Pendaftaran & tiket'))
                        ->description(__('Aktifkan pendaftaran, pakej, had peserta, dan tempat duduk.'))
                        ->icon('heroicon-o-ticket')
                        ->schema([
                            Callout::make(__('Ciri lanjutan majlis'))
                                ->description(__('Di sini anda boleh mewajibkan pendaftaran, membina beberapa jenis tiket atau pakej, menetapkan harga dan kuota, serta mengaktifkan pelan tempat duduk.'))
                                ->info(),
                            Section::make(__('Cara orang menyertai'))
                                ->description(__('Untuk majlis percuma tanpa had, biarkan pilihan asal.'))
                                ->schema([
                                    Toggle::make('registration_required')
                                        ->label(__('Wajib daftar'))
                                        ->helperText(__('Peserta perlu mendaftar sebelum hadir.'))
                                        ->inline(false)
                                        ->dehydrated(),
                                    Select::make('pricing_mode')
                                        ->label(__('Harga'))
                                        ->options($this->pricingOptions())
                                        ->native(false)
                                        ->required()
                                        ->helperText(__('Majlis berbayar memerlukan pendaftaran.')),
                                    Hidden::make('registration_mode')->dehydrated(),
                                ])
                                ->columns(2),
                            Section::make(__('Tiket & pakej'))
                                ->description(__('Gunakan beberapa jenis tiket untuk pakej VIP, keluarga, pelajar, atau kategori lain.'))
                                ->schema([
                                    Repeater::make('tickets')
                                        ->label(__('Jenis tiket'))
                                        ->schema([
                                            TextInput::make('name')
                                                ->label(__('Nama'))
                                                ->required()
                                                ->maxLength(120),
                                            TextInput::make('price')
                                                ->label(__('Harga'))
                                                ->prefix('RM')
                                                ->required()
                                                ->disabled(fn (Get $get): bool => $get('../../pricing_mode') === PricingMode::Free->value)
                                                ->extraAlpineAttributes([
                                                    'x-bind:disabled' => <<<'JS'
                                                        $get('../../pricing_mode') === 'free'
                                                    JS,
                                                ])
                                                ->dehydrated(),
                                            TextInput::make('quota')
                                                ->label(__('Had peserta'))
                                                ->numeric()
                                                ->minValue(1)
                                                ->placeholder(__('Tiada had')),
                                            TextInput::make('max_quantity')
                                                ->label(__('Maksimum seorang'))
                                                ->numeric()
                                                ->minValue(1),
                                            Select::make('seating_mode')
                                                ->label(__('Tempat duduk'))
                                                ->options($this->seatingOptions())
                                                ->native(false)
                                                ->required()
                                                ->afterStateUpdatedJs(<<<'JS'
                                                    const tickets = Object.values($get('../../tickets') || {})
                                                    const modes = tickets
                                                        .map((ticket) => ticket?.seating_mode || 'none')
                                                        .filter((mode) => mode !== 'none')
                                                    const hasGeneralAdmission = modes.includes('general_admission')
                                                    const hasAssignedSeats = modes.some((mode) => ['assigned', 'hybrid'].includes(mode))
                                                    const seatingMode = hasGeneralAdmission && hasAssignedSeats
                                                        ? 'hybrid'
                                                        : hasGeneralAdmission
                                                            ? 'general_admission'
                                                            : 'assigned'

                                                    $set('../../seating.mode', modes.length > 0 ? seatingMode : 'general_admission')
                                                JS),
                                            TextInput::make('code')
                                                ->label(__('Kod tiket'))
                                                ->maxLength(40),
                                            Textarea::make('description')
                                                ->label(__('Apa yang termasuk'))
                                                ->rows(2)
                                                ->maxLength(1000)
                                                ->columnSpanFull(),
                                        ])
                                        ->default([$this->defaultTicket()])
                                        ->generateUuidUsing(false)
                                        ->collapsed(false)
                                        ->addActionLabel(__('Tambah jenis tiket'))
                                        ->deleteAction(fn (Action $action): Action => $action->requiresConfirmation())
                                        ->columns(2)
                                        ->columnSpanFull(),
                                ]),
                            Callout::make(__('Tempat duduk diaktifkan melalui tiket'))
                                ->description(__('Pilih kaedah tempat duduk selain Tiada pada sekurang-kurangnya satu tiket. Medan pelan tempat duduk akan muncul pada langkah semakan terakhir.'))
                                ->warning()
                                ->visibleJs("!Object.values(\$get('tickets') || {}).some((ticket) => ticket && ticket.seating_mode && ticket.seating_mode !== 'none')"),
                        ]),
                    Step::make(__('Semak & cipta'))
                        ->description(__('Semak ringkasan sebelum meneruskan.'))
                        ->icon('heroicon-o-check-circle')
                        ->schema([
                            Callout::make(__('Selepas anda cipta majlis ini'))
                                ->description(__('Maklumat majlis akan disimpan sebagai satu program. Anda boleh menambah sesi lain daripada halaman majlis selepas sesi pertama dihantar.'))
                                ->info(),
                            Section::make(__('Ringkasan'))
                                ->schema([
                                    Placeholder::make('summary_title')
                                        ->label(__('Tajuk'))
                                        ->content(fn (Get $get): string => (string) ($get('title') ?: __('Belum diisi'))),
                                    Placeholder::make('summary_organizer')
                                        ->label(__('Penganjur'))
                                        ->content(fn (Get $get): string => $this->organizerOptions()[(string) ($get('primary_organizer_id') ?? '')] ?? __('Belum dipilih')),
                                    Placeholder::make('summary_first_session')
                                        ->label(__('Sesi pertama'))
                                        ->content(fn (Get $get): string => trim(implode(' · ', array_filter([
                                            $get('event_date'),
                                            $this->prayerTimeOptions()[(string) ($get('prayer_time') ?? '')] ?? null,
                                            $get('custom_time'),
                                        ]))) ?: __('Belum diisi')),
                                    Placeholder::make('summary_location')
                                        ->label(__('Lokasi'))
                                        ->content(fn (Get $get): string => $this->summaryLocation($get)),
                                ])
                                ->columns(2),
                            Section::make(__('Tempat duduk'))
                                ->description(__('Bahagian ini hanya diperlukan jika sekurang-kurangnya satu tiket menggunakan tempat duduk.'))
                                ->visibleJs("Object.values(\$get('tickets') || {}).some((ticket) => ticket && ticket.seating_mode && ticket.seating_mode !== 'none')")
                                ->schema([
                                    Select::make('seating.mode')
                                        ->label(__('Kaedah tempat duduk'))
                                        ->options($this->seatingOptions())
                                        ->native(false)
                                        ->required(),
                                    TextInput::make('seating.map_name')
                                        ->label(__('Nama pelan'))
                                        ->required()
                                        ->maxLength(120),
                                    Repeater::make('seating.sections')
                                        ->label(__('Bahagian'))
                                        ->schema([
                                            TextInput::make('name')->label(__('Nama bahagian'))->required()->maxLength(120),
                                            TextInput::make('code')->label(__('Kod'))->maxLength(20),
                                            TextInput::make('capacity')->label(__('Kapasiti'))->numeric()->required()->minValue(1),
                                            TextInput::make('rows')->label(__('Baris'))->numeric()->required()->minValue(1),
                                            TextInput::make('seats_per_row')->label(__('Tempat setiap baris'))->numeric()->required()->minValue(1),
                                        ])
                                        ->minItems(1)
                                        ->maxItems(50)
                                        ->generateUuidUsing(false)
                                        ->collapsed(false)
                                        ->addActionLabel(__('Tambah bahagian'))
                                        ->deleteAction(fn (Action $action): Action => $action->requiresConfirmation())
                                        ->columns(2)
                                        ->columnSpanFull(),
                                ])
                                ->columns(2),
                        ]),
                ])
                    ->skippable()
                    ->persistStepInQueryString()
                    ->nextAction(fn (Action $action): Action => $action->label(__('Seterusnya')))
                    ->submitAction(new HtmlString(Blade::render(<<<'BLADE'
                        <x-filament::button type="submit" size="lg" color="success">
                            {{ __('Cipta majlis') }}
                        </x-filament::button>
                    BLADE))),
            ])
            ->statePath('form');
    }

    public function hasSeatingTicket(): bool
    {
        return $this->ticketStateHasSeating($this->form['tickets'] ?? []);
    }

    public function applyTemplate(string $template): void
    {
        $timezone = (string) ($this->form['timezone'] ?? 'Asia/Kuala_Lumpur');
        $startsAt = now($timezone)->addDays(2)->setTime(20, 0);

        $templateState = match ($template) {
            'weekly_series' => [
                'title' => $this->form['title'] ?: __('Weekly Knowledge Series'),
                'description' => $this->form['description'] ?: __('A repeating program with one featured session every week.'),
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
                'description' => $this->form['description'] ?: __('An umbrella program with nightly sessions and lighter weekend highlights.'),
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
        $templateStart = Carbon::parse((string) $templateState['program_starts_at'], $timezone);
        $templateEnd = Carbon::parse((string) $templateState['program_ends_at'], $timezone);
        $this->form['event_date'] = $templateStart->toDateString();
        $this->form['custom_time'] = $templateStart->format('H:i');
        $this->form['end_time'] = $templateEnd->format('H:i');
        $this->eventForm()->fill($this->form);
    }

    public function updatedFormPrimaryOrganizerId(mixed $value): void
    {
        if ($this->contextInstitutionId !== '' || $this->contextPersonId !== '') {
            $this->applyContextDefaults();

            return;
        }

        if (! is_string($value) || $value === '') {
            return;
        }

        if (array_key_exists($value, $this->institutionOptions)) {
            $this->form['primary_organizer_kind'] = 'institution';
            $this->form['location_institution_id'] = $value;

            return;
        }

        if (array_key_exists($value, $this->personOptions)) {
            $this->form['primary_organizer_kind'] = 'person';
        }

        if (! filled($this->form['location_institution_id'] ?? null)) {
            $this->form['location_institution_id'] = array_key_first($this->institutionOptions);
        }
    }

    protected function applyContextDefaults(): void
    {
        if ($this->contextInstitutionId !== '') {
            $this->form['primary_organizer_id'] = $this->contextInstitutionId;
            $this->form['primary_organizer_kind'] = 'institution';
            $this->form['location_institution_id'] = $this->contextInstitutionId;
            $this->form['location_same_as_institution'] = true;
            $this->form['location_type'] = 'institution';
            $this->form['location_venue_id'] = null;

            return;
        }

        if ($this->contextPersonId !== '') {
            $this->form['primary_organizer_id'] = $this->contextPersonId;
            $this->form['primary_organizer_kind'] = 'person';
            $this->form['persons'] = collect([
                ...((array) ($this->form['persons'] ?? [])),
                $this->contextPersonId,
            ])->filter()->unique()->values()->all();
        }
    }

    public function submit(
        CreateManagedEventAction $createManagedEventAction,
        PrepareAdvancedParentProgramSubmissionAction $prepareAdvancedParentProgramSubmissionAction,
    ): mixed {
        $this->applyContextDefaults();
        $this->normalizeFormStateForValidation();
        $validated = $this->validate($this->rules());

        $user = $this->currentUser();

        abort_unless($user instanceof User, 403);

        $form = $validated['form'];
        $form['event_category_ids'] = (array) ($form['event_category_ids'] ?? $form['default_event_category_ids'] ?? []);
        $form['default_event_category_ids'] = $form['event_category_ids'];
        $form['event_format'] = (string) ($form['event_format'] ?? $form['default_event_format'] ?? EventFormat::Physical->value);
        $form['default_event_format'] = $form['event_format'];
        $form['cover'] = $form['cover'] ?? null;
        $form['poster'] = $form['poster'] ?? null;
        $form['gallery'] = $form['gallery'] ?? [];

        $preparedSubmission = $prepareAdvancedParentProgramSubmissionAction->handle(
            $user,
            $form,
            $this->contextInstitutionId !== '' ? $this->contextInstitutionId : null,
            $this->contextPersonId !== '' ? $this->contextPersonId : null,
        );

        try {
            $event = $createManagedEventAction->handle(
                user: $user,
                form: $form,
                startsAt: $preparedSubmission['program_starts_at'],
                endsAt: $preparedSubmission['program_ends_at'],
                timezone: $preparedSubmission['timezone'],
                primaryOrganizer: $preparedSubmission['primary_organizer'],
                locationInstitutionId: $preparedSubmission['location_institution_id'],
                locationVenueId: is_string($form['location_venue_id'] ?? null) ? $form['location_venue_id'] : null,
                organization: null,
                registrationMode: (bool) ($validated['form']['registration_required'] ?? false)
                    ? RegistrationMode::Required
                    : RegistrationMode::None,
                pricingMode: PricingMode::from((string) $validated['form']['pricing_mode']),
                tickets: (array) $validated['form']['tickets'],
                seating: (array) ($this->form['seating'] ?? []),
            );
        } catch (Throwable $throwable) {
            report($throwable);

            $this->addError('form.title', __('The advanced event could not be created. Please try again.'));

            return null;
        }

        $query = ['event' => $event->id];

        if ($this->prefillPersonId !== '') {
            $query['person'] = $this->prefillPersonId;
        }

        return redirect()->route('submit-event.create', $query);
    }

    protected function normalizeFormStateForValidation(): void
    {
        foreach (['cover', 'poster'] as $field) {
            $upload = $this->form[$field] ?? null;

            if (! is_array($upload)) {
                continue;
            }

            $uploads = array_values($upload);
            $this->form[$field] = match (count($uploads)) {
                0 => null,
                1 => $uploads[0],
                default => $uploads,
            };
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $domainOptions = $this->tagOptions(EventTaxonomyCode::Domain);

        $rules = [
            'form.title' => ['required', 'string', 'max:255'],
            'form.description' => [
                'nullable',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) && ! is_array($value)) {
                        $fail(__('Penerangan mestilah rentetan atau kandungan teks berformat.'));
                    }
                },
            ],
            'form.submission_country_id' => ['required', 'uuid'],
            'form.event_date' => ['required', 'date', 'after_or_equal:today'],
            'form.prayer_time' => ['required', Rule::enum(EventPrayerTime::class)],
            'form.custom_time' => ['nullable', 'date_format:H:i'],
            'form.end_time' => ['nullable', 'date_format:H:i'],
            'form.timezone' => ['required', 'timezone'],
            'form.program_starts_at' => ['required', 'date'],
            'form.program_ends_at' => ['required', 'date'],
            'form.primary_organizer_id' => ['required', 'string'],
            'form.primary_organizer_kind' => ['required', Rule::in(['institution', 'person'])],
            'form.location_institution_id' => ['nullable', 'string'],
            'form.location_same_as_institution' => ['required', 'boolean'],
            'form.location_type' => ['required', Rule::in(['institution', 'venue'])],
            'form.location_venue_id' => ['nullable', 'uuid'],
            'form.space_ids' => ['nullable', 'array'],
            'form.space_ids.*' => ['uuid'],
            'form.default_event_category_ids' => ['required', 'array', 'min:1'],
            'form.default_event_category_ids.*' => ['uuid', Rule::in(app(EventCategoryCatalog::class)->validTermIds((array) ($this->form['default_event_category_ids'] ?? [])))],
            'form.default_event_format' => ['required', Rule::in(array_column(EventFormat::cases(), 'value'))],
            'form.domain_tags' => $domainOptions !== []
                ? ['required', 'string', 'uuid', Rule::in(array_keys($domainOptions))]
                : ['nullable', 'string', 'uuid'],
            'form.discipline_tags' => ['nullable', 'array'],
            'form.discipline_tags.*' => ['uuid'],
            'form.source_tags' => ['nullable', 'array'],
            'form.source_tags.*' => ['uuid'],
            'form.issue_tags' => ['nullable', 'array'],
            'form.issue_tags.*' => ['uuid'],
            'form.references' => ['nullable', 'array'],
            'form.references.*' => ['uuid'],
            'form.event_url' => ['nullable', 'url', 'max:2048'],
            'form.live_url' => ['nullable', 'url', 'max:2048'],
            'form.gender' => ['required', Rule::enum(EventGenderRestriction::class)],
            'form.age_group' => ['required', 'array', 'min:1'],
            'form.age_group.*' => [Rule::enum(EventAgeGroup::class)],
            'form.children_allowed' => ['required', 'boolean'],
            'form.is_muslim_only' => ['required', 'boolean'],
            'form.languages' => ['required', 'array', 'min:1'],
            'form.languages.*' => ['uuid'],
            'form.persons' => ['nullable', 'array'],
            'form.persons.*' => ['uuid'],
            'form.other_key_people' => ['nullable', 'array'],
            'form.other_key_people.*.role_code' => ['required', 'string', Rule::in(array_keys(EventKeyPersonRole::nonSpeakerOptions()))],
            'form.other_key_people.*.involveable_id' => ['nullable', 'uuid'],
            'form.other_key_people.*.involveable_type' => ['nullable', Rule::in(['person'])],
            'form.other_key_people.*.display_name' => ['nullable', 'string', 'max:255'],
            'form.other_key_people.*.visibility' => ['required', Rule::in(['public', 'private'])],
            'form.other_key_people.*.notes' => ['nullable', 'string', 'max:500'],
            'form.cover' => ['nullable', 'image', 'max:10240'],
            'form.poster' => ['nullable', 'image', 'max:10240'],
            'form.gallery' => ['nullable', 'array', 'max:10'],
            'form.gallery.*' => ['image', 'max:10240'],
            'form.visibility' => ['required', Rule::in(array_column(EventVisibility::cases(), 'value'))],
            'form.registration_required' => ['required', 'boolean'],
            'form.registration_mode' => ['required', Rule::in(array_column(RegistrationScope::cases(), 'value'))],
            'form.pricing_mode' => ['required', Rule::enum(PricingMode::class)],
            'form.tickets' => ['required', 'array', 'min:1', 'max:20'],
            'form.tickets.*.name' => ['required', 'string', 'max:120'],
            'form.tickets.*.code' => ['nullable', 'string', 'max:40'],
            'form.tickets.*.description' => ['nullable', 'string', 'max:1000'],
            'form.tickets.*.price' => ['required', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'form.tickets.*.quota' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'form.tickets.*.max_quantity' => ['nullable', 'integer', 'min:1', 'max:100'],
            'form.tickets.*.seating_mode' => ['required', Rule::enum(SeatingMode::class)],
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
            $rules['form.registration_required'][] = 'accepted';
        }

        if (($this->form['prayer_time'] ?? null) === EventPrayerTime::LainWaktu->value) {
            $rules['form.custom_time'][] = 'required';
        }

        $rules['form.prayer_time'][] = function (string $attribute, mixed $value, Closure $fail): void {
            $prayerTime = $value instanceof EventPrayerTime
                ? $value
                : EventPrayerTime::tryFrom((string) $value);
            $date = $this->firstSessionDate();

            if (! $prayerTime instanceof EventPrayerTime || ! $date instanceof Carbon) {
                return;
            }

            if (
                in_array($prayerTime, [EventPrayerTime::SebelumJumaat, EventPrayerTime::SelepasJumaat], true)
                && ! $date->isFriday()
            ) {
                $fail(__('Pilihan waktu Jumaat hanya boleh dipilih untuk hari Jumaat.'));

                return;
            }

            if ($prayerTime === EventPrayerTime::SelepasTarawih && ! $this->isRamadhan($date)) {
                $fail(__('Pilihan waktu ini hanya boleh dipilih semasa bulan Ramadhan.'));
            }
        };

        $rules['form.end_time'][] = function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || $value === '') {
                return;
            }

            $startsAt = $this->firstSessionStartsAt();

            if (! $startsAt instanceof Carbon) {
                return;
            }

            $endAt = $startsAt->copy()->setTimeFromTimeString($value);

            if ($endAt->lessThanOrEqualTo($startsAt)) {
                $fail(__('Masa akhir mestilah selepas masa mula.'));
            }
        };

        $eventFormat = (string) ($this->form['default_event_format'] ?? EventFormat::Physical->value);

        $categoryIds = array_values(array_filter(
            (array) ($this->form['default_event_category_ids'] ?? []),
            is_string(...),
        ));
        $categoryIds = app(EventCategoryCatalog::class)->validateTermIds($categoryIds);
        $categoryPolicy = app(EventCategoryPolicyResolver::class);

        if ($categoryPolicy->requiresSpeaker($categoryIds)) {
            $rules['form.persons'] = ['required', 'array', 'min:1'];
        }

        if ($categoryPolicy->requiresPhysicalDelivery($categoryIds)) {
            $rules['form.default_event_format'][] = Rule::in([EventFormat::Physical->value]);
        }

        foreach ((array) ($this->form['other_key_people'] ?? []) as $index => $keyPerson) {
            if (is_array($keyPerson) && blank($keyPerson['involveable_id'] ?? null)) {
                $rules["form.other_key_people.{$index}.display_name"][] = 'required';
            }
        }

        if ($eventFormat !== EventFormat::Online->value) {
            if (($this->form['location_type'] ?? 'institution') === 'venue') {
                $rules['form.location_venue_id'][] = 'required';
            } else {
                $rules['form.location_institution_id'][] = 'required';
            }
        }

        return $rules;
    }

    protected function hasBuilderAccess(): bool
    {
        return $this->institutionOptions !== [] || $this->personOptions !== [];
    }

    protected function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    /** @return array<string, string> */
    protected function organizerOptions(): array
    {
        return [
            ...$this->institutionOptions,
            ...$this->personOptions,
        ];
    }

    /** @return array<string, string> */
    protected function visibilityOptions(): array
    {
        return collect(EventVisibility::cases())
            ->mapWithKeys(fn (EventVisibility $visibility): array => [$visibility->value => $visibility->getLabel()])
            ->all();
    }

    /** @return array<string, string> */
    protected function eventFormatOptions(): array
    {
        return collect(EventFormat::cases())
            ->mapWithKeys(fn (EventFormat $format): array => [$format->value => $format->getLabel()])
            ->all();
    }

    /** @return array<string, string> */
    protected function genderOptions(): array
    {
        return collect(EventGenderRestriction::cases())
            ->mapWithKeys(fn (EventGenderRestriction $gender): array => [$gender->value => $gender->getLabel()])
            ->all();
    }

    /** @return array<string, string> */
    protected function ageGroupOptions(): array
    {
        return collect(EventAgeGroup::cases())
            ->mapWithKeys(fn (EventAgeGroup $ageGroup): array => [$ageGroup->value => $ageGroup->getLabel()])
            ->all();
    }

    /** @return list<string> */
    protected function religiousDomainIds(): array
    {
        return EventTerm::query()
            ->where('code', DomainTermCode::AgamaKerohanian->value)
            ->pluck('id')
            ->map(strval(...))
            ->all();
    }

    protected function religiousTopicVisibilityJs(): string
    {
        return json_encode($this->religiousDomainIds(), JSON_THROW_ON_ERROR).".includes(String(\$get('domain_tags') || ''))";
    }

    protected function ageGroupsIncludeChildren(mixed $value): bool
    {
        $values = is_array($value) ? $value : [$value];

        return collect($values)
            ->map(fn (mixed $group): string => $group instanceof EventAgeGroup ? $group->value : (string) $group)
            ->contains(fn (string $group): bool => in_array($group, [
                EventAgeGroup::Children->value,
                EventAgeGroup::AllAges->value,
            ], true));
    }

    protected function categoriesRequirePersons(mixed $value): bool
    {
        $categoryIds = array_values(array_filter((array) $value, is_string(...)));

        return app(EventCategoryPolicyResolver::class)->requiresSpeaker(
            app(EventCategoryCatalog::class)->validateTermIds($categoryIds),
        );
    }

    /** @return array<string, string> */
    protected function pricingOptions(): array
    {
        return collect(PricingMode::cases())
            ->reject(fn (PricingMode $mode): bool => $mode === PricingMode::Mixed)
            ->mapWithKeys(fn (PricingMode $mode): array => [$mode->value => $mode->label()])
            ->all();
    }

    /** @return array<string, string> */
    protected function seatingOptions(): array
    {
        return collect(SeatingMode::cases())
            ->mapWithKeys(fn (SeatingMode $mode): array => [$mode->value => $mode->label()])
            ->all();
    }

    /** @return array<string, string> */
    protected function spaceOptionsForState(Get $get): array
    {
        $locationType = (string) ($get('location_type') ?? 'institution');
        $locationId = $locationType === 'venue'
            ? $get('location_venue_id')
            : $get('location_institution_id');

        if (! is_string($locationId) || $locationId === '') {
            return [];
        }

        $query = $locationType === 'venue'
            ? app(SpaceEligibilityResolver::class)->venueQuery($locationId)
            : app(SpaceEligibilityResolver::class)->institutionQuery($locationId);

        return $query
            ->where('status', 'active')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->mapWithKeys(fn (mixed $label, mixed $id): array => [(string) $id => (string) $label])
            ->all();
    }

    protected function summaryLocation(Get $get): string
    {
        $locationType = (string) ($get('location_type') ?? 'institution');
        $locationId = (string) ($locationType === 'venue'
            ? ($get('location_venue_id') ?? '')
            : ($get('location_institution_id') ?? ''));

        $options = $locationType === 'venue' ? $this->venueOptions() : $this->institutionOptions;

        return $options[$locationId] ?? __('Belum dipilih');
    }

    protected function ticketStateHasSeating(mixed $value): bool
    {
        foreach ((array) $value as $ticket) {
            if (
                is_array($ticket)
                && (string) ($ticket['seating_mode'] ?? SeatingMode::None->value) !== SeatingMode::None->value
            ) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> */
    protected function tagOptions(EventTaxonomyCode $type): array
    {
        $taxonomyId = EventTaxonomy::query()->where('code', $type->value)->value('id');

        if (! is_string($taxonomyId)) {
            return [];
        }

        return EventTerm::query()
            ->where('event_taxonomy_id', $taxonomyId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->mapWithKeys(fn (mixed $label, mixed $id): array => [(string) $id => (string) $label])
            ->all();
    }

    /** @return array<string, string> */
    protected function referenceOptions(): array
    {
        return Reference::query()
            ->active()
            ->orderBy('title')
            ->pluck('title', 'id')
            ->mapWithKeys(fn (mixed $label, mixed $id): array => [(string) $id => (string) $label])
            ->all();
    }

    /** @return array<string, string> */
    protected function countryOptions(): array
    {
        return AddressCountry::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<string, string> */
    protected function languageOptions(): array
    {
        return app(SelectionCatalogCache::class)->languageOptionsForCodes(
            MalaysiaLanguageCatalog::codes(),
            MalaysiaLanguageCatalog::labels(),
        );
    }

    /** @return array<string, string> */
    protected function venueOptions(): array
    {
        return Venue::query()
            ->whereIn('status', ['verified', 'pending'])
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /** @return array<string, string> */
    protected function spaceOptions(): array
    {
        $locationType = (string) ($this->form['location_type'] ?? 'institution');
        $locationId = $locationType === 'venue'
            ? ($this->form['location_venue_id'] ?? null)
            : ($this->form['location_institution_id'] ?? null);

        if (! is_string($locationId) || $locationId === '') {
            return [];
        }

        $query = $locationType === 'venue'
            ? app(SpaceEligibilityResolver::class)->venueQuery($locationId)
            : app(SpaceEligibilityResolver::class)->institutionQuery($locationId);

        return $query
            ->where('status', 'active')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /** @return array<string, string> */
    protected function prayerTimeOptions(): array
    {
        $date = $this->firstSessionDate();

        return collect(EventPrayerTime::cases())
            ->filter(function (EventPrayerTime $case) use ($date): bool {
                if (! $date instanceof Carbon) {
                    return ! in_array($case, [
                        EventPrayerTime::SebelumJumaat,
                        EventPrayerTime::SelepasJumaat,
                        EventPrayerTime::SelepasTarawih,
                    ], true);
                }

                if (in_array($case, [EventPrayerTime::SebelumJumaat, EventPrayerTime::SelepasJumaat], true)) {
                    return $date->isFriday();
                }

                if ($case === EventPrayerTime::SelepasTarawih) {
                    return $this->isRamadhan($date);
                }

                return true;
            })
            ->mapWithKeys(fn (EventPrayerTime $time): array => [$time->value => $time->getLabel()])
            ->all();
    }

    protected function firstSessionDate(): ?Carbon
    {
        $eventDate = $this->form['event_date'] ?? null;
        $timezone = (string) ($this->form['timezone'] ?? config('app.timezone', 'UTC'));

        if (! is_string($eventDate) || $eventDate === '') {
            return null;
        }

        try {
            return Carbon::parse($eventDate, $timezone)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    protected function firstSessionStartsAt(): ?Carbon
    {
        $date = $this->firstSessionDate();

        if (! $date instanceof Carbon) {
            return null;
        }

        $prayerTime = EventPrayerTime::tryFrom((string) ($this->form['prayer_time'] ?? ''));
        $time = $prayerTime === EventPrayerTime::LainWaktu
            ? ($this->form['custom_time'] ?? null)
            : $this->defaultPrayerTimes()[$prayerTime instanceof EventPrayerTime ? $prayerTime->value : ''] ?? null;

        if (! is_string($time) || $time === '') {
            return null;
        }

        try {
            return $date->copy()->setTimeFromTimeString($time);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, string> */
    protected function defaultPrayerTimes(): array
    {
        return [
            EventPrayerTime::SelepasSubuh->value => '06:30',
            EventPrayerTime::SelepasZuhur->value => '13:30',
            EventPrayerTime::SebelumJumaat->value => '13:45',
            EventPrayerTime::SelepasJumaat->value => '14:00',
            EventPrayerTime::SelepasAsar->value => '17:00',
            EventPrayerTime::SebelumMaghrib->value => '19:45',
            EventPrayerTime::SelepasMaghrib->value => '20:00',
            EventPrayerTime::SelepasIsyak->value => '21:30',
            EventPrayerTime::SelepasTarawih->value => '22:30',
        ];
    }

    protected function isRamadhan(Carbon $date): bool
    {
        $year = $date->year;
        $ramadhanPeriods = [
            2026 => ['start' => '02-18', 'end' => '03-19'],
            2027 => ['start' => '02-07', 'end' => '03-08'],
            2028 => ['start' => '01-27', 'end' => '02-25'],
            2029 => ['start' => '01-16', 'end' => '02-13'],
            2030 => ['start' => '01-05', 'end' => '02-03'],
        ];

        if (! isset($ramadhanPeriods[$year])) {
            return false;
        }

        $timezone = $date->getTimezone()->getName();
        $period = $ramadhanPeriods[$year];
        $startDate = Carbon::parse("{$year}-{$period['start']}", $timezone)->startOfDay();
        $endDate = Carbon::parse("{$year}-{$period['end']}", $timezone)->endOfDay();

        return $date->between($startDate, $endDate);
    }

    /**
     * @return array<int, array{key: string, title: string, description: string, eyebrow: string}>
     */
    protected function templateOptions(): array
    {
        return [
            ['key' => 'weekly_series', 'title' => __('Weekly Series'), 'description' => __('Use one event occurrence for a weekly chain of sessions.'), 'eyebrow' => __('Series')],
            ['key' => 'weekend_intensive', 'title' => __('Weekend Intensive'), 'description' => __('Create one event, then add each session to its occurrence.'), 'eyebrow' => __('Focused')],
            ['key' => 'ramadan_program', 'title' => __('Ramadan Program'), 'description' => __('Set up the event first, then add nightly sessions one by one.'), 'eyebrow' => __('Seasonal')],
        ];
    }

    public function render(): View
    {
        return view('livewire.pages.dashboard.events.create-advanced', [
            'prefillPersonLabel' => $this->personOptions[$this->prefillPersonId] ?? null,
            'templateOptions' => $this->templateOptions(),
        ]);
    }

    /** @return array<string, string> */
    protected function defaultTicket(): array
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
    protected function defaultSection(): array
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
}

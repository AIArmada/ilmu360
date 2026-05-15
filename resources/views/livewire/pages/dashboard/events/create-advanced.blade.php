@section('title', __('Create Advanced Event') . ' - ' . config('app.name'))

<div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(19,78,74,0.14),_transparent_38%),radial-gradient(circle_at_top_right,_rgba(217,166,76,0.15),_transparent_24%),linear-gradient(180deg,_#f7faf8_0%,_#eef4f1_55%,_#f8fbfa_100%)] pb-24 pt-10">
    <div class="mx-auto flex max-w-7xl flex-col gap-8 px-4 sm:px-6 lg:px-8">
        <section class="relative overflow-hidden rounded-[2rem] border border-white/70 bg-slate-950 px-6 py-8 text-white shadow-[0_30px_80px_-30px_rgba(15,23,42,0.55)] sm:px-8 lg:px-10">
            <div class="absolute inset-0 bg-[radial-gradient(circle_at_15%_20%,rgba(16,185,129,0.22),transparent_28%),radial-gradient(circle_at_85%_15%,rgba(217,166,76,0.25),transparent_22%),radial-gradient(circle_at_50%_100%,rgba(8,47,73,0.42),transparent_40%)]"></div>
            <div class="relative grid gap-8 lg:grid-cols-[1.4fr_0.9fr] lg:items-end">
                <div class="space-y-5">
                    <div class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-4 py-1.5 text-[11px] font-semibold uppercase tracking-[0.28em] text-white/80">
                        <span class="h-2 w-2 rounded-full bg-gold-400"></span>
                        {{ __('Parent Program Setup') }}
                    </div>

                    <div class="max-w-3xl space-y-3">
                        <h1 class="font-heading text-balance text-4xl font-semibold tracking-[-0.04em] text-white sm:text-5xl lg:text-6xl">
                            {{ __('Create the parent first. Submit each child event the normal way.') }}
                        </h1>
                        <p class="max-w-2xl text-sm leading-7 text-white/72 sm:text-base">
                            {{ __('This builder now does one stable job: create the umbrella program. After that, every child event is submitted through the standard Hantar Majlis flow and attached automatically to the parent.') }}
                        </p>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="rounded-2xl border border-white/10 bg-white/6 px-4 py-4 backdrop-blur-sm">
                            <p class="text-[11px] uppercase tracking-[0.22em] text-white/50">{{ __('Step 1') }}</p>
                            <p class="mt-2 text-sm font-medium text-white">{{ __('Create one parent program draft') }}</p>
                        </div>
                        <div class="rounded-2xl border border-white/10 bg-white/6 px-4 py-4 backdrop-blur-sm">
                            <p class="text-[11px] uppercase tracking-[0.22em] text-white/50">{{ __('Step 2') }}</p>
                            <p class="mt-2 text-sm font-medium text-white">{{ __('Open the normal submit-event form') }}</p>
                        </div>
                        <div class="rounded-2xl border border-white/10 bg-white/6 px-4 py-4 backdrop-blur-sm">
                            <p class="text-[11px] uppercase tracking-[0.22em] text-white/50">{{ __('Step 3') }}</p>
                            <p class="mt-2 text-sm font-medium text-white">{{ __('Each submitted child attaches back to the parent') }}</p>
                        </div>
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-1">
                    @foreach($stepOptions as $step)
                        <button type="button" wire:click="goToStep({{ $step['number'] }})" class="rounded-2xl border px-4 py-4 text-left transition {{ $activeStep === $step['number'] ? 'border-gold-300/70 bg-gold-300/14 shadow-[0_16px_40px_-20px_rgba(217,166,76,0.75)]' : 'border-white/10 bg-white/6 hover:border-white/20 hover:bg-white/10' }}">
                            <div class="flex items-start gap-4">
                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl text-sm font-semibold {{ $activeStep === $step['number'] ? 'bg-gold-300 text-slate-950' : 'bg-white/10 text-white/80' }}">
                                    {{ $step['number'] }}
                                </div>
                                <div>
                                    <p class="text-sm font-semibold text-white">{{ $step['title'] }}</p>
                                    <p class="mt-1 text-xs leading-5 text-white/55">{{ $step['description'] }}</p>
                                </div>
                            </div>
                        </button>
                    @endforeach
                </div>
            </div>
        </section>

        <form wire:submit="submit" class="grid gap-8 lg:grid-cols-[minmax(0,1.45fr)_minmax(320px,0.72fr)]">
            <div class="space-y-6">
                <section class="rounded-[2rem] border border-white/80 bg-white/90 p-4 shadow-[0_24px_60px_-35px_rgba(15,23,42,0.35)] backdrop-blur sm:p-6">
                    <div class="grid gap-2 sm:grid-cols-3">
                        @foreach($stepOptions as $step)
                            <button type="button" wire:click="goToStep({{ $step['number'] }})" class="rounded-2xl px-4 py-3 text-left transition {{ $activeStep === $step['number'] ? 'bg-slate-950 text-white shadow-lg' : 'bg-slate-100/80 text-slate-500 hover:bg-slate-200/80 hover:text-slate-800' }}">
                                <span class="block text-[11px] font-semibold uppercase tracking-[0.22em] {{ $activeStep === $step['number'] ? 'text-gold-300' : 'text-slate-400' }}">{{ __('Step :number', ['number' => $step['number']]) }}</span>
                                <span class="mt-1 block text-sm font-semibold">{{ $step['title'] }}</span>
                            </button>
                        @endforeach
                    </div>
                </section>

                @if($activeStep === 1)
                    <section class="overflow-hidden rounded-[2rem] border border-white/80 bg-white/90 shadow-[0_24px_60px_-35px_rgba(15,23,42,0.35)] backdrop-blur">
                        <div class="border-b border-slate-200/80 bg-[linear-gradient(135deg,rgba(15,23,42,0.98),rgba(15,118,110,0.96),rgba(217,166,76,0.25))] px-6 py-6 text-white sm:px-8">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.3em] text-gold-300/90">{{ __('Program Identity') }}</p>
                            <h2 class="mt-3 font-heading text-3xl font-semibold tracking-[-0.03em]">{{ __('Name the umbrella.') }}</h2>
                            <p class="mt-2 max-w-2xl text-sm leading-7 text-white/75">{{ __('This step only defines the parent program. Child events will be submitted one by one afterwards so each one can use the normal, stable event form.') }}</p>
                        </div>

                        <div class="space-y-8 px-6 py-6 sm:px-8 sm:py-8">
                            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-6">
                                <div class="xl:col-span-6">
                                    <label class="mb-2 block text-sm font-semibold text-slate-800">{{ __('Parent Program Title') }}</label>
                                    <input type="text" wire:model.defer="form.title" placeholder="{{ __('Example: Ramadan Knowledge Series 1447H') }}" class="h-14 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-900 shadow-inner shadow-slate-100 outline-none transition focus:border-emerald-400 focus:bg-white focus:ring-4 focus:ring-emerald-100" />
                                    @error('form.title')<p class="mt-2 text-sm text-rose-600">{{ $message }}</p>@enderror
                                </div>

                                <div class="xl:col-span-6">
                                    <label class="mb-2 block text-sm font-semibold text-slate-800">{{ __('Program Description') }}</label>
                                    <textarea wire:model.defer="form.description" rows="5" placeholder="{{ __('Describe the common narrative that will connect all child events.') }}" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-inner shadow-slate-100 outline-none transition focus:border-emerald-400 focus:bg-white focus:ring-4 focus:ring-emerald-100"></textarea>
                                    @error('form.description')<p class="mt-2 text-sm text-rose-600">{{ $message }}</p>@enderror
                                </div>

                                <div class="xl:col-span-2">
                                    <label class="mb-2 block text-sm font-semibold text-slate-800">{{ __('Timezone') }}</label>
                                    <input type="text" wire:model.defer="form.timezone" class="h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-900 outline-none transition focus:border-emerald-400 focus:bg-white focus:ring-4 focus:ring-emerald-100" />
                                    @error('form.timezone')<p class="mt-2 text-sm text-rose-600">{{ $message }}</p>@enderror
                                </div>

                                <div class="xl:col-span-2">
                                    <label class="mb-2 block text-sm font-semibold text-slate-800">{{ __('Visibility') }}</label>
                                    <select wire:model.defer="form.visibility" class="h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-900 outline-none transition focus:border-emerald-400 focus:bg-white focus:ring-4 focus:ring-emerald-100">
                                        @foreach($visibilityOptions as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="xl:col-span-2">
                                    <label class="mb-2 block text-sm font-semibold text-slate-800">{{ __('Organizer Type') }}</label>
                                    <select wire:model.live="form.organizer_type" class="h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-900 outline-none transition focus:border-emerald-400 focus:bg-white focus:ring-4 focus:ring-emerald-100">
                                        @if($institutionOptions !== [])
                                            <option value="institution">{{ __('Institution') }}</option>
                                        @endif
                                        @if($speakerOptions !== [])
                                            <option value="speaker">{{ __('Speaker') }}</option>
                                        @endif
                                    </select>
                                </div>

                                <div class="xl:col-span-3">
                                    <label class="mb-2 block text-sm font-semibold text-slate-800">{{ __('Organizer') }}</label>
                                    <select wire:model.defer="form.organizer_id" class="h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-900 outline-none transition focus:border-emerald-400 focus:bg-white focus:ring-4 focus:ring-emerald-100">
                                        @foreach(($form['organizer_type'] ?? 'institution') === 'institution' ? $institutionOptions : $speakerOptions as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @error('form.organizer_id')<p class="mt-2 text-sm text-rose-600">{{ $message }}</p>@enderror
                                </div>

                                @if(($form['organizer_type'] ?? 'institution') === 'speaker' && $institutionOptions !== [])
                                    <div class="xl:col-span-3">
                                        <label class="mb-2 block text-sm font-semibold text-slate-800">{{ __('Location Institution') }}</label>
                                        <select wire:model.defer="form.location_institution_id" class="h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-900 outline-none transition focus:border-emerald-400 focus:bg-white focus:ring-4 focus:ring-emerald-100">
                                            <option value="">{{ __('No institution location') }}</option>
                                            @foreach($institutionOptions as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                @endif
                            </div>

                            <div class="rounded-3xl border border-dashed border-emerald-300 bg-emerald-50/70 p-5">
                                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                    <div class="max-w-2xl">
                                        <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-emerald-700">{{ __('Program Templates') }}</p>
                                        <h3 class="mt-2 text-xl font-semibold text-slate-900">{{ __('Use a parent template, then submit child events individually.') }}</h3>
                                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('Templates now only scaffold the parent program title, description, and timeframe. The detailed child records come later through the normal submission UI.') }}</p>
                                    </div>
                                </div>

                                <div class="mt-5 grid gap-3 lg:grid-cols-3">
                                    @foreach($templateOptions as $template)
                                        <button type="button" wire:click="applyTemplate('{{ $template['key'] }}')" class="group rounded-3xl border border-emerald-200 bg-white p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:border-emerald-400 hover:shadow-lg">
                                            <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-emerald-700">{{ $template['eyebrow'] }}</p>
                                            <h4 class="mt-2 text-lg font-semibold text-slate-900">{{ $template['title'] }}</h4>
                                            <p class="mt-2 text-sm leading-6 text-slate-600">{{ $template['description'] }}</p>
                                            <span class="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-slate-900">
                                                {{ __('Use Template') }}
                                                <span class="transition group-hover:translate-x-1">→</span>
                                            </span>
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </section>
                @endif

                @if($activeStep === 2)
                    <section class="overflow-hidden rounded-[2rem] border border-white/80 bg-white/90 shadow-[0_24px_60px_-35px_rgba(15,23,42,0.35)] backdrop-blur">
                        <div class="border-b border-slate-200/80 bg-slate-950 px-6 py-6 text-white sm:px-8">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.3em] text-gold-300/90">{{ __('Program Defaults') }}</p>
                            <h2 class="mt-3 font-heading text-3xl font-semibold tracking-[-0.03em]">{{ __('Set the parent window and defaults.') }}</h2>
                            <p class="mt-2 max-w-2xl text-sm leading-7 text-white/72">{{ __('These defaults establish the parent program context. Child events will later inherit the relationship, while their own titles, schedules, and content will be submitted individually.') }}</p>
                        </div>

                        <div class="grid gap-6 px-6 py-6 sm:px-8 sm:py-8 xl:grid-cols-[1.2fr_0.8fr]">
                            <div class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label class="mb-2 block text-sm font-semibold text-slate-800">{{ __('Program Starts At') }}</label>
                                    <input type="datetime-local" wire:model.defer="form.program_starts_at" class="h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-900 outline-none transition focus:border-emerald-400 focus:bg-white focus:ring-4 focus:ring-emerald-100" />
                                    @error('form.program_starts_at')<p class="mt-2 text-sm text-rose-600">{{ $message }}</p>@enderror
                                </div>

                                <div>
                                    <label class="mb-2 block text-sm font-semibold text-slate-800">{{ __('Program Ends At') }}</label>
                                    <input type="datetime-local" wire:model.defer="form.program_ends_at" class="h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-900 outline-none transition focus:border-emerald-400 focus:bg-white focus:ring-4 focus:ring-emerald-100" />
                                    @error('form.program_ends_at')<p class="mt-2 text-sm text-rose-600">{{ $message }}</p>@enderror
                                </div>

                                <div>
                                    <label class="mb-2 block text-sm font-semibold text-slate-800">{{ __('Default Event Type') }}</label>
                                    <select wire:model.defer="form.default_event_type" class="h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-900 outline-none transition focus:border-emerald-400 focus:bg-white focus:ring-4 focus:ring-emerald-100">
                                        @foreach($eventTypeOptions as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div>
                                    <label class="mb-2 block text-sm font-semibold text-slate-800">{{ __('Default Format') }}</label>
                                    <select wire:model.defer="form.default_event_format" class="h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-900 outline-none transition focus:border-emerald-400 focus:bg-white focus:ring-4 focus:ring-emerald-100">
                                        @foreach($eventFormatOptions as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div>
                                    <label class="mb-2 block text-sm font-semibold text-slate-800">{{ __('Registration Mode') }}</label>
                                    <select wire:model.defer="form.registration_mode" class="h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-900 outline-none transition focus:border-emerald-400 focus:bg-white focus:ring-4 focus:ring-emerald-100">
                                        <option value="event">{{ __('Whole Event') }}</option>
                                    </select>
                                </div>

                                <label class="flex h-12 items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm font-semibold text-slate-800">
                                    <input id="registration_required" type="checkbox" wire:model.defer="form.registration_required" class="size-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500" />
                                    {{ __('Registration Required') }}
                                </label>
                            </div>

                            <div class="rounded-3xl border border-slate-200 bg-slate-50 p-5">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-slate-500">{{ __('Normalized Child Workflow') }}</p>
                                <div class="mt-4 space-y-3">
                                    <div class="rounded-2xl bg-white px-4 py-3 shadow-sm">
                                        <p class="text-xs uppercase tracking-[0.22em] text-slate-400">{{ __('After parent creation') }}</p>
                                        <p class="mt-2 text-sm font-semibold text-slate-900">{{ __('You will be redirected to Hantar Majlis to create the first child event.') }}</p>
                                    </div>
                                    <div class="rounded-2xl bg-white px-4 py-3 shadow-sm">
                                        <p class="text-xs uppercase tracking-[0.22em] text-slate-400">{{ __('Each child event') }}</p>
                                        <p class="mt-2 text-sm font-semibold text-slate-900">{{ __('Gets the normal event submission UI and auto-attaches to the parent.') }}</p>
                                    </div>
                                    <div class="rounded-2xl bg-slate-950 px-4 py-3 text-white shadow-sm">
                                        <p class="text-xs uppercase tracking-[0.22em] text-white/45">{{ __('Deliberate simplification') }}</p>
                                        <p class="mt-2 text-sm font-medium text-white/90">{{ __('No bulk child creation here. No recurring/occurrence layer here. Stabilize the parent-child path first.') }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>
                @endif

                @if($activeStep === 3)
                    <section class="overflow-hidden rounded-[2rem] border border-white/80 bg-white/90 shadow-[0_24px_60px_-35px_rgba(15,23,42,0.35)] backdrop-blur">
                        <div class="border-b border-slate-200/80 bg-white px-6 py-6 sm:px-8">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.3em] text-slate-500">{{ __('Review & Continue') }}</p>
                            <h2 class="mt-3 font-heading text-3xl font-semibold tracking-[-0.03em] text-slate-950">{{ __('Create the parent, then move into child submissions.') }}</h2>
                            <p class="mt-2 max-w-2xl text-sm leading-7 text-slate-500">{{ __('This page ends when the parent program exists. The detailed child-event work begins on the standard submit-event page immediately after.') }}</p>
                        </div>

                        <div class="space-y-6 px-6 py-6 sm:px-8 sm:py-8">
                            <div class="grid gap-4 xl:grid-cols-3">
                                <div class="rounded-3xl border border-slate-200 bg-slate-50 p-5">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-slate-500">{{ __('Parent Program') }}</p>
                                    <h3 class="mt-3 text-xl font-semibold text-slate-900">{{ trim((string) ($form['title'] ?? '')) ?: __('Untitled Parent Program') }}</h3>
                                    <p class="mt-2 text-sm leading-6 text-slate-500">{{ trim((string) ($form['description'] ?? '')) ?: __('No parent description yet.') }}</p>
                                </div>
                                <div class="rounded-3xl border border-slate-200 bg-slate-50 p-5">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-slate-500">{{ __('Defaults') }}</p>
                                    <p class="mt-3 text-sm font-semibold text-slate-900">{{ $eventTypeOptions[$form['default_event_type'] ?? ''] ?? __('Event Type') }}</p>
                                    <p class="mt-1 text-sm text-slate-500">{{ $eventFormatOptions[$form['default_event_format'] ?? ''] ?? __('Format') }}</p>
                                    <p class="mt-4 text-xs uppercase tracking-[0.18em] text-slate-400">{{ __('Registration') }}</p>
                                    <p class="mt-1 text-sm text-slate-600">{{ ! empty($form['registration_required']) ? __('Required') : __('Optional') }} · {{ __('Whole Event') }}</p>
                                </div>
                                <div class="rounded-3xl border border-slate-200 bg-slate-50 p-5">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-slate-500">{{ __('What happens next') }}</p>
                                    <p class="mt-3 text-sm font-semibold text-slate-900">{{ __('1 parent program draft') }}</p>
                                    <p class="mt-1 text-sm text-slate-600">{{ __('Then redirect to Hantar Majlis for the first child event.') }}</p>
                                    <p class="mt-4 text-xs uppercase tracking-[0.18em] text-slate-400">{{ __('Attachment model') }}</p>
                                    <p class="mt-1 text-sm text-slate-600">{{ __('Every child submission attaches to this parent automatically.') }}</p>
                                </div>
                            </div>

                            <div class="rounded-[1.75rem] border border-slate-200 bg-slate-50 p-5">
                                <div class="flex items-center justify-between gap-4">
                                    <div>
                                        <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-slate-500">{{ __('Parent Program Window') }}</p>
                                        <h3 class="mt-2 text-xl font-semibold text-slate-900">{{ __('Use the timeframe as the umbrella, not as the full schedule detail.') }}</h3>
                                    </div>
                                </div>
                                <div class="mt-5 grid gap-3 md:grid-cols-2">
                                    <div class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
                                        <p class="text-xs uppercase tracking-[0.18em] text-slate-400">{{ __('Starts') }}</p>
                                        <p class="mt-2 text-sm font-semibold text-slate-900">{{ filled($form['program_starts_at'] ?? null) ? \Illuminate\Support\Carbon::parse((string) $form['program_starts_at'], (string) ($form['timezone'] ?? 'Asia/Kuala_Lumpur'))->format('d M Y · h:i A') : __('Not set') }}</p>
                                    </div>
                                    <div class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
                                        <p class="text-xs uppercase tracking-[0.18em] text-slate-400">{{ __('Ends') }}</p>
                                        <p class="mt-2 text-sm font-semibold text-slate-900">{{ filled($form['program_ends_at'] ?? null) ? \Illuminate\Support\Carbon::parse((string) $form['program_ends_at'], (string) ($form['timezone'] ?? 'Asia/Kuala_Lumpur'))->format('d M Y · h:i A') : __('Not set') }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>
                @endif

                <section class="rounded-[2rem] border border-white/80 bg-white/90 p-4 shadow-[0_24px_60px_-35px_rgba(15,23,42,0.35)] backdrop-blur sm:p-5">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex gap-3">
                            <a href="{{ route('dashboard') }}" wire:navigate class="inline-flex items-center justify-center rounded-2xl border border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:bg-slate-100">{{ __('Cancel') }}</a>
                            @if($activeStep > 1)
                                <button type="button" wire:click="previousStep" class="inline-flex items-center justify-center rounded-2xl border border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:bg-slate-100">{{ __('Previous Step') }}</button>
                            @endif
                        </div>

                        <div class="flex gap-3">
                            @if($activeStep < 3)
                                <button type="button" wire:click="nextStep" class="inline-flex items-center justify-center rounded-2xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">{{ __('Continue') }}</button>
                            @else
                                <button type="submit" class="inline-flex items-center justify-center rounded-2xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-emerald-600/20 transition hover:bg-emerald-700">{{ __('Create Parent Program and Continue') }}</button>
                            @endif
                        </div>
                    </div>
                </section>
            </div>

            <aside class="lg:sticky lg:top-6 lg:self-start">
                <div class="space-y-6">
                    <section class="overflow-hidden rounded-[2rem] border border-slate-200 bg-slate-950 text-white shadow-[0_24px_60px_-35px_rgba(15,23,42,0.55)]">
                        <div class="border-b border-white/10 px-5 py-5">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-gold-300">{{ __('Stable Child Workflow') }}</p>
                            <h2 class="mt-2 text-2xl font-semibold tracking-[-0.03em]">{{ __('What this page does now') }}</h2>
                            <p class="mt-2 text-sm leading-6 text-white/65">{{ __('It creates the parent program only. The actual child events come through the normal event submission form, one by one, attached to the parent automatically.') }}</p>
                        </div>

                        <div class="grid gap-3 px-5 py-5 sm:grid-cols-2 lg:grid-cols-1">
                            <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-4">
                                <p class="text-[11px] uppercase tracking-[0.22em] text-white/45">{{ __('Parent Output') }}</p>
                                <p class="mt-2 text-sm font-semibold text-white">{{ __('One draft umbrella program') }}</p>
                            </div>
                            <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-4">
                                <p class="text-[11px] uppercase tracking-[0.22em] text-white/45">{{ __('Child Flow') }}</p>
                                <p class="mt-2 text-sm font-semibold text-white">{{ __('Standard submit-event UI') }}</p>
                            </div>
                            <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-4">
                                <p class="text-[11px] uppercase tracking-[0.22em] text-white/45">{{ __('Relationship') }}</p>
                                <p class="mt-2 text-sm font-semibold text-white">{{ __('Attached automatically through parent_event_id') }}</p>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-[2rem] border border-white/80 bg-white/90 p-5 shadow-[0_24px_60px_-35px_rgba(15,23,42,0.35)] backdrop-blur">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-slate-500">{{ __('Why this is safer') }}</p>
                        <div class="mt-4 space-y-3 text-sm leading-6 text-slate-600">
                            <p>{{ __('The child event form already exists, already handles real event details, and already has mature validation. Reusing it reduces hidden edge cases.') }}</p>
                            <p>{{ __('The advanced builder now focuses on parent-program identity and defaults instead of trying to be both a parent creator and a bulk event editor at the same time.') }}</p>
                        </div>
                    </section>
                </div>
            </aside>
        </form>
    </div>
</div>
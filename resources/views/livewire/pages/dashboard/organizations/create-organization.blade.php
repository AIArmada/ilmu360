@section('title', __('Create organization') . ' - ' . config('app.name'))

<main class="min-h-screen bg-[#fbf8f1] px-6 py-10 lg:px-12">
    <div class="mx-auto max-w-3xl">
        <a href="{{ route('dashboard.organizations.index') }}" wire:navigate class="text-sm font-semibold text-emerald-800 hover:text-emerald-950">
            ← {{ __('Back to organizations') }}
        </a>

        <div class="mt-6 rounded-[2rem] border border-[#eadfca] bg-white p-6 shadow-[0_30px_70px_-45px_rgba(11,42,66,0.55)] sm:p-10">
            <span class="inline-flex rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold uppercase tracking-[0.18em] text-emerald-800">
                {{ __('Organization workspace') }}
            </span>
            <h1 class="mt-5 font-heading text-4xl font-bold tracking-tight text-[#0b2a42]">{{ __('Create your organization') }}</h1>
            <p class="mt-3 max-w-2xl text-sm leading-7 text-slate-600">
                {{ __('Create a shared home for your team, members, events, tickets, and seating plans.') }}
            </p>

            <form wire:submit="submit" class="mt-8 space-y-6">
                <div>
                    <label for="organization-name" class="text-sm font-semibold text-slate-800">{{ __('Organization name') }}</label>
                    <input id="organization-name" type="text" wire:model="name" autofocus class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-900 outline-none transition focus:border-emerald-400 focus:bg-white focus:ring-4 focus:ring-emerald-100" placeholder="{{ __('Example: Ilmu Circle') }}">
                    @error('name') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="organization-description" class="text-sm font-semibold text-slate-800">{{ __('Description') }} <span class="font-normal text-slate-400">({{ __('optional') }})</span></label>
                    <textarea id="organization-description" wire:model="description" rows="5" class="mt-2 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-emerald-400 focus:bg-white focus:ring-4 focus:ring-emerald-100" placeholder="{{ __('What does your organization do?') }}"></textarea>
                    @error('description') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="flex flex-col-reverse gap-3 border-t border-slate-100 pt-6 sm:flex-row sm:justify-end">
                    <a href="{{ route('dashboard.organizations.index') }}" wire:navigate class="inline-flex items-center justify-center rounded-2xl border border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('Cancel') }}</a>
                    <button type="submit" class="inline-flex items-center justify-center rounded-2xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-emerald-600/20 hover:bg-emerald-700" wire:loading.attr="disabled">
                        {{ __('Create organization') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</main>

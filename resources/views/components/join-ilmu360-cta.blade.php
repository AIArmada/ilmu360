{{-- ════════════════════════════════════════════════════════════════
    GUEST BENEFITS CTA — Join ilmu360°
     Shared component for event, person, and institution views
     ════════════════════════════════════════════════════════════════ --}}
@guest
    <div class="scroll-reveal reveal-right revealed" x-intersect.once="$el.classList.add('revealed')" style="--reveal-d: 240ms">
        <div class="rounded-3xl border border-emerald-100 bg-linear-to-br from-emerald-50 to-teal-50/50 p-6 shadow-sm ring-1 ring-emerald-100/60">
            <div class="flex items-center gap-3">
                <div class="rounded-xl bg-emerald-100 p-2">
                    <svg class="size-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
                </div>
                <h3 class="font-bold text-slate-900">{{ __('Bina rutin ilmu yang konsisten') }}</h3>
            </div>
            <ul class="mt-4 space-y-2.5">
                <li class="flex items-start gap-2 text-sm text-slate-600">
                    <svg class="mt-0.5 size-4 shrink-0 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    {{ __('Tak terlepas majlis penting — simpan & tandakan kehadiran') }}
                </li>
                <li class="flex items-start gap-2 text-sm text-slate-600">
                    <svg class="mt-0.5 size-4 shrink-0 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    {{ __('Daftar lebih pantas untuk majlis yang memerlukan pendaftaran') }}
                </li>
                <li class="flex items-start gap-2 text-sm text-slate-600">
                    <svg class="mt-0.5 size-4 shrink-0 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    {{ __('Terima cadangan majlis yang lebih relevan dengan minat anda') }}
                </li>
                <li class="flex items-start gap-2 text-sm text-slate-600">
                    <svg class="mt-0.5 size-4 shrink-0 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    {{ __('Kongsi majlis anda dan capai lebih ramai peserta') }}
                </li>
            </ul>
            <div class="mt-5 space-y-2">
                <a href="{{ \App\Support\Auth\IntendedRedirect::registerUrl(request()->fullUrl()) }}" class="block w-full rounded-xl bg-emerald-600 py-2.5 text-center text-sm font-bold text-white transition hover:bg-emerald-700">
                    {{ __('Daftar Percuma') }}
                </a>
                <a href="{{ \App\Support\Auth\IntendedRedirect::loginUrl(request()->fullUrl()) }}" class="block w-full rounded-xl border border-slate-200 py-2.5 text-center text-sm font-semibold text-slate-600 transition hover:border-emerald-300 hover:text-emerald-700">
                    {{ __('Ada Akaun? Daftar Masuk') }}
                </a>
            </div>
        </div>
    </div>
@endguest

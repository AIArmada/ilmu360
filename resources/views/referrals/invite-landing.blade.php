@extends('layouts.app')

@section('title', 'Jemputan kawan - ' . config('app.name'))
@section('meta_robots', 'noindex, nofollow')

@section('content')
    <main class="mx-auto max-w-2xl px-4 py-16 sm:px-6">
        <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm sm:p-8">
            <p class="text-sm font-semibold uppercase tracking-wide text-primary-700">ilmu360°</p>
            <h1 class="mt-2 text-2xl font-bold text-gray-900">Jemputan kawan</h1>
            <p class="mt-4 text-gray-700">{{ $message }}</p>

            @if ($outcome === 'sign_in_required')
                <div class="mt-6 flex flex-wrap gap-3">
                    <a class="inline-flex rounded-lg bg-primary-700 px-4 py-2 font-semibold text-white hover:bg-primary-800" href="{{ route('login') }}">
                        Log masuk
                    </a>
                    @if (Route::has('register'))
                        <a class="inline-flex rounded-lg border border-gray-300 px-4 py-2 font-semibold text-gray-800 hover:bg-gray-50" href="{{ route('register') }}">
                            Daftar akaun
                        </a>
                    @endif
                </div>
            @elseif (auth()->check())
                <a class="mt-6 inline-flex rounded-lg border border-gray-300 px-4 py-2 font-semibold text-gray-800 hover:bg-gray-50" href="{{ route('dashboard.referrals') }}">
                    Lihat jemputan saya
                </a>
            @endif
        </section>
    </main>
@endsection

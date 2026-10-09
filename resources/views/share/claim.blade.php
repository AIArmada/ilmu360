@extends('layouts.app')

@section('title', __('Link past anonymous shares'))

@section('meta_robots', 'noindex, nofollow')

@section('meta_description', __('Link shares you made before signing in to your account.'))

@section('content')
    <main class="mx-auto w-full max-w-2xl px-5 py-12 sm:px-8">
        <p class="text-xs font-bold uppercase tracking-[0.2em] text-[#b27b1b]">{{ __('Shared history') }}</p>
        <h1 class="mt-3 font-heading text-3xl font-semibold tracking-tight text-[#173c34]">
            {{ __('Link past anonymous shares') }}
        </h1>

        @if (session('share_claim_status') === 'claimed')
            <div class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-5 text-sm leading-6 text-emerald-900" role="status">
                {{ __('Your past shares from this browser are now linked to your account.') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm leading-6 text-amber-900" role="alert">
                {{ $errors->first() }}
            </div>
        @endif

        @if ($state === 'available')
            <p class="mt-4 max-w-xl text-base leading-7 text-slate-600">
                {{ __('This browser shared pages before you signed in. Link that history to your account so your past shares count toward your private impact.') }}
            </p>
            <p class="mt-2 max-w-xl text-sm leading-6 text-slate-500">
                {{ __('Only continue if those past shares are yours. On a shared device, ask the person who shared them first.') }}
            </p>
            <form method="POST" action="{{ route('share.claim.store') }}" class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                @csrf
                <label class="flex cursor-pointer items-start gap-3 text-sm leading-6 text-slate-700">
                    <input type="checkbox" name="confirm" value="1" required
                        class="mt-1 size-4 rounded border-slate-300 text-[#173c34] focus:ring-[#173c34]">
                    <span>{{ __('Yes, the anonymous shares from this browser are mine. Link them to my account.') }}</span>
                </label>
                <div class="mt-5 flex flex-wrap items-center gap-3">
                    <button type="submit"
                        data-signal-event="share.claim_submitted" data-signal-category="share"
                        data-signal-component="share_claim_page" data-signal-control="claim_confirm"
                        class="rounded-xl bg-[#173c34] px-5 py-3 text-sm font-bold text-white transition hover:bg-[#21594c] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#173c34]">
                        {{ __('Link my past shares') }}
                    </button>
                    <a href="{{ route('dashboard') }}"
                        class="rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-bold text-slate-700 transition hover:bg-slate-50">
                        {{ __('Not now') }}
                    </a>
                </div>
            </form>
        @elseif ($state === 'claimed_by_you')
            <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <p class="text-sm leading-6 text-slate-600">
                    {{ __('Those past shares are already linked to your account. Nothing further is needed.') }}
                </p>
                <div class="mt-5">
                    <a href="{{ route('dashboard.dawah-impact') }}"
                        class="inline-block rounded-xl bg-[#173c34] px-5 py-3 text-sm font-bold text-white transition hover:bg-[#21594c]">
                        {{ __('View your impact') }}
                    </a>
                </div>
            </div>
        @else
            <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <p class="text-sm leading-6 text-slate-600">
                    {{ __('We could not verify past anonymous shares from this browser. Shares made on another device, in another browser, or after clearing cookies cannot be linked.') }}
                </p>
                <div class="mt-5">
                    <a href="{{ route('dashboard') }}"
                        class="inline-block rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-bold text-slate-700 transition hover:bg-slate-50">
                        {{ __('Back to dashboard') }}
                    </a>
                </div>
            </div>
        @endif
    </main>
@endsection

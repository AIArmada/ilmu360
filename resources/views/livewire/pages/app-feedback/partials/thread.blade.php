@if ($feedback->visibleMessages->isNotEmpty())
    <div class="mt-4 space-y-3 border-t border-slate-100 pt-4">
        <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">{{ __('app-feedback.history.follow_up') }}</p>
        @foreach ($feedback->visibleMessages as $message)
            <div class="rounded-xl bg-slate-50 p-3">
                <p class="text-[11px] font-semibold uppercase tracking-[0.14em] {{ $message->author_role === 'maintainer' ? 'text-emerald-700' : 'text-slate-500' }}">
                    {{ $message->author_role === 'maintainer' ? __('app-feedback.history.maintainer') : __('app-feedback.history.you') }}
                    · {{ \App\Platform\Time\UserDateTimeFormatter::format($message->created_at, 'd M Y, h:i A') }}
                </p>
                <p class="mt-1 whitespace-pre-line text-sm leading-6 text-slate-700">{{ $message->body }}</p>
            </div>
        @endforeach
    </div>
@endif

@if (in_array($feedback->status, [\App\Features\Feedback\Enums\AppFeedbackStatus::New, \App\Features\Feedback\Enums\AppFeedbackStatus::Triaged], true))
    <form wire:submit="postReply('{{ $feedback->id }}')" class="mt-4 space-y-2 border-t border-slate-100 pt-4">
        <label for="reply-{{ $feedback->id }}" class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">{{ __('app-feedback.history.add_info') }}</label>
        <textarea
            id="reply-{{ $feedback->id }}"
            wire:model="replyBodies.{{ $feedback->id }}"
            rows="2"
            maxlength="2000"
            placeholder="{{ __('app-feedback.history.add_info_placeholder') }}"
            class="block w-full rounded-xl border-slate-200 text-sm shadow-sm focus:border-emerald-400 focus:ring-emerald-200"></textarea>
        @error("replyBodies.{$feedback->id}")
            <p class="text-xs font-medium text-rose-700">{{ $message }}</p>
        @enderror
        <button
            type="submit"
            class="inline-flex items-center rounded-full border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:border-emerald-300 hover:text-emerald-800 disabled:cursor-wait disabled:opacity-60"
            wire:loading.attr="disabled"
            wire:target="postReply('{{ $feedback->id }}')">
            {{ __('app-feedback.history.send_reply') }}
        </button>
    </form>
@endif

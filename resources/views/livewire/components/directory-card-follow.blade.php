                                <button
                                    type="button"
                                    wire:click.stop.prevent="toggleFollow('{{ $subjectId }}')"
                                    wire:loading.attr="disabled"
                                    data-follow-icon="{{ $followIcon }}"
                                    data-follow-state="{{ $isFollowing ? 'following' : 'not-following' }}"
                                    aria-label="{{ $isFollowing ? __('Nyahikut') : __('Ikuti') }}"
                                    aria-pressed="{{ $isFollowing ? 'true' : 'false' }}"
                                    class="relative z-10 grid h-9 w-9 shrink-0 place-items-center rounded-xl border transition-colors duration-200 disabled:cursor-wait disabled:opacity-60 {{ $isFollowing ? 'border-emerald-200 bg-emerald-50 text-emerald-700 group-hover:border-emerald-300 group-hover:bg-emerald-100' : 'border-slate-200 bg-white text-slate-400 group-hover:border-emerald-200 group-hover:text-[#087f59] hover:border-emerald-200 hover:bg-emerald-100 hover:text-[#087f59] hover:shadow-sm' }}"
                                >
                                    <flux:icon.bookmark class="size-4" :variant="$isFollowing ? 'solid' : 'outline'" />
                                </button>

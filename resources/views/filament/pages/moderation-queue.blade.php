<x-filament-panels::page>
    {{-- Tabs --}}
    <div class="mb-6">
        <nav class="flex gap-2 flex-wrap" aria-label="Tabs">
            @foreach($this->getTabs() as $key => $tab)
                <button wire:key="moderation-tab-{{ $key }}" wire:click="setActiveTab('{{ $key }}')" type="button" @class([
                    'inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors',
                    'bg-primary-500 text-white shadow-md' => $this->activeTab === $key,
                    'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700' => $this->activeTab !== $key,
                ])>
                    <x-filament::icon :icon="$tab['icon']" class="h-5 w-5" />
                    {{ $tab['label'] }}
                    @if($tab['count'] > 0)
                        <span @class([
                            'inline-flex items-center justify-center min-w-[1.5rem] h-6 px-2 text-xs font-bold rounded-full',
                            'bg-white/20 text-white' => $this->activeTab === $key,
                            $this->getTabBadgeColorClasses($tab['badgeColor']) => $this->activeTab !== $key,
                        ])>
                            {{ $tab['count'] }}
                        </span>
                    @endif
                </button>
            @endforeach
        </nav>
    </div>

    {{-- Table --}}
    {{ $this->table }}
</x-filament-panels::page>

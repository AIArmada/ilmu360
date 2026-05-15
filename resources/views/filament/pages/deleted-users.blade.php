<x-filament-panels::page>
    <div class="mb-6 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Deleted users</h2>
        <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-400">
            Restore deleted accounts together with the relationship snapshot captured at deletion time.
            Transient records such as API tokens are intentionally not restored.
        </p>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
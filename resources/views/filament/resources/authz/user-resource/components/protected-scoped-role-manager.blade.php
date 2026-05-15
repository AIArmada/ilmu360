@php
    $protectedRoleManagers = method_exists($this, 'protectedScopedRoleManagers')
        ? $this->protectedScopedRoleManagers()
        : [];
@endphp

@if ($protectedRoleManagers === [])
    <p class="text-sm text-gray-500">
        No protected scoped ownership roles are currently assigned to this user.
    </p>
@else
    <div class="space-y-4">
        <p class="text-sm text-gray-500">
            These changes apply to every membership of the selected type because member roles are shared per resource type.
        </p>

        <div class="grid gap-4 xl:grid-cols-2">
            @foreach ($protectedRoleManagers as $manager)
                <div class="rounded-2xl border border-amber-200 bg-amber-50/60 p-4 dark:border-amber-400/20 dark:bg-amber-400/5">
                    <div class="space-y-3">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">
                                    {{ $manager['title'] }}
                                </h3>
                                <p class="text-xs text-gray-500">
                                    Current protected role: {{ $manager['current_role'] }}
                                </p>
                            </div>
                            <span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-1 text-xs font-medium text-amber-800 dark:bg-amber-400/10 dark:text-amber-200">
                                {{ $manager['membership_count'] }} memberships
                            </span>
                        </div>

                        @if ($manager['membership_labels'] !== [])
                            <div class="flex flex-wrap gap-2">
                                @foreach ($manager['membership_labels'] as $label)
                                    <span class="inline-flex rounded-full bg-white px-3 py-1 text-xs font-medium text-gray-700 shadow-sm ring-1 ring-gray-200 dark:bg-white/5 dark:text-gray-200 dark:ring-white/10">
                                        {{ $label }}
                                    </span>
                                @endforeach
                            </div>
                        @endif

                        <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                            <label class="block flex-1">
                                <span class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-200">
                                    Replacement role
                                </span>
                                <select
                                    wire:model.live="protectedRoleSelections.{{ $manager['subject_type'] }}"
                                    class="h-11 w-full rounded-xl border border-gray-300 bg-white px-3 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20 dark:border-white/10 dark:bg-white/5 dark:text-white"
                                >
                                    @foreach ($manager['options'] as $roleId => $roleLabel)
                                        <option value="{{ $roleId }}">{{ $roleLabel }}</option>
                                    @endforeach
                                </select>
                            </label>

                            <button
                                type="button"
                                wire:click="applyProtectedScopedRole('{{ $manager['subject_type'] }}')"
                                class="inline-flex h-11 items-center justify-center rounded-xl bg-primary-600 px-4 text-sm font-semibold text-white transition hover:bg-primary-700"
                            >
                                Apply
                            </button>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endif

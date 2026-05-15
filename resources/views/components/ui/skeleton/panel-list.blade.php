@props([
    'items' => 4,
    'columns' => 'md:grid-cols-2',
])

<div {{ $attributes->class(['grid gap-4', $columns]) }}>
    @foreach(range(1, (int) $items) as $index)
        <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm animate-pulse">
            <div class="flex items-start justify-between gap-4">
                <div class="w-full space-y-2">
                    <div class="h-5 w-2/3 rounded-full bg-slate-200"></div>
                    <div class="h-4 w-1/3 rounded-full bg-slate-100"></div>
                </div>
                <div class="h-6 w-20 rounded-full bg-slate-100"></div>
            </div>

            <div class="mt-4 space-y-2">
                <div class="h-4 w-full rounded-full bg-slate-100"></div>
                <div class="h-4 w-5/6 rounded-full bg-slate-100"></div>
            </div>

            <div class="mt-4 flex flex-wrap gap-2">
                <div class="h-6 w-24 rounded-full bg-slate-100"></div>
                <div class="h-6 w-20 rounded-full bg-slate-100"></div>
                <div class="h-6 w-28 rounded-full bg-slate-100"></div>
            </div>

            <div class="mt-5 flex gap-3">
                <div class="h-9 w-24 rounded-lg bg-slate-200"></div>
                <div class="h-9 w-20 rounded-lg bg-slate-100"></div>
                <div class="h-9 w-20 rounded-lg bg-slate-100"></div>
            </div>
        </div>
    @endforeach
</div>
@props([
    'items' => 8,
    'columns' => 'md:grid-cols-2 lg:grid-cols-4',
])

<div {{ $attributes->class(['grid gap-8', $columns]) }}>
    @foreach(range(1, (int) $items) as $index)
        <div class="flex flex-col items-center overflow-hidden rounded-3xl border border-slate-100 bg-white p-8 text-center shadow-sm animate-pulse">
            <div class="mb-6 h-32 w-32 rounded-full bg-slate-200"></div>

            <div class="w-full space-y-2">
                <div class="mx-auto h-5 w-3/4 rounded-full bg-slate-200"></div>
                <div class="mx-auto h-5 w-1/2 rounded-full bg-slate-200"></div>
            </div>

            <div class="mt-6 h-4 w-24 rounded-full bg-slate-100"></div>
        </div>
    @endforeach
</div>
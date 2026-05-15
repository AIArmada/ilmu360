@props([
    'items' => 6,
    'columns' => 'md:grid-cols-2 lg:grid-cols-3',
])

<div {{ $attributes->class(['grid gap-8', $columns]) }}>
    @foreach(range(1, (int) $items) as $index)
        <div class="overflow-hidden rounded-3xl border border-slate-100 bg-white shadow-sm animate-pulse">
            <div class="aspect-video bg-slate-200"></div>

            <div class="space-y-4 p-6">
                <div class="space-y-2">
                    <div class="h-6 w-4/5 rounded-full bg-slate-200"></div>
                    <div class="h-6 w-3/5 rounded-full bg-slate-200"></div>
                </div>

                <div class="flex items-start gap-2">
                    <div class="mt-1 h-4 w-4 rounded-full bg-emerald-100"></div>
                    <div class="w-full space-y-2">
                        <div class="h-4 w-3/4 rounded-full bg-slate-200"></div>
                        <div class="h-4 w-1/2 rounded-full bg-slate-100"></div>
                    </div>
                </div>

                <div class="flex items-center justify-between border-t border-slate-100 pt-5">
                    <div class="h-7 w-24 rounded-lg bg-slate-100"></div>
                    <div class="h-4 w-24 rounded-full bg-emerald-100"></div>
                </div>
            </div>
        </div>
    @endforeach
</div>
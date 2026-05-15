@props([
    'items' => 6,
    'columns' => 'md:grid-cols-2 lg:grid-cols-3',
])

<div {{ $attributes->class(['grid gap-8', $columns]) }}>
    @foreach(range(1, (int) $items) as $index)
        <article class="overflow-hidden rounded-3xl border border-slate-100 bg-white shadow-sm animate-pulse">
            <div class="relative aspect-[16/9] bg-slate-200">
                <div class="absolute left-4 top-4 h-14 w-14 rounded-xl bg-white/80"></div>
                <div class="absolute bottom-4 left-4 h-6 w-20 rounded-full bg-white/70"></div>
            </div>

            <div class="space-y-4 p-6">
                <div class="space-y-2">
                    <div class="h-6 w-5/6 rounded-full bg-slate-200"></div>
                    <div class="h-6 w-2/3 rounded-full bg-slate-200"></div>
                </div>

                <div class="space-y-3">
                    <div class="flex items-start gap-2.5">
                        <div class="mt-1 h-4 w-4 rounded-full bg-emerald-100"></div>
                        <div class="w-full space-y-2">
                            <div class="h-4 w-3/4 rounded-full bg-slate-200"></div>
                            <div class="h-3 w-1/2 rounded-full bg-slate-100"></div>
                        </div>
                    </div>

                    <div class="flex items-center gap-2.5">
                        <div class="h-4 w-4 rounded-full bg-emerald-100"></div>
                        <div class="h-4 w-2/3 rounded-full bg-slate-200"></div>
                    </div>
                </div>
            </div>
        </article>
    @endforeach
</div>

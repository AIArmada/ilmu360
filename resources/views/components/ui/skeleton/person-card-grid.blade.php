@props([
    'items' => 12,
    'columns' => 'sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4',
])

<div {{ $attributes->class(['grid gap-5', $columns]) }} role="presentation" aria-hidden="true">
    @foreach(range(1, (int) $items) as $index)
        <div data-material="opaque-card" class="living-majlis-card motion-safe:animate-pulse flex min-h-[10rem] overflow-hidden rounded-[1.5rem] sm:block sm:min-h-0">
            <div class="w-28 shrink-0 bg-gradient-to-br from-emerald-50 via-slate-50 to-gold-50 sm:aspect-[3/4] sm:w-full">
                <div class="flex h-full items-start p-3">
                    <div class="h-5 w-16 rounded-full bg-emerald-100/80"></div>
                </div>
            </div>

            <div class="flex min-w-0 flex-1 flex-col p-4 sm:p-5">
                <div class="h-5 w-4/5 rounded-lg bg-slate-200"></div>
                <div class="mt-3 h-3 w-2/5 rounded-full bg-slate-100"></div>
                <div class="mt-5 flex items-center gap-2.5">
                    <div class="h-8 w-8 rounded-xl bg-gold-100"></div>
                    <div class="h-3 w-3/5 rounded-full bg-slate-100"></div>
                </div>
                <div class="mt-2 h-3 w-2/5 rounded-full bg-emerald-100"></div>
                <div class="mt-auto pt-4">
                    <div class="h-4 w-1/2 rounded-full bg-emerald-100"></div>
                </div>
            </div>
        </div>
    @endforeach
</div>

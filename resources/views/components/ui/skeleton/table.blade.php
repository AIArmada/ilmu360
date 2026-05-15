@props([
    'rows' => 6,
    'columns' => 7,
])

<div {{ $attributes->class(['overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm']) }}>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-100">
            <thead>
                <tr>
                    @foreach(range(1, (int) $columns) as $index)
                        <th class="px-4 py-3">
                            <div class="h-3 w-20 rounded-full bg-slate-100 animate-pulse"></div>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach(range(1, (int) $rows) as $row)
                    <tr class="animate-pulse">
                        @foreach(range(1, (int) $columns) as $column)
                            <td class="px-4 py-4">
                                <div class="h-4 rounded-full bg-slate-100 {{ $column === 1 ? 'w-32' : ($column === 6 ? 'w-20' : 'w-24') }}"></div>
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
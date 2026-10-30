<div class="mx-auto max-w-4xl px-4 py-8">
    <h1 class="text-2xl font-semibold">My rewards</h1>

    @if ($this->notice !== '')
        <p class="mt-3 rounded bg-amber-50 px-3 py-2 text-sm text-amber-900">{{ $this->notice }}</p>
    @endif

    <div class="mt-4 flex flex-wrap gap-2">
        <button wire:click="showTab('overview')" class="rounded px-3 py-1 text-sm {{ $this->tab === 'overview' ? 'bg-slate-900 text-white' : 'bg-slate-100' }}">Overview</button>
        <button wire:click="showTab('programs')" class="rounded px-3 py-1 text-sm {{ $this->tab === 'programs' ? 'bg-slate-900 text-white' : 'bg-slate-100' }}">Programs</button>
        <button wire:click="showTab('appeals')" class="rounded px-3 py-1 text-sm {{ $this->tab === 'appeals' ? 'bg-slate-900 text-white' : 'bg-slate-100' }}">Appeals</button>
        <button wire:click="showTab('statements')" class="rounded px-3 py-1 text-sm {{ $this->tab === 'statements' ? 'bg-slate-900 text-white' : 'bg-slate-100' }}">Cash statements</button>
        <button wire:click="showTab('payee')" class="rounded px-3 py-1 text-sm {{ $this->tab === 'payee' ? 'bg-slate-900 text-white' : 'bg-slate-100' }}">Payout details</button>
        <button wire:click="showTab('impact')" class="rounded px-3 py-1 text-sm {{ $this->tab === 'impact' ? 'bg-slate-900 text-white' : 'bg-slate-100' }}">My impact</button>
        <button wire:click="showTab('catalog')" class="rounded px-3 py-1 text-sm {{ $this->tab === 'catalog' ? 'bg-slate-900 text-white' : 'bg-slate-100' }}">Benefit catalog</button>
        <button wire:click="showTab('grants')" class="rounded px-3 py-1 text-sm {{ $this->tab === 'grants' ? 'bg-slate-900 text-white' : 'bg-slate-100' }}">My redemptions</button>
    </div>

    @if ($this->tab === 'overview')
        <h2 class="mt-6 text-lg font-medium">Baki mata / Point balances</h2>
        @forelse ($balances as $balance)
            <div class="mt-2 rounded border px-3 py-2 text-sm">
                <strong>{{ $balance['program'] }}</strong> ({{ $balance['unit'] }}):
                tersedia / available {{ $balance['available'] }}, disimpan / reserved {{ $balance['reserved'] }}, digunakan / spent {{ $balance['spent'] }}
            </div>
        @empty
            <p class="mt-2 text-sm text-slate-500">Belum ada baki mata. / No point balances yet.</p>
        @endforelse

        <h2 class="mt-6 text-lg font-medium">Kemajuan bintang dan pencapaian / Star and milestone progress</h2>
        <p class="mt-1 text-sm text-slate-600">Kemajuan ini hanya untuk akaun anda. / This progress is private to your account.</p>
        @forelse ($milestones as $milestone)
            <div class="mt-2 rounded border px-3 py-2 text-sm">
                <strong>{{ $milestone['label'] }}</strong>
                <span class="text-slate-600">— {{ $milestone['program'] }}</span>
                <span class="ml-2">{{ $milestone['current'] }} / {{ $milestone['target'] }}</span>
                @if ($milestone['status'] === 'achieved')
                    <span class="ml-2 font-medium text-emerald-700">Dicapai / Achieved</span>
                @else
                    <span class="ml-2 text-slate-600">Dalam kemajuan / In progress</span>
                @endif
            </div>
        @empty
            <p class="mt-2 text-sm text-slate-500">Kemajuan bintang akan muncul selepas aktiviti yang layak direkodkan. / Star progress appears after an eligible activity is recorded.</p>
        @endforelse

        <h2 class="mt-6 text-lg font-medium">Award history</h2>
        @forelse ($histories as $history)
            <div class="mt-2 rounded border px-3 py-2 text-sm">
                <strong>{{ $history['component'] }}</strong> — {{ $history['status'] }}
                @if (($history['program']['title'] ?? null) !== null)
                    <span class="text-slate-600">({{ $history['program']['title'] }})</span>
                @endif
            </div>
        @empty
            <p class="mt-2 text-sm text-slate-500">No awards yet.</p>
        @endforelse
        <div class="mt-3">{{ $awards->links() }}</div>
    @endif

    @if ($this->tab === 'programs')
        <h2 class="mt-6 text-lg font-medium">Programs</h2>
        @forelse ($programs as $row)
            <div class="mt-2 rounded border px-3 py-2 text-sm">
                <strong>{{ $row['detail']['title'] }}</strong>
                <span class="text-slate-600">({{ $row['detail']['mode'] }}, {{ $row['detail']['status'] }})</span>
                @if (($row['detail']['enrollment']['status'] ?? null) !== null)
                    <span class="text-slate-600">— enrolled: {{ $row['detail']['enrollment']['status'] }}</span>
                @endif
                @if (($row['detail']['enrollment']['acceptance']['current'] ?? true) === false)
                    <span class="font-medium text-amber-800">— terms changed, please reaccept</span>
                @endif
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    @if ($row['can_enroll'] && ($row['detail']['enrollment'] ?? null) === null)
                        <button wire:click="enroll('{{ $row['detail']['id'] }}')" class="rounded bg-slate-900 px-2 py-1 text-xs text-white">Enroll</button>
                    @endif
                    @if (($row['detail']['enrollment']['acceptance']['current'] ?? true) === false)
                        <button wire:click="reaccept('{{ $row['detail']['id'] }}')" class="rounded bg-amber-700 px-2 py-1 text-xs text-white">Reaccept terms</button>
                    @endif
                    @if ($row['can_leave'] && ($row['detail']['enrollment']['status'] ?? null) === 'active')
                        <input wire:model="leaveReason" type="text" maxlength="191" placeholder="Reason for leaving (optional)" class="rounded border px-2 py-1 text-xs" />
                        <button wire:click="leave('{{ $row['detail']['id'] }}')" class="rounded bg-slate-200 px-2 py-1 text-xs">Leave</button>
                    @endif
                </div>
            </div>
        @empty
            <p class="mt-2 text-sm text-slate-500">No programs are open right now.</p>
        @endforelse
    @endif

    @if ($this->tab === 'appeals')
        <h2 class="mt-6 text-lg font-medium">Appeals</h2>
        @forelse ($appeals as $appeal)
            <div class="mt-2 rounded border px-3 py-2 text-sm">
                <strong>{{ $appeal['status'] }}</strong> — {{ $appeal['reason'] }}
                <span class="text-slate-600">opened {{ $appeal['opened_at'] }}</span>
            </div>
        @empty
            <p class="mt-2 text-sm text-slate-500">No appeals yet.</p>
        @endforelse
        @if ($appealsPager !== null)
            <div class="mt-3">{{ $appealsPager->links() }}</div>
        @endif

        <h3 class="mt-6 font-medium">Open a new appeal</h3>
        <div class="mt-2 grid gap-2 text-sm">
            <select wire:model="appealAwardId" class="rounded border px-2 py-1">
                <option value="">Choose an award…</option>
                @foreach ($appealableAwards as $option)
                    <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                @endforeach
            </select>
            <textarea wire:model="appealReason" rows="3" maxlength="2000" placeholder="What should be reviewed?" class="rounded border px-2 py-1"></textarea>
            <button wire:click="fileAppeal" class="w-fit rounded bg-slate-900 px-3 py-1 text-white">Submit appeal</button>
        </div>
    @endif

    @if ($this->tab === 'statements')
        <h2 class="mt-6 text-lg font-medium">Cash statements</h2>
        @forelse ($statements as $statement)
            <div class="mt-2 rounded border px-3 py-2 text-sm">
                <strong>{{ $statement['funds']['label'] }}</strong>
                <span class="text-slate-600">{{ $statement['currency'] }} {{ $statement['amount_minor'] }} via {{ $statement['processor'] }}</span>
                @if ($statement['account_display'] !== null)
                    <span class="text-slate-600">to {{ $statement['account_display'] }}</span>
                @endif
                <span class="text-slate-600">requested {{ $statement['requested_at'] }}</span>
            </div>
        @empty
            <p class="mt-2 text-sm text-slate-500">No payouts yet.</p>
        @endforelse
        @if ($statementsPager !== null)
            <div class="mt-3">{{ $statementsPager->links() }}</div>
        @endif
    @endif

    @if ($this->tab === 'payee')
        <h2 class="mt-6 text-lg font-medium">Payout destinations</h2>
        @forelse ($payees as $payee)
            <div class="mt-2 rounded border px-3 py-2 text-sm">
                <strong>{{ $payee['method'] }}</strong> — {{ $payee['account_display'] }}
                <span class="text-slate-600">({{ $payee['status'] }})</span>
            </div>
        @empty
            <p class="mt-2 text-sm text-slate-500">No destinations yet.</p>
        @endforelse

        <h3 class="mt-6 font-medium">Add a destination</h3>
        <div class="mt-2 grid gap-2 text-sm">
            <select wire:model="payeeMethod" class="rounded border px-2 py-1">
                @foreach ($payeeMethodOptions as $method => $label)
                    <option value="{{ $method }}">{{ $label }}</option>
                @endforeach
            </select>
            <input wire:model="payeeRef" type="text" maxlength="191" placeholder="Account reference (email, account id, …)" class="rounded border px-2 py-1" />
            <input wire:model="payeeDisplay" type="text" maxlength="191" placeholder="Display label, e.g. m***@example.com" class="rounded border px-2 py-1" />
            <button wire:click="registerPayee" class="w-fit rounded bg-slate-900 px-3 py-1 text-white">Submit for verification</button>
        </div>
    @endif

    @if ($this->tab === 'impact')
        <h2 class="mt-6 text-lg font-medium">My impact (last 30 days)</h2>
        <p class="mt-2 text-sm text-slate-600">Status: {{ $impact['status'] ?? 'unavailable' }}</p>
        @forelse (($impact['totals'] ?? []) as $key => $value)
            <div class="mt-2 rounded border px-3 py-2 text-sm">
                <strong>{{ str_replace('_', ' ', (string) $key) }}</strong>: {{ is_scalar($value) ? $value : json_encode($value) }}
            </div>
        @empty
            <p class="mt-2 text-sm text-slate-500">No impact data yet.</p>
        @endforelse
    @endif

    @if ($this->tab === 'catalog')
        <h2 class="mt-6 text-lg font-medium">Benefit catalog</h2>
        @forelse ($catalog as $item)
            <div class="mt-2 rounded border px-3 py-2 text-sm">
                <strong>{{ $item->title }}</strong>
                @if ($item->direct_entitlement)
                    <span class="text-slate-600">(granted by award)</span>
                @else
                    <span class="text-slate-600">({{ $item->cost_points }} {{ $item->cost_unit }}, {{ $stockOf($item)['available'] }} left)</span>
                    <button wire:click="redeem('{{ $item->getKey() }}')" class="ml-2 rounded bg-slate-900 px-2 py-1 text-xs text-white">Redeem</button>
                @endif
                <ul class="mt-1 list-disc pl-5 text-slate-600">
                    @foreach (($item->conditions ?? []) as $condition)
                        <li>{{ $condition }}</li>
                    @endforeach
                </ul>
            </div>
        @empty
            <p class="mt-2 text-sm text-slate-500">No benefits available right now.</p>
        @endforelse
        <div class="mt-3">{{ $catalog->links() }}</div>
    @endif

    @if ($this->tab === 'grants')
        <h2 class="mt-6 text-lg font-medium">My redemptions</h2>
        @forelse ($grants as $grant)
            <div class="mt-2 rounded border px-3 py-2 text-sm">
                <strong>{{ $grant->grant_key }}</strong> — {{ $grant->status->value }} ({{ $grant->fulfillment_adapter }})
                @if ($grant->external_ref !== null)
                    <span class="text-slate-600">ref {{ $grant->external_ref }}</span>
                @endif
            </div>
        @empty
            <p class="mt-2 text-sm text-slate-500">No redemptions yet.</p>
        @endforelse
        <div class="mt-3">{{ $grants->links() }}</div>
    @endif
</div>

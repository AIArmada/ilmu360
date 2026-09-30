<!doctype html>
<html lang="ms" class="scroll-smooth">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Pratonton Reka Bentuk v2 — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
    @include('partials.font-links')

    <style>
        /* DESIGN.md §4 tokens, scoped to this preview page only — not part of the app theme yet. */
        :root {
            --canvas: #f9f4f2;
            --surface: #ffffff;
            --divider: #e5ded8;
            --border-strong: #8c867e;
            --ink-strong: #17191c;
            --ink: #2d2c2b;
            --ink-muted: #6b645e;
            --emerald-50: #ecfdf5;
            --emerald-100: #d1fae5;
            --emerald-700: #047857;
            --emerald-800: #065f46;
            --emerald-900: #064e3b;
            --shadow-1: 0 1px 2px rgba(45, 44, 43, .05), 0 6px 20px rgba(45, 44, 43, .06);
        }

        body {
            background: var(--canvas);
            color: var(--ink);
        }
    </style>
</head>

<body class="font-sans antialiased">
    <main class="mx-auto max-w-5xl space-y-16 px-5 py-12 sm:px-6 lg:py-16">
        <header class="space-y-4">
            <p class="font-sans text-sm font-medium text-[var(--ink-muted)]">Ujian reka bentuk · DESIGN.md v2</p>
            <h1 class="font-heading text-[36px] leading-[42px] tracking-[-0.01em] text-balance text-[var(--ink-strong)] sm:text-[56px] sm:leading-[62px]">Pratonton Tipografi &amp; Ikon</h1>
            <p class="max-w-2xl font-sans text-[17px] leading-[28px] text-[var(--ink)]">Halaman ini menguji token tipografi (§5) dan sistem ikon (§7) sahaja. Warna di sini adalah pralihat sementara dan belum mengubah tema aplikasi.</p>
        </header>

        <section aria-labelledby="tipografi" class="space-y-6">
            <h2 id="tipografi" class="font-heading text-[28px] leading-[36px] text-[var(--ink-strong)] sm:text-[36px] sm:leading-[44px]">Tipografi</h2>

            <div class="rounded-3xl border border-[var(--divider)] bg-[var(--surface)] p-6 shadow-[var(--shadow-1)]">
                <p class="mb-4 font-sans text-sm font-medium text-[var(--ink-muted)]">Display / H1 · Fraunces 400 · 56/62, mudah alih 36/42 · −0.01em</p>
                <p class="font-heading text-[36px] leading-[42px] tracking-[-0.01em] text-balance text-[var(--ink-strong)] sm:text-[56px] sm:leading-[62px]">Majlis Ilmu Berhampiran Anda</p>
            </div>

            <div class="rounded-3xl border border-[var(--divider)] bg-[var(--surface)] p-6 shadow-[var(--shadow-1)]">
                <p class="mb-4 font-sans text-sm font-medium text-[var(--ink-muted)]">H2 · Fraunces 400 · 36/44, mudah alih 28/36</p>
                <p class="font-heading text-[28px] leading-[36px] text-[var(--ink-strong)] sm:text-[36px] sm:leading-[44px]">Ketenangan Melalui Ilmu</p>
            </div>

            <div class="rounded-3xl border border-[var(--divider)] bg-[var(--surface)] p-6 shadow-[var(--shadow-1)]">
                <p class="mb-4 font-sans text-sm font-medium text-[var(--ink-muted)]">Tajuk kad · Figtree 600 · 20/28, mudah alih 19/26 · klip 2 baris</p>
                <p class="line-clamp-2 font-sans text-[19px] font-semibold leading-[26px] text-[var(--ink-strong)] sm:text-[20px] sm:leading-[28px]">Kuliah Maghrib: Tafsir Surah Al-Kahfi bersama Ustaz Ahmad Zulkifli</p>
            </div>

            <div class="rounded-3xl border border-[var(--divider)] bg-[var(--surface)] p-6 shadow-[var(--shadow-1)]">
                <p class="mb-4 font-sans text-sm font-medium text-[var(--ink-muted)]">Isi kandungan · Figtree 400 · 17/28</p>
                <p class="max-w-[68ch] font-sans text-[17px] leading-[28px] text-[var(--ink)]">ilmu360 menghimpunkan kuliah, ceramah dan kelas di seluruh Malaysia. Setiap majlis memaut kepada penceramah, institusi dan kitabnya supaya anda tahu apa, siapa, bila dan di mana — sebelum keluar rumah.</p>
            </div>

            <div class="rounded-3xl border border-[var(--divider)] bg-[var(--surface)] p-6 shadow-[var(--shadow-1)]">
                <p class="mb-4 font-sans text-sm font-medium text-[var(--ink-muted)]">Meta dan label · Figtree 500 · 14/20 · angka tabular</p>
                <p class="font-sans text-sm font-medium leading-5 text-[var(--ink-muted)] tabular-nums">Selepas Maghrib · 8.30 malam</p>
                <p class="mt-1 font-sans text-sm font-medium leading-5 text-[var(--ink-muted)] tabular-nums">Sabtu, 3 Okt 2026 · Masjid Wilayah · 2.4 km</p>
            </div>

            <div class="rounded-3xl border border-[var(--divider)] bg-[var(--surface)] p-6 shadow-[var(--shadow-1)]">
                <p class="mb-4 font-sans text-sm font-medium text-[var(--ink-muted)]">Serif italic · hanya untuk subbaris dan petikan Melayu</p>
                <p class="font-serif text-xl italic leading-8 text-[var(--ink)]">“Ilmu dikongsi, kebaikan dirasai.”</p>
            </div>

            <div class="rounded-3xl border border-[var(--divider)] bg-[var(--surface)] p-6 shadow-[var(--shadow-1)]">
                <p class="mb-4 font-sans text-sm font-medium text-[var(--ink-muted)]">Arab · Noto Naskh Arabic · 24/44, mudah alih 22/40 · line-height 1.8 · tanpa italic atau jarak huruf</p>
                <p lang="ar" dir="rtl" class="font-arabic text-[22px] leading-[40px] text-[var(--ink-strong)] sm:text-[24px] sm:leading-[44px]">اللغة العربية</p>
            </div>
        </section>

        <section aria-labelledby="ikon" class="space-y-6">
            <div class="space-y-2">
                <h2 id="ikon" class="font-heading text-[28px] leading-[36px] text-[var(--ink-strong)] sm:text-[36px] sm:leading-[44px]">Ikon</h2>
                <p class="font-sans text-[17px] leading-[28px] text-[var(--ink)]">Heroicons (outline, 1.5px) melalui <code class="rounded bg-[var(--surface)] px-1.5 py-0.5 text-sm text-[var(--ink-muted)]">flux:icon</code>. Saiz 16, 20 dan 24 piksel; 44×44 untuk sasaran sentuh.</p>
            </div>

            @php
                $icons = [
                    'magnifying-glass' => 'Cari',
                    'bookmark' => 'Simpan',
                    'check' => 'Dipilih',
                    'check-badge' => 'Disahkan',
                    'home' => 'Utama',
                    'map-pin' => 'Lokasi',
                    'calendar-days' => 'Tarikh',
                    'clock' => 'Waktu',
                    'users' => 'Penceramah',
                    'building-library' => 'Institusi',
                    'book-open' => 'Kitab',
                    'globe-alt' => 'Bahasa',
                    'share' => 'Kongsi',
                    'arrow-right' => 'Lihat lagi',
                    'funnel' => 'Tapis',
                    'tag' => 'Percuma',
                    'signal' => 'Siaran langsung',
                    'exclamation-triangle' => 'Dibatalkan',
                    'plus' => 'Tambah',
                    'x-mark' => 'Tutup',
                    'user' => 'Akaun',
                ];
            @endphp

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-7">
                @foreach ($icons as $icon => $label)
                    <div class="flex flex-col items-center gap-2 rounded-2xl border border-[var(--divider)] bg-[var(--surface)] px-2 py-4 text-center">
                        <flux:icon :name="$icon" class="size-6 text-[var(--ink-strong)]" />
                        <span class="font-sans text-xs leading-4 text-[var(--ink-muted)]">{{ $label }}</span>
                    </div>
                @endforeach
            </div>

            <div class="flex flex-wrap items-center gap-8 rounded-3xl border border-[var(--divider)] bg-[var(--surface)] p-6 shadow-[var(--shadow-1)]">
                <span class="flex items-center gap-3 font-sans text-sm font-medium text-[var(--ink-muted)]">
                    <flux:icon.bookmark class="size-4 text-[var(--ink-strong)]" />
                    16px · dalam teks
                </span>
                <span class="flex items-center gap-3 font-sans text-sm font-medium text-[var(--ink-muted)]">
                    <flux:icon.bookmark class="size-5 text-[var(--ink-strong)]" />
                    20px · metadata
                </span>
                <span class="flex items-center gap-3 font-sans text-sm font-medium text-[var(--ink-muted)]">
                    <flux:icon.bookmark class="size-6 text-[var(--ink-strong)]" />
                    24px · tindakan
                </span>
            </div>
        </section>

        <section aria-labelledby="konteks" class="space-y-6">
            <h2 id="konteks" class="font-heading text-[28px] leading-[36px] text-[var(--ink-strong)] sm:text-[36px] sm:leading-[44px]">Dalam konteks</h2>

            <div class="flex flex-wrap items-center gap-6 rounded-3xl border border-[var(--divider)] bg-[var(--surface)] p-6 shadow-[var(--shadow-1)]">
                <span class="inline-flex h-10 items-center gap-1.5 rounded-full bg-[var(--emerald-700)] px-4 font-sans text-sm font-medium text-white">
                    <flux:icon.check class="size-4" />
                    Kuliah
                </span>
                <span class="inline-flex h-10 items-center rounded-full border border-[var(--border-strong)] bg-[var(--surface)] px-4 font-sans text-sm font-medium text-[var(--ink-strong)]">Ceramah</span>

                <span class="inline-flex items-center gap-1.5 rounded-full border border-[var(--emerald-100)] bg-[var(--emerald-50)] px-3 py-1 font-sans text-sm font-medium text-[var(--emerald-800)]">
                    <flux:icon.check-badge class="size-4" />
                    Disahkan
                </span>

                <button type="button" aria-label="Simpan" class="grid size-11 place-items-center rounded-xl border border-[var(--divider)] bg-[var(--surface)] text-[var(--ink-muted)] shadow-[var(--shadow-1)]">
                    <flux:icon.bookmark class="size-5" />
                </button>
                <button type="button" aria-label="Disimpan" aria-pressed="true" class="grid size-11 place-items-center rounded-xl bg-[var(--emerald-700)] text-white shadow-[var(--shadow-1)]">
                    <flux:icon.bookmark class="size-5" variant="solid" />
                </button>
            </div>
        </section>

        <footer class="border-t border-[var(--divider)] pt-6 font-sans text-sm text-[var(--ink-muted)]">
            Halaman ujian sementara — boleh dipadam apabila arah reka bentuk v2 dikunci.
        </footer>
    </main>
</body>

</html>

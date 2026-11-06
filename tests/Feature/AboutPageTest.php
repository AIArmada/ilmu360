<?php

use function Pest\Laravel\get;

it('loads the about page through the canonical route', function (): void {
    $expected = data_get(trans('about'), 'reference.hero.title');

    get(route('about'))
        ->assertOk()
        ->assertSee((string) $expected);

    get('/tentang-kami')
        ->assertOk()
        ->assertSee((string) $expected);
});

it('renders the about hero behind the floating header', function (): void {
    get(route('about'))
        ->assertOk()
        ->assertSee('jp-shell-header absolute inset-x-0 top-0 z-50 w-full transition-all', false);
});

it('renders the about page in each supported locale', function (string $locale, string $expected): void {
    $this->withSession(['locale' => $locale])
        ->get(route('about'))
        ->assertOk()
        ->assertSee($expected);
})->with([
    ['en', 'Knowledge is closer than you think.'],
    ['ms', 'Ilmu di Sekeliling Kita.'],
    ['ar', 'العلم أقرب مما تظن.'],
    ['zh', '知识，其实就在身边。'],
    ['ta', 'அறிவு அருகில்தான் இருக்கிறது.'],
    ['jv', 'Ngèlmu tansah cedhak.'],
]);

it('tells the about story through the reference layout', function (): void {
    $this->withSession(['locale' => 'ms'])
        ->get(route('about'))
        ->assertOk()
        ->assertSee('Bukan Mewujudkan, Tetapi Menyerlahkan.')
        ->assertSee('Peluangnya Sangat Besar.')
        ->assertSee('Realiti Hari Ini.')
        ->assertSee('Dengan ilmu360°.')
        ->assertSee('Apa Itu ilmu360°?')
        ->assertSee('360° dalam Kehidupan Kita.')
        ->assertSee('Untuk Siapa?')
        ->assertSee('Membuka Jalan Kepada Generasi Baharu.')
        ->assertSee('Nilai yang')
        ->assertSee('Amanah Kami.')
        ->assertSee('Jom Terokai 360° Peluang Ilmu.')
        ->assertSee('Terokai Majlis Sekarang')
        ->assertSee('Hantar Majlis Anda')
        ->assertDontSee('Hassan')
        ->assertDontSee('Sarah')
        ->assertDontSee('Amir');
});

it('exposes canonical SEO and structured data for the about page', function (): void {
    $this->withSession(['locale' => 'ms'])
        ->get(route('about'))
        ->assertOk()
        ->assertSee('<link rel="canonical" href="'.route('about').'"', false)
        ->assertSee('"@type":"AboutPage"', false)
        ->assertSee('ilmu360°', false);
});

it('does not advertise capabilities the product does not have', function (): void {
    $this->withSession(['locale' => 'ms'])
        ->get(route('about'))
        ->assertOk()
        ->assertDontSee('2KM')
        ->assertDontSee('Waze')
        ->assertDontSee('Apple Celendar');
});

it('links to the about page from the public layout', function (): void {
    get(route('home'))
        ->assertOk()
        ->assertSee(route('about'), false)
        ->assertSee(__('About Us'));
});

it('does not render the retired living proof band', function (): void {
    $this->withSession(['locale' => 'ms'])
        ->get(route('about'))
        ->assertOk()
        ->assertDontSee('Majlis dan komuniti yang tersenarai di ilmu360°.');
});

it('renders the reference scale and trust markers', function (): void {
    $this->withSession(['locale' => 'ms'])
        ->get(route('about'))
        ->assertOk()
        ->assertSee('25,000+')
        ->assertSee('300,000+')
        ->assertSee('25,000 masjid × 1 majlis sebulan × 12 bulan = 300,000+ peluang ilmu setahun')
        ->assertSee('Amanah Kami.');
});

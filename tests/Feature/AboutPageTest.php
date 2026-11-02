<?php

use App\Enums\InstitutionStatus;
use App\Models\Institution;
use App\Models\Person;
use Illuminate\Support\Facades\Cache;

use function Pest\Laravel\get;

it('loads the about page through the canonical route', function (): void {
    $expected = data_get(trans('about'), 'hero.title');

    get(route('about'))
        ->assertOk()
        ->assertSee((string) $expected);

    get('/tentang-kami')
        ->assertOk()
        ->assertSee((string) $expected);
});

it('renders the about page in each supported locale', function (string $locale, string $expected): void {
    $this->withSession(['locale' => $locale])
        ->get(route('about'))
        ->assertOk()
        ->assertSee($expected);
})->with([
    ['en', 'The masjid is here. The majlis is here.'],
    ['ms', 'Masjidnya ada. Majlisnya ada.'],
    ['ar', 'المسجد موجود. المجلس موجود.'],
    ['zh', 'Masjid 在这里。Majlis 也在这里。'],
    ['ta', 'Masjid உள்ளது. Majlis உள்ளது.'],
    ['jv', 'Masjide wis ana. Majlise wis ana.'],
]);

it('tells the whole story the page is built around', function (): void {
    $this->withSession(['locale' => 'ms'])
        ->get(route('about'))
        ->assertOk()
        ->assertSee('Cuma khabarnya belum sampai.')
        ->assertSee('Anak-anak sudah tidur')
        ->assertSee('Hassan')
        ->assertSee('Sarah')
        ->assertSee('Amir')
        ->assertSee('Rantaian yang selalu putus')
        ->assertSee('Apa yang ilmu360° buat.')
        ->assertSee('menyelamatkan malam Selasa Hassan')
        ->assertSee('Jalan yang tenang.')
        ->assertSee('Cari yang dekat')
        ->assertSee('Ajak seorang lagi')
        ->assertSee('Siapa yang kami bina untuk ini.')
        ->assertSee('Nabi Muhammad')
        ->assertSee('Dengar. Faham. Amal.')
        ->assertSee('dalam perjalanan pulang yang sunyi')
        ->assertSee('Sertai komuniti');
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

it('hides the living proof band when the database is empty', function (): void {
    Cache::forget('about.stats');

    $this->withSession(['locale' => 'ms'])
        ->get(route('about'))
        ->assertOk()
        ->assertDontSee('Yang sudah berjalan.');
});

it('shows living proof with real counts once the ummah is inside', function (): void {
    Institution::factory()->create(['status' => InstitutionStatus::Verified]);
    Person::factory()->create(['status' => 'verified']);
    Cache::forget('about.stats');

    $this->withSession(['locale' => 'ms'])
        ->get(route('about'))
        ->assertOk()
        ->assertSee('Yang sudah berjalan.')
        ->assertSee('Masjid &amp; Surau', false)
        ->assertSee('Asatizah');
});

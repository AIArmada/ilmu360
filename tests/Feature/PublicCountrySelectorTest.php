<?php

use App\Support\Location\PublicCountryPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders a separate country selector beside the language selector with coming soon placeholders', function () {
    $response = $this->withSession(['locale' => 'en'])
        ->get(route('about'));

    $content = $response->getContent();

    $response->assertOk()
        ->assertSee('aria-label="Country: Malaysia"', false)
        ->assertSee(route('country.switch', 'malaysia'), false)
        ->assertDontSee(route('country.switch', 'brunei'), false)
        ->assertDontSee(route('country.switch', 'singapore'), false)
        ->assertDontSee(route('country.switch', 'indonesia'), false)
        ->assertSee('data-country-selector-style="flag-only"', false)
        ->assertSee(route('locale.switch', 'ms'), false)
        ->assertSee(route('locale.switch', 'en'), false)
        ->assertSee(route('locale.switch', 'jv'), false)
        ->assertDontSee(route('locale.switch', 'ar'), false)
        ->assertDontSee(route('locale.switch', 'zh'), false)
        ->assertDontSee(route('locale.switch', 'ta'), false)
        ->assertSee('data-language-switcher-case="title"', false);

    expect($content)
        ->toMatch('/data-country-option="malaysia"[^>]*>\s*<span aria-hidden="true">🇲🇾<\/span>\s*<\/a>/u')
        ->toMatch('/data-country-option="brunei"/u')
        ->toMatch('/data-language-switcher-trigger="desktop"[^>]*class="[^"]*tracking-wider[^"]*"/u')
        ->toMatch('/data-language-switcher-option="ms"/u')
        ->toMatch('/data-language-switcher-option="en"[^>]*class="[^"]*tracking-wider[^"]*"/u')
        ->toMatch('/data-language-switcher-option="jv"/u')
        ->not->toMatch('/data-language-switcher-option="ar"/u')
        ->not->toMatch('/data-language-switcher-option="zh"/u')
        ->not->toMatch('/data-language-switcher-option="ta"/u')
        ->not->toMatch('/data-language-switcher-trigger="desktop"[^>]*class="[^"]*uppercase[^"]*"/u')
        ->not->toMatch('/data-language-switcher-option="en"[^>]*class="[^"]*uppercase[^"]*"/u');
});

it('translates the country switcher label in Malay', function () {
    $response = $this->withSession(['locale' => 'ms'])
        ->get(route('about'));

    $response->assertOk()
        ->assertSee('aria-label="Negara: Malaysia"', false)
        ->assertSee('Negara');
});

it('renders the public shell in Arabic with rtl direction', function () {
    $response = $this->withSession(['locale' => 'ar'])
        ->get(route('about'));

    $response->assertOk()
        ->assertSee('lang="ar"', false)
        ->assertSee('dir="rtl"', false)
        ->assertSee('aria-label="البلد: Malaysia"', false)
        ->assertDontSee(route('locale.switch', 'ar'), false)
        ->assertDontSee('data-language-switcher-option="ar"', false)
        ->assertSee('البلد')
        ->assertSee('data-country-selector-style="flag-only"', false);
});

it('stores the selected country in session and cookie', function () {
    $response = $this->from(route('about'))
        ->get(route('country.switch', 'malaysia'));

    $response->assertRedirect(route('about'))
        ->assertSessionHas(PublicCountryPreference::SESSION_KEY, 'malaysia')
        ->assertPlainCookie(PublicCountryPreference::COOKIE_NAME, 'malaysia');
});

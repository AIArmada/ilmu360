<?php

use function Pest\Laravel\get;

it('loads the legal pages', function (): void {
    get(route('privacy'))
        ->assertOk()
        ->assertSee('Notis Privasi')
        ->assertDontSee('admin@ilmu360.com');

    get(route('terms'))
        ->assertOk()
        ->assertSee('Terma Penggunaan');

    get(route('contact'))
        ->assertOk()
        ->assertSee('Hubungi Kami')
        ->assertSee('Dedahkan alamat email')
        ->assertDontSee('admin@ilmu360.com')
        ->assertDontSee('youtube.svg');
});

it('renders the legal pages in English', function (): void {
    $this->withSession(['locale' => 'en'])
        ->get(route('privacy'))
        ->assertOk()
        ->assertSee('Privacy Notice')
        ->assertDontSee('admin@ilmu360.com');

    $this->withSession(['locale' => 'en'])
        ->get(route('terms'))
        ->assertOk()
        ->assertSee('Terms of Use');

    $this->withSession(['locale' => 'en'])
        ->get(route('contact'))
        ->assertOk()
        ->assertSee('Contact Us')
        ->assertSee('Show email address')
        ->assertDontSee('admin@ilmu360.com')
        ->assertDontSee('youtube.svg');
});

it('uses the floating menu header on the legal pages', function (): void {
    collect([
        route('privacy'),
        route('terms'),
        route('contact'),
    ])->each(function (string $url): void {
        $this->get($url)
            ->assertOk()
            ->assertSee('rounded-[2rem]', false)
            ->assertSee('pt-48', false);
    });
});

it('links the legal pages from the public footer', function (): void {
    get(route('home'))
        ->assertOk()
        ->assertSee(route('privacy'), false)
        ->assertSee(route('terms'), false)
        ->assertSee(route('contact'), false);
});

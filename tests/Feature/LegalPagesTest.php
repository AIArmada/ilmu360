<?php

use function Pest\Laravel\get;

it('loads the legal pages', function (): void {
    get(route('privacy'))
        ->assertOk()
        ->assertSee('Dasar Privasi')
        ->assertSee('hantar@ilmu360.com');

    get(route('terms'))
        ->assertOk()
        ->assertSee('Terma Penggunaan');

    get(route('contact'))
        ->assertOk()
        ->assertSee('Hubungi Kami')
        ->assertSee('hantar@ilmu360.com');
});

it('renders the legal pages in English', function (): void {
    $this->withSession(['locale' => 'en'])
        ->get(route('privacy'))
        ->assertOk()
        ->assertSee('Privacy Policy');

    $this->withSession(['locale' => 'en'])
        ->get(route('terms'))
        ->assertOk()
        ->assertSee('Terms of Use');

    $this->withSession(['locale' => 'en'])
        ->get(route('contact'))
        ->assertOk()
        ->assertSee('Contact Us');
});

it('links the legal pages from the public footer', function (): void {
    get(route('home'))
        ->assertOk()
        ->assertSee(route('privacy'), false)
        ->assertSee(route('terms'), false)
        ->assertSee(route('contact'), false);
});

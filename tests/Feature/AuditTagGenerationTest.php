<?php

use App\Models\Event;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

test('tags audited models as mcp during mcp requests', function () {
    Filament::setFacadeApplication($this->app);
    Filament::swap(new class
    {
        public function getCurrentPanel(): ?object
        {
            return null;
        }
    });

    $this->app->instance('request', Request::create('/mcp/admin', 'POST'));

    expect((new Event)->generateTags())
        ->toContain('mcp')
        ->not->toContain('api');
});

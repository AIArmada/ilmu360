<?php

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\PassportServiceProvider;

it('boots Passport and MCP auth providers for connector routes', function (): void {
    expect(app()->providerIsLoaded(PassportServiceProvider::class))->toBeTrue()
        ->and(app()->providerIsLoaded(McpServiceProvider::class))->toBeTrue()
        ->and(Route::has('passport.authorizations.authorize'))->toBeTrue()
        ->and(Route::has('passport.token'))->toBeTrue()
        ->and(Route::has('mcp.oauth.authorization-server.nested'))->toBeTrue()
        ->and(Route::has('mcp.oauth.protected-resource.nested'))->toBeTrue();
});

it('registers MCP OAuth clients without using Passport schema introspection', function (): void {
    config()->set('mcp.redirect_domains', [
        'https://ilmu360.test',
        'https://chatgpt.com',
        'http://localhost',
    ]);

    config()->set('mcp.custom_schemes', ['vscode']);

    $this->instance(ClientRepository::class, new class extends ClientRepository
    {
        public function createAuthorizationCodeGrantClient(
            string $name,
            array $redirectUris,
            bool $confidential = true,
            ?Authenticatable $user = null,
            bool $enableDeviceFlow = false
        ): Client {
            throw new RuntimeException('ClientRepository should not be used by MCP registration.');
        }
    });

    $response = $this->postJson('/oauth/mcp/register', [
        'client_name' => 'ChatGPT',
        'redirect_uris' => ['https://chatgpt.com/connector/oauth/callback-123'],
    ]);

    $response->assertOk()
        ->assertJsonPath('scope', 'mcp:use')
        ->assertJsonPath('token_endpoint_auth_method', 'none');

    $clientId = $response->json('client_id');

    expect($clientId)->toBeString()->not->toBeEmpty();

    $this->assertDatabaseHas('oauth_clients', [
        'id' => $clientId,
        'name' => 'ChatGPT',
        'revoked' => false,
    ]);
});

it('renders the Passport authorization screen for a registered MCP client', function (): void {
    config()->set('mcp.redirect_domains', [
        'https://ilmu360.test',
        'https://chatgpt.com',
        'http://localhost',
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        'ChatGPT',
        ['https://chatgpt.com/connector/oauth/callback-123'],
        true,
        null,
        false,
    );

    $response = $this->get('/oauth/authorize?client_id='.$client->id.'&redirect_uri='.urlencode('https://chatgpt.com/connector/oauth/callback-123').'&response_type=code&scope=mcp:use&state=test-state');

    $response->assertOk();
});

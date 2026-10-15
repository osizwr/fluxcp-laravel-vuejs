<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Account;
use App\Models\PanelSetting;
use App\Services\Settings\PanelSettings;
use App\Services\Theme\ClientBootstrap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRathena;
use Tests\TestCase;

/**
 * The dashboard: who may open it, and what editing the downloads does.
 *
 * The authorisation tests matter more than the editing ones. An editor that
 * saves the wrong string is a bug somebody notices; a dashboard a player can
 * reach is the whole public site handed over.
 */
final class AdminDashboardTest extends TestCase
{
    use InteractsWithRathena;
    use RefreshDatabase;

    /**
     * A download section with one package, in the shape the editor submits.
     *
     * @return array<string, mixed>
     */
    private function payload(string $url = 'https://mirror.example.test/client.zip'): array
    {
        return [
            'notice' => 'Patch 1.4 is out.',
            'clients' => [
                [
                    'name' => 'Full client',
                    'platform' => 'windows',
                    'badge' => 'New',
                    'version' => '1.4',
                    'size' => '2.1 GB',
                    'updated' => '2026-10-01',
                    'description' => 'Everything needed to play.',
                    'mirrors' => [['label' => 'Mega', 'url' => $url]],
                    'notes' => ['Unzip before running.'],
                ],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Signing in
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_administrator_can_sign_in_at_the_dashboard(): void
    {
        Account::factory()->administrator()->named('gm')->withPassword('Zeny4Days!')->create();

        $this->postJson('/api/admin/session', ['username' => 'gm', 'password' => 'Zeny4Days!'])
            ->assertOk()
            ->assertJsonPath('data.username', 'gm')
            ->assertJsonPath('data.group.level', 99);

        $this->assertAuthenticated();
    }

    #[Test]
    public function a_player_with_the_right_password_is_refused_and_left_signed_out(): void
    {
        Account::factory()->named('merchant')->withPassword('Zeny4Days!')->create();

        $this->postJson('/api/admin/session', [
            'username' => 'merchant',
            'password' => 'Zeny4Days!',
        ])->assertStatus(422);

        /*
         * The point of the endpoint. Refusing but signing them in anyway would
         * turn the admin form into a general-purpose one, and would confirm to
         * whoever typed them that the credentials were good.
         */
        $this->assertGuest();
    }

    #[Test]
    public function a_junior_game_master_is_not_enough(): void
    {
        Account::factory()->juniorGameMaster()->named('support')
            ->withPassword('Zeny4Days!')->create();

        $this->postJson('/api/admin/session', [
            'username' => 'support',
            'password' => 'Zeny4Days!',
        ])->assertStatus(422);

        $this->assertGuest();
    }

    #[Test]
    public function a_refused_administrator_and_a_wrong_password_look_identical(): void
    {
        Account::factory()->named('merchant')->withPassword('Zeny4Days!')->create();

        $refusedPlayer = $this->postJson('/api/admin/session', [
            'username' => 'merchant',
            'password' => 'Zeny4Days!',
        ]);

        $wrongPassword = $this->postJson('/api/admin/session', [
            'username' => 'merchant',
            'password' => 'NotThePassword!',
        ]);

        $refusedPlayer->assertStatus(422);
        $wrongPassword->assertStatus(422);

        $this->assertSame(
            $refusedPlayer->json('message'),
            $wrongPassword->json('message'),
            'A refused non-administrator must not be distinguishable from a bad password.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Reaching the settings
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_download_settings_are_administrator_only(): void
    {
        $this->getJson('/api/admin/settings/downloads')->assertStatus(401);

        $this->actingAs(Account::factory()->create())
            ->getJson('/api/admin/settings/downloads')->assertStatus(403);

        $this->actingAs(Account::factory()->seniorGameMaster()->create())
            ->getJson('/api/admin/settings/downloads')->assertStatus(403);

        $this->actingAs(Account::factory()->administrator()->create())
            ->getJson('/api/admin/settings/downloads')->assertOk();
    }

    #[Test]
    public function writing_and_resetting_are_administrator_only(): void
    {
        $player = Account::factory()->create();

        $this->actingAs($player)
            ->putJson('/api/admin/settings/downloads', $this->payload())
            ->assertStatus(403);

        $this->actingAs($player)
            ->deleteJson('/api/admin/settings/downloads')
            ->assertStatus(403);

        $this->assertDatabaseCount('panel_settings', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | Editing
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_serves_the_configured_defaults_before_anything_is_overridden(): void
    {
        config()->set('game.downloads.notice', 'From the config file.');
        config()->set('game.downloads.clients', [[
            'name' => 'Configured client',
            'platform' => 'windows',
            'mirrors' => [['label' => 'Direct', 'url' => 'https://example.test/a.zip']],
        ]]);

        $this->actingAs(Account::factory()->administrator()->create())
            ->getJson('/api/admin/settings/downloads')
            ->assertOk()
            ->assertJsonPath('data.notice', 'From the config file.')
            ->assertJsonPath('data.clients.0.name', 'Configured client')
            ->assertJsonPath('meta.overridden', false);
    }

    #[Test]
    public function an_administrator_can_save_the_download_section(): void
    {
        $admin = Account::factory()->administrator()->create();

        $this->actingAs($admin)
            ->putJson('/api/admin/settings/downloads', $this->payload())
            ->assertOk()
            ->assertJsonPath('data.notice', 'Patch 1.4 is out.')
            ->assertJsonPath('data.clients.0.name', 'Full client')
            ->assertJsonPath('data.clients.0.mirrors.0.label', 'Mega')
            ->assertJsonPath('meta.overridden', true);

        $this->assertDatabaseHas('panel_settings', [
            'key' => 'downloads.clients',
            'updated_by' => $admin->account_id,
        ]);
    }

    #[Test]
    public function what_is_saved_is_what_the_public_page_is_rendered_from(): void
    {
        $this->actingAs(Account::factory()->administrator()->create())
            ->putJson('/api/admin/settings/downloads', $this->payload())
            ->assertOk();

        /*
         * Resolved fresh, because the bootstrap memoises the overrides for the
         * life of a request and this is asking what the *next* visitor sees.
         */
        $this->app->forgetInstance(PanelSettings::class);
        $this->app->forgetInstance(ClientBootstrap::class);

        $downloads = $this->app->make(ClientBootstrap::class)->downloads();

        $this->assertSame('Patch 1.4 is out.', $downloads['notice']);
        $this->assertSame('Full client', $downloads['clients'][0]['name']);
        $this->assertSame(
            'https://mirror.example.test/client.zip',
            $downloads['clients'][0]['mirrors'][0]['url'],
        );
    }

    #[Test]
    public function resetting_drops_the_override_and_the_config_file_takes_over_again(): void
    {
        config()->set('game.downloads.notice', 'From the config file.');

        $admin = Account::factory()->administrator()->create();

        $this->actingAs($admin)
            ->putJson('/api/admin/settings/downloads', $this->payload())
            ->assertOk()
            ->assertJsonPath('data.notice', 'Patch 1.4 is out.');

        $this->actingAs($admin)
            ->deleteJson('/api/admin/settings/downloads')
            ->assertOk()
            ->assertJsonPath('data.notice', 'From the config file.')
            ->assertJsonPath('meta.overridden', false);

        $this->assertDatabaseCount('panel_settings', 0);
    }

    #[Test]
    public function clearing_the_notice_keeps_it_cleared_rather_than_falling_back(): void
    {
        config()->set('game.downloads.notice', 'From the config file.');

        $payload = $this->payload();
        $payload['notice'] = null;

        $this->actingAs(Account::factory()->administrator()->create())
            ->putJson('/api/admin/settings/downloads', $payload)
            ->assertOk()
            ->assertJsonPath('data.notice', null);
    }

    /*
    |--------------------------------------------------------------------------
    | What it refuses to store
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_refuses_a_mirror_link_that_is_not_http(): void
    {
        $this->actingAs(Account::factory()->administrator()->create())
            ->putJson('/api/admin/settings/downloads', $this->payload('javascript:alert(1)'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('clients.0.mirrors.0.url');

        $this->assertDatabaseCount('panel_settings', 0);
    }

    #[Test]
    public function it_refuses_a_protocol_relative_mirror_link(): void
    {
        $this->actingAs(Account::factory()->administrator()->create())
            ->putJson('/api/admin/settings/downloads', $this->payload('//evil.test/client.zip'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('clients.0.mirrors.0.url');
    }

    #[Test]
    public function it_accepts_a_path_on_this_servers_own_host(): void
    {
        $this->actingAs(Account::factory()->administrator()->create())
            ->putJson('/api/admin/settings/downloads', $this->payload('/files/client.zip'))
            ->assertOk()
            ->assertJsonPath('data.clients.0.mirrors.0.url', '/files/client.zip');
    }

    #[Test]
    public function it_refuses_a_package_with_no_name(): void
    {
        $payload = $this->payload();
        $payload['clients'][0]['name'] = '';

        $this->actingAs(Account::factory()->administrator()->create())
            ->putJson('/api/admin/settings/downloads', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('clients.0.name');
    }

    #[Test]
    public function a_platform_it_does_not_recognise_is_refused_rather_than_guessed_at(): void
    {
        $payload = $this->payload();
        $payload['clients'][0]['platform'] = 'amiga';

        $this->actingAs(Account::factory()->administrator()->create())
            ->putJson('/api/admin/settings/downloads', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('clients.0.platform');
    }

    #[Test]
    public function a_server_with_no_client_yet_is_a_state_it_can_be_saved_in(): void
    {
        $this->actingAs(Account::factory()->administrator()->create())
            ->putJson('/api/admin/settings/downloads', ['notice' => null, 'clients' => []])
            ->assertOk()
            ->assertJsonPath('data.clients', []);
    }

    /*
    |--------------------------------------------------------------------------
    | The store itself
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_unset_key_falls_back_to_the_config_file(): void
    {
        config()->set('game.downloads.notice', 'From the config file.');

        $settings = $this->app->make(PanelSettings::class);

        $this->assertFalse($settings->has('downloads.notice'));
        $this->assertSame(
            'From the config file.',
            $settings->resolve('downloads.notice', 'game.downloads.notice'),
        );
    }

    #[Test]
    public function an_override_of_null_is_honoured_rather_than_treated_as_absent(): void
    {
        config()->set('game.downloads.notice', 'From the config file.');

        $settings = $this->app->make(PanelSettings::class);
        $settings->put('downloads.notice', null);

        $this->assertTrue($settings->has('downloads.notice'));
        $this->assertNull($settings->resolve('downloads.notice', 'game.downloads.notice'));
    }

    #[Test]
    public function a_stored_list_round_trips_as_a_list_rather_than_as_json(): void
    {
        $settings = $this->app->make(PanelSettings::class);
        $settings->put('downloads.clients', [['name' => 'Full client', 'mirrors' => []]]);

        $this->assertSame(
            [['name' => 'Full client', 'mirrors' => []]],
            $settings->get('downloads.clients'),
        );

        $this->assertDatabaseCount('panel_settings', 1);
        $this->assertIsArray(PanelSetting::query()->firstOrFail()->value);
    }
}

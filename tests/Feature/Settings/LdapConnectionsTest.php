<?php

namespace Tests\Feature\Settings;

use App\Livewire\LdapConnections;
use App\Models\Actionlog;
use App\Models\Company;
use App\Models\LdapConnection;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

class LdapConnectionsTest extends TestCase
{
    private function ldapUser(LdapConnection $connection, array $attributes = []): User
    {
        $user = User::factory()->create(['activated' => 1] + $attributes);
        $user->forceFill(['ldap_import' => 1, 'ldap_connection_id' => $connection->id])->saveQuietly();

        return $user->fresh();
    }

    public function test_requires_superadmin()
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(LdapConnections::class)->assertStatus(403);
    }

    public function test_toggles_connection_enabled()
    {
        $this->actingAs(User::factory()->superuser()->create());
        $connection = LdapConnection::factory()->create(['enabled' => 1]);

        Livewire::test(LdapConnections::class)->call('toggleEnabled', $connection->id);

        $this->assertFalse($connection->fresh()->enabled);
    }

    public function test_cannot_delete_connection_that_still_has_users()
    {
        $this->actingAs(User::factory()->superuser()->create());
        $connection = LdapConnection::factory()->disabled()->create();
        $this->ldapUser($connection);

        Livewire::test(LdapConnections::class)
            ->call('deleteConnection', $connection->id)
            ->assertSet('statusType', 'danger');

        $this->assertModelExists($connection);
    }

    public function test_deletes_connection_without_users()
    {
        $this->actingAs(User::factory()->superuser()->create());
        $connection = LdapConnection::factory()->create();

        Livewire::test(LdapConnections::class)->call('deleteConnection', $connection->id);

        $this->assertModelMissing($connection);
    }

    public function test_detach_refuses_enabled_connection()
    {
        $this->actingAs(User::factory()->superuser()->create());
        $connection = LdapConnection::factory()->create(['enabled' => 1]);
        $user = $this->ldapUser($connection);

        Livewire::test(LdapConnections::class)
            ->call('detach', $connection->id)
            ->assertSet('statusType', 'danger');

        $this->assertModelExists($connection);
        $this->assertSame(1, (int) $user->fresh()->ldap_import);
    }

    public function test_detach_turns_users_into_local_accounts_and_deletes_connection()
    {
        $this->actingAs(User::factory()->superuser()->create());
        $connection = LdapConnection::factory()->disabled()->create(['ldap_pw_sync' => 0]);
        $otherConnection = LdapConnection::factory()->create();
        $company = Company::factory()->create();
        $user = $this->ldapUser($connection, ['remember_token' => 'token']);
        $user->companies()->sync([$company->id]);
        $otherUser = $this->ldapUser($otherConnection);
        $location = Location::factory()->create(['ldap_ou' => 'OU=Kyiv,DC=example,DC=com']);
        $location->forceFill(['ldap_connection_id' => $connection->id])->saveQuietly();

        Livewire::test(LdapConnections::class)
            ->call('detach', $connection->id)
            ->assertSet('statusType', 'success');

        $user->refresh();
        $this->assertSame(0, (int) $user->ldap_import);
        $this->assertNull($user->ldap_connection_id);
        $this->assertNull($user->remember_token);
        $this->assertSame($user->noPassword(), $user->password);
        $this->assertSame([$company->id], $user->companies()->pluck('companies.id')->all());
        $this->assertSame(1, (int) $otherUser->fresh()->ldap_import);
        $this->assertNull($location->fresh()->ldap_ou);
        $this->assertNull($location->fresh()->ldap_connection_id);
        $this->assertModelMissing($connection);
        $this->assertTrue(Actionlog::where('item_type', User::class)->where('item_id', $user->id)->where('action_type', 'update')->exists());

        // No usable password: neither an empty nor any other password logs in.
        $this->assertFalse(Auth::validate(['username' => $user->username, 'password' => '']));
        $this->assertFalse(Auth::validate(['username' => $user->username, 'password' => '*** NO PASSWORD ***']));
    }

    public function test_detach_drops_employee_number_links_but_keeps_their_companies()
    {
        $this->actingAs(User::factory()->superuser()->create());
        $owner = LdapConnection::factory()->create();
        $company = Company::factory()->create();
        $linker = LdapConnection::factory()->disabled()->create([
            'link_by_employee_number' => 1,
            'company_source' => LdapConnection::COMPANY_SOURCE_CONNECTION,
            'company_id' => $company->id,
        ]);
        $user = $this->ldapUser($owner);
        \App\Models\Ldap::withConnection($linker, fn () => \App\Models\Ldap::linkUserToCurrentConnection($user, []));

        Livewire::test(LdapConnections::class)->call('deleteConnection', $linker->id);

        $this->assertModelMissing($linker);
        $this->assertDatabaseMissing('ldap_connection_user', ['ldap_connection_id' => $linker->id]);
        $this->assertContains($company->id, $user->companies()->pluck('companies.id')->all());
        $this->assertSame($owner->id, (int) $user->fresh()->ldap_connection_id);
    }

    public function test_detach_keeps_cached_ldap_password_as_local_password()
    {
        $this->actingAs(User::factory()->superuser()->create());
        $connection = LdapConnection::factory()->disabled()->create(['ldap_pw_sync' => 1]);
        $user = $this->ldapUser($connection, ['password' => bcrypt('last-ad-password')]);

        Livewire::test(LdapConnections::class)->call('detach', $connection->id);

        $this->assertSame(0, (int) $user->fresh()->ldap_import);
        $this->assertTrue(Auth::validate(['username' => $user->username, 'password' => 'last-ad-password']));
    }
}

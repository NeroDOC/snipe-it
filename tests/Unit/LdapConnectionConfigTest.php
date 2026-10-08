<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\Ldap;
use App\Models\LdapConnection;
use App\Models\Setting;
use App\Models\User;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

/**
 * Which LDAP config the static Ldap helpers read, and how the per-connection
 * company source is applied.
 */
#[Group('ldap')]
class LdapConnectionConfigTest extends TestCase
{
    private function ldapAttributes(array $overrides = []): array
    {
        return array_merge([
            'samaccountname' => ['jsmith'],
            'givenname' => ['Jane'],
            'sn' => ['Smith'],
            'mail' => ['jane@example.com'],
            'company' => ['Acme'],
        ], $overrides);
    }

    public function test_config_falls_back_to_settings_row_without_connections(): void
    {
        $this->settings->enableLdap();

        $this->assertInstanceOf(Setting::class, Ldap::config());
        $this->assertNull(Ldap::currentConnection());
    }

    public function test_config_defaults_to_first_enabled_connection_by_priority(): void
    {
        LdapConnection::factory()->disabled()->create(['priority' => 0]);
        $second = LdapConnection::factory()->create(['priority' => 5]);
        $first = LdapConnection::factory()->create(['priority' => 1]);

        $this->assertSame($first->id, Ldap::currentConnection()->id);
        $this->assertNotSame($second->id, Ldap::currentConnection()->id);
    }

    public function test_with_connection_restores_previous_connection_even_on_exception(): void
    {
        $default = LdapConnection::factory()->create(['priority' => 0]);
        $other = LdapConnection::factory()->create(['priority' => 1]);

        $inside = Ldap::withConnection($other, fn () => Ldap::currentConnection()->id);
        $this->assertSame($other->id, $inside);

        try {
            Ldap::withConnection($other, fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
        }

        $this->assertSame($default->id, Ldap::currentConnection()->id);
    }

    public function test_created_user_is_stamped_with_active_connection(): void
    {
        $connection = LdapConnection::factory()->create();

        $user = Ldap::withConnection($connection, fn () => Ldap::createUserFromLdap($this->ldapAttributes(), 'pw'));

        $this->assertSame($connection->id, (int) $user->ldap_connection_id);
    }

    public function test_company_source_connection_assigns_fixed_company(): void
    {
        $company = Company::factory()->create();
        $connection = LdapConnection::factory()->create([
            'company_source' => LdapConnection::COMPANY_SOURCE_CONNECTION,
            'company_id' => $company->id,
        ]);
        $user = User::factory()->withoutCompany()->create();

        Ldap::withConnection($connection, fn () => Ldap::applyLdapCompanyToUser($user, ['company' => 'Ignored Co']));

        $this->assertSame([$company->id], $user->companies()->pluck('companies.id')->all());
        $this->assertDatabaseMissing('companies', ['name' => 'Ignored Co']);
    }

    public function test_company_source_attribute_uses_ldap_value(): void
    {
        $connection = LdapConnection::factory()->create([
            'company_source' => LdapConnection::COMPANY_SOURCE_ATTRIBUTE,
            'ldap_company' => 'company',
        ]);
        $user = User::factory()->withoutCompany()->create();

        Ldap::withConnection($connection, fn () => Ldap::applyLdapCompanyToUser($user, ['company' => 'Acme']));

        $this->assertSame(['Acme'], $user->companies()->pluck('name')->all());
    }

    public function test_company_source_none_leaves_memberships_alone(): void
    {
        $connection = LdapConnection::factory()->create([
            'company_source' => LdapConnection::COMPANY_SOURCE_NONE,
            'ldap_company' => 'company',
        ]);
        $existing = Company::factory()->create();
        $user = User::factory()->withoutCompany()->create();
        $user->companies()->sync([$existing->id]);

        Ldap::withConnection($connection, fn () => Ldap::applyLdapCompanyToUser($user, ['company' => 'Acme']));

        $this->assertSame([$existing->id], $user->companies()->pluck('companies.id')->all());
        $this->assertDatabaseMissing('companies', ['name' => 'Acme']);
    }
}

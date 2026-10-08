<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\Ldap;
use App\Models\LdapConnection;
use App\Models\User;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Linking a person who exists in several directories to one account by
 * employee number.
 */
#[Group('ldap')]
class LdapEmployeeNumberLinkTest extends TestCase
{
    private LdapConnection $owner;

    private LdapConnection $linker;

    private Company $ownerCompany;

    private Company $linkerCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ownerCompany = Company::factory()->create();
        $this->linkerCompany = Company::factory()->create();
        $this->owner = LdapConnection::factory()->create([
            'company_source' => LdapConnection::COMPANY_SOURCE_CONNECTION,
            'company_id' => $this->ownerCompany->id,
        ]);
        $this->linker = LdapConnection::factory()->create([
            'link_by_employee_number' => 1,
            'company_source' => LdapConnection::COMPANY_SOURCE_CONNECTION,
            'company_id' => $this->linkerCompany->id,
        ]);
    }

    private function ownedUser(string $employeeNumber = '9851'): User
    {
        $user = User::factory()->withoutCompany()->create(['username' => 'maxborukh', 'employee_num' => $employeeNumber]);
        $user->forceFill(['ldap_import' => 1, 'ldap_connection_id' => $this->owner->id])->saveQuietly();
        Ldap::withConnection($this->owner, fn () => Ldap::applyLdapCompanyToUser($user, []));

        return $user->fresh();
    }

    private function companyIds(User $user): array
    {
        return $user->companies()->orderBy('companies.id')->pluck('companies.id')->all();
    }

    public function test_finds_user_owned_by_another_connection_with_the_same_employee_number(): void
    {
        $user = $this->ownedUser();

        $found = Ldap::withConnection($this->linker, fn () => Ldap::findLinkableUser(['employee_num' => ' 9851 ']));

        $this->assertSame($user->id, $found?->id);
    }

    public function test_does_not_link_when_disabled_ambiguous_or_without_employee_number(): void
    {
        $this->ownedUser();
        $this->linker->forceFill(['link_by_employee_number' => 0])->forceSave();
        $this->assertNull(Ldap::withConnection($this->linker, fn () => Ldap::findLinkableUser(['employee_num' => '9851'])));

        $this->linker->forceFill(['link_by_employee_number' => 1])->forceSave();
        $this->assertNull(Ldap::withConnection($this->linker, fn () => Ldap::findLinkableUser(['employee_num' => ''])));

        $second = User::factory()->create(['employee_num' => '9851']);
        $second->forceFill(['ldap_import' => 1, 'ldap_connection_id' => $this->owner->id])->saveQuietly();
        $this->assertNull(Ldap::withConnection($this->linker, fn () => Ldap::findLinkableUser(['employee_num' => '9851'])));
    }

    public function test_link_adds_the_linking_connections_company_and_keeps_the_profile(): void
    {
        $user = $this->ownedUser();

        Ldap::withConnection($this->linker, fn () => Ldap::linkUserToCurrentConnection($user, ['first_name' => 'Changed']));

        $user->refresh();
        $this->assertSame([$this->ownerCompany->id, $this->linkerCompany->id], $this->companyIds($user));
        $this->assertNotSame('Changed', $user->first_name);
        $this->assertSame($this->owner->id, (int) $user->ldap_connection_id);
        $this->assertDatabaseHas('ldap_connection_user', [
            'ldap_connection_id' => $this->linker->id,
            'user_id' => $user->id,
            'company_id' => $this->linkerCompany->id,
        ]);
    }

    public function test_unlink_removes_only_the_linked_company(): void
    {
        $user = $this->ownedUser();
        Ldap::withConnection($this->linker, fn () => Ldap::linkUserToCurrentConnection($user, []));

        Ldap::unlinkUserFromConnection($user->fresh(), $this->linker);

        $this->assertSame([$this->ownerCompany->id], $this->companyIds($user));
        $this->assertDatabaseMissing('ldap_connection_user', ['user_id' => $user->id]);
    }

    public function test_unlink_keeps_a_company_the_owner_also_assigns(): void
    {
        $this->linker->forceFill(['company_id' => $this->ownerCompany->id])->forceSave();
        $user = $this->ownedUser();
        Ldap::withConnection($this->linker, fn () => Ldap::linkUserToCurrentConnection($user, []));

        Ldap::unlinkUserFromConnection($user->fresh(), $this->linker);

        $this->assertSame([$this->ownerCompany->id], $this->companyIds($user));
    }

    public function test_owner_company_change_keeps_a_company_a_link_assigns(): void
    {
        $user = $this->ownedUser();
        Ldap::withConnection($this->linker, fn () => Ldap::linkUserToCurrentConnection($user, []));
        $this->owner->forceFill(['company_id' => $this->linkerCompany->id])->forceSave();
        Ldap::withConnection($this->owner->fresh(), fn () => Ldap::applyLdapCompanyToUser($user->fresh(), []));
        $newOwnerCompany = Company::factory()->create();
        $this->owner->forceFill(['company_id' => $newOwnerCompany->id])->forceSave();

        Ldap::withConnection($this->owner->fresh(), fn () => Ldap::applyLdapCompanyToUser($user->fresh(), []));

        $this->assertContains($this->linkerCompany->id, $this->companyIds($user));
        $this->assertContains($newOwnerCompany->id, $this->companyIds($user));
    }
}

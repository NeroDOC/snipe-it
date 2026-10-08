<?php

namespace Tests\Feature\Console;

use App\Console\Commands\LdapSync;
use App\Models\Ldap;
use App\Models\LdapConnection;
use App\Models\User;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * snipeit:ldap-sync with several LDAP connections. The ldap_* functions
 * are mocked in the App\Models namespace, where Ldap calls them.
 */
#[Group('ldap')]
class LdapSyncConnectionsTest extends TestCase
{
    use PHPMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings->set(['ldap_enabled' => 1]);
    }

    /**
     * Every directory search returns the given usernames.
     */
    private function mockDirectory(string ...$usernames): void
    {
        $entries = ['count' => count($usernames)];
        foreach ($usernames as $username) {
            $entries[] = [
                'samaccountname' => ['count' => 1, 0 => $username],
                'givenname' => ['count' => 1, 0 => ucfirst($username)],
                'sn' => ['count' => 1, 0 => 'Tester'],
                'mail' => ['count' => 1, 0 => $username.'@example.com'],
            ];
        }

        $this->getFunctionMock('App\\Models', 'ldap_connect')->expects($this->any())->willReturn('conn');
        $this->getFunctionMock('App\\Models', 'ldap_set_option')->expects($this->any())->willReturn(true);
        $this->getFunctionMock('App\\Models', 'ldap_bind')->expects($this->any())->willReturn(true);
        $this->getFunctionMock('App\\Models', 'ldap_search')->expects($this->any())->willReturn('res');
        $this->getFunctionMock('App\\Models', 'ldap_parse_result')->expects($this->any())->willReturn(true);
        $this->getFunctionMock('App\\Models', 'ldap_get_entries')->expects($this->any())->willReturn($entries);
    }

    private function ldapUser(LdapConnection $connection, string $username): User
    {
        $user = User::factory()->create(['username' => $username]);
        $user->forceFill(['ldap_import' => 1, 'ldap_connection_id' => $connection->id])->saveQuietly();

        return $user;
    }

    public function test_synced_users_are_stamped_with_their_connection(): void
    {
        $connection = LdapConnection::factory()->create();
        $this->mockDirectory('alice');

        $this->artisan('snipeit:ldap-sync', ['--connection' => $connection->id, '--json_summary' => true])->assertSuccessful();

        $this->assertSame($connection->id, (int) User::where('username', 'alice')->first()->ldap_connection_id);
    }

    public function test_delete_only_removes_missing_users_of_the_synced_connection(): void
    {
        $domainA = LdapConnection::factory()->create(['priority' => 0]);
        $domainB = LdapConnection::factory()->create(['priority' => 1]);
        $goneFromA = $this->ldapUser($domainA, 'carol');
        $fromB = $this->ldapUser($domainB, 'bob');
        $this->mockDirectory('alice');

        $this->artisan('snipeit:ldap-sync', ['--connection' => $domainA->id, '--delete' => true, '--json_summary' => true]);

        $this->assertSoftDeleted($goneFromA);
        $this->assertNotSoftDeleted($fromB);
    }

    public function test_never_takes_over_a_user_owned_by_another_connection(): void
    {
        $domainA = LdapConnection::factory()->create(['priority' => 0]);
        $domainB = LdapConnection::factory()->create(['priority' => 1]);
        $bob = $this->ldapUser($domainB, 'bob');
        $this->mockDirectory('bob');

        $this->artisan('snipeit:ldap-sync', ['--connection' => $domainA->id, '--json_summary' => true]);

        $this->assertSame($domainB->id, (int) $bob->fresh()->ldap_connection_id);
    }

    public function test_disabled_connection_is_skipped_and_its_users_untouched(): void
    {
        $disabled = LdapConnection::factory()->disabled()->create();
        $carol = $this->ldapUser($disabled, 'carol');
        $this->mockDirectory('alice');

        $this->artisan('snipeit:ldap-sync', ['--delete' => true, '--json_summary' => true]);

        $this->assertNotSoftDeleted($carol);
        $this->assertNull(User::where('username', 'alice')->first());
    }

    public function test_manager_is_found_by_dn_outside_the_user_filter_and_matched_by_employee_number(): void
    {
        $domainA = LdapConnection::factory()->create([
            'ldap_filter' => '&(objectClass=person)(!(mail=max.borukh@example.net))',
            'ldap_manager' => 'manager',
            'ldap_emp_num' => 'employeeid',
        ]);
        $managerFromOtherDomain = User::factory()->create(['username' => 'maxborukh', 'email' => 'max.borukh@example.com', 'employee_num' => '9851']);
        $managerDn = 'CN=Max Borukh,OU=IT,DC=example,DC=net';

        $searches = [];
        $this->getFunctionMock('App\\Models', 'ldap_connect')->expects($this->any())->willReturn('conn');
        $this->getFunctionMock('App\\Models', 'ldap_set_option')->expects($this->any())->willReturn(true);
        $this->getFunctionMock('App\\Models', 'ldap_bind')->expects($this->any())->willReturn(true);
        $this->getFunctionMock('App\\Models', 'ldap_parse_result')->expects($this->any())->willReturn(true);
        $this->getFunctionMock('App\\Models', 'ldap_search')->expects($this->any())
            ->willReturnCallback(function ($connection, $base, $filter) use (&$searches) {
                $searches[] = [$base, $filter];

                return 'res';
            });
        $this->getFunctionMock('App\\Models', 'ldap_get_entries')->expects($this->exactly(2))->willReturnOnConsecutiveCalls(
            ['count' => 1, 0 => [
                'samaccountname' => ['count' => 1, 0 => 'alexander'],
                'givenname' => ['count' => 1, 0 => 'Alexander'],
                'sn' => ['count' => 1, 0 => 'Tester'],
                'mail' => ['count' => 1, 0 => 'alexander@example.net'],
                'manager' => ['count' => 1, 0 => $managerDn],
            ]],
            ['count' => 1, 0 => [
                'samaccountname' => ['count' => 1, 0 => 'max.borukh'],
                'mail' => ['count' => 1, 0 => 'max.borukh@example.net'],
                'employeeid' => ['count' => 1, 0 => '9851'],
            ]],
        );

        $this->artisan('snipeit:ldap-sync', ['--connection' => $domainA->id, '--json_summary' => true]);

        $this->assertSame([$managerDn, '(objectClass=*)'], $searches[1]);
        $this->assertSame($managerFromOtherDomain->id, (int) User::where('username', 'alexander')->first()->manager_id);
    }

    public function test_find_local_manager_prefers_username_then_email_then_employee_number(): void
    {
        $map = ['username' => 'samaccountname', 'email' => 'mail', 'employee_num' => 'employeeid'];
        $byUsername = User::factory()->create(['username' => 'jdoe', 'email' => 'other@example.com', 'employee_num' => '1']);
        $byEmail = User::factory()->create(['username' => 'john.d', 'email' => 'John.Doe@Example.com', 'employee_num' => '2']);
        $byEmployeeNumber = User::factory()->create(['username' => 'johnd', 'email' => 'jd@example.org', 'employee_num' => '3']);

        $entry = fn (string $username, string $mail, string $employeeId) => [
            'samaccountname' => [$username], 'mail' => [$mail], 'employeeid' => [$employeeId],
        ];

        $this->assertSame($byUsername->id, LdapSync::findLocalManager($entry('jdoe', 'john.doe@example.com', '3'), $map)?->id);
        $this->assertSame($byEmail->id, LdapSync::findLocalManager($entry('unknown', 'john.doe@example.com', '3'), $map)?->id);
        $this->assertSame($byEmployeeNumber->id, LdapSync::findLocalManager($entry('unknown', 'unknown@example.com', '3'), $map)?->id);
    }

    public function test_find_local_manager_ignores_ambiguous_employee_numbers(): void
    {
        $map = ['username' => 'samaccountname', 'email' => 'mail', 'employee_num' => 'employeeid'];
        User::factory()->count(2)->create(['employee_num' => '777']);

        $this->assertNull(LdapSync::findLocalManager(['samaccountname' => ['nobody'], 'employeeid' => ['777']], $map));
    }

    /**
     * Every directory search returns the given entries.
     */
    private function mockEntries(array $entries): void
    {
        $this->getFunctionMock('App\\Models', 'ldap_connect')->expects($this->any())->willReturn('conn');
        $this->getFunctionMock('App\\Models', 'ldap_set_option')->expects($this->any())->willReturn(true);
        $this->getFunctionMock('App\\Models', 'ldap_bind')->expects($this->any())->willReturn(true);
        $this->getFunctionMock('App\\Models', 'ldap_search')->expects($this->any())->willReturn('res');
        $this->getFunctionMock('App\\Models', 'ldap_parse_result')->expects($this->any())->willReturn(true);
        $this->getFunctionMock('App\\Models', 'ldap_get_entries')->expects($this->any())->willReturn(['count' => count($entries)] + $entries);
    }

    private function directoryEntry(string $username, string $employeeNumber): array
    {
        return [
            'samaccountname' => ['count' => 1, 0 => $username],
            'givenname' => ['count' => 1, 0 => 'Max'],
            'sn' => ['count' => 1, 0 => 'Borukh'],
            'mail' => ['count' => 1, 0 => $username.'@example.net'],
            'employeeid' => ['count' => 1, 0 => $employeeNumber],
        ];
    }

    public function test_same_person_in_another_directory_is_linked_by_employee_number_instead_of_duplicated(): void
    {
        $owner = LdapConnection::factory()->create(['priority' => 0]);
        $linkerCompany = \App\Models\Company::factory()->create();
        $linker = LdapConnection::factory()->create([
            'priority' => 1,
            'ldap_emp_num' => 'employeeid',
            'link_by_employee_number' => 1,
            'company_source' => LdapConnection::COMPANY_SOURCE_CONNECTION,
            'company_id' => $linkerCompany->id,
        ]);
        $max = User::factory()->withoutCompany()->create(['username' => 'maxborukh', 'first_name' => 'Maksym', 'employee_num' => '9851']);
        $max->forceFill(['ldap_import' => 1, 'ldap_connection_id' => $owner->id])->saveQuietly();
        $this->mockEntries([$this->directoryEntry('max.borukh', '9851')]);

        $this->artisan('snipeit:ldap-sync', ['--connection' => $linker->id, '--json_summary' => true]);

        $this->assertNull(User::where('username', 'max.borukh')->first());
        $max->refresh();
        $this->assertSame('Maksym', $max->first_name);
        $this->assertSame($owner->id, (int) $max->ldap_connection_id);
        $this->assertSame([$linkerCompany->id], $max->companies()->pluck('companies.id')->all());
    }

    public function test_merged_duplicate_is_linked_past_instead_of_restored(): void
    {
        $owner = LdapConnection::factory()->create(['priority' => 0]);
        $linker = LdapConnection::factory()->create(['priority' => 1, 'ldap_emp_num' => 'employeeid', 'link_by_employee_number' => 1]);
        $max = User::factory()->create(['username' => 'maxborukh', 'employee_num' => '9851']);
        $max->forceFill(['ldap_import' => 1, 'ldap_connection_id' => $owner->id])->saveQuietly();
        $mergedDuplicate = User::factory()->create(['username' => 'max.borukh', 'employee_num' => '9851']);
        $mergedDuplicate->forceFill(['ldap_import' => 1, 'ldap_connection_id' => $linker->id])->saveQuietly();
        $mergedDuplicate->delete();
        $this->mockEntries([$this->directoryEntry('max.borukh', '9851')]);

        $this->artisan('snipeit:ldap-sync', ['--connection' => $linker->id, '--json_summary' => true]);

        $this->assertSoftDeleted($mergedDuplicate);
        $this->assertDatabaseHas('ldap_connection_user', ['ldap_connection_id' => $linker->id, 'user_id' => $max->id]);
    }

    public function test_delete_unlinks_people_who_left_the_linking_directory(): void
    {
        $owner = LdapConnection::factory()->create(['priority' => 0]);
        $linkerCompany = \App\Models\Company::factory()->create();
        $linker = LdapConnection::factory()->create([
            'priority' => 1,
            'ldap_emp_num' => 'employeeid',
            'link_by_employee_number' => 1,
            'company_source' => LdapConnection::COMPANY_SOURCE_CONNECTION,
            'company_id' => $linkerCompany->id,
        ]);
        $max = User::factory()->withoutCompany()->create(['username' => 'maxborukh', 'employee_num' => '9851']);
        $max->forceFill(['ldap_import' => 1, 'ldap_connection_id' => $owner->id])->saveQuietly();
        Ldap::withConnection($linker, fn () => Ldap::linkUserToCurrentConnection($max, []));
        $this->mockEntries([$this->directoryEntry('someone.else', '1234')]);

        $this->artisan('snipeit:ldap-sync', ['--connection' => $linker->id, '--delete' => true, '--json_summary' => true]);

        $this->assertNotSoftDeleted($max);
        $this->assertSame([], $max->companies()->pluck('companies.id')->all());
        $this->assertDatabaseMissing('ldap_connection_user', ['user_id' => $max->id]);
    }

    public function test_without_linking_the_other_directory_creates_its_own_account(): void
    {
        $owner = LdapConnection::factory()->create(['priority' => 0]);
        $other = LdapConnection::factory()->create(['priority' => 1, 'ldap_emp_num' => 'employeeid']);
        $max = User::factory()->create(['username' => 'maxborukh', 'employee_num' => '9851']);
        $max->forceFill(['ldap_import' => 1, 'ldap_connection_id' => $owner->id])->saveQuietly();
        $this->mockEntries([$this->directoryEntry('max.borukh', '9851')]);

        $this->artisan('snipeit:ldap-sync', ['--connection' => $other->id, '--json_summary' => true]);

        $this->assertSame($other->id, (int) User::where('username', 'max.borukh')->first()?->ldap_connection_id);
    }

    public function test_several_connections_each_sync_in_their_own_process(): void
    {
        $domainA = LdapConnection::factory()->create(['name' => 'Domain A', 'priority' => 0]);
        $domainB = LdapConnection::factory()->create(['name' => 'Domain B', 'priority' => 1]);
        LdapConnection::factory()->disabled()->create();

        Process::fake([
            '*--connection='.$domainA->id.'*' => Process::result(json_encode([
                'error' => false,
                'error_message' => '',
                'summary' => [['connection' => 'Domain A', 'username' => 'alice', 'status' => 'success', 'note' => 'created']],
            ])),
            '*--connection='.$domainB->id.'*' => Process::result(json_encode([
                'error' => true,
                'error_message' => '[Domain B] Could not bind to LDAP',
                'summary' => [],
            ])),
        ]);

        Artisan::call('snipeit:ldap-sync', ['--delete' => true, '--json_summary' => true]);
        $output = json_decode(Artisan::output(), true);

        $this->assertFalse($output['error']);
        $this->assertSame(['alice', ''], array_column($output['summary'], 'username'));
        $this->assertSame('error', $output['summary'][1]['status']);
        $this->assertStringContainsString('Could not bind to LDAP', $output['summary'][1]['note']);

        Process::assertRanTimes(fn (PendingProcess $process) => str_contains(implode(' ', (array) $process->command), 'snipeit:ldap-sync'), 2);
        Process::assertRan(fn (PendingProcess $process) => in_array('--connection='.$domainA->id, (array) $process->command, true)
            && in_array('--delete', (array) $process->command, true));
    }

    public function test_child_process_gets_the_parent_database_settings(): void
    {
        LdapConnection::factory()->create(['priority' => 0]);
        LdapConnection::factory()->create(['priority' => 1]);
        Process::fake(['*' => Process::result(json_encode(['error' => false, 'error_message' => '', 'summary' => []]))]);

        Artisan::call('snipeit:ldap-sync', ['--json_summary' => true]);

        Process::assertRan(fn (PendingProcess $process) => ($process->environment['DB_CONNECTION'] ?? null) === config('database.default')
            && ($process->environment['DB_DATABASE'] ?? null) === (string) config('database.connections.'.config('database.default').'.database')
            && ($process->environment['APP_KEY'] ?? null) === config('app.key'));
    }

    public function test_crashed_child_process_reports_its_output(): void
    {
        LdapConnection::factory()->create(['priority' => 0]);
        LdapConnection::factory()->create(['priority' => 1]);

        Process::fake([
            '*' => Process::result(output: 'PHP Fatal error:  Something broke in the child', exitCode: 1),
        ]);

        Artisan::call('snipeit:ldap-sync', ['--json_summary' => true]);
        $output = json_decode(Artisan::output(), true);

        $this->assertStringContainsString('exited with code 1', $output['summary'][0]['note']);
        $this->assertStringContainsString('Something broke in the child', $output['summary'][0]['note']);
    }

    public function test_explicitly_syncing_a_disabled_connection_reports_an_error(): void
    {
        $disabled = LdapConnection::factory()->disabled()->create();

        $this->artisan('snipeit:ldap-sync', ['--connection' => $disabled->id, '--json_summary' => true])
            ->expectsOutputToContain('"error":true');
    }
}

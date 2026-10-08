<?php

namespace Tests\Feature\Authentication;

use App\Models\LdapConnection;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Login for users owned by a disabled LDAP connection: LDAP is skipped for
 * them entirely (no other connection is tried), and they fall through to
 * local auth, which works with a password cached by "Cache LDAP Passwords".
 */
#[Group('ldap')]
class LdapConnectionLoginTest extends TestCase
{
    use PHPMock;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->settings->set(['ldap_enabled' => 1]);
    }

    private function userOwnedBy(LdapConnection $connection, string $password): User
    {
        $user = User::factory()->create(['username' => 'jsmith', 'password' => bcrypt($password), 'activated' => 1]);
        $user->forceFill(['ldap_import' => 1, 'ldap_connection_id' => $connection->id])->saveQuietly();

        return $user;
    }

    public function test_user_of_disabled_connection_logs_in_locally_with_cached_password(): void
    {
        $disabled = LdapConnection::factory()->disabled()->create();
        LdapConnection::factory()->create();
        $user = $this->userOwnedBy($disabled, 'cached-ad-password');

        $this->getFunctionMock('App\\Models', 'ldap_connect')->expects($this->never());

        $this->post('/login', ['username' => 'jsmith', 'password' => 'cached-ad-password']);

        $this->assertAuthenticatedAs($user);
        $this->assertSame($disabled->id, (int) $user->fresh()->ldap_connection_id);
    }

    public function test_user_of_disabled_connection_without_cached_password_cannot_log_in(): void
    {
        $disabled = LdapConnection::factory()->disabled()->create();
        $user = $this->userOwnedBy($disabled, 'unused');
        $user->forceFill(['password' => $user->noPassword()])->saveQuietly();

        $this->getFunctionMock('App\\Models', 'ldap_connect')->expects($this->never());

        $this->post('/login', ['username' => 'jsmith', 'password' => '']);
        $this->post('/login', ['username' => 'jsmith', 'password' => '*** NO PASSWORD ***']);

        $this->assertGuest();
    }
}

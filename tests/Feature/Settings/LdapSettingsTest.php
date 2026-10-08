<?php

namespace Tests\Feature\Settings;

use App\Models\LdapConnection;
use App\Models\User;
use Tests\TestCase;

class LdapSettingsTest extends TestCase
{
    public function test_requires_permission()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('settings.ldap.index'))
            ->assertForbidden();
    }

    public function test_page_renders()
    {
        LdapConnection::factory()->create(['name' => 'Head office AD']);

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('settings.ldap.index'))
            ->assertOk()
            ->assertSeeLivewire('ldap-connections')
            ->assertSee('Head office AD');
    }
}

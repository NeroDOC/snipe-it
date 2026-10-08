<?php

namespace Database\Factories;

use App\Models\LdapConnection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Crypt;

class LdapConnectionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<LdapConnection>
     */
    protected $model = LdapConnection::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'name' => $this->faker->unique()->domainWord().' AD',
            'enabled' => 1,
            'priority' => 0,
            'company_source' => LdapConnection::COMPANY_SOURCE_NONE,
            'is_ad' => 0,
            'ldap_server' => 'ldaps://ldap.example.com',
            'ldap_uname' => 'fake_username',
            'ldap_pword' => Crypt::encrypt('fake_password'),
            'ldap_basedn' => 'CN=Users,DC=ad,DC=example,Dc=com',
            'ldap_auth_filter_query' => 'uid=',
            'ldap_username_field' => 'samaccountname',
            'ldap_fname_field' => 'givenname',
            'ldap_lname_field' => 'sn',
            'ldap_email' => 'mail',
            'ldap_pw_sync' => 1,
        ];
    }

    public function disabled()
    {
        return $this->state(fn () => ['enabled' => 0]);
    }
}

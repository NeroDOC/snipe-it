<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-directory LDAP config columns, copied verbatim from the settings
     * row. Column names match settings so code written against a
     * Setting-shaped object (Ldap::attributeMap, shouldUseSaslExternal,
     * the wizard) works unchanged against an LdapConnection.
     */
    private const COPIED_COLUMNS = [
        'is_ad',
        'ad_domain',
        'ldap_server',
        'ldap_tls',
        'ldap_server_cert_ignore',
        'ldap_client_tls_key',
        'ldap_client_tls_cert',
        'ldap_version',
        'ldap_uname',
        'ldap_pword',
        'ldap_basedn',
        'ldap_filter',
        'ldap_auth_filter_query',
        'ldap_username_field',
        'ldap_fname_field',
        'ldap_lname_field',
        'ldap_display_name',
        'ldap_email',
        'ldap_emp_num',
        'ldap_phone_field',
        'ldap_mobile',
        'ldap_jobtitle',
        'ldap_manager',
        'ldap_dept',
        'ldap_address',
        'ldap_city',
        'ldap_state',
        'ldap_zip',
        'ldap_country',
        'ldap_location',
        'ldap_company',
        'ldap_website',
        'ldap_active_flag',
        'ldap_invert_active_flag',
        'ldap_pw_sync',
        'ldap_default_group',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ldap_connections', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->boolean('enabled')->default(0);
            $table->integer('priority')->default(0);
            $table->string('company_source', 20)->default('none');
            $table->integer('company_id')->nullable()->default(null)->index();

            $table->boolean('is_ad')->default(0);
            $table->string('ad_domain')->nullable()->default(null);
            $table->string('ldap_server')->nullable()->default(null);
            $table->boolean('ldap_tls')->default(0);
            $table->boolean('ldap_server_cert_ignore')->default(0);
            $table->text('ldap_client_tls_key')->nullable()->default(null);
            $table->text('ldap_client_tls_cert')->nullable()->default(null);
            $table->integer('ldap_version')->nullable()->default(3);

            $table->string('ldap_uname')->nullable()->default(null);
            $table->longText('ldap_pword')->nullable()->default(null);
            $table->string('ldap_basedn')->nullable()->default(null);
            $table->text('ldap_filter')->nullable()->default(null);
            $table->string('ldap_auth_filter_query')->nullable()->default(null);

            $table->string('ldap_username_field')->nullable()->default(null);
            $table->string('ldap_fname_field')->nullable()->default(null);
            $table->string('ldap_lname_field')->nullable()->default(null);
            $table->string('ldap_display_name', 191)->nullable()->default(null);
            $table->string('ldap_email')->nullable()->default(null);
            $table->string('ldap_emp_num')->nullable()->default(null);
            $table->string('ldap_phone_field')->nullable()->default(null);
            $table->string('ldap_mobile', 191)->nullable()->default(null);
            $table->string('ldap_jobtitle')->nullable()->default(null);
            $table->string('ldap_manager')->nullable()->default(null);
            $table->string('ldap_dept')->nullable()->default(null);
            $table->string('ldap_address', 191)->nullable()->default(null);
            $table->string('ldap_city', 191)->nullable()->default(null);
            $table->string('ldap_state', 191)->nullable()->default(null);
            $table->string('ldap_zip', 191)->nullable()->default(null);
            $table->string('ldap_country')->nullable()->default(null);
            $table->string('ldap_location')->nullable()->default(null);
            $table->string('ldap_company')->nullable()->default(null);
            $table->string('ldap_website')->nullable()->default(null);
            $table->string('ldap_active_flag')->nullable()->default(null);
            $table->boolean('ldap_invert_active_flag')->default(0);

            $table->boolean('ldap_pw_sync')->default(1);
            $table->integer('ldap_default_group')->nullable()->default(null);

            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->integer('ldap_connection_id')->after('ldap_import')->nullable()->default(null)->index();
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->integer('ldap_connection_id')->after('ldap_ou')->nullable()->default(null);
        });

        $this->copyExistingSettingsIntoFirstConnection();
    }

    /**
     * Carry an existing single-LDAP install over as connection #1 so
     * nothing changes for it, and mark its imported users as owned by
     * that connection.
     */
    private function copyExistingSettingsIntoFirstConnection(): void
    {
        $settings = DB::table('settings')->first();
        if (! $settings || trim((string) ($settings->ldap_server ?? '')) === '') {
            return;
        }

        $row = [
            'name' => 'Default',
            'enabled' => (int) ($settings->ldap_enabled ?? 0) === 1 ? 1 : 0,
            'priority' => 0,
            'company_source' => trim((string) ($settings->ldap_company ?? '')) !== '' ? 'attribute' : 'none',
            'created_at' => now(),
            'updated_at' => now(),
        ];
        foreach (self::COPIED_COLUMNS as $column) {
            if (property_exists($settings, $column)) {
                $row[$column] = $settings->{$column};
            }
        }
        // settings.ldap_default_group is a string column; the connection
        // stores a plain group id.
        $row['ldap_default_group'] = is_numeric($row['ldap_default_group'] ?? null) ? (int) $row['ldap_default_group'] : null;

        $connectionId = DB::table('ldap_connections')->insertGetId($row);

        DB::table('users')->where('ldap_import', 1)->update(['ldap_connection_id' => $connectionId]);
        DB::table('locations')->whereNotNull('ldap_ou')->where('ldap_ou', '!=', '')->update(['ldap_connection_id' => $connectionId]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('ldap_connection_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['ldap_connection_id']);
            $table->dropColumn('ldap_connection_id');
        });

        Schema::dropIfExists('ldap_connections');
    }
};

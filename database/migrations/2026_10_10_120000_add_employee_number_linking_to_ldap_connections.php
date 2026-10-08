<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ldap_connections', function (Blueprint $table) {
            $table->boolean('link_by_employee_number')->after('company_id')->default(0);
        });

        // Users that a connection matched by employee number but doesn't
        // own (users.ldap_connection_id points at their owner). Records the
        // company that connection added, so it can be swapped or removed.
        Schema::create('ldap_connection_user', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('ldap_connection_id')->index();
            $table->integer('user_id')->index();
            $table->integer('company_id')->nullable()->default(null);
            $table->timestamps();
            $table->unique(['ldap_connection_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ldap_connection_user');

        Schema::table('ldap_connections', function (Blueprint $table) {
            $table->dropColumn('link_by_employee_number');
        });
    }
};

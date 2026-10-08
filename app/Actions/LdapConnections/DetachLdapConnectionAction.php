<?php

namespace App\Actions\LdapConnections;

use App\Enums\ActionType;
use App\Models\Actionlog;
use App\Models\LdapConnection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DetachLdapConnectionAction
{
    /**
     * Turn every user owned by a disabled LDAP connection into a local
     * account, release the connection's location OUs, and delete the
     * connection.
     *
     * Users keep their companies, groups, assets, manager and activation
     * state. With "Cache LDAP Passwords" on, the cached AD password keeps
     * working as the local password; otherwise the account is left with no
     * usable password until an admin sets one or the user resets it.
     * "Remember me" sessions are ended either way.
     *
     * @return array{users: int, locations: int}
     *
     * @throws InvalidArgumentException when the connection is still enabled
     */
    public static function run(LdapConnection $connection): array
    {
        if ($connection->enabled) {
            throw new InvalidArgumentException(trans('admin/settings/general.ldap_connections.detach_requires_disabled'));
        }

        return DB::transaction(function () use ($connection) {
            $userIds = User::withTrashed()
                ->where('ldap_connection_id', $connection->id)
                ->pluck('id');

            $userChanges = [
                'ldap_import' => 0,
                'ldap_connection_id' => null,
                'ldap_company_id' => null,
                'remember_token' => null,
            ];
            if (! $connection->ldap_pw_sync) {
                $userChanges['password'] = (new User)->noPassword();
            }

            // Query builder, not model saves, so detaching thousands of
            // users doesn't run the user observer for each one. Each user
            // still gets its own change-log entry below.
            DB::table('users')->whereIn('id', $userIds)->update($userChanges);

            foreach ($userIds as $userId) {
                $logAction = (new Actionlog)->forceFill([
                    'item_type' => User::class,
                    'item_id' => $userId,
                    'target_type' => User::class,
                    'target_id' => $userId,
                    'created_by' => auth()->id(),
                    'note' => trans('admin/settings/general.ldap_connections.detached_log_note', ['name' => $connection->name]),
                    'log_meta' => json_encode([
                        'ldap_import' => ['old' => 1, 'new' => 0],
                        'ldap_connection_id' => ['old' => $connection->id, 'new' => null],
                    ]),
                ]);
                $logAction->logaction(ActionType::Update);
            }

            // Clear the OU too: an orphaned OU would otherwise be picked up
            // by the default connection.
            $locationCount = DB::table('locations')
                ->where('ldap_connection_id', $connection->id)
                ->update(['ldap_connection_id' => null, 'ldap_ou' => null]);

            // Users this connection only linked by employee number stay with
            // their owner; drop the links but keep the companies they added.
            DB::table('ldap_connection_user')->where('ldap_connection_id', $connection->id)->delete();

            $connection->deleteClientTlsFiles();
            $connection->delete();

            return ['users' => $userIds->count(), 'locations' => $locationCount];
        });
    }
}

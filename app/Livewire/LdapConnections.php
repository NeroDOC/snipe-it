<?php

namespace App\Livewire;

use App\Actions\LdapConnections\DetachLdapConnectionAction;
use App\Models\LdapConnection;
use App\Models\Setting;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Settings > LDAP: the list of LDAP connections plus the global LDAP
 * switch. Each connection is created and edited through the LdapSettings
 * wizard (?connection=<id>).
 */
class LdapConnections extends Component
{
    public bool $ldapEnabled = false;

    // Result of the last action, rendered as an alert above the list.
    public ?string $statusType = null;

    public ?string $statusMessage = null;

    /**
     * mount() only runs on the first render; boot() also guards replayed
     * /livewire/update requests that bypass the route middleware.
     */
    public function boot(): void
    {
        abort_unless(Gate::allows('superadmin'), 403);
    }

    public function mount(): void
    {
        $this->ldapEnabled = Setting::getSettings()->ldap_enabled == '1';
    }

    #[Computed]
    public function connections()
    {
        return LdapConnection::query()
            ->with(['company' => fn ($query) => $query->withoutGlobalScopes()])
            ->withCount('users')
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    public function toggleGlobal(): void
    {
        if (config('app.lock_passwords')) {
            return;
        }

        $setting = Setting::getSettings();
        $setting->ldap_enabled = $setting->ldap_enabled == '1' ? '0' : '1';
        $setting->save();
        $this->ldapEnabled = $setting->ldap_enabled == '1';
    }

    public function toggleEnabled(int $connectionId): void
    {
        if (config('app.lock_passwords')) {
            return;
        }

        $connection = LdapConnection::findOrFail($connectionId);
        $connection->enabled = ! $connection->enabled;
        $connection->save();
        unset($this->connections);
    }

    /**
     * Delete a connection nobody belongs to. Connections that still own
     * users go through detach() instead.
     */
    public function deleteConnection(int $connectionId): void
    {
        if (config('app.lock_passwords')) {
            return;
        }

        $connection = LdapConnection::withCount('users')->findOrFail($connectionId);
        if ($connection->users_count > 0) {
            $this->setStatus('danger', trans('admin/settings/general.ldap_connections.delete_has_users'));

            return;
        }

        $connection->enabled = false;
        DetachLdapConnectionAction::run($connection);
        $this->setStatus('success', trans('admin/settings/general.ldap_connections.deleted'));
        unset($this->connections);
    }

    /**
     * "Detach users and delete connection" for a disabled connection.
     */
    public function detach(int $connectionId): void
    {
        if (config('app.lock_passwords')) {
            return;
        }

        $connection = LdapConnection::findOrFail($connectionId);
        if ($connection->enabled) {
            $this->setStatus('danger', trans('admin/settings/general.ldap_connections.detach_requires_disabled'));

            return;
        }

        $result = DetachLdapConnectionAction::run($connection);
        $this->setStatus('success', trans('admin/settings/general.ldap_connections.detached', [
            'name' => $connection->name,
            'users' => $result['users'],
        ]));
        unset($this->connections);
    }

    protected function setStatus(string $type, string $message): void
    {
        $this->statusType = $type;
        $this->statusMessage = $message;
    }

    public function render()
    {
        return view('livewire.ldap-connections');
    }
}

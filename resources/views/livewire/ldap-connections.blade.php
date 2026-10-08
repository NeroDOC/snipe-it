<div>
    @if ($statusMessage)
        <x-alert :type="$statusType" role="status">{{ $statusMessage }}</x-alert>
    @endif

    <div class="box box-default">
        <div class="box-header with-border">
            <h2 class="box-title">
                <x-icon type="ldap"/>
                {{ trans('admin/settings/general.ldap_connections.title') }}
            </h2>
            <div class="box-tools pull-right">
                <a href="{{ route('settings.ldap.wizard') }}" class="btn btn-theme btn-sm">
                    <x-icon type="plus"/>
                    {{ trans('admin/settings/general.ldap_connections.add') }}
                </a>
            </div>
        </div>

        <div class="box-body">
            {{-- Global switch: LoginController and ldap-sync check this
                 before looking at any connection. --}}
            <p>
                <strong>{{ trans('admin/settings/general.ldap_enabled') }}:</strong>
                @if ($ldapEnabled)
                    <span class="label label-success"><x-icon type="checkmark"/> {{ trans('general.yes') }}</span>
                @else
                    <span class="label label-default"><x-icon type="x"/> {{ trans('general.no') }}</span>
                @endif
                <button
                    type="button"
                    wire:click="toggleGlobal"
                    class="btn btn-default btn-xs"
                    @disabled(config('app.lock_passwords'))
                >
                    {{ $ldapEnabled ? trans('admin/settings/general.ldap_connections.disable_all') : trans('admin/settings/general.ldap_connections.enable_all') }}
                </button>
            </p>
            <p class="help-block">{{ trans('admin/settings/general.ldap_connections.help') }}</p>

            @if ($this->connections->isEmpty())
                <p>{{ trans('admin/settings/general.ldap_connections.empty') }}</p>
            @else
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th scope="col">{{ trans('admin/settings/general.ldap_connections.priority') }}</th>
                                <th scope="col">{{ trans('general.name') }}</th>
                                <th scope="col">{{ trans('admin/settings/general.ldap_server') }}</th>
                                <th scope="col">{{ trans('admin/settings/general.ldap_connections.company_source') }}</th>
                                <th scope="col">{{ trans('general.users') }}</th>
                                <th scope="col">{{ trans('general.status') }}</th>
                                <th scope="col"><span class="sr-only">{{ trans('table.actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->connections as $connection)
                                <tr wire:key="ldap-connection-{{ $connection->id }}">
                                    <td>{{ $connection->priority }}</td>
                                    <td>{{ $connection->name }}</td>
                                    <td><code>{{ $connection->ldap_server }}</code></td>
                                    <td>
                                        {{ trans('admin/settings/general.ldap_connections.company_source_'.$connection->company_source) }}
                                        @if ($connection->company_source === \App\Models\LdapConnection::COMPANY_SOURCE_CONNECTION && $connection->company)
                                            : {{ $connection->company->name }}
                                        @elseif ($connection->company_source === \App\Models\LdapConnection::COMPANY_SOURCE_ATTRIBUTE)
                                            : <code>{{ $connection->ldap_company }}</code>
                                        @endif
                                    </td>
                                    <td>{{ $connection->users_count }}</td>
                                    <td>
                                        @if ($connection->enabled)
                                            <span class="label label-success"><x-icon type="checkmark"/> {{ trans('admin/settings/general.ldap_connections.enabled') }}</span>
                                        @else
                                            <span class="label label-default"><x-icon type="x"/> {{ trans('admin/settings/general.ldap_connections.disabled') }}</span>
                                        @endif
                                    </td>
                                    <td class="text-right" style="white-space: nowrap;">
                                        <a href="{{ route('settings.ldap.wizard', ['connection' => $connection->id]) }}" class="btn btn-sm btn-warning">
                                            <x-icon type="edit"/>
                                            <span class="sr-only">{{ trans('button.edit') }}</span>
                                        </a>
                                        <button
                                            type="button"
                                            wire:click="toggleEnabled({{ $connection->id }})"
                                            class="btn btn-sm btn-default"
                                            @disabled(config('app.lock_passwords'))
                                        >
                                            {{ $connection->enabled ? trans('admin/settings/general.ldap_connections.disable') : trans('admin/settings/general.ldap_connections.enable') }}
                                        </button>
                                        @if ($connection->users_count === 0)
                                            <button
                                                type="button"
                                                wire:click="deleteConnection({{ $connection->id }})"
                                                wire:confirm="{{ trans('admin/settings/general.ldap_connections.confirm_delete', ['name' => $connection->name]) }}"
                                                class="btn btn-sm btn-danger"
                                                @disabled(config('app.lock_passwords'))
                                            >
                                                <x-icon type="delete"/>
                                                <span class="sr-only">{{ trans('button.delete') }}</span>
                                            </button>
                                        @elseif (! $connection->enabled)
                                            <button
                                                type="button"
                                                wire:click="detach({{ $connection->id }})"
                                                wire:confirm="{{ trans('admin/settings/general.ldap_connections.confirm_detach', ['name' => $connection->name, 'users' => $connection->users_count]) }}"
                                                class="btn btn-sm btn-danger"
                                                @disabled(config('app.lock_passwords'))
                                            >
                                                {{ trans('admin/settings/general.ldap_connections.detach') }}
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>

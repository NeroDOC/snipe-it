<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Watson\Validating\ValidatingTrait;

/**
 * One configured LDAP / Active Directory directory.
 *
 * Column names deliberately mirror the legacy single-LDAP columns on the
 * settings row (ldap_server, ldap_uname, ldap_username_field, ...) so
 * code written against a Setting-shaped object, e.g. Ldap::attributeMap()
 * and Ldap::shouldUseSaslExternal(), accepts a connection unchanged.
 *
 * Users synced or logged in through a connection are stamped with
 * users.ldap_connection_id; locations.ldap_connection_id scopes a
 * location's LDAP OU to one connection.
 */
class LdapConnection extends Model
{
    use HasFactory;
    use ValidatingTrait;

    public const COMPANY_SOURCE_NONE = 'none';

    public const COMPANY_SOURCE_CONNECTION = 'connection';

    public const COMPANY_SOURCE_ATTRIBUTE = 'attribute';

    public const COMPANY_SOURCES = [
        self::COMPANY_SOURCE_NONE,
        self::COMPANY_SOURCE_CONNECTION,
        self::COMPANY_SOURCE_ATTRIBUTE,
    ];

    protected $attributes = [
        'enabled' => 0,
        'priority' => 0,
        'link_by_employee_number' => 0,
        'company_source' => self::COMPANY_SOURCE_NONE,
        'ldap_version' => 3,
        'ldap_pw_sync' => 1,
    ];

    protected $fillable = [
        'name',
        'enabled',
        'priority',
        'company_source',
        'company_id',
    ];

    protected $hidden = [
        'ldap_pword',
        'ldap_client_tls_key',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'priority' => 'integer',
        'company_id' => 'integer',
        'link_by_employee_number' => 'boolean',
        'is_ad' => 'boolean',
        'ldap_tls' => 'boolean',
        'ldap_server_cert_ignore' => 'boolean',
        'ldap_invert_active_flag' => 'boolean',
        'ldap_pw_sync' => 'boolean',
        'ldap_version' => 'integer',
        'ldap_default_group' => 'integer',
    ];

    protected $rules = [
        'name' => 'required|string|max:191',
        'priority' => 'integer',
        'company_source' => 'required|in:none,connection,attribute',
        'company_id' => 'nullable|integer|required_if:company_source,connection|exists:companies,id',
        'ldap_company' => 'nullable|string|max:191|required_if:company_source,attribute',
        'ldap_server' => 'nullable|starts_with:ldap://,ldaps://',
        'ldap_default_group' => 'nullable|integer|exists:permission_groups,id',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'ldap_connection_id');
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class, 'ldap_connection_id');
    }

    /**
     * Enabled connections in login / sync order.
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', 1)->orderBy('priority')->orderBy('id');
    }

    /**
     * The connection that owns legacy rows with no explicit connection
     * (locations whose ldap_connection_id is null): the first by
     * priority, enabled or not.
     */
    public static function defaultConnection(): ?self
    {
        return self::query()->orderBy('priority')->orderBy('id')->first();
    }

    /**
     * Path to this connection's client-side TLS certificate file,
     * refreshed from the DB value when stale.
     */
    public function clientCertPath(): string
    {
        return $this->freshFilePath('ldap_client_tls_cert', 'ldap_client_tls_'.$this->id.'.cert');
    }

    /**
     * Path to this connection's client-side TLS key file, refreshed from
     * the DB value when stale.
     */
    public function clientKeyPath(): string
    {
        return $this->freshFilePath('ldap_client_tls_key', 'ldap_client_tls_'.$this->id.'.key');
    }

    /**
     * Remove this connection's cached client cert / key files.
     */
    public function deleteClientTlsFiles(): void
    {
        foreach (['cert', 'key'] as $extension) {
            $path = storage_path('ldap_client_tls_'.$this->id.'.'.$extension);
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    /**
     * Per-connection counterpart of Setting::get_fresh_file_path(): the
     * TLS client cert / key have to live on disk for ldap_set_option(),
     * and each connection needs its own file so two directories with
     * different certs don't overwrite each other.
     */
    protected function freshFilePath(string $attribute, string $filename): string
    {
        $fullPath = storage_path($filename);
        $fileExists = file_exists($fullPath);

        if (! $fileExists || Carbon::createFromTimestamp(filemtime($fullPath)) < $this->updated_at) {
            if ($this->{$attribute}) {
                file_put_contents($fullPath, $this->{$attribute});
            } elseif ($fileExists) {
                unlink($fullPath);
            }
        }

        return $fullPath;
    }
}

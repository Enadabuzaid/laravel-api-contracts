<?php

namespace Enadstack\ApiContracts\Tenancy;

use Illuminate\Database\Eloquent\Model;

/**
 * Scopes a model by `tenant_reference` + `application` columns (SERVICE_STANDARD §4)
 * and fills them on create. Fails closed without a TenantContext.
 *
 * @mixin Model
 */
trait HasTenantReference
{
    public static function bootHasTenantReference(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model) {
            $ctx = app(TenantContext::class);
            if (! $ctx->isSet()) {
                throw new MissingTenantContextException('Tenant context is required to create '.$model::class.'.');
            }

            foreach (['tenant_reference' => $ctx->reference(), 'application' => $ctx->application()] as $column => $value) {
                $current = $model->getAttribute($column);
                if ($current !== null && $current !== $value) {
                    throw new MissingTenantContextException("Refusing to write {$column} '{$current}' outside tenant context '{$value}'.");
                }
                $model->setAttribute($column, $value);
            }
        });
    }
}

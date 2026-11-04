<?php

namespace Enadstack\ApiContracts\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Fails closed: with no tenant context, any query throws instead of
 * returning rows. Opt out explicitly with withoutGlobalScope(TenantScope::class).
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $ctx = app(TenantContext::class);
        if (! $ctx->isSet()) {
            throw new MissingTenantContextException('Tenant context is required to query '.$model::class.'.');
        }

        $builder->where($model->qualifyColumn('tenant_reference'), $ctx->reference())
            ->where($model->qualifyColumn('application'), $ctx->application());
    }
}

<?php

namespace Enadstack\ApiContracts\Contracts;

/**
 * Implemented by domain exceptions so ApiExceptionRenderer can normalize
 * them into the standard error envelope without each service having to
 * catch them one by one in every controller.
 */
interface HasErrorCode
{
    public function errorCode(): string;

    public function errorStatus(): int;

    /**
     * @return array<string, mixed>
     */
    public function errorDetails(): array;
}

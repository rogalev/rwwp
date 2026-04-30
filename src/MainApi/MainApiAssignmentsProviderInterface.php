<?php

declare(strict_types=1);

namespace App\MainApi;

interface MainApiAssignmentsProviderInterface
{
    /**
     * @return list<ParserAssignment>
     */
    public function list(): array;
}

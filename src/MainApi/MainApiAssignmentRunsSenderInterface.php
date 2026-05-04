<?php

declare(strict_types=1);

namespace App\MainApi;

interface MainApiAssignmentRunsSenderInterface
{
    /**
     * @param list<AssignmentRunStats> $items
     */
    public function send(\DateTimeImmutable $checkedAt, array $items): void;
}

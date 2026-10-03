<?php

namespace App\Policies;

use App\Models\Import;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Imports are private to their owner. Denials are reported as 404 so the
 * ids of other users' imports cannot be probed.
 */
class ImportPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, Import $import): Response
    {
        return $this->owns($user, $import);
    }

    public function downloadErrors(User $user, Import $import): Response
    {
        return $this->owns($user, $import);
    }

    public function cancel(User $user, Import $import): Response
    {
        return $this->owns($user, $import);
    }

    public function retry(User $user, Import $import): Response
    {
        return $this->owns($user, $import);
    }

    private function owns(User $user, Import $import): Response
    {
        return $import->user_id === $user->id ? Response::allow() : Response::denyAsNotFound();
    }
}

<?php

namespace App\Policies;

use App\Models\Portfolio;
use App\Models\User;

class PortfolioPolicy
{
    /**
     * Authenticated users can list their own portfolios. The controller is
     * still responsible for filtering by user_id.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Authenticated users can create new portfolios.
     */
    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, Portfolio $portfolio): bool
    {
        return $this->userOwnsOrLegacy($user, $portfolio);
    }

    public function update(User $user, Portfolio $portfolio): bool
    {
        return $this->userOwnsOrLegacy($user, $portfolio);
    }

    public function delete(User $user, Portfolio $portfolio): bool
    {
        return $this->userOwnsOrLegacy($user, $portfolio);
    }

    /**
     * Backwards-compatible ownership check. Portfolios created before this
     * PR may have user_id = null; until they're claimed, any authenticated
     * user can read/update them so existing single-user installs keep
     * working.
     */
    private function userOwnsOrLegacy(User $user, Portfolio $portfolio): bool
    {
        if ($portfolio->user_id === null) {
            return true;
        }

        return (int) $portfolio->user_id === (int) $user->getAuthIdentifier();
    }
}

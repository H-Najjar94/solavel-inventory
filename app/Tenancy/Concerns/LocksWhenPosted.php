<?php

namespace App\Tenancy\Concerns;

use RuntimeException;

/**
 * Posted-document immutability. A document using this trait may be freely edited
 * while in 'draft', but once its status is 'posted' or 'reversed' it cannot be
 * updated (except the controlled transitions the posting/reversal services make)
 * or deleted. Corrections are made via reversal documents.
 *
 * The posting/reversal services mark a model as performing an allowed transition
 * via markSystemTransition() so their own status writes are permitted.
 */
trait LocksWhenPosted
{
    protected bool $allowSystemTransition = false;

    public static function bootLocksWhenPosted(): void
    {
        static::updating(function ($model) {
            if ($model->allowSystemTransition) {
                return; // controlled transition by a posting/reversal service
            }

            $original = $model->getOriginal('status');
            if (in_array($original, ['posted', 'reversed'], true)) {
                throw new RuntimeException(
                    static::class." is locked: a {$original} document cannot be edited. "
                    .'Create a reversal/correction document instead.'
                );
            }
        });

        static::deleting(function ($model) {
            $status = $model->getOriginal('status') ?? $model->status;
            if (in_array($status, ['posted', 'reversed'], true)) {
                throw new RuntimeException(
                    static::class." is locked: a {$status} document cannot be deleted."
                );
            }
        });
    }

    /** Allow the next save() to perform a controlled status transition. */
    public function markSystemTransition(): static
    {
        $this->allowSystemTransition = true;

        return $this;
    }

    public function clearSystemTransition(): static
    {
        $this->allowSystemTransition = false;

        return $this;
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function isReversed(): bool
    {
        return $this->status === 'reversed';
    }
}

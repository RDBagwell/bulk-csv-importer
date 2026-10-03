<?php

namespace App\Enums;

/**
 * Lifecycle of an import. The transition table below is the single source
 * of truth; Import::transitionTo() is the only code that changes status.
 *
 *   pending → validating → processing → completed | completed_with_errors | failed
 *   pending | validating | processing → cancelled
 *   failed → processing   (retrying failed chunks)
 */
enum ImportStatus: string
{
    case Pending = 'pending';
    case Validating = 'validating';
    case Processing = 'processing';
    case Completed = 'completed';
    case CompletedWithErrors = 'completed_with_errors';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Validating, self::Failed, self::Cancelled],
            self::Validating => [self::Processing, self::Failed, self::Cancelled],
            self::Processing => [self::Completed, self::CompletedWithErrors, self::Failed, self::Cancelled],
            self::Failed => [self::Processing],
            self::Completed, self::CompletedWithErrors, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /**
     * States from which $to may be entered.
     *
     * @return list<self>
     */
    public static function sourcesOf(self $to): array
    {
        return array_values(array_filter(self::cases(), fn (self $from) => $from->canTransitionTo($to)));
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Pending, self::Validating, self::Processing], true);
    }

    public function isFinished(): bool
    {
        return ! $this->isActive();
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Validating => 'Validating',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::CompletedWithErrors => 'Completed with errors',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }
}

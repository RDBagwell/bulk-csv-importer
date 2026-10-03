<?php

use App\Enums\ImportStatus;

it('allows exactly the documented transitions', function () {
    $allowed = [];

    foreach (ImportStatus::cases() as $from) {
        foreach ($from->allowedTransitions() as $to) {
            $allowed[] = "{$from->value}->{$to->value}";
        }
    }

    expect($allowed)->toBe([
        'pending->validating', 'pending->failed', 'pending->cancelled',
        'validating->processing', 'validating->failed', 'validating->cancelled',
        'processing->completed', 'processing->completed_with_errors', 'processing->failed', 'processing->cancelled',
        'failed->processing',
    ]);
});

it('treats completed and cancelled imports as final', function (ImportStatus $status) {
    expect($status->allowedTransitions())->toBe([])
        ->and($status->isFinished())->toBeTrue();
})->with([ImportStatus::Completed, ImportStatus::CompletedWithErrors, ImportStatus::Cancelled]);

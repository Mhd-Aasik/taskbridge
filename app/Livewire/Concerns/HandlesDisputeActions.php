<?php

namespace App\Livewire\Concerns;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

trait HandlesDisputeActions
{
    protected function performDisputeAction(callable $action, string $field): mixed
    {
        try {
            return $action();
        } catch (ValidationException|AuthorizationException|ModelNotFoundException|HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->addError($field, 'We could not save this request. Your entered text has been kept. Please try again.');

            return null;
        }
    }
}

<?php

namespace App\Livewire\Concerns;

use App\Actions\Notifications\ManageNotifications;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

trait HandlesNotifications
{
    #[Locked]
    public string $readSetToken = '';

    #[Locked]
    public int $capturedCount = 0;

    public bool $confirmingAll = false;

    public function setNotificationRead(string $id, bool $read): void
    {
        $this->resetValidation();
        if ($this->notificationAction(fn () => app(ManageNotifications::class)->setRead($id, $read))) {
            session()->flash('notification-saved', $read ? 'Notification marked read.' : 'Notification marked unread.');
            $this->dispatch('notification-state-changed');
        }
    }

    public function openNotification(string $id): void
    {
        $this->resetValidation();
        $result = $this->notificationAction(fn () => app(ManageNotifications::class)->open($id));
        if (! $result) {
            return;
        }
        if (! $result['available']) {
            session()->flash('notification-unavailable', 'This destination is no longer available to your account. Your inbox remains private.');
        }
        $this->redirect($result['url'], navigate: true);
    }

    public function prepareMarkAll(): void
    {
        $this->resetValidation();
        $set = $this->notificationAction(fn () => app(ManageNotifications::class)->captureUnread());
        if (! $set) {
            return;
        }
        $this->readSetToken = $set['token'];
        $this->capturedCount = $set['count'];
        $this->confirmingAll = true;
    }

    public function confirmMarkAll(): void
    {
        $this->resetValidation();
        $count = $this->notificationAction(fn () => app(ManageNotifications::class)->markCapturedRead($this->readSetToken));
        if ($count === null) {
            return;
        }
        $this->confirmingAll = false;
        session()->flash('notification-saved', $count.' notifications marked read. Later arrivals remain unread.');
        $this->dispatch('notification-state-changed');
    }

    protected function notificationAction(callable $action): mixed
    {
        try {
            return $action();
        } catch (ValidationException|AuthorizationException|ModelNotFoundException|HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('notifications', 'We could not confirm this notification action. Refresh the inbox or retry.');

            return null;
        }
    }
}

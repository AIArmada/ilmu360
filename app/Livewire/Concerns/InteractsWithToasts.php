<?php

namespace App\Livewire\Concerns;

trait InteractsWithToasts
{
    protected function toast(string $message, string $type = 'success', ?string $title = null, int $timeout = 4200): void
    {
        $this->dispatch('app-toast', message: $message, type: $type, title: $title, timeout: $timeout);
    }

    protected function successToast(string $message, ?string $title = null, int $timeout = 4200): void
    {
        $this->toast($message, 'success', $title, $timeout);
    }

    protected function errorToast(string $message, ?string $title = null, int $timeout = 5200): void
    {
        $this->toast($message, 'error', $title, $timeout);
    }
}

<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the people responsible in an organization that a recall reached it. */
class RecallNoticeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $organizationName,
        public readonly string $recallNumber,
        public readonly string $title,
        public readonly string $classification,
        public readonly string $productName,
        public readonly array $batchNumbers,
        public readonly string $heldAtNotice,
        public readonly string $instructions,
        public readonly bool $cancelled = false,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->cancelled) {
            return (new MailMessage)->subject("Recall {$this->recallNumber} cancelled")
                ->line("Recall {$this->recallNumber} ({$this->title}) has been cancelled.")
                ->line('Stock quarantined because of it stays quarantined until someone in your organization releases it.');
        }

        return (new MailMessage)
            ->subject("RECALL {$this->recallNumber}: {$this->productName}")
            ->greeting("Recall notice for {$this->organizationName}")
            ->line("{$this->title} (".str_replace('_', ' ', strtoupper($this->classification)).').')
            ->line("Product: {$this->productName}. Batches: ".implode(', ', $this->batchNumbers).'.')
            ->line("Your stock of these batches when notified: {$this->heldAtNotice} base units. Available stock has been moved to quarantine and can no longer be sold.")
            ->line("Instructions: {$this->instructions}")
            ->line('Acknowledge the notice in the platform and record what you return or destroy through your normal stock documents.');
    }
}

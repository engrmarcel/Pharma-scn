<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Alerts the shipper that a cold-chain shipment left its required temperature range. */
class TemperatureExcursionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $shipmentNumber,
        public readonly string $requiredMin,
        public readonly string $requiredMax,
        public readonly string $lowest,
        public readonly string $highest,
        public readonly string $startedAt,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Temperature excursion: shipment {$this->shipmentNumber}")
            ->line("Shipment {$this->shipmentNumber} must stay between {$this->requiredMin} °C and {$this->requiredMax} °C.")
            ->line("Readings from {$this->startedAt} were {$this->lowest} °C to {$this->highest} °C.")
            ->line('Assess the excursion before the goods are used: product usable, quarantine or destroy.');
    }
}

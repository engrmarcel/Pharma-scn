<?php

namespace App\Notifications;

use App\Domain\Platform\Services\BrandingService;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to platform operations staff when an operational alert opens, escalates or resolves.
 * Deliberately not queued: when the queue itself is the problem, the e-mail must still go out.
 */
class OperationalAlertNotification extends Notification
{
    /** @param  array<string, mixed>  $alert  an operational_alerts row */
    public function __construct(public readonly string $event, public readonly array $alert) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $brand = app(BrandingService::class)->platform();
        $state = ['opened' => strtoupper($this->alert['severity']), 'escalated' => 'CRITICAL', 'resolved' => 'RESOLVED'][$this->event] ?? strtoupper($this->event);

        $mail = (new MailMessage)
            ->from($brand['email_from_address'], $brand['email_from_name'])
            ->subject("[{$brand['display_name']} {$state}] {$this->alert['message']}")
            ->line($this->alert['message'])
            ->line('Check: '.str_replace('_', ' ', $this->alert['check']).($this->alert['subject'] !== '' ? " ({$this->alert['subject']})" : '').'. Opened '.$this->alert['opened_at'].'.');
        if ($this->event === 'resolved') {
            $mail->line('Resolved '.$this->alert['resolved_at'].'.');
        }

        return $mail->action('Open Operations', route('admin.operations'));
    }
}

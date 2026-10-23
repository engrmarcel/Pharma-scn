<?php

namespace App\Notifications;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Platform\Services\BrandingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrganizationInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Organization $organization, public readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $brand = app(BrandingService::class)->platform();

        return (new MailMessage)
            ->from($brand['email_from_address'], $brand['email_from_name'])
            ->subject("You've been invited to join {$this->organization->name} on {$brand['display_name']}")
            ->line("{$this->organization->name} has invited you to join their workspace on {$brand['display_name']}.")
            ->line('Sign in or create an account with this email address, then accept the invitation using this code:')
            ->line($this->token)
            ->action('Accept the invitation', route('invitations.accept', ['token' => $this->token]))
            ->line('The invitation expires in 7 days. If you were not expecting it, you can ignore this email.');
    }
}

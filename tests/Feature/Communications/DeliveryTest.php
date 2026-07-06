<?php

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;

it('records a communication entry when a notification is sent with auto-capture', function () {
    Config::set('communications.features.auto_capture', true);
    $user = User::factory()->create();
    $notification = new class extends Notification
    {
        public function via(object $notifiable): array
        {
            return ['mail'];
        }

        public function toMail(object $notifiable): MailMessage
        {
            return (new MailMessage)
                ->subject('Comms Test Subject')
                ->line('Test body.');
        }
    };

    NotificationFacade::route('mail', 'comms-test@example.com')
        ->notify($notification);

    expect(DB::table('communications')->exists())->toBeTrue();
});

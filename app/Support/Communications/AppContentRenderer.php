<?php

namespace App\Support\Communications;

use AIArmada\Communications\Contracts\ContentRenderer;
use AIArmada\Communications\Data\RenderedContentData;
use AIArmada\Communications\Models\CommunicationTemplate;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AppContentRenderer implements ContentRenderer
{
    public function render(
        CommunicationTemplate $template,
        string $channel,
        ?string $locale,
        array $variables,
    ): RenderedContentData {
        return $this->renderFromNotification(
            notifiable: new readonly class($variables)
            {
                public function __construct(public array $data) {}

                public function getKey(): ?string
                {
                    return null;
                }
            },
            notification: new readonly class($template, $channel, $locale, $variables) extends Notification
            {
                public function __construct(
                    private CommunicationTemplate $template,
                    private string $channel,
                    private ?string $locale,
                    private array $variables,
                ) {
                    parent::__construct();
                }

                public function via(object $notifiable): array
                {
                    return [$this->channel];
                }

                public function toMail(object $notifiable): MailMessage
                {
                    return (new MailMessage)
                        ->subject($this->variables['subject'] ?? $this->template->name)
                        ->line($this->variables['body'] ?? '');
                }

                public function toArray(object $notifiable): array
                {
                    return $this->variables;
                }
            },
            channel: $channel,
        );
    }

    public function renderFromNotification(
        mixed $notifiable,
        mixed $notification,
        string $channel,
    ): RenderedContentData {
        $locale = null;
        $subject = null;
        $contentText = null;
        $contentHtml = null;
        $payload = [];

        if ($channel === 'inbox') {
            $notificationClass = $notification::class;

            $titleProp = null;
            $bodyProp = null;

            if (property_exists($notificationClass, 'title')) {
                $titleProp = $notification->title;
            }
            if (property_exists($notificationClass, 'body')) {
                $bodyProp = $notification->body;
            }

            $subject = is_string($titleProp) || is_null($titleProp) ? $titleProp : null;
            $contentText = is_string($bodyProp) || is_null($bodyProp) ? $bodyProp : null;

            if (method_exists($notification, 'toArray')) {
                $payload = $notification->toArray($notifiable);
            }
        } elseif ($channel === 'mail' && method_exists($notification, 'toMail')) {
            $mail = $notification->toMail($notifiable);

            if ($mail instanceof MailMessage) {
                $subject = $mail->subject;
                $rendered = $mail->render();
                $contentHtml = $rendered->toHtml();

                $introText = implode("\n", array_map(
                    fn (mixed $line): string => is_string($line) ? $line : '',
                    $mail->introLines,
                ));
                $outroText = implode("\n", array_map(
                    fn (mixed $line): string => is_string($line) ? $line : '',
                    $mail->outroLines,
                ));
                $actionText = is_string($mail->actionText) ? $mail->actionText : '';

                $contentText = trim($introText."\n".$actionText."\n".$outroText);
            }
        } else {
            if (method_exists($notification, 'toArray')) {
                $payload = $notification->toArray($notifiable);
                $subject = $payload['subject'] ?? $payload['title'] ?? null;
                $contentText = $payload['body'] ?? $payload['message'] ?? null;
            }
        }

        $checksum = md5(
            ($contentText ?? '').
            ($contentHtml ?? '').
            ($subject ?? '')
        );

        return new RenderedContentData(
            channel: $channel,
            locale: $locale,
            subject: $subject,
            contentText: $contentText,
            contentHtml: $contentHtml,
            payload: $payload,
            checksum: $checksum,
        );
    }
}

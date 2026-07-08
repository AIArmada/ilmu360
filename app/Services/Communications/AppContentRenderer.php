<?php

namespace App\Services\Communications;

use AIArmada\Communications\Contracts\ContentRenderer;
use AIArmada\Communications\Data\RenderedContentData;

class AppContentRenderer implements ContentRenderer
{
    public function render(
        $template,
        string $channel,
        ?string $locale,
        array $variables,
    ): RenderedContentData {
        return RenderedContentData::from([
            'channel' => $channel,
            'locale' => $locale,
        ]);
    }

    public function renderFromNotification(
        mixed $notifiable,
        mixed $notification,
        string $channel,
    ): RenderedContentData {
        $contentHtml = null;
        $subject = null;
        $contentText = null;

        $originalLocale = app()->getLocale();
        $locale = method_exists($notifiable, 'preferredLocale')
            ? $notifiable->preferredLocale()
            : $originalLocale;

        app()->setLocale($locale);

        try {
            if (method_exists($notification, 'toMail') && $channel === 'mail') {
                $mail = $notification->toMail($notifiable);

                $subject = $mail->subject;
                $contentText = implode("\n", array_map(
                    static fn (mixed $line): string => trim(strip_tags((string) $line)),
                    $mail->introLines,
                ));
                $contentHtml = $mail->render();
            } else {
                $subject = property_exists($notification, 'title') ? $notification->title : null;
            }

            return RenderedContentData::from([
                'channel' => $channel,
                'locale' => $locale,
                'subject' => $subject,
                'contentText' => $contentText,
                'contentHtml' => $contentHtml,
            ]);
        } finally {
            app()->setLocale($originalLocale);
        }
    }
}

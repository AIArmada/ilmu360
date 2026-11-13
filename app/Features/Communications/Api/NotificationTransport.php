<?php

namespace App\Features\Communications\Api;

enum NotificationTransport: string
{
    case Inbox = 'communications.inbox';
    case DigestMail = 'communications.digest-mail';
    case Push = 'communications.push';
}

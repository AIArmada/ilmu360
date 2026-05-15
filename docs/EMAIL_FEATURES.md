# Email Features

ilmu360° uses Laravel notifications for email delivery. In local development, `MAIL_MAILER=log` remains the default, so email content is written to the application log instead of being sent to a real mailbox.

## Local Development

- Mail transport: `MAIL_MAILER=log`
- Queue behavior: queued emails stay on the `notifications-mail` queue
- Queue connection: `QUEUE_CONNECTION=redis`
- To see email output locally, run Horizon, for example via `composer run dev` or `php artisan horizon`

## Supported Emails

| Email | Trigger | Recipient | Delivery path | Coverage |
| --- | --- | --- | --- | --- |
| Welcome | New web or API signup with an email address | New user | `Registered` listener -> `WelcomeNotification` | `tests/Feature/Auth/EmailLifecycleTest.php`, `tests/Feature/Api/AuthEmailApiTest.php` |
| Email verification | New signup, resend verification, account email change | User email | `User::sendEmailVerificationNotification()` -> `VerifyEmailNotification` | `tests/Feature/Auth/EmailLifecycleTest.php`, `tests/Feature/Api/AuthEmailApiTest.php`, `tests/Feature/AccountSettingsPageTest.php` |
| Password reset | Forgot-password request | User email | Password broker -> `User::sendPasswordResetNotification()` -> `ResetPasswordNotification` | `tests/Feature/Api/AuthEmailApiTest.php` |
| Member invitation | Member invitation creation | Invited email address | `InviteSubjectMember` -> `MemberInvitationNotification` | `tests/Feature/MemberInvitationActionsTest.php` |
| Notification center mail | Event updates, reminders, registrations, check-ins, submission workflow | Verified notification email destination | `NotificationEngine` -> `NotificationCenterMessage` | `tests/Feature/NotificationDeliveryFlowTest.php`, `tests/Feature/NotificationEmailRoutingTest.php` |
| Event submitted | New public event submission for moderation | Moderators / super admins | `EventSubmittedNotification` | `tests/Feature/SubmitEventNotificationTest.php`, `tests/Feature/NotificationEmailRoutingTest.php` |
| Event escalation | Moderation SLA / urgent queue escalation | Moderators / admins | `EventEscalationNotification` | `tests/Feature/EventEscalationTest.php`, `tests/Feature/NotificationEmailRoutingTest.php` |
| Report resolved | Report marked resolved or dismissed | Reporter | `ReportResolvedNotification` | `tests/Feature/NotificationEmailRoutingTest.php` |

## Notes

- Verification and welcome mail only send when the account has an email address.
- Invitation acceptance and revocation do not send email in this version.
- Notification-center email requires a verified account email because it routes through active notification destinations.
- Email links target the existing web URLs generated from `APP_URL`.

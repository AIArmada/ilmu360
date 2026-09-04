<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EventPassController extends Controller
{
    public function __invoke(Request $request, Event $event, string $passId): View
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(404);
        }

        $pass = $event->passes()
            ->where('passes.id', $passId)
            ->with(['holder', 'ticketType', 'registration'])
            ->firstOrFail();

        $holder = $pass->holder;
        $registration = $pass->registration;
        $isHolder = $holder !== null
            && $holder->holder_type === $user->getMorphClass()
            && $holder->holder_id === $user->id;
        $isPurchaser = $registration instanceof Model
            && $registration->getAttribute('registrant_type') === $user->getMorphClass()
            && (string) $registration->getAttribute('registrant_id') === (string) $user->getKey();

        abort_unless($isHolder || $isPurchaser, 404);
        abort_unless($pass->isValid(), 404);

        $checkInEnabled = data_get(
            is_array($event->metadata) ? $event->metadata : [],
            'registration.check_in_enabled',
        ) !== false;

        $qrSvg = null;
        if ($checkInEnabled && $pass->qr_code) {
            $renderer = new ImageRenderer(
                new RendererStyle(200),
                new SvgImageBackEnd,
            );
            $writer = new Writer($renderer);
            $qrSvg = $writer->writeString($pass->qr_code);
        }

        return view('pages.event-pass', [
            'pass' => $pass,
            'event' => $event,
            'qrSvg' => $qrSvg,
            'checkInEnabled' => $checkInEnabled,
        ]);
    }
}

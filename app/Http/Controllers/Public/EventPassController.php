<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
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

        abort_if(
            $holder === null
                || $holder->holder_type !== $user->getMorphClass()
                || $holder->holder_id !== $user->id,
            404,
        );

        $qrSvg = null;
        if ($pass->qr_code) {
            $renderer = new ImageRenderer(
                new RendererStyle(200),
                new SvgImageBackEnd,
            );
            $writer = new Writer($renderer);
            $qrSvg = $writer->writeString($pass->qr_code);
        }

        return view('pages.event-pass', compact('pass', 'event', 'qrSvg'));
    }
}

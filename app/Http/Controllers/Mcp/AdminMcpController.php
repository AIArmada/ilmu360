<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminMcpController extends Controller
{
    public function stream(Request $request): StreamedResponse
    {
        $headers = [
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'Content-Type' => 'text/event-stream',
            'X-Accel-Buffering' => 'no',
        ];

        $sessionId = $request->header('MCP-Session-Id');

        if (is_string($sessionId) && $sessionId !== '') {
            $headers['MCP-Session-Id'] = $sessionId;
        }

        return response()->stream(function (): void {
            @set_time_limit(0);

            // Keep the stream alive for clients that establish SSE before issuing JSON-RPC POSTs.
            do {
                echo ': keep-alive '.now()->toIso8601String()."\n\n";

                if (ob_get_level() !== 0) {
                    ob_flush();
                }

                flush();

                if (app()->runningUnitTests()) {
                    return;
                }

                sleep(15);
            } while (connection_aborted() === 0);
        }, 200, $headers);
    }

    public function destroy(Request $request): Response
    {
        $headers = [];
        $sessionId = $request->header('MCP-Session-Id');

        if (is_string($sessionId) && $sessionId !== '') {
            $headers['MCP-Session-Id'] = $sessionId;
        }

        return response('', 202, $headers);
    }
}

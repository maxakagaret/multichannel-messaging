<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provider webhooks are public endpoints, so every request must carry an
 * HMAC-SHA256 of the raw body made with the shared secret. Comparison is
 * constant-time to avoid leaking the signature byte by byte.
 */
class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = hash_hmac('sha256', $request->getContent(), (string) config('messaging.webhook_secret'));
        $given = (string) $request->header('X-Signature');

        if ($given === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        return $next($request);
    }
}

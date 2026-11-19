<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * /api/pos/* is called by pos-backend only, server to server, with the
 * shared secret config('loyalty.pos_api_token') as a Bearer token.
 */
class AuthenticatePosApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('loyalty.pos_api_token');

        if ($expected === '') {
            return response()->json(['message' => 'API loyalty belum dikonfigurasi.'], 503);
        }

        $given = (string) $request->bearerToken();

        if ($given === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Token tidak valid.'], 401);
        }

        return $next($request);
    }
}

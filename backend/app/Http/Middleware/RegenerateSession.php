<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Session;

class RegenerateSession
{
    /**
     * Handle an incoming request.
     * Regenerate session ID after successful authentication to prevent session fixation attacks.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if this is a login request
        $isLoginRequest = $request->is('api/login') || 
                         $request->is('api/hotspot/login') ||
                         $request->is('api/register');

        // If it's a login request and user is now authenticated, regenerate session
        if ($isLoginRequest && $request->user()) {
            Session::regenerate();
            
            \Log::info('Session regenerated after authentication', [
                'user_id' => $request->user()->id,
                'ip' => $request->ip(),
            ]);
        }

        return $next($request);
    }
}

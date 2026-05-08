<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Events\TokenAuthenticated;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;

/**
 * AuthenticateSseToken
 *
 * EventSource (SSE) cannot set custom headers, so the Bearer token must be
 * passed as a query parameter (?token=...). This middleware authenticates that
 * token directly and binds the resolved user to the current request.
 *
 * SECURITY: Only active on SSE routes. The token is never logged or stored.
 */
class AuthenticateSseToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->query('token');
        $existingBearer = $request->bearerToken();
        $token = is_string($token) && $token !== '' ? $token : $existingBearer;

        Log::debug('SseAuthMiddleware: Processing request', [
            'has_token_param' => !empty($token),
            'has_bearer_header' => !empty($existingBearer),
            'url' => $request->url(),
            'path' => $request->path(),
            'method' => $request->method(),
        ]);

        if (!$token) {
            return $this->errorStream('Authentication token required', 401);
        }

        if (!$this->isValidBearerToken($token)) {
            return $this->errorStream('Invalid authentication token', 401);
        }

        // Keep downstream code that reads bearerToken() working.
        if (!$existingBearer) {
            $request->headers->set('Authorization', 'Bearer ' . $token);
        }

        Auth::forgetGuards();

        $user = $this->resolveUserFromToken($token);
        if (!$user) {
            Log::warning('SseAuthMiddleware: Token authentication failed', [
                'path' => $request->path(),
                'ip' => $request->ip(),
            ]);

            return $this->errorStream('Unauthenticated', 401);
        }

        Auth::shouldUse('sanctum');
        Auth::guard('sanctum')->setUser($user);
        $request->setUserResolver(fn () => $user);

        $response = $next($request);

        // Log 401 responses for debugging
        if ($response->getStatusCode() === 401) {
            Log::warning('SseAuthMiddleware: Request returned 401', [
                'auth_header_set' => !empty($request->header('Authorization')),
                'user_authenticated' => auth()->check(),
                'user_id' => auth()->id(),
                'token_from_query' => !empty($token),
                'token_from_header' => !empty($existingBearer),
            ]);
        }

        return $response;
    }

    private function resolveUserFromToken(string $token): ?Authenticatable
    {
        $model = Sanctum::personalAccessTokenModel();
        $accessToken = $model::findToken($token);

        if (!$this->isValidAccessToken($accessToken)) {
            return null;
        }

        $tokenable = $accessToken->tokenable;
        if (!$this->supportsTokens($tokenable)) {
            return null;
        }

        $user = $tokenable->withAccessToken($accessToken);
        event(new TokenAuthenticated($accessToken));

        $accessToken->forceFill(['last_used_at' => now()])->save();

        return $user;
    }

    private function isValidBearerToken(?string $token): bool
    {
        if (!$token) {
            return false;
        }

        if (str_contains($token, '|')) {
            $modelClass = Sanctum::personalAccessTokenModel();
            $model = new $modelClass();

            if ($model->getKeyType() === 'int') {
                [$id, $tokenValue] = explode('|', $token, 2);

                return ctype_digit($id) && $tokenValue !== '';
            }
        }

        return true;
    }

    private function isValidAccessToken($accessToken): bool
    {
        if (!$accessToken) {
            return false;
        }

        $expiration = config('sanctum.expiration');

        return (!$expiration || $accessToken->created_at->gt(now()->subMinutes($expiration)))
            && (!$accessToken->expires_at || !$accessToken->expires_at->isPast());
    }

    private function supportsTokens($tokenable): bool
    {
        return $tokenable && in_array(HasApiTokens::class, class_uses_recursive(get_class($tokenable)), true);
    }

    private function errorStream(string $message, int $status): Response
    {
        return response()->stream(function () use ($message) {
            echo "event: error\n";
            echo 'data: ' . json_encode(['error' => $message]) . "\n\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }, $status, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store, must-revalidate, private',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}

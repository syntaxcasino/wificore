<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Services\MetricsService;
use Symfony\Component\HttpFoundation\Response;

class TrackTransactions
{
    /**
     * Handle an incoming request and track it as a transaction
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Increment transaction counter
        MetricsService::incrementTransactions();

        return $next($request);
    }
}

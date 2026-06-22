<?php

namespace HMWEvents\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WordPressCapability
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $capability = 'read'): Response
    {
        // Check if WordPress is loaded and user functions are available
        if (!function_exists('current_user_can') || !function_exists('is_user_logged_in')) {
            abort(500, 'WordPress not properly loaded');
        }

        // Check if user is logged in
        if (!is_user_logged_in()) {
            return redirect()->to(wp_login_url($request->fullUrl()));
        }

        // Check if user has the required capability
        if (!current_user_can($capability)) {
            abort(403, 'Insufficient permissions');
        }

        return $next($request);
    }
}

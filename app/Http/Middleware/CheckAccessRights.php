<?php

namespace App\Http\Middleware;
use Closure;

class CheckAccessRights
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @param $routeId
     * @return mixed
     */
    public function handle($request, Closure $next, $routeId)
    {
        $user = auth()->user();

        if (! $user) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            return response()->view('components.unauthorize-access');
        }

        if ($user->is_an_admin()) {
            return $next($request);
        }

        if ($user->assign_role->has_permission_to_route($routeId)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->view('components.unauthorize-access');
    }
}

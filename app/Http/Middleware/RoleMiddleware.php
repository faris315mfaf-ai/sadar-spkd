<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        if ($user->hasAnyRole($roles)) {
            return $next($request);
        }

        // An employee record without the employee role has this very page as home;
        // redirecting would loop until the browser gives up.
        if (rtrim($user->homeUrl(), '/') === rtrim($request->url(), '/')) {
            abort(403, 'Anda tidak memiliki akses ke halaman tersebut.');
        }

        return redirect()
            ->to($user->homeUrl())
            ->with('error', 'Anda tidak memiliki akses ke halaman tersebut.');
    }
}

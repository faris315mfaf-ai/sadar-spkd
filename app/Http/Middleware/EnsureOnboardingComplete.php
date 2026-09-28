<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends users who signed up but skipped the biodata step back to it. Face registration is not
 * forced here: employees without a photo can still reach attendance (e.g. to report sick) and
 * get a link to register their face there.
 */
class EnsureOnboardingComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->employee === null && ! $user->isAdmin()) {
            return redirect()->route('onboarding.biodata');
        }

        return $next($request);
    }
}

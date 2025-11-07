<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     *
     * We override this to avoid redirecting for API/JWT requests and instead let the
     * framework return a JSON 401 (or the handler can do it).
     *
     * @param \Illuminate\Http\Request $request
     * @return string|null
     */
    protected function redirectTo($request)
    {
        // If request expects JSON, don't redirect. Return null so Laravel won't try to redirect.
        if ($request->expectsJson()) {
            return null;
        }

        // For non-JSON requests you can still return a web login page if you want:
        // return route('login');
        return null;
    }
}

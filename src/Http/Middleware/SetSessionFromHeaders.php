<?php

namespace Overtrue\LaravelStatelessSession\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

class SetSessionFromHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure                 $next
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $headerName = \config('session.header', 'x-session');
        $sessionId = $request->header($headerName);

        if (!empty($sessionId) && $sessionId !== 'undefined') {
            $request->cookies->add([Session::getName() => $sessionId]);
        }

        $response = $next($request);

        if ($request->hasSession()) {
            $response->headers->set($headerName, $request->session()->getId());
        }

        return $response;
    }
}

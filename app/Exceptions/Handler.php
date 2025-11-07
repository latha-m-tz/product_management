<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;
use Illuminate\Auth\AuthenticationException;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their custom log levels.
     */
    protected $levels = [];

    /**
     * A list of the exception types that are not reported.
     */
    protected $dontReport = [];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     */
    protected $dontFlash = ['current_password', 'password', 'password_confirmation'];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        //
    }

    /**
     * Handle unauthenticated users.
     */
    protected function unauthenticated($request, AuthenticationException $exception)
    {
        return $request->expectsJson()
            ? response()->json(['status' => 'error', 'message' => 'Unauthenticated'], 401)
            : redirect()->guest(route('login'));
    }
}

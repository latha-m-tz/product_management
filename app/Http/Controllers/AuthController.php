<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\ForgotPasswordOtpMail;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()], 422);
        }

        $user = User::create([
            'email' => $request->email,
            'username' => $request->username,
            'password' => bcrypt($request->password),
            'email_verified' => false
        ]);

        $token = JWTAuth::fromUser($user);

        return response()->json([
            'status' => 'success',
            'message' => 'Registration successful',
            'data' => ['token' => $token, 'user' => $user]
        ]);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'This email is not registered.'], 401);
        }

        if (!Hash::check($request->password, $user->password)) {
            return response()->json(['status' => 'error', 'message' => 'Incorrect password.'], 401);
        }

        $token = JWTAuth::attempt(['email' => $request->email, 'password' => $request->password]);

        if (!$token) {
            return response()->json(['status' => 'error', 'message' => 'Login failed. Please try again.'], 401);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Login successful',
            'data' => ['token' => $token, 'user' => JWTAuth::user()]
        ]);
    }

    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'User not found'], 404);
        }

        $otp = rand(100000, 999999);
        $expiresInMinutes = 10;

        Cache::put('otp_' . $user->email, $otp, now()->addMinutes($expiresInMinutes));
        Cache::put('otp_verified_' . $user->email, false, now()->addMinutes($expiresInMinutes));

        try {
            Mail::to($user->email)->send(new ForgotPasswordOtpMail($otp));
        } catch (\Exception $e) {
            Log::error("OTP email failed: " . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Email sending failed. Check SMTP settings.'], 500);
        }

        return response()->json(['status' => 'success', 'message' => 'OTP has been sent to your email address']);
    }

    public function verifyOtp(Request $request)
    {
        $request->validate(['email' => 'required|email', 'otp' => 'required|numeric']);

        $cachedOtp = Cache::get('otp_' . $request->email);

        if (!$cachedOtp || $cachedOtp != $request->otp) {
            return response()->json(['status' => 'error', 'message' => 'Invalid or expired OTP'], 400);
        }

        Cache::put('otp_verified_' . $request->email, true, now()->addMinutes(10));
        Cache::forget('otp_' . $request->email);

        return response()->json(['status' => 'success', 'message' => 'OTP verified successfully']);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|min:6|confirmed'
        ]);

        if (!Cache::get('otp_verified_' . $request->email)) {
            return response()->json(['status' => 'error', 'message' => 'OTP verification required'], 403);
        }

        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'User not found'], 404);
        }

        $user->password = bcrypt($request->password);
        $user->save();

        Cache::forget('otp_verified_' . $request->email);

        return response()->json(['status' => 'success', 'message' => 'Password has been reset']);
    }

    public function user()
    {
        return response()->json(['status' => 'success', 'user' => JWTAuth::user()]);
    }

    public function logout(Request $request)
    {
        try {
            $token = JWTAuth::getToken();
            if (!$token) return response()->json(['status' => 'error', 'message' => 'Token not provided'], 400);

            JWTAuth::invalidate($token);
            return response()->json(['status' => 'success', 'message' => 'Logged out successfully']);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'Logout failed', 'details' => $e->getMessage()], 500);
        }
    }
    public function getLogin(Request $request)
{
    $email = $request->query('email');
    $password = $request->query('password');

    if (!$email || !$password) {
        return response()->json(['status' => 'error', 'message' => 'Email and password are required'], 400);
    }

    $user = User::where('email', $email)->first();

    if (!$user) {
        return response()->json(['status' => 'error', 'message' => 'This email is not registered.'], 401);
    }

    if (!Hash::check($password, $user->password)) {
        return response()->json(['status' => 'error', 'message' => 'Incorrect password.'], 401);
    }

    $token = JWTAuth::attempt(['email' => $email, 'password' => $password]);

    if (!$token) {
        return response()->json(['status' => 'error', 'message' => 'Login failed.'], 401);
    }

    return response()->json([
        'status' => 'success',
        'message' => 'Login successful (GET)',
        'data' => ['token' => $token, 'user' => JWTAuth::user()],
    ]);
}
public function getUsers() {
    $users = User::select('id', 'username')->get(); 
    return response()->json($users);
}
public function index()
{
    $users = User::select('id', 'username')->get(); 
    return response()->json($users);
}
protected function redirectTo($request)
{
    if (! $request->expectsJson()) {
        abort(response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401));
    }
}
}

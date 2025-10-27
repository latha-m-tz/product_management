<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});
// use Illuminate\Support\Facades\Mail;

// Route::get('/test-email', function () {
//     try {
//         \Mail::raw('Testing email service', function($message) {
//             $message->to('lathasmoorthi@gmail.com')
//                     ->subject('Test Email');
//         });
//         return '✅ Email sent!';
//     } catch (\Exception $e) {
//         return '❌ Failed: ' . $e->getMessage();
//     }
// });


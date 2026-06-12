<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    /**
     * Display the user's email preferences form.
     */
    public function emailPreferences(Request $request): View
    {
        return view('profile.email-preferences');
    }
}

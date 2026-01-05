<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class SessionController extends Controller
{
    /**
     * Refresh session and CSRF token (keep-alive)
     */
    public function keepAlive(Request $request)
    {
        // Regenerate CSRF token to extend session lifetime
        $request->session()->regenerateToken();
        
        return response()->json([
            'success' => true,
            'csrf_token' => csrf_token(),
        ]);
    }
}


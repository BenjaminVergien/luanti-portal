<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class InvitationController extends Controller
{
    public function __invoke(Request $request, #[\SensitiveParameter] string $token): RedirectResponse
    {
        $invitation = Invitation::query()->where('token_hash', hash('sha256', $token))->first();
        abort_unless($invitation !== null && $invitation->isPending(), 404);
        $request->session()->put('invitation_hash', hash('sha256', $token));

        return redirect()->route('access')->with('status', 'Bitte anmelden und die persönliche Einladung bestätigen.');
    }
}

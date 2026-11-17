<?php

namespace App\Http\Controllers;

use App\Filament\Resources\Members\Actions\MemberExportAction;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the Members PDF export queued from the Members table's Export modal.
 * The URL is signed and short-lived, and the export details live in the
 * admin's own session, so a copied link is useless to anyone else.
 */
class MemberExportController extends Controller
{
    public function pdf(Request $request, string $token): Response
    {
        $user = $request->user();

        abort_unless(
            $user && ($user->hasRole('Super Admin') || $user->can('members.index')),
            403,
        );

        return MemberExportAction::pdfResponse($token);
    }
}

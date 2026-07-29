<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateUserRequest;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class UserController extends Controller
{
    protected AuthService $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Update authenticated user information.
     */
    public function update(UpdateUserRequest $request): JsonResponse
    {
        $user = auth()->user();
        $validatedData = $request->validated();

        try {
            DB::transaction(function () use ($user, $validatedData) {
                // Update basic user information
                $userData = [];

                if (isset($validatedData['name'])) {
                    $userData['name'] = $validatedData['name'];
                }

                if (isset($validatedData['email'])) {
                    $userData['email'] = $validatedData['email'];
                }

                if (isset($validatedData['password'])) {
                    $userData['password'] = Hash::make($validatedData['password']);
                }

                if (isset($validatedData['city'])) {
                    $userData['city'] = $validatedData['city'];
                }


                // Handle profile picture upload
                if (isset($validatedData['profile_picture'])) {
                    // Delete old profile picture if exists
                    if ($user->profile_picture && Storage::disk('public')->exists($user->profile_picture)) {
                        Storage::disk('public')->delete($user->profile_picture);
                    }

                    // Store new profile picture
                    $file = $validatedData['profile_picture'];
                    $filename = time().'_'.$user->id.'.'.$file->getClientOriginalExtension();
                    $path = $file->storeAs('profile-pictures', $filename, 'public');
                    $userData['profile_picture'] = 'profile-pictures/'.$filename;
                }

                if (! empty($userData)) {
                    $user->update($userData);

                    // if user is agent update agent profile
                    if ($user->isAgent()) {
                        $user->agentProfile()->update([
                            'bank_name' => $validatedData['bank_name'] ?? null,
                            'account_number' => $validatedData['account_number'] ?? null,
                            'account_holder_name' => $validatedData['account_holder_name'] ?? null,
                        ]);
                    }
                }

            });

            // Reload user with relationships
            $updatedUser = User::find($user->id);

            return response()->json([
                'status' => 'success',
                'message' => 'User updated successfully.',
                'user' => $updatedUser->makeHidden(['password']),
                'profile_picture_url' => $updatedUser->profile_picture_url,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update user.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    /**
     * Store or update the FCM device token for the authenticated user.
     * The mobile app should call this once after login and whenever
     * the device token is refreshed by FCM.
     *
     * It also automatically re-subscribes the new token to all the user's
     * active Equb group topics.
     */
    public function updateFcmToken(\Illuminate\Http\Request $request): JsonResponse
    {
        $request->validate([
            'fcm_token' => 'required|string|max:255',
        ]);

        $user = auth()->user();
        $token = $request->input('fcm_token');

        $user->update(['fcm_token' => $token]);

        // Re-subscribe to all active Equb groups
        if ($user->member) {
            $activeGroupIds = $user->member->equbMemberships()
                ->where('status', \App\Enums\EqubMembershipStatus::Active)
                ->pluck('equb_group_id');

            $fcmService = app(\App\Services\FcmService::class);
            foreach ($activeGroupIds as $groupId) {
                $topic = \App\Services\FcmService::equbGroupTopic($groupId);
                $fcmService->subscribeToTopic($token, $topic);
            }
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'FCM token updated and topics re-subscribed.',
        ]);
    }
}

<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $user = auth()->user();
        $userId = $user?->id;
        $rules = [
            // Basic user fields
            'name' => 'sometimes|nullable|string|max:255',

            'email' => [
                'sometimes',
                'nullable',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],

            'password' => 'sometimes|nullable|string|min:8|max:255',
            'city' => 'nullable|string|max:255',

            'bank_name' => 'sometimes|nullable|string|max:255',
            'account_number' => 'sometimes|nullable|string|max:50',
            'account_holder_name' => 'sometimes|nullable|string|max:255',

            'profile_picture' => 'sometimes|nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
        ];

        // If no authenticated user (Scribe, CLI, tests) → return base rules only
        if (! $user) {
            return $rules;
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'name.string' => 'Name must be a valid string.',
            'name.max' => 'Name cannot exceed 255 characters.',

            'email.email' => 'Please provide a valid email address.',
            'email.unique' => 'This email address is already in use.',

            'password.min' => 'Password must be at least 8 characters long.',

            'profile_picture.image' => 'Profile picture must be an image file.',
            'profile_picture.mimes' => 'Profile picture must be jpeg, png, jpg, gif, or webp.',
            'profile_picture.max' => 'Profile picture size must not exceed 2MB.',

        ];
    }
}

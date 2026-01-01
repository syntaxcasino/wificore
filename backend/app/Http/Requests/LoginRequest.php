<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'tenant_slug' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'username.required' => 'Username is required',
            'password.required' => 'Password is required',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Sanitize username to prevent injection
        if ($this->has('username')) {
            $this->merge([
                'username' => strip_tags(trim($this->username)),
            ]);
        }
    }
}

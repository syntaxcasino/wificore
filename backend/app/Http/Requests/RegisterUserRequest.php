<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Rules\StrongPassword;

class RegisterUserRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'unique:users,username'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', new StrongPassword()],
            'phone_number' => ['nullable', 'string', 'max:20'],
            'role' => ['nullable', 'string', 'in:admin,user,hotspot_user'],
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
            'name.required' => 'Name is required',
            'username.required' => 'Username is required',
            'username.unique' => 'Username already exists',
            'email.required' => 'Email is required',
            'email.email' => 'Email must be a valid email address',
            'email.unique' => 'Email already exists',
            'password.required' => 'Password is required',
            'password.confirmed' => 'Password confirmation does not match',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Sanitize inputs to prevent XSS
        $sanitized = [];
        
        if ($this->has('name')) {
            $sanitized['name'] = strip_tags(trim($this->name));
        }
        
        if ($this->has('username')) {
            $sanitized['username'] = strip_tags(trim($this->username));
        }
        
        if ($this->has('email')) {
            $sanitized['email'] = filter_var(trim($this->email), FILTER_SANITIZE_EMAIL);
        }
        
        if ($this->has('phone_number')) {
            $sanitized['phone_number'] = preg_replace('/[^0-9+\-\s()]/', '', $this->phone_number);
        }
        
        if (!empty($sanitized)) {
            $this->merge($sanitized);
        }
    }
}

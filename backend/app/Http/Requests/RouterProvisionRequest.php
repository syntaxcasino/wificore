<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RouterProvisionRequest extends FormRequest
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
            'ip_address' => ['required', 'ip'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'api_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'type' => ['nullable', 'string', 'in:mikrotik,genieacs,other'],
            'status' => ['nullable', 'string', 'in:active,inactive,maintenance'],
            'location' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:1000'],
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
            'name.required' => 'Router name is required',
            'ip_address.required' => 'IP address is required',
            'ip_address.ip' => 'IP address must be a valid IP address',
            'username.required' => 'Router username is required',
            'password.required' => 'Router password is required',
            'port.integer' => 'Port must be a valid number',
            'port.min' => 'Port must be at least 1',
            'port.max' => 'Port cannot exceed 65535',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Sanitize inputs to prevent injection
        $sanitized = [];
        
        if ($this->has('name')) {
            $sanitized['name'] = strip_tags(trim($this->name));
        }
        
        if ($this->has('ip_address')) {
            $sanitized['ip_address'] = trim($this->ip_address);
        }
        
        if ($this->has('username')) {
            $sanitized['username'] = strip_tags(trim($this->username));
        }
        
        if ($this->has('location')) {
            $sanitized['location'] = strip_tags(trim($this->location));
        }
        
        if ($this->has('description')) {
            $sanitized['description'] = strip_tags(trim($this->description));
        }
        
        if (!empty($sanitized)) {
            $this->merge($sanitized);
        }
    }
}

<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

class StrongPassword implements Rule
{
    /**
     * Determine if the validation rule passes.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    public function passes($attribute, $value)
    {
        // Minimum 12 characters (enhanced security - P1 fix)
        if (strlen($value) < 12) {
            return false;
        }

        // At least one uppercase letter
        if (!preg_match('/[A-Z]/', $value)) {
            return false;
        }

        // At least one lowercase letter
        if (!preg_match('/[a-z]/', $value)) {
            return false;
        }

        // At least one digit
        if (!preg_match('/\d/', $value)) {
            return false;
        }

        // At least one special character
        if (!preg_match('/[@$!%*?&#^()_+\-=\[\]{};:\'",.<>\/\\|`~]/', $value)) {
            return false;
        }

        return true;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return 'The :attribute must be at least 12 characters and contain at least one uppercase letter, one lowercase letter, one number, and one special character.';
    }
}

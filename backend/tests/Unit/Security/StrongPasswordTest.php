<?php

namespace Tests\Unit\Security;

use Tests\TestCase;
use App\Rules\StrongPassword;

class StrongPasswordTest extends TestCase
{
    protected $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rule = new StrongPassword();
    }

    /** @test */
    public function it_rejects_passwords_shorter_than_12_characters()
    {
        $this->assertFalse($this->rule->passes('password', 'Short1!'));
        $this->assertFalse($this->rule->passes('password', 'Pass123!'));
    }

    /** @test */
    public function it_rejects_passwords_without_uppercase()
    {
        $this->assertFalse($this->rule->passes('password', 'lowercase123!'));
    }

    /** @test */
    public function it_rejects_passwords_without_lowercase()
    {
        $this->assertFalse($this->rule->passes('password', 'UPPERCASE123!'));
    }

    /** @test */
    public function it_rejects_passwords_without_numbers()
    {
        $this->assertFalse($this->rule->passes('password', 'NoNumbers!@#'));
    }

    /** @test */
    public function it_rejects_passwords_without_special_characters()
    {
        $this->assertFalse($this->rule->passes('password', 'NoSpecial123'));
    }

    /** @test */
    public function it_accepts_valid_strong_passwords()
    {
        $this->assertTrue($this->rule->passes('password', 'StrongPass123!'));
        $this->assertTrue($this->rule->passes('password', 'MyP@ssw0rd2024'));
        $this->assertTrue($this->rule->passes('password', 'Secure#Pass123'));
    }

    /** @test */
    public function it_returns_correct_error_message()
    {
        $message = $this->rule->message();
        $this->assertStringContainsString('12 characters', $message);
        $this->assertStringContainsString('uppercase', $message);
        $this->assertStringContainsString('lowercase', $message);
        $this->assertStringContainsString('number', $message);
        $this->assertStringContainsString('special character', $message);
    }
}

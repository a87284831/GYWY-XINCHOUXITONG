<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class AuthSecurityTest extends TestCase
{
    public function test_password_hash_uses_static_salt(): void
    {
        $password = 'test123';
        $hash = hash('sha256', 'gwxy_' . $password);
        $this->assertEquals(64, strlen($hash));
        $this->assertEquals($hash, hash('sha256', 'gwxy_' . $password));
    }

    public function test_different_passwords_produce_different_hashes(): void
    {
        $hash1 = hash('sha256', 'gwxy_' . 'password1');
        $hash2 = hash('sha256', 'gwxy_' . 'password2');
        $this->assertNotEquals($hash1, $hash2);
    }

    public function test_hash_equals_is_timing_safe(): void
    {
        $expected = 'abc123def456';
        $this->assertTrue(hash_equals($expected, $expected));
        $this->assertFalse(hash_equals($expected, 'abc123def457'));
        $this->assertFalse(hash_equals($expected, 'short'));
    }

    public function test_id_card_last6_comparison(): void
    {
        $fullCard = '110101199001011234';
        $last6 = strtoupper(substr($fullCard, -6));
        $this->assertEquals('011234', $last6);

        $this->assertTrue(hash_equals('011234', $last6));
        $this->assertFalse(hash_equals('011235', $last6));
    }
}

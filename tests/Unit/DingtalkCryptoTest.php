<?php

namespace Tests\Unit;

use App\Services\DingtalkCrypto;
use App\Services\DingtalkCryptoException;
use PHPUnit\Framework\TestCase;

/**
 * 钉钉回调加解密（P0 修复 2026-09-30）。
 * 协议以官方 open-dingtalk/dingtalk-callback-Crypto 为准：
 * - 签名 sha1(sort(encrypt, token, timestamp, nonce))
 * - AES-256-CBC，key=base64_decode(aesKey."=")，iv=key[:16]，PKCS7 块 32
 * - 明文结构 random(16B) + pack("N", len)(4B) + msg + ownerKey
 * 官方测试向量取自钉钉开放平台文档《事件订阅》FAQ。
 */
class DingtalkCryptoTest extends TestCase
{
    private const TOKEN = 'testtoken123';
    private const AES_KEY = '1234567890123456789012345678901234567890123'; // 43 字符
    private const OWNER_KEY = 'ding0000000000000001';

    private function crypto(): DingtalkCrypto
    {
        return new DingtalkCrypto(self::TOKEN, self::AES_KEY, self::OWNER_KEY);
    }

    /** 造一个与官方兼容的合法请求（encrypt + 签名 + 时间戳 + nonce） */
    private function makeRequest(string $plain): array
    {
        $resp = json_decode($this->crypto()->encryptMsg($plain), true);
        return [
            'signature' => $resp['msg_signature'],
            'timestamp' => (string) $resp['timeStamp'],
            'nonce' => $resp['nonce'],
            'encrypt' => $resp['encrypt'],
            'raw' => $resp,
        ];
    }

    public function test_official_faq_vector_decrypts_to_success(): void
    {
        // 钉钉开放平台文档 FAQ 提供的真实向量
        $crypto = new DingtalkCrypto('123456', '1234567890123456789012345678901234567890123', 'dingsnotzck6pm5veliw');
        $plain = $crypto->decryptMsg(
            '9a95a004dd16f5c307e849b994173f76aa26e5eb',
            '1614767836',
            'A7Co0cJLMzIDtMMI',
            'YvkvaGe4hQxd3VxRmEty0dVlnCOAqwf56xwTRHDHoOURqhalbmBJQk5FNcRk42Gl5T0YQXZNwpwWSm1xAFJ5ZA=='
        );
        $this->assertSame('success', $plain);
    }

    public function test_encrypt_decrypt_roundtrip(): void
    {
        $plain = '{"EventType":"user_add_org","UserId":["u1"]}';
        $req = $this->makeRequest($plain);
        $this->assertSame($plain, $this->crypto()->decryptMsg($req['signature'], $req['timestamp'], $req['nonce'], $req['encrypt']));
    }

    public function test_encrypt_msg_response_has_required_fields(): void
    {
        $raw = json_decode($this->crypto()->encryptMsg('success'), true);
        $this->assertArrayHasKey('msg_signature', $raw);
        $this->assertArrayHasKey('encrypt', $raw);
        $this->assertArrayHasKey('timeStamp', $raw);
        $this->assertArrayHasKey('nonce', $raw);
        $this->assertSame(40, strlen($raw['msg_signature'])); // sha1 hex
    }

    public function test_wrong_signature_rejected(): void
    {
        $req = $this->makeRequest('success');
        $this->expectException(DingtalkCryptoException::class);
        $this->expectExceptionCode(900005);
        $this->crypto()->decryptMsg('deadbeef', $req['timestamp'], $req['nonce'], $req['encrypt']);
    }

    public function test_tampered_encrypt_rejected(): void
    {
        $req = $this->makeRequest('success');
        // 篡改密文（签名仍为原文的）→ 验签必然失败
        $tampered = $req['encrypt'];
        $tampered[0] = $tampered[0] === 'A' ? 'B' : 'A';
        $this->expectException(DingtalkCryptoException::class);
        $this->expectExceptionCode(900005);
        $this->crypto()->decryptMsg($req['signature'], $req['timestamp'], $req['nonce'], $tampered);
    }

    public function test_owner_key_mismatch_rejected(): void
    {
        $req = $this->makeRequest('success');
        // 用不同 ownerKey 的实例解密 → 明文尾部校验失败
        $other = new DingtalkCrypto(self::TOKEN, self::AES_KEY, 'ding9999999999999999');
        try {
            $other->decryptMsg($req['signature'], $req['timestamp'], $req['nonce'], $req['encrypt']);
            $this->fail('expected DingtalkCryptoException');
        } catch (DingtalkCryptoException $e) {
            $this->assertSame(900010, $e->getCode());
        }
    }

    public function test_invalid_aes_key_length_rejected(): void
    {
        $this->expectException(DingtalkCryptoException::class);
        $this->expectExceptionCode(900004);
        new DingtalkCrypto(self::TOKEN, 'shortkey', self::OWNER_KEY);
    }

    public function test_long_message_roundtrip(): void
    {
        // 超过单个 AES 块（32B）的消息，验证 PKCS7 与长度解析
        $plain = json_encode(['EventType' => 'user_modify_org', 'data' => str_repeat('测', 100)], JSON_UNESCAPED_UNICODE);
        $req = $this->makeRequest($plain);
        $this->assertSame($plain, $this->crypto()->decryptMsg($req['signature'], $req['timestamp'], $req['nonce'], $req['encrypt']));
    }
}

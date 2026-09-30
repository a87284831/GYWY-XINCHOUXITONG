<?php

namespace App\Services;

/**
 * 钉钉回调加解密（P0 修复 2026-09-30）。
 * 移植自官方 open-dingtalk/dingtalk-callback-Crypto PHP 版，协议：
 * - 签名 sha1(sort(encrypt, token, timestamp, nonce))（SORT_STRING）
 * - AES-256-CBC，key=base64_decode(aesKey."=")，iv=key[:16]，自定 PKCS7 块 32
 * - 明文结构 random(16B) + pack("N", len)(4B) + msg + ownerKey
 * 企业内部应用事件订阅 ownerKey 传 appKey。
 */
class DingtalkCrypto
{
    public function __construct(
        private readonly string $token,
        private readonly string $aesKey,
        private readonly string $ownerKey,
    ) {
        if (strlen($this->aesKey) !== 43) {
            throw new DingtalkCryptoException('IllegalAesKey: 需要恰好 43 个字符', 900004);
        }
    }

    /**
     * 验签 + 解密回调报文，返回明文（JSON 字符串）。
     *
     * @throws DingtalkCryptoException 验签失败(900005)/解密失败(900008)/ownerKey 不匹配(900010)
     */
    public function decryptMsg(string $signature, string $timestamp, string $nonce, string $encrypt): string
    {
        $calc = $this->signature($this->token, $timestamp, $nonce, $encrypt);
        if (!hash_equals($calc, $signature)) {
            throw new DingtalkCryptoException('ValidateSignatureError: 回调签名校验失败', 900005);
        }

        $key = base64_decode($this->aesKey . '=');
        $iv = substr($key, 0, 16);
        $ciphertext = base64_decode($encrypt);
        if ($ciphertext === false || strlen($ciphertext) % 16 !== 0) {
            throw new DingtalkCryptoException('DecryptAESError: 密文非法', 900008);
        }
        $decrypted = openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
        if ($decrypted === false) {
            throw new DingtalkCryptoException('DecryptAESError: AES 解密失败', 900008);
        }
        $result = $this->pkcs7Decode($decrypted);

        if (strlen($result) < 16) {
            throw new DingtalkCryptoException('DecryptAESError: 明文结构非法', 900008);
        }
        $content = substr($result, 16);
        $len = unpack('N', substr($content, 0, 4))[1];
        $msg = substr($content, 4, $len);
        $fromOwnerKey = substr($content, $len + 4);
        if ($fromOwnerKey !== $this->ownerKey) {
            throw new DingtalkCryptoException('ValidateOwnerKeyError: ownerKey 与 appKey 不匹配', 900010);
        }
        return $msg;
    }

    /**
     * 加密返回给钉钉的响应体，返回 JSON 字符串（msg_signature/encrypt/timeStamp/nonce）。
     */
    public function encryptMsg(string $plain): string
    {
        $key = base64_decode($this->aesKey . '=');
        $iv = substr($key, 0, 16);
        $random = bin2hex(random_bytes(8)); // 16 字节随机串
        $text = $random . pack('N', strlen($plain)) . $plain . $this->ownerKey;
        $encrypted = openssl_encrypt($this->pkcs7Encode($text), 'AES-256-CBC', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
        if ($encrypted === false) {
            throw new DingtalkCryptoException('EncryptAESError: AES 加密失败', 900007);
        }
        $encrypt = base64_encode($encrypted);
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(8));
        return json_encode([
            'msg_signature' => $this->signature($this->token, $timestamp, $nonce, $encrypt),
            'encrypt' => $encrypt,
            'timeStamp' => $timestamp,
            'nonce' => $nonce,
        ]);
    }

    private function signature(string $token, string $timestamp, string $nonce, string $encrypt): string
    {
        $arr = [$encrypt, $token, $timestamp, $nonce];
        sort($arr, SORT_STRING);
        return sha1(implode('', $arr));
    }

    /** 官方协议自定补位：块大小 32（非标准 PKCS7/16） */
    private function pkcs7Encode(string $text): string
    {
        $amount = 32 - (strlen($text) % 32);
        return $text . str_repeat(chr($amount), $amount);
    }

    private function pkcs7Decode(string $text): string
    {
        $pad = ord(substr($text, -1));
        if ($pad < 1 || $pad > 32) {
            $pad = 0;
        }
        return substr($text, 0, strlen($text) - $pad);
    }
}

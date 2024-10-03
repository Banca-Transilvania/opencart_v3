<?php
namespace BtIpay\Opencart\Card;

class Encrypt
{
    public const TO_ENCRYPT = [
        'expiration',
        'cardholderName',
        'pan'
    ];

    public const ALG = "AES-256-GCM";

    private static $key;

    public static function encrypt($data)
    {
        self::getKey();
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length(self::ALG));
        $tag = null;
        $encrypted = openssl_encrypt($data, self::ALG, self::$key, 0, $iv, $tag);
        if ($encrypted === false) {
            throw new \Exception(openssl_error_string());
        }
        return base64_encode($encrypted . '::' . $iv . '::' . $tag);
    }

    public static function decrypt($data)
    {
        if (!is_string($data)) {
            return "";
        }
        $parts = explode('::', base64_decode($data), 3);
        if (count($parts) < 3) {
            return "";
        }
        self::getKey();
        list($encryptedData, $iv, $tag) = $parts;
        $decrypted = openssl_decrypt($encryptedData, self::ALG, self::$key, 0, $iv, $tag);
        if ($decrypted === false) {
            throw new \Exception(openssl_error_string());
        }
        return $decrypted;
    }

    private static function getKey()
    {
        $file = DIR_SESSION . "ipay_encryption_key";
        if (file_exists($file)) {
            self::$key = file_get_contents($file);
            return;
        }
        $key = bin2hex(openssl_random_pseudo_bytes(32));
        file_put_contents($file, $key);
        self::$key = $key;
    }

    public static function encryptCard(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array($key, self::TO_ENCRYPT)) {
                $data[$key] = Encrypt::encrypt($value);
            }
        }
        return $data;
    }
}

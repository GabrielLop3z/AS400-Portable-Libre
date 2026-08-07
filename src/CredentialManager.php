<?php
namespace App;

class CredentialManager {
    private static $secret = "AS400_GLR_KEY_2026"; // Llave interna para ofuscación

    public static function encrypt($text) {
        $result = '';
        for($i=0; $i<strlen($text); $i++) {
            $char = substr($text, $i, 1);
            $keychar = substr(self::$secret, ($i % strlen(self::$secret))-1, 1);
            $char = chr(ord($char) + ord($keychar));
            $result .= $char;
        }
        return base64_encode($result);
    }

    public static function decrypt($text) {
        $text = base64_decode($text);
        $result = '';
        for($i=0; $i<strlen($text); $i++) {
            $char = substr($text, $i, 1);
            $keychar = substr(self::$secret, ($i % strlen(self::$secret))-1, 1);
            $char = chr(ord($char) - ord($keychar));
            $result .= $char;
        }
        return $result;
    }

    public static function store($user, $pass) {
        $data = [
            'u' => self::encrypt($user),
            'p' => self::encrypt($pass),
            'updated' => date('Y-m-d H:i:s')
        ];
        $configDir = realpath($_SERVER['DOCUMENT_ROOT'] . '/AS400/config') ?: dirname(__DIR__) . '/config';
        if (!is_dir($configDir)) mkdir($configDir, 0777, true);
        return file_put_contents($configDir . '/proxy.dat', json_encode($data));
    }

    public static function load() {
        $configDir = realpath($_SERVER['DOCUMENT_ROOT'] . '/AS400/config') ?: dirname(__DIR__) . '/config';
        $file = $configDir . '/proxy.dat';
        if (!file_exists($file)) return null;
        $data = json_decode(file_get_contents($file), true);
        return [
            'user' => self::decrypt($data['u']),
            'pass' => self::decrypt($data['p'])
        ];
    }
}

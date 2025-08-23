<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Ig_rx_login {
    protected $CI;

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->app_secret = $this->CI->config->item('instagram_app_secret');
        // TODO: load additional settings
    }

    public function connect($code, $redirect_uri)
    {
        // TODO: exchange code for access token using Facebook/Instagram OAuth endpoints
        // Return an array with access_token, expires_in, ig_user_id
        return ['error' => 'not_implemented'];
    }

    public function sendMessage($account_id, $payload)
    {
        // TODO: look up account token and call IG Messaging API
        // For now return stub
        return ['ok' => false, 'reason' => 'not_implemented'];
    }

    public function verifySignature($rawPayload, $signatureHeader)
    {
        if (empty($this->app_secret) || empty($signatureHeader)) {
            return false;
        }

        // header is like: sha256=HEX
        if (strpos($signatureHeader, 'sha256=') === 0) {
            $hash = substr($signatureHeader, 7);
        } else {
            $hash = $signatureHeader;
        }

        $computed = hash_hmac('sha256', $rawPayload, $this->app_secret);
        // timing-safe compare
        return hash_equals($computed, $hash);
    }
}
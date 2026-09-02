<?php

namespace App\Services;

class WhatsAppService
{
    protected $apiUrl;
    protected $clientToken;

    public function __construct($apiUrl, $clientToken = null)
    {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->clientToken = $clientToken;
    }

    public function sendMessage($destination, $message)
    {
        // Basic implementation using file_get_contents for portability.
        $payload = json_encode(['phone' => $destination, 'message' => $message]);
        $headers = "Content-Type: application/json\r\n";
        if ($this->clientToken) $headers .= "Client-Token: {$this->clientToken}\r\n";
        $opts = [
            'http' => [
                'method'  => 'POST',
                'header'  => $headers,
                'content' => $payload,
                'timeout' => 5,
            ]
        ];
        $context  = stream_context_create($opts);
        return @file_get_contents($this->apiUrl, false, $context) !== false;
    }
}

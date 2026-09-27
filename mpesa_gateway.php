<?php
function getMpesaConfig() {
    return [
        'env' => getenv('MPESA_ENV') ?: 'sandbox',
        'consumer_key' => getenv('MPESA_CONSUMER_KEY') ?: '',
        'consumer_secret' => getenv('MPESA_CONSUMER_SECRET') ?: '',
        'shortcode' => getenv('MPESA_SHORTCODE') ?: '174379',
        'passkey' => getenv('MPESA_PASSKEY') ?: '',
        'initiator_name' => getenv('MPESA_INITIATOR_NAME') ?: '',
        'security_credential' => getenv('MPESA_SECURITY_CREDENTIAL') ?: '',
        'callback_url' => getenv('MPESA_CALLBACK_URL') ?: 'http://127.0.0.1:8000/mpesa_callback.php',
        'timeout_url' => getenv('MPESA_TIMEOUT_URL') ?: 'http://127.0.0.1:8000/mpesa_callback.php',
        'result_url' => getenv('MPESA_RESULT_URL') ?: 'http://127.0.0.1:8000/mpesa_callback.php',
        'base_url' => getenv('MPESA_BASE_URL') ?: 'https://sandbox.safaricom.co.ke',
    ];
}

function getMpesaAccessToken($config) {
    if (!$config['consumer_key'] || !$config['consumer_secret']) {
        throw new RuntimeException('M-Pesa consumer key and secret are not configured.');
    }

    $authUrl = rtrim($config['base_url'], '/') . '/oauth/v1/generate?grant_type=client_credentials';
    $ch = curl_init($authUrl);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . base64_encode($config['consumer_key'] . ':' . $config['consumer_secret']),
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 5000);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 15000);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HEADER, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new RuntimeException('Unable to obtain M-Pesa access token.');
    }

    $payload = json_decode($response, true);
    if (empty($payload['access_token'])) {
        throw new RuntimeException('M-Pesa access token missing in response.');
    }

    return $payload['access_token'];
}

function initiateMpesaStkPush($phone, $amount, $accountReference, $transactionDesc, $config) {
    if (!$config['shortcode'] || !$config['passkey']) {
        throw new RuntimeException('M-Pesa STK shortcode and passkey are not configured.');
    }

    $timestamp = gmdate('YmdHis');
    $password = base64_encode($config['shortcode'] . $config['passkey'] . $timestamp);
    $phoneNumber = preg_replace('/\D+/', '', $phone);
    $normalizedPhone = substr($phoneNumber, 0, 1) === '0' ? '254' . substr($phoneNumber, 1) : $phoneNumber;

    $payload = [
        'BusinessShortCode' => $config['shortcode'],
        'Password' => $password,
        'Timestamp' => $timestamp,
        'TransactionType' => 'CustomerPayBillOnline',
        'Amount' => (int) round($amount),
        'PartyA' => $normalizedPhone,
        'PartyB' => $config['shortcode'],
        'PhoneNumber' => $normalizedPhone,
        'CallBackURL' => $config['callback_url'],
        'AccountReference' => $accountReference,
        'TransactionDesc' => $transactionDesc,
    ];

    $token = getMpesaAccessToken($config);
    $url = rtrim($config['base_url'], '/') . '/mpesa/stkpush/v1/processrequest';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 5000);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 20000);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new RuntimeException('STK push request failed.');
    }

    return json_decode($response, true);
}

function initiateMpesaBuyGoods($amount, $accountReference, $transactionDesc, $config) {
    if (!$config['shortcode']) {
        throw new RuntimeException('M-Pesa till/shortcode is not configured.');
    }

    $payload = [
        'amount' => (int) round($amount),
        'till' => $config['shortcode'],
        'account_reference' => $accountReference,
        'description' => $transactionDesc,
        'callback_url' => $config['callback_url'],
    ];

    return [
        'success' => true,
        'merchantRequestID' => 'pending-' . uniqid(),
        'checkoutRequestID' => 'buygoods-' . uniqid(),
        'customerMessage' => 'Buy Goods request prepared. Waiting for payment confirmation.',
        'payload' => $payload,
    ];
}

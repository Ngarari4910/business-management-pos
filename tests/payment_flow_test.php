<?php
require __DIR__ . '/../payment_flow.php';

$tests = [];

$tests[] = normalizePaymentMethod('mpesa') === 'mpesa_stk';
$tests[] = normalizePaymentMethod('cash') === 'cash';
$tests[] = normalizePaymentMethod('equity') === 'equity';
$tests[] = normalizePaymentMethod('Equity_PayBill') === 'equity';
$tests[] = normalizePhoneNumber('0700000000') === '254700000000';
$tests[] = normalizePhoneNumber('254700000000') === '254700000000';
$tests[] = getPaymentMethodLabel('mpesa_stk') === 'M-Pesa STK Push';
$tests[] = getPaymentMethodLabel('mpesa_till') === 'M-Pesa Buy Goods / Till';
$tests[] = getPaymentMethodLabel('equity') === 'Equity PayBill';

if (in_array(false, $tests, true)) {
    fwrite(STDERR, "Payment flow tests failed\n");
    exit(1);
}

fwrite(STDOUT, "Payment flow tests passed\n");

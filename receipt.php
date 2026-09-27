<?php
function formatCurrency($value) {
    return number_format(floatval($value), 2);
}

function padRight($text, $width) {
    return str_pad((string) $text, $width, ' ', STR_PAD_RIGHT);
}

function padLeft($text, $width) {
    return str_pad((string) $text, $width, ' ', STR_PAD_LEFT);
}

function generateThermalReceiptText($sale, $saleItems, $paymentMethod, $paymentReceiptNumber, $cashierName = 'Cashier', $amountTendered = null, $changeAmount = null, array $paymentBreakdown = []) {
    $lines = [];
    $verificationUrl = 'verify_receipt.php?receipt=' . urlencode($paymentReceiptNumber);
    $lines[] = 'CUSTOMER CASH SALE';
    $lines[] = '------------------------------';
    $lines[] = 'RCP: ' . $paymentReceiptNumber;
    $lines[] = 'Date: ' . date('d/m/Y H:i');
    $lines[] = '------------------------------';
    $lines[] = 'ITEM       AMT';
    $lines[] = '------------------------------';

    $subtotal = 0.0;
    foreach ($saleItems as $item) {
        $name = trim((string) ($item['product_name'] ?? ''));
        $quantity = floatval($item['quantity'] ?? 0);
        $unitPrice = floatval($item['unit_price'] ?? 0);
        $lineTotal = floatval($item['line_total'] ?? ($quantity * $unitPrice));
        $subtotal += $lineTotal;

        $shortName = substr($name, 0, 18);
        $wrappedName = wordwrap($shortName, 18, "\n", true);
        foreach (explode("\n", $wrappedName) as $lineName) {
            $lines[] = $lineName;
        }

        $qtyText = number_format($quantity, 0);
        $unitText = formatCurrency($unitPrice);
        $lineText = $qtyText . ' x ' . $unitText;
        $lines[] = padRight($lineText, 18) . padLeft(formatCurrency($lineTotal), 8);
    }

    $total = $subtotal;
    $tenderedAmount = $amountTendered !== null ? floatval($amountTendered) : $total;
    $changeValue = $changeAmount !== null ? floatval($changeAmount) : max(0.0, $tenderedAmount - $total);
    $paidAmount = $tenderedAmount;
    if (count($paymentBreakdown) > 1) {
        $paidAmount = array_reduce($paymentBreakdown, static function ($sum, $payment) {
            return $sum + floatval($payment['amount'] ?? 0);
        }, 0.0);
    }

    $lines[] = '------------------------------';
    $lines[] = 'Pay mthd: ' . (count($paymentBreakdown) > 1 ? 'CASH + EQUITY PAYBILL' : strtoupper(str_replace('_', '-', ucfirst($paymentMethod))));
    if (count($paymentBreakdown) > 1) {
        foreach ($paymentBreakdown as $payment) {
            $method = strtolower((string) ($payment['method'] ?? ''));
            $label = $method === 'equity' ? 'Equity' : strtoupper($method);
            $lines[] = $label . ': ' . padLeft(formatCurrency($payment['amount'] ?? 0), 10);
        }
    }
    $lines[] = 'Paid: ' . padLeft(formatCurrency($paidAmount), 10);
    $lines[] = 'Change: ' . padLeft(formatCurrency($changeValue), 8);
    $lines[] = '------------------------------';
    $lines[] = 'THANK YOU!';
    $lines[] = 'You were served by: ' . $cashierName;
    $lines[] = 'GOODS ONCE SOLD CANNOT BE REFUNDED';
    $lines[] = 'POWERED BY NOVASOFT SYSTEMS MERU © ' . date('Y') . ' SMARTPOS';

    return implode(PHP_EOL, $lines);
}

function printThermalReceipt($text, $filePath = null) {
    $file = $filePath ?? (__DIR__ . '/tmp/receipt.txt');
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($file, $text . PHP_EOL);
    return $file;
}

function generateShiftCloseStatementText($shift, array $sales, array $saleItems, float $openingCash, float $expectedCash, float $countedCash, float $variance, string $cashierName = 'Cashier', float $debtSales = 0.0, float $debtCollectedCash = 0.0, float $expectedCashIfDebtPaid = 0.0, float $cashPurchases = 0.0, float $cashExpenses = 0.0, float $openingCashAfterExpenses = 0.0, array $expenses = [], float $openingCashUsed = 0.0, float $cashSalesUsed = 0.0): string {
    $lines = [];
    $lines[] = 'SMART POS SYSTEM';
    $lines[] = 'SHIFT SALES STATEMENT';
    $lines[] = '------------------------------';
    $lines[] = 'Cashier: ' . $cashierName;
    $lines[] = 'Opened: ' . ($shift['started_at'] ?? $shift['created_at'] ?? '');
    $lines[] = 'Closed: ' . date('d/m/Y H:i');
    $lines[] = '------------------------------';
    $lines[] = 'ITEM            QTY  AMT  PAY';

    foreach ($saleItems as $item) {
        $name = substr(trim((string) ($item['product_name'] ?? '')), 0, 14);
        $quantity = number_format(floatval($item['quantity'] ?? 0), 0);
        $lineTotal = formatCurrency($item['line_total'] ?? 0);
        $paymentNorm = normalizePaymentMethod($item['payment_method'] ?? 'cash');
        $paymentCode = $paymentNorm === 'cash' ? 'CASH' : ($paymentNorm === 'mixed' ? 'MIXED' : 'PB');
        $lines[] = padRight($name, 15) . padLeft($quantity, 3) . padLeft($lineTotal, 8) . ' ' . $paymentCode;
    }

    $cashSalesTotal = 0.0;
    $paybillSalesTotal = 0.0;
    $mixedSalesTotal = 0.0;
    foreach ($sales as $sale) {
        $paymentNorm = normalizePaymentMethod($sale['payment_method'] ?? 'cash');
        if (array_key_exists('cash_amount', $sale) || array_key_exists('equity_amount', $sale)) {
            $cashSalesTotal += floatval($sale['cash_amount'] ?? 0);
            $paybillSalesTotal += floatval($sale['equity_amount'] ?? 0);
            if ($paymentNorm === 'mixed') {
                $mixedSalesTotal += floatval($sale['total_amount'] ?? 0);
            }
        } elseif ($paymentNorm === 'cash') {
            $cashSalesTotal += floatval($sale['total_amount'] ?? 0);
        } elseif ($paymentNorm === 'mixed') {
            $mixedSalesTotal += floatval($sale['total_amount'] ?? 0);
        } else {
            $paybillSalesTotal += floatval($sale['total_amount'] ?? 0);
        }
    }

    $lines[] = '------------------------------';
    $lines[] = 'CASH SALES: ' . padLeft(formatCurrency($cashSalesTotal), 12);
    $lines[] = 'PAYBILL SALES: ' . padLeft(formatCurrency($paybillSalesTotal), 10);
    $lines[] = 'MIXED SALES: ' . padLeft(formatCurrency($mixedSalesTotal), 12);
    $lines[] = 'DEBT SALES ISSUED: ' . padLeft(formatCurrency($debtSales), 8);
    $lines[] = 'DEBT COLLECTED: ' . padLeft(formatCurrency($debtCollectedCash), 8);
    $lines[] = 'OPENING CASH: ' . padLeft(formatCurrency($openingCash), 10);
    $lines[] = 'OPEN AFTER OUT: ' . padLeft(formatCurrency($openingCashAfterExpenses), 12);
    $lines[] = 'FROM OPENING: ' . padLeft(formatCurrency($openingCashUsed), 14);
    $lines[] = 'FROM SALES: ' . padLeft(formatCurrency($cashSalesUsed), 16);
    $lines[] = 'CASH PURCHASES: ' . padLeft(formatCurrency($cashPurchases), 9);
    $lines[] = 'CASH EXPENSES: ' . padLeft(formatCurrency($cashExpenses), 10);
    foreach ($expenses as $expense) {
        $title = substr(trim((string) ($expense['title'] ?? 'Expense')), 0, 18);
        $deducted = (float) ($expense['cash_drawer_amount'] ?? 0);
        $lines[] = padRight($title, 18) . ' ' . ($deducted > 0 ? 'DRAWER ' : 'NO DRAWER ') . formatCurrency($deducted);
    }
    $lines[] = 'EXPECTED CLOSE: ' . padLeft(formatCurrency($expectedCash), 8);
    if ($debtSales > 0) {
        $lines[] = 'IF DEBT PAID: ' . padLeft(formatCurrency($expectedCashIfDebtPaid), 8);
    }
    $lines[] = 'COUNTED CASH: ' . padLeft(formatCurrency($countedCash), 10);
    $lines[] = 'VARIANCE: ' . padLeft(formatCurrency($variance), 12);
    $lines[] = '------------------------------';
    $lines[] = 'THANK YOU!';
    $lines[] = 'SMART POS SYSTEM';

    return implode(PHP_EOL, $lines);
}

function generateShiftAnalysisText(array $shift, array $sales, float $debtSales, float $debtCollectedCash, float $openingCash, float $openingCashAfterOutflow, float $openingCashUsed, float $cashSalesUsed, float $cashPurchases, float $cashExpenses, array $expenses, float $expectedCash, float $countedCash, float $variance, string $cashierName = 'Cashier'): string {
    $cashSalesTotal = 0.0;
    $paybillSalesTotal = 0.0;
    $mixedSalesTotal = 0.0;
    foreach ($sales as $sale) {
        $paymentNorm = normalizePaymentMethod($sale['payment_method'] ?? 'cash');
        if (array_key_exists('cash_amount', $sale) || array_key_exists('equity_amount', $sale)) {
            $cashSalesTotal += (float) ($sale['cash_amount'] ?? 0);
            $paybillSalesTotal += (float) ($sale['equity_amount'] ?? 0);
            if ($paymentNorm === 'mixed') {
                $mixedSalesTotal += (float) ($sale['total_amount'] ?? 0);
            }
        } elseif ($paymentNorm === 'cash') {
            $cashSalesTotal += (float) ($sale['total_amount'] ?? 0);
        } elseif ($paymentNorm === 'mixed') {
            $mixedSalesTotal += (float) ($sale['total_amount'] ?? 0);
        } else {
            $paybillSalesTotal += (float) ($sale['total_amount'] ?? 0);
        }
    }

    $lines = [
        'SMART POS SYSTEM',
        'SHIFT ANALYSIS',
        '------------------------------',
        'Cashier: ' . $cashierName,
        'Opened: ' . ($shift['started_at'] ?? $shift['created_at'] ?? ''),
        '------------------------------',
        'SALES INFLOW',
        'CASH INFLOW: ' . padLeft(formatCurrency($cashSalesTotal), 14),
        'EQUITY PAYBILL: ' . padLeft(formatCurrency($paybillSalesTotal), 10),
        'MIXED SALES INFLOW: ' . padLeft(formatCurrency($mixedSalesTotal), 3),
        '------------------------------',
        'DEBT ACTIVITY',
        'DEBT ISSUED: ' . padLeft(formatCurrency($debtSales), 16),
        'DEBT COLLECTED: ' . padLeft(formatCurrency($debtCollectedCash), 9),
        '------------------------------',
        'CASH POSITION (SEPARATE)',
        'OPENING CASH: ' . padLeft(formatCurrency($openingCash), 13),
        '------------------------------',
        'CASH OUTFLOWS',
        'CASH PURCHASES: ' . padLeft(formatCurrency($cashPurchases), 9),
        'CASH EXPENSES: ' . padLeft(formatCurrency($cashExpenses), 10),
    ];
    foreach ($expenses as $expense) {
        $title = substr(trim((string) ($expense['title'] ?? 'Expense')), 0, 18);
        $deducted = (float) ($expense['cash_drawer_amount'] ?? 0);
        $lines[] = padRight($title, 18) . ' ' . ($deducted > 0 ? 'DRAWER ' : 'NO DRAWER ') . formatCurrency($deducted);
    }
    $lines = array_merge($lines, [
        '------------------------------',
        'OUTFLOW FUNDING',
        'FROM OPENING: ' . padLeft(formatCurrency($openingCashUsed), 13),
        'FROM SALES: ' . padLeft(formatCurrency($cashSalesUsed), 16),
        'OPEN AFTER OUT: ' . padLeft(formatCurrency($openingCashAfterOutflow), 10),
        '------------------------------',
        'FINAL RECONCILIATION',
        'EXPECTED CLOSE: ' . padLeft(formatCurrency($expectedCash), 8),
        'COUNTED CASH: ' . padLeft(formatCurrency($countedCash), 10),
        'VARIANCE: ' . padLeft(formatCurrency($variance), 13),
        '------------------------------',
        'SMART POS SYSTEM',
    ]);
    return implode(PHP_EOL, $lines);
}

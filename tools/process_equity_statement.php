<?php
require __DIR__ . '/../db.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$uploadId = isset($argv[1]) ? (int) $argv[1] : 0;
if ($uploadId <= 0) {
    exit("Usage: php tools/process_equity_statement.php <upload_id>\n");
}

$stmt = $pdo->prepare('SELECT * FROM equity_statement_uploads WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $uploadId]);
$upload = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$upload) {
    exit("No upload found for ID {$uploadId}\n");
}

$sourcePath = __DIR__ . '/../uploads/equity_statements/' . basename((string) $upload['filename']);
if (!is_file($sourcePath)) {
    $pdo->prepare('UPDATE equity_statement_uploads SET status = :status WHERE id = :id')->execute([
        'status' => 'missing_file',
        'id' => $uploadId,
    ]);
    exit("Statement file not found for upload {$uploadId}\n");
}

$fileType = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
$fileSize = filesize($sourcePath);
if ($fileSize === false || $fileSize > 10 * 1024 * 1024) {
    $pdo->prepare('UPDATE equity_statement_uploads SET status = :status WHERE id = :id')->execute([
        'status' => 'file_too_large',
        'id' => $uploadId,
    ]);
    exit("Statement file exceeds the 10 MB limit for upload {$uploadId}\n");
}
$rows = [];
$maxRows = 10000;
if ($fileType === 'pdf') {
    $pythonScript = __DIR__ . '/extract_pdf_text.py';
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($pythonScript) . ' ' . escapeshellarg($sourcePath);
    $output = shell_exec($command);
    if (is_string($output)) {
        $lines = preg_split('/\r\n|\r|\n/', $output);
        foreach ($lines as $line) {
            if (count($rows) >= $maxRows) {
                break;
            }
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/([A-Za-z0-9]{3,})\s*([0-9,\.]+)\s*(\d{4}-\d{2}-\d{2}|\d{2}\/\d{2}\/\d{4})/i', $line, $matches)) {
                $rows[] = [
                    'transaction_code' => strtoupper(trim(preg_replace('/[^A-Za-z0-9]/', '', $matches[1]))),
                    'amount' => floatval(str_replace([',', ' '], '', preg_replace('/[^0-9.\-]/', '', $matches[2]))),
                    'statement_date' => $matches[3],
                ];
            }
        }
    }
} else {
    $handle = fopen($sourcePath, 'r');
    if ($handle !== false) {
        $firstLine = fgets($handle);
        if ($firstLine !== false) {
            $delimiter = strpos($firstLine, ';') > strpos($firstLine, ',') ? ';' : ',';
            rewind($handle);
            $header = fgetcsv($handle, 0, $delimiter);
            if ($header !== false) {
                $header = array_map('strtolower', array_map('trim', $header));
                $codeIndex = null;
                $amountIndex = null;
                $dateIndex = null;
                foreach ($header as $index => $column) {
                    if ($codeIndex === null && preg_match('/code|txn|transaction|reference|ref/i', $column)) {
                        $codeIndex = $index;
                    }
                    if ($amountIndex === null && preg_match('/amount|amt|value|paid/i', $column)) {
                        $amountIndex = $index;
                    }
                    if ($dateIndex === null && preg_match('/date|day|posted|time/i', $column)) {
                        $dateIndex = $index;
                    }
                }
                if ($codeIndex === null || $amountIndex === null) {
                    $codeIndex = 0;
                    $amountIndex = 1;
                }
                while (($cols = fgetcsv($handle, 0, $delimiter)) !== false && count($rows) < $maxRows) {
                    if (!isset($cols[$codeIndex], $cols[$amountIndex])) {
                        continue;
                    }
                    $transactionCode = strtoupper(trim(preg_replace('/[^A-Za-z0-9]/', '', (string) $cols[$codeIndex])));
                    $amount = floatval(str_replace([',', ' '], '', preg_replace('/[^0-9.\-]/', '', (string) $cols[$amountIndex])));
                    if ($transactionCode === '' || $amount <= 0) {
                        continue;
                    }
                    $rows[] = [
                        'transaction_code' => $transactionCode,
                        'amount' => $amount,
                        'statement_date' => $dateIndex !== null && isset($cols[$dateIndex]) ? trim((string) $cols[$dateIndex]) : '',
                    ];
                }
            }
        }
        fclose($handle);
    }
}

$duplicateCodes = [];
$seen = [];
foreach ($rows as $row) {
    if (isset($seen[$row['transaction_code']])) {
        $duplicateCodes[$row['transaction_code']] = true;
    }
    $seen[$row['transaction_code']] = true;
}

$pdo->prepare('UPDATE equity_statement_uploads SET total_transactions = :total_transactions, duplicate_codes_count = :duplicate_codes_count, status = :status WHERE id = :id')->execute([
    'total_transactions' => count($rows),
    'duplicate_codes_count' => count($duplicateCodes),
    'status' => 'processed',
    'id' => $uploadId,
]);

$matchedCount = 0;
$unmatchedBankCount = 0;
$posUnmatchedCount = 0;
$paymentQuery = $pdo->prepare('SELECT id, amount, status, created_at FROM sale_payments WHERE payment_method = :method AND transaction_code = :code AND status IN ("recorded", "amount_mismatch") ORDER BY id ASC LIMIT 1');
$updatePayment = $pdo->prepare('UPDATE sale_payments SET status = :status, verified_by = :verified_by, verified_at = NOW(), statement_filename = :statement_filename WHERE id = :id');
$insertRow = $pdo->prepare('INSERT INTO equity_statement_rows (upload_id, transaction_code, amount, matched_payment_id, status, statement_date) VALUES (:upload_id, :transaction_code, :amount, :matched_payment_id, :status, :statement_date)');

foreach ($rows as $row) {
    $paymentQuery->execute(['method' => 'equity', 'code' => $row['transaction_code']]);
    $payment = $paymentQuery->fetch(PDO::FETCH_ASSOC);
    $status = 'unmatched';
    $matchedPaymentId = null;

    if ($payment) {
        $matchedPaymentId = (int) $payment['id'];
        $paymentDate = (string) ($payment['created_at'] ?? '');
        $statementDate = trim((string) ($row['statement_date'] ?? ''));
        $dateMatches = $statementDate === '' || $paymentDate === '' || date('Y-m-d', strtotime($statementDate)) === date('Y-m-d', strtotime($paymentDate));
        if (abs((float) $payment['amount'] - (float) $row['amount']) < 0.01 && $dateMatches) {
            $status = 'matched';
            $matchedCount++;
            $updatePayment->execute([
                'status' => 'verified',
                'verified_by' => 'system_job',
                'statement_filename' => basename($sourcePath),
                'id' => $matchedPaymentId,
            ]);
        } elseif ($dateMatches) {
            $status = 'amount_mismatch';
            $updatePayment->execute([
                'status' => 'amount_mismatch',
                'verified_by' => 'system_job',
                'statement_filename' => basename($sourcePath),
                'id' => $matchedPaymentId,
            ]);
        } else {
            $status = 'date_mismatch';
        }
    } else {
        $unmatchedBankCount++;
    }

    $insertRow->execute([
        'upload_id' => $uploadId,
        'transaction_code' => $row['transaction_code'],
        'amount' => $row['amount'],
        'matched_payment_id' => $matchedPaymentId,
        'status' => $status,
        'statement_date' => $row['statement_date'] ?? '',
    ]);
}

if (count($seen) === 0) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM sale_payments WHERE payment_method = :method AND status = :status');
    $stmt->execute(['method' => 'equity', 'status' => 'recorded']);
    $posUnmatchedCount = (int) $stmt->fetchColumn();
} else {
    $paramPlaceholders = implode(',', array_fill(0, count($seen), '?'));
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM sale_payments WHERE payment_method = ? AND status = ? AND transaction_code NOT IN (' . $paramPlaceholders . ')');
    $params = array_merge(['equity', 'recorded'], array_keys($seen));
    $stmt->execute($params);
    $posUnmatchedCount = (int) $stmt->fetchColumn();
}

$pdo->prepare('UPDATE equity_statement_uploads SET matched_count = :matched_count, unmatched_pos_count = :unmatched_pos_count, unmatched_bank_count = :unmatched_bank_count, status = :status WHERE id = :id')->execute([
    'matched_count' => $matchedCount,
    'unmatched_pos_count' => $posUnmatchedCount,
    'unmatched_bank_count' => $unmatchedBankCount,
    'status' => 'processed',
    'id' => $uploadId,
]);

echo "Processed Equity statement upload {$uploadId} with " . count($rows) . " rows\n";

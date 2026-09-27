<?php
$baseDir = __DIR__;
$defaultPath = $baseDir . '/tmp/receipt.txt';

$requestedPath = trim($_GET['path'] ?? '');
$receiptPath = '';

if ($requestedPath !== '') {
    $requestedPath = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $requestedPath);
    if (str_starts_with($requestedPath, $baseDir . DIRECTORY_SEPARATOR)) {
        $receiptPath = $requestedPath;
    }
}

if ($receiptPath === '') {
    $receiptPath = $defaultPath;
}

if (!is_file($receiptPath)) {
    http_response_code(404);
    echo 'Receipt not found.';
    exit;
}

$receiptText = file_get_contents($receiptPath);
$logoUrl = '';
$logoPath = $baseDir . '/logo.png';

if (is_file($logoPath) && preg_match('/\.(png|jpe?g|gif|webp)$/i', $logoPath)) {
    $logoUrl = 'logo.png';
}

$displayText = preg_replace('/^\[LOGO\]$/m', '', $receiptText);
// Remove duplicated header lines if present at the top of the receipt text
$displayText = preg_replace('/\A\s*(SMART POS SYSTEM\s*\r?\n\s*Novasoft Technologies\s*\r?\n)/i', '', $displayText);
// Remove branded receipt header lines such as store name and phone number
$displayText = preg_replace('/\A\s*(SMART POS DEMO\s*\r?\n\s*Tel .*?\s*\r?\n)/i', '', $displayText);
// Remove any trailing parenthetical block that contains the POS branding or dev name
$displayText = preg_replace('/\([^)]*(SMART POS SYSTEM|Novasoft Technologies)[^)]*\)\s*$/is', '', $displayText);
$displayLines = preg_split('/\r?\n/', trim($displayText));
$displayTitle = array_shift($displayLines) ?? '';
$displayBody = implode(PHP_EOL, $displayLines);
$displayText = htmlspecialchars($displayText, ENT_QUOTES, 'UTF-8');
$displayTitle = htmlspecialchars($displayTitle, ENT_QUOTES, 'UTF-8');
$displayBody = htmlspecialchars($displayBody, ENT_QUOTES, 'UTF-8');
$displayBody = preg_replace(
    '/^(Pay mthd:.*|Paid:.*|Change:.*|GOODS ONCE SOLD CANNOT BE REFUNDED)$/m',
    '<span class="receipt-payment">$1</span>',
    $displayBody
);
$verificationUrl = '';
// Try to extract a receipt identifier from the printed receipt text.
// Accept common forms like "RCP-2026...", "RCP: ...", or lines like "Receipt No 12345".
$rcp = '';
if (preg_match('/\bRCP(?:[:\s-])?([A-Za-z0-9\-_]+)/i', $receiptText, $m)) {
  $rcp = trim($m[1]);
} elseif (preg_match('/receipt(?:\s*(?:no|number))?[:\s-]*([A-Za-z0-9\-_]+)/i', $receiptText, $m2)) {
  $rcp = trim($m2[1]);
}
if ($rcp !== '') {
  $verificationUrl = 'verify_receipt.php?receipt=' . urlencode($rcp);
}
?>
<!doctype html>
<html lang="en">
<head>
  <link rel="icon" type="image/png" href="logo.png">
  <meta charset="utf-8">

  <link rel="stylesheet" href="assets/css/splash-screen.css">
  <style>
    body {
      font-family: monospace;
      font-size: 13px;
      margin: 0;
      padding: 16px;
      background: #fff;
      color: #111;
      display: flex;
      justify-content: center;
    }
    .receipt-wrapper {
      width: 100%;
      max-width: 240px;
    }
    .logo-wrap {
      text-align: center;
      margin: 0 auto 12px;
      width: 100%;
    }
    .logo-wrap img {
      display: block;
      max-width: 140px;
      width: 140px;
      height: auto;
      margin: 0 auto;
    }
    .receipt-phone {
      margin-top: 6px;
      text-align: center;
      font-family: sans-serif;
      font-size: 12px;
      line-height: 1.3;
      color: #111;
    }
    .receipt-branch {
      margin-top: 6px;
      text-align: center;
      font-family: sans-serif;
      font-size: 13px;
      font-weight: 600;
      line-height: 1.3;
      color: #111;
    }
    .receipt-original {
      margin-top: 4px;
      text-align: center;
      font-family: sans-serif;
      font-size: 16px;
      font-weight: 700;
      line-height: 1.3;
      color: #111;
    }
    .receipt-title {
      margin: 0;
      font-weight: 700;
    }
    .receipt-payment {
      display: block;
      font-size: inherit;
      font-weight: 700;
      line-height: 1.05;
    }
    pre {
      margin: 0;
      white-space: pre-wrap;
      word-break: break-word;
    }
    @media print {
      body {
        padding: 0;
      }
      .receipt-wrapper {
        max-width: 240px;
      }
    }
  </style>
</head>
<body>
  <div class="receipt-wrapper">
    <?php if ($logoUrl !== ''): ?>
      <div class="logo-wrap">
        <img src="<?php echo htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Store logo">
        <div class="receipt-branch">PILLARS MITUNGUU BRANCH</div>
        <div class="receipt-phone">tell: 0700000000</div>
        <div class="receipt-original">ORIGINAL</div>
      </div>
    <?php endif; ?>
    <pre class="receipt-title"><?php echo $displayTitle; ?></pre>
    <pre><?php echo $displayBody; ?></pre>
    <?php if ($verificationUrl !== ''): ?>
      <div style="margin-top:16px;text-align:center;">
        <div id="qrClient" style="display:inline-block;margin:0 auto;width:80px;height:80px;"></div>
    </div>
      <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
      <script>
        (function(){
          try {
            var url = <?php echo json_encode($verificationUrl, JSON_UNESCAPED_SLASHES); ?>;
            if (url && window.QRCode) {
              var el = document.getElementById('qrClient');
              if (el) {
                // clear
                el.innerHTML = '';
                new QRCode(el, { text: url, width: 70, height: 70 });
              }
            }
          } catch (e) {
            console.warn('QR render failed', e);
          }
        })();
      </script>
    <?php endif; ?>
  </div>

  <script>
    window.addEventListener('load', () => {
      const splashScreen = document.getElementById('splashScreen');
      if (splashScreen) {
        splashScreen.classList.add('hidden');
        document.body.classList.remove('splash-loading');
      }
      setTimeout(() => {
        window.print();
      }, 300);
    });
  </script>
</body>
</html>

<?php
session_start();
require __DIR__ . '/security.php';
require __DIR__ . '/db.php';
require __DIR__ . '/account_helpers.php';

$token = trim((string) ($_GET['token'] ?? ''));
$user = null;
$errors = [];
$success = false;

if ($token !== '') {
    $user = getPendingUserByToken($pdo, $token);
}

if (!$user) {
    $errors[] = 'Activation link is invalid or has expired.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user) {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if ($username === '') {
        $errors[] = 'Username is required.';
    } elseif (isUsernameTaken($pdo, $username)) {
        $errors[] = 'That username is already taken.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match.';
    }

    if (empty($errors)) {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if (activateCashierAccount($pdo, intval($user['id']), $username, $passwordHash)) {
            $success = true;
        } else {
            $errors[] = 'Unable to activate the account. The token may already have been used.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>  <link rel="icon" type="image/png" href="logo.png">  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Activate Cashier Account — POS2</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="assets/css/splash-screen.css">
</head>
<body class="min-h-screen bg-slate-50 splash-loading">
  <div class="splash-screen" id="splashScreen">
    <div class="splash-screen-logo">
      <img src="logo.png" alt="Logo">
    </div>
    <div class="splash-screen-spinner"></div>
    <div class="splash-screen-text">LOADING</div>
  </div>
  <div class="flex min-h-screen items-center justify-center px-4 py-10">
    <div class="w-full max-w-2xl rounded-3xl border border-slate-200 bg-white p-8 shadow-sm">
      <?php if ($success): ?>
        <div class="rounded-3xl border border-emerald-200 bg-emerald-50 p-6 text-emerald-900">
          <h1 class="text-2xl font-semibold">Account Activated</h1>
          <p class="mt-3 text-sm">Your cashier account has been successfully activated.</p>
          <div class="mt-6 space-y-2">
            <p><span class="font-semibold">Username:</span> <?php echo htmlspecialchars($username); ?></p>
            <p><span class="font-semibold">Role:</span> Cashier</p>
            <p><span class="font-semibold">Branch:</span> <?php echo htmlspecialchars($user['branch'] ?? 'N/A'); ?></p>
          </div>
          <a href="cashier_login.php" class="inline-flex mt-6 rounded-3xl bg-slate-900 px-6 py-3 text-sm font-semibold text-white">Go to Login</a>
        </div>
      <?php else: ?>
        <h1 class="text-3xl font-bold text-slate-900">Activate Your Cashier Account</h1>
        <?php if ($user): ?>
          <p class="mt-2 text-sm text-slate-500">Welcome, <?php echo htmlspecialchars($user['full_name']); ?>. Complete your account setup below.</p>
          <div class="mt-6 rounded-3xl border border-slate-200 bg-slate-50 p-6">
            <p class="text-sm text-slate-700"><strong>Employee ID:</strong> <?php echo htmlspecialchars($user['employee_id'] ?? 'N/A'); ?></p>
            <p class="text-sm text-slate-700 mt-2"><strong>Role:</strong> Cashier</p>
            <p class="text-sm text-slate-700 mt-2"><strong>Branch:</strong> <?php echo htmlspecialchars($user['branch'] ?? 'N/A'); ?></p>
          </div>
          <?php if (!empty($errors)): ?>
            <div class="mt-6 rounded-3xl border border-rose-200 bg-rose-50 p-4 text-rose-900">
              <ul class="list-disc pl-5 text-sm">
                <?php foreach ($errors as $error): ?>
                  <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>
          <form method="POST" class="mt-6 space-y-5">
            <label class="block text-sm text-slate-700">
              <span class="mb-1 block font-medium">Create Username *</span>
              <input name="username" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required class="w-full rounded-3xl border border-slate-200 px-4 py-3" />
            </label>
            <label class="block text-sm text-slate-700">
              <span class="mb-1 block font-medium">Create Password *</span>
              <input type="password" name="password" required class="w-full rounded-3xl border border-slate-200 px-4 py-3" />
            </label>
            <label class="block text-sm text-slate-700">
              <span class="mb-1 block font-medium">Confirm Password *</span>
              <input type="password" name="confirm_password" required class="w-full rounded-3xl border border-slate-200 px-4 py-3" />
            </label>
            <button type="submit" class="rounded-3xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white">Activate Account</button>
          </form>
        <?php else: ?>
          <div class="rounded-3xl border border-rose-200 bg-rose-50 p-6 text-rose-900">
            <h2 class="text-xl font-semibold">Activation Failed</h2>
            <p class="mt-2 text-sm">The activation link is invalid, expired, or already used.</p>
            <a href="cashier_login.php" class="inline-flex mt-4 rounded-3xl bg-slate-900 px-6 py-3 text-sm font-semibold text-white">Go to Login</a>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
  <script>
    window.addEventListener('load', function() {
      const splashScreen = document.getElementById('splashScreen');
      if (splashScreen) {
        splashScreen.classList.add('hidden');
        document.body.classList.remove('splash-loading');
      }
    });
  </script>
</body>
</html>

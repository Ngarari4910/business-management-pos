<?php
session_start();
require __DIR__ . '/security.php';
require __DIR__ . '/db.php';
require __DIR__ . '/account_helpers.php';

$errors = [];
$csrfToken = csrfToken();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $throttle = getLoginThrottle($pdo, $username);

    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
      $errors[] = 'Your login session expired. Refresh the page and try again.';
      $user = null;
    } elseif ($throttle['blocked']) {
      $errors[] = 'Too many failed attempts. Try again in 15 minutes.';
      $user = null;
    } else {
      $user = getUserByUsername($pdo, $username);
    }

    if ($user && password_verify($password, $user['password_hash']) && $user['role'] === 'admin' && $user['status'] === 'active') {
        clearLoginFailures($pdo, $username);
        maybeRehashPassword($pdo, $user, $password);
        establishAuthenticatedSession($user);
        header('Location: admin.php');
        exit;
    }

    if (empty($errors)) {
      recordLoginFailure($pdo, $username);
      $errors[] = 'Invalid admin username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>  <link rel="icon" type="image/jpeg" href="colour_logo.jpg">  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin Login — SMART POS SYSTEM</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="assets/css/splash-screen.css">
</head>
<body class="min-h-screen bg-slate-50 splash-loading">
  <div class="splash-screen" id="splashScreen">
    <div class="splash-screen-logo">
      <img src="colour_logo.jpg" alt="Logo">
    </div>
    <div class="splash-screen-spinner"></div>
    <div class="splash-screen-text">LOADING</div>
  </div>
  <div class="flex min-h-screen items-center justify-center px-4 py-10">
    <div class="w-full max-w-md rounded-3xl border border-slate-200 bg-white p-8 shadow-sm">
      <div class="mb-5 flex items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-3">
        <img src="colour_logo.jpg" alt="POS2 logo" class="h-12 w-12 rounded-2xl object-cover shadow-sm">
        <div>
          <p class="text-base font-semibold text-slate-900">SMART POS SYSTEM</p>
          <p class="text-sm text-slate-500">Admin sign in</p>
        </div>
      </div>
      <h1 class="text-2xl font-semibold text-slate-900">Admin Login</h1>
      <p class="mt-2 text-sm text-slate-500">Use your admin credentials to access the manager dashboard.</p>

      <?php if (!empty($errors)): ?>
        <div class="mt-4 rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
          <?php echo htmlspecialchars($errors[0]); ?>
        </div>
      <?php endif; ?>

      <form method="POST" class="mt-6 space-y-4">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <label class="block text-sm text-slate-700">
          <span class="mb-1 block font-medium">Username</span>
          <input name="username" required autocomplete="username" class="w-full rounded-2xl border border-slate-200 px-4 py-3" />
        </label>
        <label class="block text-sm text-slate-700">
          <span class="mb-1 block font-medium">Password</span>
          <input type="password" name="password" required autocomplete="current-password" class="w-full rounded-2xl border border-slate-200 px-4 py-3" />
        </label>
        <button type="submit" class="w-full rounded-2xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white">Sign in</button>
      </form>
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

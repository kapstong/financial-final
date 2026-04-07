<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_once 'includes/csrf.php';
require_once 'includes/two_factor_auth.php';
require_once 'includes/device_detector.php';

// Check if user is in pending 2FA verification state
if (!isset($_SESSION['pending_2fa_user_id'])) {
    header('Location: index.php');
    exit();
}

$userId = $_SESSION['pending_2fa_user_id'];
$user = $_SESSION['pending_2fa_user'];
$twoFA = TwoFactorAuth::getInstance();

$error = '';
$info = '';

// Get 2FA configuration
$config = $twoFA->get2FAConfig($userId);
$isTwoFALocked = $twoFA->shouldLockAccount($userId);

if (!$config) {
    $error = '2FA is not properly configured for this account.';
} elseif ($isTwoFALocked) {
    $error = 'Too many invalid verification attempts. Please wait before trying again.';
}

// Handle form submission
if ($_POST) {
    if (!csrf_verify_request()) {
        $error = 'Invalid CSRF token. Please reload the page.';
    } else {
        $code = trim($_POST['code'] ?? '');

        if ($twoFA->shouldLockAccount($userId)) {
            $error = 'Too many invalid verification attempts. Please wait before trying again.';
        } elseif (empty($code)) {
            $error = 'Please enter your verification code.';
        } else {
            // Verify the code
            $result = $twoFA->verify2FACode($userId, $code);

            if ($result['success']) {
                // 2FA verification successful
                session_regenerate_id(true);
                $twoFA->mark2FAVerified($userId);

                // Restore the user session
                $_SESSION['user'] = $user;

                // Clear pending 2FA data
                unset($_SESSION['pending_2fa_user_id']);
                unset($_SESSION['pending_2fa_user']);

                // Log the session using stored procedure
                require_once 'includes/database.php';
                $db = Database::getInstance()->getConnection();

                try {
                    $stmt = $db->prepare("CALL sp_log_login_session(?, ?, ?, @session_id)");
                    $stmt->execute([
                        $userId,
                        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                        $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
                    ]);
                } catch (Exception $e) {
                    // Fallback if stored procedure doesn't exist yet
                    error_log("Error calling sp_log_login_session: " . $e->getMessage());
                }

                $devicePayload = $_SESSION['pending_device'] ?? [];
                $loginMethod = $_SESSION['pending_login_method'] ?? 'password';
                $deviceInfo = detect_device_info($_SERVER['HTTP_USER_AGENT'] ?? '');
                $deviceLabel = build_device_label($devicePayload, $_SERVER['HTTP_USER_AGENT'] ?? '');
                Logger::getInstance()->logUserAction(
                    $loginMethod === 'qr' ? 'QR Login' : ($loginMethod === 'sso' ? 'SSO Login' : 'User Login'),
                    'login_sessions',
                    null,
                    null,
                    [
                        'login_method' => $loginMethod,
                        'device_label' => $deviceLabel,
                        'device_type' => $devicePayload['device_type'] ?? $deviceInfo['device_type'],
                        'os' => $devicePayload['device_os'] ?? $deviceInfo['os'],
                        'browser' => $devicePayload['device_browser'] ?? $deviceInfo['browser'],
                        'platform' => $devicePayload['device_platform'] ?? null,
                        'model' => $devicePayload['device_model'] ?? null
                    ]
                );
                unset($_SESSION['pending_device']);
                unset($_SESSION['pending_login_method']);

                // Redirect based on role
                $role = strtolower($user['role_name'] ?? '');
                if ($role === 'super_admin') {
                    $target = 'superadmin/index.php';
                } elseif ($role === 'admin') {
                    $target = 'admin/index.php';
                } else {
                    $target = 'staff/index.php';
                }

                header('Location: ' . $target);
                exit();
            } else {
                $error = $result['error'] ?? 'Invalid verification code. Please try again.';
            }
        }
    }
}

// Handle cancel action
if (isset($_GET['cancel'])) {
    unset($_SESSION['pending_2fa_user_id']);
    unset($_SESSION['pending_2fa_user']);
    unset($_SESSION['pending_device']);
    unset($_SESSION['pending_login_method']);
    header('Location: index.php?info=2fa_cancelled');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
<meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token()); ?>">
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title>ATIERA — Two-Factor Authentication</title>
<link rel="icon" href="logo2.png">
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="responsive.css">

<style>
  :root{
    --blue-600:#1b2f73; --blue-700:#15265e; --blue-800:#0f1c49; --blue-a:#2342a6;
    --gold:#d4af37; --ink:#0f172a; --muted:#64748b;
    --ring:0 0 0 3px rgba(35,66,166,.28);
    --card-bg: rgba(255,255,255,.95); --card-border: rgba(226,232,240,.9);
  }

  body{
    min-height:100svh; margin:0; color:var(--ink);
    background:
      radial-gradient(70% 60% at 8% 10%, rgba(255,255,255,.18) 0, transparent 60%),
      radial-gradient(40% 40% at 100% 0%, rgba(212,175,55,.08) 0, transparent 40%),
      linear-gradient(140deg, rgba(15,28,73,1) 50%, rgba(255,255,255,1) 50%);
  }

  .card{
    background:var(--card-bg); -webkit-backdrop-filter: blur(12px); backdrop-filter: blur(12px);
    border:1px solid var(--card-border); border-radius:18px; box-shadow:0 16px 48px rgba(2,6,23,.18);
  }

  .input{
    width:100%; border:1px solid #e5e7eb; border-radius:12px; background:#fff;
    padding:1rem; outline:none; color:#0f172a; transition:border-color .15s, box-shadow .15s;
    text-align: center; font-size: 1.5rem; letter-spacing: 0.5rem; font-family: monospace;
  }
  .input:focus{ border-color:var(--blue-a); box-shadow:var(--ring) }

  .btn{
    width:100%; display:inline-flex; align-items:center; justify-content:center; gap:.6rem;
    background:linear-gradient(180deg, var(--blue-600), var(--blue-800));
    color:#fff; font-weight:800; border-radius:14px; padding:.95rem 1rem; border:1px solid rgba(255,255,255,.06);
    transition:transform .08s ease, filter .15s ease, box-shadow .2s ease; box-shadow:0 8px 18px rgba(2,6,23,.18);
  }
  .btn:hover{ filter:saturate(1.08); box-shadow:0 12px 26px rgba(2,6,23,.26); }
  .btn:active{ transform:translateY(1px) scale(.99); }

  .alert{ border-radius:12px; padding:.65rem .8rem; font-size:.9rem; margin-bottom: 1rem; }
  .alert-error{ border:1px solid #fecaca; background:#fef2f2; color:#b91c1c }
  .alert-info{ border:1px solid #c7d2fe; background:#eef2ff; color:#3730a3 }
</style>
</head>

<body class="grid place-items-center p-6 md:p-10">
  <?php include_once __DIR__ . '/includes/loading_screen.php'; ?>
  <main class="w-full max-w-md">
    <div class="card p-6 sm:p-8">
      <div class="text-center mb-6">
        <img src="logo.png" alt="ATIERA" class="h-16 w-auto mx-auto mb-4">
        <h3 class="text-xl font-semibold mb-1">Two-Factor Authentication</h3>
        <p class="text-sm text-slate-500">Enter the verification code from your authenticator app</p>
      </div>

      <?php if ($error): ?>
        <div class="alert alert-error" role="alert">
          <?php echo htmlspecialchars($error); ?>
        </div>
      <?php endif; ?>

      <?php if ($info): ?>
        <div class="alert alert-info" role="alert">
          <?php echo htmlspecialchars($info); ?>
        </div>
      <?php endif; ?>

      <?php if ($config): ?>
      <form method="POST" class="space-y-4" novalidate>
        <?php csrf_input(); ?>

        <div>
          <label for="code" class="block text-sm font-medium text-slate-700 mb-2 text-center">
            Verification Code
          </label>
          <input
            id="code"
            name="code"
            type="text"
            inputmode="numeric"
            pattern="[0-9]*"
            maxlength="6"
            class="input"
            placeholder="000000"
            required
            autofocus
            autocomplete="one-time-code"
            <?php echo $isTwoFALocked ? 'disabled' : ''; ?>
          >
          <p class="text-xs text-slate-500 mt-2 text-center">
            Enter the 6-digit code from your authenticator app
          </p>
        </div>

        <button type="submit" class="btn" <?php echo $isTwoFALocked ? 'disabled' : ''; ?>>
          <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
          <span>Verify</span>
        </button>

        <div class="text-center mt-4">
          <a href="?cancel=1" class="text-sm text-slate-600 hover:text-slate-800">
            Cancel and return to login
          </a>
        </div>
      </form>
      <?php else: ?>
        <div class="alert alert-error">
          <p>2FA is not properly configured for your account.</p>
          <p class="mt-2"><a href="?cancel=1" class="underline">Return to login</a></p>
        </div>
      <?php endif; ?>

      <div class="mt-6 pt-6 border-t border-slate-200">
        <p class="text-xs text-center text-slate-500">
          Having trouble? Contact your system administrator.
        </p>
      </div>
    </div>
  </main>

<script>
// Auto-submit when 6 digits entered
const codeInput = document.getElementById('code');
if (codeInput) {
  codeInput.addEventListener('input', function(e) {
    // Only allow numbers
    this.value = this.value.replace(/[^0-9]/g, '');

    // Auto-submit when 6 digits are entered
    if (this.value.length === 6) {
      // Small delay for better UX
      setTimeout(() => {
        this.form.submit();
      }, 300);
    }
  });

  // Paste support
  codeInput.addEventListener('paste', function(e) {
    const paste = (e.clipboardData || window.clipboardData).getData('text');
    const numbers = paste.replace(/[^0-9]/g, '').substring(0, 6);
    this.value = numbers;
    if (numbers.length === 6) {
      setTimeout(() => {
        this.form.submit();
      }, 300);
    }
    e.preventDefault();
  });
}
</script>
<script src="includes/tab_persistence.js?v=1"></script>
</body>
</html>


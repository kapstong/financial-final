<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/device_detector.php';
$auth = new Auth();
$recaptchaConfigured = RECAPTCHA_SITE_KEY !== '' && RECAPTCHA_SECRET_KEY !== '';

// Initialize variables
$info = '';
$error = '';

// Handle logout success message from session flash
if (isset($_SESSION['logout_success']) && $_SESSION['logout_success']) {
    regenerate_csrf_token(); // Ensure fresh CSRF token after logout
    $info = 'You have been logged out successfully.';
    unset($_SESSION['logout_success']); // Clear the flash message
}

// Handle session timeout message
if (isset($_GET['info'])) {
    if ($_GET['info'] === 'session_timeout') {
        $error = 'Your session expired due to inactivity. Please log in again.';
    } elseif ($_GET['info'] === '2fa_cancelled') {
        $info = '2FA verification cancelled. Please log in again.';
    }
}

if ($auth->isLoggedIn()) {
    $user = $auth->getCurrentUser();
    $role = strtolower($user['role_name'] ?? '');
    if ($role === 'super_admin') {
        header('Location: superadmin/index.php');
    } elseif ($role === 'admin') {
        header('Location: admin/index.php');
    } elseif ($role === 'staff') {
        header('Location: staff/index.php');
    } else {
        header('Location: staff/index.php'); // Default to staff dashboard
    }
    exit();
}

$error = '';

$lockout = $auth->checkLockout();
$isLocked = $lockout['locked'];

if ($_POST) {
    if (!csrf_verify_request()) {
        $error = 'Invalid CSRF token. Please reload the page.';
    } else {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $recaptchaResponse = trim($_POST['g-recaptcha-response'] ?? '');
    $devicePayload = [
        'device_label' => trim($_POST['device_label'] ?? ''),
        'device_model' => trim($_POST['device_model'] ?? ''),
        'device_platform' => trim($_POST['device_platform'] ?? ''),
        'device_type' => trim($_POST['device_type'] ?? ''),
        'device_os' => trim($_POST['device_os'] ?? ''),
        'device_browser' => trim($_POST['device_browser'] ?? '')
    ];
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter username and password.';
    } else {
        if ($recaptchaConfigured) {
            $recaptchaOk = false;
            $recaptchaGraceUntil = $_SESSION['recaptcha_verified_until'] ?? 0;
            if (!empty($recaptchaGraceUntil) && time() < $recaptchaGraceUntil) {
                $recaptchaOk = true;
            }

            if (!$recaptchaOk) {
                if (empty($recaptchaResponse)) {
                    $error = 'Captcha verification failed. Please try again.';
                    goto login_end;
                }

                // Verify reCAPTCHA
                $verifyUrl = 'https://www.google.com/recaptcha/api/siteverify';
                $verifyPayload = http_build_query([
                    'secret' => RECAPTCHA_SECRET_KEY,
                    'response' => $recaptchaResponse,
                    'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''
                ]);
                $verifyContext = stream_context_create([
                    'http' => [
                        'method' => 'POST',
                        'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                        'content' => $verifyPayload,
                        'timeout' => 5
                    ]
                ]);
                $verifyResult = @file_get_contents($verifyUrl, false, $verifyContext);
                $verifyData = $verifyResult ? json_decode($verifyResult, true) : null;

                if (!$verifyData || empty($verifyData['success'])) {
                    $error = 'Captcha verification failed. Please try again.';
                    goto login_end;
                }

                $_SESSION['recaptcha_verified_until'] = time() + (30 * 60);
            }
        }

        $result = $auth->login($username, $password);

        if ($result['success']) {
              // Proceed with normal login
            $deviceInfo = detect_device_info($_SERVER['HTTP_USER_AGENT'] ?? '');
            $deviceLabel = build_device_label($devicePayload, $_SERVER['HTTP_USER_AGENT'] ?? '');
            Logger::getInstance()->logUserAction(
                'User Login',
                'login_sessions',
                null,
                null,
                [
                    'login_method' => 'password',
                    'device_label' => $deviceLabel,
                    'device_type' => $devicePayload['device_type'] ?: $deviceInfo['device_type'],
                    'os' => $devicePayload['device_os'] ?: $deviceInfo['os'],
                    'browser' => $devicePayload['device_browser'] ?: $deviceInfo['browser'],
                    'platform' => $devicePayload['device_platform'] ?: null,
                    'model' => $devicePayload['device_model'] ?: null
                ]
            );

            // Route users based on their role
            $role = strtolower($result['user']['role_name'] ?? '');
            if ($role === 'super_admin') {
                $target = 'superadmin/index.php';
            } elseif ($role === 'admin') {
                $target = 'admin/index.php';
            } elseif ($role === 'staff') {
                $target = 'staff/index.php';
            } else {
                $target = 'staff/index.php'; // Default to staff dashboard
            }
            header('Location: ' . $target);
            exit();
        } else {
                         if (isset($result['lockout'])) {
                 $error = 'Account locked due to too many attempts. Please try again in ' . $result['lockout']['remaining'] . ' seconds.';
             } elseif (isset($result['error'])) {
                $error = $result['error'];
            } else {
                $attemptsLeft = max(0, (int) Config::get('security.login_attempts_max', 5) - (int) ($result['attempts'] ?? 0));
                $error = 'Invalid username or password. Attempts remaining: ' . $attemptsLeft;
            }
        }
    }
    }
}
login_end:
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
<meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token()); ?>">
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title>ATIERA — Secure Login</title>
<link rel="icon" href="logo2.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
<script>
  (function() {
    try {
      var saved = localStorage.getItem('atiera-theme');
      var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
      if (saved === 'dark' || (!saved && prefersDark)) {
        document.documentElement.classList.add('dark');
      }
    } catch (e) {}
  })();
</script>
    <script src="https://cdn.tailwindcss.com"></script>
<?php if ($recaptchaConfigured): ?>
    <script src="https://www.google.com/recaptcha/api.js?render=explicit" async defer></script>
<?php endif; ?>
<link rel="stylesheet" href="responsive.css">

<style>
  :root{
    --blue-600:#1b2f73; --blue-700:#15265e; --blue-800:#0f1c49; --blue-a:#2342a6;
    --gold:#d4af37; --ink:#0f172a; --muted:#64748b;
    --ring:0 0 0 3px rgba(35,66,166,.28);
    --card-bg: rgba(255,255,255,.95); --card-border: rgba(226,232,240,.9);
    --wm-opa-light:.35; --wm-opa-dark:.55;
  }
  @media (prefers-color-scheme: dark){ :root{ --ink:#e5e7eb; --muted:#9ca3af; } }

  body{
    min-height:100svh; margin:0; color:var(--ink);
    font-family:"Plus Jakarta Sans","Segoe UI",Tahoma,Geneva,Verdana,sans-serif;
    letter-spacing:.01em;
    background:
      radial-gradient(70% 60% at 8% 10%, rgba(255,255,255,.18) 0, transparent 60%),
      radial-gradient(40% 40% at 100% 0%, rgba(212,175,55,.08) 0, transparent 40%),
      linear-gradient(140deg, rgba(15,28,73,1) 50%, rgba(255,255,255,1) 50%);
  }
  html.dark body{
    background:
      radial-gradient(70% 60% at 8% 10%, rgba(212,175,55,.08) 0, transparent 60%),
      radial-gradient(40% 40% at 100% 0%, rgba(212,175,55,.12) 0, transparent 40%),
      linear-gradient(140deg, rgba(7,12,38,1) 50%, rgba(11,21,56,1) 50%);
    color:#e5e7eb;
  }

  .bg-watermark{ position:fixed; inset:0; z-index:-1; display:grid; place-items:center; pointer-events:none; }
  .bg-watermark img{
    width:min(820px,70vw); max-height:68vh; object-fit:contain; opacity:var(--wm-opa-light);
    filter: drop-shadow(0 0 26px rgba(255,255,255,.40)) drop-shadow(0 14px 34px rgba(0,0,0,.25));
    transition:opacity .25s ease, filter .25s ease, transform .6s ease;
  }
  html.dark .bg-watermark img{
    opacity:var(--wm-opa-dark);
    filter: drop-shadow(0 0 34px rgba(255,255,255,.55)) drop-shadow(0 16px 40px rgba(0,0,0,.30));
  }

  .reveal { opacity:0; transform:translateY(8px); animation:reveal .45s .05s both; }
  .reveal-2{ animation-delay:.14s; }
  .reveal-3{ animation-delay:.22s; }
  @keyframes reveal { to { opacity:1; transform:none; } }

  .hero-shell{
    position:relative;
    width:min(620px,90%);
    border-radius:28px;
    padding:2rem 2.2rem;
    background:linear-gradient(145deg, rgba(15,28,73,.48), rgba(15,28,73,.18));
    border:1px solid rgba(255,255,255,.16);
    box-shadow:0 26px 56px rgba(2,6,23,.28);
    backdrop-filter: blur(4px);
  }
  .hero-shell::before{
    content:'';
    position:absolute;
    width:250px; height:250px;
    right:-50px; top:-70px;
    background:radial-gradient(circle, rgba(212,175,55,.24) 0, rgba(212,175,55,0) 72%);
    pointer-events:none;
  }
  .hero-kicker{
    display:inline-flex; align-items:center; gap:.45rem;
    color:#dbeafe; text-transform:uppercase;
    font-weight:700; letter-spacing:.16em; font-size:.68rem; margin-bottom:1.1rem;
  }
  .hero-title{
    margin:0;
    font-family:"Sora","Plus Jakarta Sans","Segoe UI",sans-serif;
    font-size:2.85rem; font-weight:800; line-height:1.12; letter-spacing:-.02em;
  }
  .hero-sub{
    margin:.9rem 0 0;
    color:#c7d2fe; font-size:1rem; max-width:42ch;
  }

  .card{
    background:linear-gradient(180deg, rgba(255,255,255,.98), rgba(248,250,252,.92));
    -webkit-backdrop-filter: blur(16px); backdrop-filter: blur(16px);
    border:1px solid rgba(226,232,240,.9);
    border-radius:24px;
    box-shadow:0 24px 62px rgba(2,6,23,.20), inset 0 1px 0 rgba(255,255,255,.75);
    transition:transform .18s ease, box-shadow .2s ease;
  }
  @media (hover: hover) and (pointer: fine) {
    .card:hover{
      transform:translateY(-2px);
      box-shadow:0 28px 68px rgba(2,6,23,.24), inset 0 1px 0 rgba(255,255,255,.7);
    }
  }
  html.dark .card{ background:rgba(17,24,39,.92); border-color:rgba(71,85,105,.55); box-shadow:0 16px 48px rgba(0,0,0,.5); }

  .card::before{
    content:''; position:absolute; inset:0; border-radius:24px; pointer-events:none;
    box-shadow:0 0 0 1px rgba(255,255,255,.5) inset;
  }
  .card::after{
    content:'';
    position:absolute;
    left:22px; right:22px; top:0;
    height:5px;
    border-radius:0 0 999px 999px;
    background:linear-gradient(90deg, rgba(27,47,115,.95), rgba(212,175,55,.9));
    opacity:.85;
    pointer-events:none;
  }

  .field{ position:relative; }
  .input{
    width:100%; border:1px solid #d6dfef; border-radius:14px; background:linear-gradient(180deg,#ffffff,#f8fbff);
    padding:1.05rem 2.8rem 1.05rem .95rem; outline:none; color:#0f172a; transition:border-color .16s, box-shadow .16s, background .16s, transform .16s;
  }
  .input:hover{ border-color:#c2d1f2; }
  .input:focus{ border-color:var(--blue-a); box-shadow:var(--ring); transform:translateY(-1px); }
  html.dark .input{ background:#0b1220; border-color:#243041; color:#e5e7eb; }
  .float-label{
    position:absolute; left:.9rem; top:50%; transform:translateY(-50%); padding:0 .25rem; color:#94a3b8;
    pointer-events:none; background:transparent; transition:all .15s ease;
  }
  .input:focus + .float-label,
  .input:not(:placeholder-shown) + .float-label{
    top:0; transform:translateY(-50%) scale(.92); color:var(--blue-a); background:#fff;
  }
  html.dark .input:focus + .float-label,
  html.dark .input:not(:placeholder-shown) + .float-label{ background:#0b1220; }
  .icon-right{ position:absolute; right:.6rem; top:50%; transform:translateY(-50%); color:#64748b; }
  html.dark .icon-right{ color:#94a3b8; }

  .card-header-pill{
    display:inline-flex; align-items:center; gap:.5rem; padding:.4rem .75rem;
    border-radius:999px; border:1px solid rgba(15,23,42,.12); background:rgba(248,250,252,.9);
    font-size:.75rem; font-weight:700; color:#1b2f73; letter-spacing:.04em;
  }

  .form-block{
    border:1px solid rgba(210,223,243,.88);
    border-radius:18px;
    padding:1.12rem 1.12rem 1.2rem;
    background:linear-gradient(180deg, rgba(249,251,255,.88), rgba(244,248,255,.7));
    box-shadow:inset 0 1px 0 rgba(255,255,255,.8);
  }
  html.dark .form-block{
    border-color:rgba(71,85,105,.6);
    background:linear-gradient(180deg, rgba(14,24,42,.82), rgba(12,21,37,.75));
  }

  .recaptcha-wrap{
    padding:.25rem 0;
  }
  .captcha-panel{
    border:1px solid rgba(210,223,243,.9);
    border-radius:16px;
    padding:.72rem .78rem;
    background:rgba(248,250,252,.7);
  }
  html.dark .captcha-panel{
    border-color:rgba(71,85,105,.55);
    background:rgba(15,23,42,.65);
  }
  #recaptchaMount{ min-height:78px; }
  @media (max-width: 430px) {
    .recaptcha-wrap .g-recaptcha{
      transform:scale(.90);
      transform-origin:left top;
    }
  }

  .card-ornament{
    position:absolute; right:16px; top:16px; width:46px; height:46px;
    border-radius:14px; background:linear-gradient(135deg, rgba(212,175,55,.2), rgba(27,47,115,.15));
    display:grid; place-items:center; box-shadow:inset 0 0 0 1px rgba(15,23,42,.08);
  }

  .btn{
    width:100%; display:inline-flex; align-items:center; justify-content:center; gap:.6rem;
    background:linear-gradient(180deg, #1b2f73, #0f1c49);
    color:#fff; font-weight:800; font-size:1.05rem; border-radius:16px; padding:1.08rem 1.1rem; border:1px solid rgba(255,255,255,.08);
    transition:transform .08s ease, filter .15s ease, box-shadow .2s ease; box-shadow:0 10px 22px rgba(2,6,23,.22);
    position:relative; overflow:hidden;
  }
  .btn::after{
    content:''; position:absolute; top:0; left:-110%;
    width:60%; height:100%;
    background:linear-gradient(90deg, transparent, rgba(255,255,255,.22), transparent);
    transition:left .45s ease;
  }
  .btn:hover::after{ left:120%; }
  .btn:hover{ filter:saturate(1.08); box-shadow:0 12px 26px rgba(2,6,23,.26); }
  .btn:active{ transform:translateY(1px) scale(.99); }
  .btn[disabled]{ opacity:.85; cursor:not-allowed; }
  .btn-ghost{
    width:100%; display:inline-flex; align-items:center; justify-content:center; gap:.6rem;
    background:transparent; color:var(--blue-700); font-weight:800; border-radius:16px; padding:.95rem 1.1rem;
    border:1px dashed rgba(27,47,115,.35); transition:transform .08s ease, background .15s ease, box-shadow .2s ease;
  }
  .btn-ghost:hover{ background:rgba(27,47,115,.06); box-shadow:0 10px 20px rgba(2,6,23,.12); }
  .btn-ghost.qr-side{
    width:74px;
    height:78px;
    padding:0;
    border-style:solid;
    border-radius:14px;
    display:grid;
    place-items:center;
  }
  .qr-icon{
    width:34px;
    height:34px;
    object-fit:contain;
  }

  .divider{
    display:flex; align-items:center; gap:.6rem; color:#94a3b8; font-size:.75rem; letter-spacing:.2em; text-transform:uppercase;
  }
  .divider::before, .divider::after{
    content:''; height:1px; background:rgba(148,163,184,.35); flex:1;
  }

  .qr-modal{
    position:fixed; inset:0; background:rgba(2,6,23,.55); display:none; align-items:center; justify-content:center; z-index:50;
    backdrop-filter: blur(4px);
  }
  .qr-modal.show{ display:flex; }
  .qr-panel{
    background:var(--card-bg); border-radius:18px; padding:1.25rem; width:min(760px,96vw);
    box-shadow:0 20px 50px rgba(2,6,23,.28);
  }
  .qr-panel h4{ margin:.2rem 0 .6rem; }
  #qrReader{
    width:100%;
    min-height:380px;
    border-radius:14px;
    overflow:hidden;
    background:#0b1220;
  }

  .alert{ border-radius:12px; padding:.65rem .8rem; font-size:.9rem; transition:opacity 0.5s ease }
  .alert-error{ border:1px solid #fecaca; background:#fef2f2; color:#b91c1c }
  .alert-info{ border:1px solid #c7d2fe; background:#eef2ff; color:#3730a3 }
  html.dark .alert-error{ background:#3f1b1b; border-color:#7f1d1d; color:#fecaca }
  html.dark .alert-info{ background:#1e1b4b; border-color:#3730a3; color:#c7d2fe }

  .tos-link{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    margin-top:.45rem;
    padding:.48rem .85rem;
    border-radius:10px;
    border:1px solid rgba(27,47,115,.25);
    background:rgba(255,255,255,.7);
    color:var(--blue-700);
    font-size:.72rem;
    font-weight:700;
    text-decoration:none;
    transition:all .15s ease;
  }
  .tos-link:hover{
    background:rgba(27,47,115,.06);
    border-color:rgba(27,47,115,.45);
  }
  html.dark .tos-link{
    background:rgba(15,23,42,.65);
    border-color:rgba(148,163,184,.35);
    color:#cbd5e1;
  }
  html.dark .tos-link:hover{
    background:rgba(30,41,59,.8);
  }

  .typing::after{ content:'|'; margin-left:2px; opacity:.6; animation: blink 1s steps(1) infinite; }
  @keyframes blink { 50%{opacity:0} }
  .secure-chip{
    display:inline-flex; align-items:center; gap:.4rem;
    border:1px solid rgba(27,47,115,.18); background:rgba(255,255,255,.75); color:#1e3a8a;
    border-radius:999px; padding:.24rem .58rem; font-size:.66rem; font-weight:700;
    letter-spacing:.05em; text-transform:uppercase;
  }
  html.dark .secure-chip{
    color:#cbd5e1; border-color:rgba(148,163,184,.35); background:rgba(15,23,42,.6);
  }
  .caps-hint{
    display:none; margin-top:.45rem; border-radius:10px; border:1px solid #fde68a;
    background:#fffbeb; color:#92400e; font-size:.78rem; padding:.42rem .6rem;
  }
  html.dark .caps-hint{ border-color:#854d0e; background:#2a1f0f; color:#fcd34d; }
  .caps-hint.show{ display:block; }
  .form-title{
    margin:0 0 .35rem;
    font-family:"Sora","Plus Jakarta Sans","Segoe UI",sans-serif;
    font-size:1.9rem;
    line-height:1.1;
    letter-spacing:-.02em;
    color:#0f172a;
  }
  html.dark .form-title{ color:#e5e7eb; }
  .form-sub{
    margin:0 0 1rem;
    color:#64748b;
    font-size:.95rem;
  }
  html.dark .form-sub{ color:#94a3b8; }
</style>
</head>

<body class="grid md:grid-cols-2 gap-0 place-items-center p-6 md:p-10 min-h-screen">
  <?php include_once 'includes/loading_screen.php'; ?>

  <div class="bg-watermark" aria-hidden="true">
    <img src="logo.png" alt="ATIERA watermark" id="wm">
  </div>

  <section class="hidden md:flex w-full h-full items-center justify-center">
    <div class="hero-shell text-white reveal">
      <img src="logo.png" alt="ATIERA" class="w-56 mb-6 drop-shadow-xl select-none" draggable="false">
      <h1 class="hero-title">
        ATIERA <span style="color:var(--gold)">HOTEL &amp; RESTAURANT</span> Management
      </h1>
      <p class="hero-sub">Secure access to accounting, operations, and reporting workflows in one controlled workspace.</p>
    </div>
  </section>

  <main class="w-full max-w-xl md:ml-28 md:mr-0 md:pr-4 self-center">
    <div id="card" class="card p-7 sm:p-9 reveal reveal-2 relative">
      <div class="card-ornament" aria-hidden="true">
        <img src="atieralogo2.png" alt="" style="width:26px;height:26px;object-fit:contain;">
      </div>
      <div class="flex items-center justify-between mb-4">
        <div class="md:hidden flex items-center gap-3">
          <img src="logo.png" alt="ATIERA" class="h-10 w-auto">
          <div>
            <div class="text-sm font-semibold leading-4">ATIERA Finance Suite</div>
            <div class="text-[10px] text-[color:var(--muted)]">Blue • White • <span class="font-medium" style="color:var(--gold)">Gold</span></div>
          </div>
        </div>
        <button id="modeBtn" class="px-3 py-2 rounded-lg border border-slate-200 text-sm hover:bg-white/60 dark:hover:bg-slate-800" aria-pressed="false" title="Toggle dark mode" aria-label="Toggle dark mode">
          <span id="modeIconLight" aria-hidden="true" style="display:inline;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M12 4a1 1 0 0 1 1 1v1a1 1 0 1 1-2 0V5a1 1 0 0 1 1-1Zm0 13a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm8-5a1 1 0 0 1-1 1h-1a1 1 0 1 1 0-2h1a1 1 0 0 1 1 1ZM7 12a1 1 0 0 1-1 1H5a1 1 0 1 1 0-2h1a1 1 0 0 1 1 1Zm9.66 5.25a1 1 0 0 1 1.41 0l.7.71a1 1 0 0 1-1.41 1.41l-.7-.7a1 1 0 0 1 0-1.42Zm-11.43 0a1 1 0 0 1 0 1.42l-.7.7a1 1 0 1 1-1.42-1.4l.71-.72a1 1 0 0 1 1.41 0ZM18.77 5.52a1 1 0 0 1 0 1.41l-.7.7a1 1 0 1 1-1.42-1.41l.7-.7a1 1 0 0 1 1.42 0ZM6.64 6.93a1 1 0 0 1-1.41 1.41l-.71-.7A1 1 0 0 1 5.93 6.2l.71.72Z" fill="currentColor"/></svg>
          </span>
          <span id="modeIconDark" aria-hidden="true" style="display:none;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M21 12.8A9 9 0 1 1 11.2 3a1 1 0 0 1 1.08 1.28A7 7 0 0 0 19.72 11.7 1 1 0 0 1 21 12.8Z" fill="currentColor"/></svg>
          </span>
        </button>
      </div>

      <div class="mb-2">
        <span class="secure-chip">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 2 4 5v6c0 5 3.4 9.7 8 11 4.6-1.3 8-6 8-11V5l-8-3Zm0 5a3 3 0 0 1 3 3v1h1a1 1 0 0 1 1 1v4a1 1 0 0 1-1 1H8a1 1 0 0 1-1-1v-4a1 1 0 0 1 1-1h1v-1a3 3 0 0 1 3-3Zm-1 4h2v-1a1 1 0 1 0-2 0v1Z" fill="currentColor"/></svg>
          Secure Access
        </span>
      </div>
      <h3 class="form-title">Sign in</h3>
      <p class="form-sub">Use your credentials to continue.</p>

      <?php if ($error || $info || (!$isLocked && isset($_SESSION['login_attempts']) && $_SESSION['login_attempts'] > 0)): ?>
        <div id="formStatus" class="alert <?php echo $error ? 'alert-error' : 'alert-info'; ?> mb-4" role="alert" aria-live="polite">
          <?php if ($error): ?>
            <?php echo htmlspecialchars($error); ?>
          <?php elseif ($info): ?>
            <?php echo htmlspecialchars($info); ?>
          <?php elseif (!$isLocked && isset($_SESSION['login_attempts']) && $_SESSION['login_attempts'] > 0): ?>
            Login attempts: <?php echo $_SESSION['login_attempts']; ?>/5
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <form method="POST" class="space-y-5 reveal reveal-3 no-loading" data-no-loading="true" novalidate <?php echo $isLocked ? 'onsubmit="return false;"' : ''; ?> aria-describedby="formStatus">
        <?php csrf_input(); ?>
        <input type="hidden" name="device_label" id="device_label">
        <input type="hidden" name="device_model" id="device_model">
        <input type="hidden" name="device_platform" id="device_platform">
        <input type="hidden" name="device_type" id="device_type">
        <input type="hidden" name="device_os" id="device_os">
        <input type="hidden" name="device_browser" id="device_browser">
        <div class="form-block space-y-4">
          <div class="field">
            <input id="username" name="username" type="text" autocomplete="username" class="input peer" placeholder=" " required value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" <?php echo $isLocked ? 'disabled' : ''; ?> autofocus>
            <label for="username" class="float-label">Username</label>
            <span class="icon-right" aria-hidden="true">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5Zm0 2c-5.33 0-8 2.67-8 5v1h16v-1c0-2.33-2.67-5-8-5Z" fill="currentColor"/></svg>
            </span>
          </div>

          <div class="field">
            <input id="password" name="password" type="password" autocomplete="current-password" class="input peer" placeholder=" " required <?php echo $isLocked ? 'disabled' : ''; ?>>
            <label for="password" class="float-label">Password</label>
            <button type="button" id="togglePassword" class="icon-right" aria-label="Show password" title="Show password">
              <svg id="eyeOpen" width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 5c-5 0-9 4.5-10 7 1 2.5 5 7 10 7s9-4.5 10-7c-1-2.5-5-7-10-7Zm0 11a4 4 0 1 1 0-8 4 4 0 0 1 0 8Z" fill="currentColor"/></svg>
              <svg id="eyeClosed" width="20" height="20" viewBox="0 0 24 24" fill="none" style="display:none;"><path d="M3 5.27 4.28 4 20 19.72 18.73 21l-3.1-3.1A10.66 10.66 0 0 1 12 19c-5 0-9-4.5-10-7a17.3 17.3 0 0 1 4.22-5.6L3 5.27ZM12 7c5 0 9 4.5 10 7a17.5 17.5 0 0 1-3.2 4.4l-2.2-2.2a4 4 0 0 0-5.8-5.8L8.7 8.1A8.8 8.8 0 0 1 12 7Z" fill="currentColor"/></svg>
            </button>
          </div>
          <div id="capsHint" class="caps-hint" role="status" aria-live="polite">Caps Lock is ON.</div>
        </div>

        <div class="recaptcha-wrap captcha-panel">
          <div class="flex items-center gap-3 flex-wrap">
            <?php if ($recaptchaConfigured): ?>
            <div id="recaptchaMount" data-sitekey="<?php echo htmlspecialchars(RECAPTCHA_SITE_KEY); ?>"></div>
            <?php else: ?>
            <div class="text-xs text-slate-500 dark:text-slate-400 px-1">Captcha is disabled for this environment.</div>
            <?php endif; ?>
            <button type="button" id="qrScanBtn" class="btn-ghost qr-side" aria-label="Fast QR Login" title="Fast QR Login">
              <img src="qricon.png" alt="" class="qr-icon">
            </button>
          </div>
        </div>

        <button type="submit" class="btn" <?php echo $isLocked ? 'disabled' : ''; ?> aria-busy="false">
          <span id="submitLabel"><?php echo $isLocked ? 'Account Locked' : 'Sign In'; ?></span>
        </button>

        <div class="text-center">
          <p class="text-xs text-center text-slate-500 dark:text-slate-400">© 2025 ATIERA</p>
          <a class="tos-link" href="tos.php">Terms of Service / Terms &amp; Conditions</a>
        </div>
      </form>
    </div>
  </main>

  <div id="qrModal" class="qr-modal" aria-hidden="true">
    <div class="qr-panel">
      <div class="flex items-center justify-between mb-2">
        <h4 class="text-lg font-semibold">Scan QR Code</h4>
        <button type="button" id="qrClose" class="px-3 py-1 rounded-lg border border-slate-200">Close</button>
      </div>
      <p class="text-sm text-slate-500 mb-3">Point your camera at your ATIERA Fast Login QR card. If camera scan fails, import a QR photo.</p>
      <div id="qrReader"></div>
      <div class="mt-3 flex items-center gap-2 flex-wrap">
        <button type="button" id="qrUploadBtn" class="px-3 py-2 rounded-lg border border-slate-300 text-sm text-black hover:bg-slate-100">Import QR Photo</button>
        <input type="file" id="qrImageInput" accept="image/*" capture="environment" style="display:none;">
      </div>
      <div id="qrStatus" class="text-sm text-slate-500 mt-3"></div>
    </div>
  </div>

<script>
  const $ = (s, r=document)=>r.querySelector(s);

  const modeBtn = $('#modeBtn');
  const wmImg = $('#wm');
  const togglePassword = $('#togglePassword');
  const passwordInput = $('#password');
  const eyeOpen = $('#eyeOpen');
  const eyeClosed = $('#eyeClosed');
  const modeIconLight = $('#modeIconLight');
  const modeIconDark = $('#modeIconDark');
  const capsHint = $('#capsHint');
  const recaptchaMount = document.getElementById('recaptchaMount');
  const form = document.querySelector('form');
  const submitBtn = form ? form.querySelector('button[type="submit"]') : null;
  const submitLabel = $('#submitLabel');
  let recaptchaWidgetId = null;
  let recaptchaRenderSeq = 0;

  function renderRecaptchaForTheme(force) {
    if (!recaptchaMount || !window.grecaptcha || typeof window.grecaptcha.render !== 'function') return false;
    if (recaptchaWidgetId !== null && !force) return true;

    const sitekey = recaptchaMount.getAttribute('data-sitekey');
    if (!sitekey) return false;
    try {
      recaptchaRenderSeq += 1;
      recaptchaMount.innerHTML = '';
      const slot = document.createElement('div');
      slot.id = 'recaptchaSlot' + recaptchaRenderSeq;
      recaptchaMount.appendChild(slot);
      recaptchaWidgetId = window.grecaptcha.render(slot, {
        sitekey: sitekey,
        theme: document.documentElement.classList.contains('dark') ? 'dark' : 'light'
      });
      return true;
    } catch (e) {
      return false;
    }
  }

  function ensureRecaptchaRendered(retries) {
    const remaining = typeof retries === 'number' ? retries : 50;
    if (renderRecaptchaForTheme(false) || remaining <= 0) return;
    setTimeout(() => ensureRecaptchaRendered(remaining - 1), 120);
  }

  function syncThemeToggle() {
    const isDark = document.documentElement.classList.contains('dark');
    modeBtn.setAttribute('aria-pressed', String(isDark));
    if (modeIconLight && modeIconDark) {
      modeIconLight.style.display = isDark ? 'none' : 'inline';
      modeIconDark.style.display = isDark ? 'inline' : 'none';
    }
  }

  syncThemeToggle();
  ensureRecaptchaRendered();

  modeBtn.addEventListener('click', ()=>{
    const root = document.documentElement;
    const dark = root.classList.toggle('dark');
    try { localStorage.setItem('atiera-theme', dark ? 'dark' : 'light'); } catch (e) {}
    syncThemeToggle();
    if (!renderRecaptchaForTheme(true)) {
      setTimeout(() => renderRecaptchaForTheme(true), 120);
    }
    wmImg.style.transform = 'scale(1.01)'; setTimeout(()=> wmImg.style.transform = '', 220);
  });

  if (togglePassword && passwordInput) {
    togglePassword.addEventListener('click', () => {
      const isHidden = passwordInput.type === 'password';
      passwordInput.type = isHidden ? 'text' : 'password';
      togglePassword.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
      togglePassword.setAttribute('title', isHidden ? 'Hide password' : 'Show password');
      if (eyeOpen && eyeClosed) {
        eyeOpen.style.display = isHidden ? 'none' : 'inline';
        eyeClosed.style.display = isHidden ? 'inline' : 'none';
      }
    });

    const updateCapsState = (event) => {
      if (!capsHint || !event || typeof event.getModifierState !== 'function') return;
      capsHint.classList.toggle('show', event.getModifierState('CapsLock'));
    };
    passwordInput.addEventListener('keydown', updateCapsState);
    passwordInput.addEventListener('keyup', updateCapsState);
    passwordInput.addEventListener('blur', () => {
      if (capsHint) capsHint.classList.remove('show');
    });
  }

  (function() {
    if (!form) return;

    const labelInput = document.getElementById('device_label');
    const modelInput = document.getElementById('device_model');
    const platformInput = document.getElementById('device_platform');
    const typeInput = document.getElementById('device_type');
    const osInput = document.getElementById('device_os');
    const browserInput = document.getElementById('device_browser');

    function detectFromUA(ua) {
      const lower = (ua || '').toLowerCase();
      let deviceType = 'Desktop';
      if (/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|mediapartners-google/.test(lower)) {
        deviceType = 'Bot';
      } else if (/ipad|tablet|android(?!.*mobile)/.test(lower)) {
        deviceType = 'Tablet';
      } else if (/mobile|iphone|ipod|android.*mobile|windows phone/.test(lower)) {
        deviceType = 'Mobile';
      }

      let os = 'Unknown';
      if (/windows nt 11\.0/.test(lower)) os = 'Windows 11';
      else if (/windows nt 10\.0/.test(lower)) os = 'Windows 10';
      else if (/windows nt 6\.3/.test(lower)) os = 'Windows 8.1';
      else if (/windows nt 6\.1/.test(lower)) os = 'Windows 7';
      else if (/mac os x/.test(lower)) os = 'macOS';
      else if (/android/.test(lower)) os = 'Android';
      else if (/iphone|ipad|ipod/.test(lower)) os = 'iOS';
      else if (/linux/.test(lower)) os = 'Linux';

      let browser = 'Unknown';
      if (/edg\//.test(lower)) browser = 'Edge';
      else if (/chrome\/|crios\//.test(lower)) browser = 'Chrome';
      else if (/firefox\//.test(lower)) browser = 'Firefox';
      else if (/safari\//.test(lower) && !/chrome\/|crios\/|edg\//.test(lower)) browser = 'Safari';

      let model = '';
      const androidMatch = ua && ua.match(/Android\s[\d.]+;\s([^;]+)\sBuild/i);
      if (androidMatch && androidMatch[1]) {
        model = androidMatch[1].trim();
      } else if (/iphone/.test(lower)) {
        model = 'iPhone';
      } else if (/ipad/.test(lower)) {
        model = 'iPad';
      }

      return { deviceType, os, browser, model };
    }

    async function fillDeviceFields() {
      const ua = navigator.userAgent || '';
      const uaData = navigator.userAgentData;
      let model = '';
      let platform = '';
      let deviceType = '';
      let os = '';
      let browser = '';

      if (uaData && uaData.getHighEntropyValues) {
        try {
          const hints = await uaData.getHighEntropyValues(['model', 'platform', 'platformVersion', 'fullVersionList']);
          model = hints.model || '';
          platform = hints.platform || '';
          if (hints.fullVersionList && hints.fullVersionList.length) {
            const preferred = hints.fullVersionList.find(item => item.brand && !/not.a.brand|not a brand/i.test(item.brand))
              || hints.fullVersionList[0];
            browser = preferred && preferred.brand ? preferred.brand.replace('Google ', '') : '';
          }
        } catch (e) {}
      }

      const fallback = detectFromUA(ua);
      deviceType = deviceType || fallback.deviceType;
      os = os || fallback.os;
      if (!browser || /not.a.brand|not a brand/i.test(browser)) {
        browser = fallback.browser;
      }
      model = model || fallback.model;
      platform = platform || navigator.platform || '';

      let label = '';
      if (model && platform) {
        label = `${model} (${platform})`;
      } else if (model) {
        label = model;
      } else if (deviceType && os) {
        label = `${deviceType} • ${os}`;
      } else {
        label = 'Unknown Device';
      }

      if (labelInput) labelInput.value = label;
      if (modelInput) modelInput.value = model;
      if (platformInput) platformInput.value = platform;
      if (typeInput) typeInput.value = deviceType;
      if (osInput) osInput.value = os;
      if (browserInput) browserInput.value = browser;
    }

    form.addEventListener('submit', async function(e) {
      if (form.dataset.deviceReady === '1') return;
      e.preventDefault();
      await fillDeviceFields();
      form.dataset.deviceReady = '1';
      if (submitBtn && submitLabel && !submitBtn.disabled) {
        submitBtn.disabled = true;
        submitBtn.setAttribute('aria-busy', 'true');
        submitLabel.textContent = 'Signing in...';
      }
      form.submit();
    });
    fillDeviceFields();
  })();

  // Handle lockout countdown
  <?php if ($isLocked): ?>
  function updateLockoutCountdown() {
    const remaining = <?php echo $lockout['remaining']; ?>;
    if (remaining > 0) {
      const btn = document.querySelector('button[type="submit"]');
      const span = btn.querySelector('span');
      
      const countdown = setInterval(() => {
        const currentRemaining = Math.max(0, remaining - Math.floor((Date.now() - startTime) / 1000));
        if (currentRemaining <= 0) {
          clearInterval(countdown);
          location.reload();
          return;
        }
        span.textContent = `Locked (${currentRemaining}s)`;
      }, 1000);
      
      const startTime = Date.now();
    }
  }
  
  updateLockoutCountdown();
  <?php endif; ?>

  // Auto-hide logout message after 5 seconds
  <?php if (!empty($info) && strpos($info, 'logged out') !== false): ?>
  setTimeout(() => {
    const alertBox = document.querySelector('.alert');
    if (alertBox) {
      alertBox.style.transition = 'opacity 0.5s ease';
      alertBox.style.opacity = '0';
      setTimeout(() => {
        alertBox.remove();
      }, 500);
    }
  }, 5000);
  <?php endif; ?>
</script>
<script>
  const qrScanBtn = document.getElementById("qrScanBtn");
  const qrModal = document.getElementById("qrModal");
  const qrClose = document.getElementById("qrClose");
  const qrUploadBtn = document.getElementById("qrUploadBtn");
  const qrImageInput = document.getElementById("qrImageInput");
  const qrStatus = document.getElementById("qrStatus");
  let qrScanner = null;
  let qrHandled = false;

  function openQrModal() {
    if (!qrModal) return;
    qrModal.classList.add("show");
    qrModal.setAttribute("aria-hidden", "false");
    if (qrClose) qrClose.focus();
    startQrScanner();
  }

  function closeQrModal() {
    if (!qrModal) return;
    qrModal.classList.remove("show");
    qrModal.setAttribute("aria-hidden", "true");
    stopQrScanner();
  }

  function loadScript(src) {
    return new Promise((resolve, reject) => {
      const s = document.createElement("script");
      s.src = src;
      s.async = true;
      s.onload = resolve;
      s.onerror = reject;
      document.head.appendChild(s);
    });
  }

  async function ensureQrLibrary() {
    if (window.Html5Qrcode) return true;
    try {
      await loadScript("https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js");
      return !!window.Html5Qrcode;
    } catch (e1) {
      try {
        await loadScript("https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js");
        return !!window.Html5Qrcode;
      } catch (e2) {
        return false;
      }
    }
  }

  async function startQrScanner() {
    const hasLib = await ensureQrLibrary();
    if (!hasLib) {
      if (qrStatus) qrStatus.textContent = "QR scanner library failed to load.";
      return;
    }

    qrHandled = false;
    if (qrStatus) qrStatus.textContent = "Starting camera...";

    try {
      qrScanner = new Html5Qrcode("qrReader");
      let cameraConfig = { facingMode: "environment" };

      try {
        const cameras = await Html5Qrcode.getCameras();
        if (Array.isArray(cameras) && cameras.length > 0) {
          const rear = cameras.find(c => /back|rear|environment/i.test((c.label || "")));
          cameraConfig = { deviceId: { exact: (rear || cameras[0]).id } };
        }
      } catch (e) {}

      const qrConfig = {
        fps: 14,
        qrbox: function(viewfinderWidth, viewfinderHeight) {
          const edge = Math.floor(Math.min(viewfinderWidth, viewfinderHeight) * 0.9);
          const size = Math.max(260, Math.min(edge, 420));
          return { width: size, height: size };
        },
        aspectRatio: 1.0
      };

      if (window.Html5QrcodeSupportedFormats) {
        qrConfig.formatsToSupport = [Html5QrcodeSupportedFormats.QR_CODE];
      }

      try {
        await qrScanner.start(
          cameraConfig,
          qrConfig,
          (decodedText) => handleQrResult(decodedText),
          () => {}
        );
      } catch (firstStartError) {
        // Retry with simple facingMode in case strict deviceId fails on some browsers.
        await qrScanner.start(
          { facingMode: "environment" },
          qrConfig,
          (decodedText) => handleQrResult(decodedText),
          () => {}
        );
      }

      if (qrStatus) qrStatus.textContent = "Scanner ready. Point your camera at the QR card.";
    } catch (err) {
      if (qrStatus) qrStatus.textContent = "Unable to access the camera. Please allow camera permission, then retry.";
    }
  }

  async function stopQrScanner() {
    if (qrScanner) {
      try {
        await qrScanner.stop();
      } catch (e) {}
      qrScanner.clear();
      qrScanner = null;
    }
  }

  function handleQrResult(decodedText) {
    if (qrHandled) return;
    qrHandled = true;
    const raw = (decodedText || "").trim();
    if (qrStatus) qrStatus.textContent = "QR code detected. Signing in...";
    stopQrScanner();

    function extractToken(value) {
      const text = (value || "").trim();
      if (!text) return "";

      // Raw hex token generated by qr_login_generate_code().
      if (/^[a-f0-9]{64}$/i.test(text)) {
        return text;
      }

      // Handle full/relative qr-login URLs.
      if (/qr-login\.php/i.test(text)) {
        try {
          const url = new URL(text, window.location.origin);
          const t = (url.searchParams.get("t") || "").trim();
          if (t) return t;
        } catch (e) {}

        const match = text.match(/[?&]t=([^&#\s]+)/i);
        if (match && match[1]) {
          try {
            return decodeURIComponent(match[1]).trim();
          } catch (e) {
            return match[1].trim();
          }
        }
      }

      return text;
    }

    const token = extractToken(raw);
    if (!token) {
      qrHandled = false;
      if (qrStatus) qrStatus.textContent = "Could not read QR token. Please try scanning again.";
      return;
    }

    window.location.href = "qr-login.php?t=" + encodeURIComponent(token);
  }

  async function scanQrFromImageFile(file) {
    if (!file) return;

    const hasLib = await ensureQrLibrary();
    if (!hasLib) {
      if (qrStatus) qrStatus.textContent = "QR scanner library failed to load.";
      return;
    }

    if (qrStatus) qrStatus.textContent = "Reading QR from image...";
    try {
      await stopQrScanner();
      const scanner = new Html5Qrcode("qrReader");
      const decodedText = await scanner.scanFile(file, true);
      scanner.clear();
      if (qrStatus) qrStatus.textContent = "QR image detected. Signing in...";
      handleQrResult(decodedText);
    } catch (err) {
      if (qrStatus) qrStatus.textContent = "Could not detect QR from that image. Try a clearer photo.";
    } finally {
      if (qrImageInput) qrImageInput.value = "";
    }
  }

  if (qrScanBtn) qrScanBtn.addEventListener("click", openQrModal);
  if (qrClose) qrClose.addEventListener("click", closeQrModal);
  if (qrUploadBtn && qrImageInput) {
    qrUploadBtn.addEventListener("click", () => qrImageInput.click());
    qrImageInput.addEventListener("change", (e) => {
      const file = e.target.files && e.target.files[0];
      scanQrFromImageFile(file);
    });
  }
  if (qrModal) {
    qrModal.addEventListener("click", (e) => {
      if (e.target === qrModal) closeQrModal();
    });
  }
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && qrModal && qrModal.classList.contains("show")) {
      closeQrModal();
    }
  });
</script>
<script src="includes/tab_persistence.js?v=1"></script>
</body>
</html>







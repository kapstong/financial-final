<?php
require_once __DIR__ . '/includes/csrf.php';
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token()); ?>">
<title>ATIERA - Terms of Service</title>
<link rel="icon" href="logo2.png">
<style>
  :root{
    --blue-600:#1b2f73;
    --blue-700:#15265e;
    --blue-800:#0f1c49;
    --gold:#d4af37;
    --ink:#0f172a;
    --muted:#64748b;
    --card:#ffffff;
  }
  *{ box-sizing:border-box; }
  body{
    margin:0;
    min-height:100vh;
    font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    color:var(--ink);
    background:
      radial-gradient(70% 60% at 8% 10%, rgba(255,255,255,.18) 0, transparent 60%),
      radial-gradient(40% 40% at 100% 0%, rgba(212,175,55,.08) 0, transparent 40%),
      linear-gradient(140deg, rgba(15,28,73,1) 50%, rgba(255,255,255,1) 50%);
  }
  .wrap{
    width:min(980px, 94vw);
    margin:2rem auto;
  }
  .card{
    background:linear-gradient(180deg, rgba(255,255,255,.98), rgba(248,250,252,.95));
    border:1px solid rgba(226,232,240,.9);
    border-radius:22px;
    box-shadow:0 22px 60px rgba(2,6,23,.20), inset 0 1px 0 rgba(255,255,255,.7);
    overflow:hidden;
  }
  .head{
    background:linear-gradient(135deg, var(--blue-700), var(--blue-800));
    color:#fff;
    padding:1.2rem 1.4rem;
    border-bottom:3px solid var(--gold);
  }
  .head h1{
    margin:0 0 .25rem;
    font-size:1.35rem;
  }
  .head p{
    margin:0;
    font-size:.92rem;
    color:#dbeafe;
  }
  .content{
    padding:1.2rem 1.4rem 1.4rem;
  }
  .notice{
    border-left:4px solid var(--gold);
    background:#fffbeb;
    padding:.85rem .9rem;
    border-radius:8px;
    margin-bottom:1rem;
    color:#78350f;
    font-size:.92rem;
  }
  h2{
    font-size:1.03rem;
    color:var(--blue-700);
    margin:1.15rem 0 .5rem;
  }
  p, li{
    line-height:1.5;
    color:#1e293b;
  }
  ul{
    margin:.4rem 0 .65rem 1.2rem;
    padding:0;
  }
  .penalty{
    border:1px solid #fecaca;
    background:#fef2f2;
    border-radius:10px;
    padding:.8rem .9rem;
    margin-top:.5rem;
  }
  .penalty strong{
    color:#991b1b;
  }
  .actions{
    display:flex;
    gap:.65rem;
    flex-wrap:wrap;
    margin-top:1.25rem;
  }
  .btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:12px;
    padding:.68rem 1rem;
    text-decoration:none;
    font-weight:700;
    font-size:.9rem;
    transition:all .15s ease;
  }
  .btn-primary{
    background:linear-gradient(180deg, var(--blue-600), var(--blue-800));
    border:1px solid rgba(255,255,255,.08);
    color:#fff;
  }
  .btn-primary:hover{ filter:saturate(1.08); }
  .btn-ghost{
    border:1px solid rgba(27,47,115,.35);
    color:var(--blue-700);
    background:#fff;
  }
  .btn-ghost:hover{
    background:rgba(27,47,115,.06);
  }
  .footer{
    margin-top:.8rem;
    color:var(--muted);
    font-size:.82rem;
  }
  @media (max-width: 768px){
    .wrap{ margin:1.1rem auto; }
    .content{ padding:1rem; }
    .head{ padding:1rem; }
  }
</style>
</head>
<body>
  <?php include_once __DIR__ . '/includes/loading_screen.php'; ?>
  <main class="wrap">
    <section class="card">
      <header class="head">
        <h1>ATIERA Terms of Service / Terms &amp; Conditions</h1>
        <p>Effective Date: February 21, 2026</p>
      </header>
      <div class="content">
        <div class="notice">
          This system handles confidential operational and financial records. Access is granted only for authorized business use.
        </div>

        <h2>1. Authorized Use</h2>
        <p>By accessing ATIERA, users agree to use the system strictly for official company operations. Personal, unrelated, or unauthorized use is prohibited.</p>

        <h2>2. Sensitive Data Handling</h2>
        <p>ATIERA may collect and process sensitive information, including but not limited to:</p>
        <ul>
          <li>User identity and account credentials</li>
          <li>Financial transactions, reports, and accounting records</li>
          <li>Operational activity logs and system audit trails</li>
          <li>Uploaded supporting documents and payment-related data</li>
        </ul>
        <p>Users must handle all records with care and follow internal security and privacy policies at all times.</p>

        <h2>3. Confidentiality and Non-Disclosure</h2>
        <ul>
          <li>Do not share, export, forward, or disclose confidential data to unauthorized persons.</li>
          <li>Do not expose account credentials, one-time codes, or session access to others.</li>
          <li>Do not bypass role permissions or attempt to access data outside your authorization.</li>
          <li>Any suspected leak, misuse, or unauthorized access must be reported immediately.</li>
        </ul>

        <h2>4. Security Responsibilities</h2>
        <ul>
          <li>Maintain strong credentials and do not reuse shared passwords.</li>
          <li>Log out after using shared or public devices.</li>
          <li>Use only approved devices and channels for system access.</li>
          <li>Cooperate with compliance checks, incident reviews, and audit procedures.</li>
        </ul>

        <h2>5. Monitoring and Audit</h2>
        <p>System usage, transactions, and access events may be logged and reviewed for security, compliance, and operational integrity.</p>

        <h2>6. Violations and Penalties</h2>
        <div class="penalty">
          <strong>Failure to comply may result in one or more of the following:</strong>
          <ul>
            <li>Immediate account suspension or permanent access revocation</li>
            <li>Internal disciplinary action under company policy</li>
            <li>Financial liability for damages caused by misuse or data leakage</li>
            <li>Civil or criminal legal action where applicable by law</li>
          </ul>
        </div>

        <h2>7. Acceptance</h2>
        <p>Continued use of ATIERA confirms that you understand and accept these terms and agree to comply with all applicable company policies and legal obligations.</p>

        <div class="actions">
          <a class="btn btn-primary" href="index.php">Back to Login</a>
          <a class="btn btn-ghost" href="tos-print.html" target="_blank" rel="noopener">Print</a>
        </div>
        <p class="footer">For policy questions, contact your system administrator or compliance officer.</p>
      </div>
    </section>
  </main>
</body>
</html>

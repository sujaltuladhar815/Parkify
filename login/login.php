<?php
// ── Start Session ─────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── DB Connection Configuration ──────────────────────────────
$host = 'localhost';
$user = 'root';
$pass = '';
$db   = 'parkify_db';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    if (isset($_GET['action'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $conn->connect_error]);
        exit;
    }
}

// ── Central API Endpoint Router ───────────────────────────────
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $_GET['action'];

    // ── 1. NATIVE EMAIL/PASSWORD LOGIN ───────────────────────
    if ($action === 'login') {
        $email = isset($input['email']) ? trim($input['email']) : '';
        $password = isset($input['password']) ? $input['password'] : '';

        if (empty($email) || empty($password)) {
            echo json_encode(['success' => false, 'message' => 'Please fill in all fields.']);
            exit;
        }

        $stmt = $conn->prepare("SELECT id, full_name, email, password_hash, role FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $userRow = $result->fetch_assoc();
            // Verify BCRYPT hashed password
            if (password_verify($password, $userRow['password_hash'])) {
                $_SESSION['user_id']    = $userRow['id'];
                $_SESSION['user_name']  = $userRow['full_name'];
                $_SESSION['user_email'] = $userRow['email'];
                $_SESSION['role']       = $userRow['role'];

                echo json_encode([
                    'success' => true,
                    'message' => 'Login successful! Welcome back.',
                    'user' => [
                        'name'  => $userRow['full_name'],
                        'email' => $userRow['email'],
                        'role'  => $userRow['role']
                    ]
                ]);
                exit;
            }
        }
        echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
        exit;
    }

    // ── 2. NATIVE ACCOUNT REGISTRATION ───────────────────────
    if ($action === 'signup') {
        $full_name = isset($input['full_name']) ? trim($input['full_name']) : '';
        $email     = isset($input['email']) ? trim($input['email']) : '';
        $password  = isset($input['password']) ? $input['password'] : '';

        if (empty($full_name) || empty($email) || empty($password)) {
            echo json_encode(['success' => false, 'message' => 'All mandatory fields are required.']);
            exit;
        }

        // Check if email already exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            echo json_encode(['success' => false, 'message' => 'This email address is already registered.']);
            exit;
        }

        // Encrypt using strong unilateral BCRYPT algorithm
        $password_hash = password_hash($password, PASSWORD_BCRYPT);
        $role = 'user'; 

        $stmt = $conn->prepare("INSERT INTO users (full_name, email, password_hash, role) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $full_name, $email, $password_hash, $role);

        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Account created successfully! Proceeding to login.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Registration failed. Please try again later.']);
        }
        exit;
    }

    // ── 3. GOOGLE SIGN-IN DATABASE SYNC ──────────────────────
    if ($action === 'google_sync') {
        $email = isset($input['email']) ? trim($input['email']) : '';
        $name  = isset($input['name']) ? trim($input['name']) : 'Google User';

        if (empty($email)) {
            echo json_encode(['success' => false, 'message' => 'Missing email payload from Google provider.']);
            exit;
        }

        // Verify if user exists or provision a new federated record
        $stmt = $conn->prepare("SELECT id, full_name, email, role FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $dummy_hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
            $default_role = 'user';
            $ins_stmt = $conn->prepare("INSERT INTO users (full_name, email, password_hash, role) VALUES (?, ?, ?, ?)");
            $ins_stmt->bind_param("ssss", $name, $email, $dummy_hash, $default_role);
            $ins_stmt->execute();
            
            $userId = $ins_stmt->insert_id;
            $role   = $default_role;
        } else {
            $userRow = $result->fetch_assoc();
            $userId  = $userRow['id'];
            $name    = $userRow['full_name'];
            $role    = $userRow['role'];
        }

        $_SESSION['user_id']    = $userId;
        $_SESSION['user_name']  = $name;
        $_SESSION['user_email'] = $email;
        $_SESSION['role']       = $role;

        echo json_encode([
            'success' => true, 
            'message' => 'Google authorization synchronized.',
            'role'    => $role
        ]);
        exit;
    }
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Parkify — Log In</title>

    <script src="https://accounts.google.com/gsi/client" async defer></script>

    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=Inter:wght@400;500;600&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <link rel="icon" href="../images/fabiconlogo.png" type="image/png" />
    <link rel="stylesheet" href="login.css" />

    <style>
      #google-profile-card {
        display: none;
        background: linear-gradient(135deg, #f0f7ff 0%, #e8f4fd 100%);
        border: 1.5px solid #c3ddf9;
        border-radius: 14px;
        padding: 18px 20px;
        margin-bottom: 18px;
        animation: fadeSlideIn 0.4s ease forwards;
      }
      @keyframes fadeSlideIn {
        from { opacity: 0; transform: translateY(-8px); }
        to { opacity: 1; transform: translateY(0); }
      }
      #google-profile-card .gpc-inner { display: flex; align-items: center; gap: 14px; }
      #google-profile-card img { width: 46px; height: 46px; border-radius: 50%; border: 2px solid #4285f4; object-fit: cover; flex-shrink: 0; }
      #google-profile-card .gpc-avatar-placeholder { width: 46px; height: 46px; border-radius: 50%; background: linear-gradient(135deg, #4285f4, #34a853); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 18px; font-weight: 700; flex-shrink: 0; }
      #google-profile-card .gpc-info { flex: 1; min-width: 0; }
      #google-profile-card .gpc-name { font-family: "Plus Jakarta Sans", sans-serif; font-weight: 700; font-size: 15px; color: #1a1a2e; margin: 0 0 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
      #google-profile-card .gpc-email { font-family: "Inter", sans-serif; font-size: 12.5px; color: #5a7a9a; margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
      #google-profile-card .gpc-badge { display: flex; align-items: center; gap: 5px; background: #fff; border: 1px solid #c3ddf9; border-radius: 20px; padding: 3px 10px; font-size: 11px; font-weight: 600; color: #4285f4; white-space: nowrap; flex-shrink: 0; }
      #google-profile-card .gpc-badge svg { width: 12px; height: 12px; }
      .gpc-signout { display: block; width: 100%; margin-top: 12px; padding: 8px; background: transparent; border: 1px solid #c3ddf9; border-radius: 8px; color: #e05252; font-size: 12.5px; font-weight: 600; cursor: pointer; transition: background 0.2s, border-color 0.2s; font-family: "Plus Jakarta Sans", sans-serif; }
      .gpc-signout:hover { background: #fff0f0; border-color: #e05252; }
      .social-btn-login.google-loading { opacity: 0.7; pointer-events: none; }
      #gsi-setup-warning { display: none; background: #fff8e1; border: 1.5px solid #ffcc02; border-radius: 10px; padding: 12px 14px; margin-bottom: 14px; font-size: 12.5px; color: #7a5c00; line-height: 1.5; }
      #gsi-setup-warning strong { color: #5a4000; }
      #gsi-setup-warning code { background: #fff3cc; border-radius: 4px; padding: 1px 5px; font-size: 11.5px; }
    </style>
  </head>
  <body>
    <div id="auth-loading" style="position: fixed; inset: 0; background: #fff; z-index: 9999; flex-direction: column; align-items: center; justify-content: center; gap: 20px; opacity: 0; pointer-events: none; display: flex; transition: opacity 0.5s ease;">
      <div style="position: relative; width: 64px; height: 64px">
        <svg width="64" height="64" viewBox="0 0 64 64" style="animation: spin 1s linear infinite; position: absolute">
          <circle cx="32" cy="32" r="28" fill="none" stroke="#e2e8f0" stroke-width="5" />
          <circle cx="32" cy="32" r="28" fill="none" stroke="#4285f4" stroke-width="5" stroke-linecap="round" stroke-dasharray="60 120" />
        </svg>
        <svg width="28" height="28" viewBox="0 0 48 48" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);">
          <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z" />
          <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z" />
          <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z" />
          <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.18 1.48-4.97 2.35-8.16 2.35-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z" />
        </svg>
      </div>
      <div style="text-align: center">
        <div id="auth-loading-name" style="font-family: 'Plus Jakarta Sans', sans-serif; font-size: 17px; font-weight: 700; color: #1a1a2e;"></div>
        <div style="font-size: 13px; color: #64748b; margin-top: 4px">Signing you in...</div>
      </div>
    </div>

    <style> @keyframes spin { to { transform: rotate(360deg); } } </style>
    <div class="blob blob-a"></div>
    <div class="blob blob-b"></div>
    <div class="blob blob-c"></div>

    <a class="site-link" href="../user/index.php"><i class="fa-solid fa-arrow-left fa-xs"></i> Back to site</a>

    <div class="card">
      <div class="card-header">
        <div class="brand-icon"><img src="../images/initialLogo.png" alt="logo" width="150px" /></div>
        <h1 class="card-title">Welcome to Parkify</h1>
        <p class="card-sub" id="card-sub">Log in to manage your parking, slots &amp; payments.</p>
      </div>

      <div class="tab-row" id="tab-row">
        <button class="tab-btn active" id="tab-login" onclick="switchTab('login')">Log In</button>
        <button class="tab-btn" id="tab-signup" onclick="switchTab('signup')">Sign Up</button>
      </div>

      <div id="main-panel">
        <div id="gsi-setup-warning">
          ⚠️ <strong>Developer notice:</strong> Replace <code>YOUR_GOOGLE_CLIENT_ID</code> in code with your real Client ID to activate Google Integration.
        </div>

        <div id="google-profile-card">
          <div class="gpc-inner">
            <div class="gpc-avatar-placeholder" id="gpc-avatar-placeholder"></div>
            <div class="gpc-info">
              <p class="gpc-name" id="gpc-name">—</p>
              <p class="gpc-email" id="gpc-email">—</p>
            </div>
            <div class="gpc-badge">
              <svg viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg">
                <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z" />
                <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z" />
                <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z" />
                <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.18 1.48-4.97 2.35-8.16 2.35-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z" />
              </svg> Verified
            </div>
          </div>
          <button class="gpc-signout" onclick="handleGoogleSignOut()">
            <i class="fa-solid fa-right-from-bracket" style="margin-right: 5px"></i> Sign out of Google
          </button>
        </div>

        <div class="social-row">
          <button class="social-btn-login" id="google-btn" onclick="handleGoogleSignIn()">
            <svg width="17" height="17" viewBox="0 0 48 48" style="flex-shrink: 0">
              <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z" />
              <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z" />
              <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z" />
              <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.18 1.48-4.97 2.35-8.16 2.35-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z" />
            </svg> Continue with Google
          </button>
          <button class="social-btn-login" onclick="socialLogin('Facebook')">
            <i class="fa-brands fa-facebook" style="color: #1877f2; font-size: 17px"></i> Facebook
          </button>
        </div>

        <div class="or-row">
          <div class="or-line"></div><span class="or-text">or continue with email</span><div class="or-line"></div>
        </div>

        <div id="form-login">
          <div class="field-group">
            <div class="field-wrap">
              <label class="field-label" for="login-email">Email Address <span class="req">*</span></label>
              <div class="input-wrap">
                <i class="fa-regular fa-envelope input-icon"></i>
                <input class="field-input" type="email" id="login-email" placeholder="you@example.com" autocomplete="email" />
              </div>
              <span class="field-err" id="login-email-err"></span>
            </div>
            <div class="field-wrap">
              <label class="field-label" for="login-pass">Password <span class="req">*</span></label>
              <div class="input-wrap">
                <i class="fa-solid fa-lock input-icon"></i>
                <input class="field-input" type="password" id="login-pass" placeholder="Enter your password" autocomplete="current-password" />
                <button class="input-eye" type="button" onclick="toggleEye('login-pass', this)" tabindex="-1"><i class="fa-regular fa-eye"></i></button>
              </div>
              <span class="field-err" id="login-pass-err"></span>
            </div>
          </div>
          <div class="extras-row">
            <div class="remember-wrap"><input type="checkbox" id="remember" /><label for="remember">Remember me</label></div>
            <span class="forgot-link" onclick="showForgot()">Forgot password?</span>
          </div>
          <button class="submit-btn" id="login-btn" onclick="handleLogin()">
            <span class="btn-label"><i class="fa-solid fa-right-to-bracket"></i>&nbsp; Log In</span>
            <span class="spinner"></span>
          </button>
          <p class="bottom-text">Don't have an account? <a onclick="switchTab('signup')">Create one &rarr;</a></p>
        </div>

        <div id="form-signup" style="display: none">
          <div class="field-group">
            <div class="name-row">
              <div class="field-wrap">
                <label class="field-label" for="su-fname">First Name <span class="req">*</span></label>
                <div class="input-wrap">
                  <i class="fa-regular fa-user input-icon"></i>
                  <input class="field-input" type="text" id="su-fname" placeholder="Sujan" autocomplete="given-name" />
                </div>
                <span class="field-err" id="su-fname-err"></span>
              </div>
              <div class="field-wrap">
                <label class="field-label" for="su-lname">Last Name <span class="req">*</span></label>
                <div class="input-wrap">
                  <i class="fa-regular fa-user input-icon"></i>
                  <input class="field-input" type="text" id="su-lname" placeholder="Karki" autocomplete="family-name" />
                </div>
                <span class="field-err" id="su-lname-err"></span>
              </div>
            </div>
            <div class="field-wrap">
              <label class="field-label" for="su-email">Email Address <span class="req">*</span></label>
              <div class="input-wrap">
                <i class="fa-regular fa-envelope input-icon"></i>
                <input class="field-input" type="email" id="su-email" placeholder="you@example.com" autocomplete="email" />
              </div>
              <span class="field-err" id="su-email-err"></span>
            </div>
            <div class="field-wrap">
              <label class="field-label" for="su-pass">Password <span class="req">*</span></label>
              <div class="input-wrap">
                <i class="fa-solid fa-lock input-icon"></i>
                <input class="field-input" type="password" id="su-pass" placeholder="Min 8 characters" autocomplete="new-password" oninput="checkStrength(this.value)" />
                <button class="input-eye" type="button" onclick="toggleEye('su-pass', this)" tabindex="-1"><i class="fa-regular fa-eye"></i></button>
              </div>
              <div class="strength-bar">
                <div class="seg" id="seg1"></div><div class="seg" id="seg2"></div><div class="seg" id="seg3"></div><div class="seg" id="seg4"></div>
              </div>
              <span class="field-err" id="su-pass-err"></span>
              <span id="strength-hint" class="strength-hint"></span>
            </div>
            <div class="field-wrap">
              <label class="field-label" for="su-cpass">Confirm Password <span class="req">*</span></label>
              <div class="input-wrap">
                <i class="fa-solid fa-lock input-icon"></i>
                <input class="field-input" type="password" id="su-cpass" placeholder="Repeat your password" autocomplete="new-password" />
                <button class="input-eye" type="button" onclick="toggleEye('su-cpass', this)" tabindex="-1"><i class="fa-regular fa-eye"></i></button>
              </div>
              <span class="field-err" id="su-cpass-err"></span>
            </div>
          </div>
          <button class="submit-btn" id="signup-btn" onclick="handleSignup()">
            <span class="btn-label"><i class="fa-solid fa-user-plus"></i>&nbsp; Create Account</span>
            <span class="spinner"></span>
          </button>
          <p class="bottom-text">Already have an account? <a onclick="switchTab('login')">Log in &rarr;</a></p>
        </div>
      </div>

      <div id="forgot-panel">
        <div class="forgot-back" onclick="hideForgot()"><i class="fa-solid fa-arrow-left fa-xs"></i> Back to login</div>
        <p class="forgot-desc">Enter the email linked to your account and we'll send a reset link.</p>
        <div class="field-wrap">
          <label class="field-label" for="forgot-email">Email Address <span class="req">*</span></label>
          <div class="input-wrap">
            <i class="fa-regular fa-envelope input-icon"></i>
            <input class="field-input" type="email" id="forgot-email" placeholder="you@example.com" />
          </div>
          <span class="field-err" id="forgot-email-err"></span>
        </div>
        <button class="submit-btn" id="forgot-btn" onclick="handleForgot()">
          <span class="btn-label"><i class="fa-regular fa-paper-plane"></i>&nbsp; Send Reset Link</span>
          <span class="spinner"></span>
        </button>
      </div>
    </div>

    <div id="toast"><i></i><span id="toast-msg"></span></div>

    <script>
      const GOOGLE_CLIENT_ID = "251560807541-a79kp4kss9a2ireg7pa7d2juft5igcbt.apps.googleusercontent.com";

      let googleClient = null;
      let googleUserData = null;

      window.addEventListener("load", () => {
        if (GOOGLE_CLIENT_ID.startsWith("YOUR_")) {
          document.getElementById("gsi-setup-warning").style.display = "block";
        }
        initGoogleSignIn();
      });

      function initGoogleSignIn() {
        if (typeof google === "undefined") return;
        google.accounts.id.initialize({
          client_id: GOOGLE_CLIENT_ID,
          callback: handleGoogleCredentialResponse,
          error_callback: (err) => {
            showToast("error", "Google Sign-In failed.");
            document.getElementById("google-btn").classList.remove("google-loading");
          },
        });
      }

      function handleGoogleSignIn() {
        if (typeof google === "undefined") {
          showToast("error", "Google SDK not loaded. Check internet connection.");
          return;
        }
        document.getElementById("google-btn").classList.add("google-loading");

        const client = google.accounts.oauth2.initTokenClient({
          client_id: GOOGLE_CLIENT_ID,
          scope: "email profile openid",
          callback: async (tokenResponse) => {
            document.getElementById("google-btn").classList.remove("google-loading");
            if (tokenResponse.error) return;

            const userInfoRes = await fetch("https://www.googleapis.com/oauth2/v3/userinfo", {
              headers: { Authorization: "Bearer " + tokenResponse.access_token },
            });
            const userInfo = await userInfoRes.json();
            googleUserData = userInfo;
            showGoogleProfileCard(userInfo);

            const syncRes = await fetch("login.php?action=google_sync", {
              method: "POST",
              headers: { "Content-Type": "application/json" },
              body: JSON.stringify({ name: userInfo.name, email: userInfo.email })
            });
            const syncData = await syncRes.json();

            localStorage.setItem("parkify_user", JSON.stringify({
              name: userInfo.name, email: userInfo.email, picture: userInfo.picture, given_name: userInfo.given_name, role: syncData.role
            }));

            const overlay = document.getElementById("auth-loading");
            document.getElementById("auth-loading-name").textContent = `Welcome, ${userInfo.given_name || userInfo.name}!`;
            overlay.style.pointerEvents = "all";

            requestAnimationFrame(() => {
              requestAnimationFrame(() => {
                overlay.style.opacity = "1";
                setTimeout(() => { 
                  // Relative directory route adjustments based on user roles
                  if (syncData.role === 'admin') {
                     window.location.href = "../admin/dashboard.php";
                  } else {
                     window.location.href = "../user/home.php";
                  }
                }, 1500);
              });
            });
          },
        });
        client.requestAccessToken({ prompt: "select_account" });
      }

      async function handleGoogleCredentialResponse(response) {
        document.getElementById("google-btn").classList.remove("google-loading");
        if (!response.credential) return;

        const payload = decodeGoogleJWT(response.credential);
        if (!payload) return;

        googleUserData = payload;
        showGoogleProfileCard(payload);

        try {
          const res = await fetch("login.php?action=google_sync", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ name: payload.name, email: payload.email }),
          });
          const data = await res.json();
          showToast("success", `Welcome, ${payload.given_name || payload.name}! 🎉`);
          
          setTimeout(() => { 
             if (data.role === 'admin') {
                 window.location.href = "../admin/dashboard.php";
             } else {
                 window.location.href = "../user/home.php";
             }
          }, 1500);
        } catch (err) {
          showToast("error", "Sign-in failed.");
        }
      }

      function decodeGoogleJWT(token) {
        try {
          const base64Url = token.split(".")[1];
          const base64 = base64Url.replace(/-/g, "+").replace(/_/g, "/");
          return JSON.parse(atob(base64));
        } catch (e) { return null; }
      }

      function showGoogleProfileCard(payload) {
        document.getElementById("gpc-name").textContent = payload.name || "Google User";
        document.getElementById("gpc-email").textContent = payload.email || "";
        const placeholder = document.getElementById("gpc-avatar-placeholder");
        if (payload.picture) {
          const img = document.createElement("img");
          img.src = payload.picture;
          img.alt = payload.name;
          placeholder.replaceWith(img);
        } else {
          placeholder.textContent = (payload.given_name || "G")[0].toUpperCase();
        }
        document.getElementById("google-profile-card").style.display = "block";
        document.getElementById("google-btn").style.display = "none";
      }

      function handleGoogleSignOut() {
        localStorage.removeItem("parkify_user");
        document.getElementById("google-profile-card").style.display = "none";
        document.getElementById("google-btn").style.display = "";
        showToast("success", "Signed out successfully.");
      }

      // ── Native Form Login Execution Function ────────────────────────
     async function handleLogin() {
  clearErrors();
  const email = document.getElementById("login-email").value.trim();
  const pass = document.getElementById("login-pass").value;
  let ok = true;

  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { setErr("login-email-err", "Invalid email"); ok = false; }
  if (pass.length < 6) { setErr("login-pass-err", "Invalid password"); ok = false; }
  if (!ok) return;

  const response = await fetch("login.php?action=login", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ email, password: pass }),
  });
  const data = await response.json();

  if (data.success) {
    localStorage.setItem("parkify_user", JSON.stringify(data.user));
    showToast("success", data.message);
    setTimeout(() => {
      if (data.user.role === 'admin') {
        window.location.href = "../admin/dashboard.php";
      } else {
        window.location.href = "../user/home.php";
      }
    }, 1500);
  } else {
    showToast("error", data.message);
  }
}

      // ── Native Form Registration Execution Function ─────────────────
      async function handleSignup() {
        clearErrors();
        const fname = document.getElementById("su-fname").value.trim();
        const lname = document.getElementById("su-lname").value.trim();
        const email = document.getElementById("su-email").value.trim();
        const pass = document.getElementById("su-pass").value;
        const cpass = document.getElementById("su-cpass").value;
        let ok = true;

        if (!fname) { setErr("su-fname-err", "First name required"); ok = false; }
        if (!lname) { setErr("su-lname-err", "Last name required"); ok = false; }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { setErr("su-email-err", "Invalid email"); ok = false; }
        if (pass.length < 8) { setErr("su-pass-err", "Minimum 8 characters"); ok = false; }
        if (pass !== cpass) { setErr("su-cpass-err", "Passwords do not match"); ok = false; }
        if (!ok) return;

        const response = await fetch("login.php?action=signup", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ full_name: fname + " " + lname, email: email, password: pass }),
        });
        const data = await response.json();

        if (data.success) {
          showToast("success", data.message);
          switchTab("login");
        } else {
          showToast("error", data.message);
        }
      }

      function switchTab(tab) {
        const isLogin = tab === "login";
        document.getElementById("tab-login").classList.toggle("active", isLogin);
        document.getElementById("tab-signup").classList.toggle("active", !isLogin);
        document.getElementById("form-login").style.display = isLogin ? "block" : "none";
        document.getElementById("form-signup").style.display = isLogin ? "none" : "block";
        document.getElementById("card-sub").textContent = isLogin ? "Log in to manage your parking, slots & payments." : "Create your free Parkify account today.";
        clearErrors();
      }
      function toggleEye(id, btn) {
        const inp = document.getElementById(id);
        const icon = btn.querySelector("i");
        const show = inp.type === "password";
        inp.type = show ? "text" : "password";
        icon.className = show ? "fa-regular fa-eye-slash" : "fa-regular fa-eye";
      }
      function checkStrength(val) {
        const segs = [1, 2, 3, 4].map(n => document.getElementById("seg" + n));
        const hint = document.getElementById("strength-hint");
        const colors = ["#f43f5e", "#f59e0b", "#2563eb", "#10b981"];
        const labels = ["Weak", "Fair", "Good", "Strong"];
        let score = 0;
        if (val.length >= 8) score++;
        if (/[A-Z]/.test(val)) score++;
        if (/[0-9]/.test(val)) score++;
        if (/[^A-Za-z0-9]/.test(val)) score++;
        segs.forEach((s, i) => { s.style.background = i < score ? colors[score - 1] : "var(--border)"; });
        hint.textContent = val.length ? labels[score - 1] || "" : "";
        hint.style.color = score ? colors[score - 1] : "var(--text4)";
      }
      function setErr(id, msg) {
        const err = document.getElementById(id);
        const inp = document.getElementById(id.replace("-err", ""));
        err.textContent = msg; err.classList.add("show");
        if (inp) inp.classList.add("error");
      }
      function clearErrors() {
        document.querySelectorAll(".field-err").forEach(e => { e.classList.remove("show"); e.textContent = ""; });
        document.querySelectorAll(".field-input").forEach(i => i.classList.remove("error"));
      }
      function showForgot() {
        document.getElementById("main-panel").classList.add("hide");
        document.getElementById("forgot-panel").classList.add("show");
        document.getElementById("tab-row").classList.add("hide-tabs");
        document.getElementById("card-sub").textContent = "";
      }
      function hideForgot() {
  document.getElementById("main-panel").classList.remove("hide");
  document.getElementById("forgot-panel").classList.remove("show"); // ✅ Fixed typo
  document.getElementById("tab-row").classList.remove("hide-tabs");
  document.getElementById("card-sub").textContent = "Log in to manage your parking, slots & payments.";
}
      function handleForgot() {
        clearErrors();
        const email = document.getElementById("forgot-email").value.trim();
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { setErr("forgot-email-err", "Please enter a valid email address."); return; }
        const btn = document.getElementById("forgot-btn");
        btn.classList.add("loading");
        setTimeout(() => {
          btn.classList.remove("loading");
          showToast("success", "Reset link sent to " + email);
          hideForgot();
          document.getElementById("forgot-email").value = "";
        }, 1800);
      }
      function socialLogin(p) { showToast("success", "Redirecting to " + p + " sign-in…"); }
      function showToast(type, msg) {
        const t = document.getElementById("toast");
        const ico = t.querySelector("i");
        document.getElementById("toast-msg").textContent = msg;
        t.className = "";
        ico.className = type === "success" ? "fa-solid fa-circle-check" : "fa-solid fa-circle-xmark";
        void t.offsetWidth; t.classList.add("show", type);
        setTimeout(() => t.classList.remove("show"), 3500);
      }
    </script>
  </body>
</html>
<?php
require __DIR__.'/customer/auth.php';
require_once __DIR__.'/admin/ActivityLogger.php';
$pdo = getPDO();
$logger = new ActivityLogger($pdo);
$error = '';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $email = trim($_POST['email'] ?? '');
    $pin = trim($_POST['pin'] ?? '');
    
    if($email === '' || $pin === ''){
        $error = 'Bitte geben Sie E-Mail und PIN ein.';
    }else{
        $stmt = $pdo->prepare('SELECT * FROM customers WHERE email = ?');
        $stmt->execute([$email]);
        $cust = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if(!$cust){
            $error = 'Ungültige E-Mail oder PIN.';
        }elseif(empty($cust['pin']) || empty($cust['pin_expires']) || strtotime($cust['pin_expires']) < time()){
            $error = 'PIN abgelaufen oder ungültig. Bitte fordern Sie eine neue PIN an.';
        }elseif(!password_verify($pin, $cust['pin'])){
            $error = 'Ungültige E-Mail oder PIN.';
            $_SESSION['pin_attempts'] = ($_SESSION['pin_attempts'] ?? 0) + 1;
            if (isset($cust['id'])) {
                $logger->logActivity($cust['id'], 'login_failed', [
                    'login_method' => 'pin',
                    'pin_attempts' => $_SESSION['pin_attempts'],
                    'failure_reason' => 'invalid_pin'
                ]);
            }
        }else{
            create_customer_session($cust['id']);
            $upd = $pdo->prepare('UPDATE customers SET last_login = NOW() WHERE id = ?');
            $upd->execute([$cust['id']]);
            $_SESSION['customer'] = $cust;
            $_SESSION['customer_login_time'] = time();
            $logger->logActivity($cust['id'], 'login', [
                'login_method' => 'pin',
                'login_success' => true,
                'pin_attempts' => $_SESSION['pin_attempts'] ?? 1
            ]);
            unset($_SESSION['pin_attempts']);
            header('Location: customer/index.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#4a90b8">
    <title>Anmelden - Anna Braun Lerncoaching</title>
    
    <style>
        :root {
            --primary: #4a90b8;
            --secondary: #52b3a4;
            --accent-green: #7cb342;
            --accent-teal: #26a69a;
            --light-blue: #e3f2fd;
            --white: #ffffff;
            --gray-light: #f8f9fa;
            --gray-medium: #6c757d;
            --gray-dark: #343a40;
            --shadow: rgba(0, 0, 0, 0.1);
            --error: #dc3545;
            --success: #28a745;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background: linear-gradient(135deg, var(--light-blue) 0%, var(--white) 100%);
            min-height: 100vh;
            color: var(--gray-dark);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.5;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .login-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 40px var(--shadow);
            width: 100%;
            max-width: 400px;
            overflow: hidden;
            animation: slideUp 0.6s ease-out;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .login-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 2rem 1.5rem;
            text-align: center;
        }

        .logo {
            width: 80px;
            height: 80px;
            margin: 0 auto 1rem;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
        }

        .login-header h1 {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .login-header p {
            opacity: 0.9;
            font-size: 0.95rem;
        }

        .login-form {
            padding: 2rem 1.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--gray-dark);
            font-size: 0.9rem;
        }

        .form-input {
            width: 100%;
            padding: 0.875rem 1rem;
            border: 2px solid #e1e5e9;
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: var(--gray-light);
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary);
            background: white;
            box-shadow: 0 0 0 3px rgba(74, 144, 184, 0.1);
        }

        .form-input.error {
            border-color: var(--error);
            background: #fff5f5;
        }

        .login-button {
            width: 100%;
            padding: 1rem;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(74, 144, 184, 0.3);
        }

        .login-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(74, 144, 184, 0.4);
        }

        .login-button:active {
            transform: translateY(0);
        }

        .login-button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .alert {
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }

        .alert-error {
            background: #fff5f5;
            color: var(--error);
            border: 1px solid #fed7d7;
        }

        .alert-success {
            background: #f0fff4;
            color: var(--success);
            border: 1px solid #c6f6d5;
        }

        .alert-icon {
            font-size: 1.2rem;
        }

        .pin-info {
            background: var(--light-blue);
            padding: 1.25rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            border-left: 4px solid var(--primary);
        }

        .pin-info h3 {
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
            color: var(--primary);
            font-weight: 600;
        }

        .pin-info p {
            font-size: 0.85rem;
            color: var(--gray-medium);
            line-height: 1.4;
            margin: 0;
        }

        @media (max-width: 480px) {
            .login-container {
                margin: 0.5rem;
                max-width: none;
            }
            
            .login-header {
                padding: 1.5rem 1rem;
            }
            
            .logo {
                width: 60px;
                height: 60px;
                font-size: 2rem;
            }
            
            .login-header h1 {
                font-size: 1.3rem;
            }
            
            .login-form {
                padding: 1.5rem 1rem;
            }
        }

        .loading {
            position: relative;
            overflow: hidden;
        }

        .loading::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
            animation: loading 1.5s infinite;
        }

        @keyframes loading {
            0% { left: -100%; }
            100% { left: 100%; }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="logo">🧠</div>
            <h1>Anna Braun Lerncoaching</h1>
            <p>Willkommen zurück</p>
        </div>

        <div class="login-form">
            <?php if($error): ?>
                <div class="alert alert-error">
                    <span class="alert-icon">⚠️</span>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <?php if(!empty($_GET['message'])): ?>
                <div class="alert alert-success">
                    <span class="alert-icon">✅</span>
                    <span><?= htmlspecialchars($_GET['message']) ?></span>
                </div>
            <?php endif; ?>

            <div class="pin-info">
                <h3>📧 PIN erforderlich</h3>
                <p>Sie benötigen eine aktuelle PIN, die an Ihre E-Mail-Adresse gesendet wurde. Falls Sie keine PIN erhalten haben, wenden Sie sich bitte an Ihren Berater.</p>
            </div>

            <form method="post" id="loginForm">
                <div class="form-group">
                    <label class="form-label" for="email">E-Mail-Adresse</label>
                    <input 
                        type="email" 
                        id="email"
                        name="email" 
                        class="form-input" 
                        placeholder="ihre.email@example.com"
                        value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label class="form-label" for="pin">PIN (6-stellig)</label>
                    <input 
                        type="password" 
                        id="pin"
                        name="pin" 
                        class="form-input" 
                        placeholder="123456"
                        maxlength="6"
                        pattern="[0-9]{6}"
                        required
                    >
                </div>

                <button type="submit" class="login-button" id="submitBtn">
                    <span id="submitText">Anmelden</span>
                </button>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('loginForm');
            const emailInput = document.getElementById('email');
            const pinInput = document.getElementById('pin');
            const submitBtn = document.getElementById('submitBtn');
            const submitText = document.getElementById('submitText');

            // Auto-format PIN input
            pinInput.addEventListener('input', function(e) {
                let value = e.target.value.replace(/\D/g, '').substring(0, 6);
                e.target.value = value;
                
                if (value.length === 6 && emailInput.value) {
                    setTimeout(() => form.submit(), 100);
                }
            });

            // Form submission with loading state
            form.addEventListener('submit', function(e) {
                submitBtn.disabled = true;
                submitBtn.classList.add('loading');
                submitText.textContent = 'Wird angemeldet...';
                
                setTimeout(() => {
                    submitBtn.disabled = false;
                    submitBtn.classList.remove('loading');
                    submitText.textContent = 'Anmelden';
                }, 3000);
            });

            // Email validation
            emailInput.addEventListener('blur', function() {
                if (this.value && !this.validity.valid) {
                    this.classList.add('error');
                } else {
                    this.classList.remove('error');
                }
            });

            emailInput.addEventListener('input', function() {
                this.classList.remove('error');
            });

            pinInput.addEventListener('input', function() {
                this.classList.remove('error');
            });

            // Focus management
            if (emailInput.value) {
                pinInput.focus();
            } else {
                emailInput.focus();
            }
        });
    </script>
</body>
</html>
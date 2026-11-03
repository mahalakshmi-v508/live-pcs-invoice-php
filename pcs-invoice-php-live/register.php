<?php
require_once __DIR__ . '/session.php';
ensureSessionStarted();
require_once __DIR__ . '/AuthService.php';

$auth = new AuthService();
$csrfToken = $auth->csrfToken();

include __DIR__ . '/header.php';
?>
<title>Registration page</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Overpass&display=swap">
<style>
    * {
        box-sizing: border-box;
    }

    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        margin: 0;
        padding: 0;
        background: linear-gradient(135deg, #0f1e45 0%, #1e3c72 100%);
        display: flex;
        justify-content: center;
        align-items: center;
        height: 100vh;
    }

    .register-wrapper {
        background: #ffffff;
        max-width: 350px;
        height: 500px;
        width: 100%;
        border-radius: 12px;
        box-shadow: 0 8px 30px rgba(0, 0, 0, 0.2);
        padding: 25px 20px;
        display: flex;
        flex-direction: column;
        justify-content: flex-start;
    }

    .logo {
        text-align: left;
        margin-bottom: 10px;
    }

    .logo img {
        max-width: 120px;
    }

    h4 {
        font-size: 22px;
        text-align: center;
        color: #222;
        margin-bottom: 20px;
    }

    .form-control {
        height: 36px;
        font-size: 13px;
        border-radius: 6px;
        margin-bottom: 12px;
    }

    .btn-primary {
        height: 38px;
        font-size: 14px;
        font-weight: bold;
        border-radius: 6px;
        background: linear-gradient(180deg, #29BFFF 0%, #8548D0 100%);
        color: #fff;
        border: none;
        width: 100%;
        margin-top: 10px;
    }

    .btn-primary:hover {
        opacity: 0.95;
    }

    @media (max-width: 480px) {
        .register-wrapper {
            height: auto;
            padding: 20px;
            margin: 10px;
        }
    }
    </style>
</head>

<body>
    <div class="register-wrapper">
        <div class="logo">
            <img src="<?php echo asset_url('img/PCS-Logo.png'); ?>" alt="PCS Logo">
        </div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <h4><b>Register Here</b></h4>
            <input type="text" name="first_name" class="form-control" placeholder="First Name" required>
            <input type="text" name="last_name" class="form-control" placeholder="Last Name">
            <input type="email" name="email" class="form-control" placeholder="Email" required>
            <input type="text" name="address" class="form-control" placeholder="Address">
            <input type="text" name="mobile" class="form-control" placeholder="Mobile">
            <input type="password" name="password" class="form-control" placeholder="Password (min 8 characters)"
                autocomplete="new-password" minlength="8" required>
            <button type="submit" name="invoice_user" class="btn-primary">Register</button>
        </form>
    </div>
</body>

</html>

<?php
if (isset($_POST['invoice_user'])) {
    if (!$auth->validateCsrf((string) ($_POST['csrf_token'] ?? ''))) {
        echo '<script>alert(' . json_encode('Your session expired. Please refresh the page and try again.') . ');</script>';
    } else {
        try {
            $auth->register(
                (string) ($_POST['first_name'] ?? ''),
                (string) ($_POST['last_name'] ?? ''),
                (string) ($_POST['email'] ?? ''),
                (string) ($_POST['address'] ?? ''),
                (string) ($_POST['mobile'] ?? ''),
                (string) ($_POST['password'] ?? '')
            );

            rememberEmailCookie((string) ($_POST['email'] ?? ''));

            echo '<script>alert(' . json_encode('Successfully Registered! You can now log in.') . ');</script>';
            echo "<script>window.location = 'index.php'</script>";
        } catch (AuthException $e) {
            echo '<script>alert(' . json_encode($e->getMessage()) . ');</script>';
        } catch (Throwable $e) {
            error_log('Registration error: ' . $e->getMessage());
            echo '<script>alert(' . json_encode('Unable to register right now. Please try again later.') . ');</script>';
        }
    }
}
?>
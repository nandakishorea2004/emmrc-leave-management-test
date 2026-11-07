<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Employee Leave Management System</title>

    <link rel="stylesheet" href="css/style.css">

    <link rel="stylesheet" href="css/responsive.css?v=1">
</head>

<body>

    <!-- Login Page -->
    <div class="login-page">

        <!-- Login Card -->
        <div class="login-card">

            <!-- EMMRC Logo -->
            <div class="logo-container">
                <img src="assets/emmrc logo.png" alt="EMMRC Logo">
            </div>

            <!-- Page Heading -->
            <h1>
                Educational Multimedia<br>
                Research Centre
            </h1>

            <p class="subtitle">
                Leave Management Portal
            </p>

            <!-- Login Form -->
            <form action="login.php" method="POST">

                <!-- Employee ID -->
                <div class="input-box">
                    <span class="input-icon"></span>

                    <input
                        type="text"
                        name="employee_id"
                        placeholder="Employee ID"
                        required
                    >
                </div>

                <!-- Password -->
                <div class="input-box">
                    <span class="input-icon"></span>

                    <input
                        type="password"
                        name="password"
                        placeholder="Password"
                        required
                    >
                </div>

                <!-- Login Button -->
                <button type="submit" class="login-button">
                    LOGIN
                </button>

            </form>

        </div>

    </div>

</body>

</html>
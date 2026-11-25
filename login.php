<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Login - HandyLink Solutions</title>

    <link rel="stylesheet" href="assets/css/style.css">

</head>

<body>

    <main>

        <section class="login-container">

            <img src="assets/images/handylink-logo.png"
                 alt="HandyLink Solutions Logo">

            <h1>Welcome Back</h1>

            <p>Login to your HandyLink account.</p>

            <form action="actions/login_action.php" method="POST">

                <label for="email">Email Address</label>

                <input
                    type="email"
                    id="email"
                    name="email"
                    placeholder="Enter your email"
                    required
                >

                <label for="password">Password</label>

                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Enter your password"
                    required
                >

                <button type="submit">
                    Login
                </button>

            </form>

            <p>
                Don't have an account?
                <a href="register.php">Create an account</a>
            </p>

        </section>

    </main>

</body>

</html>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Register - HandyLink Solutions</title>

    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>

    <main>

        <section class="register-container">

            <img src="assets/images/handylink-logo.png"
                 alt="HandyLink Solutions Logo">

            <h1>Create Your HandyLink Account</h1>

            <p>Join HandyLink Solutions today.</p>

            <form action="actions/register_action.php" method="POST">

                <label for="full_name">Full Name</label>
                <input
                    type="text"
                    id="full_name"
                    name="full_name"
                    placeholder="Enter your full name"
                    required
                >

                <label for="email">Email Address</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    placeholder="Enter your email"
                    required
                >

                <label for="phone">Phone Number</label>
                <input
                    type="tel"
                    id="phone"
                    name="phone"
                    placeholder="Enter your phone number"
                    required
                >

                <label for="location">Location</label>
                <input
                    type="text"
                    id="location"
                    name="location"
                    placeholder="e.g. Nairobi"
                    required
                >

                <label for="role">Account Type</label>
                <select id="role" name="role" required>
                    <option value="">Select account type</option>
                    <option value="client">Client</option>
                    <option value="provider">Service Provider</option>
                </select>

                <label for="password">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Create a password"
                    required
                >

                <label for="confirm_password">Confirm Password</label>
                <input
                    type="password"
                    id="confirm_password"
                    name="confirm_password"
                    placeholder="Confirm your password"
                    required
                >

                <button type="submit">
                    Create Account
                </button>

            </form>

            <p>
                Already have an account?
                <a href="login.php">Login here</a>
            </p>

        </section>

    </main>

</body>
</html>
<div class="auth-card">

    <span class="eyebrow">WELCOME BACK</span>

    <h2>Sign in to PLM FIND</h2>

    <p class="muted">
        Use your university email to access your account.
    </p>

    <form method="post" class="stack-form">

        <input type="hidden" name="action" value="login">

        <input
            type="hidden"
            name="csrf"
            value="<?= e(token()) ?>"
        >

        <label>
            PLM Email Address

            <input
                type="email"
                name="email"
                placeholder="you@plm.edu.ph"
                autocomplete="email"
                required
            >
        </label>

        <label>
            Password

            <input
                type="password"
                name="password"
                autocomplete="current-password"
                required
            >
        </label>

        <button class="primary-btn" type="submit">
            Sign in <span>→</span>
        </button>
    </form>

    <p class="auth-switch">
        New here?
        <a href="register.php">Create an account</a>
    </p>

</div>

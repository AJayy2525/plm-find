<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

if (current_user($db)) {
    go('dashboard');
}

// PROGRAM AND COLLEGE LIST
// Based on the registration reference provided.

$programColleges = [
    'Bachelor of Science in Accountancy' =>
        'College of Business Administration',

    'Bachelor of Science in Architecture' =>
        'College of Architecture and Sustainable Built Environments',

    'Bachelor of Science in Business Administration - Marketing Management' =>
        'College of Business Administration',

    'Bachelor of Science in Business Administration - Financial Management' =>
        'College of Business Administration',

    'Bachelor of Science in Business Administration - Human Resource Management' =>
        'College of Business Administration',

    'BSEd Major in Mathematics' =>
        'College of Education',

    'BSEd Major in Science' =>
        'College of Education',

    'BSEd Major in English' =>
        'College of Education',

    'BSEd Major in Social Studies' =>
        'College of Education',

    'BSEd Major in Filipino' =>
        'College of Education',

    'BSEd Major in Physical Education' =>
        'College of Education',

    'Bachelor of Science in Civil Engineering' =>
        'College of Engineering',

    'Bachelor of Science in Electrical Engineering' =>
        'College of Engineering',

    'Bachelor of Science in Electronics Engineering' =>
        'College of Engineering',

    'Bachelor of Arts in Communication' =>
        'College of Humanities & Social Sciences',

    'Bachelor of Arts in Political Science' =>
        'College of Humanities & Social Sciences',

    'Bachelor of Arts in Psychology' =>
        'College of Humanities & Social Sciences',

    'Bachelor of Science in Information Technology' =>
        'College of Information Systems and Technology Management',

    'Bachelor of Science in Computer Science' =>
        'College of Information Systems and Technology Management',

    'Bachelor of Science in Information Systems' =>
        'College of Information Systems and Technology Management',

    'Juris Doctor' =>
        'College of Law',

    'Doctor of Medicine' =>
        'College of Medicine',

    'Bachelor of Science in Nursing' =>
        'College of Nursing',

    'Bachelor of Science in Physical Therapy' =>
        'College of Physical Therapy',

    'Bachelor of Public Administration' =>
        'College of Public Administration',

    'Bachelor of Science in Biology' =>
        'College of Science',

    'Bachelor of Science in Chemistry' =>
        'College of Science',

    'Bachelor of Science in Mathematics' =>
        'College of Science',

    'Bachelor of Science in Tourism Management' =>
        'College of Tourism and Hospitality Management',

    'Bachelor of Science in Hospitality Management' =>
        'College of Tourism and Hospitality Management',

    'Bachelor of Science in Pharmacy' =>
        'College of Pharmacy',

    'Bachelor of Science in Radiologic Technology' =>
        'College of Allied Health',
];

// FORM VALUES
// Preserve input when validation fails.

$old = [
    'name' => '',
    'student_number' => '',
    'program' => '',
    'year_level' => '',
    'email' => '',
];

$error = '';

// REGISTRATION PROCESS

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    check_token();

    foreach (array_keys($old) as $key) {
        $old[$key] = trim(
            (string) ($_POST[$key] ?? '')
        );
    }

    $name = $old['name'];
    $number = $old['student_number'];
    $program = $old['program'];
    $year = filter_var(
        $old['year_level'],
        FILTER_VALIDATE_INT
    );
    $email = strtolower($old['email']);

    $old['email'] = $email;

    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    try {

        // VALIDATE FULL NAME

        if (
            mb_strlen($name) < 3 ||
            mb_strlen($name) > 120 ||
            !preg_match(
                "/^[\p{L}][\p{L}\p{M} .,'’\-]*$/u",
                $name
            )
        ) {
            throw new RuntimeException(
                'Enter a valid full name using letters.'
            );
        }

        // VALIDATE STUDENT NUMBER

        if (!preg_match(
            '/^\d{4}-\d{5}$/',
            $number
        )) {
            throw new RuntimeException(
                'Student number must be YYYY-12345.'
            );
        }

        // VALIDATE PROGRAM

        if (!array_key_exists(
            $program,
            $programColleges
        )) {
            throw new RuntimeException(
                'Select a valid program from the list.'
            );
        }

        // VALIDATE YEAR LEVEL

        if (
            $year === false ||
            $year < 1 ||
            $year > 7
        ) {
            throw new RuntimeException(
                'Choose a valid year level.'
            );
        }

        // VALIDATE PLM EMAIL

        if (
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            ) ||
            !preg_match(
                '/@plm\.edu\.ph$/i',
                $email
            )
        ) {
            throw new RuntimeException(
                'Only valid @plm.edu.ph email addresses are allowed.'
            );
        }

        // PASSWORD REQUIREMENTS

        if (
            strlen($password) < 10 ||
            !preg_match('/[A-Z]/', $password) ||
            !preg_match('/[a-z]/', $password) ||
            !preg_match('/[0-9]/', $password)
        ) {
            throw new RuntimeException(
                'Password needs at least 10 characters, uppercase, lowercase, and a number.'
            );
        }

        // CONFIRM PASSWORD

        if ($password !== $confirm) {
            throw new RuntimeException(
                'Passwords do not match.'
            );
        }

        // AUTOMATIC COLLEGE ASSIGNMENT
        // College is determined on the server.

        $college = $programColleges[$program];

        // SAVE USER INFORMATION

        $stmt = $db->prepare(
            'INSERT INTO users
            (
                full_name,
                student_number,
                email,
                password_hash,
                program,
                college,
                year_level,
                role
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );

        try {

            $stmt->execute([
                $name,
                $number,
                $email,
                password_hash(
                    $password,
                    PASSWORD_DEFAULT
                ),
                $program,
                $college,
                $year,
                'student'
            ]);

        } catch (PDOException $ex) {

            if ($ex->getCode() === '23000') {
                throw new RuntimeException(
                    'That email or student number is already registered.'
                );
            }

            throw $ex;
        }

        // SUCCESS

        notice(
            'Registration successful. You can now sign in.'
        );

        go('login');

    } catch (RuntimeException $ex) {

        $error = $ex->getMessage();

    } catch (Throwable $ex) {

        error_log((string) $ex);

        $error =
            'Registration could not be completed. Check your database setup.';
    }
}
?>

<!doctype html>
<html lang="en">

<head>

    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <meta
        name="theme-color"
        content="#721923"
    >

    <title>Create Account | PLM FIND</title>

    <link
        rel="stylesheet"
        href="assets/style.css?v=2"
    >

    <style>

        .register-card {
            max-width: 560px;
        }

        .field-hint {
            font-size: 12px;
            color: #82787b;
            margin: 4px 0 0;
        }

        .register-card input[readonly] {
            background: #f7f3f2;
            color: #675d60;
        }

        .show-password {
            display: flex !important;
            align-items: center;
            gap: 8px;
            font-weight: 600 !important;
        }

        .show-password input {
            width: auto;
            margin: 0;
            accent-color: #721923;
        }

        .helper-error {
            color: #a02f28;
            font-size: 12px;
            margin-top: 5px;
        }

        .register-card input.invalid {
            border-color: #a02f28;
        }

    </style>

</head>

<body class="auth-layout">

    <!-- LEFT AUTHENTICATION PANEL -->

    <div class="auth-aside">

        <a
            class="brand"
            href="index.php?page=login"
        >

            <span class="brand-mark">
                P.
            </span>

            <span>

                <strong>
                    PLM FIND
                </strong>

                <small>
                    LOST &amp; FOUND PORTAL
                </small>

            </span>

        </a>

        <div class="auth-statement">

            <span class="eyebrow light">
                YOUR CAMPUS. YOUR COMMUNITY.
            </span>

            <h1>
                Lost something?
                <br>
                <em>Find your way back.</em>
            </h1>

            <p>
                A simpler, safer way for the PLM
                community to reconnect with their belongings.
            </p>

        </div>

        <div class="auth-footnote">
            PLM · CAMPUS COMMUNITY
        </div>

    </div>

    <!-- REGISTRATION PANEL -->

    <main class="auth-main">

        <?php if ($error !== ''): ?>

            <div
                class="alert alert-error"
                role="alert"
            >
                <?= e($error) ?>
            </div>

        <?php endif; ?>

        <div class="auth-card register-card">

            <span class="eyebrow">
                JOIN THE COMMUNITY
            </span>

            <h2>
                Create your account
            </h2>

            <p class="muted">
                Register using your PLM student information.
            </p>

            <form
                method="post"
                class="stack-form"
                id="registrationForm"
            >

                <input
                    type="hidden"
                    name="csrf"
                    value="<?= e(token()) ?>"
                >

                <!-- FULL NAME -->

                <label for="nameInput">

                    Full Name

                    <input
                        id="nameInput"
                        name="name"
                        type="text"
                        maxlength="120"
                        autocomplete="name"
                        placeholder="Juan Dela Cruz"
                        value="<?= e($old['name']) ?>"
                        required
                    >

                </label>

                <!-- STUDENT NUMBER & YEAR -->

                <div class="two-fields">

                    <label for="studentInput">

                        Student Number

                        <input
                            id="studentInput"
                            name="student_number"
                            type="text"
                            maxlength="10"
                            inputmode="numeric"
                            pattern="[0-9]{4}-[0-9]{5}"
                            placeholder="2026-12345"
                            value="<?= e($old['student_number']) ?>"
                            required
                        >

                    </label>

                    <label for="yearInput">

                        Year Level

                        <select
                            id="yearInput"
                            name="year_level"
                            required
                        >

                            <option value="">
                                Select year level
                            </option>

                            <?php for ($i = 1; $i <= 7; $i++): ?>

                                <option
                                    value="<?= $i ?>"
                                    <?= (string) $i === $old['year_level'] ? 'selected' : '' ?>
                                >
                                    <?= $i ?><?= $i === 1 ? 'st' : ($i === 2 ? 'nd' : ($i === 3 ? 'rd' : 'th')) ?> Year
                                </option>

                            <?php endfor; ?>

                        </select>

                    </label>

                </div>

                <!-- PROGRAM SELECTION -->

                <label for="programInput">

                    Program / Course

                    <select
                        id="programInput"
                        name="program"
                        required
                    >

                        <option value="">
                            Select program
                        </option>

                        <?php foreach (
                            $programColleges as
                            $programName => $collegeName
                        ): ?>

                            <option
                                value="<?= e($programName) ?>"
                                data-college="<?= e($collegeName) ?>"
                                <?= $old['program'] === $programName ? 'selected' : '' ?>
                            >
                                <?= e($programName) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </label>

                <!-- AUTOMATIC COLLEGE -->

                <label for="collegeInput">

                    College (automatically assigned)

                    <input
                        id="collegeInput"
                        type="text"
                        readonly
                        placeholder="Select a program first"
                        aria-live="polite"
                    >

                </label>

                <!-- EMAIL -->

                <label for="emailInput">

                    PLM Email Address

                    <input
                        id="emailInput"
                        name="email"
                        type="email"
                        placeholder="you@plm.edu.ph"
                        autocomplete="email"
                        value="<?= e($old['email']) ?>"
                        required
                    >

                </label>

                <!-- PASSWORDS -->

                <div class="two-fields">

                    <label for="passwordInput">

                        Password

                        <input
                            id="passwordInput"
                            name="password"
                            type="password"
                            minlength="10"
                            autocomplete="new-password"
                            required
                        >

                    </label>

                    <label for="confirmInput">

                        Confirm Password

                        <input
                            id="confirmInput"
                            name="confirm_password"
                            type="password"
                            minlength="10"
                            autocomplete="new-password"
                            required
                        >

                    </label>

                </div>

                <p class="field-hint">
                    At least 10 characters, with an uppercase
                    letter, lowercase letter, and a number.
                </p>

                <!-- SHOW PASSWORD -->

                <label
                    class="show-password"
                    for="showPassword"
                >

                    <input
                        type="checkbox"
                        id="showPassword"
                    >

                    Show passwords

                </label>

                <!-- REGISTER BUTTON -->

                <button
                    type="submit"
                    class="primary-btn"
                >

                    Create account

                    <span>→</span>

                </button>

            </form>

            <p class="auth-switch">

                Already registered?

                <a href="index.php?page=login">
                    Sign in
                </a>

            </p>

        </div>

    </main>

    <!-- FORM INTERACTIONS -->

    <script>

    (() => {

        'use strict';

        const form =
            document.getElementById('registrationForm');

        const student =
            document.getElementById('studentInput');

        const program =
            document.getElementById('programInput');

        const college =
            document.getElementById('collegeInput');

        const email =
            document.getElementById('emailInput');

        const password =
            document.getElementById('passwordInput');

        const confirmPassword =
            document.getElementById('confirmInput');

        const showPassword =
            document.getElementById('showPassword');

        // AUTOMATIC COLLEGE

        function updateCollege() {

            college.value =
                program.selectedOptions[0]?.dataset.college
                || '';

        }

        // AUTOMATIC STUDENT NUMBER DASH

        student.addEventListener('input', () => {

            const digits = student.value
                .replace(/\D/g, '')
                .slice(0, 9);

            student.value = digits.length > 4
                ? digits.slice(0, 4) + '-' + digits.slice(4)
                : digits;

        });

        // PLM EMAIL VALIDATION

        function validateEmail() {

            const value = email.value.trim();

            email.setCustomValidity(
                value &&
                !/^[^\s@]+@plm\.edu\.ph$/i.test(value)
                    ? 'Please use your @plm.edu.ph email.'
                    : ''
            );

        }

        // PASSWORD VALIDATION

        function validatePassword() {

            const value = password.value;

            password.setCustomValidity(
                value &&
                !/^(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9]).{10,}$/.test(value)
                    ? 'Use 10+ characters, uppercase, lowercase and a number.'
                    : ''
            );

        }

        // CONFIRM PASSWORD VALIDATION

        function validateConfirm() {

            confirmPassword.setCustomValidity(
                confirmPassword.value &&
                confirmPassword.value !== password.value
                    ? 'Passwords do not match.'
                    : ''
            );

        }

        program.addEventListener(
            'change',
            updateCollege
        );

        email.addEventListener(
            'input',
            validateEmail
        );

        password.addEventListener('input', () => {

            validatePassword();

            validateConfirm();

        });

        confirmPassword.addEventListener(
            'input',
            validateConfirm
        );

        // SHOW / HIDE PASSWORDS

        showPassword.addEventListener('change', () => {

            const type = showPassword.checked
                ? 'text'
                : 'password';

            password.type = type;

            confirmPassword.type = type;

        });

        // FINAL FORM VALIDATION

        form.addEventListener('submit', (event) => {

            updateCollege();
            validateEmail();
            validatePassword();
            validateConfirm();

            if (!form.checkValidity()) {

                event.preventDefault();

                form.reportValidity();

            }

        });

        // INITIAL COLLEGE VALUE

        updateCollege();

    })();

    </script>

</body>
</html>

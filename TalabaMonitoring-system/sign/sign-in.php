<?php
    require ("../DB-connection/connect.php");
    session_start();

    // if (!isset($_SESSION['attemps'])) {
    //     $_SESSION['attemps'] = 3;
    // }

    // Prevent browser from serving cached dashboard after logout
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");
    header("Expires: 0");

    if (!isset($_SESSION['attempts'])) {
        $_SESSION['attempts'] = 3;
    }

    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        
        $email = htmlspecialchars($_POST["email"]);
        $password = $_POST["password"]; // Do NOT sanitize before password_verify()

        try {
            if ($_SESSION['attempts'] > 0) {
                $checks = $connection->prepare("SELECT * FROM myUsers WHERE email = ?");
                $checks->bind_param('s', $email);
                $checks->execute();
                $result_statement = $checks->get_result();

                if ($user = $result_statement->fetch_assoc()) {
                    if ($user["role"] !== "admin" && ((($user["accountLock"] ?? '') === 'LOCKED') || (($user["status"] ?? '') === 'Locked'))) {
                        echo "<script>alert('Account locked. Contact an administrator to unlock your account.');</script>";
                    } else if (password_verify($password, $user["password"])) {
                        $_SESSION["user_fname"] = $user['firstName'] . " " . $user['lastName'];
                        $_SESSION["user_id"] = $user["user_ID"];
                        $_SESSION["role"] = $user["role"];
                        $_SESSION['attempts'] = 3;

                        $statusUpdate = $connection->prepare("UPDATE myUsers SET status = 'online', accountLock = 'UNLOCKED' WHERE user_ID = ?");
                        $statusUpdate->bind_param('i', $user['user_ID']);
                        $statusUpdate->execute();

                        if ($user["role"] === "admin") {
                            header("Location: ../admin-dashboard/adrnin-dashboard.rtl.php");
                        } else {
                            header("Location: ../farm-dashboard/farm.dashboard.rtl www.php");
                        }
                        exit();
                    } else {
                        $_SESSION['attempts'] -= 1;
                        if ($_SESSION['attempts'] <= 0 && $user["role"] !== "admin") {
                            $lockQuery = $connection->prepare("UPDATE myUsers SET accountLock = 'LOCKED' WHERE user_ID = ?");
                            $lockQuery->bind_param('i', $user['user_ID']);
                            $lockQuery->execute();
                            echo "<script>alert('Your account has been locked after 3 failed login attempts. Contact an administrator.');</script>";
                        } else if ($user["role"] !== "admin") {
                            echo "<script>alert('Incorrect password. You have " . $_SESSION['attempts'] . " attempt(s) remaining.');</script>";
                        } else {
                            echo "<script>alert('Incorrect password. Please try again.');</script>";
                        }
                    }
                } else {
                    echo "<script>alert('No account is found with that email, try again');</script>";
                }
            } else {
                echo "<script>alert('Your account is locked due to too many failed attempts. Contact an administrator.');</script>";
            }
        }
        catch (mysqli_sql_exception $exp) {
            echo "error occur<br>" . $exp;
        }
        finally {
            $connection->close();
        }
    }
?>


<!doctype html>
<html lang="en" data-bs-theme="auto">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="">
        <meta name="author" content="Mark Otto, Jacob Thornton, and Bootstrap contributors">
        <meta name="generator" content="Astro v5.13.2">
        <title>Signin Template · Bootstrap v5.3</title>

        <link rel="canonical" href="https://getbootstrap.com/docs/5.3/examples/sign-in/">
        <script src="../assets/js/color-modes.js"></script>
        <link href="../assets/dist/css/bootstrap.min.css" rel="stylesheet">
        <meta name="theme-color" content="#712cf9">
        <link href="sign-in.css" rel="stylesheet">
    </head>

    <body class="d-flex align-items-center py-4 bg-body-tertiary">

        <svg xmlns="http://www.w3.org/2000/svg" class="d-none">
            <symbol id="check2" viewBox="0 0 16 16">
                <path d="M13.854 3.646a.5.5 0 0 1 0 .708l-7 7a.5.5 0 0 1-.708 0l-3.5-3.5a.5.5 0 1 1 .708-.708L6.5 10.293l6.646-6.647a.5.5 0 0 1 .708 0z"/>
            </symbol>
            <symbol id="circle-half" viewBox="0 0 16 16">
                <path d="M8 15A7 7 0 1 0 8 1v14zm0 1A8 8 0 1 1 8 0a8 8 0 0 1 0 16z"/>
            </symbol>
            <symbol id="moon-stars-fill" viewBox="0 0 16 16">
                <path d="M6 .278a.768.768 0 0 1 .08.858 7.208 7.208 0 0 0-.878 3.46c0 4.021 3.278 7.277 7.318 7.277.527 0 1.04-.055 1.533-.16a.787.787 0 0 1 .81.316.733.733 0 0 1-.031.893A8.349 8.349 0 0 1 8.344 16C3.734 16 0 12.286 0 7.71 0 4.266 2.114 1.312 5.124.06A.752.752 0 0 1 6 .278z"/>
                <path d="M10.794 3.148a.217.217 0 0 1 .412 0l.387 1.162c.173.518.579.924 1.097 1.097l1.162.387a.217.217 0 0 1 0 .412l-1.162.387a1.734 1.734 0 0 0-1.097 1.097l-.387 1.162a.217.217 0 0 1-.412 0l-.387-1.162A1.734 1.734 0 0 0 9.31 6.593l-1.162-.387a.217.217 0 0 1 0-.412l1.162-.387a1.734 1.734 0 0 0 1.097-1.097l.387-1.162zM13.863.099a.145.145 0 0 1 .274 0l.258.774c.115.346.386.617.732.732l.774.258a.145.145 0 0 1 0 .274l-.774.258a1.156 1.156 0 0 0-.732.732l-.258.774a.145.145 0 0 1-.274 0l-.258-.774a1.156 1.156 0 0 0-.732-.732l-.774-.258a.145.145 0 0 1 0-.274l.774-.258c.346-.115.617-.386.732-.732L13.863.1z"/>
            </symbol>
            <symbol id="sun-fill" viewBox="0 0 16 16">
                <path d="M8 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM8 0a.5.5 0 0 1 .5.5v2a.5.5 0 0 1-1 0v-2A.5.5 0 0 1 8 0zm0 13a.5.5 0 0 1 .5.5v2a.5.5 0 0 1-1 0v-2A.5.5 0 0 1 8 13zm8-5a.5.5 0 0 1-.5.5h-2a.5.5 0 0 1 0-1h2a.5.5 0 0 1 .5.5zM3 8a.5.5 0 0 1-.5.5h-2a.5.5 0 0 1 0-1h2A.5.5 0 0 1 3 8zm10.657-5.657a.5.5 0 0 1 0 .707l-1.414 1.415a.5.5 0 1 1-.707-.708l1.414-1.414a.5.5 0 0 1 .707 0zm-9.193 9.193a.5.5 0 0 1 0 .707L3.05 13.657a.5.5 0 0 1-.707-.707l1.414-1.414a.5.5 0 0 1 .707 0zm9.193 2.121a.5.5 0 0 1-.707 0l-1.414-1.414a.5.5 0 0 1 .707-.707l1.414 1.414a.5.5 0 0 1 0 .707zM4.464 4.465a.5.5 0 0 1-.707 0L2.343 3.05a.5.5 0 1 1 .707-.707l1.414 1.414a.5.5 0 0 1 0 .708z"/>
            </symbol>
        </svg>

        <!-- <div class="dropdown position-fixed bottom-0 end-0 mb-3 me-3 bd-mode-toggle">
            <button class="btn btn-bd-primary py-2 dropdown-toggle d-flex align-items-center"
                    id="bd-theme" type="button" aria-expanded="false"
                    data-bs-toggle="dropdown" aria-label="Toggle theme (auto)">
                <svg class="bi my-1 theme-icon-active" aria-hidden="true">
                    <use href="#circle-half"></use>
                </svg>
                <span class="visually-hidden" id="bd-theme-text">Toggle theme</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow" aria-labelledby="bd-theme-text">
                <li>
                    <button type="button" class="dropdown-item d-flex align-items-center"
                            data-bs-theme-value="light" aria-pressed="false">
                        <svg class="bi me-2 opacity-50" aria-hidden="true">
                            <use href="#sun-fill"></use>
                        </svg>
                        Light
                        <svg class="bi ms-auto d-none" aria-hidden="true">
                            <use href="#check2"></use>
                        </svg>
                    </button>
                </li>
                <li>
                    <button type="button" class="dropdown-item d-flex align-items-center"
                            data-bs-theme-value="dark" aria-pressed="false">
                        <svg class="bi me-2 opacity-50" aria-hidden="true">
                            <use href="#moon-stars-fill"></use>
                        </svg>
                        Dark
                        <svg class="bi ms-auto d-none" aria-hidden="true">
                            <use href="#check2"></use>
                        </svg>
                    </button>
                </li>
                <li>
                    <button type="button" class="dropdown-item d-flex align-items-center active"
                            data-bs-theme-value="auto" aria-pressed="true">
                        <svg class="bi me-2 opacity-50" aria-hidden="true">
                            <use href="#circle-half"></use>
                        </svg>
                        Auto
                        <svg class="bi ms-auto d-none" aria-hidden="true">
                            <use href="#check2"></use>
                        </svg>
                    </button>
                </li>
            </ul>
        </div> -->

        <main class="form-signin w-100 m-auto">
            <form action="sign-in.php" method="POST" >
                <!-- <img class="mb-4" src="../assets/brand/bootstrap-logo.svg" alt="" width="72" height="57"> -->
                <h1 class="h3 mb-3 fw-normal center-header">Sign In</h1>

                <div class="form-floating">
                    <input type="email" name="email" class="form-control" id="floatingInput" placeholder="name@example.com">
                    <label for="floatingInput">Email address</label>
                </div>

                <div class="form-floating">
                    <input type="password" name="password" class="form-control" id="floatingPassword" placeholder="Password">
                    <label for="floatingPassword">Password</label>
                </div>

                <div class="form-check text-start my-3">
                    <input class="form-check-input" type="checkbox" value="remember-me" id="checkDefault">
                    <label class="form-check-label" for="checkDefault">
                        Remember me
                    </label>
                </div>

                <button class="btn btn-primary w-100 py-2" type="submit">Sign in</button>
                <!-- <p class="mt-5 mb-3 text-body-secondary">&copy; 2017–2025</p> -->
                <a href="sign-up.php" class="center-header">Sign Up?</a>
            </form>
        </main>

        <script src="../assets/dist/js/bootstrap.bundle.min.js"></script>

    </body>
</html>
<?php

    require("../DB-connection/connect.php");
    session_start();

    // Set status to offline before ending the session
    if (isset($_SESSION["user_id"]) && isset($connection) && $connection) {
        $connection->query(
            "UPDATE myUsers SET status = 'offline' WHERE user_ID = " . (int)$_SESSION["user_id"]
        );
    }

    session_destroy();
    header("Location: sign-in.php");
    exit;

?>
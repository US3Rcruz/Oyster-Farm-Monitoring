<?php

    $server = "localhost";
    $db_username = "root";
    $db_password = "";
    $db_name = "talabaMonitoring";

    try {
        $connection = new mysqli($server, $db_username, $db_password, $db_name);

        if ($connection->connect_error) {
            die ("Connection failed: " . $connection->connect_error);
        }
    }
    catch (mysqli_sql_exception $exp) {
        echo "error occur<br>" . $exp;
    }

    // echo "<br><br>code end";
<?php
    include("../DB-connection/connect.php");
    session_start();

    // Prevent access without login
    if (!isset($_SESSION["user_id"])) {
        header("Location: ../sign/sign-in.php");
        exit();
    }

    // Resolve user_id from session (GET override kept for admin viewing a farmer)
    $user_id = null;
    if (isset($_GET['user_id'])) {
        $user_id = (int) $_GET['user_id'];
    } else {
        $user_id = (int) $_SESSION['user_id'];
    }

    // Status is set to "online" in sign-in.php and "offline" in sign-out.php

    $currentUser = [];
    $isGuest = false;
    if (isset($connection) && $connection && $user_id !== null) {
        $userCheck = $connection->prepare("SELECT * FROM myUsers WHERE user_ID = ?");
        $userCheck->bind_param("i", $user_id);
        $userCheck->execute();
        $userResult = $userCheck->get_result();
        $currentUser = $userResult->fetch_assoc() ?: [];
        $isGuest = strtolower($currentUser['role'] ?? '') === 'guest';
    }

    /* arrays  ||  database tables */
    $user = [];      // myUsers
    $farms = [];     // oysterFarm (active only)
    $allFarms = [];  // oysterFarm (all farms, including deleted)
    $harvest = [];   // harvestHistory
    $weather = [];   // weatherHistory
        $notifications = []; // notifications

    // Mark notifications as read
    if (isset($_POST["markNotificationsRead"])) {
        if ($isGuest) {
            echo "<script>window.alert('Guest accounts are read-only and cannot mark notifications.');</script>";
        } else {
            try {
                $q = $connection->prepare("UPDATE notifications SET is_read = TRUE WHERE user_ID = ? AND is_read = FALSE");
                $q->bind_param("i", $user_id);
                $q->execute();
                // Redirect to avoid re-POST
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
            } catch (mysqli_sql_exception $e) {
                echo "Mark read error: " . $e->getMessage();
            }
        }
    }
 
    // register new oyster farm
    if (isset($_POST["registerFarm"])) {
        if ($isGuest) {
            echo "<script>window.alert('Guest accounts are read-only and cannot register farms.');</script>";
        }
        else {
            try {
                // $breadingMethod = null;

                $location = htmlspecialchars($_POST["location"] ?? ""); // ternary operator
                $surfaceArea = htmlspecialchars($_POST["surfaceArea"] ?? "");
                $seedingDate = htmlspecialchars($_POST["seedingDate"] ?? "");
                $waterDepts = htmlspecialchars($_POST["waterDepts"] ?? "");
                $breadingMethod = $_POST["breedtype"] ?? null; // radio button

                $latitude    = htmlspecialchars($_POST["latitude"]  ?? "");  // ← ADD
                $longitude   = htmlspecialchars($_POST["longitude"] ?? "");  // ← ADD

                // if (
                //         $location == null || $location == "" ||
                //         $surfaceArea == null || $surfaceArea == "" ||
                //         $seedingDate == null || $seedingDate == "" ||
                //         $waterDepts == null || $waterDepts == "" ||
                //         $breadingMethod == null || $breadingMethod == ""
                //     )
                if (empty($location) || empty($surfaceArea) || empty($seedingDate) || empty($waterDepts) || empty($breadingMethod)) {
                    echo "<script>window.alert('Fill Up the Following Sections');</script>";
                    // echo "<script>window.alert('". $user_id . "');</script>"; // the user id had a value

                    // echo "<script>window.alert('" . $farms_q . "');</script>";
                }
                else {
                    if ($user_id != null) {

                        /** count the farm of the of the farmer */
                        $countFarm_query = $connection->prepare("SELECT COUNT(*) AS total FROM oysterFarm WHERE user_ID = ? AND deleted_at IS NULL");
                        $countFarm_query->bind_param("i", $user_id);
                        $countFarm_query->execute();
                        $result = $countFarm_query->get_result();
                        $farRow = $result->fetch_assoc();
                        /** automated naming of farm */
                        $farmNumber = $farRow['total'] + 1;
                        $farmName = "Farm #" . $farmNumber;


                        /** this hundle the image upload */
                        $imagePath = null;
                        if (isset($_FILES['farmImage']) && $_FILES['farmImage']['error'] === 0) {
                            $fileName = $_FILES['farmImage']['name'];
                            $tmpName = $_FILES['farmImage']['tmp_name'];
                            $fileSize = $_FILES['farmImage']['size'];

                            $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                            $allowed = ['jpg','jpeg','png'];

                            if (in_array($fileExt, $allowed)) {

                                if ($fileSize < 5000000) { // 5MB limit

                                    // new path structure: farmers/farms/{user_id}/
                                    $baseDir = "../upload-image/farmer/farms/" . $user_id . "/";

                                    if (!is_dir($baseDir)) {
                                        mkdir($baseDir, 0777, true);
                                    }

                                    $newFileName = uniqid("farm_", true) . "." . $fileExt;
                                    $destination = $baseDir . $newFileName;

                                    if (move_uploaded_file($tmpName, $destination)) {
                                        $imagePath = "farmer/farms/" . $user_id . "/" . $newFileName;
                                    }
                                }
                            }
                        }


                        // $checkS = $connection->prepare("SELECT * FROM oysterFarm WHERE user_ID = ?");
                        // $checkS->bind_param('s', $user_id);
                        // $checks->execute();
                        // $result_statement = $checks->get_result();
                        
                        // if ($result_statement->num_rows > 0) {
                            
                        // }
                        
                        /** insert the value to tha databse */
                        $addFarm_query = $connection->prepare(
                            "INSERT INTO oysterFarm (user_ID, location, latitude, longitude, surfaceArea, breedMethod, seedingDate, farmName_number, waterDepts, imagePath) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                        );
                        $addFarm_query->bind_param("issddsssss", $user_id, $location, $latitude, $longitude, $surfaceArea, $breadingMethod, $seedingDate, $farmName, $waterDepts, $imagePath);
                        if ($addFarm_query->execute())
                            echo "<script>window.alert('New Farm Registered');</script>";
                        else 
                            echo "<script>window.alert('New Farm Registration Attempt Failed');</script>";
                    }
                }

                
            }
            catch (mysqli_sql_exception $exp) {
                echo "error occur<br>" . $exp;
            }
        }
    }


    // update oyster farm
    if (isset($_POST["updateFarm"])) {
        if ($isGuest) {
            echo "<script>window.alert('Guest accounts are read-only and cannot update farms.');</script>";
        }
        else {
            try {
                $farmID        = (int) ($_POST["farmID"] ?? 0);
                $location      = htmlspecialchars($_POST["location"]    ?? "");
                $surfaceArea   = htmlspecialchars($_POST["surfaceArea"] ?? "");
                $seedingDate   = htmlspecialchars($_POST["seedingDate"] ?? "");
                $waterDepts    = htmlspecialchars($_POST["waterDepts"]  ?? "");
                $breadingMethod = $_POST["breedtype"] ?? null;

                if (!$farmID || empty($location) || empty($surfaceArea) || empty($seedingDate) || empty($waterDepts) || empty($breadingMethod)) {
                    echo "<script>window.alert('Please fill in all fields.');</script>";
                }
                else {

                    // Handle optional new image upload
                    $imageUpdate = "";
                    $params      = [$location, $surfaceArea, $breadingMethod, $seedingDate, $waterDepts];
                    $types       = "sssss";

                    if (isset($_FILES['farmImage']) && $_FILES['farmImage']['error'] === 0) {
                        $fileExt = strtolower(pathinfo($_FILES['farmImage']['name'], PATHINFO_EXTENSION));
                        $allowed = ['jpg', 'jpeg', 'png'];

                        if (in_array($fileExt, $allowed) && $_FILES['farmImage']['size'] < 5000000) {
                            $baseDir = "../upload-image/farmer/farms/" . $user_id . "/";
                            if (!is_dir($baseDir)) mkdir($baseDir, 0777, true);

                            $newFileName = uniqid("farm_", true) . "." . $fileExt;
                            if (move_uploaded_file($_FILES['farmImage']['tmp_name'], $baseDir . $newFileName)) {
                                $imageUpdate = ", imagePath = ?";
                                $params[]    = "farmer/farms/" . $user_id . "/" . $newFileName;
                                $types      .= "s";
                            }
                        }
                    }

                    $params[] = $farmID;
                    $types   .= "i";

                    $q = $connection->prepare(
                        "UPDATE oysterFarm 
                        SET location=?, surfaceArea=?, breedMethod=?, seedingDate=?, waterDepts=? $imageUpdate
                        WHERE farm_ID=?"
                    );
                    $q->bind_param($types, ...$params);

                    if ($q->execute())
                        echo "<script>window.alert('Farm updated successfully.');</script>";
                    else
                        echo "<script>window.alert('Update failed: " . $connection->error . "');</script>";
                }
            } catch (mysqli_sql_exception $exp) {
                echo "error occur<br>" . $exp;
            }
        }
    }


    // soft-delete farm (sets deleted_at timestamp instead of removing the row)
    if (isset($_POST["deleteFarm"])) {
        if ($isGuest) {
            echo "<script>window.alert('Guest accounts are read-only and cannot delete farms.');</script>";
        } else {
            try {
                $farmID = (int) ($_POST["farmID"] ?? 0);

                if (!$farmID) {
                    echo "<script>window.alert('Invalid farm ID.');</script>";
                } else {
                    $q = $connection->prepare("UPDATE oysterFarm SET deleted_at = NOW() WHERE farm_ID = ? AND user_ID = ?");
                    $q->bind_param("ii", $farmID, $user_id);

                    if ($q->execute())
                        echo "<script>window.alert('Farm deleted successfully.');</script>";
                    else
                        echo "<script>window.alert('Delete failed: " . $connection->error . "');</script>";
                }
            } catch (mysqli_sql_exception $exp) {
                echo "error occur<br>" . $exp;
            }
        }
    }


    // ---------------------------------------------------------------
    // Ensure oysterFarm has isHarvested column (run once, safe to repeat)
    // ---------------------------------------------------------------
    $connection->query(
        "ALTER TABLE oysterFarm ADD COLUMN IF NOT EXISTS isHarvested TINYINT(1) NOT NULL DEFAULT 0"
    );


    // ---------------------------------------------------------------
    // Record harvest → insert harvestHistory, clear farm seeding info
    // ---------------------------------------------------------------
    if (isset($_POST["harvestFarm"])) {
        if ($isGuest) {
            echo "<script>window.alert('Guest accounts are read-only and cannot record harvests.');</script>";
        } else {
            try {
                $farmID      = (int)   ($_POST["farmID"]      ?? 0);
                $harvestDate = htmlspecialchars($_POST["harvestDate"] ?? date("Y-m-d"));
                $quantity    = (float) ($_POST["quantity"]    ?? 0);

                if (!$farmID || $quantity <= 0) {
                    echo "<script>window.alert('Please fill in all harvest fields.');</script>";
                }
                else {
                    // Insert into harvestHistory
                    $ins = $connection->prepare(
                        "INSERT INTO harvestHistory (farm_ID, user_ID, harvestDate, quantity)
                        VALUES (?, ?, ?, ?)"
                    );
                    $ins->bind_param("iisd", $farmID, $user_id, $harvestDate, $quantity);

                    if ($ins->execute()) {
                        // Mark farm as harvested (clear seeding/breeding info so it shows "empty")
                        $upd = $connection->prepare(
                            "UPDATE oysterFarm
                            SET isHarvested = 1, seedingDate = NULL, breedMethod = NULL
                            WHERE farm_ID = ?"
                        );
                        $upd->bind_param("i", $farmID);
                        $upd->execute();

                        // echo "<script>window.alert('Harvest recorded successfully!'); window.location.reload();</script>";
                        echo "<script>window.alert('Harvest recorded successfully!');</script>";
                    } else {
                        echo "<script>window.alert('Failed to record harvest: " . $connection->error . "');</script>";
                    }
                }
            } catch (mysqli_sql_exception $exp) {
                echo "error occur<br>" . $exp;
            }
        }
    }


    // ---------------------------------------------------------------
    // Update farmer profile (name, address, email, phone, sex, birth,
    // password, and optional profile photo)
    // ---------------------------------------------------------------
    if (isset($_POST["updateProfile"])) {
        if ($isGuest) {
            echo "<script>window.alert('Guest accounts are read-only and cannot update profiles.');</script>";
        } else {
            try {
                $firstName  = htmlspecialchars(trim($_POST["firstName"]  ?? ""));
                $lastName   = htmlspecialchars(trim($_POST["lastName"]   ?? ""));
                $middleName = htmlspecialchars(trim($_POST["middleName"] ?? ""));
                $address    = htmlspecialchars(trim($_POST["address"]    ?? ""));
                $email      = htmlspecialchars(trim($_POST["email"]      ?? ""));
                $contactNo  = htmlspecialchars(trim($_POST["contactNo"]  ?? ""));
                $sex        = htmlspecialchars(trim($_POST["sex"]        ?? ""));
                $birthDate  = htmlspecialchars(trim($_POST["birthDate"]  ?? ""));
                $newPassword = trim($_POST["newPassword"] ?? "");

                if (empty($firstName) || empty($lastName) || empty($email)) {
                    echo "<script>window.alert('First name, last name, and email are required.');</script>";
                } else {
                    // --- optional profile photo upload ---
                    $profileImageUpdate = "";
                    $profileParams      = [$firstName, $lastName, $middleName, $address, $email, $contactNo, $sex, $birthDate];
                    $profileTypes       = "ssssssss";

                    if (isset($_FILES['profileImage']) && $_FILES['profileImage']['error'] === 0) {
                        $fileExt = strtolower(pathinfo($_FILES['profileImage']['name'], PATHINFO_EXTENSION));
                        $allowed = ['jpg', 'jpeg', 'png'];

                        if (in_array($fileExt, $allowed) && $_FILES['profileImage']['size'] < 5000000) {
                            // new path: farmers/profiles/{user_id}/
                            $profileDir = "../upload-image/farmer/profiles/" . $user_id . "/";
                            if (!is_dir($profileDir)) mkdir($profileDir, 0777, true);

                            $newFileName = uniqid("profile_", true) . "." . $fileExt;
                            if (move_uploaded_file($_FILES['profileImage']['tmp_name'], $profileDir . $newFileName)) {
                                $profileImageUpdate  = ", imagePath = ?";
                                // store relative path so it stays portable
                                $profileParams[] = "farmer/profiles/" . $user_id . "/" . $newFileName;
                                $profileTypes .= "s";
                            }
                        }
                    }

                    // --- optional password change ---
                    $passwordUpdate = "";
                    if (!empty($newPassword)) {
                        $hashedPw        = password_hash($newPassword, PASSWORD_DEFAULT);
                        $passwordUpdate  = ", password = ?";
                        $profileParams[] = $hashedPw;
                        $profileTypes   .= "s";
                    }

                    $profileParams[] = $user_id;
                    $profileTypes   .= "i";

                    $pq = $connection->prepare(
                        "UPDATE myUsers
                        SET firstName=?, lastName=?, middleName=?, address=?, email=?,
                            contactNo=?, sex=?, birthDate=? $profileImageUpdate $passwordUpdate
                        WHERE user_ID=?"
                    );
                    $pq->bind_param($profileTypes, ...$profileParams);

                    if ($pq->execute())
                        echo "<script>window.alert('Profile updated successfully.');</script>";
                    else
                        echo "<script>window.alert('Profile update failed: " . $connection->error . "');</script>";
                }
            } catch (mysqli_sql_exception $exp) {
                echo "Profile update error: " . $exp->getMessage();
            }
        }
    }

    /**
     * Submit farmer report
     */
    if (isset($_POST['submitReport'])) {
        if ($isGuest) {
            echo "<script>window.alert('Guest accounts are read-only and cannot submit reports.');</script>";
        } else {
            try {
                $reportType = htmlspecialchars(trim($_POST["reportType"]  ?? "other"));
                $subject = "";
                // subject inout accross the report modal
                $otherSubject = htmlspecialchars(trim($_POST["other-subject"] ?? ""));
                $requestSubject = htmlspecialchars(trim($_POST["request-subject"] ?? ""));
                $damageSubject = htmlspecialchars(trim($_POST["damage-subject"] ?? ""));
                $feedbackSubject = htmlspecialchars(trim($_POST["feedback-subject"] ?? ""));
                $description = "";
                $otherDescription = htmlspecialchars(trim($_POST["other-description"] ?? ""));
                $requestDescription = htmlspecialchars(trim($_POST["request-description"] ?? ""));
                $damageDescription = htmlspecialchars(trim($_POST["damage-description"] ?? ""));
                $feedbackDescription = htmlspecialchars(trim($_POST["feadback-description"] ?? ""));
                // type-specific fields
                $farmID_r = !empty($_POST["farmID_r"]) ? (int)$_POST["farmID_r"] : null;
                $damageTypes = !empty($_POST["damageTypes"]) ? htmlspecialchars(implode(", ", (array)$_POST["damageTypes"])) : null;
                $damageDate = !empty($_POST["damageDate"]) ? htmlspecialchars($_POST["damageDate"]) : null;
                $estimatedLoss = !empty($_POST["estimatedLoss"]) ? (float)$_POST["estimatedLoss"] : null;
                $feedbackType = !empty($_POST["feedbackType"]) ? htmlspecialchars($_POST["feedbackType"]) : null;
                $pageAffected = !empty($_POST["pageAffected"]) ? htmlspecialchars($_POST["pageAffected"]) : null;
                $priority = !empty($_POST["priority"]) ? htmlspecialchars($_POST["priority"]) : null;
                $requestCategory = !empty($_POST["requestCategory"]) ? htmlspecialchars($_POST["requestCategory"]) : null;
                $urgency = !empty($_POST["urgency"]) ? htmlspecialchars($_POST["urgency"]) : null;
    
                if (!empty($otherSubject) || !empty($requestSubject) || !empty($damageSubject) || !empty($feedbackSubject)) {
                    
                    // all the subjetcts from different divs
                    $subject .= $damageSubject . $feedbackSubject . $requestSubject . $otherSubject;
                    $description .= $damageDescription . $feedbackDescription . $requestDescription . $otherDescription;

                    // --- optional attachment upload ---
                    $imagePath = null;
                    if (isset($_FILES["reportAttachment"]) && $_FILES["reportAttachment"]["error"] === 0) {
                        $ext     = strtolower(pathinfo($_FILES["reportAttachment"]["name"], PATHINFO_EXTENSION));
                        $allowed = ["jpg","jpeg","png","pdf","doc","docx"];
                        if (in_array($ext, $allowed) && $_FILES["reportAttachment"]["size"] < 10000000) {
                            $rDir = "../upload-image/farmer/report/" . $user_id . "/";
                            if (!is_dir($rDir)) mkdir($rDir, 0777, true);
                            $rFile = uniqid("report_", true) . "." . $ext;
                            if (move_uploaded_file($_FILES["reportAttachment"]["tmp_name"], $rDir . $rFile)) {
                                $imagePath = "farmer/report/" . $user_id . "/" . $rFile;
                            }
                        }
                    }

                    $ins = $connection->prepare(
                        "INSERT INTO farmersReports
                            (user_ID, report_category, subject, description, farm_ID, damage_types, damage_date, estimated_loss, feedback_type, page_affected, priority, request_category, urgency, imagePath)
                        VALUES
                            (?,?,?,?, ?,?,?,?, ?,?,?, ?,?,?)"
                    );
                    $ins->bind_param(
                        "isss" . "issd" . "sss" . "sss",
                        $user_id, $reportType, $subject, $description,
                        $farmID_r, $damageTypes, $damageDate, $estimatedLoss,
                        $feedbackType, $pageAffected, $priority,
                        $requestCategory, $urgency, $imagePath
                    );

                    if ($ins->execute()) {
                        echo "<script>window.alert('Report submitted successfully! The admin will review it.'); window.location.href=window.location.href;</script>";
                    } else {
                        echo "<script>window.alert('Failed to submit report: " . $connection->error . "');</script>";
                    }
                }
                else {
                    echo "<script>window.alert('Please fill in the Subject field.');</script>";
                }
            } catch (mysqli_sql_exception $exp) {
                echo "Report submission error: " . $exp->getMessage();
            }
        }
            
    }

    try {
        // Check if connection exists
        if (!isset($connection) || !$connection)  throw new Exception("Database connection failed");

        /**
         farmers
         */ 
        if ($user_id !== null)  $user_q = "SELECT * FROM myUsers WHERE user_ID = " . $user_id;
        else  $user_q = "SELECT * FROM myUsers";

        $user_tb = $connection->query($user_q);

        if (!$user_tb)  throw new Exception("Failed to fetch users: " . $connection->error);
        while ($user_row = $user_tb->fetch_assoc())  $user[] = $user_row;

        /**
         oyster farms
         */
        if ($user_id !== null)  $farms_q = "SELECT * FROM oysterFarm WHERE user_ID = " . $user_id;
        else  $farms_q = "SELECT * FROM oysterFarm";

        $farms_tb = $connection->query($farms_q);

        if (!$farms_tb)  throw new Exception("Failed to fetch farms: " . $connection->error);
        while ($farm_row = $farms_tb->fetch_assoc()) {
            $allFarms[] = $farm_row;
            if (empty($farm_row['deleted_at'])) {
                $farms[] = $farm_row;
            }
        }

        /**
         harvest history
         */
        if ($user_id !== null)  $harvest_q = "SELECT * FROM harvestHistory WHERE user_ID = " . $user_id . " AND deleted_at IS NULL";
        else  $harvest_q = "SELECT * FROM harvestHistory WHERE deleted_at IS NULL";

        $harvest_tb = $connection->query($harvest_q);

        if (!$harvest_tb)  throw new Exception("Failed to fetch harvest history: " . $connection->error);
        while ($harvest_row = $harvest_tb->fetch_assoc())  $harvest[] = $harvest_row;

        /**
         weather
         */
        if ($user_id !== null)  $weather_q = "SELECT * FROM weatherHistory WHERE user_ID = " . $user_id . " AND deleted_at IS NULL";
        else  $weather_q = "SELECT * FROM weatherHistory WHERE deleted_at IS NULL";
        
        $weather_tb = $connection->query($weather_q);

        if (!$weather_tb)  throw new Exception("Failed to fetch weather history: " . $connection->error);
        while ($weather_row = $weather_tb->fetch_assoc())  $weather[] = $weather_row;

        /**
         notifications
         */
        $notif_q = "SELECT * FROM notifications WHERE user_ID = " . $user_id . " ORDER BY created_at DESC";
        $notif_tb = $connection->query($notif_q);
        if (!$notif_tb)  throw new Exception("Failed to fetch notifications: " . $connection->error);
        while ($notif_row = $notif_tb->fetch_assoc())  $notifications[] = $notif_row;

        $unread_notifications = count(array_filter($notifications, fn($n) => !$n['is_read']));
        
    }
    catch (Exception $exp) {
        echo "
            <script>
                window.alert('Error: " . htmlspecialchars($exp->getMessage()) . "');
            </script>
        ";
        error_log($exp->getMessage());
    }


    // ---------------------------------------------------------------
    // Prepare harvest history JSON for the chart (injected into page)
    // Groups records by farm so the chart can render one line per farm.
    // ---------------------------------------------------------------
    $harvestChartData = [];
    foreach ($harvest as $row) {
        $farmLabel = 'Farm #' . $row['farm_ID'];   // fallback label
        // Try to use the real farm name from all farms, including deleted ones.
        foreach ($allFarms as $f) {
            if ((int)$f['farm_ID'] === (int)$row['farm_ID']) {
                $farmLabel = $f['farmName_number'];
                break;
            }
        }
        $harvestChartData[] = [
            'farm_id'     => (int)$row['farm_ID'],
            'farm_name'   => $farmLabel,
            'harvestDate' => $row['harvestDate'],       // YYYY-MM-DD
            'quantity'    => (float)$row['quantity'],
        ];
    }

    // ---------------------------------------------------------------
    // Re-seed a harvested farm (sets new seedingDate + breedMethod)
    // ---------------------------------------------------------------
    if (isset($_POST["reseedFarm"])) {
        if ($isGuest) {
            echo "<script>window.alert('Guest accounts are read-only and cannot re-seed farms.');</script>";
        }
        else {
                try {
                    $farmID        = (int) ($_POST["farmID"]     ?? 0);
                    $seedingDate   = htmlspecialchars($_POST["seedingDate"]  ?? "");
                    $breedingMethod = $_POST["breedtype"] ?? null;

                if (!$farmID || empty($seedingDate) || empty($breedingMethod)) {
                    echo "<script>window.alert('Please fill in all re-seeding fields.');</script>";
                } else {
                    $upd = $connection->prepare(
                        "UPDATE oysterFarm
                        SET isHarvested = 0, seedingDate = ?, breedMethod = ?
                        WHERE farm_ID = ?"
                    );
                    $upd->bind_param("ssi", $seedingDate, $breedingMethod, $farmID);

                    if ($upd->execute())
                        // echo "<script>window.alert('Farm re-seeded successfully!'); window.location.reload();</script>";
                        echo "<script>window.alert('Farm re-seeded successfully!');</script>";
                    else
                        echo "<script>window.alert('Re-seed failed: " . $connection->error . "');</script>";
                }
            } catch (mysqli_sql_exception $exp) {
                echo "error occur<br>" . $exp;
            }
        }
    }
?>


<!doctype html>
<html lang="en" data-bs-theme="auto">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <meta name="description" content="" />
        <meta
            name="author"
            content="Mark Otto, Jacob Thornton, and Bootstrap contributors"
        />
        <meta name="generator" content="Astro v5.13.2" />
        <title>Oyster Farm</title>
        <link
            rel="canonical"
            href="https://getbootstrap.com/docs/5.3/examples/dashboard/"
        />
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"> <!-- image icon for add farm modal -->
        <script>
            /* Harvest history from PHP – used by initPerformanceChart() in dashboard.js */
            const HARVEST_DATA = <?= json_encode($harvestChartData, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
            /* Farms data for weather alerts */
            const FARMS_DATA = <?= json_encode($farms, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        </script>
        <script src="../assets/js/color-modes.js"></script>
        <link href="../assets/dist/css/bootstrap.min.css" rel="stylesheet" />
        <meta name="theme-color" content="#712cf9" />
        <link href="dashboard.css" rel="stylesheet" />
        <link href="harvest-additions.css" rel="stylesheet" />
        <link href="dashboard-ocean-theme.css" rel="stylesheet" />
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
        <!-- <link href="dashboard.rtl" rel="stylesheet" /> -->
    </head>
    <body>
        <svg xmlns="http://www.w3.org/2000/svg" class="d-none">
            <symbol id="check2" viewBox="0 0 16 16">
                <path
                    d="M13.854 3.646a.5.5 0 0 1 0 .708l-7 7a.5.5 0 0 1-.708 0l-3.5-3.5a.5.5 0 1 1 .708-.708L6.5 10.293l6.646-6.647a.5.5 0 0 1 .708 0z"
                ></path>
            </symbol>
            <symbol id="circle-half" viewBox="0 0 16 16">
                <path
                    d="M8 15A7 7 0 1 0 8 1v14zm0 1A8 8 0 1 1 8 0a8 8 0 0 1 0 16z"
                ></path>
            </symbol>
            <symbol id="moon-stars-fill" viewBox="0 0 16 16">
                <path
                    d="M6 .278a.768.768 0 0 1 .08.858 7.208 7.208 0 0 0-.878 3.46c0 4.021 3.278 7.277 7.318 7.277.527 0 1.04-.055 1.533-.16a.787.787 0 0 1 .81.316.733.733 0 0 1-.031.893A8.349 8.349 0 0 1 8.344 16C3.734 16 0 12.286 0 7.71 0 4.266 2.114 1.312 5.124.06A.752.752 0 0 1 6 .278z"
                ></path>
                <path
                    d="M10.794 3.148a.217.217 0 0 1 .412 0l.387 1.162c.173.518.579.924 1.097 1.097l1.162.387a.217.217 0 0 1 0 .412l-1.162.387a1.734 1.734 0 0 0-1.097 1.097l-.387 1.162a.217.217 0 0 1-.412 0l-.387-1.162A1.734 1.734 0 0 0 9.31 6.593l-1.162-.387a.217.217 0 0 1 0-.412l1.162-.387a1.734 1.734 0 0 0 1.097-1.097l.387-1.162zM13.863.099a.145.145 0 0 1 .274 0l.258.774c.115.346.386.617.732.732l.774.258a.145.145 0 0 1 0 .274l-.774.258a1.156 1.156 0 0 0-.732.732l-.258.774a.145.145 0 0 1-.274 0l-.258-.774a1.156 1.156 0 0 0-.732-.732l-.774-.258a.145.145 0 0 1 0-.274l.774-.258c.346-.115.617-.386.732-.732L13.863.1z"
                ></path>
            </symbol>
            <symbol id="sun-fill" viewBox="0 0 16 16">
                <path
                    d="M8 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM8 0a.5.5 0 0 1 .5.5v2a.5.5 0 0 1-1 0v-2A.5.5 0 0 1 8 0zm0 13a.5.5 0 0 1 .5.5v2a.5.5 0 0 1-1 0v-2A.5.5 0 0 1 8 13zm8-5a.5.5 0 0 1-.5.5h-2a.5.5 0 0 1 0-1h2a.5.5 0 0 1 .5.5zM3 8a.5.5 0 0 1-.5.5h-2a.5.5 0 0 1 0-1h2A.5.5 0 0 1 3 8zm10.657-5.657a.5.5 0 0 1 0 .707l-1.414 1.415a.5.5 0 1 1-.707-.708l1.414-1.414a.5.5 0 0 1 .707 0zm-9.193 9.193a.5.5 0 0 1 0 .707L3.05 13.657a.5.5 0 0 1-.707-.707l1.414-1.414a.5.5 0 0 1 .707 0zm9.193 2.121a.5.5 0 0 1-.707 0l-1.414-1.414a.5.5 0 0 1 .707-.707l1.414 1.414a.5.5 0 0 1 0 .707zM4.464 4.465a.5.5 0 0 1-.707 0L2.343 3.05a.5.5 0 1 1 .707-.707l1.414 1.414a.5.5 0 0 1 0 .708z"
                ></path>
            </symbol>
        </svg>
        <div
            class="dropdown position-fixed bottom-0 end-0 mb-3 me-3 bd-mode-toggle"
        >
            <button
                class="btn btn-bd-primary py-2 dropdown-toggle d-flex align-items-center"
                id="bd-theme"
                type="button"
                aria-expanded="false"
                data-bs-toggle="dropdown"
                aria-label="Toggle theme (auto)"
            >
                <svg class="bi my-1 theme-icon-active" aria-hidden="true">
                    <use href="#circle-half"></use>
                </svg>
                <span class="visually-hidden" id="bd-theme-text">Toggle theme</span>
            </button>
            <ul
                class="dropdown-menu dropdown-menu-end shadow"
                aria-labelledby="bd-theme-text"
            >
                <li>
                    <button
                        type="button"
                        class="dropdown-item d-flex align-items-center"
                        data-bs-theme-value="light"
                        aria-pressed="false"
                    >
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
                    <button
                        type="button"
                        class="dropdown-item d-flex align-items-center"
                        data-bs-theme-value="dark"
                        aria-pressed="false"
                    >
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
                    <button
                        type="button"
                        class="dropdown-item d-flex align-items-center active"
                        data-bs-theme-value="auto"
                        aria-pressed="true"
                    >
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
        </div>
        <svg xmlns="http://www.w3.org/2000/svg" class="d-none">
            <symbol id="calendar3" viewBox="0 0 16 16">
                <path
                    d="M14 0H2a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V2a2 2 0 0 0-2-2zM1 3.857C1 3.384 1.448 3 2 3h12c.552 0 1 .384 1 .857v10.286c0 .473-.448.857-1 .857H2c-.552 0-1-.384-1-.857V3.857z"
                ></path>
                <path
                    d="M6.5 7a1 1 0 1 0 0-2 1 1 0 0 0 0 2zm3 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2zm3 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2zm-9 3a1 1 0 1 0 0-2 1 1 0 0 0 0 2zm3 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2zm3 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2zm3 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2zm-9 3a1 1 0 1 0 0-2 1 1 0 0 0 0 2zm3 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2zm3 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2z"
                ></path>
            </symbol>
            <symbol id="cart" viewBox="0 0 16 16">
                <path
                    d="M0 1.5A.5.5 0 0 1 .5 1H2a.5.5 0 0 1 .485.379L2.89 3H14.5a.5.5 0 0 1 .49.598l-1 5a.5.5 0 0 1-.465.401l-9.397.472L4.415 11H13a.5.5 0 0 1 0 1H4a.5.5 0 0 1-.491-.408L2.01 3.607 1.61 2H.5a.5.5 0 0 1-.5-.5zM3.102 4l.84 4.479 9.144-.459L13.89 4H3.102zM5 12a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm7 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm-7 1a1 1 0 1 1 0 2 1 1 0 0 1 0-2zm7 0a1 1 0 1 1 0 2 1 1 0 0 1 0-2z"
                ></path>
            </symbol>
            <symbol id="chevron-right" viewBox="0 0 16 16">
                <path
                    fill-rule="evenodd"
                    d="M4.646 1.646a.5.5 0 0 1 .708 0l6 6a.5.5 0 0 1 0 .708l-6 6a.5.5 0 0 1-.708-.708L10.293 8 4.646 2.354a.5.5 0 0 1 0-.708z"
                ></path>
            </symbol>
            <symbol id="door-closed" viewBox="0 0 16 16">
                <path
                    d="M3 2a1 1 0 0 1 1-1h8a1 1 0 0 1 1 1v13h1.5a.5.5 0 0 1 0 1h-13a.5.5 0 0 1 0-1H3V2zm1 13h8V2H4v13z"
                ></path>
                <path d="M9 9a1 1 0 1 0 2 0 1 1 0 0 0-2 0z"></path>
            </symbol>
            <symbol id="file-earmark" viewBox="0 0 16 16">
                <path
                    d="M14 4.5V14a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V2a2 2 0 0 1 2-2h5.5L14 4.5zm-3 0A1.5 1.5 0 0 1 9.5 3V1H4a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V4.5h-2z"
                ></path>
            </symbol>
            <symbol id="file-earmark-text" viewBox="0 0 16 16">
                <path
                    d="M5.5 7a.5.5 0 0 0 0 1h5a.5.5 0 0 0 0-1h-5zM5 9.5a.5.5 0 0 1 .5-.5h5a.5.5 0 0 1 0 1h-5a.5.5 0 0 1-.5-.5zm0 2a.5.5 0 0 1 .5-.5h2a.5.5 0 0 1 0 1h-2a.5.5 0 0 1-.5-.5z"
                ></path>
                <path
                    d="M9.5 0H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V4.5L9.5 0zm0 1v2A1.5 1.5 0 0 0 11 4.5h2V14a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1h5.5z"
                ></path>
            </symbol>
            <symbol id="gear-wide-connected" viewBox="0 0 16 16">
                <path
                    d="M7.068.727c.243-.97 1.62-.97 1.864 0l.071.286a.96.96 0 0 0 1.622.434l.205-.211c.695-.719 1.888-.03 1.613.931l-.08.284a.96.96 0 0 0 1.187 1.187l.283-.081c.96-.275 1.65.918.931 1.613l-.211.205a.96.96 0 0 0 .434 1.622l.286.071c.97.243.97 1.62 0 1.864l-.286.071a.96.96 0 0 0-.434 1.622l.211.205c.719.695.03 1.888-.931 1.613l-.284-.08a.96.96 0 0 0-1.187 1.187l.081.283c.275.96-.918 1.65-1.613.931l-.205-.211a.96.96 0 0 0-1.622.434l-.071.286c-.243.97-1.62.97-1.864 0l-.071-.286a.96.96 0 0 0-1.622-.434l-.205.211c-.695.719-1.888.03-1.613-.931l.08-.284a.96.96 0 0 0-1.186-1.187l-.284.081c-.96.275-1.65-.918-.931-1.613l.211-.205a.96.96 0 0 0-.434-1.622l-.286-.071c-.97-.243-.97-1.62 0-1.864l.286-.071a.96.96 0 0 0 .434-1.622l-.211-.205c-.719-.695-.03-1.888.931-1.613l.284.08a.96.96 0 0 0 1.187-1.186l-.081-.284c-.275-.96.918-1.65 1.613-.931l.205.211a.96.96 0 0 0 1.622-.434l.071-.286zM12.973 8.5H8.25l-2.834 3.779A4.998 4.998 0 0 0 12.973 8.5zm0-1a4.998 4.998 0 0 0-7.557-3.779l2.834 3.78h4.723zM5.048 3.967c-.03.021-.058.043-.087.065l.087-.065zm-.431.355A4.984 4.984 0 0 0 3.002 8c0 1.455.622 2.765 1.615 3.678L7.375 8 4.617 4.322zm.344 7.646.087.065-.087-.065z"
                ></path>
            </symbol>
            <symbol id="graph-up" viewBox="0 0 16 16">
                <path
                    fill-rule="evenodd"
                    d="M0 0h1v15h15v1H0V0Zm14.817 3.113a.5.5 0 0 1 .07.704l-4.5 5.5a.5.5 0 0 1-.74.037L7.06 6.767l-3.656 5.027a.5.5 0 0 1-.808-.588l4-5.5a.5.5 0 0 1 .758-.06l2.609 2.61 4.15-5.073a.5.5 0 0 1 .704-.07Z"
                ></path>
            </symbol>
            <symbol id="house-fill" viewBox="0 0 16 16">
                <path
                    d="M8.707 1.5a1 1 0 0 0-1.414 0L.646 8.146a.5.5 0 0 0 .708.708L8 2.207l6.646 6.647a.5.5 0 0 0 .708-.708L13 5.793V2.5a.5.5 0 0 0-.5-.5h-1a.5.5 0 0 0-.5.5v1.293L8.707 1.5Z"
                ></path>
                <path
                    d="m8 3.293 6 6V13.5a1.5 1.5 0 0 1-1.5 1.5h-9A1.5 1.5 0 0 1 2 13.5V9.293l6-6Z"
                ></path>
            </symbol>
            <symbol id="list" viewBox="0 0 16 16">
                <path
                    fill-rule="evenodd"
                    d="M2.5 12a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5zm0-4a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5zm0-4a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5z"
                ></path>
            </symbol>
            <symbol id="people" viewBox="0 0 16 16">
                <path
                    d="M15 14s1 0 1-1-1-4-5-4-5 3-5 4 1 1 1 1h8Zm-7.978-1A.261.261 0 0 1 7 12.996c.001-.264.167-1.03.76-1.72C8.312 10.629 9.282 10 11 10c1.717 0 2.687.63 3.24 1.276.593.69.758 1.457.76 1.72l-.008.002a.274.274 0 0 1-.014.002H7.022ZM11 7a2 2 0 1 0 0-4 2 2 0 0 0 0 4Zm3-2a3 3 0 1 1-6 0 3 3 0 0 1 6 0ZM6.936 9.28a5.88 5.88 0 0 0-1.23-.247A7.35 7.35 0 0 0 5 9c-4 0-5 3-5 4 0 .667.333 1 1 1h4.216A2.238 2.238 0 0 1 5 13c0-1.01.377-2.042 1.09-2.904.243-.294.526-.569.846-.816ZM4.92 10A5.493 5.493 0 0 0 4 13H1c0-.26.164-1.03.76-1.724.545-.636 1.492-1.256 3.16-1.275ZM1.5 5.5a3 3 0 1 1 6 0 3 3 0 0 1-6 0Zm3-2a2 2 0 1 0 0 4 2 2 0 0 0 0-4Z"
                ></path>
            </symbol>
            <symbol id="plus-circle" viewBox="0 0 16 16">
                <path
                    d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"
                ></path>
                <path
                    d="M8 4a.5.5 0 0 1 .5.5v3h3a.5.5 0 0 1 0 1h-3v3a.5.5 0 0 1-1 0v-3h-3a.5.5 0 0 1 0-1h3v-3A.5.5 0 0 1 8 4z"
                ></path>
            </symbol>
            <symbol id="puzzle" viewBox="0 0 16 16">
                <path
                    d="M3.112 3.645A1.5 1.5 0 0 1 4.605 2H7a.5.5 0 0 1 .5.5v.382c0 .696-.497 1.182-.872 1.469a.459.459 0 0 0-.115.118.113.113 0 0 0-.012.025L6.5 4.5v.003l.003.01c.004.01.014.028.036.053a.86.86 0 0 0 .27.194C7.09 4.9 7.51 5 8 5c.492 0 .912-.1 1.19-.24a.86.86 0 0 0 .271-.194.213.213 0 0 0 .039-.063v-.009a.112.112 0 0 0-.012-.025.459.459 0 0 0-.115-.118c-.375-.287-.872-.773-.872-1.469V2.5A.5.5 0 0 1 9 2h2.395a1.5 1.5 0 0 1 1.493 1.645L12.645 6.5h.237c.195 0 .42-.147.675-.48.21-.274.528-.52.943-.52.568 0 .947.447 1.154.862C15.877 6.807 16 7.387 16 8s-.123 1.193-.346 1.638c-.207.415-.586.862-1.154.862-.415 0-.733-.246-.943-.52-.255-.333-.48-.48-.675-.48h-.237l.243 2.855A1.5 1.5 0 0 1 11.395 14H9a.5.5 0 0 1-.5-.5v-.382c0-.696.497-1.182.872-1.469a.459.459 0 0 0 .115-.118.113.113 0 0 0 .012-.025L9.5 11.5v-.003a.214.214 0 0 0-.039-.064.859.859 0 0 0-.27-.193C8.91 11.1 8.49 11 8 11c-.491 0-.912.1-1.19.24a.859.859 0 0 0-.271.194.214.214 0 0 0-.039.063v.003l.001.006a.113.113 0 0 0 .012.025c.016.027.05.068.115.118.375.287.872.773.872 1.469v.382a.5.5 0 0 1-.5.5H4.605a1.5 1.5 0 0 1-1.493-1.645L3.356 9.5h-.238c-.195 0-.42.147-.675.48-.21.274-.528.52-.943.52-.568 0-.947-.447-1.154-.862C.123 9.193 0 8.613 0 8s.123-1.193.346-1.638C.553 5.947.932 5.5 1.5 5.5c.415 0 .733.246.943.52.255.333.48.48.675.48h.238l-.244-2.855zM4.605 3a.5.5 0 0 0-.498.55l.001.007.29 3.4A.5.5 0 0 1 3.9 7.5h-.782c-.696 0-1.182-.497-1.469-.872a.459.459 0 0 0-.118-.115.112.112 0 0 0-.025-.012L1.5 6.5h-.003a.213.213 0 0 0-.064.039.86.86 0 0 0-.193.27C1.1 7.09 1 7.51 1 8c0 .491.1.912.24 1.19.07.14.14.225.194.271a.213.213 0 0 0 .063.039H1.5l.006-.001a.112.112 0 0 0 .025-.012.459.459 0 0 0 .118-.115c.287-.375.773-.872 1.469-.872H3.9a.5.5 0 0 1 .498.542l-.29 3.408a.5.5 0 0 0 .497.55h1.878c-.048-.166-.195-.352-.463-.557-.274-.21-.52-.528-.52-.943 0-.568.447-.947.862-1.154C6.807 10.123 7.387 10 8 10s1.193.123 1.638.346c.415.207.862.586.862 1.154 0 .415-.246.733-.52.943-.268.205-.415.39-.463.557h1.878a.5.5 0 0 0 .498-.55l-.001-.007-.29-3.4A.5.5 0 0 1 12.1 8.5h.782c.696 0 1.182.497 1.469.872.05.065.091.099.118.115.013.008.021.01.025.012a.02.02 0 0 0 .006.001h.003a.214.214 0 0 0 .064-.039.86.86 0 0 0 .193-.27c.14-.28.24-.7.24-1.191 0-.492-.1-.912-.24-1.19a.86.86 0 0 0-.194-.271.215.215 0 0 0-.063-.039H14.5l-.006.001a.113.113 0 0 0-.025.012.459.459 0 0 0-.118.115c-.287.375-.773.872-1.469.872H12.1a.5.5 0 0 1-.498-.543l.29-3.407a.5.5 0 0 0-.497-.55H9.517c.048.166.195.352.463.557.274.21.52.528.52.943 0 .568-.447.947-.862 1.154C9.193 5.877 8.613 6 8 6s-1.193-.123-1.638-.346C5.947 5.447 5.5 5.068 5.5 4.5c0-.415.246-.733.52-.943.268-.205.415-.39.463-.557H4.605z"
                ></path>
            </symbol>
            <symbol id="search" viewBox="0 0 16 16">
                <path
                    d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001c.03.04.062.078.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1.007 1.007 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0z"
                ></path>
            </symbol>
        </svg>
        <header
            class="navbar sticky-top bg-dark flex-md-nowrap p-0 shadow"
            data-bs-theme="dark"
        >
            <a
                class="navbar-brand col-md-3 col-lg-2 me-0 px-3 fs-6 text-white"
                href="#"
                >Talaba Farm Monitoring</a
            >
            
            <ul class="navbar-nav flex-row d-md-none">
                <li class="nav-item text-nowrap">
                    <button
                        class="nav-link px-3 text-white"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#navbarSearch"
                        aria-controls="navbarSearch"
                        aria-expanded="false"
                        aria-label="Toggle search"
                    >
                        <svg class="bi" aria-hidden="true">
                            <use xlink:href="#search"></use>
                        </svg>
                    </button>
                </li>
                <li class="nav-item text-nowrap">
                    <button
                        class="nav-link px-3 text-white"
                        type="button"
                        data-bs-toggle="offcanvas"
                        data-bs-target="#sidebarMenu"
                        aria-controls="sidebarMenu"
                        aria-expanded="false"
                        aria-label="Toggle navigation"
                    >
                        <svg class="bi" aria-hidden="true">
                            <use xlink:href="#list"></use>
                        </svg>
                    </button>
                </li>
            </ul>
            <div id="navbarSearch" class="navbar-search w-100 collapse">
                <input
                    class="form-control w-100 rounded-0 border-0"
                    type="text"
                    placeholder="Search"
                    aria-label="Search"
                />
            </div>
            <div class="d-flex align-items-center">
                <a
                    href="#"
                    class="nav-link align-items-center"
                    data-bs-toggle="modal" 
                    data-bs-target="#notificationModal"
                    style="position: relative; margin-right: 1.5rem;"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" fill="currentColor" class="bi bi-bell" viewBox="0 0 15 15">
                        <path d="M8 16a2 2 0 0 0 2-2H6a2 2 0 0 0 2 2M8 1.918l-.797.161A4 4 0 0 0 4 6c0 .628-.134 2.197-.459 3.742-.16.767-.376 1.566-.663 2.258h10.244c-.287-.692-.502-1.49-.663-2.258C12.134 8.197 12 6.628 12 6a4 4 0 0 0-3.203-3.92zM14.22 12c.223.447.481.801.78 1H1c.299-.199.557-.553.78-1C2.68 10.2 3 6.88 3 6c0-2.42 1.72-4.44 4.005-4.901a1 1 0 1 1 1.99 0A5 5 0 0 1 13 6c0 .88.32 4.2 1.22 6"/>
                    </svg>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" 
                        id="notif-bell-badge" 
                        style="min-width: 18px; height: 18px; font-size: 11px;"
                    >
                        <?= $unread_notifications ?: '' ?>
                    </span>
                </a>
            </div>
        </header>
        <div class="container-fluid">
            <!-- for mobile layout -->
            <div class="row">
                <nav
                    class="sidebar border border-right col-md-3 col-lg-2 p-0 bg-body-tertiary"
                >
                    <div
                        class="offcanvas-md offcanvas-end bg-body-tertiary"
                        tabindex="-1"
                        id="sidebarMenu"
                        aria-labelledby="sidebarMenuLabel"
                    >
                        <div class="offcanvas-header">
                            <h5 class="offcanvas-title" id="sidebarMenuLabel">
                                Talaba Farm Monitoring
                            </h5>
                            <button
                                type="button"
                                class="btn-close"
                                data-bs-dismiss="offcanvas"
                                data-bs-target="#sidebarMenu"
                                aria-label="Close"
                            ></button>
                            
                        </div>
                        <div
                            class="offcanvas-body d-md-flex flex-column p-0 pt-lg-3 overflow-y-auto"
                        >
                            <ul class="nav flex-column">
                                <li class="nav-item">
                                    <a
                                        class="nav-link d-flex align-items-center gap-2 active"
                                        aria-current="page"
                                        href="#"
                                    >
                                        <svg class="bi" aria-hidden="true">
                                            <use xlink:href="#house-fill"></use>
                                        </svg>
                                        Dashboard
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link d-flex align-items-center gap-2 indent" href="#weather-updates">
                                        <!-- <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-bell" viewBox="0 0 16 16">
                                            <path d="M8 16a2 2 0 0 0 2-2H6a2 2 0 0 0 2 2M8 1.918l-.797.161A4 4 0 0 0 4 6c0 .628-.134 2.197-.459 3.742-.16.767-.376 1.566-.663 2.258h10.244c-.287-.692-.502-1.49-.663-2.258C12.134 8.197 12 6.628 12 6a4 4 0 0 0-3.203-3.92zM14.22 12c.223.447.481.801.78 1H1c.299-.199.557-.553.78-1C2.68 10.2 3 6.88 3 6c0-2.42 1.72-4.44 4.005-4.901a1 1 0 1 1 1.99 0A5 5 0 0 1 13 6c0 .88.32 4.2 1.22 6"/>
                                        </svg> -->
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-cloud-haze" viewBox="0 0 16 16">
                                            <path d="M4 11.5a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9a.5.5 0 0 1-.5-.5m-3 2a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9a.5.5 0 0 1-.5-.5m2 2a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9a.5.5 0 0 1-.5-.5M13.405 4.027a5.001 5.001 0 0 0-9.499-1.004A3.5 3.5 0 1 0 3.5 10H13a3 3 0 0 0 .405-5.973M8.5 1a4 4 0 0 1 3.976 3.555.5.5 0 0 0 .5.445H13a2 2 0 0 1 0 4H3.5a2.5 2.5 0 1 1 .605-4.926.5.5 0 0 0 .596-.329A4 4 0 0 1 8.5 1"/>
                                        </svg>
                                        Weather
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link d-flex align-items-center gap-2 indent" href="#report-chart">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-graph-up" viewBox="0 0 16 16">
                                            <path fill-rule="evenodd" d="M0 0h1v15h15v1H0zm14.817 3.113a.5.5 0 0 1 .07.704l-4.5 5.5a.5.5 0 0 1-.74.037L7.06 6.767l-3.656 5.027a.5.5 0 0 1-.808-.588l4-5.5a.5.5 0 0 1 .758-.06l2.609 2.61 4.15-5.073a.5.5 0 0 1 .704-.07"/>
                                        </svg>
                                        Charts
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link d-flex align-items-center gap-2 indent" href="#calendar">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-calendar-week" viewBox="0 0 16 16">
                                            <path d="M11 6.5a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-1a.5.5 0 0 1-.5-.5zm-3 0a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-1a.5.5 0 0 1-.5-.5zm-5 3a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-1a.5.5 0 0 1-.5-.5zm3 0a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-1a.5.5 0 0 1-.5-.5z"/>
                                            <path d="M3.5 0a.5.5 0 0 1 .5.5V1h8V.5a.5.5 0 0 1 1 0V1h1a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V3a2 2 0 0 1 2-2h1V.5a.5.5 0 0 1 .5-.5M1 4v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V4z"/>
                                        </svg>
                                        Calendar
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link d-flex align-items-center gap-2 indent" href="#farms">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-layers-half" viewBox="0 0 16 16">
                                            <path d="M8.235 1.559a.5.5 0 0 0-.47 0l-7.5 4a.5.5 0 0 0 0 .882L3.188 8 .264 9.559a.5.5 0 0 0 0 .882l7.5 4a.5.5 0 0 0 .47 0l7.5-4a.5.5 0 0 0 0-.882L12.813 8l2.922-1.559a.5.5 0 0 0 0-.882zM8 9.433 1.562 6 8 2.567 14.438 6z"/>
                                        </svg>
                                        Farm
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a
                                        class="nav-link d-flex align-items-center gap-2 indent"
                                        data-bs-toggle="modal" 
                                        data-bs-target="#notificationModal"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" fill="currentColor" class="bi bi-bell" viewBox="0 0 15 15">
                                            <path d="M8 16a2 2 0 0 0 2-2H6a2 2 0 0 0 2 2M8 1.918l-.797.161A4 4 0 0 0 4 6c0 .628-.134 2.197-.459 3.742-.16.767-.376 1.566-.663 2.258h10.244c-.287-.692-.502-1.49-.663-2.258C12.134 8.197 12 6.628 12 6a4 4 0 0 0-3.203-3.92zM14.22 12c.223.447.481.801.78 1H1c.299-.199.557-.553.78-1C2.68 10.2 3 6.88 3 6c0-2.42 1.72-4.44 4.005-4.901a1 1 0 1 1 1.99 0A5 5 0 0 1 13 6c0 .88.32 4.2 1.22 6"/>
                                        </svg>
                                        Notification
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a
                                        class="nav-link d-flex align-items-center gap-2 indent"
                                        data-bs-toggle="modal" 
                                        data-bs-target="#reportModal"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-envelope" viewBox="0 0 16 16">
                                            <path d="M0 4a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2zm2-1a1 1 0 0 0-1 1v.217l7 4.2 7-4.2V4a1 1 0 0 0-1-1zm13 2.383-4.708 2.825L15 11.105zm-.034 6.876-5.64-3.471L8 9.583l-1.326-.795-5.64 3.47A1 1 0 0 0 2 13h12a1 1 0 0 0 .966-.741M1 11.105l4.708-2.897L1 5.383z"/>
                                        </svg>
                                        Make a Report
                                    </a>
                                </li>
                            </ul>

                            <hr class="my-3" />
                            <ul class="nav flex-column mb-auto">
                                <li class="nav-item">
                                    <!-- <a class="nav-link d-flex align-items-center gap-2" href="#">
                                        <svg class="bi" aria-hidden="true">
                                            <use xlink:href="#gear-wide-connected"></use>
                                        </svg>
                                        Settings
                                    </a> -->
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link d-flex align-items-center gap-2" href="#"
                                       data-bs-toggle="modal" data-bs-target="#editProfileModal">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-person-circle" viewBox="0 0 16 16">
                                            <path d="M11 6a3 3 0 1 1-6 0 3 3 0 0 1 6 0"/>
                                            <path fill-rule="evenodd" d="M0 8a8 8 0 1 1 16 0A8 8 0 0 1 0 8m8-7a7 7 0 0 0-5.468 11.37C3.242 11.226 4.805 10 8 10s4.757 1.225 5.468 2.37A7 7 0 0 0 8 1"/>
                                        </svg>
                                        My Profile
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link d-flex align-items-center gap-2" href="../sign/sign-out.php">
                                        <svg class="bi" aria-hidden="true">
                                            <use xlink:href="#door-closed"></use>
                                        </svg>
                                        Sign out
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </div>
                </nav>

                <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                    <section>
                        <div class="container text-center" style="margin-bottom: 30px;" id="weather-updates">
                            <!-- <div class="row top-collum">
                                <div class="col">
                                    weather
                                </div>
                                <div class="col">
                                    tide type
                                </div>
                                <div class="col">
                                    water tenpreture
                                </div>
                                <div class="col">
                                    note or remainder
                                </div>
                            </div> -->
                            <div class="card" style="border:none; border-radius:16px; overflow:hidden; box-shadow:0 4px 20px rgba(0,180,220,0.15);">
                                <div class="card-header text-center fw-bold" style="background: linear-gradient(135deg, #00b4d8, #0096c7); color:white; border:none;">
                                        🌤️ Weather Update
                                </div>
                                <div class="card-body d-flex gap-3 p-3">

                                    <div class="flex-fill text-center d-flex flex-column align-items-center justify-content-center gap-2 rounded-3 p-3"
                                            style="background:#f0fbff; border:1.5px solid #b2e9f7;"
                                    >
                                        <small class="text-uppercase fw-bold" style="letter-spacing:1px; color:#0096c7">🌊 Tide Type</small>
                                        <span class="fw-semibold" id="weather-tide-type" style="color:#023e8a">—</span>
                                    </div>

                                    <div class="flex-fill text-center d-flex flex-column align-items-center justify-content-center gap-2 rounded-3 p-3"
                                            style="background:#f0fbff; border:1.5px solid #b2e9f7;"
                                    >
                                        <small class="text-uppercase fw-bold" style="letter-spacing:1px; color:#0096c7">💨 Wind Speed</small>
                                        <span class="fw-semibold" id="weather-wind-speed" style="color:#023e8a">—</span>
                                    </div>

                                    <div class="flex-fill text-center d-flex flex-column align-items-center justify-content-center gap-2 rounded-3 p-3"
                                            style="background:#f0fbff; border:1.5px solid #b2e9f7;"
                                    >
                                        <small class="text-uppercase fw-bold" style="letter-spacing:1px; color:#0096c7">Natural Temperature</small>
                                        <span class="fw-semibold" id="weather-water-temp" style="color:#023e8a">—</span>
                                    </div>

                                </div>
                            </div>
                        </div>
                        <div class="container text-center">
                            <!-- chart -->
                            <div id="report-chart" class="card mb-4 shadow-sm" style="border:none; border-radius:16px; overflow:hidden;">
                                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2"
                                     style="background: linear-gradient(135deg, #198754, #0d6efd); color:white; border:none;">
                                    <span class="fw-bold">
                                        🦪 Harvest History
                                    </span>
                                    <div class="d-flex gap-2 align-items-center flex-wrap">
                                        <!-- Group-by toggle -->
                                        <div class="btn-group btn-group-sm" role="group" id="chartGroupBy">
                                            <button type="button" class="btn btn-light active" data-group="month">Monthly</button>
                                            <button type="button" class="btn btn-light" data-group="quarter">Quarterly</button>
                                            <button type="button" class="btn btn-light" data-group="year">Yearly</button>
                                        </div>
                                        <!-- Chart-type toggle -->
                                        <div class="btn-group btn-group-sm" role="group" id="chartType">
                                            <button type="button" class="btn btn-light active" data-type="line">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="currentColor" class="bi bi-graph-up" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M0 0h1v15h15v1H0zm14.817 3.113a.5.5 0 0 1 .07.704l-4.5 5.5a.5.5 0 0 1-.74.037L7.06 6.767l-3.656 5.027a.5.5 0 0 1-.808-.588l4-5.5a.5.5 0 0 1 .758-.06l2.609 2.61 4.15-5.073a.5.5 0 0 1 .704-.07Z"/></svg>
                                                Line
                                            </button>
                                            <button type="button" class="btn btn-light" data-type="bar">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="currentColor" class="bi bi-bar-chart-fill" viewBox="0 0 16 16"><path d="M1 11a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1zm5-4a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v7a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1zm5-5a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v12a1 1 0 0 0-1 1h-2a1 1 0 0 1-1-1z"/></svg>
                                                Bar
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-body p-3">
                                    <div class="chart">
                                        <canvas id="performanceChart"></canvas>
                                    </div>
                                    <div id="harvestChartEmpty" class="text-center text-muted py-5 d-none">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="#adb5bd" class="bi bi-basket mb-3 d-block mx-auto" viewBox="0 0 16 16">
                                            <path d="M5.757 1.071a.5.5 0 0 1 .172.686L3.383 6h9.234L10.07 1.757a.5.5 0 1 1 .858-.514L13.783 6H15a1 1 0 0 1 1 1v1a1 1 0 0 1-1 1v4.5a2.5 2.5 0 0 1-2.5 2.5h-9A2.5 2.5 0 0 1 1 13.5V9a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h1.217L5.07 1.243a.5.5 0 0 1 .686-.172zM2 9v4.5A1.5 1.5 0 0 0 3.5 15h9a1.5 1.5 0 0 0 1.5-1.5V9H2zm13-2H1v1h14V7zm-6 5a.5.5 0 0 1-.5.5h-1a.5.5 0 0 1 0-1h1a.5.5 0 0 1 .5.5z"/>
                                        </svg>
                                        <p class="mb-0">No harvest records yet.<br><small>Record your first harvest using the <strong>Harvest</strong> button on a farm card.</small></p>
                                    </div>
                                </div>
                            </div>

                            <!-- Previous Harvest History -->
                            <div class="card mb-4 shadow-sm" style="border:none; border-radius:16px; overflow:hidden;">
                                <div class="card-header d-flex align-items-center justify-content-between" style="background: linear-gradient(135deg, #6f42c1, #20c997); color:white; border:none;">
                                    <span class="fw-bold">📜 Previous Harvest History</span>
                                    <span class="badge bg-white text-dark" style="font-size:.78rem; font-weight:700;">
                                        <?= count($harvest) ?> record<?= count($harvest) !== 1 ? 's' : '' ?>
                                    </span>
                                </div>
                                <div class="card-body p-3">
                                    <?php if (empty($harvest)): ?>
                                    <div class="text-center text-muted py-4">
                                        <i class="bi bi-clock-history fs-1 d-block mb-2 opacity-25"></i>
                                        No harvest records yet.
                                        <div class="small text-muted">Your completed harvests will appear here.</div>
                                    </div>
                                    <?php else: ?>
                                    <?php
                                        $recentHarvests = $harvest;
                                        usort($recentHarvests, fn($a, $b) => strcmp($b['harvestDate'], $a['harvestDate']));
                                    ?>
                                    <div class="table-responsive" style="max-height:320px; overflow-y:auto;">
                                        <table class="table table-sm mb-0 align-middle">
                                            <thead>
                                                <tr>
                                                    <th class="text-muted" style="width:50px;">#</th>
                                                    <th>Farm</th>
                                                    <th>Date</th>
                                                    <th class="text-end">Quantity (kg)</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ($recentHarvests as $index => $hr): ?>
                                            <?php
                                                $farmLabel = 'Farm #' . $hr['farm_ID'];
                                                $farmDeleted = false;
                                                foreach ($allFarms as $f) {
                                                    if ((int)$f['farm_ID'] === (int)$hr['farm_ID']) {
                                                        $farmLabel = $f['farmName_number'];
                                                        $farmDeleted = !empty($f['deleted_at']);
                                                        break;
                                                    }
                                                }
                                            ?>
                                                <tr>
                                                    <td class="text-muted"><?= $index + 1 ?></td>
                                                    <td>
                                                        <?= htmlspecialchars($farmLabel) ?>
                                                        <?php if ($farmDeleted): ?>
                                                            <span class="badge bg-danger text-white ms-2" style="font-size:.68rem;">Deleted</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?= htmlspecialchars($hr['harvestDate'] ?? '—') ?></td>
                                                    <td class="text-end">
                                                        <span class="badge rounded-pill" style="background:linear-gradient(90deg,#20c997,#0dcaf0); color:#fff;">
                                                            <?= number_format((float)$hr['quantity'], 2) ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- CALENDAR -->
                            <div id="calendar">
                            <div class="calendar-row">

                                <div class="cal-wrapper">
                                    <div class="cal">

                                        <!-- Month & Year + Navigation -->
                                        <div class="cal-month">
                                            <button class="btn cal-btn" type="button" aria-label="previous month" id="prevMonth">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-arrow-left-square" viewBox="0 0 16 16">
                                                    <path fill-rule="evenodd" d="M15 2a1 1 0 0 0-1-1H2a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1zM0 2a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2zm11.5 5.5a.5.5 0 0 1 0 1H5.707l2.147 2.146a.5.5 0 0 1-.708.708l-3-3a.5.5 0 0 1 0-.708l3-3a.5.5 0 1 1 .708.708L5.707 7.5z"/>
                                                </svg>
                                            </button>

                                            <div class="cal-month-name fw-bold" id="monthYear">March 2025</div>

                                            <button class="btn cal-btn" type="button" aria-label="next month" id="nextMonth">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-arrow-right-square" viewBox="0 0 16 16">
                                                    <path fill-rule="evenodd" d="M15 2a1 1 0 0 0-1-1H2a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1zM0 2a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2zm4.5 5.5a.5.5 0 0 0 0 1h5.793l-2.147 2.146a.5.5 0 0 0 .708.708l3-3a.5.5 0 0 0 0-.708l-3-3a.5.5 0 1 0-.708.708L10.293 7.5z"/>
                                                </svg>
                                            </button>
                                        </div>

                                        <!-- Weekday headers -->
                                        <div class="cal-weekdays text-body-secondary">
                                            <div class="cal-weekday">Sun</div>
                                            <div class="cal-weekday">Mon</div>
                                            <div class="cal-weekday">Tue</div>
                                            <div class="cal-weekday">Wed</div>
                                            <div class="cal-weekday">Thu</div>
                                            <div class="cal-weekday">Fri</div>
                                            <div class="cal-weekday">Sat</div>
                                        </div>

                                        <!-- Days grid (will be filled by JavaScript) -->
                                        <div class="cal-days" id="calendarDays">
                                            <!-- 
                                                Days will be dynamically inserted here as <button class="btn cal-btn" type="button">1</button>
                                                Some may have .disabled, .today, .selected, .has-event etc. classes
                                            -->
                                        </div>

                                    </div> <!-- .cal -->
                                </div> <!-- .cal-wrapper -->

                                <!-- ------------------------------------------------------------------
                                 make this part scrollable like the nitification modal
                                ------------------------------------------------------------------ 
                                -->
                                <!-- REMINDERS / UPCOMING EVENTS -->
                                <div class="reminders-panel">
                                    <div class="card">
                                        <div class="card-header">
                                            FARM REMINDERS
                                        </div>
                                        <div class="card-body" style="height: 300px; overflow-y: auto;">
                                            <ul class="list-group list-group-flush" id="remindersList">

                                                <!-- Example static items – can be replaced / added dynamically -->
                                                <!-- <li class="list-group-item d-flex justify-content-between align-items-start">
                                                    <div class="ms-2 me-auto">
                                                        <div class="fw-bold">Harvest Schedule #1 Farm 1</div>
                                                        Mar 15, 2025
                                                    </div>
                                                    <span class="badge bg-warning rounded-pill">Soon</span>
                                                </li>

                                                <li class="list-group-item d-flex justify-content-between align-items-start">
                                                    <div class="ms-2 me-auto">
                                                        <div class="fw-bold">Water Quality Inspection</div>
                                                        Mar 18, 2025
                                                    </div>
                                                </li>

                                                <li class="list-group-item d-flex justify-content-between align-items-start">
                                                    <div class="ms-2 me-auto">
                                                        <div class="fw-bold">Seeding #1 Farm 2</div>
                                                        Mar 22, 2025
                                                    </div>
                                                </li>

                                                <li class="list-group-item d-flex justify-content-between align-items-start">
                                                    <div class="ms-2 me-auto">
                                                        <div class="fw-bold">Harvest Schedule #1 Farm 2</div>
                                                        Apr 10, 2025
                                                    </div>
                                                <li class="list-group-item d-flex justify-content-between align-items-start">
                                                    <div class="ms-2 me-auto">
                                                        <div class="fw-bold">Harvest Schedule #1 Farm 1</div>
                                                        Mar 15, 2025
                                                    </div>
                                                    <span class="badge bg-warning rounded-pill">Soon</span>
                                                </li>

                                                <li class="list-group-item d-flex justify-content-between align-items-start">
                                                    <div class="ms-2 me-auto">
                                                        <div class="fw-bold">Water Quality Inspection</div>
                                                        Mar 18, 2025
                                                    </div>
                                                </li>

                                                <li class="list-group-item d-flex justify-content-between align-items-start">
                                                    <div class="ms-2 me-auto">
                                                        <div class="fw-bold">Seeding #1 Farm 2</div>
                                                        Mar 22, 2025
                                                    </div>
                                                </li>

                                                <li class="list-group-item d-flex justify-content-between align-items-start">
                                                    <div class="ms-2 me-auto">
                                                        <div class="fw-bold">Harvest Schedule #1 Farm 2</div>
                                                        Apr 10, 2025
                                                    </div>
                                                <li class="list-group-item d-flex justify-content-between align-items-start">
                                                    <div class="ms-2 me-auto">
                                                        <div class="fw-bold">Harvest Schedule #1 Farm 1</div>
                                                        Mar 15, 2025
                                                    </div>
                                                    <span class="badge bg-warning rounded-pill">Soon</span>
                                                </li>

                                                <li class="list-group-item d-flex justify-content-between align-items-start">
                                                    <div class="ms-2 me-auto">
                                                        <div class="fw-bold">Water Quality Inspection</div>
                                                        Mar 18, 2025
                                                    </div>
                                                </li>

                                                <li class="list-group-item d-flex justify-content-between align-items-start">
                                                    <div class="ms-2 me-auto">
                                                        <div class="fw-bold">Seeding #1 Farm 2</div>
                                                        Mar 22, 2025
                                                    </div>
                                                </li>

                                                <li class="list-group-item d-flex justify-content-between align-items-start">
                                                    <div class="ms-2 me-auto">
                                                        <div class="fw-bold">Harvest Schedule #1 Farm 2</div>
                                                        Apr 10, 2025
                                                    </div>
                                                </li> -->

                                                <!-- more items can be appended here via JS -->
                                            </ul>
                                        </div>
                                    </div>
                                </div>

                            </div> <!-- .calendar-row -->
                        </div>
                        </div><!-- /#calendar -->
                    </section>

                    
                    <section class="">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h2 class="mb-0">Oyster Farms</h2>
                            <button type="button" class="btn btn-outline-dark" data-bs-toggle="modal" data-bs-target="#staticBackdrop">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-box-arrow-in-right" viewBox="0 0 16 16">
                                    <path fill-rule="evenodd" d="M6 3.5a.5.5 0 0 1 .5-.5h8a.5.5 0 0 1 .5.5v9a.5.5 0 0 1-.5.5h-8a.5.5 0 0 1-.5-.5v-2a.5.5 0 0 0-1 0v2A1.5 1.5 0 0 0 6.5 14h8a1.5 1.5 0 0 0 1.5-1.5v-9A1.5 1.5 0 0 0 14.5 2h-8A1.5 1.5 0 0 0 5 3.5v2a.5.5 0 0 0 1 0z"/>
                                    <path fill-rule="evenodd" d="M11.854 8.354a.5.5 0 0 0 0-.708l-3-3a.5.5 0 1 0-.708.708L10.293 7.5H1.5a.5.5 0 0 0 0 1h8.793l-2.147 2.146a.5.5 0 0 0 .708.708z"/>
                                </svg>
                                    <strong>
                                        &nbsp <!-- white spoace -->
                                        Add New Farm
                                    </strong>
                            </button>
                        </div>

                        <div class="container text-center" id="farms">
                            <!-- <div class="row farm-card">
                                <div class="col">farm 1</div>
                                <div class="col">farm 2</div>
                            </div> -->

                            <div class="row g-3 mb-3">
                                <?php foreach ($farms as $farm): ?>
                                <div class="col-12 col-md-6">
                                    <div class="card h-100 shadow-sm farm-card-item"
                                        data-farm-id="<?= $farm['farm_ID'] ?>"
                                        data-lat="<?= htmlspecialchars($farm['latitude'] ?? '') ?>"
                                        data-lng="<?= htmlspecialchars($farm['longitude'] ?? '') ?>"
                                        data-breed-method="<?= htmlspecialchars($farm['breedMethod'] ?? '') ?>"
                                        data-seeding-date="<?= htmlspecialchars($farm['seedingDate'] ?? '') ?>"
                                    >
                                        <!-- Card header: farm name + action buttons -->
                                        <div class="card-header d-flex justify-content-between align-items-center">
                                            <span class="fw-bold fs-5"><?= htmlspecialchars($farm['farmName_number']) ?></span>
                                            <div class="d-flex gap-2">
                                                <?php if (!empty($farm['isHarvested'])): ?>
                                                <!-- Farm is harvested → show Re-seed button -->
                                                <button type="button" class="btn btn-info btn-sm text-white"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#reseedFarm-modal-<?= $farm['farm_ID'] ?>">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-seed me-1" viewBox="0 0 16 16">
                                                        <path d="M8 3C4.686 3 2 5.686 2 9s2.686 6 6 6 6-2.686 6-6-2.686-6-6-6zm0 10a4 4 0 1 1 0-8 4 4 0 0 1 0 8z"/>
                                                        <path d="M9.5 7.5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0z"/>
                                                    </svg>
                                                    Re-seed
                                                </button>
                                                <?php else: ?>
                                                <!-- Farm is active → show Harvest button -->
                                                <button type="button" class="btn btn-success btn-sm"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#harvestFarm-modal-<?= $farm['farm_ID'] ?>">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-basket me-1" viewBox="0 0 16 16">
                                                        <path d="M5.757 1.071a.5.5 0 0 1 .172.686L3.383 6h9.234L10.07 1.757a.5.5 0 1 1 .858-.514L13.783 6H15a1 1 0 0 1 1 1v1a1 1 0 0 1-1 1v4.5a2.5 2.5 0 0 1-2.5 2.5h-9A2.5 2.5 0 0 1 1 13.5V9a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h1.217L5.07 1.243a.5.5 0 0 1 .686-.172zM2 9v4.5A1.5 1.5 0 0 0 3.5 15h9a1.5 1.5 0 0 0 1.5-1.5V9H2zm13-2H1v1h14V7zm-6 5a.5.5 0 0 1-.5.5h-1a.5.5 0 0 1 0-1h1a.5.5 0 0 1 .5.5z"/>
                                                    </svg>
                                                    Harvest
                                                </button>
                                                <?php endif; ?>
                                                <button type="button" class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#editFarm-modal-<?= $farm['farm_ID'] ?>">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-pencil me-1" viewBox="0 0 16 16">
                                                        <path d="M12.146.146a.5.5 0 0 1 .708 0l3 3a.5.5 0 0 1 0 .708l-10 10a.5.5 0 0 1-.168.11l-5 2a.5.5 0 0 1-.65-.65l2-5a.5.5 0 0 1 .11-.168l10-10zM11.207 2.5 13.5 4.793 14.793 3.5 12.5 1.207 11.207 2.5zm1.586 3L10.5 3.207 4 9.707V10h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.293l6.5-6.5zm-9.761 5.175-.106.106-1.528 3.821 3.821-1.528.106-.106A.5.5 0 0 1 5 12.5V12h-.5a.5.5 0 0 1-.5-.5V11h-.5a.5.5 0 0 1-.468-.325z"/>
                                                    </svg>
                                                    Edit
                                                </button>
                                                <button type="button" class="btn btn-danger btn-sm"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#deleteFarm-modal-<?= $farm['farm_ID'] ?>">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-trash me-1" viewBox="0 0 16 16">
                                                        <path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0V6z"/>
                                                        <path fill-rule="evenodd" d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1v1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4H4.118zM2.5 3V2h11v1h-11z"/>
                                                    </svg>
                                                    Delete
                                                </button>
                                            </div>
                                        </div>
                                        <!-- Card body: farm image or harvested state -->
                                        <div class="card-body d-flex align-items-center justify-content-center p-2">
                                            <?php if (!empty($farm['isHarvested'])): ?>
                                                <!-- Harvested: show empty farm state -->
                                                <div class="d-flex flex-column align-items-center justify-content-center text-muted" style="height: 220px; width: 100%; background: #f5f0e8; border-radius: 0.5rem; border: 2px dashed #c8a96e;">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="56" height="56" fill="#c8a96e" class="bi bi-basket-fill mb-2" viewBox="0 0 16 16">
                                                        <path d="M5.071 1.243a.5.5 0 0 1 .858.514L3.383 6h9.234L10.07 1.757a.5.5 0 1 1 .858-.514L13.783 6H15a1 1 0 0 1 1 1v1a1 1 0 0 1-1 1v4.5a2.5 2.5 0 0 1-2.5 2.5h-9A2.5 2.5 0 0 1 1 13.5V9a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h1.217z"/>
                                                    </svg>
                                                    <span class="fw-semibold" style="color:#c8a96e; font-size:.9rem;">Farm Harvested</span>
                                                    <small class="text-muted mt-1">Ready for re-seeding</small>
                                                </div>
                                            <?php elseif (!empty($farm['imagePath'])): ?>
                                                <img
                                                    src="../upload-image/<?= htmlspecialchars($farm['imagePath']) ?>"
                                                    alt="<?= htmlspecialchars($farm['farmName_number']) ?>"
                                                    class="img-fluid rounded"
                                                    style="max-height: 220px; object-fit: cover; width: 100%;"
                                                >
                                            <?php else: ?>
                                                <div class="d-flex flex-column align-items-center justify-content-center text-muted" style="height: 220px; width: 100%; background: #f0f4f8; border-radius: 0.5rem;">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" fill="currentColor" class="bi bi-image mb-2 opacity-50" viewBox="0 0 16 16">
                                                        <path d="M6.002 5.5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0"/>
                                                        <path d="M2.002 1a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V3a2 2 0 0 0-2-2zm12 1a1 1 0 0 1 1 1v6.5l-3.777-1.947a.5.5 0 0 0-.577.093l-3.71 3.71-2.66-1.772a.5.5 0 0 0-.63.062L1.002 12V3a1 1 0 0 1 1-1z"/>
                                                    </svg>
                                                    <small>No image uploaded</small>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <!-- Tide Type & Water Temperature -->
                                        <div class="d-flex border-top">
                                            <div class="flex-fill text-center py-2 px-3" style="border-right: 1px solid #dee2e6;">
                                                <small class="d-block text-uppercase fw-bold" style="font-size:.65rem; letter-spacing:.6px; color:#0096c7;">
                                                    🌊 Tide Type
                                                </small>
                                                <span class="fw-semibold small farm-tide-type" style="color:#023e8a;">—</span>
                                            </div>
                                            <div class="flex-fill text-center py-2 px-3">
                                                <small class="d-block text-uppercase fw-bold" style="font-size:.65rem; letter-spacing:.6px; color:#e85d04;">
                                                    🌡️ Water Temperature
                                                </small>
                                                <span class="fw-semibold small farm-water-temp" style="color:#023e8a;">—</span>
                                            </div>
                                        </div>
                                        <!-- Card footer: farm details -->
                                        <div class="card-footer text-muted small text-start">
                                            <span class="me-3">
                                                <i class="bi bi-geo-alt-fill"></i> <?= htmlspecialchars($farm['location'] ?? '—') ?>
                                            </span>
                                            <span>
                                                <i class="bi bi-rulers"></i> <?= htmlspecialchars($farm['surfaceArea'] ?? '—') ?> m²
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <!-- Delete Confirmation Modal for farm <?= $farm['farm_ID'] ?> -->
                                <div class="modal fade" id="deleteFarm-modal-<?= $farm['farm_ID'] ?>" tabindex="-1" aria-labelledby="deleteFarmLabel-<?= $farm['farm_ID'] ?>" aria-hidden="true">
                                    <div class="modal-dialog modal-dialog-centered">
                                        <div class="modal-content">
                                            <div class="modal-header bg-danger text-white">
                                                <h2 class="modal-title fs-5" id="deleteFarmLabel-<?= $farm['farm_ID'] ?>">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" class="bi bi-exclamation-triangle-fill me-2" viewBox="0 0 16 16">
                                                        <path d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5m.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2"/>
                                                    </svg>
                                                    Delete Farm
                                                </h2>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <div class="modal-body">
                                                <p>Are you sure you want to delete <strong><?= htmlspecialchars($farm['farmName_number']) ?></strong>?</p>
                                                <p class="text-muted small mb-0">This action cannot be undone. All data associated with this farm will be permanently removed.</p>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                                    Cancel
                                                </button>
                                                <form method="post" action="farm_dashboard_rtl.php" class="d-inline">
                                                    <input type="hidden" name="farmID" value="<?= $farm['farm_ID'] ?>">
                                                    <button type="submit" name="deleteFarm" class="btn btn-danger">
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-trash me-1" viewBox="0 0 16 16">
                                                            <path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0V6z"/>
                                                            <path fill-rule="evenodd" d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1v1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4H4.118zM2.5 3V2h11v1h-11z"/>
                                                        </svg>
                                                        Yes, Delete
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- ================================================
                                     HARVEST MODAL for farm <?= $farm['farm_ID'] ?>
                                     ================================================ -->
                                <div class="modal fade" id="harvestFarm-modal-<?= $farm['farm_ID'] ?>" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-hidden="true">
                                    <div class="modal-dialog modal-dialog-centered">
                                        <div class="modal-content">
                                            <div class="modal-header bg-success text-white">
                                                <h2 class="modal-title fs-5">
                                                    🪣 Harvest — <?= htmlspecialchars($farm['farmName_number']) ?>
                                                </h2>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <?php
                                                $hw_windows = [
                                                    'Triploids'                => ['min'=>6,  'max'=>9],
                                                    'Tetraploids (4n)'         => ['min'=>7,  'max'=>10],
                                                    'cross'                    => ['min'=>7,  'max'=>11],
                                                    'line'                     => ['min'=>8,  'max'=>12],
                                                    'family'                   => ['min'=>9,  'max'=>13],
                                                    'Fertilization'            => ['min'=>8,  'max'=>12],
                                                    'Temperature Manipulation' => ['min'=>7,  'max'=>11],
                                                    'Environmental Cues'       => ['min'=>9,  'max'=>14],
                                                    'Stake Method'             => ['min'=>8,  'max'=>12],
                                                    'Cultch / Substrate'       => ['min'=>8,  'max'=>13],
                                                    'Seed Collection'          => ['min'=>10, 'max'=>16],
                                                ];
                                                $hw_method       = $farm['breedMethod'] ?? '';
                                                $hw_seeding      = $farm['seedingDate'] ?? '';
                                                $hw_on_schedule  = false;
                                                $hw_earliest_str = '';
                                                $hw_latest_str   = '';
                                                if ($hw_method && $hw_seeding && isset($hw_windows[$hw_method])) {
                                                    $s   = new DateTime($hw_seeding);
                                                    $e   = clone $s; $e->modify('+' . $hw_windows[$hw_method]['min'] . ' months');
                                                    $l   = clone $s; $l->modify('+' . $hw_windows[$hw_method]['max'] . ' months');
                                                    $now = new DateTime();
                                                    $hw_on_schedule  = ($now >= $e && $now <= $l);
                                                    $hw_earliest_str = $e->format('M j, Y');
                                                    $hw_latest_str   = $l->format('M j, Y');
                                                }
                                            ?>
                                            <div class="modal-body">

                                                <?php if ($hw_earliest_str): ?>
                                                <!-- Schedule status banner -->
                                                <div class="alert <?= $hw_on_schedule ? 'alert-success' : 'alert-warning' ?> py-2 mb-3">
                                                    <div class="fw-semibold mb-1">
                                                        <?= $hw_on_schedule ? '✅ Within harvest window' : '⚠️ Outside harvest window' ?>
                                                    </div>
                                                    <small>
                                                        Scheduled window for <strong><?= htmlspecialchars($hw_method) ?></strong>:<br>
                                                        <strong><?= $hw_earliest_str ?></strong> — <strong><?= $hw_latest_str ?></strong>
                                                    </small>
                                                </div>
                                                <?php endif; ?>

                                                <?php if ($hw_earliest_str && !$hw_on_schedule): ?>
                                                <!-- Off-schedule confirmation checkbox -->
                                                <div class="border border-warning rounded p-3 mb-3 bg-warning bg-opacity-10">
                                                    <p class="mb-1 small text-danger fw-semibold">
                                                        ⚠️ You are harvesting outside the recommended schedule.
                                                    </p>
                                                    <p class="mb-2 small text-muted">
                                                        Harvesting too early or too late may affect oyster quality and yield.
                                                        The optimal window is <strong><?= $hw_earliest_str ?></strong> to <strong><?= $hw_latest_str ?></strong>.
                                                    </p>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox"
                                                            id="offScheduleConfirm-<?= $farm['farm_ID'] ?>"
                                                            onchange="document.getElementById('harvestSubmitBtn-<?= $farm['farm_ID'] ?>').disabled = !this.checked">
                                                        <label class="form-check-label small fw-semibold text-danger"
                                                            for="offScheduleConfirm-<?= $farm['farm_ID'] ?>">
                                                            I understand and still want to harvest outside the recommended schedule.
                                                        </label>
                                                    </div>
                                                </div>
                                                <?php endif; ?>

                                                <!-- Harvest form -->
                                                <form method="post" action="farm_dashboard_rtl.php">
                                                    <input type="hidden" name="farmID" value="<?= $farm['farm_ID'] ?>">

                                                    <div class="form-floating mb-3">
                                                        <input type="date" name="harvestDate" class="form-control"
                                                            id="harvestDate-<?= $farm['farm_ID'] ?>"
                                                            value="<?= date('Y-m-d') ?>"
                                                            max="<?= date('Y-m-d') ?>"
                                                            required>
                                                        <label for="harvestDate-<?= $farm['farm_ID'] ?>">Harvest Date</label>
                                                    </div>

                                                    <div class="form-floating mb-1">
                                                        <input type="number" step="0.01" min="0.01" name="quantity"
                                                            class="form-control"
                                                            id="harvestQty-<?= $farm['farm_ID'] ?>"
                                                            placeholder="Quantity (kg)"
                                                            required>
                                                        <label for="harvestQty-<?= $farm['farm_ID'] ?>">Quantity Harvested (kg)</label>
                                                    </div>

                                                    <?php if (!empty($farm['surfaceArea'])): ?>
                                                    <small class="text-muted d-block mb-3">
                                                        📐 Farm surface area: <strong><?= htmlspecialchars($farm['surfaceArea']) ?> m²</strong>
                                                        — enter the total kg harvested from this entire area.
                                                    </small>
                                                    <?php endif; ?>

                                                    <div class="d-flex justify-content-end gap-2 mt-3">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" name="harvestFarm"
                                                            id="harvestSubmitBtn-<?= $farm['farm_ID'] ?>"
                                                            class="btn btn-success"
                                                            <?= ($hw_earliest_str && !$hw_on_schedule) ? 'disabled' : '' ?>>
                                                            🪣 Confirm Harvest
                                                        </button>
                                                    </div>
                                                </form>

                                            </div><!-- /modal-body -->
                                        </div>
                                    </div>
                                </div><!-- /harvestFarm-modal -->


                                <!-- ================================================
                                     RE-SEED MODAL for farm <?= $farm['farm_ID'] ?>
                                     ================================================ -->
                                <div class="modal fade" id="reseedFarm-modal-<?= $farm['farm_ID'] ?>" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-hidden="true">
                                    <div class="modal-dialog modal-dialog-centered">
                                        <div class="modal-content">
                                            <div class="modal-header bg-info text-white">
                                                <h2 class="modal-title fs-5">
                                                    🌱 Re-seed — <?= htmlspecialchars($farm['farmName_number']) ?>
                                                </h2>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <form method="post" action="farm_dashboard_rtl.php" class="modal-body">
                                                <input type="hidden" name="farmID" value="<?= $farm['farm_ID'] ?>">

                                                <p class="text-muted small mb-3">
                                                    Enter the new seeding date and breeding method to start a fresh growth cycle for this farm.
                                                </p>

                                                <div class="form-floating mb-3">
                                                    <input type="date" name="seedingDate" class="form-control"
                                                        id="reseedDate-<?= $farm['farm_ID'] ?>"
                                                        value="<?= date('Y-m-d') ?>"
                                                        max="<?= date('Y-m-d') ?>"
                                                        required>
                                                    <label for="reseedDate-<?= $farm['farm_ID'] ?>">Seeding Date</label>
                                                </div>

                                                <label class="form-label fw-semibold">Breeding Method:</label>

                                                <div class="dropdown mb-2">
                                                    <button class="btn btn-outline-secondary dropdown-toggle text-start w-100" type="button" data-bs-toggle="dropdown">Natural Breeding</button>
                                                    <div class="dropdown-menu p-3 dropdown-radio w-100">
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="radio" name="breedtype" id="rs-stake-<?= $farm['farm_ID'] ?>" value="Stake Method">
                                                            <label class="form-check-label" for="rs-stake-<?= $farm['farm_ID'] ?>">Stake Method (Tulos / Patusok)</label>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="dropdown mb-2">
                                                    <button class="btn btn-outline-secondary dropdown-toggle text-start w-100" type="button" data-bs-toggle="dropdown">Selective Breeding (Genetic Improvement)</button>
                                                    <div class="dropdown-menu p-3 dropdown-radio w-100">
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="radio" name="breedtype" id="rs-line-<?= $farm['farm_ID'] ?>" value="line">
                                                            <label class="form-check-label" for="rs-line-<?= $farm['farm_ID'] ?>">Line Breeding</label>
                                                        </div>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="radio" name="breedtype" id="rs-family-<?= $farm['farm_ID'] ?>" value="family">
                                                            <label class="form-check-label" for="rs-family-<?= $farm['farm_ID'] ?>">Family Breeding</label>
                                                        </div>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="radio" name="breedtype" id="rs-cross-<?= $farm['farm_ID'] ?>" value="cross">
                                                            <label class="form-check-label" for="rs-cross-<?= $farm['farm_ID'] ?>">Crossbreeding</label>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="dropdown mb-2">
                                                    <button class="btn btn-outline-secondary dropdown-toggle text-start w-100" type="button" data-bs-toggle="dropdown">Hatchery / Artificial Breeding (Spawning Induction)</button>
                                                    <div class="dropdown-menu p-3 dropdown-radio w-100">
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="radio" name="breedtype" id="rs-temp-<?= $farm['farm_ID'] ?>" value="Temperature Manipulation">
                                                            <label class="form-check-label" for="rs-temp-<?= $farm['farm_ID'] ?>">Temperature Manipulation</label>
                                                        </div>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="radio" name="breedtype" id="rs-env-<?= $farm['farm_ID'] ?>" value="Environmental Cues">
                                                            <label class="form-check-label" for="rs-env-<?= $farm['farm_ID'] ?>">Environmental Cues</label>
                                                        </div>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="radio" name="breedtype" id="rs-fert-<?= $farm['farm_ID'] ?>" value="Fertilization">
                                                            <label class="form-check-label" for="rs-fert-<?= $farm['farm_ID'] ?>">Fertilization</label>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="dropdown mb-2">
                                                    <button class="btn btn-outline-secondary dropdown-toggle text-start w-100" type="button" data-bs-toggle="dropdown">Ploidy Manipulation (Triploid Production)</button>
                                                    <div class="dropdown-menu p-3 dropdown-radio w-100">
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="radio" name="breedtype" id="rs-tri-<?= $farm['farm_ID'] ?>" value="Triploids">
                                                            <label class="form-check-label" for="rs-tri-<?= $farm['farm_ID'] ?>">Triploids</label>
                                                        </div>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="radio" name="breedtype" id="rs-tetra-<?= $farm['farm_ID'] ?>" value="Tetraploids (4n)">
                                                            <label class="form-check-label" for="rs-tetra-<?= $farm['farm_ID'] ?>">Tetraploids (4n)</label>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="dropdown mb-3">
                                                    <button class="btn btn-outline-secondary dropdown-toggle text-start w-100" type="button" data-bs-toggle="dropdown">Larval Settlement and Nursery</button>
                                                    <div class="dropdown-menu p-3 dropdown-radio w-100">
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="radio" name="breedtype" id="rs-cultch-<?= $farm['farm_ID'] ?>" value="Cultch / Substrate">
                                                            <label class="form-check-label" for="rs-cultch-<?= $farm['farm_ID'] ?>">Cultch / Substrate</label>
                                                        </div>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="radio" name="breedtype" id="rs-seed-<?= $farm['farm_ID'] ?>" value="Seed Collection">
                                                            <label class="form-check-label" for="rs-seed-<?= $farm['farm_ID'] ?>">Seed Collection</label>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="d-flex justify-content-end gap-2">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" name="reseedFarm" class="btn btn-info text-white">
                                                        🌱 Start New Cycle
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div><!-- /reseedFarm-modal -->


                                <!-- Edit Modal for farm <?= $farm['farm_ID'] ?> -->
                                <div class="modal fade" id="editFarm-modal-<?= $farm['farm_ID'] ?>" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="editFarmLabel-<?= $farm['farm_ID'] ?>" aria-hidden="true">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h2 class="modal-title fs-5" id="editFarmLabel-<?= $farm['farm_ID'] ?>">Edit <?= htmlspecialchars($farm['farmName_number']) ?></h2>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <form method="post" action="farm_dashboard_rtl.php" enctype="multipart/form-data" class="modal-body">

                                                <!-- hidden: carries the farm ID to PHP -->
                                                <input type="hidden" name="farmID" value="<?= $farm['farm_ID'] ?>">

                                                <div class="form-floating mb-2">
                                                    <input type="text" name="location" class="form-control"
                                                        id="edit-location-<?= $farm['farm_ID'] ?>"
                                                        placeholder="Location"
                                                        value="<?= htmlspecialchars($farm['location'] ?? '') ?>">
                                                    <label for="edit-location-<?= $farm['farm_ID'] ?>">Location</label>
                                                </div>

                                                <div class="form-floating mb-2">
                                                    <input type="number" step="0.01" min="0" name="surfaceArea" class="form-control"
                                                        id="edit-surfaceArea-<?= $farm['farm_ID'] ?>"
                                                        placeholder="Surface Area"
                                                        value="<?= htmlspecialchars($farm['surfaceArea'] ?? '') ?>">
                                                    <label for="edit-surfaceArea-<?= $farm['farm_ID'] ?>">Surface Area (square meter)</label>
                                                </div>

                                                <div class="form-floating mb-2">
                                                    <input type="date" name="seedingDate" class="form-control"
                                                        id="edit-seedingDate-<?= $farm['farm_ID'] ?>"
                                                        value="<?= htmlspecialchars($farm['seedingDate'] ?? '') ?>">
                                                    <label for="edit-seedingDate-<?= $farm['farm_ID'] ?>">Seeding Date</label>
                                                </div>

                                                <div class="form-floating mb-2">
                                                    <input type="number" step="0.01" min="0" name="waterDepts" class="form-control"
                                                        id="edit-waterDepts-<?= $farm['farm_ID'] ?>"
                                                        placeholder="Water Depth"
                                                        value="<?= htmlspecialchars($farm['waterDepts'] ?? '') ?>">
                                                    <label for="edit-waterDepts-<?= $farm['farm_ID'] ?>">Water Depth (meters)</label>
                                                </div>

                                                <!-- Breeding Methods -->
                                                <?php $currentMethod = $farm['breedMethod'] ?? ''; ?>
                                                <div class="breed-type mb-2">
                                                    <label class="form-label">
                                                        Breeding Methods:
                                                        <?php if (!empty($currentMethod)): ?>
                                                            <span class="badge bg-info text-dark ms-1"><?= htmlspecialchars($currentMethod) ?></span>
                                                        <?php endif; ?>
                                                    </label>

                                                    <div class="dropdown">
                                                        <button class="btn btn-outline-secondary dropdown-toggle text-start" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                            Natural Breeding
                                                        </button>
                                                        <div class="dropdown-menu p-3 w-100">
                                                            <div class="form-check">
                                                                <input class="form-check-input" type="radio" name="breedtype"
                                                                    id="edit-stake-<?= $farm['farm_ID'] ?>"
                                                                    value="Stake Method"
                                                                    <?= $currentMethod === 'Stake Method' ? 'checked' : '' ?>>
                                                                <label class="form-check-label" for="edit-stake-<?= $farm['farm_ID'] ?>">Stake Method (Tulos / Patusok)</label>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <div class="dropdown">
                                                        <button class="btn btn-outline-secondary dropdown-toggle text-start" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                            Selective Breeding (Genetic Improvement)
                                                        </button>
                                                        <div class="dropdown-menu p-3 w-100">
                                                            <div class="form-check">
                                                                <input class="form-check-input" type="radio" name="breedtype"
                                                                    id="edit-line-<?= $farm['farm_ID'] ?>"
                                                                    value="line"
                                                                    <?= $currentMethod === 'line' ? 'checked' : '' ?>>
                                                                <label class="form-check-label" for="edit-line-<?= $farm['farm_ID'] ?>">Line Breeding</label>
                                                            </div>
                                                            <div class="form-check">
                                                                <input class="form-check-input" type="radio" name="breedtype"
                                                                    id="edit-family-<?= $farm['farm_ID'] ?>"
                                                                    value="family"
                                                                    <?= $currentMethod === 'family' ? 'checked' : '' ?>>
                                                                <label class="form-check-label" for="edit-family-<?= $farm['farm_ID'] ?>">Family Breeding</label>
                                                            </div>
                                                            <div class="form-check mb-2">
                                                                <input class="form-check-input" type="radio" name="breedtype"
                                                                    id="edit-cross-<?= $farm['farm_ID'] ?>"
                                                                    value="cross"
                                                                    <?= $currentMethod === 'cross' ? 'checked' : '' ?>>
                                                                <label class="form-check-label" for="edit-cross-<?= $farm['farm_ID'] ?>">Crossbreeding</label>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <div class="dropdown">
                                                        <button class="btn btn-outline-secondary dropdown-toggle text-start" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                            Hatchery / Artificial Breeding (Spawning Induction)
                                                        </button>
                                                        <div class="dropdown-menu p-3 w-100">
                                                            <div class="form-check">
                                                                <input class="form-check-input" type="radio" name="breedtype"
                                                                    id="edit-temp-<?= $farm['farm_ID'] ?>"
                                                                    value="Temperature Manipulation"
                                                                    <?= $currentMethod === 'Temperature Manipulation' ? 'checked' : '' ?>>
                                                                <label class="form-check-label" for="edit-temp-<?= $farm['farm_ID'] ?>">Temperature Manipulation</label>
                                                            </div>
                                                            <div class="form-check">
                                                                <input class="form-check-input" type="radio" name="breedtype"
                                                                    id="edit-env-<?= $farm['farm_ID'] ?>"
                                                                    value="Environmental Cues"
                                                                    <?= $currentMethod === 'Environmental Cues' ? 'checked' : '' ?>>
                                                                <label class="form-check-label" for="edit-env-<?= $farm['farm_ID'] ?>">Environmental Cues</label>
                                                            </div>
                                                            <div class="form-check mb-2">
                                                                <input class="form-check-input" type="radio" name="breedtype"
                                                                    id="edit-fert-<?= $farm['farm_ID'] ?>"
                                                                    value="Fertilization"
                                                                    <?= $currentMethod === 'Fertilization' ? 'checked' : '' ?>>
                                                                <label class="form-check-label" for="edit-fert-<?= $farm['farm_ID'] ?>">Fertilization</label>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <div class="dropdown">
                                                        <button class="btn btn-outline-secondary dropdown-toggle text-start" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                            Ploidy Manipulation (Triploid Production)
                                                        </button>
                                                        <div class="dropdown-menu p-3 w-100">
                                                            <div class="form-check">
                                                                <input class="form-check-input" type="radio" name="breedtype"
                                                                    id="edit-triploid-<?= $farm['farm_ID'] ?>"
                                                                    value="Triploids"
                                                                    <?= $currentMethod === 'Triploids' ? 'checked' : '' ?>>
                                                                <label class="form-check-label" for="edit-triploid-<?= $farm['farm_ID'] ?>">Triploids</label>
                                                            </div>
                                                            <div class="form-check">
                                                                <input class="form-check-input" type="radio" name="breedtype"
                                                                    id="edit-tetra-<?= $farm['farm_ID'] ?>"
                                                                    value="Tetraploids (4n)"
                                                                    <?= $currentMethod === 'Tetraploids (4n)' ? 'checked' : '' ?>>
                                                                <label class="form-check-label" for="edit-tetra-<?= $farm['farm_ID'] ?>">Tetraploids (4n)</label>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <div class="dropdown">
                                                        <button class="btn btn-outline-secondary dropdown-toggle text-start" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                            Larval Settlement and Nursery
                                                        </button>
                                                        <div class="dropdown-menu p-3 w-100">
                                                            <div class="form-check">
                                                                <input class="form-check-input" type="radio" name="breedtype"
                                                                    id="edit-cultch-<?= $farm['farm_ID'] ?>"
                                                                    value="Cultch / Substrate"
                                                                    <?= $currentMethod === 'Cultch / Substrate' ? 'checked' : '' ?>>
                                                                <label class="form-check-label" for="edit-cultch-<?= $farm['farm_ID'] ?>">Cultch / Substrate</label>
                                                            </div>
                                                            <div class="form-check">
                                                                <input class="form-check-input" type="radio" name="breedtype"
                                                                    id="edit-seed-<?= $farm['farm_ID'] ?>"
                                                                    value="Seed Collection"
                                                                    <?= $currentMethod === 'Seed Collection' ? 'checked' : '' ?>>
                                                                <label class="form-check-label" for="edit-seed-<?= $farm['farm_ID'] ?>">Seed Collection</label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- Farm Image upload (optional on edit) -->
                                                <div class="mb-3">
                                                    <label class="form-label">
                                                        <i class="bi bi-image"></i> Farm Image
                                                        <small class="text-muted">(leave blank to keep current)</small>
                                                    </label>
                                                    <div class="input-group">
                                                        <span class="input-group-text"><i class="bi bi-upload"></i></span>
                                                        <input type="file" class="form-control" name="farmImage"
                                                            id="edit-farmImage-<?= $farm['farm_ID'] ?>"
                                                            accept="image/png, image/jpeg, image/jpg">
                                                    </div>
                                                    <small class="text-muted">Allowed formats: JPG, JPEG, PNG</small>
                                                </div>

                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" name="updateFarm" class="btn btn-primary">Update</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- <div class="row farm-card">
                                <button class="col farm-width btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#staticBackdrop">
                                    Add Farm
                                    <br>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-plus-square" viewBox="0 0 16 16">
                                        <path d="M14 1a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1zM2 0a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V2a2 2 0 0 0-2-2z"/>
                                        <path d="M8 4a.5.5 0 0 1 .5.5v3h3a.5.5 0 0 1 0 1h-3v3a.5.5 0 0 1-1 0v-3h-3a.5.5 0 0 1 0-1h3v-3A.5.5 0 0 1 8 4"/>
                                    </svg>
                                </button>
                            </div> -->
                        </div>
                    </section>
                </main>

                <!-- Notification Modal -->
                <!-- <div class="modal fade" id="notificationModal" tabindex="-1">
                    <div class="modal-dialog modal-dialog-scrollable modal-dialog-slideout modal-lg modal-dialog-end">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Notifications</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body p-0">
                                
                                <div class="list-group list-group-flush" id="notification-list">
                                    
                                    <div class="list-group-item list-group-item-action">
                                        <strong>loading ....</strong>
                                        <div class="small text-muted">....</div>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>
                </div> -->

                <!-- add farm modal -->
                <div class="modal fade" id="staticBackdrop" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="staticBackdropLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                        <div class="modal-header">
                            <h2 class="modal-title fs-5" id="staticBackdropLabel">Add Farming Area</h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <form method="post" action="farm_dashboard_rtl.php" enctype="multipart/form-data" class="modal-body">
                            <!-- <div class="form-floating"> -->
                                <!-- <input type="text" name="location" class="form-control" id="floatingInput" placeholder="Location" require>
                                <label for="floatingInput">Location</label> -->
                                <!-- MAP PICKER: replaces the plain location text input -->
                            <!-- </div> -->
                            <div class="mb-3 mt-2">
                                <label class="form-label fw-semibold">
                                    <i class="bi bi-geo-alt-fill text-info"></i> Farm Location <span class="text-danger">*</span>
                                </label>

                                <div id="farm-map-picker" style="height:260px; border-radius:10px; border:2px solid #00b4d8; box-shadow:0 2px 12px rgba(0,180,216,0.15);"></div>

                                <div class="d-flex gap-2 mt-2">
                                    <div class="flex-fill p-2 rounded-2 border" style="border-color:#b2e9f7!important; background:#f0fbff;">
                                        <small class="d-block fw-bold text-uppercase" style="font-size:.68rem;color:#0096c7;letter-spacing:.5px;">Latitude</small>
                                        <input type="text" id="lat-display" class="border-0 bg-transparent fw-semibold w-100" style="color:#023e8a;outline:none;" readonly placeholder="—">
                                    </div>
                                    <div class="flex-fill p-2 rounded-2 border" style="border-color:#b2e9f7!important; background:#f0fbff;">
                                        <small class="d-block fw-bold text-uppercase" style="font-size:.68rem;color:#0096c7;letter-spacing:.5px;">Longitude</small>
                                        <input type="text" id="lng-display" class="border-0 bg-transparent fw-semibold w-100" style="color:#023e8a;outline:none;" readonly placeholder="—">
                                    </div>
                                </div>

                                <div id="map-address-display" class="mt-2 p-2 rounded-2 small" style="background:#f0fbff; border:1.5px solid #b2e9f7; min-height:34px; color:#374151;">
                                    <span class="text-muted">No location selected yet. Click the map above.</span>
                                </div>

                                <input type="hidden" name="location"  id="location-hidden" required>
                                <input type="hidden" name="latitude"  id="lat-hidden">
                                <input type="hidden" name="longitude" id="lng-hidden">
                            </div>
                            <div class="form-floating">
                                <input type="text" step="0.01" min="0" name="surfaceArea" class="form-control" id="surfaceAreaInput" placeholder="Surface Area" required>
                                <label for="surfaceAreaInput">Surface Area (square meter)</label>
                            </div>
                            <div class="form-floating">
                                <input type="date" name="seedingDate" class="form-control" id="seedingDateInput" placeholder="Seeding Date" required>
                                <label for="seedingDateInput">Seeding Date</label>
                            </div>
                            <div class="form-floating">
                                <input type="number" step="0.01" min="0" name="waterDepts" class="form-control" id="waterDeptsInput" placeholder="Water Depth" required>
                                <label for="waterDeptsInput">Water Depth (meters)</label>
                            </div>
                            <!-- <div class="form-floating"> -->
                                <!-- <input type="text" name="breedingMethod" class="form-control" id="floatingInput" placeholder="Breeding Method"> -->
                            <div class="breed-type" id="breed-type">
                                <label for="breed-type" class="form-label">Breeding Methods:</label>

                                <div class="dropdown">
                                    <button class="btn btn-outline-secondary dropdown-toggle text-start" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                        Natural Breeding
                                    </button>
                                    <div class="dropdown-menu p-3 dropdown-radio w-100">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="breedtype" id="stakeMethodModal" value="Stake Method">
                                            <label class="form-check-label" for="stakeMethodModal">Stake Method (Tulos / Patusok)</label>
                                        </div>
                                    </div>
                                </div>
                                <div class="dropdown">
                                    <button class="btn btn-outline-secondary dropdown-toggle text-start" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                        Selective Breeding (Genetic Improvement)
                                    </button>
                                    <div class="dropdown-menu p-3 dropdown-radio w-100">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="breedtype" id="lineBreedingModal" value="line">
                                            <label class="form-check-label" for="lineBreedingModal">Line Breeding</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="breedtype" id="familyBreedingModal" value="family">
                                            <label class="form-check-label" for="familyBreedingModal">Family Breeding</label>
                                        </div>
                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="radio" name="breedtype" id="crossBreedingModal" value="cross">
                                            <label class="form-check-label" for="crossBreedingModal">Crossbreeding</label>
                                        </div>
                                    </div>
                                </div>
                                <div class="dropdown">
                                    <button class="btn btn-outline-secondary dropdown-toggle text-start" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                        Hatchery / Artificial Breeding (Spawning Induction)
                                    </button>
                                    <div class="dropdown-menu p-3 dropdown-radio w-100">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="breedtype" id="tempManipModal" value="Temperature Manipulation">
                                            <label class="form-check-label" for="tempManipModal">Temperature Manipulation</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="breedtype" id="envCuesModal" value="Environmental Cues">
                                            <label class="form-check-label" for="envCuesModal">Environmental Cues</label>
                                        </div>
                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="radio" name="breedtype" id="fertilizationModal" value="Fertilization">
                                            <label class="form-check-label" for="fertilizationModal">Fertilization</label>
                                        </div>
                                    </div>
                                </div>
                                <div class="dropdown">
                                    <button class="btn btn-outline-secondary dropdown-toggle text-start" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                        Ploidy Manipulation (Triploid Production)
                                    </button>
                                    <div class="dropdown-menu p-3 dropdown-radio w-100">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="breedtype" id="triploidsModal" value="Triploids">
                                            <label class="form-check-label" for="triploidsModal">Triploids</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="breedtype" id="tetraploidsModal" value="Tetraploids (4n)">
                                            <label class="form-check-label" for="tetraploidsModal">Tetraploids (4n)</label>
                                        </div>
                                    </div>
                                </div>
                                <div class="dropdown">
                                    <button class="btn btn-outline-secondary dropdown-toggle text-start" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                        Larval Settlement and Nursery
                                    </button>
                                    <div class="dropdown-menu p-3 dropdown-radio w-100">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="breedtype" id="cultchModal" value="Cultch / Substrate">
                                            <label class="form-check-label" for="cultchModal">Cultch / Substrate</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="breedtype" id="seedCollectionModal" value="Seed Collection">
                                            <label class="form-check-label" for="seedCollectionModal">Seed Collection</label>
                                        </div>
                                    </div>
                                </div>

                            </div>

                            <!-- add image / upload photo -->
                            <div class="mb-3">
                                <label class="form-label">
                                    <i class="bi bi-image"></i> Farm Image
                                </label>

                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="bi bi-upload"></i>
                                    </span>
                                    <input 
                                        type="file" 
                                        class="form-control" 
                                        name="farmImage" 
                                        id="farmImage"
                                        accept="image/png, image/jpeg, image/jpg"
                                        required
                                    >
                                </div>

                                <small class="text-muted">
                                    Allowed formats: JPG, JPEG, PNG
                                </small>
                            </div>

                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" name="registerFarm" class="btn btn-primary">Register</button>
                            </div>
                        </form>
                    </div>
                </div>
                </div><!-- closes #staticBackdrop modal -->

        <!-- ============================================================
             EDIT PROFILE MODAL
             ============================================================ -->
        <div class="modal fade" id="editProfileModal" tabindex="-1" aria-labelledby="editProfileModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header" style="background: linear-gradient(135deg, #0d6efd, #0096c7); color:white;">
                        <h5 class="modal-title fw-bold" id="editProfileModalLabel">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" class="bi bi-person-gear me-2" viewBox="0 0 16 16">
                                <path d="M11 5a3 3 0 1 1-6 0 3 3 0 0 1 6 0M8 7a2 2 0 1 0 0-4 2 2 0 0 0 0 4m.256 7a4.5 4.5 0 0 1-.229-1.004H3c.001-.246.154-.986.832-1.664C4.484 10.68 5.711 10 8 10q.39 0 .74.025c.226-.341.496-.65.804-.918Q8.844 9.002 8 9c-5 0-6 3-6 4s1 1 1 1zm3.63-4.54c.18-.613 1.048-.613 1.229 0l.043.148a.64.64 0 0 0 .921.382l.136-.074c.561-.305 1.175.308.87.869l-.075.136a.64.64 0 0 0 .382.92l.149.045c.612.18.612 1.048 0 1.229l-.15.043a.64.64 0 0 0-.38.921l.074.136c.305.561-.309 1.175-.87.87l-.136-.075a.64.64 0 0 0-.92.382l-.045.149c-.18.612-1.048.612-1.229 0l-.043-.15a.64.64 0 0 0-.921-.38l-.136.074c-.561.305-1.175-.309-.87-.87l.075-.136a.64.64 0 0 0-.382-.92l-.148-.045c-.613-.18-.613-1.048 0-1.229l.148-.043a.64.64 0 0 0 .382-.921l-.074-.136c-.306-.562.308-1.175.869-.87l.136.075a.64.64 0 0 0 .92-.382zM14 12.5a1.5 1.5 0 1 0-3 0 1.5 1.5 0 0 0 3 0"/>
                            </svg>
                            Edit Profile
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form method="POST" action="farm_dashboard_rtl.php" enctype="multipart/form-data">
                        <div class="modal-body">

                            <!-- Profile photo preview + upload -->
                            <div class="text-center mb-4">
                                <div class="position-relative d-inline-block">
                                    <!-- <img
                                        id="profilePreview"
                                        src="<?= !empty($user[0]['imagePath'])
                                            ? '../upload-image/' . htmlspecialchars($user[0]['imagePath'])
                                            : '../assets/img/default-avatar.png' ?>"
                                        alt="Profile photo"
                                        class="rounded-circle border border-3 border-primary"
                                        style="width:100px; height:100px; object-fit:cover;"
                                        onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode(($user[0]['firstName'] ?? 'U') . '+' . ($user[0]['lastName'] ?? '')) ?>&background=0d6efd&color=fff&size=100'"
                                    >
                                     -->
                                    <img
                                        id="profilePreview"
                                        src="<?= !empty($user[0]['imagePath'])
                                            ? '../upload-image/' . htmlspecialchars($user[0]['imagePath']) . '?t=' . time()
                                            : '../assets/img/default-avatar.png' ?>"
                                        alt="Profile photo"
                                        class="rounded-circle border border-3 border-primary"
                                        style="width:100px; height:100px; object-fit:cover;"
                                        onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode(($user[0]['firstName'] ?? 'U') . '+' . ($user[0]['lastName'] ?? '')) ?>&background=0d6efd&color=fff&size=100'"
                                    >
                                    <label for="profileImage" class="position-absolute bottom-0 end-0 bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width:28px;height:28px;cursor:pointer;" title="Upload photo">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-camera" viewBox="0 0 16 16">
                                            <path d="M15 12a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h1.172a3 3 0 0 0 2.12-.879l.83-.828A1 1 0 0 1 6.827 3h2.344a1 1 0 0 1 .707.293l.828.828A3 3 0 0 0 12.828 5H14a1 1 0 0 1 1 1zM2 4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-1.172a2 2 0 0 1-1.414-.586l-.828-.828A2 2 0 0 0 9.172 2H6.828a2 2 0 0 0-1.414.586l-.828.828A2 2 0 0 0 3.172 4z"/>
                                            <path d="M8 11a2.5 2.5 0 1 1 0-5 2.5 2.5 0 0 1 0 5m0 1a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7M3 6.5a.5.5 0 1 1-1 0 .5.5 0 0 1 1 0"/>
                                        </svg>
                                    </label>
                                </div>
                                <input
                                    type="file"
                                    id="profileImage"
                                    name="profileImage"
                                    accept="image/png, image/jpeg, image/jpg"
                                    class="d-none"
                                    onchange="previewProfilePhoto(this)"
                                >
                                <div class="text-muted mt-1" style="font-size:.75rem;">JPG, JPEG, PNG · max 5 MB</div>
                            </div>

                            <div class="row g-3">
                                <!-- First name -->
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">First Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="firstName"
                                           value="<?= htmlspecialchars($user[0]['firstName'] ?? '') ?>" required>
                                </div>
                                <!-- Middle name -->
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Middle Name</label>
                                    <input type="text" class="form-control" name="middleName"
                                           value="<?= htmlspecialchars($user[0]['middleName'] ?? '') ?>">
                                </div>
                                <!-- Last name -->
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Last Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="lastName"
                                           value="<?= htmlspecialchars($user[0]['lastName'] ?? '') ?>" required>
                                </div>
                                <!-- Address -->
                                <div class="col-12">
                                    <label class="form-label fw-semibold">Address</label>
                                    <input type="text" class="form-control" name="address"
                                           value="<?= htmlspecialchars($user[0]['address'] ?? '') ?>">
                                </div>
                                <!-- Email -->
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Email <span class="text-danger">*</span></label>
                                    <input type="email" class="form-control" name="email"
                                           value="<?= htmlspecialchars($user[0]['email'] ?? '') ?>" required>
                                </div>
                                <!-- Phone -->
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Phone / Contact No.</label>
                                    <input type="text" class="form-control" name="contactNo"
                                           value="<?= htmlspecialchars($user[0]['contactNo'] ?? '') ?>">
                                </div>
                                <!-- Sex -->
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Sex</label>
                                    <select class="form-select" name="sex">
                                        <option value="">— Select —</option>
                                        <option value="Male"   <?= ($user[0]['sex'] ?? '') === 'Male'   ? 'selected' : '' ?>>Male</option>
                                        <option value="Female" <?= ($user[0]['sex'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
                                    </select>
                                </div>
                                <!-- Birth date -->
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Birth Date</label>
                                    <input type="date" class="form-control" name="birthDate"
                                           value="<?= htmlspecialchars($user[0]['birthDate'] ?? '') ?>">
                                </div>
                            </div>

                            <!-- Password section -->
                            <hr class="my-3">
                            <p class="fw-semibold mb-2">
                                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="currentColor" class="bi bi-lock me-1" viewBox="0 0 16 16">
                                    <path d="M8 1a2 2 0 0 1 2 2v4H6V3a2 2 0 0 1 2-2m3 6V3a3 3 0 0 0-6 0v4a2 2 0 0 0-2 2v5a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2"/>
                                </svg>
                                Change Password <small class="text-muted fw-normal">(leave blank to keep current)</small>
                            </p>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">New Password</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" name="newPassword" id="newPasswordInput" placeholder="Enter new password">
                                        <button class="btn btn-outline-secondary" type="button" onclick="togglePw('newPasswordInput', this)">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-eye" viewBox="0 0 16 16"><path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8M1.173 8a13 13 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5s3.879 1.168 5.168 2.457A13 13 0 0 1 14.828 8q-.086.13-.195.288c-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5s-3.879-1.168-5.168-2.457A13 13 0 0 1 1.172 8z"/><path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5M4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0"/></svg>
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Confirm Password</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" id="confirmPasswordInput" placeholder="Confirm new password">
                                        <button class="btn btn-outline-secondary" type="button" onclick="togglePw('confirmPasswordInput', this)">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-eye" viewBox="0 0 16 16"><path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8M1.173 8a13 13 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5s3.879 1.168 5.168 2.457A13 13 0 0 1 14.828 8q-.086.13-.195.288c-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5s-3.879-1.168-5.168-2.457A13 13 0 0 1 1.172 8z"/><path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5M4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0"/></svg>
                                        </button>
                                    </div>
                                    <div id="pwMismatch" class="text-danger mt-1" style="font-size:.8rem;display:none;">Passwords do not match.</div>
                                </div>
                            </div>

                        </div><!-- /.modal-body -->
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="updateProfile" class="btn btn-primary" onclick="return validateProfileForm()">
                                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="currentColor" class="bi bi-floppy me-1" viewBox="0 0 16 16"><path d="M11 2H9v3h2z"/><path d="M1.5 0h11.586a1.5 1.5 0 0 1 1.06.44l1.415 1.414A1.5 1.5 0 0 1 16 2.914V14.5a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 0 14.5v-13A1.5 1.5 0 0 1 1.5 0M1 1.5v13a.5.5 0 0 0 .5.5H2v-4.5A1.5 1.5 0 0 1 3.5 9h9a1.5 1.5 0 0 1 1.5 1.5V15h.5a.5.5 0 0 0 .5-.5V2.914a.5.5 0 0 0-.146-.353l-1.415-1.415A.5.5 0 0 0 13.086 1H13v4.5A1.5 1.5 0 0 1 11.5 7h-7A1.5 1.5 0 0 1 3 5.5V1H1.5a.5.5 0 0 0-.5.5m3 4a.5.5 0 0 0 .5.5h7a.5.5 0 0 0 .5-.5V1H4zm6 6.5v3h-6v-3a.5.5 0 0 1 .5-.5h5a.5.5 0 0 1 .5.5"/></svg>
                                Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <!-- /EDIT PROFILE MODAL -->


        <!-- ============================================================
             REPORT MODAL  — fully wired to back-end
             ============================================================ -->
        <div class="modal fade" id="reportModal" tabindex="-1" aria-labelledby="reportModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header" style="background: linear-gradient(135deg, #dc3545, #fd7e14); color:white;">
                        <h5 class="modal-title fw-bold" id="reportModalLabel">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" class="bi bi-flag me-2" viewBox="0 0 16 16">
                                <path d="M14.778.085A.5.5 0 0 1 15 .5V8a.5.5 0 0 1-.314.464L14.5 8l.186.464-.003.001-.006.003-.023.009a12 12 0 0 1-.397.15c-.264.095-.631.223-1.047.35-.816.252-1.879.523-2.71.523-.847 0-1.548-.28-2.158-.525l-.028-.01C7.68 8.71 7.14 8.5 6.5 8.5c-.7 0-1.638.23-2.437.477A20 20 0 0 0 3 9.342V15.5a.5.5 0 0 1-1 0V.5a.5.5 0 0 1 1 0v.282c.226-.079.496-.17.79-.26C4.606.272 5.67 0 6.5 0c.84 0 1.524.277 2.121.519l.043.018C9.286.788 9.828 1 10.5 1c.7 0 1.638-.23 2.437-.477a20 20 0 0 0 1.361-.544l.019-.009.004-.002ZM14 1.221c-.22.078-.48.167-.766.255C12.362 1.73 11.3 2 10.5 2c-.84 0-1.524-.277-2.121-.519l-.043-.018C7.714 1.212 7.172 1 6.5 1c-.51 0-1.19.135-1.83.323-.361.112-.679.228-.913.318v8.699c.254-.086.543-.177.843-.264C5.394 9.77 6.458 9.5 7.5 9.5c.74 0 1.524.213 2.18.52C9.838 10.247 10.285 10.5 11 10.5c.51 0 1.19-.135 1.83-.323.361-.112.679-.228.913-.318z"/>
                            </svg>
                            Make a Report
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <!-- Wrapping FORM for the entire modal body+footer -->
                    <form method="POST" action="farm_dashboard_rtl.php" enctype="multipart/form-data" id="reportForm">
                        <!-- Hidden: which tab is active → sets reportType -->
                        <input type="hidden" name="reportType" id="reportTypeInput" value="damage">
                        <div class="modal-body overflow-y-auto" style="max-height: calc(100vh - 220px);">

                        <!-- Report type tabs -->
                        <ul class="nav nav-pills mb-4 gap-2" id="reportTypeTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active d-flex align-items-center gap-1" id="tab-damage" data-bs-toggle="pill" data-bs-target="#report-damage" type="button" role="tab">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-exclamation-triangle" viewBox="0 0 16 16"><path d="M7.938 2.016A.13.13 0 0 1 8.002 2a.13.13 0 0 1 .063.016.15.15 0 0 1 .054.057l6.857 11.667c.036.06.035.124.002.183a.2.2 0 0 1-.054.06.1.1 0 0 1-.066.017H1.146a.1.1 0 0 1-.066-.017.2.2 0 0 1-.054-.06.18.18 0 0 1 .002-.183L7.884 2.073a.15.15 0 0 1 .054-.057m1.044-.45a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767z"/><path d="M7.002 12a1 1 0 1 1 2 0 1 1 0 0 1-2 0M7.1 5.995a.905.905 0 1 1 1.8 0l-.35 3.507a.552.552 0 0 1-1.1 0z"/></svg>
                                    Damage
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link d-flex align-items-center gap-1" id="tab-feedback" data-bs-toggle="pill" data-bs-target="#report-feedback" type="button" role="tab">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-bug" viewBox="0 0 16 16"><path d="M4.355.522a.5.5 0 0 1 .623.333l.291.956A5 5 0 0 1 8 1a5 5 0 0 1 2.731.811l.29-.956a.5.5 0 1 1 .957.29l-.41 1.352A5 5 0 0 1 13 6h.5a.5.5 0 0 0 .5-.5V5a.5.5 0 0 1 1 0v.5A1.5 1.5 0 0 1 13.5 7H13v1h1.5a.5.5 0 0 1 0 1H13v1h.5a1.5 1.5 0 0 1 1.5 1.5v.5a.5.5 0 1 1-1 0v-.5a.5.5 0 0 0-.5-.5H13a5 5 0 0 1-10 0H2.5a.5.5 0 0 0-.5.5v.5a.5.5 0 1 1-1 0V11A1.5 1.5 0 0 1 2.5 9.5H3v-1H1.5a.5.5 0 0 1 0-1H3V7h-.5A1.5 1.5 0 0 1 1 5.5V5a.5.5 0 0 1 1 0v.5a.5.5 0 0 0 .5.5H3a5 5 0 0 1 1.530-3.543L4.12.855a.5.5 0 0 1 .236-.333zM8 2a4 4 0 0 0-3.646 5.635Q4.543 8 5 8h6q.457-.001.646-.365A4 4 0 0 0 8 2m3 6H5v1a3 3 0 0 0 6 0z"/></svg>
                                    Feedback / Bug
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link d-flex align-items-center gap-1" id="tab-request" data-bs-toggle="pill" data-bs-target="#report-request" type="button" role="tab">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-hand-index-thumb" viewBox="0 0 16 16"><path d="M6.75 1a.75.75 0 0 1 .75.75V8a.5.5 0 0 0 1 0V5.467l.086-.004c.317-.012.637.008.816.027l.028.003c.3.038.53.168.68.324.133.138.218.3.234.476q.013.15.008.312V8.5a.5.5 0 0 0 1 0V5.75a.75.75 0 0 1 1.5 0v3a5 5 0 0 1-10 0v-1a.75.75 0 0 1 1.5 0"/></svg>
                                    Request
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link d-flex align-items-center gap-1" id="tab-other" data-bs-toggle="pill" data-bs-target="#report-other" type="button" role="tab">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-three-dots" viewBox="0 0 16 16"><path d="M3 9.5a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3m5 0a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3m5 0a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3"/></svg>
                                    Others
                                </button>
                            </li>
                        </ul>

                        <!-- Tab pane content -->
                        <div class="tab-content" id="reportTabContent">

                            <!-- Damage Report -->
                            <div class="tab-pane fade show active" id="report-damage" role="tabpanel">
                                <div class="alert alert-danger d-flex align-items-start gap-2 py-2">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1" viewBox="0 0 16 16"><path d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5m.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2"/></svg>
                                    <small>Use this form to report physical damage to your farm, equipment, or oyster stock.</small>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Subject <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="damage-subject" placeholder="Brief summary of the damage">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Farm Affected</label>
                                    <select class="form-select" name="farmID_r">
                                        <option value="">— Select a farm —</option>
                                        <?php foreach ($farms as $f): ?>
                                            <option value="<?= $f['farm_ID'] ?>"><?= htmlspecialchars($f['farmName_number']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Damage Type</label>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php foreach (['Storm / Typhoon', 'Flooding', 'Equipment Failure', 'Disease / Pest', 'Theft / Vandalism', 'Other'] as $dt): ?>
                                            <div class="form-check form-check-inline border rounded px-3 py-2">
                                                <input class="form-check-input" type="checkbox" name="damageTypes[]" value="<?= $dt ?>" id="dmg_<?= md5($dt) ?>">
                                                <label class="form-check-label" for="dmg_<?= md5($dt) ?>"><?= $dt ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Date of Damage</label>
                                    <input type="date" class="form-control" name="damageDate" max="<?= date('Y-m-d') ?>">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Estimated Loss (kg / units)</label>
                                    <input type="number" class="form-control" name="estimatedLoss" placeholder="e.g. 50" min="0">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Description</label>
                                    <textarea class="form-control" name="damage-description" rows="3" placeholder="Describe what happened..."></textarea>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Attach Photo (optional)</label>
                                    <input type="file" class="form-control" name="reportAttachment" accept="image/*">
                                </div>
                            </div>

                            <!-- Feedback / Bug Report -->
                            <div class="tab-pane fade" id="report-feedback" role="tabpanel">
                                <div class="alert alert-info d-flex align-items-start gap-2 py-2">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-info-circle-fill flex-shrink-0 mt-1" viewBox="0 0 16 16"><path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16m.93-9.412-1 4.705c-.07.34.029.533.304.533.194 0 .487-.07.686-.246l-.088.416c-.287.346-.92.598-1.465.598-.703 0-1.002-.422-.808-1.319l.738-3.468c.064-.293.006-.399-.287-.47l-.451-.081.082-.381 2.29-.287zM8 5.5a1 1 0 1 1 0-2 1 1 0 0 1 0 2"/></svg>
                                    <small>Found a bug or have a suggestion? Let us know so we can improve the system.</small>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Subject <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="feedback-subject" placeholder="Brief title of the bug or suggestion">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Type</label>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php foreach (['Bug / Error', 'UI Issue', 'Performance', 'Feature Request', 'Suggestion', 'Other'] as $fb): ?>
                                            <div class="form-check form-check-inline border rounded px-3 py-2">
                                                <input class="form-check-input" type="radio" name="feedbackType" value="<?= $fb ?>" id="fb_<?= md5($fb) ?>">
                                                <label class="form-check-label" for="fb_<?= md5($fb) ?>"><?= $fb ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Page / Feature Affected</label>
                                    <input type="text" class="form-control" name="pageAffected" placeholder="e.g. Farm Registration, Calendar...">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Description</label>
                                    <textarea class="form-control" name="feadback-description" rows="4" placeholder="Describe the issue or your suggestion..."></textarea>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Screenshot (optional)</label>
                                    <input type="file" class="form-control" name="reportAttachment" accept="image/*">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Priority</label>
                                    <select class="form-select" name="priority">
                                        <option>Low</option>
                                        <option selected>Medium</option>
                                        <option>High</option>
                                        <option>Critical</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Request Report -->
                            <div class="tab-pane fade" id="report-request" role="tabpanel">
                                <div class="alert alert-warning d-flex align-items-start gap-2 py-2">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-hand-index-thumb-fill flex-shrink-0 mt-1" viewBox="0 0 16 16"><path d="M8.5 1.75v2.716l.047-.002c.312-.012.742.016.962.055.382.069.703.245.93.477.145.15.258.318.328.487.06.15.11.31.146.49.022.117.037.244.046.383.01.14.014.29.01.45-.006.3-.045.62-.12.92-.064.256-.15.497-.251.72-.1.22-.209.425-.322.61-.068.113-.14.224-.215.327l.041-.022c.1-.05.21-.097.322-.138.218-.08.46-.14.704-.163.202-.02.4-.013.58.02.224.043.43.14.6.296.165.15.29.35.362.6.063.22.08.474.044.75-.033.254-.11.51-.22.75a3 3 0 0 1-.42.662c-.155.181-.322.334-.494.46-.167.123-.336.22-.505.29l-.048.02c-.018.008-.032.015-.04.019v.375a.75.75 0 0 1-1.5 0V8.5a.5.5 0 0 0-1 0v6.25a.75.75 0 0 1-1.5 0V8.5a.5.5 0 0 0-1 0v5a.75.75 0 0 1-1.5 0v-5a.5.5 0 0 0-1 0v3.75a.75.75 0 0 1-1.5 0V6a3 3 0 0 1 3-3h2.75a.75.75 0 0 1 .75.75z"/></svg>
                                    <small>Submit a formal request to the admin — assistance, materials, permits, etc.</small>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Request Category</label>
                                    <select class="form-select" name="requestCategory">
                                        <option value="">— Select —</option>
                                        <?php foreach (['Technical Assistance', 'Supplies / Materials', 'Permit / Documentation', 'Financial Support', 'Training / Workshop', 'Other'] as $rc): ?>
                                            <option><?= $rc ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Subject</label>
                                    <input type="text" class="form-control" name="request-subject" placeholder="Brief title of your request">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Details</label>
                                    <textarea class="form-control" name="request-description" rows="4" placeholder="Explain your request in detail..."></textarea>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Urgency</label>
                                    <div class="d-flex gap-3">
                                        <?php foreach (['Not Urgent' => 'success', 'Moderate' => 'warning', 'Urgent' => 'danger'] as $urg => $col): ?>
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" name="urgency" value="<?= $urg ?>" id="urg_<?= $urg ?>">
                                                <label class="form-check-label text-<?= $col ?> fw-semibold" for="urg_<?= $urg ?>"><?= $urg ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Supporting Document (optional)</label>
                                    <input type="file" class="form-control" name="reportAttachment" accept=".pdf,.doc,.docx,image/*">
                                </div>
                            </div>

                            <!-- Others -->
                            <div class="tab-pane fade" id="report-other" role="tabpanel">
                                <div class="alert alert-secondary d-flex align-items-start gap-2 py-2">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-chat-left-text-fill flex-shrink-0 mt-1" viewBox="0 0 16 16"><path d="M0 2a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H4.414a1 1 0 0 0-.707.293L.854 15.146A.5.5 0 0 1 0 14.793zm3.5 1a.5.5 0 0 0 0 1h9a.5.5 0 0 0 0-1zm0 2.5a.5.5 0 0 0 0 1h9a.5.5 0 0 0 0-1zm0 2.5a.5.5 0 0 0 0 1h5a.5.5 0 0 0 0-1z"/></svg>
                                    <small>Anything that doesn't fit the above categories — concerns, observations, queries, etc.</small>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Subject</label>
                                    <input type="text" class="form-control" name="other-subject" placeholder="Short summary">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Message</label>
                                    <textarea class="form-control" name="other-description" rows="5" placeholder="Write your message to the admin here..."></textarea>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Attachment (optional)</label>
                                    <input type="file" class="form-control" name="reportAttachment" accept="image/*,.pdf,.doc,.docx">
                                </div>
                            </div>

                        </div><!-- /.tab-content -->
                        </div><!-- /.modal-body -->
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="submitReport" class="btn btn-danger">
                                <svg
                                    xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="currentColor" class="bi bi-send me-1" viewBox="0 0 16 16"><path d="M15.964.686a.5.5 0 0 0-.65-.65L.767 5.855H.766l-.452.18a.5.5 0 0 0-.082.887l.41.26.001.002 4.995 3.178 3.178 4.995.002.002.26.41a.5.5 0 0 0 .886-.083zm-1.833 1.89L6.637 10.07l-.215-.338a.5.5 0 0 0-.154-.154l-.338-.215 7.494-7.494 1.178-.471z"
                                >
                                </svg>
                                Submit Report
                            </button>
                        </div>
                    </form><!-- /reportForm -->
                </div>
            </div>
        </div>
        <!-- /REPORT MODAL -->

        <!-- Notification Modal -->
        <div class="modal fade" id="notificationModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-scrollable modal-dialog-slideout modal-lg modal-dialog-end">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-bell me-2"></i>Notifications</h5>
                        <div class="d-flex gap-2">
                            <?php if (!empty($notifications)): ?>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="markNotificationsRead" value="1">
                                <button type="submit" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-check2-all me-1"></i>Mark All Read
                                </button>
                            </form>
                            <?php endif; ?>
                            <!-- <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button> -->
                        </div>
                    </div>
                    <div class="modal-body p-0" id="notification-list">
                        <?php if (empty($notifications)): ?>
                        <div class="text-center text-muted py-5">
                            <i class="bi bi-bell-slash fs-1 d-block mb-2 opacity-25"></i>
                            No notifications yet.
                        </div>
                        <?php else: ?>
                        <?php foreach ($notifications as $notif): ?>
                        <div class="p-3 border-bottom <?= !$notif['is_read'] ? 'bg-light' : '' ?>">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="flex-grow-1">
                                    <h6 class="mb-1"><?= htmlspecialchars($notif['title']) ?></h6>
                                    <p class="mb-1 small text-muted"><?= htmlspecialchars($notif['message']) ?></p>
                                    <small class="text-muted"><?= date('M j, Y g:i A', strtotime($notif['created_at'])) ?></small>
                                </div>
                                <?php if (!$notif['is_read']): ?>
                                <span class="badge bg-primary">New</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Profile photo preview + password toggle scripts -->
        <script>
            // Update hidden reportType when tab changes
            document.querySelectorAll('#reportTypeTabs button[data-bs-toggle="pill"]').forEach(btn => {
                btn.addEventListener('shown.bs.tab', function () {
                    const targetMap = {
                        '#report-damage':   'damage',
                        '#report-feedback': 'feedback',
                        '#report-request':  'request',
                        '#report-other':    'other'
                    };
                    const inp = document.getElementById('reportTypeInput');
                    if (inp) inp.value = targetMap[this.dataset.bsTarget] || 'other';
                });
            });

            function previewProfilePhoto(input) {
                if (input.files && input.files[0]) {
                    const reader = new FileReader();
                    reader.onload = e => document.getElementById('profilePreview').src = e.target.result;
                    reader.readAsDataURL(input.files[0]);
                }
            }
            function togglePw(inputId, btn) {
                const inp = document.getElementById(inputId);
                const isText = inp.type === 'text';
                inp.type = isText ? 'password' : 'text';
                btn.querySelector('svg use')
                    ? null // bootstrap-icons SVG, just swap title
                    : null;
                btn.title = isText ? 'Show password' : 'Hide password';
            }
            function validateProfileForm() {
                const pw  = document.getElementById('newPasswordInput').value;
                const pw2 = document.getElementById('confirmPasswordInput').value;
                const msg = document.getElementById('pwMismatch');
                if (pw && pw !== pw2) {
                    msg.style.display = 'block';
                    return false;
                }
                msg.style.display = 'none';
                return true;
            }
        </script>

        <script>
            const IS_GUEST_USER = <?= $isGuest ? 'true' : 'false' ?>;
            if (IS_GUEST_USER) {
                document.addEventListener('DOMContentLoaded', () => {
                    const banner = document.createElement('div');
                    banner.className = 'alert alert-warning text-center mb-0';
                    banner.textContent = 'Guest accounts are read-only. Interactive controls are disabled.';
                    document.body.insertBefore(banner, document.body.firstChild);

                    document.querySelectorAll('form').forEach(form => {
                        form.querySelectorAll('input, select, textarea, button').forEach(control => {
                            if (control.type !== 'button' && control.type !== 'reset' && control.dataset.allowGuest !== 'true') {
                                control.disabled = true;
                            }
                        });
                    });
                });
            }
        </script>
        <script
            src="../assets/dist/js/bootstrap.bundle.min.js"
            class="astro-vvvwv3sm"
        ></script>
        <script
            src="https://cdn.jsdelivr.net/npm/chart.js@4.3.2/dist/chart.umd.js"
            integrity="sha384-eI7PSr3L1XLISH8JdDII5YN/njoSsxfbrkCTnJrzXt+ENP5MOVBxD+l6sEG4zoLp"
            crossorigin="anonymous"
            class="astro-vvvwv3sm"
        ></script>
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

        <script src="dashboard.js" class="astro-vvvwv3sm"></script>
    </body>
</html>
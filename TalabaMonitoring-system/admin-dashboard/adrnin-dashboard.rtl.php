<?php

    include("../DB-connection/connect.php");
    session_start();

    // Prevent access without login
    if (!isset($_SESSION["user_id"])) {
        header("Location: ../sign/sign-in.php");
        exit();
    }

    $admin_id = (int) $_SESSION["user_id"];

    // Role guard — only admins may enter
    $roleCheck = null;
    if (isset($connection) && $connection) {
        $rc = $connection->prepare("SELECT role FROM myUsers WHERE user_ID = ?");
        $rc->bind_param("i", $admin_id);
        $rc->execute();
        $roleCheck = $rc->get_result()->fetch_assoc();
    }
    if (!$roleCheck || $roleCheck["role"] !== "admin") {
        header("Location: ../sign/sign-in.php");
        exit();
    }

    // Show session message
    if (isset($_SESSION['message'])) {
        echo "<script>window.alert('" . addslashes($_SESSION['message']) . "');</script>";
        unset($_SESSION['message']);
    }

    /* ---------------------------------------------------------------
       Admin: Edit farmer profile
       --------------------------------------------------------------- */
    if (isset($_POST["adminEditUser"])) {
        try {
            $uid        = (int)   ($_POST["editUserId"]    ?? 0);
            $firstName  = htmlspecialchars(trim($_POST["firstName"]  ?? ""));
            $lastName   = htmlspecialchars(trim($_POST["lastName"]   ?? ""));
            $middleName = htmlspecialchars(trim($_POST["middleName"] ?? ""));
            $address    = htmlspecialchars(trim($_POST["address"]    ?? ""));
            $email      = htmlspecialchars(trim($_POST["email"]      ?? ""));
            $contactNo  = htmlspecialchars(trim($_POST["contactNo"]  ?? ""));
            $sex        = htmlspecialchars(trim($_POST["sex"]        ?? ""));
            $birthDate  = htmlspecialchars(trim($_POST["birthDate"]  ?? ""));
            $role         = htmlspecialchars(trim($_POST["role"]       ?? "farmer"));
            $status       = htmlspecialchars(trim($_POST["status"]     ?? "offline"));
            $accountLock  = htmlspecialchars(trim($_POST["accountLock"] ?? "UNLOCKED"));
            $newPw        = trim($_POST["newPassword"] ?? "");

            if (!$uid || empty($firstName) || empty($lastName) || empty($email)) {
                echo "<script>window.alert('Required fields missing.');</script>";
            } else {
                $params = [$firstName, $lastName, $middleName, $address, $email, $contactNo, $sex, $birthDate, $role, $status, $accountLock];
                $types  = "sssssssssiss";
                $pwSql  = "";

                if (!empty($newPw)) {
                    $pwSql    = ", password = ?";
                    $params[] = password_hash($newPw, PASSWORD_DEFAULT);
                    $types   .= "s";
                }

                // Optional profile photo upload
                $imgSql = "";
                if (isset($_FILES['profileImage']) && $_FILES['profileImage']['error'] === 0) {
                    $ext = strtolower(pathinfo($_FILES['profileImage']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg','jpeg','png']) && $_FILES['profileImage']['size'] < 5000000) {
                        $dir = "../upload-image/farmer/profiles/" . $uid . "/";
                        if (!is_dir($dir)) mkdir($dir, 0777, true);
                        $fname = uniqid("profile_", true) . "." . $ext;
                        if (move_uploaded_file($_FILES['profileImage']['tmp_name'], $dir . $fname)) {
                            $imgSql   = ", imagePath = ?";
                            $params[] = "farmer/profiles/" . $uid . "/" . $fname;
                            $types   .= "s";
                        }
                    }
                }

                $params[] = $uid;
                $types   .= "i";

                $q = $connection->prepare(
                    "UPDATE myUsers SET firstName=?, lastName=?, middleName=?, address=?,
                     email=?, contactNo=?, sex=?, birthDate=?, role=?, status=?, accountLock=? $pwSql $imgSql
                     WHERE user_ID=?"
                );
                $q->bind_param($types, ...$params);
                if ($q->execute()) {
                    echo "<script>window.alert('User updated successfully.'); window.location.href=window.location.href;</script>";
                } else {
                    echo "<script>window.alert('Update failed: " . $connection->error . "');</script>";
                }
            }
        } catch (mysqli_sql_exception $e) {
            echo "Edit user error: " . $e->getMessage();
        }
    }

    /* ---------------------------------------------------------------
       Admin: Delete farmer
       --------------------------------------------------------------- */
    if (isset($_POST["adminDeleteUser"])) {
        try {
            $uid = (int) ($_POST["deleteUserId"] ?? 0);
            if (!$uid) {
                echo "<script>window.alert('Invalid user ID.');</script>";
            } else {
                $connection->begin_transaction();

                $softDeleteTables = [
                    "oysterFarm",
                    "harvestHistory",
                    "weatherHistory",
                    "farmersReports"
                ];

                foreach ($softDeleteTables as $table) {
                    $q = $connection->prepare("UPDATE $table SET deleted_at = NOW() WHERE user_ID = ?");
                    $q->bind_param("i", $uid);
                    if (!$q->execute()) {
                        throw new Exception("Soft delete failed for $table: " . $connection->error);
                    }
                }

                $connection->query("SET FOREIGN_KEY_CHECKS = 0");
                $q = $connection->prepare("DELETE FROM myUsers WHERE user_ID = ?");
                $q->bind_param("i", $uid);
                if (!$q->execute()) {
                    throw new Exception("Delete failed: " . $connection->error);
                }
                $connection->query("SET FOREIGN_KEY_CHECKS = 1");

                $connection->commit();
                echo "<script>window.alert('Farmer deleted successfully. All related farmer data was marked deleted.'); window.location.href=window.location.href;</script>";
            }
        } catch (Exception $e) {
            if ($connection->errno) {
                $connection->rollback();
            }
            echo "<script>window.alert('Delete failed: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "');</script>";
        }
    }

    /* ---------------------------------------------------------------
       Admin: Add new farmer (register directly from admin dashboard)
       --------------------------------------------------------------- */
    if (isset($_POST["adminAddFarmer"])) {
        try {
            $firstName  = htmlspecialchars(trim($_POST["firstName"]  ?? ""));
            $lastName   = htmlspecialchars(trim($_POST["lastName"]   ?? ""));
            $middleName = htmlspecialchars(trim($_POST["middleName"] ?? ""));
            $address    = htmlspecialchars(trim($_POST["address"]    ?? ""));
            $email      = htmlspecialchars(trim($_POST["email"]      ?? ""));
            $contactNo  = htmlspecialchars(trim($_POST["contactNo"]  ?? ""));
            $sex        = htmlspecialchars(trim($_POST["sex"]        ?? ""));
            $birthDate  = htmlspecialchars(trim($_POST["birthDate"]  ?? ""));
            $rawPw      = trim($_POST["password"] ?? "");

            if (empty($firstName) || empty($lastName) || empty($email) || empty($rawPw)) {
                echo "<script>window.alert('First name, last name, email, and password are required.');</script>";
            } else {
                // Check email not already used
                $chk = $connection->prepare("SELECT user_ID FROM myUsers WHERE email = ?");
                $chk->bind_param("s", $email);
                $chk->execute();
                if ($chk->get_result()->num_rows > 0) {
                    echo "<script>window.alert('A user with that email already exists.');</script>";
                } else {
                    $hashed = password_hash($rawPw, PASSWORD_DEFAULT);
                    $ins = $connection->prepare(
                        "INSERT INTO myUsers (firstName, lastName, middleName, address, email, contactNo, sex, birthDate, password, role, status)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'farmer', 'offline')"
                    );
                    $ins->bind_param("sssssssss", $firstName, $lastName, $middleName, $address, $email, $contactNo, $sex, $birthDate, $hashed);
                    if ($ins->execute()) {
                        echo "<script>window.alert('Farmer registered successfully.'); window.location.href=window.location.href;</script>";
                    } else {
                        echo "<script>window.alert('Registration failed: " . $connection->error . "');</script>";
                    }
                }
            }
        } catch (mysqli_sql_exception $e) {
            echo "Add farmer error: " . $e->getMessage();
        }
    }

    /* ---------------------------------------------------------------
       Admin: Edit own profile
       --------------------------------------------------------------- */
    if (isset($_POST["adminEditSelf"])) {
        try {
            $firstName  = htmlspecialchars(trim($_POST["adminFirstName"]  ?? ""));
            $lastName   = htmlspecialchars(trim($_POST["adminLastName"]   ?? ""));
            $email      = htmlspecialchars(trim($_POST["adminEmail"]      ?? ""));
            $newPw      = trim($_POST["adminNewPassword"] ?? "");

            $params = [$firstName, $lastName, $email];
            $types  = "sss";
            $pwSql  = "";
            $imgSql = "";

            if (!empty($newPw)) {
                $pwSql    = ", password = ?";
                $params[] = password_hash($newPw, PASSWORD_DEFAULT);
                $types   .= "s";
            }

            if (isset($_FILES['adminProfileImage']) && $_FILES['adminProfileImage']['error'] === 0) {
                $ext = strtolower(pathinfo($_FILES['adminProfileImage']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg','jpeg','png']) && $_FILES['adminProfileImage']['size'] < 5000000) {
                    $dir = "../upload-image/admin/profiles/";
                    if (!is_dir($dir)) mkdir($dir, 0777, true);
                    $fname = uniqid("admin_", true) . "." . $ext;
                    if (move_uploaded_file($_FILES['adminProfileImage']['tmp_name'], $dir . $fname)) {
                        $imgSql   = ", imagePath = ?";
                        $params[] = "admin/profiles/" . $fname;
                        $types   .= "s";
                    }
                }
            }

            $params[] = $admin_id;
            $types   .= "i";

            $q = $connection->prepare(
                "UPDATE myUsers SET firstName=?, lastName=?, email=? $pwSql $imgSql WHERE user_ID=?"
            );
            $q->bind_param($types, ...$params);
            if ($q->execute()) {
                // Update session name so navbar reflects change immediately
                $_SESSION["user_fname"] = $firstName . " " . $lastName;
                echo "<script>window.alert('Profile updated.'); window.location.href=window.location.href;</script>";
            } else {
                echo "<script>window.alert('Update failed: " . $connection->error . "');</script>";
            }

        } catch (mysqli_sql_exception $e) {
            echo "Admin self-edit error: " . $e->getMessage();
        }
    }

    /* ---------------------------------------------------------------
       Admin: Update report status (read / unread / resolved)
       --------------------------------------------------------------- */
    if (isset($_POST["markReportStatus"])) {
        try {
            $rID       = (int) ($_POST["reportID"]   ?? 0);
            $newStatus = htmlspecialchars(trim($_POST["newStatus"] ?? "read"));
            $replyMessage = htmlspecialchars(trim($_POST["replyMessage"] ?? ""));
            $allowed   = ['unread', 'read', 'resolved'];
            if ($rID && in_array($newStatus, $allowed)) {
                $q = $connection->prepare("UPDATE farmersReports SET status = ? WHERE report_ID = ?");
                $q->bind_param("si", $newStatus, $rID);
                $q->execute();

                // If resolved, insert weather alert and notification with reply
                if ($newStatus === 'resolved') {
                    $report_q = $connection->prepare("SELECT user_ID, subject FROM farmersReports WHERE report_ID = ?");
                    $report_q->bind_param("i", $rID);
                    $report_q->execute();
                    $report_result = $report_q->get_result();
                    if ($report = $report_result->fetch_assoc()) {
                        $user_id = $report['user_ID'];
                        $subject = $report['subject'];
                        // Get a farm_ID for this user
                        $farm_q = $connection->prepare("SELECT farm_ID FROM oysterFarm WHERE user_ID = ? LIMIT 1");
                        $farm_q->bind_param("i", $user_id);
                        $farm_q->execute();
                        $farm_result = $farm_q->get_result();
                        if ($farm = $farm_result->fetch_assoc()) {
                            $farm_id = $farm['farm_ID'];
                            // Do not insert a weather history record for farmer report resolution.
                            // Weather History should only contain actual weather logs and alerts created from weather events.
                            $notif_q = $connection->prepare("INSERT INTO notifications (user_ID, type, title, message) VALUES (?, 'report_response', 'Report Resolved', ?)");
                            $notif_msg = 'Your report "' . $subject . '" has been resolved by the admin.';
                            if ($replyMessage) {
                                $notif_msg .= ' Admin reply: ' . $replyMessage;
                            }
                            $notif_q->bind_param("is", $user_id, $notif_msg);
                            $notif_q->execute();
                        }
                    }
                }
                $_SESSION['message'] = "Report status updated.";
                if ($newStatus === 'resolved') {
                    $_SESSION['message'] = "Report resolved. Weather alert and notification sent to farmer.";
                }
            }
            // Redirect back to avoid re-POST on refresh
            header("Location: " . $_SERVER['PHP_SELF'] . "#reports-section");
            exit();
        } catch (mysqli_sql_exception $e) {
            echo "Mark report error: " . $e->getMessage();
        }
    }

    /* ---------------------------------------------------------------
       Fetch summary data
       --------------------------------------------------------------- */
    $allUsers    = [];
    $allFarms    = [];
    $allHarvest  = [];
    $allWeather  = [];
    $allReports  = [];
    $stats       = [
        'total_farmers'   => 0,
        'total_farms'     => 0,
        'total_harvest'   => 0,
        'total_weather'   => 0,
        'online_farmers'  => 0,
        'unread_reports'  => 0,
    ];

    try {
        if (!isset($connection) || !$connection)  throw new Exception("Database connection failed");

        // All farmers and guest users
        $u = $connection->query("SELECT * FROM myUsers WHERE role IN ('farmer','guest') ORDER BY user_ID DESC");
        if (!$u) throw new Exception("Failed to fetch users: " . $connection->error);
        while ($r = $u->fetch_assoc()) $allUsers[] = $r;

        // All farms (include deleted rows for admin filtering)
        $f = $connection->query("SELECT * FROM oysterFarm ORDER BY farm_ID DESC");
        if (!$f) throw new Exception("Failed to fetch farms: " . $connection->error);
        while ($r = $f->fetch_assoc()) $allFarms[] = $r;

        // All harvest (include deleted rows for admin filtering)
        $h = $connection->query("SELECT * FROM harvestHistory ORDER BY harvestDate DESC");
        if (!$h) throw new Exception("Failed to fetch harvest: " . $connection->error);
        while ($r = $h->fetch_assoc()) $allHarvest[] = $r;

        // All reports — joined with farmer name for display (include deleted rows)
        $rq = $connection->query("
            SELECT r.*,
                   u.firstName AS farmer_firstName,
                   u.lastName  AS farmer_lastName
            FROM farmersReports r
            LEFT JOIN myUsers u ON u.user_ID = r.user_ID
            ORDER BY r.submitted_image DESC
        ");
        if (!$rq) throw new Exception("Failed to fetch reports: " . $connection->error);
        while ($r = $rq->fetch_assoc()) $allReports[] = $r;

        // All weather logs, excluding old invalid report-resolution alerts
        $w = $connection->query("SELECT * FROM weatherHistory WHERE NOT (naturalDisaster = 'Alert' AND (note LIKE 'Admin response to report:%' OR note LIKE 'Weather alert created after resolving a farmer report%')) ORDER BY recordDate DESC, recordTime DESC");
        if (!$w) throw new Exception("Failed to fetch weather history: " . $connection->error);
        while ($r = $w->fetch_assoc()) $allWeather[] = $r;

        $activeFarms    = array_filter($allFarms, fn($f) => empty($f['deleted_at']));
        $activeHarvest  = array_filter($allHarvest, fn($h) => empty($h['deleted_at']));
        $activeWeather  = array_filter($allWeather, fn($w) => empty($w['deleted_at']));
        $activeReports  = array_filter($allReports, fn($r) => empty($r['deleted_at']));

        // Stats
        $stats['total_farmers']  = count($allUsers);
        $stats['total_farms']    = count($activeFarms);
        $stats['total_harvest']  = array_sum(array_column($activeHarvest, 'quantity'));
        $stats['total_weather']  = count($activeWeather);
        $stats['online_farmers'] = count(array_filter($allUsers, fn($u) => ($u['status'] ?? '') === 'online'));
        $stats['unread_reports'] = count(array_filter($activeReports, fn($r) => ($r['status'] ?? '') === 'unread'));

    } catch (Exception $e) {
        echo "<script>window.alert('Error: " . htmlspecialchars($e->getMessage()) . "');</script>";
        error_log($e->getMessage());
    }

        /* ---------------------------------------------------------------
       Harvest chart data for JS
       --------------------------------------------------------------- */
    $harvestChartData = [];
    foreach ($allHarvest as $row) {
        $farmLabel = 'Farm #' . $row['farm_ID'];
        foreach ($allFarms as $f) {
            if ((int)$f['farm_ID'] === (int)$row['farm_ID']) {
                $farmLabel = $f['farmName_number'];
                break;
            }
        }
        $harvestChartData[] = [
            'farm_id'     => (int)$row['farm_ID'],
            'farm_name'   => $farmLabel,
            'harvestDate' => $row['harvestDate'],
            'quantity'    => (float)$row['quantity'],
        ];
    }

    // Fetch admin info
    $adminInfo = [];
    $ai = $connection->query("SELECT * FROM myUsers WHERE user_ID = $admin_id");
    if ($ai) $adminInfo = $ai->fetch_assoc() ?? [];

?>
<!doctype html>
<html lang="en" data-bs-theme="auto">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <title>Admin Dashboard — Talaba Farm</title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
        <link href="../assets/dist/css/bootstrap.min.css" rel="stylesheet" />
        <link href="admin-dashboard.css" rel="stylesheet" />
        <link href="dashboard-ocean-theme.css" rel="stylesheet" />
        <script>
            const HARVEST_DATA = <?= json_encode($harvestChartData, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
            const ADMIN_STATS  = <?= json_encode($stats,            JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        </script>
        <script src="../assets/js/color-modes.js"></script>
        <style>
            /* ── Admin-specific overrides ─────────────────────── */
            :root {
                --adm-teal:   #00bcd4;
                --adm-deep:   #007c91;
                --adm-sky:    #e0f7fa;
                --adm-card:   #fff;
                --adm-border: #b2ebf2;
            }

            /* Stat cards */
            .stat-card {
                border-radius: 20px;
                padding: 1.5rem 1.75rem;
                color: #fff;
                position: relative;
                overflow: hidden;
                box-shadow: 0 8px 28px rgba(0,0,0,0.13);
                transition: transform .2s, box-shadow .2s;
            }
            .stat-card:hover { transform: translateY(-4px); box-shadow: 0 14px 40px rgba(0,0,0,0.18); }
            .stat-card .stat-icon {
                position: absolute; right: 1.5rem; top: 50%;
                transform: translateY(-50%);
                opacity: .18; font-size: 5rem; line-height: 1;
            }
            .stat-card .stat-value { font-size: 2.4rem; font-weight: 800; line-height: 1; }
            .stat-card .stat-label { font-size: .85rem; font-weight: 600; opacity: .85; margin-top: .3rem; }
            .stat-card .stat-sub   { font-size: .75rem; opacity: .7; margin-top: .15rem; }

            .stat-farmers  { background: linear-gradient(135deg, #1ab3ef, #00c2d4); }
            .stat-farms    { background: linear-gradient(135deg, #43a047, #66bb6a); }
            .stat-harvest  { background: linear-gradient(135deg, #f5a623, #fb8c00); }
            .stat-online   { background: linear-gradient(135deg, #7e57c2, #5c35a8); }

            /* Section header */
            .section-card { border-radius: 20px !important; overflow: hidden; border: none !important; }
            .section-head {
                padding: .9rem 1.4rem;
                color: #fff;
                font-family: 'Poppins', sans-serif;
                font-weight: 700;
                font-size: 1rem;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: .75rem;
            }
            .section-head.teal { background: linear-gradient(90deg, #00bcd4, #1ab3ef); }
            .section-head.green{ background: linear-gradient(90deg, #43a047, #66bb6a); }
            .section-head.orange{background: linear-gradient(90deg, #f5a623, #fb8c00); }
            .section-head.purple{background: linear-gradient(90deg, #7e57c2, #5c35a8); }
            .section-head.red  { background: linear-gradient(90deg, #e53935, #f44336); }
            .section-head.violet{ background: linear-gradient(90deg, #7c4dff, #ba68c8); }

            /* Farmer cards in the user list */
            .farmer-card {
                background: #fff;
                border: 1.5px solid #d0e8f5;
                border-radius: 16px;
                margin-bottom: .9rem;
                overflow: hidden;
                transition: box-shadow .2s, transform .2s;
            }
            .farmer-card:hover { box-shadow: 0 6px 24px rgba(26,179,239,.18); transform: translateY(-2px); }
            .farmer-card-head {
                background: #f0fbff;
                padding: .6rem 1rem;
                display: flex;
                align-items: center;
                gap: .75rem;
                border-bottom: 1px solid #d0e8f5;
            }
            .farmer-avatar {
                width: 42px; height: 42px;
                border-radius: 50%;
                object-fit: cover;
                border: 2px solid #1ab3ef;
                flex-shrink: 0;
            }
            .farmer-card-name { font-weight: 700; font-size: .95rem; color: #1a3a4f; }
            .farmer-card-email{ font-size: .78rem; color: #6b8fa3; }
            .farmer-card-body {
                padding: .65rem 1rem;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 1rem;
                flex-wrap: wrap;
                background: #fcfeff;
            }
            .farmer-meta { display: flex; flex-wrap: wrap; gap: .3rem .9rem; font-size: .8rem; color: #2d6080; }
            .farmer-meta span { display: flex; align-items: center; gap: .25rem; }
            .farmer-actions { display: flex; gap: .4rem; flex-shrink: 0; }

            /* Status dot */
            .status-dot {
                width: 9px; height: 9px; border-radius: 50%;
                display: inline-block; flex-shrink: 0;
            }
            .dot-online  { background: #28a745; box-shadow: 0 0 0 3px rgba(40,167,69,.2); }
            .dot-offline { background: #adb5bd; }

            /* Farm mini cards in the farm list */
            .farm-row {
                display: flex;
                align-items: center;
                gap: .75rem;
                padding: .6rem .85rem;
                border-bottom: 1px solid #e8f6fd;
                font-size: .83rem;
                transition: background .15s;
            }
            .farm-row:last-child { border-bottom: none; }
            .farm-row:hover { background: #f0fbff; }
            .farm-row-thumb {
                width: 44px; height: 44px;
                border-radius: 10px;
                object-fit: cover;
                flex-shrink: 0;
                border: 1.5px solid #d0e8f5;
            }
            .farm-row-name { font-weight: 700; color: #1a3a4f; font-size: .88rem; }
            .farm-row-meta { color: #6b8fa3; font-size: .75rem; }
            .farm-row-owner{ margin-left: auto; font-size: .75rem; color: #2d6080; flex-shrink: 0; }

            /* Harvest table */
            .harvest-table thead th {
                background: #e0f7fa;
                color: #007c91;
                font-size: .75rem;
                text-transform: uppercase;
                letter-spacing: .06em;
                font-weight: 700;
                border: none;
            }
            .harvest-table tbody td { font-size: .83rem; color: #2d6080; vertical-align: middle; }
            .harvest-table tbody tr:hover { background: #f0fbff; }

            /* Reports inbox */
            .report-item {
                display: flex;
                align-items: flex-start;
                gap: .75rem;
                padding: .75rem 1rem;
                border-bottom: 1px solid #e8f6fd;
                cursor: pointer;
                transition: background .15s;
            }
            .report-item:hover { background: #f0fbff; }
            .report-item:last-child { border-bottom: none; }
            .report-badge-type {
                font-size: .68rem;
                font-weight: 700;
                padding: .18rem .55rem;
                border-radius: 50px;
                flex-shrink: 0;
                margin-top: .15rem;
            }
            .rt-damage   { background: #fde8e8; color: #c0392b; }
            .rt-feedback { background: #e8f4fd; color: #1565c0; }
            .rt-request  { background: #fff8e1; color: #b45309; }
            .rt-other    { background: #f3e8ff; color: #6d28d9; }
            .report-title { font-weight: 700; font-size: .87rem; color: #1a3a4f; }
            .report-meta  { font-size: .75rem; color: #6b8fa3; margin-top: .1rem; }
            .report-unread { background: #f0fbff; }
            .report-unread .report-title::before {
                content: '';
                display: inline-block;
                width: 7px; height: 7px;
                background: #1ab3ef;
                border-radius: 50%;
                margin-right: .4rem;
                vertical-align: middle;
            }

            .section-scrollable {
                max-height: 520px;
                overflow-y: auto;
                overflow-x: hidden;
            }

            /* Chart area */
            .chart { position: relative; height: 340px; width: 100%; }

            /* Admin profile card in sidebar */
            .admin-profile-box {
                background: linear-gradient(135deg, #1ab3ef22, #00c2d422);
                border: 1.5px solid #b2ebf2;
                border-radius: 16px;
                padding: .9rem 1rem;
                margin: .75rem .5rem 1rem;
                text-align: center;
            }
            .admin-avatar {
                width: 56px; height: 56px;
                border-radius: 50%;
                object-fit: cover;
                border: 3px solid #1ab3ef;
                margin-bottom: .4rem;
            }
            .admin-name { font-weight: 700; font-size: .92rem; color: #1a3a4f; }
            .admin-role-badge {
                display: inline-block;
                font-size: .68rem;
                font-weight: 700;
                padding: .15rem .55rem;
                border-radius: 50px;
                background: linear-gradient(90deg, #1ab3ef, #00c2d4);
                color: #fff;
                margin-top: .2rem;
            }

            /* Quick action buttons */
            .quick-btn {
                display: flex;
                align-items: center;
                gap: .5rem;
                border-radius: 12px;
                padding: .55rem .9rem;
                font-size: .82rem;
                font-weight: 700;
                border: none;
                color: #fff;
                cursor: pointer;
                transition: filter .2s, transform .15s;
            }
            .quick-btn:hover { filter: brightness(1.08); transform: translateY(-1px); }
            .qb-teal   { background: linear-gradient(90deg, #00bcd4, #1ab3ef); }
            .qb-green  { background: linear-gradient(90deg, #43a047, #66bb6a); }
            .qb-orange { background: linear-gradient(90deg, #f5a623, #fb8c00); }
            .qb-red    { background: linear-gradient(90deg, #e53935, #f44336); }

            /* View farmer dashboard link */
            .view-farmer-link {
                font-size: .75rem;
                color: #1ab3ef;
                text-decoration: none;
                font-weight: 600;
            }
            .view-farmer-link:hover { text-decoration: underline; }

            /* Responsive tweaks */
            @media (min-width: 768px) {
                .sidebar {
                    position: fixed; top: 60px; left: 0;
                    height: calc(100vh - 60px);
                    overflow-y: auto; z-index: 100; width: 240px;
                }
                main { margin-left: 240px; }
            }
            @media (max-width: 767px) {
                .stat-card { margin-bottom: .75rem; }
            }
        </style>
    </head>
    <body>

    <!-- ================================================================
        NAVBAR
        ================================================================ -->
    <header class="navbar sticky-top bg-dark flex-md-nowrap p-0 shadow" data-bs-theme="dark">
        <a class="navbar-brand col-md-3 col-lg-2 me-0 px-3 fs-6 text-white" href="#">
            🦪 Talaba Farm — Admin
        </a>

        <ul class="navbar-nav flex-row d-md-none">
            <li class="nav-item text-nowrap">
                <button class="nav-link px-3 text-white" type="button"
                        data-bs-toggle="offcanvas" data-bs-target="#sidebarMenu"
                        aria-controls="sidebarMenu">
                    <i class="bi bi-list fs-5"></i>
                </button>
            </li>
        </ul>

        <!-- Navbar right side -->
        <div class="d-flex align-items-center gap-3 ms-auto pe-3">

            <!-- Global search -->
            <div class="input-group input-group-sm" style="width:220px;">
                <span class="input-group-text bg-transparent border-secondary">
                    <i class="bi bi-search text-secondary"></i>
                </span>
                <input id="globalSearch" type="text" class="form-control bg-transparent border-secondary text-white"
                    placeholder="Search farmers, farms…" style="font-size:.82rem;">
            </div>

            <!-- Notifications -->
            <a href="#reports-section" class="text-white position-relative" title="Reports Inbox" onclick="document.getElementById('reports-section').scrollIntoView({behavior:'smooth'});return false;">
                <i class="bi bi-bell fs-5"></i>
                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:.6rem;">
                    <?= $stats['unread_reports'] ?: '' ?>
                </span>
            </a>

            <!-- Admin avatar -->
            <a href="#" data-bs-toggle="modal" data-bs-target="#adminProfileModal" title="My Profile">
                <img
                    src="<?= !empty($adminInfo['imagePath'])
                        ? '../upload-image/' . htmlspecialchars($adminInfo['imagePath'])
                        : '' ?>"
                    onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode(($adminInfo['firstName'] ?? 'A') . '+' . ($adminInfo['lastName'] ?? '')) ?>&background=1ab3ef&color=fff&size=40'"
                    alt="Admin"
                    class="rounded-circle border border-2 border-light"
                    style="width:36px;height:36px;object-fit:cover;cursor:pointer;"
                >
            </a>
        </div>
    </header>

    <!-- ================================================================
        LAYOUT WRAPPER
        ================================================================ -->
    <div class="container-fluid">
    <div class="row">

    <!-- ================================================================
        SIDEBAR
        ================================================================ -->
    <nav class="sidebar border-end col-md-3 col-lg-2 p-0 bg-body-tertiary">
        <div class="offcanvas-md offcanvas-end bg-body-tertiary" tabindex="-1"
            id="sidebarMenu" aria-labelledby="sidebarMenuLabel">

            <div class="offcanvas-header">
                <h5 class="offcanvas-title" id="sidebarMenuLabel">Admin Menu</h5>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas"
                        data-bs-target="#sidebarMenu" aria-label="Close"></button>
            </div>

            <div class="offcanvas-body d-md-flex flex-column p-0 pt-lg-3 overflow-y-auto">

                <!-- Admin profile box -->
                <div class="admin-profile-box">
                    <img
                        src="<?= !empty($adminInfo['imagePath'])
                            ? '../upload-image/' . htmlspecialchars($adminInfo['imagePath'])
                            : '' ?>"
                        onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode(($adminInfo['firstName'] ?? 'Admin') . '+' . ($adminInfo['lastName'] ?? '')) ?>&background=1ab3ef&color=fff&size=56'"
                        class="admin-avatar"
                        alt="Admin Photo"
                    >
                    <div class="admin-name"><?= htmlspecialchars(($adminInfo['firstName'] ?? '') . ' ' . ($adminInfo['lastName'] ?? '')) ?></div>
                    <div><span class="admin-role-badge"><i class="bi bi-shield-check me-1"></i>Administrator</span></div>
                </div>

                <!-- Nav links -->
                <ul class="nav flex-column px-2">
                    <li class="nav-item">
                        <a class="nav-link d-flex align-items-center gap-2 active" href="#overview">
                            <i class="bi bi-speedometer2"></i> Overview
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link d-flex align-items-center gap-2 indent" href="#farmers">
                            <i class="bi bi-people"></i> Farmers
                            <span class="badge rounded-pill ms-auto" style="background:#1ab3ef;font-size:.68rem;"><?= $stats['total_farmers'] ?></span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link d-flex align-items-center gap-2 indent" href="#farms-section">
                            <i class="bi bi-layers-half"></i> Farms
                            <span class="badge rounded-pill ms-auto" style="background:#43a047;font-size:.68rem;"><?= $stats['total_farms'] ?></span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link d-flex align-items-center gap-2 indent" href="#harvest-section">
                            <i class="bi bi-graph-up"></i> Harvest Records
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link d-flex align-items-center gap-2 indent" href="#reports-section">
                            <i class="bi bi-envelope"></i> Reports Inbox
                            <?php if ($stats['unread_reports'] > 0): ?>
                            <span class="badge rounded-pill ms-auto bg-danger" style="font-size:.68rem;"><?= $stats['unread_reports'] ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link d-flex align-items-center gap-2 indent" href="#chart-section">
                            <i class="bi bi-bar-chart-line"></i> Charts
                        </a>
                    </li>
                </ul>

                <hr class="my-2 mx-3" />

                <ul class="nav flex-column px-2 mb-auto">
                    <li class="nav-item">
                        <a class="nav-link d-flex align-items-center gap-2"
                        href="#" data-bs-toggle="modal" data-bs-target="#adminProfileModal">
                            <i class="bi bi-person-gear"></i> My Profile
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link d-flex align-items-center gap-2" href="../sign/sign-out.php">
                            <i class="bi bi-door-closed"></i> Sign Out
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- ================================================================
        MAIN CONTENT
        ================================================================ -->
    <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">

        <!-- ── Page title ───────────────────────────────────────────── -->
        <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2" id="overview">
            <div>
                <h4 class="mb-0 fw-bold" style="font-family:'Poppins',sans-serif;">
                    👋 Welcome back, <?= htmlspecialchars($adminInfo['firstName'] ?? 'Admin') ?>
                </h4>
                <small class="text-muted">
                    <i class="bi bi-calendar3 me-1"></i><?= date('l, F j Y') ?>
                </small>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <button class="quick-btn qb-teal" data-bs-toggle="modal" data-bs-target="#addFarmerModal">
                    <i class="bi bi-person-plus"></i> Add Farmer
                </button>
                <button class="quick-btn qb-green" onclick="window.location.reload()">
                    <i class="bi bi-arrow-clockwise"></i> Refresh
                </button>
            </div>
        </div>

        <!-- ── Stat cards ────────────────────────────────────────────── -->
        <div class="row g-3 mb-4">
            <div class="col-sm-6 col-xl-3">
                <div class="stat-card stat-farmers">
                    <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
                    <div class="stat-value"><?= $stats['total_farmers'] ?></div>
                    <div class="stat-label">Total Farmers</div>
                    <div class="stat-sub"><?= $stats['online_farmers'] ?> online now</div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="stat-card stat-farms">
                    <div class="stat-icon"><i class="bi bi-layers-fill"></i></div>
                    <div class="stat-value"><?= $stats['total_farms'] ?></div>
                    <div class="stat-label">Registered Farms</div>
                    <div class="stat-sub">Across all farmers</div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="stat-card stat-harvest">
                    <div class="stat-icon"><i class="bi bi-basket3-fill"></i></div>
                    <div class="stat-value"><?= number_format($stats['total_harvest'], 1) ?></div>
                    <div class="stat-label">Total Harvest (kg)</div>
                    <div class="stat-sub"><?= count($allHarvest) ?> recorded events</div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="stat-card stat-online">
                    <div class="stat-icon"><i class="bi bi-wifi"></i></div>
                    <div class="stat-value"><?= $stats['online_farmers'] ?></div>
                    <div class="stat-label">Online Farmers</div>
                    <div class="stat-sub">Active right now</div>
                </div>
            </div>
        </div>

        <!-- ── Harvest chart ─────────────────────────────────────────── -->
        <div class="card section-card mb-4" id="chart-section">
            <div class="section-head green">
                <span><i class="bi bi-graph-up-arrow me-2"></i>Harvest Overview</span>
                <div class="d-flex gap-2">
                    <div class="btn-group btn-group-sm" id="adminChartGroup">
                        <button class="btn btn-light btn-sm active" data-group="month">Monthly</button>
                        <button class="btn btn-light btn-sm" data-group="quarter">Quarterly</button>
                        <button class="btn btn-light btn-sm" data-group="year">Yearly</button>
                    </div>
                    <div class="btn-group btn-group-sm" id="adminChartType">
                        <button class="btn btn-light btn-sm active" data-type="bar">
                            <i class="bi bi-bar-chart-fill"></i> Bar
                        </button>
                        <button class="btn btn-light btn-sm" data-type="line">
                            <i class="bi bi-graph-up"></i> Line
                        </button>
                    </div>
                </div>
            </div>
            <div class="card-body p-3">
                <div class="chart">
                    <canvas id="adminHarvestChart"></canvas>
                </div>
                <?php if (empty($allHarvest)): ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-basket fs-1 d-block mb-2 opacity-25"></i>
                    No harvest records yet.
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── Two-column layout: Farmers + Farms ──────────────────── -->
        <div class="row g-3 mb-4">

            <!-- Farmers list -->
            <div class="col-lg-7" id="farmers">
                <div class="card section-card h-100">
                    <div class="section-head teal">
                        <span><i class="bi bi-people me-2"></i>Farmers
                            <span class="badge bg-white text-dark ms-2" style="font-size:.72rem;font-weight:700;"><?= $stats['total_farmers'] ?></span>
                        </span>
                        <div class="d-flex gap-2 align-items-center">
                            <input id="farmerSearch" type="text" class="form-control form-control-sm"
                                placeholder="Filter…" style="width:130px;border-radius:50px;font-size:.78rem;">
                            <div class="btn-group btn-group-sm" id="farmerTabBtns">
                                <button class="btn btn-light active" data-filter="all">All</button>
                                <button class="btn btn-light" data-filter="online">Online</button>
                                <button class="btn btn-light" data-filter="offline">Offline</button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-0" style="max-height:520px;overflow-y:auto;" id="farmerListBody">
                        <?php if (empty($allUsers)): ?>
                        <div class="text-center text-muted py-5">
                            <i class="bi bi-person-x fs-1 d-block mb-2 opacity-25"></i>
                            No farmers registered yet.
                        </div>
                        <?php else: ?>
                        <?php foreach ($allUsers as $u): ?>
                        <?php
                            $isOnline  = ($u['status'] ?? '') === 'online';
                            $farmerFarms = array_filter($allFarms, fn($f) => (int)$f['user_ID'] === (int)$u['user_ID']);
                            $fullName  = htmlspecialchars(($u['firstName'] ?? '') . ' ' . ($u['lastName'] ?? ''));
                            $avatarUrl = !empty($u['imagePath'])
                                ? '../upload-image/' . htmlspecialchars($u['imagePath'])
                                : '';
                            $avatarFb  = 'https://ui-avatars.com/api/?name=' . urlencode($fullName) . '&background=1ab3ef&color=fff&size=42';
                        ?>
                        <div class="farmer-card"
                            data-status="<?= $isOnline ? 'online' : 'offline' ?>"
                            data-name="<?= strtolower($fullName) ?>">
                            <div class="farmer-card-head">
                                <img src="<?= $avatarUrl ?>" onerror="this.src='<?= $avatarFb ?>'"
                                    class="farmer-avatar" alt="<?= $fullName ?>">
                                <div class="flex-grow-1 overflow-hidden">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="status-dot <?= $isOnline ? 'dot-online' : 'dot-offline' ?>"></span>
                                        <span class="farmer-card-name"><?= $fullName ?></span>
                                        <span class="badge bg-secondary" style="font-size:.63rem;">#<?= $u['user_ID'] ?></span>
                                        <span class="badge <?= (($u['accountLock'] ?? 'UNLOCKED') === 'LOCKED') ? 'bg-danger' : 'bg-success' ?>" style="font-size:.63rem;"><i class="bi <?= (($u['accountLock'] ?? 'UNLOCKED') === 'LOCKED') ? 'bi-lock' : 'bi-unlock' ?>" style="margin-right:3px;"></i><?= htmlspecialchars($u['accountLock'] ?? 'UNLOCKED') ?></span>
                                    </div>
                                    <div class="farmer-card-email"><?= htmlspecialchars($u['email'] ?? '') ?></div>
                                </div>
                                <div class="d-flex gap-1 flex-shrink-0 ms-auto">
                                
                                        <!-- allows the admin to view the farmers dashboard -->
                                    <a class="view-farmer-link"
                                        href="../farm-dashboard/farm.dashboard.rtl.php?user_id=<?= $u['user_ID'] ?>"
                                        target="_blank" title="View farmer dashboard"
                                    >
                                        <i class="bi bi-box-arrow-up-right">view dashboard</i>
                                    </a>
                                    <button class="btn btn-sm py-0 px-2"
                                            style="background:#e8c200;color:#333;border-radius:8px;font-size:.75rem;font-weight:700;"
                                            onclick="openEditFarmer(<?= htmlspecialchars(json_encode($u)) ?>)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-sm py-0 px-2 btn-danger"
                                            style="border-radius:8px;font-size:.75rem;font-weight:700;"
                                            onclick="confirmDeleteFarmer(<?= $u['user_ID'] ?>, '<?= addslashes($fullName) ?>')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="farmer-card-body">
                                <div class="farmer-meta">
                                    <span><i class="bi bi-geo-alt text-primary"></i><?= htmlspecialchars($u['address'] ?? '—') ?></span>
                                    <span><i class="bi bi-telephone text-primary"></i><?= htmlspecialchars($u['contactNo'] ?? '—') ?></span>
                                    <span><i class="bi bi-layers text-success"></i><?= count($farmerFarms) ?> farm<?= count($farmerFarms) !== 1 ? 's' : '' ?></span>
                                    <span><i class="bi bi-gender-ambiguous text-info"></i><?= htmlspecialchars($u['sex'] ?? '—') ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Farms list -->
            <div class="col-lg-5" id="farms-section">
                <div class="card section-card h-100">
                    <div class="section-head green">
                        <span><i class="bi bi-layers me-2"></i>All Farms</span>
                        <span class="badge bg-white text-dark" style="font-size:.72rem;font-weight:700;"><?= $stats['total_farms'] ?></span>
                    </div>
                    <div class="px-3 pb-2 d-flex align-items-center gap-2">
                        <span class="text-muted" style="font-size:.82rem;">Show:</span>
                        <div class="btn-group btn-group-sm" id="farmDeletedFilter">
                            <button class="btn btn-outline-secondary active" data-deleted="active">Active</button>
                            <button class="btn btn-outline-secondary" data-deleted="deleted">Deleted</button>
                            <button class="btn btn-outline-secondary" data-deleted="all">All</button>
                        </div>
                    </div>
                    <div class="card-body p-0" style="max-height:520px;overflow-y:auto;">
                        <?php if (empty($allFarms)): ?>
                        <div class="text-center text-muted py-5">
                            <i class="bi bi-layer-backward fs-1 d-block mb-2 opacity-25"></i>
                            No farms registered yet.
                        </div>
                        <?php else: ?>
                        <?php foreach ($allFarms as $farm): ?>
                        <?php
                            $farmThumb = !empty($farm['imagePath'])
                                ? '../upload-image/' . htmlspecialchars($farm['imagePath'])
                                : 'https://placehold.co/44x44/cce9f8/1ab3ef?text=🌊';
                            $owner = null;
                            foreach ($allUsers as $u) {
                                if ((int)$u['user_ID'] === (int)$farm['user_ID']) { $owner = $u; break; }
                            }
                            $ownerName = $owner ? htmlspecialchars(($owner['firstName'] ?? '') . ' ' . ($owner['lastName'] ?? '')) : '—';
                        ?>
                        <div class="farm-row" data-deleted="<?= !empty($farm['deleted_at']) ? '1' : '0' ?>">
                            <img src="<?= $farmThumb ?>"
                                onerror="this.src='https://placehold.co/44x44/cce9f8/1ab3ef?text=F'"
                                class="farm-row-thumb" alt="">
                            <div class="flex-grow-1 overflow-hidden">
                                <div class="farm-row-name"><?= htmlspecialchars($farm['farmName_number'] ?? 'Farm') ?></div>
                                <div class="farm-row-meta">
                                    <i class="bi bi-geo-alt"></i> <?= htmlspecialchars($farm['location'] ?? '—') ?>
                                    &nbsp;·&nbsp;
                                    <i class="bi bi-rulers"></i> <?= htmlspecialchars($farm['surfaceArea'] ?? '—') ?> m²
                                </div>
                            </div>
                            <div class="farm-row-owner">
                                <i class="bi bi-person-fill text-primary"></i> <?= $ownerName ?>
                                <?php if (!empty($farm['deleted_at'])): ?>
                                <span class="badge bg-danger ms-2" style="font-size:.65rem;">Deleted</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Harvest Records table ─────────────────────────────────── -->
        <div class="card section-card mb-4" id="harvest-section">
            <div class="section-head orange">
                <span><i class="bi bi-basket me-2"></i>Harvest Records</span>
                <span class="badge bg-white text-dark" style="font-size:.72rem;font-weight:700;"><?= count($activeHarvest) ?> records</span>
                <div class="btn-group btn-group-sm ms-3" id="harvestDeletedFilter">
                    <button class="btn btn-outline-secondary active" data-deleted="active">Active</button>
                    <button class="btn btn-outline-secondary" data-deleted="deleted">Deleted</button>
                    <button class="btn btn-outline-secondary" data-deleted="all">All</button>
                </div>
            </div>
            <div class="card-body p-0">
                <div style="overflow-x:auto;max-height:380px;overflow-y:auto;">
                <table class="table table-hover harvest-table mb-0">
                    <thead class="sticky-top">
                        <tr>
                            <th>#</th>
                            <th>Farm</th>
                            <th>Farmer</th>
                            <th>Date</th>
                            <th>Quantity (kg)</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($allHarvest)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">No harvest records.</td></tr>
                    <?php else: ?>
                    <?php foreach ($allHarvest as $i => $hr): ?>
                    <?php
                        $hFarm  = null;
                        foreach ($allFarms as $f) { if ((int)$f['farm_ID'] === (int)$hr['farm_ID']) { $hFarm = $f; break; } }
                        $hUser  = null;
                        foreach ($allUsers as $u) { if ((int)$u['user_ID'] === (int)$hr['user_ID']) { $hUser = $u; break; } }
                    ?>
                    <tr data-deleted="<?= !empty($hr['deleted_at']) ? '1' : '0' ?>">
                        <td class="text-muted"><?= $i + 1 ?></td>
                        <td><?= htmlspecialchars($hFarm['farmName_number'] ?? 'Farm #' . $hr['farm_ID']) ?></td>
                        <td><?= htmlspecialchars(($hUser['firstName'] ?? '') . ' ' . ($hUser['lastName'] ?? '—')) ?></td>
                        <td><?= htmlspecialchars($hr['harvestDate'] ?? '—') ?></td>
                        <td>
                            <span class="badge" style="background:linear-gradient(90deg,#43a047,#66bb6a);border-radius:50px;padding:.3em .75em;">
                                <?= number_format((float)$hr['quantity'], 2) ?> kg
                            </span>
                            <?php if (!empty($hr['deleted_at'])): ?>
                            <span class="badge bg-danger ms-2" style="font-size:.68rem;">Deleted</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>

        <!-- ── Weather History ───────────────────────────────────────── -->
        <div class="card section-card mb-4" id="weather-section">
            <div class="section-head violet">
                <span><i class="bi bi-cloud-sun-rain me-2"></i>Weather History</span>
                <span class="badge bg-white text-dark" style="font-size:.72rem;font-weight:700;">
                    <?= count($activeWeather) ?> record<?= count($activeWeather) !== 1 ? 's' : '' ?>
                </span>
            </div>
            <div class="card-body p-0">
                <div style="overflow-x:auto;max-height:420px;overflow-y:auto;">
                    <table class="table table-hover weather-table mb-0">
                        <thead class="sticky-top">
                            <tr>
                                <th>#</th>
                                <th>Farm</th>
                                <th>Date</th>
                                <th>Time</th>
                                <th>Tide</th>
                                <th>Temp</th>
                                <th>Disaster</th>
                                <th>Note</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($activeWeather)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">No weather history records.</td></tr>
                        <?php else: ?>
                        <?php foreach ($activeWeather as $i => $weather): ?>
                        <?php
                            $weatherFarm = null;
                            foreach ($allFarms as $f) {
                                if ((int)$f['farm_ID'] === (int)$weather['farm_ID']) { $weatherFarm = $f; break; }
                            }
                            $weatherFarmName = $weatherFarm ? htmlspecialchars($weatherFarm['farmName_number']) : 'Farm #' . htmlspecialchars($weather['farm_ID']);
                        ?>
                        <tr>
                            <td class="text-muted"><?= $i + 1 ?></td>
                            <td><?= $weatherFarmName ?></td>
                            <td><?= htmlspecialchars($weather['recordDate'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($weather['recordTime'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($weather['tideType'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($weather['waterTempreture'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($weather['naturalDisaster'] ?? $weather['naturalDisater'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($weather['note'] ?? '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ── Reports Inbox ─────────────────────────────────────────── -->
        <div class="card section-card mb-4" id="reports-section">
            <div class="section-head red">
                <span><i class="bi bi-inbox me-2"></i>Reports Inbox</span>
                <span class="badge bg-white text-dark" style="font-size:.72rem;font-weight:700;">
                    <?= count($activeReports) ?> report<?= count($activeReports) !== 1 ? 's' : '' ?>
                    <?php if ($stats['unread_reports'] > 0): ?>
                    &nbsp;·&nbsp; <span class="text-danger fw-bold"><?= $stats['unread_reports'] ?> unread</span>
                    <?php endif; ?>
                </span>
            </div>

            <!-- Filter bar -->
            <div class="d-flex flex-wrap align-items-center gap-2 px-3 py-2 border-bottom bg-light" style="font-size:.82rem;">
                <strong class="text-muted me-1">Filter:</strong>
                <div class="btn-group btn-group-sm" id="rptStatusFilter">
                    <button class="btn btn-outline-secondary active" data-status="all">All</button>
                    <button class="btn btn-outline-primary"          data-status="unread">Unread</button>
                    <button class="btn btn-outline-secondary"        data-status="read">Read</button>
                    <button class="btn btn-outline-success"          data-status="resolved">Resolved</button>
                </div>
                <div class="btn-group btn-group-sm ms-2" id="rptCatFilter">
                    <button class="btn btn-outline-secondary active" data-cat="all">All Types</button>
                    <button class="btn btn-outline-danger"           data-cat="damage">Damage</button>
                    <button class="btn btn-outline-info"             data-cat="feedback">Feedback</button>
                    <button class="btn btn-outline-warning"          data-cat="request">Request</button>
                    <button class="btn btn-outline-secondary"        data-cat="other">Other</button>
                </div>
                <div class="btn-group btn-group-sm ms-2" id="rptDeletedFilter">
                    <button class="btn btn-outline-secondary active" data-deleted="active">Active</button>
                    <button class="btn btn-outline-secondary" data-deleted="deleted">Deleted</button>
                    <button class="btn btn-outline-secondary" data-deleted="all">All</button>
                </div>
                <span class="ms-auto text-muted" id="rptVisibleCount" style="font-size:.75rem;"></span>
            </div>

            <div class="card-body p-0 section-scrollable" id="reportsListBody">
                <?php if (empty($allReports)): ?>
                <div class="text-center text-muted py-5">
                    <i class="bi bi-mailbox fs-1 d-block mb-2 opacity-25"></i>
                    <p class="mb-0">No reports yet.</p>
                    <small>Submitted reports from farmers will appear here.</small>
                </div>
                <?php else: ?>
                <?php
                $typeBadge = [
                    'damage'   => ['class' => 'rt-damage',   'label' => 'Damage'],
                    'feedback' => ['class' => 'rt-feedback', 'label' => 'Feedback'],
                    'request'  => ['class' => 'rt-request',  'label' => 'Request'],
                    'other'    => ['class' => 'rt-other',    'label' => 'Other'],
                ];
                foreach ($allReports as $rpt):
                    $isUnread   = ($rpt['status'] ?? 'unread') === 'unread';
                    $isResolved = ($rpt['status'] ?? '') === 'resolved';
                    $cat        = $rpt['report_category'] ?? 'other';
                    $badge      = $typeBadge[$cat] ?? $typeBadge['other'];
                    $farmerName = htmlspecialchars(
                        trim(($rpt['farmer_firstName'] ?? '') . ' ' . ($rpt['farmer_lastName'] ?? '')) ?: '—'
                    );
                    $submittedAt = !empty($rpt['submitted_image'])
                        ? date('M j, Y g:i A', strtotime($rpt['submitted_image']))
                        : '—';
                    $statusColour = match($rpt['status'] ?? 'unread') {
                        'resolved' => 'success',
                        'read'     => 'secondary',
                        default    => 'primary',
                    };
                    $priColour = match($rpt['priority'] ?? '') {
                        'Critical' => 'danger',
                        'High'     => 'warning',
                        'Medium'   => 'info',
                        'Low'      => 'secondary',
                        default    => '',
                    };
                ?>
                <?php $isDeletedReport = !empty($rpt['deleted_at']); ?>
                <div class="report-item <?= $isUnread ? 'report-unread' : '' ?>"
                     data-status="<?= htmlspecialchars($rpt['status'] ?? 'unread') ?>"
                     data-cat="<?= htmlspecialchars($cat) ?>"
                     data-deleted="<?= $isDeletedReport ? '1' : '0' ?>"
                     onclick="openReportModal(<?= htmlspecialchars(json_encode($rpt), ENT_QUOTES) ?>)">

                    <!-- Type badge -->
                    <span class="report-badge-type <?= $badge['class'] ?>" style="margin-top:.2rem;"><?= $badge['label'] ?></span>

                    <!-- Subject + meta -->
                    <div class="flex-grow-1 overflow-hidden">
                        <div class="report-title"><?= htmlspecialchars($rpt['subject'] ?? '(No subject)') ?></div>
                        <div class="report-meta">
                            From <strong><?= $farmerName ?></strong>
                            &nbsp;·&nbsp; <?= $submittedAt ?>
                            <?php if ($priColour): ?>
                            &nbsp;·&nbsp;
                            <span class="badge bg-<?= $priColour ?>" style="font-size:.63rem;"><?= htmlspecialchars($rpt['priority']) ?></span>
                            <?php endif; ?>
                            &nbsp;·&nbsp;
                            <span class="badge bg-<?= $statusColour ?>" style="font-size:.63rem;"><?= htmlspecialchars($rpt['status'] ?? 'unread') ?></span>
                            <?php if ($isDeletedReport): ?>
                            &nbsp;·&nbsp;
                            <span class="badge bg-danger" style="font-size:.63rem;">Deleted</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Action buttons — stop propagation so row click (modal) doesn't fire -->
                    <div class="d-flex flex-column align-items-end gap-1 flex-shrink-0" onclick="event.stopPropagation()">
                        <form method="POST">
                            <input type="hidden" name="markReportStatus" value="1">
                            <input type="hidden" name="reportID"         value="<?= $rpt['report_ID'] ?>">
                            <input type="hidden" name="newStatus"        value="<?= $isUnread ? 'read' : 'unread' ?>">
                            <button type="submit"
                                    class="btn btn-sm <?= $isUnread ? 'btn-outline-primary' : 'btn-outline-secondary' ?>"
                                    style="border-radius:10px;font-size:.7rem;white-space:nowrap;">
                                <i class="bi bi-<?= $isUnread ? 'envelope-open' : 'envelope' ?> me-1"></i>
                                <?= $isUnread ? 'Mark Read' : 'Mark Unread' ?>
                            </button>
                        </form>
                        <?php if (!$isResolved): ?>
                        <form method="POST">
                            <input type="hidden" name="markReportStatus" value="1">
                            <input type="hidden" name="reportID"         value="<?= $rpt['report_ID'] ?>">
                            <input type="hidden" name="newStatus"        value="resolved">
                            <button type="submit"
                                    class="btn btn-sm btn-outline-success"
                                    style="border-radius:10px;font-size:.7rem;">
                                <i class="bi bi-check2-circle me-1"></i>Resolve
                            </button>
                        </form>
                        <?php else: ?>
                        <span class="badge text-success border border-success"
                              style="font-size:.7rem;border-radius:10px;padding:.28rem .6rem;background:rgba(25,135,84,.08);">
                            <i class="bi bi-check2-all me-1"></i>Resolved
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

    </main><!-- /main -->
    </div><!-- /row -->
    </div><!-- /container-fluid -->


    <!-- ================================================================
        MODALS
        ================================================================ -->

    <!-- Edit Farmer Modal -->
    <div class="modal fade" id="editFarmerModal" tabindex="-1" aria-labelledby="editFarmerModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editFarmerModalLabel">
                        <i class="bi bi-person-gear me-2"></i>Edit Farmer
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="editUserId" id="editUserId">
                    <div class="modal-body">

                        <!-- Profile photo -->
                        <div class="text-center mb-4">
                            <div class="position-relative d-inline-block">
                                <img id="editFarmerPreview" src="" alt="Photo"
                                    class="rounded-circle border border-3 border-primary"
                                    style="width:90px;height:90px;object-fit:cover;"
                                    onerror="this.src='https://ui-avatars.com/api/?name=F&background=1ab3ef&color=fff&size=90'">
                                <label for="editProfileImage"
                                    class="position-absolute bottom-0 end-0 bg-primary text-white rounded-circle d-flex align-items-center justify-content-center"
                                    style="width:26px;height:26px;cursor:pointer;">
                                    <i class="bi bi-camera" style="font-size:.75rem;"></i>
                                </label>
                            </div>
                            <input type="file" id="editProfileImage" name="profileImage"
                                accept="image/png,image/jpeg,image/jpg" class="d-none"
                                onchange="previewImg(this,'editFarmerPreview')">
                            <div class="text-muted mt-1" style="font-size:.72rem;">JPG/PNG · max 5 MB</div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">First Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="firstName" id="ef_firstName" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Middle Name</label>
                                <input type="text" class="form-control" name="middleName" id="ef_middleName">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Last Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="lastName" id="ef_lastName" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Address</label>
                                <input type="text" class="form-control" name="address" id="ef_address">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" name="email" id="ef_email" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Contact No.</label>
                                <input type="text" class="form-control" name="contactNo" id="ef_contactNo">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Sex</label>
                                <select class="form-select" name="sex" id="ef_sex">
                                    <option value="">— Select —</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Birth Date</label>
                                <input type="date" class="form-control" name="birthDate" id="ef_birthDate">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Role</label>
                                <select class="form-select" name="role" id="ef_role">
                                    <option value="farmer">Farmer</option>
                                    <option value="guest">Guest</option>
                                    <option value="admin">Admin</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Account Status</label>
                                <select class="form-select" name="status" id="ef_status">
                                    <option value="offline">Offline</option>
                                    <option value="online">Online</option>
                                    <option value="Locked">Locked</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Account Lock</label>
                                <select class="form-select" name="accountLock" id="ef_accountLock">
                                    <option value="UNLOCKED">UNLOCKED</option>
                                    <option value="LOCKED">LOCKED</option>
                                </select>
                            </div>
                        </div>

                        <hr class="my-3">
                        <p class="fw-semibold mb-2"><i class="bi bi-lock me-1"></i>Reset Password <small class="text-muted fw-normal">(leave blank to keep)</small></p>
                        <div class="col-md-6">
                            <input type="password" class="form-control" name="newPassword" placeholder="New password">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="adminEditUser" class="btn btn-primary">
                            <i class="bi bi-floppy me-1"></i>Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Farmer Confirm Modal -->
    <div class="modal fade" id="deleteFarmerModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header" style="background:linear-gradient(90deg,#e53935,#f44336)!important;">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Confirm Delete</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to permanently delete <strong id="deleteFarmerName"></strong>?</p>
                    <p class="text-danger small mb-0"><i class="bi bi-exclamation-circle me-1"></i>This will also remove all associated data.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="deleteUserId" id="deleteUserId">
                        <button type="submit" name="adminDeleteUser" class="btn btn-danger">
                            <i class="bi bi-trash me-1"></i>Delete
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Farmer Modal -->
    <div class="modal fade" id="addFarmerModal" tabindex="-1" aria-labelledby="addFarmerModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addFarmerModalLabel">
                        <i class="bi bi-person-plus me-2"></i>Add New Farmer
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data"><!-- posts to self -->
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">First Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="firstName" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Middle Name</label>
                                <input type="text" class="form-control" name="middleName">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Last Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="lastName" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Address</label>
                                <input type="text" class="form-control" name="address">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" name="email" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Contact No.</label>
                                <input type="text" class="form-control" name="contactNo">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Sex</label>
                                <select class="form-select" name="sex">
                                    <option value="">— Select —</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Birth Date</label>
                                <input type="date" class="form-control" name="birthDate">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Password <span class="text-danger">*</span></label>
                                <input type="password" class="form-control" name="password" required>
                            </div>
                            <input type="hidden" name="role" value="farmer">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="adminAddFarmer" class="btn btn-primary">
                            <i class="bi bi-person-plus me-1"></i>Register
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Notification Modal -->
    <div class="modal fade" id="notifModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable" style="max-width:400px;">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-bell me-2"></i>Notifications</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0">
                    <div class="text-center text-muted py-5">
                        <i class="bi bi-bell-slash fs-1 d-block mb-2 opacity-25"></i>
                        No new notifications.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Admin Profile Modal -->
    <div class="modal fade" id="adminProfileModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-gear me-2"></i>My Profile</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="text-center mb-4">
                            <div class="position-relative d-inline-block">
                                <img id="adminSelfPreview"
                                    src="<?= !empty($adminInfo['imagePath'])
                                        ? '../upload-images/' . htmlspecialchars($adminInfo['imagePath'])
                                        : '' ?>"
                                    onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode(($adminInfo['firstName'] ?? 'A') . '+' . ($adminInfo['lastName'] ?? '')) ?>&background=1ab3ef&color=fff&size=90'"
                                    class="rounded-circle border border-3 border-primary"
                                    style="width:90px;height:90px;object-fit:cover;" alt="Admin">
                                <label for="adminProfileImage"
                                    class="position-absolute bottom-0 end-0 bg-primary text-white rounded-circle d-flex align-items-center justify-content-center"
                                    style="width:26px;height:26px;cursor:pointer;">
                                    <i class="bi bi-camera" style="font-size:.75rem;"></i>
                                </label>
                            </div>
                            <input type="file" id="adminProfileImage" name="adminProfileImage"
                                accept="image/png,image/jpeg,image/jpg" class="d-none"
                                onchange="previewImg(this,'adminSelfPreview')">
                        </div>
                        <div class="row g-3">
                            <div class="col-6">
                                <label class="form-label">First Name</label>
                                <input type="text" class="form-control" name="adminFirstName"
                                    value="<?= htmlspecialchars($adminInfo['firstName'] ?? '') ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label">Last Name</label>
                                <input type="text" class="form-control" name="adminLastName"
                                    value="<?= htmlspecialchars($adminInfo['lastName'] ?? '') ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="adminEmail"
                                    value="<?= htmlspecialchars($adminInfo['email'] ?? '') ?>">
                            </div>
                        </div>
                        <hr>
                        <label class="form-label"><i class="bi bi-lock me-1"></i>New Password <small class="text-muted">(blank = keep current)</small></label>
                        <input type="password" class="form-control" name="adminNewPassword" placeholder="New password">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="adminEditSelf" class="btn btn-primary">
                            <i class="bi bi-floppy me-1"></i>Save
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>


    <!-- ================================================================
        SCRIPTS
        ================================================================ -->
    <script src="../assets/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.3.2/dist/chart.umd.js"></script>

    <script>
    /* ── Helpers ── */
    function previewImg(input, previewId) {
        if (input.files && input.files[0]) {
            const r = new FileReader();
            r.onload = e => document.getElementById(previewId).src = e.target.result;
            r.readAsDataURL(input.files[0]);
        }
    }

    /* ── Edit Farmer — populate modal ── */
    function openEditFarmer(u) {
        document.getElementById('editUserId').value      = u.user_ID;
        document.getElementById('ef_firstName').value    = u.firstName  || '';
        document.getElementById('ef_middleName').value   = u.middleName || '';
        document.getElementById('ef_lastName').value     = u.lastName   || '';
        document.getElementById('ef_address').value      = u.address    || '';
        document.getElementById('ef_email').value        = u.email      || '';
        document.getElementById('ef_contactNo').value    = u.contactNo  || '';
        document.getElementById('ef_sex').value          = u.sex        || '';
        document.getElementById('ef_birthDate').value    = u.birthDate  || '';
        document.getElementById('ef_role').value         = u.role       || 'farmer';
        document.getElementById('ef_status').value       = u.status     || 'offline';
        document.getElementById('ef_accountLock').value  = u.accountLock || 'UNLOCKED';

        const preview = document.getElementById('editFarmerPreview');
        if (u.imagePath) {
            preview.src = '../upload-image/' + u.imagePath;
        } else {
            preview.src = 'https://ui-avatars.com/api/?name=' +
                encodeURIComponent((u.firstName||'') + ' ' + (u.lastName||'')) +
                '&background=1ab3ef&color=fff&size=90';
        }
        new bootstrap.Modal(document.getElementById('editFarmerModal')).show();
    }

    /* ── Delete Farmer confirm ── */
    function confirmDeleteFarmer(id, name) {
        document.getElementById('deleteUserId').value    = id;
        document.getElementById('deleteFarmerName').textContent = name;
        new bootstrap.Modal(document.getElementById('deleteFarmerModal')).show();
    }

    /* ── Farmer list filter (online/offline/all) ── */
    document.querySelectorAll('#farmerTabBtns .btn').forEach(btn => {
        btn.addEventListener('click', function () {
            document.querySelectorAll('#farmerTabBtns .btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            const filter = this.dataset.filter;
            document.querySelectorAll('.farmer-card').forEach(card => {
                const show = filter === 'all' || card.dataset.status === filter;
                card.style.display = show ? '' : 'none';
            });
        });
    });

    /* ── Farmer search ── */
    document.getElementById('farmerSearch').addEventListener('input', function () {
        const q = this.value.toLowerCase();
        document.querySelectorAll('.farmer-card').forEach(card => {
            card.style.display = card.dataset.name.includes(q) ? '' : 'none';
        });
    });

    /* ── Farms deleted filter ── */
    (function () {
        const farmButtons = document.querySelectorAll('#farmDeletedFilter .btn');
        farmButtons.forEach(btn => {
            btn.addEventListener('click', function () {
                farmButtons.forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                const deletedFilter = this.dataset.deleted;
                document.querySelectorAll('.farm-row').forEach(row => {
                    const isDeleted = row.dataset.deleted === '1';
                    const show = deletedFilter === 'all' ||
                                 (deletedFilter === 'active' && !isDeleted) ||
                                 (deletedFilter === 'deleted' && isDeleted);
                    row.style.display = show ? '' : 'none';
                });
            });
        });
    })();

    /* ── Harvest deleted filter ── */
    (function () {
        const harvestButtons = document.querySelectorAll('#harvestDeletedFilter .btn');
        harvestButtons.forEach(btn => {
            btn.addEventListener('click', function () {
                harvestButtons.forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                const deletedFilter = this.dataset.deleted;
                document.querySelectorAll('#harvest-section tbody tr').forEach(row => {
                    const isDeleted = row.dataset.deleted === '1';
                    const show = deletedFilter === 'all' ||
                                 (deletedFilter === 'active' && !isDeleted) ||
                                 (deletedFilter === 'deleted' && isDeleted);
                    row.style.display = show ? '' : 'none';
                });
            });
        });
    })();

    /* ── Global search ── */
    document.getElementById('globalSearch').addEventListener('input', function () {
        const q = this.value.toLowerCase();
        document.querySelectorAll('.farmer-card').forEach(card => {
            card.style.display = card.dataset.name.includes(q) ? '' : 'none';
        });
        // Scroll to farmers section if there's a query
        if (q.length > 0) {
            document.getElementById('farmers').scrollIntoView({ behavior: 'smooth' });
        }
    });

    /* ── Harvest Chart ── */
    (function () {
        const data = typeof HARVEST_DATA !== 'undefined' ? HARVEST_DATA : [];
        const ctx  = document.getElementById('adminHarvestChart');
        if (!ctx || data.length === 0) return;

        let currentGroup = 'month';
        let currentType  = 'bar';
        let chart        = null;

        function groupKey(date, group) {
            const d = new Date(date);
            if (group === 'year')    return d.getFullYear().toString();
            if (group === 'quarter') return d.getFullYear() + '-Q' + (Math.floor(d.getMonth() / 3) + 1);
            return date.slice(0, 7); // YYYY-MM
        }

        function buildDatasets(group) {
            const farms = {};
            data.forEach(row => {
                const key = row.farm_name;
                if (!farms[key]) farms[key] = {};
                const gk = groupKey(row.harvestDate, group);
                farms[key][gk] = (farms[key][gk] || 0) + row.quantity;
            });
            const allLabels = [...new Set(data.map(r => groupKey(r.harvestDate, group)))].sort();
            const palette   = ['#1ab3ef','#00c2d4','#43a047','#f5a623','#7e57c2','#e53935','#fb8c00'];
            const datasets  = Object.entries(farms).map(([name, pts], i) => ({
                label: name,
                data: allLabels.map(l => pts[l] || 0),
                backgroundColor: palette[i % palette.length] + '99',
                borderColor:     palette[i % palette.length],
                borderWidth: 2,
                borderRadius: 8,
                fill: false,
                tension: 0.4,
            }));
            return { labels: allLabels, datasets };
        }

        function renderChart() {
            const { labels, datasets } = buildDatasets(currentGroup);
            if (chart) chart.destroy();
            chart = new Chart(ctx, {
                type: currentType,
                data: { labels, datasets },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top', labels: { font: { family: 'Nunito', size: 12 } } },
                        tooltip: { callbacks: { label: c => ` ${c.formattedValue} kg` } },
                    },
                    scales: {
                        y: { beginAtZero: true, title: { display: true, text: 'kg', font: { family: 'Nunito' } } },
                        x: { grid: { display: false } }
                    }
                }
            });
        }

        renderChart();

        document.querySelectorAll('#adminChartGroup .btn').forEach(btn => {
            btn.addEventListener('click', function () {
                document.querySelectorAll('#adminChartGroup .btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                currentGroup = this.dataset.group;
                renderChart();
            });
        });
        document.querySelectorAll('#adminChartType .btn').forEach(btn => {
            btn.addEventListener('click', function () {
                document.querySelectorAll('#adminChartType .btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                currentType = this.dataset.type;
                renderChart();
            });
        });
    })();
    </script>

    <!-- ================================================================
        REPORT DETAIL MODAL
        ================================================================ -->
    <div class="modal fade" id="reportDetailModal" tabindex="-1" aria-labelledby="reportDetailModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header" style="background:linear-gradient(90deg,#e53935,#f44336);">
                    <h5 class="modal-title text-white" id="reportDetailModalLabel">
                        <i class="bi bi-envelope-open me-2"></i>Report Detail
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="reportDetailBody">
                    <!-- Filled by JS -->
                </div>
                <div class="modal-footer" id="reportDetailFooter">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <!-- Status action buttons injected by JS -->
                </div>
            </div>
        </div>
    </div>

    <script>
    /* ── Report Detail Modal ── */
    function openReportModal(rpt) {
        const typeBadge = {
            damage:   { cls: 'rt-damage',   label: 'Damage' },
            feedback: { cls: 'rt-feedback', label: 'Feedback' },
            request:  { cls: 'rt-request',  label: 'Request' },
            other:    { cls: 'rt-other',    label: 'Other' },
        };
        const cat        = rpt.report_category || 'other';
        const badge      = typeBadge[cat] || typeBadge.other;
        const farmer     = ((rpt.farmer_firstName || '') + ' ' + (rpt.farmer_lastName || '')).trim() || '—';
        const dateStr    = rpt.submitted_image
            ? new Date(rpt.submitted_image).toLocaleString('en-PH', { dateStyle:'medium', timeStyle:'short' })
            : '—';

        const statusColour = { resolved:'success', read:'secondary', unread:'primary' };
        const priColour    = { Critical:'danger', High:'warning', Medium:'info', Low:'secondary' };

        /* ── Category-specific detail rows ── */
        let specificSection = '';
        if (cat === 'damage') {
            specificSection = `
            <div class="card border-danger-subtle mb-3">
                <div class="card-header bg-danger-subtle text-danger fw-bold py-2" style="font-size:.85rem;">
                    <i class="bi bi-lightning-charge me-1"></i> Damage Details
                </div>
                <div class="card-body py-2">
                    <div class="row g-2" style="font-size:.85rem;">
                        <div class="col-sm-6"><span class="text-muted">Farm ID:</span> <strong>${escHtml(rpt.farm_ID ?? '—')}</strong></div>
                        <div class="col-sm-6"><span class="text-muted">Damage Date:</span> <strong>${escHtml(rpt.damage_date ?? '—')}</strong></div>
                        <div class="col-sm-6"><span class="text-muted">Damage Types:</span> <strong>${escHtml(rpt.damage_types ?? '—')}</strong></div>
                        <div class="col-sm-6"><span class="text-muted">Estimated Loss:</span> <strong>${rpt.estimated_loss ? escHtml(rpt.estimated_loss) + ' kg' : '—'}</strong></div>
                    </div>
                </div>
            </div>`;
        } else if (cat === 'feedback') {
            specificSection = `
            <div class="card border-info-subtle mb-3">
                <div class="card-header bg-info-subtle text-info fw-bold py-2" style="font-size:.85rem;">
                    <i class="bi bi-chat-dots me-1"></i> Feedback Details
                </div>
                <div class="card-body py-2">
                    <div class="row g-2" style="font-size:.85rem;">
                        <div class="col-sm-6"><span class="text-muted">Feedback Type:</span> <strong>${escHtml(rpt.feedback_type ?? '—')}</strong></div>
                        <div class="col-sm-6"><span class="text-muted">Page Affected:</span> <strong>${escHtml(rpt.page_affected ?? '—')}</strong></div>
                        <div class="col-sm-6"><span class="text-muted">Priority:</span>
                            ${rpt.priority
                                ? `<span class="badge bg-${priColour[rpt.priority]||'secondary'}">${escHtml(rpt.priority)}</span>`
                                : '—'}
                        </div>
                    </div>
                </div>
            </div>`;
        } else if (cat === 'request') {
            specificSection = `
            <div class="card border-warning-subtle mb-3">
                <div class="card-header bg-warning-subtle text-warning fw-bold py-2" style="font-size:.85rem;">
                    <i class="bi bi-hand-index me-1"></i> Request Details
                </div>
                <div class="card-body py-2">
                    <div class="row g-2" style="font-size:.85rem;">
                        <div class="col-sm-6"><span class="text-muted">Request Category:</span> <strong>${escHtml(rpt.request_category ?? '—')}</strong></div>
                        <div class="col-sm-6"><span class="text-muted">Urgency:</span> <strong>${escHtml(rpt.urgency ?? '—')}</strong></div>
                    </div>
                </div>
            </div>`;
        }

        /* ── Attachment ── */
        const isImage = rpt.imagePath && /\.(jpg|jpeg|png|gif|webp)$/i.test(rpt.imagePath);
        const attachment = rpt.imagePath ? `
            <div class="mb-3">
                <p class="fw-semibold mb-2"><i class="bi bi-paperclip me-1"></i>Attachment</p>
                ${isImage
                    ? `<a href="../upload-image/${escHtml(rpt.imagePath)}" target="_blank">
                           <img src="../upload-image/${escHtml(rpt.imagePath)}"
                                class="img-fluid rounded border" style="max-height:260px;object-fit:contain;"
                                onerror="this.style.display='none'">
                       </a>`
                    : `<a href="../upload-image/${escHtml(rpt.imagePath)}" target="_blank"
                           class="btn btn-sm btn-outline-primary">
                           <i class="bi bi-file-earmark-arrow-down me-1"></i>Download File
                       </a>`
                }
            </div>` : '';

        /* ── Populate modal body ── */
        document.getElementById('reportDetailBody').innerHTML = `
            <!-- Header row: type badge + status -->
            <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
                <span class="report-badge-type ${badge.cls}">${badge.label}</span>
                <span class="badge bg-${statusColour[rpt.status]||'primary'} rounded-pill" style="font-size:.75rem;">
                    ${escHtml(rpt.status || 'unread')}
                </span>
                <span class="ms-auto text-muted" style="font-size:.75rem;">
                    <i class="bi bi-clock me-1"></i>${dateStr}
                </span>
            </div>

            <!-- Subject -->
            <h5 class="fw-bold mb-1">${escHtml(rpt.subject || '(No subject)')}</h5>

            <!-- Farmer info -->
            <p class="text-muted mb-3" style="font-size:.83rem;">
                <i class="bi bi-person-fill me-1"></i>From <strong>${escHtml(farmer)}</strong>
                &nbsp;·&nbsp; Farmer ID #${escHtml(String(rpt.user_ID ?? '—'))}
            </p>

            <hr class="my-2">

            <!-- Description -->
            <p class="fw-semibold mb-1"><i class="bi bi-card-text me-1"></i>Description</p>
            <div class="bg-light rounded p-3 mb-3" style="white-space:pre-wrap;font-size:.9rem;line-height:1.6;">
                ${escHtml(rpt.description || '(No description provided)')}
            </div>

            <!-- Category-specific section -->
            ${specificSection}

            <!-- Attachment -->
            ${attachment}
        `;

        /* ── Populate modal footer with inline status actions ── */
        const isUnread   = (rpt.status || 'unread') === 'unread';
        const isResolved = rpt.status === 'resolved';

        let footerActions = `<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>`;

        // Mark read / unread
        footerActions += `
            <form method="POST" class="d-inline">
                <input type="hidden" name="markReportStatus" value="1">
                <input type="hidden" name="reportID"         value="${escHtml(String(rpt.report_ID))}">
                <input type="hidden" name="newStatus"        value="${isUnread ? 'read' : 'unread'}">
                <button type="submit" class="btn ${isUnread ? 'btn-primary' : 'btn-outline-secondary'}">
                    <i class="bi bi-${isUnread ? 'envelope-open' : 'envelope'} me-1"></i>
                    ${isUnread ? 'Mark as Read' : 'Mark as Unread'}
                </button>
            </form>`;

        // Resolve (only if not resolved)
        if (!isResolved) {
            footerActions += `
            <form method="POST" class="d-inline">
                <input type="hidden" name="markReportStatus" value="1">
                <input type="hidden" name="reportID"         value="${escHtml(String(rpt.report_ID))}">
                <input type="hidden" name="newStatus"        value="resolved">
                <div class="input-group me-2" style="width: 300px;">
                    <textarea name="replyMessage" class="form-control" placeholder="Enter reply message (optional)" rows="1" style="resize: none;"></textarea>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check2-circle me-1"></i>Resolve & Reply
                    </button>
                </div>
            </form>`;
        }

        document.getElementById('reportDetailFooter').innerHTML = footerActions;

        new bootstrap.Modal(document.getElementById('reportDetailModal')).show();
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /* ── Reports Inbox filter ── */
    (function () {
        let activeStatus = 'all';
        let activeCat    = 'all';
        let activeDeleted = 'active';

        function applyFilter() {
            const items = document.querySelectorAll('#reportsListBody .report-item');
            let visible = 0;
            items.forEach(item => {
                const statusOk = activeStatus === 'all' || item.dataset.status === activeStatus;
                const catOk    = activeCat    === 'all' || item.dataset.cat    === activeCat;
                const deletedOk = activeDeleted === 'all' ||
                                  (activeDeleted === 'active' && item.dataset.deleted === '0') ||
                                  (activeDeleted === 'deleted' && item.dataset.deleted === '1');
                const show     = statusOk && catOk && deletedOk;
                item.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            const countEl = document.getElementById('rptVisibleCount');
            if (countEl) countEl.textContent = `Showing ${visible} of ${items.length}`;
        }

        document.querySelectorAll('#rptStatusFilter .btn').forEach(btn => {
            btn.addEventListener('click', function () {
                document.querySelectorAll('#rptStatusFilter .btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                activeStatus = this.dataset.status;
                applyFilter();
            });
        });

        document.querySelectorAll('#rptDeletedFilter .btn').forEach(btn => {
            btn.addEventListener('click', function () {
                document.querySelectorAll('#rptDeletedFilter .btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                activeDeleted = this.dataset.deleted;
                applyFilter();
            });
        });


        document.querySelectorAll('#rptCatFilter .btn').forEach(btn => {
            btn.addEventListener('click', function () {
                document.querySelectorAll('#rptCatFilter .btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                activeCat = this.dataset.cat;
                applyFilter();
            });
        });

        // Initial count
        applyFilter();
    })();
    </script>

    </body>
</html>
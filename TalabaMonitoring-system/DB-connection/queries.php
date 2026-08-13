<?php
    include("connect.php");

    try {
        $sql = "CREATE TABLE IF NOT EXISTS myUsers (
                user_ID INT AUTO_INCREMENT PRIMARY KEY,
                firstName VARCHAR(50),
                lastName VARCHAR(50),
                middleName VARCHAR(50),
                address VARCHAR(50),
                email VARCHAR(50),
                contactNo VARCHAR(50),
                sex VARCHAR(6),
                birthDate DATE,
                password VARCHAR(255),
                role VARCHAR(20) DEFAULT 'farmer',
                status VARCHAR(10) DEFAULT 'offline',
                accountLock VARCHAR(10) DEFAULT 'UNLOCKED',
                failed_attempts INT DEFAULT 0,
                imagePath VARCHAR(50) DEFAULT NULL,
                deleted_at DATETIME DEFAULT NULL,
                myUsers VARCHAR(10) DEFAULT 'none'
            );"
        ;
        // $sql = "ALTER TABLE myUsers ADD COLUMN IF NOT EXISTS deleted_at DATETIME DEFAULT NULL";
        // $sql = "DROP TABLE myUsers";
        // $sql = "ALTER TABLE oysterFarm ADD COLUMN myUsers VARCHAR(10) DEFAULT 'none';";

        // $sql = "CREATE TABLE IF NOT EXISTS oysterFarm (
        //         farm_ID INT AUTO_INCREMENT PRIMARY KEY,
        //         user_ID INT NOT NULL,
        //         location VARCHAR(100),
        //         surfaceArea DECIMAL(15,2),
        //         breedMethod VARCHAR(100),
        //         seedingDate DATE,
        //         farmName_number VARCHAR(50),
        //         waterDepts DECIMAL(15,2),
        //         latitude  DECIMAL(10, 7),
        //         longitude DECIMAL(10, 7),
        //         deleted_at DATETIME NULL DEFAULT NULL,
        //         imagePath VARCHAR(50),
        //         FOREIGN KEY (user_ID) REFERENCES myUsers(user_ID) ON DELETE RESTRICT ON UPDATE CASCADE
        //     );"
        // ;
        // $sql = "DROP TABLE oysterFarm";
        // $sql = "ALTER TABLE oysterFarm ADD COLUMN farmName_number VARCHAR(50) DEFAULT 'none';";
        // $sql = "ALTER TABLE oysterFarm ADD COLUMN imagePath VARCHAR(50) DEFAULT 'none';";
        // $sql = "ALTER TABLE oysterFarm ADD COLUMN waterDepts DECIMAL(15,2) DEFAULT NULL;";
        // $sql = "ALTER TABLE oysterFarm MODIFY COLUMN surfaceArea DECIMAL(15,2) DEFAULT NULL;";
        // $sql = "ALTER TABLE oysterFarm
        //             ADD COLUMN latitude  DECIMAL(10, 7) NULL,
        //             ADD COLUMN longitude DECIMAL(10, 7) NULL
        //         ;"
        // ;
        // $sql = "ALTER TABLE oysterFarm ADD COLUMN IF NOT EXISTS deleted_at DATETIME DEFAULT NULL";

        // $sql = "CREATE TABLE IF NOT EXISTS harvestHistory (
        //         harvest_ID INT AUTO_INCREMENT PRIMARY KEY,
        //         farm_ID INT NOT NULL,
        //         user_ID INT NOT NULL,
        //         harvestDate DATE,
        //         quantity DECIMAL,
        //         deleted_at DATETIME NULL DEFAULT NULL,
        //         FOREIGN KEY (user_ID) REFERENCES myUsers(user_ID) ON DELETE RESTRICT ON UPDATE CASCADE,
        //         FOREIGN KEY (farm_ID) REFERENCES oysterFarm(farm_ID) ON DELETE RESTRICT ON UPDATE CASCADE
        //     );"
        // ;
        // $sql = "DROP TABLE harvestHistory";
        // $sql = "ALTER TABLE farmersReports ADD COLUMN IF NOT EXISTS deleted_at DATETIME DEFAULT NULL";

        // $sql = "CREATE TABLE IF NOT EXISTS weatherHistory (
        //         weather_ID INT AUTO_INCREMENT PRIMARY KEY,
        //         farm_ID INT NOT NULL,
        //         user_ID INT NOT NULL,
        //         recordDate DATE NOT NULL,
        //         recordTime TIME,
        //         tideType VARCHAR(100),
        //         waterTempreture DECIMAL,
        //         naturalDisaster VARCHAR(50),
        //         note VARCHAR(200),
        //         naturalDisater VARCHAR(50), -- eample: bagyo, elninyo or none
        //         deleted_at DATETIME NULL DEFAULT NULL,
        //         FOREIGN KEY (user_ID)  REFERENCES myUsers(user_ID) ON DELETE RESTRICT ON UPDATE CASCADE,
        //         FOREIGN KEY (farm_ID)  REFERENCES oysterFarm(farm_ID) ON DELETE RESTRICT ON UPDATE CASCADE
        //     );"
        // ;
        // $sql = "DROP TABLE weatherHistory";
        // $sql = "ALTER TABLE weatherHistory ADD COLUMN naturalDisaster VARCHAR(50) DEFAULT 'none';";

        // $sql = "INSERT INTO
        //     "
        // ;
        
        // $sql = "CREATE TABLE IF NOT EXISTS farmersReports (
        //         report_ID INT AUTO_INCREMENT PRIMARY KEY,
        //         user_ID INT NOT NULL,
        //         report_category VARCHAR(20) NOT NULL,         -- 'damage', 'feedback', 'request', 'other'
        //         subject VARCHAR(255) NOT NULL,
        //         description TEXT,
        //         -- Damage-specific fields
        //         farm_ID INT DEFAULT NULL,
        //         damage_types VARCHAR(255) DEFAULT NULL,   -- Comma-separated values (e.g., 'Storm / Typhoon, Flooding')
        //         damage_date DATE DEFAULT NULL,
        //         estimated_loss DECIMAL(10,2) DEFAULT NULL,  -- In kg/units
        //         -- Feedback-specific fields
        //         feedback_type  VARCHAR(50) DEFAULT NULL,
        //         page_affected  VARCHAR(100) DEFAULT NULL,
        //         priority VARCHAR(20) DEFAULT NULL,    -- 'Low', 'Medium', 'High', 'Critical'
        //         -- Request-specific fields
        //         request_category VARCHAR(80) DEFAULT NULL,
        //         urgency VARCHAR(20) DEFAULT NULL,
        //         -- Attachment
        //         imagePath VARCHAR(255) DEFAULT NULL,   -- Path to uploaded file (e.g., 'farmer/report/1/report_123.jpg')
        //         -- Metadata
        //         status VARCHAR(20) DEFAULT 'unread', -- 'unread', 'read', 'resolved'
        //         submitted_image DATETIME DEFAULT CURRENT_TIMESTAMP,
        //         deleted_at DATETIME NULL DEFAULT NULL,
        //         FOREIGN KEY (user_ID) REFERENCES myUsers(user_ID) ON DELETE CASCADE,
        //         FOREIGN KEY (farm_ID) REFERENCES oysterFarm(farm_ID) ON DELETE SET NULL
        //     );"
        // ;

        // $sql = "DROP TABLE farmersReports";

        // $sql = "CREATE TABLE IF NOT EXISTS notifications (
        //         notification_ID INT AUTO_INCREMENT PRIMARY KEY,
        //         user_ID INT NOT NULL,
        //         type VARCHAR(50) NOT NULL,  -- 'weather_alert', 'report_response', etc.
        //         title VARCHAR(255) NOT NULL,
        //         message TEXT,
        //         is_read BOOLEAN DEFAULT FALSE,
        //         created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        //         deleted_at DATETIME NULL DEFAULT NULL,
        //         FOREIGN KEY (user_ID) REFERENCES myUsers(user_ID) ON DELETE CASCADE
        //     );"
        // ;


        /**
         * soft delete feature
        */
        // $sql = "ALTER TABLE oysterFarm ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL DEFAULT NULL";

        // $sql = "ALTER TABLE harvestHistory ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL DEFAULT NULL";

        // $sql = "ALTER TABLE weatherHistory ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL DEFAULT NULL";

        // $sql = "ALTER TABLE farmersReports ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL DEFAULT NULL";

        // $sql = "ALTER TABLE farmersReports ADD COLUMN admin_response TEXT DEFAULT NULL";



        if ($connection->query($sql) == TRUE) {
            $alter = "ALTER TABLE myUsers ADD COLUMN IF NOT EXISTS accountLock VARCHAR(10) DEFAULT 'UNLOCKED'";
            if ($connection->query($alter) === TRUE) {
                echo "Queried successfully.";
            } else {
                echo "Error updating myUsers table: " . $connection->error;
            }
        } else {
            echo "Error creating table: " . $connection->error;
        }       
    }
    catch (mysqli_sql_exception $exp) {
        echo "error occur<br>" . $exp;
    }

    $connection->close();
?>
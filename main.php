<?php
session_start();
/*  ROLE CHECK FUNCTIONS  */
function isAdmin() {
    return isset($_SESSION['user']) && $_SESSION['user']['role'] === 'admin';
}
function isAgent() {
    return isset($_SESSION['user']) && $_SESSION['user']['role'] === 'agent';
}
function isMechanic() {
    return isset($_SESSION['user']) && $_SESSION['user']['role'] === 'mechanic';
}
/*  CALCULATE RENTAL PRICE  */
function calculateRentalPrice($pdo, $car_id, $start_date, $end_date) {
    if (!$car_id || !$start_date || !$end_date) return 0;
    $stmt = $pdo->prepare("SELECT daily_price FROM cars WHERE car_id = ?");
    $stmt->execute([$car_id]);
    $car = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$car) return 0;
    $start = strtotime($start_date);
    $end   = strtotime($end_date);
    if ($end < $start) return 0;
    $days = max(1, ceil(($end - $start) / 86400));
    return round($days * $car['daily_price'], 2);
}
/*  DATABASE AUTO-CREATION  */
try {
    $pdo = new PDO("mysql:host=localhost;charset=utf8mb4", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS benazzouzmohamed CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo = new PDO("mysql:host=localhost;dbname=benazzouzmohamed;charset=utf8mb4", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("
       CREATE TABLE IF NOT EXISTS agencies (
          agency_id INT AUTO_INCREMENT PRIMARY KEY,
          name VARCHAR(100) UNIQUE,
          password_hash VARCHAR(255),
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
       )
    ");
    function addAgencyColumn($pdo, $table) {
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM $table LIKE 'agency_id'")->fetchAll();
            if (empty($cols)) {
                $pdo->exec("ALTER TABLE $table ADD COLUMN agency_id INT");
                $pdo->exec("ALTER TABLE $table ADD CONSTRAINT fk_{$table}_agency FOREIGN KEY (agency_id) REFERENCES agencies(agency_id) ON DELETE CASCADE");
            }
        } catch (Exception $e) {
        }
    }
    $pdo->exec("
       CREATE TABLE IF NOT EXISTS staff (
          staff_id INT AUTO_INCREMENT PRIMARY KEY,
          first_name VARCHAR(50),
          last_name VARCHAR(50),
          email VARCHAR(100),
          phone VARCHAR(30),
          role ENUM('admin','agent','mechanic') NOT NULL,
          password_hash VARCHAR(255),
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
       )
    ");
    addAgencyColumn($pdo, 'staff');
    $pdo->exec("
       CREATE TABLE IF NOT EXISTS cars (
          car_id INT AUTO_INCREMENT PRIMARY KEY,
          brand VARCHAR(50),
          model VARCHAR(50),
          year INT,
          license_plate VARCHAR(20),
          color VARCHAR(30),
          mileage INT DEFAULT 0,
          status ENUM('available','rented','maintenance') DEFAULT 'available',
          daily_price DECIMAL(10,2),
          image_url VARCHAR(2048) NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
       )
    ");
    addAgencyColumn($pdo, 'cars');
    $carImageColumn = $pdo->query("SHOW COLUMNS FROM cars WHERE Field = 'image_url'")->fetch(PDO::FETCH_ASSOC);
    if (!$carImageColumn) {
        $pdo->exec("ALTER TABLE cars ADD COLUMN image_url VARCHAR(2048) NULL");
    }
    $pdo->exec("
       CREATE TABLE IF NOT EXISTS clients (
          client_id INT AUTO_INCREMENT PRIMARY KEY,
          first_name VARCHAR(50),
          last_name VARCHAR(50),
          email VARCHAR(100),
          phone VARCHAR(30),
          driver_license VARCHAR(50),
          address TEXT,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
       )
    ");
    addAgencyColumn($pdo, 'clients');
    $pdo->exec("
       CREATE TABLE IF NOT EXISTS rentals (
          rental_id INT AUTO_INCREMENT PRIMARY KEY,
          client_id INT,
          car_id INT,
          start_date DATE,
          end_date DATE,
          total_price DECIMAL(10,2),
          status ENUM('ongoing','completed','cancelled') DEFAULT 'ongoing',
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          FOREIGN KEY (client_id) REFERENCES clients(client_id) ON DELETE CASCADE,
          FOREIGN KEY (car_id) REFERENCES cars(car_id) ON DELETE CASCADE
       )
    ");
    addAgencyColumn($pdo, 'rentals');
    $pdo->exec("
       CREATE TABLE IF NOT EXISTS maintenance (
          maintenance_id INT AUTO_INCREMENT PRIMARY KEY,
          car_id INT, 
          description TEXT,
          cost DECIMAL(10,2) DEFAULT 0.00,
          maintenance_date DATE,
          mechanic_id INT,
          status ENUM('in_progress','completed') DEFAULT 'in_progress',
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          FOREIGN KEY (car_id) REFERENCES cars(car_id) ON DELETE CASCADE,
          FOREIGN KEY (mechanic_id) REFERENCES staff(staff_id) ON DELETE RESTRICT
       )
    ");
    addAgencyColumn($pdo, 'maintenance');
    $maintenanceStatusColumn = $pdo->query("SHOW COLUMNS FROM maintenance LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if (!$maintenanceStatusColumn) {
        $pdo->exec("ALTER TABLE maintenance ADD COLUMN status ENUM('in_progress','completed') DEFAULT 'in_progress'");
    }
    $pdo->exec("
       CREATE TABLE IF NOT EXISTS payments (
          payment_id INT AUTO_INCREMENT PRIMARY KEY,
          rental_id INT,
          amount DECIMAL(10,2),
          method ENUM('cash','credit_card','debit_card','bank_transfer'),
          status ENUM('paid','pending') DEFAULT 'paid',
          payment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          FOREIGN KEY (rental_id) REFERENCES rentals(rental_id) ON DELETE CASCADE
       )
    ");
    addAgencyColumn($pdo, 'payments');
    /*  ADVANCED DB FEATURES  */
    try {
        $pdo->exec("DROP TRIGGER IF EXISTS after_rental_insert");
        $pdo->exec("
            CREATE TRIGGER after_rental_insert AFTER INSERT ON rentals
            FOR EACH ROW
            BEGIN
                IF NEW.start_date <= CURDATE() AND NEW.end_date >= CURDATE() THEN
                    UPDATE cars SET status = 'rented' WHERE car_id = NEW.car_id;
                END IF;
            END
        ");
        $pdo->exec("DROP TRIGGER IF EXISTS after_rental_complete");
        $pdo->exec("
            CREATE TRIGGER after_rental_complete AFTER UPDATE ON rentals
            FOR EACH ROW
            BEGIN
                IF NEW.status = 'completed' OR NEW.status = 'cancelled' THEN
                    -- Only set to available if no other ongoing rental is currently active for this car
                    IF NOT EXISTS (
                        SELECT 1 FROM rentals 
                        WHERE car_id = NEW.car_id 
                        AND rental_id != NEW.rental_id
                        AND status = 'ongoing'
                        AND start_date <= CURDATE() 
                        AND end_date >= CURDATE()
                    ) THEN
                        UPDATE cars SET status = 'available' WHERE car_id = NEW.car_id;
                    END IF;
                END IF;
            END
        ");
        $pdo->exec("DROP TRIGGER IF EXISTS before_rental_insert_check");
        $pdo->exec("
            CREATE TRIGGER before_rental_insert_check BEFORE INSERT ON rentals
            FOR EACH ROW
            BEGIN
                DECLARE car_state VARCHAR(20);
                DECLARE overlap_count INT;
                -- Check if car is in maintenance
                SELECT status INTO car_state FROM cars WHERE car_id = NEW.car_id;
                IF car_state = 'maintenance' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Car is under maintenance and cannot be rented.';
                END IF;
                -- Check for overlapping rentals (only ongoing rentals that overlap with new dates)
                SELECT COUNT(*) INTO overlap_count 
                FROM rentals 
                WHERE car_id = NEW.car_id 
                AND status = 'ongoing'
                AND NOT (NEW.end_date < start_date OR NEW.start_date > end_date);
                IF overlap_count > 0 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Car is already rented during the selected dates. Please choose different dates.';
                END IF;
            END
        ");
        $pdo->exec("DROP TRIGGER IF EXISTS after_payment_insert");
        $pdo->exec("DROP TRIGGER IF EXISTS payment_status_check");
        $pdo->exec("
            CREATE TRIGGER payment_status_check BEFORE INSERT ON payments
            FOR EACH ROW
            BEGIN
                DECLARE total DECIMAL(10,2);
                SELECT total_price INTO total FROM rentals WHERE rental_id = NEW.rental_id;
                IF NEW.amount >= total THEN
                    SET NEW.status = 'paid';
                END IF;
            END
        ");
        $pdo->exec("CREATE OR REPLACE VIEW currently_rented_cars AS
            SELECT r.rental_id, r.car_id, c.brand, c.model, c.license_plate, r.client_id, r.start_date, r.end_date, r.agency_id
            FROM rentals r
            JOIN cars c ON r.car_id = c.car_id
            WHERE r.status = 'ongoing'
        ");
        $pdo->exec("CREATE OR REPLACE VIEW revenue_by_month AS
            SELECT agency_id, YEAR(p.payment_date) AS yr, MONTH(p.payment_date) AS m, SUM(p.amount) AS revenue
            FROM payments p
            WHERE p.status = 'paid'
            GROUP BY agency_id, yr, m
        ");
        $pdo->exec("DROP PROCEDURE IF EXISTS get_client_rentals");
        $pdo->exec("
            CREATE PROCEDURE get_client_rentals(IN in_client_id INT)
            BEGIN
                SELECT r.*, c.brand, c.model, c.license_plate
                FROM rentals r
                JOIN cars c ON r.car_id = c.car_id
                WHERE r.client_id = in_client_id
                ORDER BY r.start_date DESC;
            END
        ");
        $pdo->exec("DROP PROCEDURE IF EXISTS complete_rental");
        $pdo->exec("
            CREATE PROCEDURE complete_rental(IN in_rental_id INT, IN extra_charges DECIMAL(10,2))
            BEGIN
                UPDATE rentals SET total_price = total_price + extra_charges, status = 'completed' WHERE rental_id = in_rental_id;
            END
        ");
    } catch (Exception $e) {
        echo "DB Feature Error: " . $e->getMessage();
    }
    /*  MIGRATION & INIT  */
    $defaultAgencyId = 1;
    $agencyExists = $pdo->query("SELECT COUNT(*) FROM agencies WHERE agency_id=$defaultAgencyId")->fetchColumn();
    if ($agencyExists == 0) {
        $stmt = $pdo->prepare("INSERT INTO agencies (agency_id, name, password_hash) VALUES (?, ?, ?)");
        $stmt->execute([$defaultAgencyId, 'Allo Location', password_hash('agence123', PASSWORD_DEFAULT)]);
    }
    $adminExists = $pdo->query("SELECT COUNT(*) FROM staff WHERE role='admin' AND agency_id=$defaultAgencyId")->fetchColumn();
    if ($adminExists == 0) {
        $anyAdmin = $pdo->query("SELECT COUNT(*) FROM staff WHERE email='admin@admin.com'")->fetchColumn();
        if ($anyAdmin == 0) {
           $pdo->prepare("
              INSERT INTO staff (staff_id, first_name, last_name, email, phone, role, password_hash, agency_id)
              VALUES (1, 'Mohamed', 'Benazzouz', 'admin@admin.com', '0000', 'admin', ?, ?)
           ")->execute([password_hash("admin123", PASSWORD_DEFAULT), $defaultAgencyId]);
        }
    }
    $agentExists = $pdo->query("SELECT COUNT(*) FROM staff WHERE email='agent@agent.com'")->fetchColumn();
    if ($agentExists == 0) {
       $pdo->prepare("
          INSERT INTO staff (staff_id, first_name, last_name, email, phone, role, password_hash, agency_id)
          VALUES (2, 'kermache', 'Wahid', 'agent@agent.com', '1001', 'agent', ?, 1)
       ")->execute([password_hash("agent123", PASSWORD_DEFAULT)]);
    }
    $mechanicExists = $pdo->query("SELECT COUNT(*) FROM staff WHERE email='mechanic@mechanic.com'")->fetchColumn();
    if ($mechanicExists == 0) {
       $pdo->prepare("
          INSERT INTO staff (staff_id, first_name, last_name, email, phone, role, password_hash, agency_id)
          VALUES (3, 'Boussaid', 'Ishak', 'mechanic@mechanic.com', '1002', 'mechanic', ?, 1)
       ")->execute([password_hash("mechanic123", PASSWORD_DEFAULT)]);
    }
    $carCount = $pdo->query("SELECT COUNT(*) FROM cars")->fetchColumn();
    if ($carCount == 0) {
       $pdo->query("
          INSERT INTO cars(car_id,brand, model, year, license_plate, color, mileage, daily_price, status, agency_id) VALUES
          (1,'Citroën',    'C-elysée', 2014, '00622 114 46','blanche',136000,   4500, 'maintenance', 1),
          (2,'Renault',    'Clio 4',   2016, '1234 116 46', 'grise',   89000,   6000, 'rented', 1),
          (3,'Volkswagen', 'Golf 7',   2018, '0564 118 46', 'noire',  110000,   7500, 'maintenance', 1),
          (4,'Ferrari',    'Roma',     2025, 'F-164',       'rouge',    1000, 280000, 'available', 1),
          (5,'BMW',        '320i',     2021, '1232 121 31', 'bleue',   95000,  12000, 'rented', 1)
       ");
    }
    $clientCount = $pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
    if ($clientCount == 0) {
       $pdo->query("
          INSERT INTO clients (client_id,first_name, last_name, phone, driver_license, email, agency_id) VALUES
          (1,'Benalia',    'seifeddine',  '2001','huer7266',   'seifeddine@gmail.com', 1),
          (2,'Boughalem',   'yassine','2002','oeui928',    'yassine@gmail.com', 1),
          (3,'Bouab', 'Iyed',  '2003','iyewj78473', 'iyed@gmail.com', 1)
       ");
    }
    $rentalCount = $pdo->query("SELECT COUNT(*) FROM rentals")->fetchColumn();
    if ($rentalCount == 0) {
       $pdo->query("
          INSERT INTO rentals (rental_id,client_id, car_id, start_date, end_date, total_price, status, agency_id) VALUES
          (1, 1, 2, '2025-12-09', '2025-12-13', 30000.00, 'ongoing', 1),
          (2, 2, 5, '2025-11-20', '2025-11-21', 12000.00, 'completed', 1)
       ");
    }
    $paymentCount = $pdo->query("SELECT COUNT(*) FROM payments")->fetchColumn();
    if ($paymentCount == 0) {
       $pdo->query("
          INSERT INTO payments (payment_id,rental_id, amount, method, status, agency_id) VALUES
          (1, 1, 30000, 'credit_card', 'paid', 1),
          (2, 2, 12000, 'cash', 'paid', 1)
       ");
    }
    $maintenanceCount = $pdo->query("SELECT COUNT(*) FROM maintenance")->fetchColumn();
    if ($maintenanceCount == 0) {
       $pdo->query("
          INSERT INTO maintenance (car_id, description, cost, maintenance_date, mechanic_id, agency_id) VALUES
          (1, 'Oil change and brake check', 2500, '2025-12-03', 3, 1),
          (3, 'Tire replacement', 8000, '2025-12-04', 3, 1)
       ");
    }
    $tables = ['staff', 'cars', 'clients', 'rentals', 'maintenance', 'payments'];
    foreach ($tables as $t) {
        $pdo->exec("UPDATE $t SET agency_id=$defaultAgencyId WHERE agency_id IS NULL");
    }
} catch (Exception $e) {
    die("!!! Database initialization error: " . $e->getMessage());
}
/*  AUTHENTICATION & AGENCY MANAGEMENT  */
if (isset($_GET['logout_agency'])) {
    session_destroy();
    header("Location: ?");
    exit;
}
if (isset($_POST['super_logout'])) {
    session_destroy();
    header("Location: ?");
    exit;
}
if (isset($_POST['super_login'])) {
    if ($_POST['username'] === 'super' && $_POST['password'] === 'super123') {
        $_SESSION['is_super_admin'] = true;
    } else {
        $superError = "Invalid Super Admin Credentials";
    }
}
if (isset($_SESSION['is_super_admin']) && isset($_POST['super_action'])) {
    $action = $_POST['super_action'];
    if ($action === 'create') {
        try {
            $stmt = $pdo->prepare("INSERT INTO agencies (name, password_hash) VALUES (?, ?)");
            $stmt->execute([$_POST['name'], password_hash($_POST['password'], PASSWORD_DEFAULT)]);
            $newAgencyId = $pdo->lastInsertId();
            $stmt = $pdo->prepare("
                INSERT INTO staff (first_name, last_name, email, phone, role, password_hash, agency_id)
                VALUES (?, ?, ?, ?, 'admin', ?, ?)
            ");
            $stmt->execute([
                $_POST['admin_first_name'],
                $_POST['admin_last_name'],
                $_POST['admin_email'],
                $_POST['admin_phone'],
                password_hash($_POST['admin_password'], PASSWORD_DEFAULT),
                $newAgencyId
            ]);
            $superMsg = "Agency and Admin Created Successfully!";
        } catch (PDOException $e) {
            $superError = "Error: " . $e->getMessage();
        }
    } elseif ($action === 'edit') {
        $stmt = $pdo->prepare("UPDATE agencies SET name=?, password_hash=? WHERE agency_id=?");
        $stmt->execute([$_POST['name'], password_hash($_POST['password'], PASSWORD_DEFAULT), $_POST['agency_id']]);
        $superMsg = "Agency Updated!";
    } elseif ($action === 'delete') {
        $stmt = $pdo->prepare("DELETE FROM agencies WHERE agency_id=?");
        $stmt->execute([$_POST['agency_id']]);
        $superMsg = "Agency Deleted!";
    }
}
if (isset($_POST['agency_login'])) {
    $stmt = $pdo->prepare("SELECT * FROM agencies WHERE name = ?");
    $stmt->execute([$_POST['agency_name']]);
    $agency = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($agency && password_verify($_POST['agency_password'], $agency['password_hash'])) {
        $_SESSION['agency_id'] = $agency['agency_id'];
        $_SESSION['agency_name'] = $agency['name'];
        header("Location: ?");
        exit;
    } else {
        $agencyError = "Invalid Agency Name or Password.";
    }
}
if (!isset($_SESSION['agency_id'])) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="UTF-8">
    <title>Agency Portal</title>
    <link rel="stylesheet" href="assets/portal.css">
    </head>
    <body>
    <?php if (isset($_SESSION['is_super_admin'])): ?>
        <div class="container" style="width: 600px;">
            <h2>Super Admin Dashboard <span class="super-badge">ROOT</span></h2>
            <?php if (isset($superMsg)) echo "<p style='color:#1e8e3e; background:#e6f4ea; padding:12px 16px; border-radius:8px; font-weight:500;'>$superMsg</p>"; ?>
            <?php if (isset($superError)) echo "<p style='color:#d93025; background:#fce8e6; padding:12px 16px; border-radius:8px; font-weight:500;'>$superError</p>"; ?>
            <h3>Manage Agencies</h3>
            <?php
            $allAgencies = $pdo->query("SELECT * FROM agencies")->fetchAll(PDO::FETCH_ASSOC);
            ?>
            <table>
                <tr><th>ID</th><th>Name</th><th>Actions</th></tr>
                <?php foreach ($allAgencies as $a): ?>
                <tr>
                    <td><?= $a['agency_id'] ?></td>
                    <td><?= htmlspecialchars($a['name']) ?></td>
                    <td>
                        <form method="POST" style="display:inline; padding:0; background:none; border:none; box-shadow:none;">
                            <input type="hidden" name="agency_id" value="<?= $a['agency_id'] ?>">
                            <input type="hidden" name="super_action" value="delete">
                            <button type="submit" style="background:none; color:#dc3545; padding:0; width:auto; margin:0;" onclick="return confirm('DELETE Agency and ALL DATA?')">Del</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
            <hr style="border-color:#e8eaed;">
            <h3>Create New Agency</h3>
            <form method="POST" style="background:none; padding:0; border:none; box-shadow:none;">
                <input type="hidden" name="super_action" value="create">
                <h4 style="color:#aaa; margin:15px 0 5px 0; font-size:0.9em;">Agency Details</h4>
                <input name="name" placeholder="Agency Name" required>
                <input type="password" name="password" placeholder="Agency Password" required>
                <h4 style="color:#aaa; margin:15px 0 5px 0; font-size:0.9em;">Admin Details</h4>
                <input name="admin_first_name" placeholder="Admin First Name" required>
                <input name="admin_last_name" placeholder="Admin Last Name" required>
                <input type="email" name="admin_email" placeholder="Admin Email" required>
                <input name="admin_phone" placeholder="Admin Phone">
                <input type="password" name="admin_password" placeholder="Admin Password" required>
                <button type="submit">Create Agency & Admin</button>
            </form>
            <form method="POST" style="margin-top:20px; background:none; padding:0; border:none; box-shadow:none;">
                <button type="submit" name="super_logout" style="background:#5f6368;">Logout</button>
            </form>
        </div>
    <?php else: ?>
        <div class="container" id="login-box">
            <h2>Agency Login</h2>
            <?php if (isset($agencyError)) echo "<p style='color:#d93025; background:#fce8e6; padding:12px 16px; border-radius:8px; font-weight:500;'>$agencyError</p>"; ?>
            <form method="POST">
                <input name="agency_name" placeholder="Agency Name" required>
                <input type="password" name="agency_password" placeholder="Password" required>
                <button type="submit" name="agency_login">Enter Agency</button>
            </form>
            <p style="color:#80868b; font-size:13px; margin-top:16px; line-height:1.6;">
                <strong>Demo Credentials:</strong><br>
                Agency: Allo Location<br>
                Password: agence123
            </p>
            <span class="switch-link" onclick="document.getElementById('login-box').style.display='none';document.getElementById('super-box').style.display='block'">Super Admin Login</span>
        </div>
        <div class="container" id="super-box" style="display:none; border-top-color:#dc3545;">
            <h2 style="color:#dc3545;">Super Admin</h2>
            <?php if (isset($superError)) echo "<p style='color:#d93025; background:#fce8e6; padding:12px 16px; border-radius:8px; font-weight:500;'>$superError</p>"; ?>
            <form method="POST">
                <input name="username" placeholder="Username" required>
                <input type="password" name="password" placeholder="Password" required>
                <button type="submit" name="super_login" style="background:#dc3545;">Login as Super Admin</button>
            </form>
            <p style="color:#80868b; font-size:13px; margin-top:16px; line-height:1.6;">
                <strong>Demo Credentials:</strong><br>
                Username: super<br>
                Password: super123
            </p>
            <span class="switch-link" onclick="document.getElementById('super-box').style.display='none';document.getElementById('login-box').style.display='block'">Back to Agency Login</span>
        </div>
    <?php endif; ?>
    </body>
    </html>
    <?php
    exit;
}
/*  USER AUTHENTICATION  */
if (isset($_POST['login'])) {
    $stmt = $pdo->prepare("SELECT * FROM staff WHERE email=? AND agency_id=?");
    $stmt->execute([$_POST['email'], $_SESSION['agency_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user && password_verify($_POST['password'], $user['password_hash'])) {
        $_SESSION['user'] = $user;
        if ($user['role'] === 'mechanic') {
            header("Location: ?table=maintenance");
        } else {
            header("Location: ?");
        }
        exit;
    } else {
        echo "<p style='color:red'>Invalid email or password for this agency.</p>";
        echo "<p style='color:orange; font-size:0.8em'>Debug: Checking " . htmlspecialchars($_POST['email']) . " in Agency ID: " . $_SESSION['agency_id'] . "</p>";
    }
}
if (isset($_GET['logout'])) { //logout
    unset($_SESSION['user']);
    header("Location: ?");
    exit;
}
function getAll($pdo, $table) {
    $stmt = $pdo->prepare("SELECT * FROM $table WHERE agency_id = ?");
    $stmt->execute([$_SESSION['agency_id']]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function getById($pdo, $table, $id, $idCol) {
    if (!$id) return [];
    $stmt = $pdo->prepare("SELECT * FROM $table WHERE $idCol=? AND agency_id=?");
    $stmt->execute([$id, $_SESSION['agency_id']]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ?: [];
}
function tableUrl($overrides = []) {
    $params = $_GET;
    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }
    return '?' . http_build_query($params);
}
/*  CRUD OPERATIONS  */
$allowedTables = ['cars', 'clients', 'rentals', 'maintenance', 'payments', 'staff'];
$table = $_GET['table'] ?? null;
if ($table !== null && !in_array($table, $allowedTables, true)) {
    http_response_code(404);
    exit('Unknown table.');
}

/*  HANDLE DELETION  */
if (isset($_GET['delete']) && $table && isAdmin()) {
    $id = $_GET['delete'];
    $idCol = [
        'cars' => 'car_id',
        'clients' => 'client_id',
        'rentals' => 'rental_id',
        'maintenance' => 'maintenance_id',
        'payments' => 'payment_id',
        'staff' => 'staff_id'
    ][$table] ?? null;

    if ($idCol) {
        $stmt = $pdo->prepare("DELETE FROM $table WHERE $idCol = ? AND agency_id = ?");
        $stmt->execute([$id, $_SESSION['agency_id']]);
        header("Location: ?table=$table");
        exit;
    }
}
if (isset($_GET['mark_done']) && $table === 'maintenance' && (isAdmin() || isMechanic())) {
    $maintenanceId = (int)$_GET['mark_done'];
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT car_id FROM maintenance WHERE maintenance_id = ? AND agency_id = ?");
    $stmt->execute([$maintenanceId, $_SESSION['agency_id']]);
    $maintenanceCarId = $stmt->fetchColumn();
    if ($maintenanceCarId !== false) {
        $stmt = $pdo->prepare("UPDATE maintenance SET status = 'completed' WHERE maintenance_id = ? AND agency_id = ?");
        $stmt->execute([$maintenanceId, $_SESSION['agency_id']]);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM maintenance WHERE car_id = ? AND agency_id = ? AND status = 'in_progress'");
        $stmt->execute([$maintenanceCarId, $_SESSION['agency_id']]);
        if ((int)$stmt->fetchColumn() === 0) {
            $stmt = $pdo->prepare("
                UPDATE cars
                SET status = CASE WHEN EXISTS (
                    SELECT 1 FROM rentals
                    WHERE rentals.car_id = cars.car_id
                      AND rentals.agency_id = cars.agency_id
                      AND rentals.status = 'ongoing'
                      AND rentals.start_date <= CURDATE()
                      AND rentals.end_date >= CURDATE()
                ) THEN 'rented' ELSE 'available' END
                WHERE car_id = ? AND agency_id = ? AND status = 'maintenance'
            ");
            $stmt->execute([$maintenanceCarId, $_SESSION['agency_id']]);
        }
    }
    $pdo->commit();
    header("Location: ?table=maintenance");
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $table) {
    $agencyId = $_SESSION['agency_id'];
    if ($table == 'cars') {
        $imageUrlInput = $_POST['image_url'] ?? '';
        $imageUrl = is_scalar($imageUrlInput) ? trim((string)$imageUrlInput) : '';
        $imageUrl = $imageUrl === '' ? null : $imageUrl;
        if (isset($_POST['car_id']) && $_POST['car_id']) {
            $stmt = $pdo->prepare("UPDATE cars SET brand=?, model=?, year=?, license_plate=?, color=?, mileage=?, status=?, daily_price=?, image_url=? WHERE car_id=? AND agency_id=?");
            $stmt->execute([$_POST['brand'], $_POST['model'], $_POST['year'], $_POST['license_plate'], $_POST['color'], $_POST['mileage'], $_POST['status'], $_POST['daily_price'], $imageUrl, $_POST['car_id'], $agencyId]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO cars (brand, model, year, license_plate, color, mileage, status, daily_price, image_url, agency_id) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$_POST['brand'], $_POST['model'], $_POST['year'], $_POST['license_plate'], $_POST['color'], $_POST['mileage'], $_POST['status'], $_POST['daily_price'], $imageUrl, $agencyId]);
        }
    } elseif ($table == 'clients') {
        if (isset($_POST['client_id']) && $_POST['client_id']) {
            $stmt = $pdo->prepare("UPDATE clients SET first_name=?, last_name=?, email=?, phone=?, driver_license=?, address=? WHERE client_id=? AND agency_id=?");
            $stmt->execute([$_POST['first_name'], $_POST['last_name'], $_POST['email'], $_POST['phone'], $_POST['driver_license'], $_POST['address'], $_POST['client_id'], $agencyId]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO clients (first_name, last_name, email, phone, driver_license, address, agency_id) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$_POST['first_name'], $_POST['last_name'], $_POST['email'], $_POST['phone'], $_POST['driver_license'], $_POST['address'], $agencyId]);
        }
    } elseif ($table == 'rentals') {
        if (isset($_POST['rental_id']) && $_POST['rental_id']) {
            if ($_POST['status'] === 'completed') {
                $stmt = $pdo->prepare("CALL complete_rental(?, 0)");
                $stmt->execute([$_POST['rental_id']]);
            } else {
                $stmt = $pdo->prepare("UPDATE rentals SET client_id=?, car_id=?, start_date=?, end_date=?, total_price=?, status=? WHERE rental_id=? AND agency_id=?");
                $stmt->execute([$_POST['client_id'], $_POST['car_id'], $_POST['start_date'], $_POST['end_date'], $_POST['total_price'], $_POST['status'], $_POST['rental_id'], $agencyId]);
            }
        } else {
            // Check for overlapping rentals before inserting
            $overlapCheck = $pdo->prepare("
                SELECT COUNT(*) FROM rentals 
                WHERE car_id = ? 
                AND agency_id = ?
                AND status = 'ongoing'
                AND NOT (? < start_date OR ? > end_date)
            ");
            $overlapCheck->execute([
                $_POST['car_id'], 
                $agencyId, 
                $_POST['end_date'],
                $_POST['start_date']
            ]);
            $hasOverlap = $overlapCheck->fetchColumn() > 0;
            
            if ($hasOverlap) {
                $_SESSION['rental_error'] = "This car is already rented during the selected dates. Please choose different dates.";
                header("Location: ?table=rentals");
                exit;
            }
            
            $totalPrice = $_POST['total_price'];
            if (empty($totalPrice)) {
                $totalPrice = calculateRentalPrice($pdo, $_POST['car_id'], $_POST['start_date'], $_POST['end_date']);
            }
            $stmt = $pdo->prepare("INSERT INTO rentals (client_id, car_id, start_date, end_date, total_price, status, agency_id) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$_POST['client_id'], $_POST['car_id'], $_POST['start_date'], $_POST['end_date'], $totalPrice, 'ongoing', $agencyId]);
        }
    } elseif ($table == 'maintenance') {
        $mechanicId = isMechanic() ? $_SESSION['user']['staff_id'] : null;
        if (isset($_POST['maintenance_id']) && $_POST['maintenance_id']) {
            $stmt = $pdo->prepare("UPDATE maintenance SET car_id=?, description=?, cost=?, maintenance_date=? WHERE maintenance_id=? AND agency_id=?");
            $stmt->execute([$_POST['car_id'], $_POST['description'], $_POST['cost'], $_POST['maintenance_date'], $_POST['maintenance_id'], $agencyId]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO maintenance (car_id, description, cost, maintenance_date, mechanic_id, agency_id) VALUES (?,?,?,?,?,?)");
            $stmt->execute([$_POST['car_id'], $_POST['description'], $_POST['cost'], $_POST['maintenance_date'], $mechanicId, $agencyId]);
            $stmt = $pdo->prepare("UPDATE cars SET status = 'maintenance' WHERE car_id = ? AND agency_id = ?");
            $stmt->execute([$_POST['car_id'], $agencyId]);
        }
    } elseif ($table == 'staff' && isAdmin()) {
        if (isset($_POST['staff_id']) && $_POST['staff_id']) {
            if (!empty($_POST['password'])) {
                $stmt = $pdo->prepare("UPDATE staff SET first_name=?, last_name=?, email=?, phone=?, role=?, password_hash=? WHERE staff_id=? AND agency_id=?");
                $stmt->execute([$_POST['first_name'], $_POST['last_name'], $_POST['email'], $_POST['phone'], $_POST['role'], password_hash($_POST['password'], PASSWORD_DEFAULT), $_POST['staff_id'], $agencyId]);
            } else {
                $stmt = $pdo->prepare("UPDATE staff SET first_name=?, last_name=?, email=?, phone=?, role=? WHERE staff_id=? AND agency_id=?");
                $stmt->execute([$_POST['first_name'], $_POST['last_name'], $_POST['email'], $_POST['phone'], $_POST['role'], $_POST['staff_id'], $agencyId]);
            }
        } else {
            $stmt = $pdo->prepare("INSERT INTO staff (first_name, last_name, email, phone, role, password_hash, agency_id) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$_POST['first_name'], $_POST['last_name'], $_POST['email'], $_POST['phone'], $_POST['role'], password_hash($_POST['password'], PASSWORD_DEFAULT), $agencyId]);
        }
    }
    header("Location: ?table=$table");
    exit;
}
if (isset($_SESSION['agency_id']) && !isset($_SESSION['user']) && !isset($_GET['login'])) {
    header("Location: ?login");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Car Rental Agency</title>
<link rel="stylesheet" href="assets/app.css">
<link rel="stylesheet" href="assets/table.css">
</head>
<body>
<?php
function isActive($linkTable) {
    global $table;
    if ($linkTable === 'dashboard' && !$table) return 'active';
    if ($linkTable === $table) return 'active';
    return '';
}
?>
<?php if (isset($_SESSION['user'])): ?>
<nav class="navbar">
    <div class="brand"><?= htmlspecialchars($_SESSION['agency_name']) ?></div>
    <div class="nav-links">
        <?php if (!isMechanic()): ?>
            <a href="?" class="<?= isActive('dashboard') ?>">Dashboard</a>
            <a href="?table=cars" class="<?= isActive('cars') ?>">Cars</a>
            <a href="?table=clients" class="<?= isActive('clients') ?>">Clients</a>
            <a href="?table=rentals" class="<?= isActive('rentals') ?>">Rentals</a>
            <a href="?table=payments" class="<?= isActive('payments') ?>">Payments</a>
            <?php if (isAdmin()): ?>
            <a href="?table=staff" class="<?= isActive('staff') ?>">Staff</a>
            <?php endif; ?>
        <?php elseif (!isAgent()): ?>
            <a href="?table=maintenance" class="<?= isActive('maintenance') ?>">Maintenance</a>
        <?php endif; ?>
    </div>
    <div class="user-info">
        <span><?= htmlspecialchars($_SESSION['user']['first_name']) ?> (<?= $_SESSION['user']['role'] ?>)</span>
        <a href="?logout" class="btn-logout">Logout</a>
        <a href="?logout_agency" class="btn-logout" style="background:transparent; border-color:#5f6368; color:#5f6368;">Exit Agency</a>
    </div>
</nav>
<div style="padding: 20px;">
<?php else: ?>
    <div style="max-width: 400px; margin: 100px auto; text-align: center;">
    <h2 style="color: #1a73e8; font-weight: 500;"><?= htmlspecialchars($_SESSION['agency_name']) ?></h2>
<?php endif; ?>
<?php if (isset($_GET['login'])): ?>
<h3>Login</h3>
<form method="POST">
    <input type="email" name="email" placeholder="Email" required>
    <input type="password" name="password" placeholder="Password" required>
    <button name="login" type="submit" style="width:100%">Login</button>
</form>
<p style="color:#80868b; font-size:13px; margin-top:16px; line-height:1.6;">
    <strong>Demo Credentials:</strong><br>
    Admin Email: admin@admin.com | Password: admin123<br>
    Agent Email: agent@agent.com | Password: agent123<br>
    Mechanic Email: mechanic@mechanic.com | Password: mechanic123
</p>
<p><a href="?logout_agency" style="color:#1a73e8; font-size:0.9em; text-decoration:none; font-weight:500;">&larr; Switch Agency</a></p>
<p style="color:#5f6368; font-size:0.85em; margin-top:20px;">Don't have an account? Contact your agency administrator.</p>
</div>
</div>
<?php exit; endif; ?>
<?php if (!$table): ?>
    <?php
    $aid = $_SESSION['agency_id'];
    $availableCars = $pdo->prepare("SELECT COUNT(*) FROM cars WHERE status='available' AND agency_id=?"); $availableCars->execute([$aid]); $availableCars = $availableCars->fetchColumn();
    $maintenanceCars = $pdo->prepare("SELECT COUNT(*) FROM cars WHERE status='maintenance' AND agency_id=?"); $maintenanceCars->execute([$aid]); $maintenanceCars = $maintenanceCars->fetchColumn();
    $rentedCars = $pdo->prepare("SELECT COUNT(*) FROM cars WHERE status='rented' AND agency_id=?"); $rentedCars->execute([$aid]); $rentedCars = $rentedCars->fetchColumn();
    $totalClients = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE agency_id=?"); $totalClients->execute([$aid]); $totalClients = $totalClients->fetchColumn();
    $uncompletedPayments = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE status='pending' AND agency_id=?"); $uncompletedPayments->execute([$aid]); $uncompletedPayments = $uncompletedPayments->fetchColumn();
    $ongoingRentals = $pdo->prepare("SELECT COUNT(*) FROM rentals WHERE status='ongoing' AND agency_id=?"); $ongoingRentals->execute([$aid]); $ongoingRentals = $ongoingRentals->fetchColumn();
    $stmtRev = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE agency_id=? AND status='paid'");
    $stmtRev->execute([$aid]);
    $totalRevenue = $stmtRev->fetchColumn() ?: 0;
    ?>
    <h3 style="margin-top:20px; color:#202124; font-weight:500;">Car Fleet</h3>
    <div class="dashboard">
        <div class="card">
            <h3>Available Cars</h3>
            <div class="number"><?= $availableCars ?></div>
        </div>
        <div class="card maintenance">
            <h3>Cars in Maintenance</h3>
            <div class="number"><?= $maintenanceCars ?></div>
        </div>
        <div class="card rented">
            <h3>Rented Cars</h3>
            <div class="number"><?= $rentedCars ?></div>
        </div>
    </div>
    <h3 style="margin-top:30px; color:#202124; font-weight:500;">Business Overview</h3>
    <div class="dashboard">
        <div class="card clients">
            <h3>Total Clients</h3>
            <div class="number"><?= $totalClients ?></div>
        </div>
        <div class="card ongoing">
            <h3>Ongoing Rentals</h3>
            <div class="number"><?= $ongoingRentals ?></div>
        </div>
        <div class="card payments">
            <h3>Total Revenue</h3>
            <div class="number"><?= number_format($totalRevenue, 2) ?> dzd</div>
        </div>
    </div>
<?php else: ?>
<?php
$idColumns = [
    'cars' => 'car_id',
    'clients' => 'client_id',
    'rentals' => 'rental_id',
    'maintenance' => 'maintenance_id',
    'payments' => 'payment_id',
    'staff' => 'staff_id'
];
$idCol = $idColumns[$table];
$baseQueries = [
    'cars' => 'SELECT * FROM cars WHERE agency_id = ?',
    'clients' => 'SELECT * FROM clients WHERE agency_id = ?',
    'rentals' => "SELECT r.rental_id, cl.first_name, cl.last_name, c.brand, c.model,
                         r.start_date, r.end_date, r.total_price, r.status, r.created_at
                  FROM rentals r
                  JOIN clients cl ON r.client_id = cl.client_id
                  JOIN cars c ON r.car_id = c.car_id
                  WHERE r.agency_id = ?",
    'payments' => "SELECT p.payment_id, p.rental_id, p.amount, p.method, p.status, p.payment_date,
                          c.first_name AS client_first, c.last_name AS client_last,
                          cars.brand AS car_brand, cars.model AS car_model
                   FROM payments p
                   JOIN rentals r ON p.rental_id = r.rental_id
                   JOIN clients c ON r.client_id = c.client_id
                   JOIN cars ON r.car_id = cars.car_id
                   WHERE p.agency_id = ?",
    'maintenance' => "SELECT m.*, c.brand, c.model, c.license_plate
                      FROM maintenance m
                      JOIN cars c ON m.car_id = c.car_id
                      WHERE m.agency_id = ?",
    'staff' => 'SELECT staff_id, first_name, last_name, email, phone, role, created_at FROM staff WHERE agency_id = ?'
];
$numericColumns = [
    'cars' => ['car_id', 'year', 'mileage', 'daily_price', 'agency_id'],
    'clients' => ['client_id', 'agency_id'],
    'rentals' => ['rental_id', 'total_price'],
    'payments' => ['payment_id', 'rental_id', 'amount'],
    'maintenance' => ['maintenance_id', 'car_id', 'cost', 'mechanic_id', 'agency_id'],
    'staff' => ['staff_id']
];
$metaStmt = $pdo->prepare("SELECT * FROM ({$baseQueries[$table]}) AS records LIMIT 0");
$metaStmt->execute([$_SESSION['agency_id']]);
$columns = [];
for ($columnIndex = 0; $columnIndex < $metaStmt->columnCount(); $columnIndex++) {
    $columnMeta = $metaStmt->getColumnMeta($columnIndex);
    if ($columnMeta && isset($columnMeta['name'])) {
        $columns[] = $columnMeta['name'];
    }
}

$where = [];
$filterParams = [];
$submittedFilters = isset($_GET['filters']) && is_array($_GET['filters']) ? $_GET['filters'] : [];
$textOperators = ['starts', 'ends', 'contains', 'not_contains', 'null', 'not_null'];
$numberOperators = ['eq', 'neq', 'gt', 'lt', 'gte', 'lte'];
foreach ($columns as $column) {
    if (!isset($submittedFilters[$column]) || !is_array($submittedFilters[$column])) {
        continue;
    }
    $filter = $submittedFilters[$column];
    $isNumeric = in_array($column, $numericColumns[$table], true);
    $operator = $filter['op'] ?? ($isNumeric ? 'eq' : 'contains');
    $operator = is_scalar($operator) ? (string)$operator : '';
    $rawValue = $filter['value'] ?? '';
    $value = is_scalar($rawValue) ? trim((string)$rawValue) : '';
    if ($isNumeric) {
        if ($value === '' || !is_numeric($value) || !in_array($operator, $numberOperators, true)) {
            continue;
        }
        $sqlOperator = [
            'eq' => '=', 'neq' => '!=', 'gt' => '>', 'lt' => '<',
            'gte' => '>=', 'lte' => '<='
        ][$operator];
        $where[] = "`$column` $sqlOperator ?";
        $filterParams[] = $value;
        continue;
    }
    if ($operator === 'null' || $operator === 'not_null') {
        $where[] = "`$column` IS " . ($operator === 'null' ? '' : 'NOT ') . 'NULL';
        continue;
    }
    if ($value === '' || !in_array($operator, $textOperators, true)) {
        continue;
    }
    $terms = preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($terms as $term) {
        $columnText = "LOWER(CAST(`$column` AS CHAR))";
        $term = strtolower($term);
        if ($operator === 'starts') {
            $where[] = "LEFT($columnText, CHAR_LENGTH(?)) = ?";
            $filterParams[] = $term;
            $filterParams[] = $term;
        } elseif ($operator === 'ends') {
            $where[] = "RIGHT($columnText, CHAR_LENGTH(?)) = ?";
            $filterParams[] = $term;
            $filterParams[] = $term;
        } elseif ($operator === 'not_contains') {
            $where[] = "LOCATE(?, $columnText) = 0";
            $filterParams[] = $term;
        } else {
            $where[] = "LOCATE(?, $columnText) > 0";
            $filterParams[] = $term;
        }
    }
}
$whereSql = $where ? ' AND ' . implode(' AND ', $where) : '';
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM ({$baseQueries[$table]}) AS records WHERE 1=1$whereSql");
$countStmt->execute(array_merge([$_SESSION['agency_id']], $filterParams));
$totalRows = (int)$countStmt->fetchColumn();
$pageSizeOptions = [10, 25, 50, 100];
$pageSize = isset($_GET['per_page']) && is_scalar($_GET['per_page']) && in_array((int)$_GET['per_page'], $pageSizeOptions, true)
    ? (int)$_GET['per_page'] : 10;
$pageCount = max(1, (int)ceil($totalRows / $pageSize));
$requestedPage = isset($_GET['page']) && is_scalar($_GET['page']) ? (int)$_GET['page'] : 1;
$page = min(max(1, $requestedPage), $pageCount);
$sortColumn = isset($_GET['sort']) && in_array($_GET['sort'], $columns, true) ? $_GET['sort'] : null;
$sortSql = $sortColumn ? " ORDER BY `$sortColumn` ASC" : '';
$offset = ($page - 1) * $pageSize;
$dataStmt = $pdo->prepare("SELECT * FROM ({$baseQueries[$table]}) AS records WHERE 1=1$whereSql$sortSql LIMIT $pageSize OFFSET $offset");
$dataStmt->execute(array_merge([$_SESSION['agency_id']], $filterParams));
$rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);
$canManageTable = isAdmin() || (isAgent() && !in_array($table, ['cars', 'staff'], true));
?>
<?php include __DIR__ . '/includes/table_view.php'; ?>

<?php endif; ?>
</div>
<script src="assets/table.js" defer></script>
</body>
</html>
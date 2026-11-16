<?php
try {
    $pdo = new PDO("mysql:host=localhost;dbname=benazzouzmohamed", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "--- AGENCIES ---\n";
    $stm = $pdo->query("SELECT * FROM agencies");
    print_r($stm->fetchAll(PDO::FETCH_ASSOC));

    echo "\n--- STAFF ---\n";
    $stm = $pdo->query("SELECT staff_id, first_name, email, role, agency_id FROM staff");
    print_r($stm->fetchAll(PDO::FETCH_ASSOC));

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}

<form method="POST" class="record-form">
    <input type="hidden" name="<?= htmlspecialchars($idCol) ?>" value="<?= htmlspecialchars((string)($editData[$idCol] ?? '')) ?>">
    <?php if ($table === 'cars' && isAdmin()): ?>
        <input name="brand" placeholder="Brand" value="<?= htmlspecialchars((string)($editData['brand'] ?? '')) ?>" required>
        <input name="model" placeholder="Model" value="<?= htmlspecialchars((string)($editData['model'] ?? '')) ?>" required>
        <input name="year" type="number" placeholder="Year" value="<?= htmlspecialchars((string)($editData['year'] ?? '')) ?>" min="1930" max="<?= date('Y') ?>">
        <input name="license_plate" placeholder="License Plate" value="<?= htmlspecialchars((string)($editData['license_plate'] ?? '')) ?>" required>
        <input name="color" placeholder="Color" value="<?= htmlspecialchars((string)($editData['color'] ?? '')) ?>">
        <input name="mileage" type="number" placeholder="Mileage" value="<?= htmlspecialchars((string)($editData['mileage'] ?? 0)) ?>">
        <select name="status">
            <?php foreach (['available', 'rented', 'maintenance'] as $status): ?>
                <option value="<?= $status ?>" <?= ($editData['status'] ?? '') === $status ? 'selected' : '' ?>><?= ucfirst($status) ?></option>
            <?php endforeach; ?>
        </select>
        <input name="daily_price" type="number" step="0.01" placeholder="Daily Price" value="<?= htmlspecialchars((string)($editData['daily_price'] ?? '')) ?>" required>
        <input name="image_url" type="url" placeholder="Car image URL (https://...)" value="<?= htmlspecialchars((string)($editData['image_url'] ?? '')) ?>">
    <?php elseif ($table === 'clients'): ?>
        <input name="first_name" placeholder="First Name" value="<?= htmlspecialchars((string)($editData['first_name'] ?? '')) ?>" required>
        <input name="last_name" placeholder="Last Name" value="<?= htmlspecialchars((string)($editData['last_name'] ?? '')) ?>" required>
        <input name="email" type="email" placeholder="Email" value="<?= htmlspecialchars((string)($editData['email'] ?? '')) ?>" required>
        <input name="phone" placeholder="Phone" value="<?= htmlspecialchars((string)($editData['phone'] ?? '')) ?>">
        <input name="driver_license" placeholder="Driver License" value="<?= htmlspecialchars((string)($editData['driver_license'] ?? '')) ?>" required>
        <textarea name="address" placeholder="Address"><?= htmlspecialchars((string)($editData['address'] ?? '')) ?></textarea>
    <?php elseif ($table === 'rentals'): ?>
        <?php
        $stmtCars = $pdo->prepare("SELECT * FROM cars WHERE agency_id = ? AND status != 'maintenance'");
        $stmtCars->execute([$_SESSION['agency_id']]);
        $cars = $stmtCars->fetchAll(PDO::FETCH_ASSOC);
        $clients = getAll($pdo, 'clients');
        ?>
        <select name="client_id" required>
            <option value="">-- Select Client --</option>
            <?php foreach ($clients as $client): ?>
                <option value="<?= $client['client_id'] ?>" <?= ($editData['client_id'] ?? '') == $client['client_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($client['first_name'] . ' ' . $client['last_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="car_id" required>
            <option value="">-- Select Car --</option>
            <?php foreach ($cars as $car): ?>
                <option value="<?= $car['car_id'] ?>" <?= ($editData['car_id'] ?? '') == $car['car_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($car['brand'] . ' ' . $car['model'] . ' (' . $car['license_plate'] . ')') ?>
                </option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="start_date" value="<?= htmlspecialchars((string)($editData['start_date'] ?? '')) ?>" required>
        <input type="date" name="end_date" value="<?= htmlspecialchars((string)($editData['end_date'] ?? '')) ?>" required>
        <input type="number" step="0.01" name="total_price" placeholder="Total Price (leave empty to auto-calculate)" value="<?= htmlspecialchars((string)($editData['total_price'] ?? '')) ?>">
        <select name="status">
            <?php foreach (['ongoing', 'completed', 'cancelled'] as $status): ?>
                <option value="<?= $status ?>" <?= ($editData['status'] ?? '') === $status ? 'selected' : '' ?>><?= ucfirst($status) ?></option>
            <?php endforeach; ?>
        </select>
    <?php elseif ($table === 'maintenance'): ?>
        <?php $cars = getAll($pdo, 'cars'); ?>
        <select name="car_id" required>
            <option value="">-- Select Car --</option>
            <?php foreach ($cars as $car): ?>
                <?php $selectedCarId = $editData['car_id'] ?? $_GET['add_from_car'] ?? ''; ?>
                <option value="<?= $car['car_id'] ?>" <?= $selectedCarId == $car['car_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($car['brand'] . ' ' . $car['model'] . ' (' . $car['license_plate'] . ')') ?>
                </option>
            <?php endforeach; ?>
        </select>
        <textarea name="description" placeholder="Description"><?= htmlspecialchars((string)($editData['description'] ?? '')) ?></textarea>
        <input type="number" step="0.01" name="cost" placeholder="Cost" value="<?= htmlspecialchars((string)($editData['cost'] ?? '0.00')) ?>">
        <input type="date" name="maintenance_date" value="<?= htmlspecialchars((string)($editData['maintenance_date'] ?? date('Y-m-d'))) ?>">
    <?php elseif ($table === 'payments'): ?>
        <?php $rentals = getAll($pdo, 'rentals'); ?>
        <select name="rental_id" required>
            <option value="">-- Select Rental --</option>
            <?php foreach ($rentals as $rental): ?>
                <option value="<?= $rental['rental_id'] ?>" <?= ($editData['rental_id'] ?? '') == $rental['rental_id'] ? 'selected' : '' ?>>
                    Rental #<?= $rental['rental_id'] ?>
                </option>
            <?php endforeach; ?>
        </select>
        <input type="number" step="0.01" name="amount" placeholder="Amount" value="<?= htmlspecialchars((string)($editData['amount'] ?? '')) ?>" required>
        <select name="method" required>
            <?php foreach (['cash', 'credit_card', 'debit_card', 'bank_transfer'] as $method): ?>
                <option value="<?= $method ?>" <?= ($editData['method'] ?? '') === $method ? 'selected' : '' ?>>
                    <?= ucfirst(str_replace('_', ' ', $method)) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="status">
            <?php foreach (['paid', 'pending'] as $status): ?>
                <option value="<?= $status ?>" <?= ($editData['status'] ?? '') === $status ? 'selected' : '' ?>><?= ucfirst($status) ?></option>
            <?php endforeach; ?>
        </select>
    <?php elseif ($table === 'staff' && isAdmin()): ?>
        <input name="first_name" placeholder="First Name" value="<?= htmlspecialchars((string)($editData['first_name'] ?? '')) ?>" required>
        <input name="last_name" placeholder="Last Name" value="<?= htmlspecialchars((string)($editData['last_name'] ?? '')) ?>" required>
        <input type="email" name="email" placeholder="Email" value="<?= htmlspecialchars((string)($editData['email'] ?? '')) ?>" required>
        <input name="phone" placeholder="Phone" value="<?= htmlspecialchars((string)($editData['phone'] ?? '')) ?>">
        <select name="role" required>
            <?php foreach (['admin', 'agent', 'mechanic'] as $role): ?>
                <option value="<?= $role ?>" <?= ($editData['role'] ?? '') === $role ? 'selected' : '' ?>><?= ucfirst($role) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="password" name="password" placeholder="<?= isset($editData['staff_id']) ? 'New Password (leave empty to keep current)' : 'Password' ?>" <?= !isset($editData['staff_id']) ? 'required' : '' ?>>
    <?php endif; ?>
    <div class="form-actions">
        <button type="button" class="secondary-button dialog-close">Cancel</button>
        <button type="submit" class="primary-button">Save</button>
    </div>
</form>

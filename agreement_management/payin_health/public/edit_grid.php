<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
require '../../config/database.php';

$entry_id = $_GET['id'] ?? null;
if (!$entry_id) die("Error: Entry ID is required");

try {
    // Fetch entry details
    $stmt = $pdo->prepare("SELECT
        pg.*,
        b.br_name AS broker_name,
        c.c_name AS company_name,
        p.pl_name AS plan_name
        FROM ins_payin_grid_health pg
        LEFT JOIN brokers b ON pg.broker_code = b.br_id
        LEFT JOIN companies c ON pg.company_code = c.c_id
        LEFT JOIN plans p ON pg.plan_code = p.pl_id
        WHERE pg.id = ?");
    $stmt->execute([$entry_id]);
    $entry = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$entry) die("Error: Entry not found");

    // Fetch related data for dropdowns
    $brokers = $pdo->query("SELECT br_id, br_name FROM brokers")->fetchAll();
    $companies = $pdo->query("SELECT c_id, c_name FROM companies")->fetchAll();
    $plans = $pdo->query("SELECT pl_id, pl_name FROM plans")->fetchAll();

    // Determine payin category for health insurance (Base, Reward)
    $payin_category = $entry['category'] ?? '';
    $is_health = true; // This page is specifically for health insurance entries

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        // Validate required fields
        $errors = [];

        // Common validation for all health insurance categories
        $requiredFields = ['applicable_percentage', 'applicable_start', 'applicable_end'];
        $numericFields = ['applicable_percentage', 'premium_from', 'premium_to'];

        foreach ($requiredFields as $field) {
            if (empty($_POST[$field])) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . " is required";
            }
        }

        if (empty($errors)) {
            // Prepare data for update
            $sql = "UPDATE ins_payin_grid_health SET ";
            $params = ['id' => $entry_id];
            $updates = [];

            // Common fields for all health insurance categories
            $updates[] = "applicable_percentage = :applicable_percentage";
            $updates[] = "premium_from = :premium_from";
            $updates[] = "premium_to = :premium_to";
            $updates[] = "applicable_start = :applicable_start";
            $updates[] = "applicable_end = :applicable_end";
            $updates[] = "policy_start = :policy_start";
            $updates[] = "policy_end = :policy_end";

            $params['applicable_percentage'] = $_POST['applicable_percentage'] ?? null;
            $params['premium_from'] = $_POST['premium_from'] ?? null;
            $params['premium_to'] = $_POST['premium_to'] ?? null;
            $params['applicable_start'] = !empty($_POST['applicable_start']) ? $_POST['applicable_start'] : null;
            $params['applicable_end'] = !empty($_POST['applicable_end']) ? $_POST['applicable_end'] : null;
            $params['policy_start'] = !empty($_POST['policy_start']) ? date('Y-m-d', strtotime($_POST['policy_start'])) : null;
            $params['policy_end'] = !empty($_POST['policy_end']) ? date('Y-m-d', strtotime($_POST['policy_end'])) : null;

            // Additional fields
            $additionalFields = [
                'policy_combination', 'case_type', 'location',
                'age_from', 'age_to', 'slab_start', 'slab_end',
                'applicable_start', 'applicable_end', 'policy_start', 'policy_end'
            ];
            
            foreach ($additionalFields as $field) {
                if (isset($_POST[$field])) {
                    $updates[] = "$field = :$field";
                    $params[$field] = $_POST[$field] ?? null;
                }
            }

            $sql .= implode(", ", $updates);
            $sql .= " WHERE id = :id";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            header("Location: index.php?updated=1");
            exit;
        } else {
            $_SESSION['errors'] = $errors;
            $_SESSION['post_data'] = $_POST;
        }
    }
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Retrieve errors from session
$errors = $_SESSION['errors'] ?? [];
unset($_SESSION['errors']);

// Use posted data if available, otherwise use database values
$postData = $_SESSION['post_data'] ?? [];
unset($_SESSION['post_data']);

function getFieldValue($field, $default = '') {
    global $postData, $entry;
    return !empty($postData[$field]) ? htmlspecialchars($postData[$field]) :
           (isset($entry[$field]) ? htmlspecialchars($entry[$field]) : $default);
}

function isReadonly($field) {
    // Common readonly fields for all health insurance entries
    $always_readonly = [
        'product_name', 'subproduct_name', 'broker_code', 'company_code',
        'plan_code', 'ppt', 'pt', 'brokerage_type', 'case_type',
        'payin_percent', 'category'
    ];

    if (in_array($field, $always_readonly)) {
        return true;
    }
    
    // All other fields are editable for health insurance
    return false;
}
?>

<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="../css/payin_edit_style.css">
    <title>Edit Health Payin Grid Entry</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('form');

    form.addEventListener('submit', function(e) {
        // Validate date ranges
        const fromDate = document.querySelector('input[name="applicable_start"]');
        const toDate = document.querySelector('input[name="applicable_end"]');
        const policyStart = document.querySelector('input[name="policy_start"]');
        const policyEnd = document.querySelector('input[name="policy_end"]');

        // Validate applicable dates
        if (fromDate && toDate && fromDate.value && toDate.value) {
            const from = new Date(fromDate.value);
            const to = new Date(toDate.value);

            if (to < from) {
                alert('Applicable To date must be after Applicable From date');
                e.preventDefault();
                return false;
            }
        }

        // Validate policy dates
        if (policyStart && policyEnd && policyStart.value && policyEnd.value) {
            const start = new Date(policyStart.value);
            const end = new Date(policyEnd.value);

            if (end < start) {
                alert('Policy End date must be after Policy Start date');
                e.preventDefault();
                return false;
            }
        }

        return true;
    });

    // Disable all non-editable fields to prevent modification
    document.querySelectorAll('input[readonly], select[disabled]').forEach(field => {
        field.addEventListener('keydown', function(e) {
            e.preventDefault();
            return false;
        });
    });
});
    </script>
</head>
<body>
    <div class="container">
        <?php if (!empty($errors)): ?>
            <div class="error-alert">
                <?php foreach ($errors as $error): ?>
                    <p><?= $error ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <h2>Edit Health Payin Grid Entry #<?= $entry_id ?> (<?= $payin_category ?>)</h2>
        <a href="index.php" class="back-btn">← Back to List</a>

        <form method="POST" enctype="multipart/form-data">
            <div class="form-section">
                <h3>Basic Information</h3>
                <div class="form-grid">
                    <div>
                        <label>Product Name</label>
                        <input type="text" name="product_name" value="<?= getFieldValue('product_name') ?>" readonly class="readonly-field">
                    </div>
                    <div>
                        <label>Subproduct Name</label>
                        <input type="text" name="subproduct_name" value="<?= getFieldValue('subproduct_name') ?>" readonly class="readonly-field">
                    </div>
                    <div>
                        <label>Broker</label>
                        <select name="broker_code" disabled class="readonly-field">
                            <option value="">Select Broker</option>
                            <?php foreach ($brokers as $broker): ?>
                                <option value="<?= $broker['br_id'] ?>"
                                    <?= $broker['br_id'] == getFieldValue('broker_code') ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($broker['br_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Company</label>
                        <select name="company_code" disabled class="readonly-field">
                            <option value="">Select Company</option>
                            <?php foreach ($companies as $company): ?>
                                <option value="<?= $company['c_id'] ?>"
                                    <?= $company['c_id'] == getFieldValue('company_code') ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($company['c_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Plan</label>
                        <select name="plan_code" disabled class="readonly-field">
                            <option value="">Select Plan</option>
                            <?php foreach ($plans as $plan): ?>
                                <option value="<?= $plan['pl_id'] ?>"
                                    <?= $plan['pl_id'] == getFieldValue('plan_code') ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($plan['pl_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Policy Combination</label>
                        <input type="text" name="policy_combination" value="<?= getFieldValue('policy_combination') ?>" <?= isReadonly('policy_combination') ? 'readonly class="readonly-field"' : '' ?>>
                    </div>
                    <div>
                        <label>Case Type</label>
                        <input type="text" name="case_type" value="<?= getFieldValue('case_type') ?>" <?= isReadonly('case_type') ? 'readonly class="readonly-field"' : '' ?>>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h3>Brokerage Information</h3>
                <div class="form-grid">
                    <div>
                        <label>Payin Category</label>
                        <select name="category" disabled class="readonly-field">
                            <option value="Base" <?= getFieldValue('category') == 'Base' ? 'selected' : '' ?>>Base</option>
                            <option value="Reward" <?= getFieldValue('category') == 'Reward' ? 'selected' : '' ?>>Reward</option>
                        </select>
                    </div>
                    <div>
                        <label>Location</label>
                        <input type="text" name="location" value="<?= getFieldValue('location') ?>" <?= isReadonly('location') ? 'readonly class="readonly-field"' : '' ?>>
                    </div>
                    <div>
                        <label>Applicable Percentage*</label>
                        <input type="number" name="applicable_percentage" step="0.01" value="<?= getFieldValue('applicable_percentage') ?>">
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h3>Applicable Period</h3>
                <div class="form-grid">
                    <div>
                        <label>Applicable Start</label>
                        <input type="date" name="applicable_start" value="<?= getFieldValue('applicable_start') ?>">
                    </div>
                    <div>
                        <label>Applicable End</label>
                        <input type="date" name="applicable_end" value="<?= getFieldValue('applicable_end') ?>">
                    </div>
                    <div>
                        <label>Policy Start</label>
                        <input type="date" name="policy_start" value="<?= !empty($entry['policy_start']) ? date('Y-m-d', strtotime($entry['policy_start'])) : '' ?>">
                    </div>
                    <div>
                        <label>Policy End</label>
                        <input type="date" name="policy_end" value="<?= !empty($entry['policy_end']) ? date('Y-m-d', strtotime($entry['policy_end'])) : '' ?>">
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h3>Age Range</h3>
                <div class="form-grid">
                    <div>
                        <label>Age From</label>
                        <input type="number" name="age_from" step="1" value="<?= getFieldValue('age_from') ?>">
                    </div>
                    <div>
                        <label>Age To</label>
                        <input type="number" name="age_to" step="1" value="<?= getFieldValue('age_to') ?>">
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h3>Slab Range</h3>
                <div class="form-grid">
                    <div>
                        <label>Slab Start</label>
                        <input type="number" name="slab_start" step="0.01" value="<?= getFieldValue('slab_start') ?>">
                    </div>
                    <div>
                        <label>Slab End</label>
                        <input type="number" name="slab_end" step="0.01" value="<?= getFieldValue('slab_end') ?>">
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h3>Premium Range</h3>
                <div class="form-grid">
                    <div>
                        <label>Premium From</label>
                        <input type="number" name="premium_from" step="0.01" value="<?= getFieldValue('premium_from') ?>" readonly class="readonly-field">
                    </div>
                    <div>
                        <label>Premium To</label>
                        <input type="number" name="premium_to" step="0.01" value="<?= getFieldValue('premium_to') ?>" readonly class="readonly-field">
                    </div>
                            </div>
                        </div>

            <div class="form-section">
                <h3>Commission Statement</h3>
                <div class="form-grid">
                    <div>
                        <label>Current Commission Statement</label>
                        <?php if (!empty($entry['commission_statement'])): ?>
                            <div class="file-info">
                                <a href="download_commission.php?id=<?= $entry_id ?>" target="_blank">Download Current File</a>
                            </div>
                        <?php else: ?>
                            <div class="file-info">No file uploaded</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="save-btn">Save Changes</button>
                <a href="index.php" class="cancel-btn">Cancel</a>
            </div>
        </form>
    </div>
</body>
</html>
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
        fr.rname AS partner_name
        FROM payout_grid pg
        LEFT JOIN first_register fr ON pg.partner_finqy_id = fr.refercode
        WHERE pg.id = ?");
    $stmt->execute([$entry_id]);
    $entry = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$entry) die("Error: Entry not found");

    // Fetch related data for dropdowns
    $partners = $pdo->query("SELECT refercode, rname FROM first_register")->fetchAll();
    $companies = $pdo->query("SELECT c_id, c_name FROM companies")->fetchAll();
    $plans = $pdo->query("SELECT pl_id, pl_name FROM plans")->fetchAll();

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        // Validate required fields
        $errors = [];
        $requiredFields = [
            'case_type', 'brokerage_from', 'brokerage_to',
            'premium_from', 'premium_to', 'applicable_percentage'
        ];

        foreach ($requiredFields as $field) {
            if (empty($_POST[$field])) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . " is required";
            }
        }

        if (empty($errors)) {
            // Prepare data for update
            $sql = "UPDATE payout_grid SET 
                case_type = :case_type,
                brokerage_from = :brokerage_from,
                brokerage_to = :brokerage_to,
                premium_from = :premium_from,
                premium_to = :premium_to,
                applicable_percentage = :applicable_percentage
                WHERE id = :id";

            $params = [
                'case_type' => $_POST['case_type'],
                'brokerage_from' => $_POST['brokerage_from'],
                'brokerage_to' => $_POST['brokerage_to'],
                'premium_from' => $_POST['premium_from'],
                'premium_to' => $_POST['premium_to'],
                'applicable_percentage' => $_POST['applicable_percentage'],
                'id' => $entry_id
            ];

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
    $readonlyFields = [
        'product_name', 'subproduct_name', 'partner_finqy_id',
        'partner_name', 'company_code', 'plan_code', 'ppt', 'pt'
    ];
    return in_array($field, $readonlyFields);
}
?>

<!DOCTYPE html>
<html>
<head>
        <link rel="stylesheet" href="../css/payout_edit_style.css">

    <title>Edit Payout Grid Entry</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('form');

            form.addEventListener('submit', function(e) {
                // Validate date ranges
                const fromDate = document.querySelector('input[name="brokerage_from"]');
                const toDate = document.querySelector('input[name="brokerage_to"]');

                if (fromDate && toDate && fromDate.value && toDate.value) {
                    const from = new Date(fromDate.value);
                    const to = new Date(toDate.value);

                    if (to < from) {
                        alert('Applicable To date must be after Applicable From date');
                        e.preventDefault();
                        return false;
                    }
                }

                // Validate premium range
                const premiumFrom = document.querySelector('input[name="premium_from"]');
                const premiumTo = document.querySelector('input[name="premium_to"]');

                if (premiumFrom && premiumTo && premiumFrom.value && premiumTo.value) {
                    if (parseFloat(premiumTo.value) < parseFloat(premiumFrom.value)) {
                        alert('Premium To must be greater than or equal to Premium From');
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

        <h2>Edit Payout Grid Entry #<?= $entry_id ?></h2>
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
                        <label>Partner Finqy ID</label>
                        <input type="text" name="partner_finqy_id" value="<?= getFieldValue('partner_finqy_id') ?>" readonly class="readonly-field">
                    </div>
                    <div>
                        <label>Partner Name</label>
                        <input type="text" name="partner_name" value="<?= getFieldValue('partner_name') ?>" readonly class="readonly-field">
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
                        <label>PPT</label>
                        <input type="number" name="ppt" step="0.01" value="<?= getFieldValue('ppt') ?>" readonly class="readonly-field">
                    </div>
                    <div>
                        <label>PT</label>
                        <input type="number" name="pt" step="0.01" value="<?= getFieldValue('pt') ?>" readonly class="readonly-field">
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h3>Commission Information</h3>
                <div class="form-grid">
                    <div>
                        <label>Case Type*</label>
                        <input type="text" name="case_type" value="<?= getFieldValue('case_type') ?>">
                    </div>
                    <div>
                        <label>Applicable From*</label>
                        <input type="date" name="brokerage_from" value="<?= getFieldValue('brokerage_from') ?>">
                    </div>
                    <div>
                        <label>Applicable To*</label>
                        <input type="date" name="brokerage_to" value="<?= getFieldValue('brokerage_to') ?>">
                    </div>
                    <div>
                        <label>Premium From*</label>
                        <input type="number" name="premium_from" step="1" value="<?= getFieldValue('premium_from') ?>" readonly class="readonly-field">
                    </div>
                    <div>
                        <label>Premium To*</label>
                        <input type="number" name="premium_to" step="1" value="<?= getFieldValue('premium_to') ?>" readonly class="readonly-field">
                    </div>
                    <div>
                        <label>Applicable Percentage*</label>
                        <input type="number" name="applicable_percentage" step="0.01" min= "0" value="<?= getFieldValue('applicable_percentage') ?>">
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
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
        FROM payin_grid pg
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

    // Determine brokerage type for field control
    $brokerage_type = $entry['category'] ?? '';
    $is_base_orc = in_array($brokerage_type, ['Base', 'ORC']);
    $is_incentive = ($brokerage_type == 'Incentive');
    $is_contest = ($brokerage_type == 'Contest');

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        // Validate required fields
// Validate required fields
$errors = [];

// Validate fields based on brokerage type
if ($is_base_orc) {
    // Base/ORC validation
    $requiredFields = ['applicable_percentage', 'brokerage_from', 'brokerage_to'];
    $numericFields = ['applicable_percentage', 'premium_from', 'premium_to'];

    foreach ($requiredFields as $field) {
        if (empty($_POST[$field])) {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . " is required";
        }
    }
} elseif ($is_incentive) {
    // Incentive validation
    $requiredFields = ['target_amount', 'applicable_percentage', 'incentive_from', 'incentive_to'];
    $numericFields = ['target_amount', 'applicable_percentage'];

    foreach ($requiredFields as $field) {
        if (empty($_POST[$field])) {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . " is required";
        }
    }
} elseif ($is_contest) {
    // Contest validation
    $requiredFields = ['target_amount', 'no_of_tickets', 'contest_from', 'contest_to'];
    $numericFields = ['target_amount', 'no_of_tickets'];

    foreach ($requiredFields as $field) {
        if (empty($_POST[$field])) {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . " is required";
        }
    }
}

        if (empty($errors)) {
            // Prepare data for update based on brokerage type
           // Build the update query dynamically based on brokerage type
$sql = "UPDATE payin_grid SET ";
$params = ['id' => $entry_id];
$updates = [];

if ($is_base_orc) {
    $updates[] = "applicable_percentage = :applicable_percentage";
    $updates[] = "premium_from = :premium_from";
    $updates[] = "premium_to = :premium_to";
    $updates[] = "brokerage_from = :brokerage_from";
    $updates[] = "brokerage_to = :brokerage_to";

    $params['applicable_percentage'] = $_POST['applicable_percentage'] ?? null;
    $params['premium_from'] = $_POST['premium_from'] ?? null;
    $params['premium_to'] = $_POST['premium_to'] ?? null;
    $params['brokerage_from'] = !empty($_POST['brokerage_from']) ? $_POST['brokerage_from'] : null;
    $params['brokerage_to'] = !empty($_POST['brokerage_to']) ? $_POST['brokerage_to'] : null;
} elseif ($is_incentive) {
    $updates[] = "incentive_from = :incentive_from";
    $updates[] = "incentive_to = :incentive_to";
    $updates[] = "target_amount = :target_amount";
    $updates[] = "applicable_percentage = :applicable_percentage";

    $params['incentive_from'] = !empty($_POST['incentive_from']) ? $_POST['incentive_from'] : null;
    $params['incentive_to'] = !empty($_POST['incentive_to']) ? $_POST['incentive_to'] : null;
    $params['target_amount'] = $_POST['target_amount'] ?? null;
    $params['applicable_percentage'] = $_POST['applicable_percentage'] ?? null;
} elseif ($is_contest) {
    $updates[] = "contest_from = :contest_from";
    $updates[] = "contest_to = :contest_to";
    $updates[] = "target_amount = :target_amount";
    $updates[] = "no_of_tickets = :no_of_tickets";

    $params['contest_from'] = !empty($_POST['contest_from']) ? $_POST['contest_from'] : null;
    $params['contest_to'] = !empty($_POST['contest_to']) ? $_POST['contest_to'] : null;
    $params['target_amount'] = $_POST['target_amount'] ?? null;
    $params['no_of_tickets'] = $_POST['no_of_tickets'] ?? null;
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
    // For 'brokerage_from' and 'brokerage_to' fields, use the correct column names from the database
    // based on the brokerage type for initial display.
    global $brokerage_type; // Make $brokerage_type available in this function

    $actual_field_name = $field; // Default to the requested field name

    if ($brokerage_type == 'Incentive') {
        if ($field == 'brokerage_from') {
            $actual_field_name = 'incentive_from';
        } elseif ($field == 'brokerage_to') {
            $actual_field_name = 'incentive_to';
        } elseif ($field == 'target_amount') {
            $actual_field_name = 'target_amount';
        } elseif ($field == 'applicable_percentage') {
            $actual_field_name = 'applicable_percentage';
        }
    } elseif ($brokerage_type == 'Contest') {
        if ($field == 'brokerage_from') {
            $actual_field_name = 'contest_from';
        } elseif ($field == 'brokerage_to') {
            $actual_field_name = 'contest_to';
        } elseif ($field == 'target_amount') {
            $actual_field_name = 'target_amount';
        }
    }

    return !empty($postData[$field]) ? htmlspecialchars($postData[$field]) :
           (isset($entry[$actual_field_name]) ? htmlspecialchars($entry[$actual_field_name]) : $default);
}


// Function to determine if a field should be readonly
function isReadonly($field) {
    global $is_base_orc, $is_incentive, $is_contest;

    // Common readonly fields for all types
    $always_readonly = [
        'product_name', 'subproduct_name', 'broker_code', 'company_code',
        'plan_code', 'ppt', 'pt', 'brokerage_type', 'case_type',
        'payin_percent', 'incentive_payin_percent',
        'premium_from', 'premium_to' // Premium range is only for Base/ORC
    ];

    if (in_array($field, $always_readonly)) {
        return true;
    }

    // Field-specific logic based on brokerage type
    if ($is_base_orc) {
        $editable_fields = ['applicable_percentage', 'premium_from', 'premium_to', 'brokerage_from', 'brokerage_to'];
        return !in_array($field, $editable_fields);
    } elseif ($is_incentive) {
        // Only these fields should be editable for Incentive
        $editable_fields = ['target_amount', 'applicable_percentage', 'incentive_from', 'incentive_to'];
        return !in_array($field, $editable_fields);
    } elseif ($is_contest) {
        // Only these fields should be editable for Contest
        $editable_fields = ['target_amount', 'no_of_tickets', 'contest_from', 'contest_to'];
        return !in_array($field, $editable_fields);
    }

    return true; // Default to readonly if no specific rule applies
}
?>

<!DOCTYPE html>
<html>
<head>
        <link rel="stylesheet" href="../css/payin_edit_style.css">

    <title>Edit Payin Grid Entry</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <script>
        function toggleBrokerageFields() {
            const brokerageType = "<?= $brokerage_type ?>";

            // Hide all conditional sections first
            document.querySelectorAll('.conditional-section').forEach(section => {
                section.style.display = 'none';
            });

            // Show relevant section based on brokerage type
            if (brokerageType === 'Base' || brokerageType === 'ORC') {
                document.getElementById('standard-brokerage').style.display = 'block';
            } else if (brokerageType === 'Incentive') {
                document.getElementById('incentive-brokerage').style.display = 'block';
            } else if (brokerageType === 'Contest') {
                document.getElementById('contest-brokerage').style.display = 'block';
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            toggleBrokerageFields();
        });

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

        <h2>Edit Payin Grid Entry #<?= $entry_id ?> (<?= $brokerage_type ?>)</h2>
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
                    <?php if ($is_base_orc): // Show only for Base/ORC ?>
                    <div>
                        <label>PPT</label>
                        <input type="number" name="ppt" step="0.01" value="<?= getFieldValue('ppt') ?>" readonly class="readonly-field">
                    </div>
                    <?php endif; ?>
                    <?php if ($is_base_orc): // Show only for Base/ORC ?>
                    <div>
                        <label>PT</label>
                        <input type="number" name="pt" step="0.01" value="<?= getFieldValue('pt') ?>" readonly class="readonly-field">
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-section">
                <h3>Brokerage Information</h3>
                <div class="form-grid">
                    <div>
                        <label>Brokerage Type</label>
                        <select name="brokerage_type" id="brokerage_type" disabled class="readonly-field">
                            <option value="">Select Type</option>
                            <option value="Base" <?= getFieldValue('category') == 'Base' ? 'selected' : '' ?>>Base</option>
                            <option value="ORC" <?= getFieldValue('category') == 'ORC' ? 'selected' : '' ?>>ORC</option>
                            <option value="Incentive" <?= getFieldValue('category') == 'Incentive' ? 'selected' : '' ?>>Incentive</option>
                            <option value="Contest" <?= getFieldValue('category') == 'Contest' ? 'selected' : '' ?>>Contest</option>
                        </select>
                    </div>
                    <?php if ($is_base_orc): // Show only for Base/ORC ?>
                    <div>
                        <label>Case Type</label>
                        <input type="text" name="case_type" value="<?= getFieldValue('case_type') ?>" readonly class="readonly-field">
                    </div>
                    <?php endif; ?>
                    <?php if ($is_incentive || $is_contest): // Display only for Incentive/Contest ?>
                    <div>
                        <label>Target Amount*</label>
                        <input type="number" name="target_amount" step="1" value="<?= getFieldValue('target_amount') ?>" >
                    </div>
                    <?php endif; ?>
                    <?php if ($is_contest): // Display only for Contest ?>
                    <div>
                        <label>No. of Tickets</label>
                        <input type="number" name="no_of_tickets" value="<?= getFieldValue('no_of_tickets') ?>" <?= isReadonly('no_of_tickets') ? 'readonly class="readonly-field"' : '' ?>>
                    </div>
                    <?php endif; ?>
                     <?php if ($is_base_orc || $is_incentive): // Display only for Base/ORC/Incentive ?>
                    <div>
                        <label>Applicable Percentage</label>
                        <input type="number" name="applicable_percentage" step="0.01" value="<?= getFieldValue('applicable_percentage') ?>" <?= isReadonly('applicable_percentage') ? 'readonly class="readonly-field"' : '' ?>>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-section conditional-section" id="standard-brokerage">
                <h3>Standard Brokerage Period</h3>
                <div class="form-grid">
                    <div>
                        <label>Applicable From</label>
                        <input type="date" name="brokerage_from" value="<?= getFieldValue('brokerage_from') ?>" <?= isReadonly('brokerage_from') ? 'readonly class="readonly-field"' : '' ?>>
                    </div>
                    <div>
                        <label>Applicable To</label>
                        <input type="date" name="brokerage_to" value="<?= getFieldValue('brokerage_to') ?>" <?= isReadonly('brokerage_to') ? 'readonly class="readonly-field"' : '' ?>>
                    </div>
                </div>
            </div>

            <div class="form-section conditional-section" id="incentive-brokerage">
                <h3>Incentive Details</h3>
                <div class="form-grid">
                    <div>
                        <label>Incentive From*</label>
                        <input type="date" name="incentive_from" value="<?= getFieldValue('incentive_from') ?>" >
                    </div>
                    <div>
                        <label>Incentive To*</label>
                        <input type="date" name="incentive_to" value="<?= getFieldValue('incentive_to') ?>">
                    </div>
                </div>
            </div>

            <div class="form-section conditional-section" id="contest-brokerage">
                <h3>Contest Details</h3>
                <div class="form-grid">
                    <div>
                        <label>Contest From*</label>
                        <input type="date" name="contest_from" value="<?= getFieldValue('contest_from') ?>" >
                    </div>
                    <div>
                        <label>Contest To*</label>
                        <input type="date" name="contest_to" value="<?= getFieldValue('contest_to') ?>">
        </div>
    </div>
</div>

<?php if ($is_base_orc): // Show only for Base/ORC ?>
<div class="form-section">
    <h3>Premium Range</h3>
    <div class="form-grid">
        <div>
            <label>Premium From</label>
            <input type="number" name="premium_from" step="0.01" value="<?= getFieldValue('premium_from') ?>" <?= isReadonly('premium_from') ? 'readonly class="readonly-field"' : '' ?>>
        </div>
        <div>
            <label>Premium To</label>
            <input type="number" name="premium_to" step="0.01" value="<?= getFieldValue('premium_to') ?>" <?= isReadonly('premium_to') ? 'readonly class="readonly-field"' : '' ?>>
        </div>
    </div>
</div>
<?php endif; ?>

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
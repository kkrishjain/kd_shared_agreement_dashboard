<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
require '../config/database.php';

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get repository ID
$repo_id = $_GET['id'] ?? null;
if (!$repo_id) die("Error: Repository ID is required");

try {
    // Fetch repository details
    $stmt = $pdo->prepare("SELECT * FROM billing_repository WHERE id = ?");
    $stmt->execute([$repo_id]);
    $repository = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$repository) die("Error: Repository not found");

    // Normalize repository_type for consistency
    $repository['repository_type'] = strtolower(trim($repository['repository_type']));

    // Fetch existing emails
    $stmt = $pdo->prepare("SELECT email FROM billing_repository_email WHERE repository_id = ?");
    $stmt->execute([$repo_id]);
    $existing_emails = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

    // Fetch brokers and companies for dropdowns
    $brokers = $pdo->query("SELECT br_id, br_name FROM brokers WHERE br_status = '1'")->fetchAll(PDO::FETCH_ASSOC);
    $companies = $pdo->query("SELECT c_id, c_name FROM companies WHERE c_status = '1'")->fetchAll(PDO::FETCH_ASSOC);

    // Handle form submission
    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        $errors = [];

        // Validate CSRF token
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
            $errors[] = "Invalid CSRF token";
        }

        // Validate repository type
        $submitted_type = strtolower(trim($_POST['repository_type'] ?? ''));
        if ($submitted_type !== $repository['repository_type']) {
            $errors[] = "Repository type cannot be changed (submitted: '$submitted_type', expected: '{$repository['repository_type']}')";
        }

        // Collect and validate emails
        $submitted_emails = array_filter(
            array_map('trim', $_POST['emails'] ?? []), 
            function($email) {
                return !empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL);
            }
        );

        // Check if emails have changed
        // Change the emails_changed check to:
        $emails_changed = !empty(array_diff($submitted_emails, $existing_emails)) || 
                        !empty(array_diff($existing_emails, $submitted_emails));
                        
        // Validate emails only if they have changed or if no existing emails
        if ($emails_changed || empty($existing_emails)) {
            if (empty($submitted_emails)) {
                $errors[] = "At least one valid email is required";
            }
            if (count($submitted_emails) > 12) {
                $errors[] = "Cannot add more than 12 emails";
            }
        } else {
            // If emails haven't changed, use existing emails
            $submitted_emails = $existing_emails;
        }

        // Initialize data for update
        $data = [
            'repository_type' => $repository['repository_type'],
            'entity_name' => null,
            'broker_id' => null,
            'company_id' => null,
            'company_all' => null,
            'address' => null,
            'gst_no' => null,
            'pan_no' => null,
            'pin_code' => null,
            'city' => null,
            'state' => null,
            'tan_no' => null,
            'created_by' => $_SESSION['user_id'] ?? 1
        ];

        // Type-specific validation and data mapping
        if ($repository['repository_type'] === 'internal') {
            $required_fields = ['entity_name', 'internal_address', 'internal_gst_no', 'pin_code', 'internal_pan_no'];
            foreach ($required_fields as $field) {
                if (empty(trim($_POST[$field] ?? ''))) {
                    $errors[] = "Required field missing: " . str_replace('internal_', '', $field);
                }
            }

            // Validate entity name (alphanumeric and spaces only)
            if (!empty($_POST['entity_name']) && !preg_match('/^[A-Za-z0-9 ]+$/', $_POST['entity_name'])) {
                $errors[] = "Entity name must contain only letters, numbers and spaces";
            }

            // Validate pin code
            if (!empty($_POST['pin_code']) && !preg_match('/^\d{6}$/', $_POST['pin_code'])) {
                $errors[] = "Invalid pin code format";
            }

            // Validate GST No.
            if (!empty($_POST['internal_gst_no']) && !preg_match('/^[A-Za-z0-9]+$/', $_POST['internal_gst_no'])) {
                $errors[] = "GST No. must contain only letters and numbers";
            }

            // Validate PAN No.
            if (!empty($_POST['internal_pan_no']) && !preg_match('/^[A-Za-z0-9]+$/', $_POST['internal_pan_no'])) {
                $errors[] = "PAN No. must contain only letters and numbers";
            }

            // Validate TAN No.
            if (!empty($_POST['internal_tan_no']) && !preg_match('/^[A-Za-z0-9]+$/', $_POST['internal_tan_no'])) {
                $errors[] = "TAN No. must contain only letters and numbers";
            }

            // Map internal fields
            if (empty($errors)) {
                $data['entity_name'] = $_POST['entity_name'];
                $data['address'] = $_POST['internal_address'];
                $data['gst_no'] = $_POST['internal_gst_no'];
                $data['pan_no'] = $_POST['internal_pan_no'];
                $data['pin_code'] = $_POST['pin_code'];
                $data['city'] = $_POST['city'];
                $data['state'] = $_POST['state'];
                $data['tan_no'] = $_POST['internal_tan_no'] ?? null;
            }
        } elseif ($repository['repository_type'] === 'external') {
            $required_fields = ['broker_id', 'company_id', 'external_address', 'external_gst_no', 'external_pan_no'];
            foreach ($required_fields as $field) {
                if (empty(trim($_POST[$field] ?? ''))) {
                    $errors[] = "Required field missing: " . str_replace('external_', '', $field);
                }
            }

            // Handle company_id and company_all
            $company_id = $_POST['company_id'] ?? null;
            $data['company_all'] = ($company_id === 'all') ? 'all' : null;
            $data['company_id'] = ($company_id === 'all') ? null : $company_id;

            // Validate broker exists
            if (!empty($_POST['broker_id'])) {
                $stmt = $pdo->prepare("SELECT br_id FROM brokers WHERE br_id = ? AND br_status = '1'");
                $stmt->execute([$_POST['broker_id']]);
                if (!$stmt->fetch()) {
                    $errors[] = "Invalid broker selected";
                }
            }

            // Validate company exists if not 'all'
            if ($company_id !== 'all' && !empty($company_id)) {
                $stmt = $pdo->prepare("SELECT c_id FROM companies WHERE c_id = ? AND c_status = '1'");
                $stmt->execute([$company_id]);
                if (!$stmt->fetch()) {
                    $errors[] = "Invalid company selected";
                }
            }

            // Validate GST No.
            if (!empty($_POST['external_gst_no']) && !preg_match('/^[A-Za-z0-9]+$/', $_POST['external_gst_no'])) {
                $errors[] = "GST No. must contain only letters and numbers";
            }

            // Validate PAN No.
            if (!empty($_POST['external_pan_no']) && !preg_match('/^[A-Za-z0-9]+$/', $_POST['external_pan_no'])) {
                $errors[] = "PAN No. must contain only letters and numbers";
            }

            // Validate TAN No.
            if (!empty($_POST['external_tan_no']) && !preg_match('/^[A-Za-z0-9]+$/', $_POST['external_tan_no'])) {
                $errors[] = "TAN No. must contain only letters and numbers";
            }

            // Map external fields
            if (empty($errors)) {
                $data['broker_id'] = $_POST['broker_id'];
                $data['company_id'] = $data['company_id'];
                $data['company_all'] = $data['company_all'];
                $data['address'] = $_POST['external_address'];
                $data['gst_no'] = $_POST['external_gst_no'];
                $data['pan_no'] = $_POST['external_pan_no'];
                $data['pin_code'] = $_POST['external_pin_code'] ?? null;
                $data['city'] = $_POST['external_city'] ?? null;
                $data['state'] = $_POST['external_state'] ?? null;
                $data['tan_no'] = $_POST['external_tan_no'] ?? null;
            }
        }

        // Redirect on errors
        if (!empty($errors)) {
            $_SESSION['errors'] = $errors;
            header("Location: edit_repo.php?id=$repo_id");
            exit;
        }

        // Update repository
        $pdo->beginTransaction();
        
        try {
            // Update billing_repository
            $stmt = $pdo->prepare("UPDATE billing_repository SET
                repository_type = ?, entity_name = ?, broker_id = ?, company_id = ?, company_all = ?,
                address = ?, gst_no = ?, pan_no = ?, pin_code = ?, city = ?, state = ?,
                tan_no = ?, created_by = ?
                WHERE id = ?");
            $stmt->execute([
                $data['repository_type'],
                $data['entity_name'],
                $data['broker_id'],
                $data['company_id'],
                $data['company_all'],
                $data['address'],
                $data['gst_no'],
                $data['pan_no'],
                $data['pin_code'],
                $data['city'],
                $data['state'],
                $data['tan_no'],
                $data['created_by'],
                $repo_id
            ]);

            // Delete existing emails
            $stmt = $pdo->prepare("DELETE FROM billing_repository_email WHERE repository_id = ?");
            $stmt->execute([$repo_id]);

            // Insert new emails
            $stmt = $pdo->prepare("INSERT INTO billing_repository_email (repository_id, email) VALUES (?, ?)");
            foreach ($submitted_emails as $email) {
                $stmt->execute([$repo_id, $email]);
            }

            $pdo->commit();
            header("Location: index.php?updated=1");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "Database error: " . $e->getMessage();
            $_SESSION['errors'] = $errors;
            header("Location: edit_repo.php?id=$repo_id");
            exit;
        }
    }
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Retrieve errors from session
$errors = $_SESSION['errors'] ?? [];
unset($_SESSION['errors']);
?>
<!DOCTYPE html>
<html>
<head>
            <link rel="stylesheet" href="./css/billing_edit_style.css">

    <title>Edit Billing Repository</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            toggleSections('<?= htmlspecialchars($repository['repository_type']) ?>');

            // Add event listeners for pincode
            const internalPin = document.getElementById('pin_code');
            const externalPin = document.getElementById('external_pin_code');
            if (internalPin) internalPin.addEventListener('blur', () => fetchCityState());
            if (externalPin) externalPin.addEventListener('blur', () => fetchCityState('external_'));

            // Add input validation for GST No., PAN No., and TAN No.
            const gstFields = [document.getElementById('internal_gst_no'), document.getElementById('external_gst_no')];
            const panFields = [document.getElementById('internal_pan_no'), document.getElementById('external_pan_no')];
            const tanFields = [document.getElementById('internal_tan_no'), document.getElementById('external_tan_no')];

            gstFields.forEach(field => {
                if (field) {
                    field.addEventListener('input', function() {
                        this.value = this.value.replace(/[^A-Za-z0-9]/g, '');
                    });
                }
            });

            panFields.forEach(field => {
                if (field) {
                    field.addEventListener('input', function() {
                        this.value = this.value.replace(/[^A-Za-z0-9]/g, '');
                    });
                }
            });

            tanFields.forEach(field => {
                if (field) {
                    field.addEventListener('input', function() {
                        this.value = this.value.replace(/[^A-Za-z0-9]/g, '');
                    });
                }
            });

            // Add input validation for entity name
            const entityName = document.getElementById('entity_name');
            if (entityName) {
                entityName.addEventListener('input', function() {
                    this.value = this.value.replace(/[^A-Za-z0-9 ]/g, '');
                });
            }

            // Initialize email fields
            const emailContainers = document.querySelectorAll('.email-container');
            emailContainers.forEach(container => {
                // Add delete handlers for existing email fields
            container.querySelectorAll('.delete-email').forEach(button => {
                button.addEventListener('click', function() {
                    const emailFields = container.querySelectorAll('.email-field');
                    if (emailFields.length > 1) {
                        this.closest('.email-field').remove(); // This is correct
                    } else {
                        // Instead of alert, clear the input value
                        this.closest('.email-field').querySelector('input').value = '';
                        alert('At least one email field must remain, but you can clear its value');
                    }
                });
            });

                // Add handler for add email button
                container.querySelector('.add-email')?.addEventListener('click', function() {
                    const emailFields = container.querySelectorAll('.email-field');
                    if (emailFields.length >= 12) {
                        alert('Cannot add more than 12 emails');
                        return;
                    }
                    const newEmailField = document.createElement('div');
                    newEmailField.className = 'email-field';
                    newEmailField.innerHTML = `
                        <input type="email" name="emails[]" placeholder="Enter email" required>
                        <button type="button" class="delete-email"><i class="fas fa-trash"></i></button>
                    `;
                    container.insertBefore(newEmailField, this);
                    newEmailField.querySelector('.delete-email').addEventListener('click', function() {
                        const emailFields = container.querySelectorAll('.email-field');
                        if (emailFields.length > 1) {
                            newEmailField.remove();
                        } else {
                            alert('At least one email field is required.');
                        }
                    });
                });
            });
        });

        function toggleSections(repositoryType) {
            repositoryType = repositoryType.toLowerCase();
            const internalSection = document.getElementById('internal-section');
            const externalSection = document.getElementById('external-section');

            internalSection.style.display = repositoryType === 'internal' ? 'block' : 'none';
            externalSection.style.display = repositoryType === 'external' ? 'block' : 'none';

            // Disable fields in non-selected section
            const allFields = document.querySelectorAll('input, select, textarea');
            allFields.forEach(field => {
                if (field.id === 'repository_type' || field.name === 'csrf_token' || field.name === 'repository_type') {
                    return;
                }
                field.disabled = true;
                field.removeAttribute('name');
            });

            // Enable fields in selected section
            if (repositoryType === 'internal') {
                internalSection.querySelectorAll('input, select, textarea').forEach(field => {
                    field.disabled = false;
                    if (field.id === 'entity_name') field.name = 'entity_name';
                    if (field.id === 'internal_address') field.name = 'internal_address';
                    if (field.id === 'internal_gst_no') field.name = 'internal_gst_no';
                    if (field.id === 'internal_pan_no') field.name = 'internal_pan_no';
                    if (field.id === 'pin_code') field.name = 'pin_code';
                    if (field.id === 'city') field.name = 'city';
                    if (field.id === 'state') field.name = 'state';
                    if (field.id === 'internal_tan_no') field.name = 'internal_tan_no';
                    if (field.type === 'email') field.name = 'emails[]';
                });
            } else if (repositoryType === 'external') {
                externalSection.querySelectorAll('input, select, textarea').forEach(field => {
                    field.disabled = false;
                    if (field.id === 'broker_id') field.name = 'broker_id';
                    if (field.id === 'company_id') field.name = 'company_id';
                    if (field.id === 'external_address') field.name = 'external_address';
                    if (field.id === 'external_gst_no') field.name = 'external_gst_no';
                    if (field.id === 'external_pin_code') field.name = 'external_pin_code';
                    if (field.id === 'external_city') field.name = 'external_city';
                    if (field.id === 'external_state') field.name = 'external_state';
                    if (field.id === 'external_pan_no') field.name = 'external_pan_no';
                    if (field.id === 'external_tan_no') field.name = 'external_tan_no';
                    if (field.type === 'email') field.name = 'emails[]';
                });
            }
        }

        async function fetchCityState(fieldPrefix = '') {
            const pincode = document.getElementById(fieldPrefix + 'pin_code').value.trim();
            if (pincode.length !== 6 || !/^\d{6}$/.test(pincode)) {
                alert("Please enter a valid 6-digit pincode");
                document.getElementById(fieldPrefix + 'city').value = '';
                document.getElementById(fieldPrefix + 'state').value = '';
                return;
            }

            try {
                document.getElementById(fieldPrefix + 'city').value = 'Loading...';
                document.getElementById(fieldPrefix + 'state').value = 'Loading...';
                
                const response = await fetch(`https://api.postalpincode.in/pincode/${pincode}`);
                const data = await response.json();
                
                if (data[0] && data[0].Status === 'Success') {
                    const postOffice = data[0].PostOffice[0];
                    document.getElementById(fieldPrefix + 'city').value = postOffice.District;
                    document.getElementById(fieldPrefix + 'state').value = postOffice.State;
                } else {
                    alert("Could not find details for this pincode");
                    document.getElementById(fieldPrefix + 'city').value = '';
                    document.getElementById(fieldPrefix + 'state').value = '';
                }
            } catch (error) {
                console.error('Error fetching pincode data:', error);
                alert("Error fetching pincode details. Please try again.");
                document.getElementById(fieldPrefix + 'city').value = '';
                document.getElementById(fieldPrefix + 'state').value = '';
            }
        }

        function validateForm() {
            const repositoryType = document.getElementById('repository_type').value.toLowerCase();

            // Validate emails
            const emailInputs = document.querySelectorAll('input[name="emails[]"]');
            let validEmails = 0;
            emailInputs.forEach(input => {
                const email = input.value.trim();
                if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                    alert("Please enter a valid email address");
                    input.focus();
                    return false;
                }
                if (email) validEmails++;
            });
            if (validEmails > 12) {
                alert("Cannot add more than 12 emails");
                return false;
            }

            if (repositoryType === 'internal') {
                const requiredFields = ['entity_name', 'internal_address', 'internal_gst_no', 'pin_code', 'internal_pan_no'];
                for (let field of requiredFields) {
                    const input = document.getElementById(field);
                    if (!input.value.trim()) {
                        alert(`Please fill in ${field.replace('internal_', '').replace('_', ' ')}`);
                        input.focus();
                        return false;
                    }
                }
                if (!/^[A-Za-z0-9 ]+$/.test(document.getElementById('entity_name').value)) {
                    alert("Entity name must contain only letters, numbers and spaces");
                    document.getElementById('entity_name').focus();
                    return false;
                }
                if (!/^\d{6}$/.test(document.getElementById('pin_code').value)) {
                    alert("Please enter a valid 6-digit pincode");
                    document.getElementById('pin_code').focus();
                    return false;
                }
                if (!/^[A-Za-z0-9]+$/.test(document.getElementById('internal_gst_no').value)) {
                    alert("GST No. must contain only letters and numbers");
                    document.getElementById('internal_gst_no').focus();
                    return false;
                }
                if (!/^[A-Za-z0-9]+$/.test(document.getElementById('internal_pan_no').value)) {
                    alert("PAN No. must contain only letters and numbers");
                    document.getElementById('internal_pan_no').focus();
                    return false;
                }
                const internalTan = document.getElementById('internal_tan_no').value;
                if (internalTan && !/^[A-Za-z0-9]+$/.test(internalTan)) {
                    alert("TAN No. must contain only letters and numbers");
                    document.getElementById('internal_tan_no').focus();
                    return false;
                }
            } else if (repositoryType === 'external') {
                const requiredFields = ['broker_id', 'company_id', 'external_address', 'external_gst_no', 'external_pan_no'];
                for (let field of requiredFields) {
                    const input = document.getElementById(field);
                    if (!input.value.trim()) {
                        alert(`Please fill in ${field.replace('external_', '').replace('_', ' ')}`);
                        input.focus();
                        return false;
                    }
                }
                if (!/^[A-Za-z0-9]+$/.test(document.getElementById('external_gst_no').value)) {
                    alert("GST No. must contain only letters and numbers");
                    document.getElementById('external_gst_no').focus();
                    return false;
                }
                if (!/^[A-Za-z0-9]+$/.test(document.getElementById('external_pan_no').value)) {
                    alert("PAN No. must contain only letters and numbers");
                    document.getElementById('external_pan_no').focus();
                    return false;
                }
                const externalTan = document.getElementById('external_tan_no').value;
                if (externalTan && !/^[A-Za-z0-9]+$/.test(externalTan)) {
                    alert("TAN No. must contain only letters and numbers");
                    document.getElementById('external_tan_no').focus();
                    return false;
                }
            }
            return true;
        }
    </script>
</head>
<body>
    <div class="container">
        <?php if (!empty($errors)): ?>
            <div class="error-alert">
                <?php foreach ($errors as $error): ?>
                    <p><?= htmlspecialchars($error) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <h2>Edit Billing Repository #<?= $repo_id ?></h2>
        <a href="index.php" class="back-btn">← Back</a>

        <form method="POST" onsubmit="return validateForm()">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

            <!-- Repository Type -->
            <div class="form-section">
                <h3>Repository Type</h3>
                <input type="text" value="<?= htmlspecialchars(ucfirst($repository['repository_type'])) ?>" disabled>
                <input type="hidden" name="repository_type" id="repository_type" value="<?= htmlspecialchars($repository['repository_type']) ?>">
            </div>

            <!-- Internal Section -->
            <div class="form-section conditional-section" id="internal-section">
                <h3>Internal Company Details</h3>
                <div class="form-grid">
                    <label>
                        Entity Name:
                        <input type="text" id="entity_name" value="<?= htmlspecialchars($repository['entity_name'] ?? '') ?>">
                    </label>
                </div>
                <div>
                    <label>
                        Address:
                    </label>
                    <textarea id="internal_address" rows="3"><?= htmlspecialchars($repository['address'] ?? '') ?></textarea>
                </div>
                <div class="col-2">
                    <label>
                        GST No.:
                        <input type="text" id="internal_gst_no" value="<?= htmlspecialchars($repository['gst_no'] ?? '') ?>">
                    </label>
                    <label>
                        PAN No.:
                        <input type="text" id="internal_pan_no" value="<?= htmlspecialchars($repository['pan_no'] ?? '') ?>">
                    </label>
                </div>
                <div class="col-2">
                    <label>
                        Pin Code:
                        <input type="text" id="pin_code" maxlength="6" pattern="\d{6}" 
                               title="Please enter exactly 6 digits" value="<?= htmlspecialchars($repository['pin_code'] ?? '') ?>">
                    </label>
                    <label>
                        TAN No.:
                        <input type="text" id="internal_tan_no" value="<?= htmlspecialchars($repository['tan_no'] ?? '') ?>">
                    </label>
                </div>
                <div class="col-2">
                    <label>
                        City:
                        <input type="text" id="city" value="<?= htmlspecialchars($repository['city'] ?? '') ?>" readonly>
                    </label>
                    <label>
                        State:
                        <input type="text" id="state" value="<?= htmlspecialchars($repository['state'] ?? '') ?>" readonly>
                    </label>
                </div>
                <div class="email-container">
                    <label>Emails for Communication:</label>
                    <?php foreach ($existing_emails as $email): ?>
                        <div class="email-field">
                            <input type="email" name="emails[]" value="<?= htmlspecialchars($email) ?>">
                            <button type="button" class="delete-email"><i class="fas fa-trash"></i></button>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($existing_emails)): ?>
                        <div class="email-field">
                            <input type="email" name="emails[]" placeholder="Enter email">
                            <button type="button" class="delete-email"><i class="fas fa-trash"></i></button>
                        </div>
                    <?php endif; ?>
                    <button type="button" class="add-email">Add Email</button>
                </div>
            </div>

            <!-- External Section -->
            <div class="form-section conditional-section" id="external-section">
                <h3>External Company Details</h3>
                <div class="form-grid">
                    <label>
                        Broker Name:
                        <select id="broker_id">
                            <option value="">Select Broker</option>
                            <?php foreach ($brokers as $broker): ?>
                                <option value="<?= $broker['br_id'] ?>" <?= $repository['broker_id'] == $broker['br_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($broker['br_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        Company Name:
                        <select id="company_id">
                            <option value="">Select Company</option>
                            <?php foreach ($companies as $company): ?>
                                <option value="<?= $company['c_id'] ?>" <?= $repository['company_id'] == $company['c_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($company['c_name']) ?>
                                </option>
                            <?php endforeach; ?>
                            <option value="all" <?= $repository['company_all'] === 'all' ? 'selected' : '' ?>>All Companies</option>
                        </select>
                    </label>
                </div>
                <div>
                    <label>
                        Address:
                    </label>
                    <textarea id="external_address" rows="3"><?= htmlspecialchars($repository['address'] ?? '') ?></textarea>
                </div>
                <div class="col-2">
                    <label>
                        GST No.:
                        <input type="text" id="external_gst_no" value="<?= htmlspecialchars($repository['gst_no'] ?? '') ?>">
                    </label>
                    <label>
                        PAN No.:
                        <input type="text" id="external_pan_no" value="<?= htmlspecialchars($repository['pan_no'] ?? '') ?>">
                    </label>
                </div>
                <div class="col-2">
                    <label>
                        Pin Code:
                        <input type="text" id="external_pin_code" maxlength="6" pattern="\d{6}" 
                               title="Please enter exactly 6 digits" value="<?= htmlspecialchars($repository['pin_code'] ?? '') ?>">
                    </label>
                    <label>
                        TAN No.:
                        <input type="text" id="external_tan_no" value="<?= htmlspecialchars($repository['tan_no'] ?? '') ?>">
                    </label>
                </div>
                <div class="col-2">
                    <label>
                        City:
                        <input type="text" id="external_city" value="<?= htmlspecialchars($repository['city'] ?? '') ?>" readonly>
                    </label>
                    <label>
                        State:
                        <input type="text" id="external_state" value="<?= htmlspecialchars($repository['state'] ?? '') ?>" readonly>
                    </label>
                </div>
                <div class="email-container">
                    <label>Emails for Communication:</label>
                    <?php foreach ($existing_emails as $email): ?>
                        <div class="email-field">
                            <input type="email" name="emails[]" value="<?= htmlspecialchars($email) ?>">
                            <button type="button" class="delete-email"><i class="fas fa-trash"></i></button>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($existing_emails)): ?>
                        <div class="email-field">
                            <input type="email" name="emails[]" placeholder="Enter email">
                            <button type="button" class="delete-email"><i class="fas fa-trash"></i></button>
                        </div>
                    <?php endif; ?>
                    <button type="button" class="add-email">Add Email</button>
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
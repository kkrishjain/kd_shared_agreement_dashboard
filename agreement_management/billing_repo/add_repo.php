<?php
session_start();
require '../config/database.php';

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Initialize variables
$error = $success = '';
$brokers = $companies = [];

try {
    // Fetch brokers and companies for dropdowns
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $pdo->query("SELECT br_id, br_name FROM brokers WHERE br_status = '1'");
    $brokers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("SELECT c_id, c_name FROM companies WHERE c_status = '1'");
    $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database error: " . htmlspecialchars($e->getMessage()));
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        // Validate CSRF token
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
            throw new Exception("Invalid CSRF token");
        }

        // Validate repository type
        $repositoryType = $_POST['repository_type'] ?? '';
        if (!in_array($repositoryType, ['internal', 'external'])) {
            throw new Exception("Invalid repository type");
        }

        // Initialize data for insertion
        $data = [
            'type' => $repositoryType,
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

        // Collect emails
        $emails = $_POST['emails'] ?? [];
        $emails = array_filter(array_map('trim', $emails), function($email) {
            return filter_var($email, FILTER_VALIDATE_EMAIL);
        });
        
        if (empty($emails)) {
            throw new Exception("At least one valid email is required");
        }
        if (count($emails) > 12) {
            throw new Exception("Cannot add more than 12 emails");
        }

        // Type-specific validation and data mapping
        if ($repositoryType === 'internal') {
            $requiredInternal = ['entity_name', 'internal_address', 'internal_gst_no', 'pin_code', 'internal_pan_no'];
            foreach ($requiredInternal as $field) {
                if (empty(trim($_POST[$field] ?? ''))) {
                    throw new Exception("Required field missing: " . htmlspecialchars(str_replace('internal_', '', $field)));
                }
            }
            
            // Validate entity name (alphanumeric and spaces only)
            if (!preg_match('/^[A-Za-z0-9 ]+$/', $_POST['entity_name'])) {
                throw new Exception("Entity name must contain only letters, numbers and spaces");
            }

            // Validate pin code
            if (!preg_match('/^\d{6}$/', $_POST['pin_code'])) {
                throw new Exception("Invalid pin code format");
            }

            // Validate GST No. (alphanumeric only)
            if (!preg_match('/^[A-Za-z0-9]+$/', $_POST['internal_gst_no'])) {
                throw new Exception("GST No. must contain only letters and numbers");
            }

            // Validate PAN No. (alphanumeric only)
            if (!preg_match('/^[A-Za-z0-9]+$/', $_POST['internal_pan_no'])) {
                throw new Exception("PAN No. must contain only letters and numbers");
            }

            // Validate TAN No. (alphanumeric only, if provided)
            if (!empty($_POST['internal_tan_no']) && !preg_match('/^[A-Za-z0-9]+$/', $_POST['internal_tan_no'])) {
                throw new Exception("TAN No. must contain only letters and numbers");
            }

            // Map internal fields
            $data['entity_name'] = $_POST['entity_name'];
            $data['address'] = $_POST['internal_address'];
            $data['gst_no'] = $_POST['internal_gst_no'];
            $data['pan_no'] = $_POST['internal_pan_no'];
            $data['pin_code'] = $_POST['pin_code'];
            $data['city'] = $_POST['city'];
            $data['state'] = $_POST['state'];
            $data['tan_no'] = $_POST['internal_tan_no'] ?? null;
        } elseif ($repositoryType === 'external') {
            $requiredExternal = ['broker_id', 'company_id', 'external_address', 'external_gst_no', 'external_pan_no'];
            foreach ($requiredExternal as $field) {
                if (empty(trim($_POST[$field] ?? ''))) {
                    throw new Exception("Required field missing: " . htmlspecialchars(str_replace('external_', '', $field)));
                }
            }

            // Handle company_id and company_all
            $company_id = $_POST['company_id'] ?? null;
            $data['company_all'] = ($company_id === 'all') ? 'all' : null;
            $data['company_id'] = ($company_id === 'all') ? null : $company_id;

            // Validate broker exists
            $broker = fetchBroker($_POST['broker_id']);
            if (!$broker) {
                throw new Exception("Invalid broker selected");
            }
            
            // Validate company exists if not 'all'
            if ($company_id !== 'all') {
                $company = fetchCompany($company_id);
                if (!$company) {
                    throw new Exception("Invalid company selected");
                }
            }
            
            // Validate GST No. (alphanumeric only)
            if (!preg_match('/^[A-Za-z0-9]+$/', $_POST['external_gst_no'])) {
                throw new Exception("GST No. must contain only letters and numbers");
            }

            // Validate PAN No. (alphanumeric only)
            if (!preg_match('/^[A-Za-z0-9]+$/', $_POST['external_pan_no'])) {
                throw new Exception("PAN No. must contain only letters and numbers");
            }

            // Validate TAN No. (alphanumeric only, if provided)
            if (!empty($_POST['external_tan_no']) && !preg_match('/^[A-Za-z0-9]+$/', $_POST['external_tan_no'])) {
                throw new Exception("TAN No. must contain only letters and numbers");
            }

            // Map external fields
            $data['broker_id'] = $_POST['broker_id'];
            $data['company_id'] = $data['company_id'];
            $data['address'] = $_POST['external_address'];
            $data['gst_no'] = $_POST['external_gst_no'];
            $data['pan_no'] = $_POST['external_pan_no'];
            $data['pin_code'] = $_POST['external_pin_code'] ?? null;
            $data['city'] = $_POST['external_city'] ?? null;
            $data['state'] = $_POST['external_state'] ?? null;
            $data['tan_no'] = $_POST['external_tan_no'] ?? null;
        }

        // Start transaction
        $pdo->beginTransaction();

        // Insert into billing_repository (without email)
        $repositoryId = insertBillingRepository($data);

        // Insert emails into billing_repository_email
        $stmt = $pdo->prepare("INSERT INTO billing_repository_email (repository_id, email) VALUES (?, ?)");
        foreach ($emails as $email) {
            $stmt->execute([$repositoryId, $email]);
        }

        $pdo->commit();
        $_SESSION['success'] = "Billing repository created successfully!";
        header("Location: index.php");
        exit();

    } catch (Exception $e) {
        error_log("Billing Repository Error: " . $e->getMessage());
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

// Helper functions
function fetchBroker($id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT br_id, br_name FROM brokers WHERE br_id = ? AND br_status = '1'");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function fetchCompany($id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT c_id, c_name FROM companies WHERE c_id = ? AND c_status = '1'");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function insertBillingRepository($data) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        INSERT INTO billing_repository (
            repository_type, entity_name, broker_id, company_id, company_all,
            address, gst_no, pan_no, pin_code, city, state, 
            tan_no, created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    $stmt->execute([
        $data['type'],
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
        $data['created_by']
    ]);

    return $pdo->lastInsertId();
}
?>
<!DOCTYPE html>
<html>
<head>
        <link rel="stylesheet" href="./css/billing_add_style.css">

    <title>Add Billing Repository</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            // Initialize form - disable all fields until type is selected
            const allFields = document.querySelectorAll('input, select, textarea');
            allFields.forEach(field => {
                if (field.id !== 'repository_type' && field.name !== 'csrf_token') {
                    field.disabled = true;
                    field.value = '';
                }
            });
            
            // Add event listener for repository type change
            document.getElementById('repository_type').addEventListener('change', function() {
                toggleSections(this.value);
            });
            
            // Add event listeners for pincode
            document.getElementById('pin_code')?.addEventListener('blur', () => fetchCityState());
            document.getElementById('external_pin_code')?.addEventListener('blur', () => fetchCityState('external_'));

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
            document.getElementById('entity_name')?.addEventListener('input', function() {
                this.value = this.value.replace(/[^A-Za-z0-9 ]/g, '');
            });

            // Add email field handlers
            document.querySelectorAll('.add-email').forEach(button => {
                button.addEventListener('click', function() {
                    const container = this.closest('.email-container');
                    const emailFields = container.querySelectorAll('.email-field');
                    if (emailFields.length >= 12) {
                        alert('Cannot add more than 12 emails');
                        return;
                    }
                    const newEmailField = document.createElement('div');
                    newEmailField.className = 'email-field';
                    newEmailField.innerHTML = `
                        <input type="email" name="emails[]" placeholder="Enter email">
                        <button type="button" class="delete-email"><i class="fas fa-trash"></i></button>
                    `;
                    container.insertBefore(newEmailField, this);
                    // Add delete handler to new button
                    newEmailField.querySelector('.delete-email').addEventListener('click', function() {
                        newEmailField.remove();
                    });
                });
            });
        });

        function toggleSections(repositoryType) {
            const internalSection = document.getElementById('internal-section');
            const externalSection = document.getElementById('external-section');
            
            // Hide all sections and reset fields
            internalSection.style.display = 'none';
            externalSection.style.display = 'none';
            
            // Clear and disable all fields except repository_type and csrf_token
            document.querySelectorAll('input, select, textarea').forEach(field => {
                if (field.id !== 'repository_type' && field.name !== 'csrf_token') {
                    if (field.type === 'select-one') {
                        field.selectedIndex = 0;
                    } else if (field.name !== 'emails[]') { // Preserve email fields
                        field.value = '';
                    }
                    field.disabled = true;
                    if (field.name !== 'emails[]') { // Preserve email field names
                        field.removeAttribute('name');
                    }
                }
            });
            
            // Show and enable relevant section
            if (repositoryType === 'internal') {
                internalSection.style.display = 'block';
                internalSection.querySelectorAll('input, select, textarea').forEach(field => {
                    field.disabled = false;
                    if (field.id === 'entity_name') field.name = 'entity_name';
                    if (field.id === 'internal_address') field.name = 'internal_address';
                    if (field.id === 'internal_gst_no') field.name = 'internal_gst_no';
                    if (field.id === 'pin_code') field.name = 'pin_code';
                    if (field.id === 'city') field.name = 'city';
                    if (field.id === 'state') field.name = 'state';
                    if (field.id === 'internal_pan_no') field.name = 'internal_pan_no';
                    if (field.id === 'internal_tan_no') field.name = 'internal_tan_no';
                });
            } else if (repositoryType === 'external') {
                externalSection.style.display = 'block';
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
            const repositoryType = document.getElementById('repository_type').value;
            if (!repositoryType) {
                alert("Please select Repository Type first");
                return false;
            }

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
            if (validEmails === 0) {
                alert("At least one valid email is required");
                return false;
            }
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
                        return false;
                    }
                }
                if (!/^[A-Za-z0-9 ]+$/.test(document.getElementById('entity_name').value)) {
                    alert("Entity name must contain only letters, numbers and spaces");
                    return false;
                }
                if (!/^\d{6}$/.test(document.getElementById('pin_code').value)) {
                    alert("Please enter a valid 6-digit pincode");
                    return false;
                }
                if (!/^[A-Za-z0-9]+$/.test(document.getElementById('internal_gst_no').value)) {
                    alert("GST No. must contain only letters and numbers");
                    return false;
                }
                if (!/^[A-Za-z0-9]+$/.test(document.getElementById('internal_pan_no').value)) {
                    alert("PAN No. must contain only letters and numbers");
                    return false;
                }
                const internalTan = document.getElementById('internal_tan_no').value;
                if (internalTan && !/^[A-Za-z0-9]+$/.test(internalTan)) {
                    alert("TAN No. must contain only letters and numbers");
                    return false;
                }
            } else if (repositoryType === 'external') {
                const requiredFields = ['broker_id', 'company_id', 'external_address', 'external_gst_no', 'external_pan_no'];
                for (let field of requiredFields) {
                    const input = document.getElementById(field);
                    if (!input.value.trim()) {
                        alert(`Please fill in ${field.replace('external_', '').replace('_', ' ')}`);
                        return false;
                    }
                }
                if (!/^[A-Za-z0-9]+$/.test(document.getElementById('external_gst_no').value)) {
                    alert("GST No. must contain only letters and numbers");
                    return false;
                }
                if (!/^[A-Za-z0-9]+$/.test(document.getElementById('external_pan_no').value)) {
                    alert("PAN No. must contain only letters and numbers");
                    return false;
                }
                const externalTan = document.getElementById('external_tan_no').value;
                if (externalTan && !/^[A-Za-z0-9]+$/.test(externalTan)) {
                    alert("TAN No. must contain only letters and numbers");
                    return false;
                }
            }
            return true;
        }
    </script>
</head>
<body>
    <a href="index.php" class="back-btn">← Back</a>
    <h2>Add Billing Repository</h2>
    
    <?php if (!empty($error)) : ?>
        <div class="error">Error: <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <form method="POST" onsubmit="return validateForm()">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        
        <div class="form-section">
            <h3>Repository Type</h3>
            <label>
                Type:
                <select name="repository_type" id="repository_type" required>
                    <option value="">Select Repository Type</option>
                    <option value="internal">Internal Company</option>
                    <option value="external">External Company</option>
                </select>
            </label>
        </div>
        
        <div class="form-section conditional-section" id="internal-section">
            <h3>Internal Company Details</h3>
            <label>
                Entity Name:
                <input type="text" id="entity_name" name="entity_name" required>
            </label>
            <label>
                Address:
                <textarea id="internal_address" name="internal_address" rows="3" required></textarea>
            </label>
            <div class="col-2">
                <label>
                    GST No.:
                    <input type="text" id="internal_gst_no" name="internal_gst_no" required>
                </label>
                <label>
                    PAN No.:
                    <input type="text" id="internal_pan_no" name="internal_pan_no" required>
                </label>
            </div>
            <div class="col-2">
                <label>
                    Pin Code:
                    <input type="text" id="pin_code" name="pin_code" maxlength="6" pattern="\d{6}" 
                           title="Please enter exactly 6 digits" onblur="fetchCityState()" required>
                </label>
                <label>
                    TAN No.:
                    <input type="text" id="internal_tan_no" name="internal_tan_no">
                </label>
            </div>
            <div class="col-2">
                <label>
                    City:
                    <input type="text" id="city" name="city" readonly>
                </label>
                <label>
                    State:
                    <input type="text" id="state" name="state" readonly>
                </label>
            </div>
            <div class="email-container">
                <label>Emails for Communication:</label>
                <div class="email-field">
                    <input type="email" name="emails[]" placeholder="Enter email" required>
                    <button type="button" class="delete-email"><i class="fas fa-trash"></i></button>
                </div>
                <button type="button" class="add-email">Add Email</button>
            </div>
        </div>
        
        <div class="form-section conditional-section" id="external-section">
            <h3>External Company Details</h3>
            <label>
                Broker Name:
                <select id="broker_id" name="broker_id" required>
                    <option value="">Select Broker</option>
                    <?php foreach ($brokers as $broker): ?>
                        <option value="<?= $broker['br_id'] ?>">
                            <?= htmlspecialchars($broker['br_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Company Name:
                <select id="company_id" name="company_id" required>
                    <option value="">Select Company</option>
                    <?php foreach ($companies as $company): ?>
                        <option value="<?= $company['c_id'] ?>">
                            <?= htmlspecialchars($company['c_name']) ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="all">All Companies</option>
                </select>
            </label>
            <label>
                Address:
                <textarea id="external_address" name="external_address" rows="3" required></textarea>
            </label>
            <div class="col-2">
                <label>
                    GST No.:
                    <input type="text" id="external_gst_no" name="external_gst_no" required>
                </label>
                <label>
                    PAN No.:
                    <input type="text" id="external_pan_no" name="external_pan_no" required>
                </label>
            </div>
            <div class="col-2">
                <label>
                    Pin Code:
                    <input type="text" id="external_pin_code" name="external_pin_code" maxlength="6" pattern="\d{6}" 
                           title="Please enter exactly 6 digits" onblur="fetchCityState('external_')">
                </label>
                <label>
                    TAN No.:
                    <input type="text" id="external_tan_no" name="external_tan_no">
                </label>
            </div>
            <div class="col-2">
                <label>
                    City:
                    <input type="text" id="external_city" name="external_city" readonly>
                </label>
                <label>
                    State:
                    <input type="text" id="external_state" name="external_state" readonly>
                </label>
            </div>
            <div class="email-container">
                <label>Emails for Communication:</label>
                <div class="email-field">
                    <input type="email" name="emails[]" placeholder="Enter email" required>
                    <button type="button" class="delete-email"><i class="fas fa-trash"></i></button>
                </div>
                <button type="button" class="add-email">Add Email</button>
            </div>
        </div>
        
        <button type="submit">Save Repository</button>
    </form>
</body>
</html>
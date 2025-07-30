<?php
// At the very top of the file
session_start();
require '../config/database.php';

// Check if agreement_id is provided
$agreement_id = $_GET['id'] ?? null;
if (!$agreement_id) {
    die("Error: Agreement ID is required");
}

try {
    // Fetch agreement details with correct column names
    $stmt = $pdo->prepare("SELECT a.*, b.br_name 
                          FROM agreements a
                          JOIN brokers b ON a.broker_id = b.br_id
                          WHERE a.agreement_id = ?");
    $stmt->execute([$agreement_id]);
    $agreement = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$agreement) {
        die("Error: Agreement not found");
    }

    // Fetch related data with correct column names
    $companies = $pdo->query("SELECT c_id, c_name FROM companies")->fetchAll();
    
    // Fetch SPOCs
    $spocs_stmt = $pdo->prepare("SELECT * FROM spocs WHERE agreement_id = ?");
    $spocs_stmt->execute([$agreement_id]);
    $spocs = $spocs_stmt->fetchAll();
    
    // Fetch cycles
    $cycles_stmt = $pdo->prepare("SELECT * FROM agreement_cycles WHERE agreement_id = ?");
    $cycles_stmt->execute([$agreement_id]);
    $cycles = $cycles_stmt->fetchAll();

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $errors = [];
        $_SESSION['post_data'] = $_POST;
// Validate email formats
if (!filter_var($_POST['biz_mis'], FILTER_VALIDATE_EMAIL)) {
    $errors[] = "Business MIS must be a valid email address";
}
if (!filter_var($_POST['com_statement'], FILTER_VALIDATE_EMAIL)) {
    $errors[] = "Commission Statement must be a valid email address";
}
        function handleFileUpdate($fieldName, $existingValue) {
            if (!empty($_FILES[$fieldName]['tmp_name']) && is_uploaded_file($_FILES[$fieldName]['tmp_name'])) {
                return file_get_contents($_FILES[$fieldName]['tmp_name']);
            }
            return $existingValue;
        }
           // Handle file updates
        $agreement_file = handleFileUpdate('agreement_file', $agreement['agreement_file']);

        // Validate mandatory fields
        $required = ['company_id', 'transaction_in_name_of','start_date', 'end_date', 'gst', 'tds','biz_mis','com_statement','frequency','mis_type'];
        foreach ($required as $field) {
            if (empty($_POST[$field])) {
                $errors[] = ucfirst($field) . " is required";
            }
        }

        // Validate files
        if ($agreement_file === null) {
            $errors[] = "Agreement file is required";
        }

        // Check for errors
        if (!empty($errors)) {
            $_SESSION['errors'] = $errors;
            header("Location: edit_agreement.php?id=$agreement_id");
            exit;
        }

        // Update main agreement with correct column names
        $updateStmt = $pdo->prepare("UPDATE agreements SET
            company_id = ?,
            transaction_in_name_of = ?,
            start_date = ?, 
            end_date = ?,
            num_cycles = ?, 
            gst = ?, 
            tds = ?,
            agreement_file = ?,
            biz_mis = ?,
            com_statement = ?,
            frequency = ?,
            mis_type = ?
            WHERE agreement_id = ?");

        $updateStmt->execute([
            $_POST['company_id'] ?? null,
            $_POST['transaction_in_name_of'] ?? null,
            $_POST['start_date'] ?? null,
            $_POST['end_date'] ?? null,
            $_POST['num_cycles'] ?? 0,
            $_POST['gst'] ?? 0.0,
            $_POST['tds'] ?? 0.0,
            $agreement_file,
            $_POST['biz_mis'] ?? null,
            $_POST['com_statement'] ?? null,
            $_POST['frequency'] ?? null,
            $_POST['mis_type'] ?? null,
            $agreement_id
        ]);

        // Update SPOCs
        updateRelatedData('spocs', [
            'name', 'number', 'email', 'department', 'designation', 'source','invoice_cc'
        ], $_POST['spoc_data'] ?? [], $agreement_id, $pdo,'spocs_id');
        
        updateRelatedData('agreement_cycles', [
            'business_start_day', 'business_end_day',
            'invoice_day', 'invoice_month',
            'payment_day',
            'gst_start_day', 'gst_month',
        ], $_POST['cycle_data'] ?? [], $agreement_id, $pdo,'cycle_id');

        // Clear session data on success
        unset($_SESSION['post_data']);
        unset($_SESSION['errors']);
        
        header("Location: index.php?updated=1");
        exit;
    }
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Retrieve stored data for form repopulation
$errors = $_SESSION['errors'] ?? [];
$postData = $_SESSION['post_data'] ?? [];
unset($_SESSION['errors']);
unset($_SESSION['post_data']);
function updateRelatedData($table, $fields, $items, $agreement_id, $pdo, $idField = 'id') {
    $existingIds = [];  // ✅ Always initialize this
    $submittedIds = [];

        // 1. Fetch existing IDs
        $stmt = $pdo->prepare("SELECT $idField FROM $table WHERE agreement_id = ?");
        $stmt->execute([$agreement_id]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row){
            $existingIds[] = $row[$idField];
        }



foreach ($items as $item) {
    $values = [];
    foreach ($fields as $field) {
        $values[] = $item[$field] ?? null;
    }
    $itemId = $item[$idField] ?? null;

    if ($itemId) {
        // Update existing
        $setFields = implode(', ', array_map(fn($f) => "$f = ?", $fields));
        $stmt = $pdo->prepare("UPDATE $table SET $setFields WHERE $idField = ?");
        $stmt->execute([...$values, $itemId]);
        $submittedIds[] = $itemId;
    } else {
        // Insert new
        $fieldList = implode(', ', $fields) . ", agreement_id";
        $placeholders = implode(', ', array_fill(0, count($fields) + 1, '?'));
        $stmt = $pdo->prepare("INSERT INTO $table ($fieldList) VALUES ($placeholders)");
        $stmt->execute([...$values, $agreement_id]);

        // Store the last inserted ID
        $submittedIds[] = $pdo->lastInsertId();
    }
}
if (!empty($submittedIds)) {
    $placeholders = implode(',', array_fill(0, count($submittedIds), '?'));
    $stmt = $pdo->prepare("DELETE FROM $table WHERE agreement_id = ? AND $idField NOT IN ($placeholders)");
    $stmt->execute([$agreement_id, ...$submittedIds]);
} else {
    // If no items submitted, remove all
    $stmt = $pdo->prepare("DELETE FROM $table WHERE agreement_id = ?");
    $stmt->execute([$agreement_id]);
}

}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Agreement</title>
    
    <link rel="stylesheet" href="./css/edit_style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

</head>
<body>
    <div class="container">
    <?php if (!empty($errors)): ?>
        <script>
            alert("Please fix the following errors:\n- <?= implode("\n- ", $errors) ?>");
        </script>
        <?php endif; ?>
        <h2>Edit Agreement #<?= $agreement_id ?></h2>
        <a href="index.php" class="back-btn">← Back to List</a>
        
        <form method="POST" enctype="multipart/form-data">
            <!-- Basic Information Section -->
            <div class="form-section">
                <h3>Basic Information</h3>
                <div class="form-grid">
                    <div>
                        <label>Broker Name</label>
                      
                        <input type="hidden" name="broker_id" value="<?= $agreement['broker_id'] ?>">
                        <p> <?= htmlspecialchars($agreement['broker_name']) ?> (ID: <?= $agreement['broker_id'] ?>)</p>
                    </div>
                    <div>
                        <label>Company</label>
                        <select name="company_id">
                            <?php foreach ($companies as $c): ?>
                                <option value="<?= $c['c_id'] ?>" <?= $c['c_id'] == $agreement['company_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['c_name']) ?>
                                </option>
                                <?= htmlspecialchars($c['company_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                     <div>
                        <label>Transaction in name of:</label>
                        <input type="text" name="transaction_in_name_of" 
                            pattern="[A-Za-z0-9\s.,&()-]+" 
                            title="Only letters, numbers, and basic punctuation allowed"
                            value="<?= htmlspecialchars($postData['transaction_in_name_of'] ?? $agreement['transaction_in_name_of']) ?>" 
                            required>
                    </div>
                    <div>
                        <label>Start Date</label>
                        <input type="date" name="start_date" 
               value="<?= htmlspecialchars($postData['start_date'] ?? $agreement['start_date']) ?>" 
               required>
                    </div>
                    <div>
                        <label>End Date</label>
                        <input type="date" name="end_date" 
               value="<?= htmlspecialchars($postData['end_date'] ?? $agreement['end_date']) ?>" 
               required>
                    </div>
                    <div>
                        <label>GST (%)</label>
                      <input type="number" step="0.01" name="gst" 
                        max="100" 
                        value="<?= htmlspecialchars($postData['gst'] ?? $agreement['gst']) ?>">
                    </div>
                    <div>
                        <label>TDS (%)</label>
                        <input type="number" step="0.01" name="tds" 
                        max="100" 
                        value="<?= htmlspecialchars($postData['tds'] ?? $agreement['tds']) ?>">
                    </div>
                    <div>
                        <label>Business MIS</label>
                        <input type="email" 
                            name="biz_mis" 
                            pattern="^[^\s@]+@[^\s@]+\.[^\s@]+$" 
                            title="Enter a valid email address"
                            class="email-validation"
                            oninput="validateEmail(this)"
                            value="<?= htmlspecialchars($postData['biz_mis'] ?? $agreement['biz_mis']) ?>" 
                            required>
                        <div class="email-error" style="color: red; display: none;">Invalid email format</div>
                    </div>
                    <div>
                        <label>Commission Statement</label>
                        <input type="email" 
                            name="com_statement" 
                            pattern="^[^\s@]+@[^\s@]+\.[^\s@]+$" 
                            title="Enter a valid email address"
                            class="email-validation"
                            oninput="validateEmail(this)"
                            value="<?= htmlspecialchars($postData['com_statement'] ?? $agreement['com_statement']) ?>" 
                            required>
                        <div class="email-error" style="color: red; display: none;">Invalid email format</div>
                    </div>
                    <div>
                        <label>Frequency</label>
                        <div class="source">
                            <select name="frequency" required>
                            
                                <option value="Weekly" <?= ($agreement['frequency'] ?? '') === 'Weekly' ? 'selected' : '' ?>>Weekly</option>
                                <option value="Daily" <?= ($agreement['frequency'] ?? '') === 'Daily' ? 'selected' : '' ?>>Daily</option>
                                <option value="Monthly" <?= ($agreement['frequency'] ?? '') === 'Monthly' ? 'selected' : '' ?>>Monthly</option>
                                <option value="Fortnightly" <?= ($agreement['frequency'] ?? '') === 'Fortnightly' ? 'selected' : '' ?>>Fortnightly</option>
                            </select>
                        </div>
                    </div>  
                    <div>
                    <label>MIS Type</label>
                        <div class="source">
                            <select name="mis_type" required>
                            
                                <option value="Monthly" <?= ($agreement['mis_type'] ?? '') === 'Monthly' ? 'selected' : '' ?>>Monthly</option>
                                <option value="Yearly" <?= ($agreement['mis_type'] ?? '') === 'Yearly' ? 'selected' : '' ?>>Yearly</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Documents Section -->
            <div class="form-section">
                <h3>Documents</h3>
                <div class="form-grid">
                    <div class="file-section">
                        <?php if ($agreement['agreement_file']): ?>
                        
                            <a href="download.php?id=<?= $agreement_id ?>&type=agreement" target="_blank" class="btn">
                            
                            <i class="fas fa-download" title="Downlaod Agreement"></i>
                            </a>
 
                        
                        <?php endif; ?>
                        <input type="file" name="agreement_file" accept="application/pdf">
                    </div>
            
                </div>
            </div>

            <!-- SPOCs Section -->
            <!-- SPOCs Section - Correct Code -->
        <div class="form-section">
            <h3>SPOCs</h3>
            <div id="spoc-container">
                <?php foreach ($spocs as $index => $spoc): ?>
                <div class="dynamic-section">
                <input type="hidden" name="spoc_data[<?= $index ?>][spoc_id]" value="<?= htmlspecialchars($spoc['spoc_id'] ?? '') ?>">
                <div class="form-grid">
                        <div>
                            <label>Name</label>
                            <input type="text" name="spoc_data[<?= $index ?>][name]" 
                            pattern="^[A-Za-z\s]+$" 
                            title="Only letters and spaces allowed"
                            value="<?= htmlspecialchars($spoc['name'] ?? '') ?>">
                        </div>
                        <div>
                            <label>Contact No</label>
                            <input type="text" name="spoc_data[<?= $index ?>][number]" 
                            pattern="[0-9]{10}" 
                            title="10 digits required"
                            value="<?= htmlspecialchars($spoc['number'] ?? '') ?>">
                        </div>
                        <div>
                            <label>Email</label>
                            <input type="email" name="spoc_data[<?= $index ?>][email]" 
                                value="<?= htmlspecialchars($spoc['email'] ?? '') ?>">
                        </div>
                        <div>
                            <label>Department</label>
                           <input type="text" name="spoc_data[<?= $index ?>][department]" 
                            pattern="^[A-Za-z\s]+$" 
                            title="Only letters and spaces allowed"
                            value="<?= htmlspecialchars($spoc['department'] ?? '') ?>">
                        </div>
                        <div>
                            <label>Designation</label>
                            <input type="text" name="spoc_data[<?= $index ?>][designation]" 
                            pattern="^[A-Za-z\s]+$" 
                            title="Only letters and spaces allowed"
                            value="<?= htmlspecialchars($spoc['designation'] ?? '') ?>">                        
                        </div>
                       
                        <div class="source">
                        <label>Source</label>
                            <select name="spoc_data[<?= $index ?>][source]" required>
                            
                                <option value="Internal" <?= ($spoc['source'] ?? '') === 'Internal' ? 'selected' : '' ?>>Internal</option>
                                <option value="External" <?= ($spoc['source'] ?? '') === 'External' ? 'selected' : '' ?>>External</option>
                            </select>
                        </div>
                        <div class="source">
                        <label>Invoice CC</label>
                        <select name="spoc_data[<?= $index ?>][invoice_cc]" required>
                        
                        <option value="yes" <?= ($spoc['invoice_cc'] ?? '') === 'yes' ? 'selected' : '' ?>>Yes</option>
                        <option value="no" <?= ($spoc['invoice_cc'] ?? '') === 'no' ? 'selected' : '' ?>>No</option>
                    </select>
                        </div>
                    </div>
                    <button type="button" class="remove-btn" onclick="this.parentNode.remove()">Remove</button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="add-btn" onclick="addSpocField()">Add SPOC</button>
        </div>
        <!-- Cycles Section -->
<!-- Cycles Section -->
<div class="form-section">
    <h3>Agreement Cycles</h3>
    <div id="cycles-container">
        <?php foreach ($cycles as $index => $cycle): ?>
        <div class="dynamic-section">
        <input type="hidden" name="cycle_data[<?= $index ?>][ac_id]" value="<?= htmlspecialchars($cycle['ac_id'] ?? '') ?>">
        <div class="form-grid">
                <!-- Business Cycle -->
                <div class="cycle-input-group">
                    <div class="flex-center">
                        <label>Business Cycle:</label>
                        <input type="number" name="cycle_data[<?= $index ?>][business_start_day]"
                               min="1" max="31" required
                               data-cycle-index="<?= $index ?>"
                               value="<?= htmlspecialchars($cycle['business_start_day'] ?? 1) ?>">
                        <span>to</span>
                        <input type="number" name="cycle_data[<?= $index ?>][business_end_day]"
                               min="1" max="31" required
                               data-cycle-index="<?= $index ?>"
                               value="<?= htmlspecialchars($cycle['business_end_day'] ?? 1) ?>">
                    </div>
                </div>

                <!-- Invoice Cycle -->
                <div class="cycle-input-group">
                    <div class="flex-center">
                        <label>Invoice Cycle:</label>
                        <input type="number" name="cycle_data[<?= $index ?>][invoice_day]"
                               min="1" max="31" required
                               value="<?= htmlspecialchars($cycle['invoice_day'] ?? 1) ?>">
                        <div class="month">
                            <select name="cycle_data[<?= $index ?>][invoice_month]"required>
                                <option value="current" <?= ($cycle['invoice_month'] ?? 'current') === 'current' ? 'selected' : '' ?>>This Month</option>
                                <option value="next" <?= ($cycle['invoice_month'] ?? 'current') === 'next' ? 'selected' : '' ?>>Next Month</option>
                                <option value="next_next" <?= ($cycle['invoice_month'] ?? 'current') === 'next_next' ? 'selected' : '' ?>>Next to Next Month</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Payment Cycle -->
                <div class="cycle-input-group">
                    <div class="flex-center">
                        <label>Payment Cycle Day:</label>
                        <input type="number" name="cycle_data[<?= $index ?>][payment_day]"
                               min="1" max="31" required
                               value="<?= htmlspecialchars($cycle['payment_day'] ?? 1) ?>">
                    </div>
                </div>

                <!-- GST Cycle -->
                <div class="cycle-input-group">
                    <div class="flex-center">
                        <label>GST Cycle:</label>
                        <input type="number" name="cycle_data[<?= $index ?>][gst_start_day]"
                               min="1" max="31" required
                               value="<?= htmlspecialchars($cycle['gst_start_day'] ?? 1) ?>">
                        <div class="month">
                            <select name="cycle_data[<?= $index ?>][gst_month]" required>
                                <option value="current" <?= ($cycle['gst_month'] ?? 'current') === 'current' ? 'selected' : '' ?>>This Month</option>
                                <option value="next" <?= ($cycle['gst_month'] ?? 'current') === 'next' ? 'selected' : '' ?>>Next Month</option>
                                <option value="next_next" <?= ($cycle['gst_month'] ?? 'current') === 'next_next' ? 'selected' : '' ?>>Next to Next Month</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            <button type="button" class="remove-btn" onclick="this.parentNode.remove()">Remove</button>
        </div>
        <?php endforeach; ?>
    </div>
    <button type="button" class="add-btn" onclick="addCycleField()">Add Cycle</button>
</div>

                <div class="form-actions">
                    <button type="submit" class="save-btn">Save Changes</button>
                    <a href="index.php" class="cancel-btn">Cancel</a>
                </div>
            </div>

    <script>
        // Add these 2 functions to handle cycle validation
function validateCycle(index) {
    const container = document.getElementById("cycles-container");
    const startInput = container.querySelector(`input[name="cycle_data[${index}][business_start_day]"]`);
    const endInput = container.querySelector(`input[name="cycle_data[${index}][business_end_day]"]`);

    // Reset previous errors
    startInput.setCustomValidity('');
    endInput.setCustomValidity('');

    // Validate minimum value
    if (startInput.value < 1) {
        startInput.setCustomValidity('Start day must be at least 1');
        startInput.reportValidity();
        return false;
    }

    // Validate current cycle
    if (parseInt(endInput.value) <= parseInt(startInput.value)) {
        endInput.setCustomValidity('End day must be greater than start day');
        endInput.reportValidity();
        return false;
    }

    // Validate against previous cycle
    if (index > 0) {
        const prevEndInput = container.querySelector(`input[name="cycle_data[${index-1}][business_end_day]"]`);
        const minStart = parseInt(prevEndInput.value) + 1;
        
        if (parseInt(startInput.value) <= parseInt(prevEndInput.value)) {
            startInput.setCustomValidity(`Must start after previous cycle (day ${minStart})`);
            startInput.reportValidity();
            return false;
        }
    }

    return true;
}

function updateSubsequentCycles(index) {
    const container = document.getElementById("cycles-container");
    const endInput = container.querySelector(`input[name="cycle_data[${index}][business_end_day]"]`);
    const nextIndex = index + 1;
    const nextStartInput = container.querySelector(`input[name="cycle_data[${nextIndex}][business_start_day]"]`);

    if (nextStartInput) {
        nextStartInput.min = parseInt(endInput.value) + 1;
        validateCycle(nextIndex);
        updateSubsequentCycles(nextIndex);
    }
}
     // Date Validation
     document.addEventListener('DOMContentLoaded', function() {
    // Date validation setup
    setupCycleValidationForExistingElements();
    document.querySelectorAll('[name^="cycle_data["]').forEach(input => {
        const indexMatch = input.name.match(/\[(\d+)\]/);
        if (indexMatch) {
            const index = parseInt(indexMatch[1]);
            input.addEventListener('change', () => {
                if (validateCycle(index)) {
                    updateSubsequentCycles(index);
                }
            });
        }
    });
    // File input requirements
    document.querySelectorAll('input[type="checkbox"][name^="delete_"]').forEach(checkbox => {
        const fileInput = checkbox.closest('.file-section').querySelector('input[type="file"]');
        checkbox.addEventListener('change', () => {
            fileInput.required = checkbox.checked;
        });
        fileInput.required = checkbox.checked;
    });

    // Main date validation
    const startDate = document.querySelector('input[name="start_date"]');
    const endDate = document.querySelector('input[name="end_date"]');
    
    function updateDateLimits() {
        if (startDate.value) {
            endDate.min = startDate.value;
            endDate.disabled = false;
        } else {
            endDate.disabled = true;
        }
    }
    
    startDate.addEventListener('change', updateDateLimits);
    updateDateLimits();
});
 // Initialize on page load
 function addCycleField() {
    const container = document.getElementById('cycles-container');
    const index = container.children.length;
    
    container.insertAdjacentHTML('beforeend', `
        <div class="dynamic-section">
            <div class="form-grid">
                <div class="cycle-input-group">
                    <div class="flex-center">
                        <label>Business Cycle:</label>
                        <input type="number" name="cycle_data[${index}][business_start_day]" 
                               min="1" max="31" required  data-cycle-index="${index}">
                        <span>to</span>
                        <input type="number" name="cycle_data[${index}][business_end_day]" 
                               min="1" max="31" required  data-cycle-index="${index}">
                    </div>
                </div>

                <div class="cycle-input-group">
                    <div class="flex-center">
                        <label>Invoice Cycle:</label>
                        <input type="number" name="cycle_data[${index}][invoice_day]" 
                               min="1" max="31" required>
                        <select name="cycle_data[${index}][invoice_month]" required>
                            <option value="current">This Month</option>
                            <option value="next">Next Month</option>
                            <option value="next_next">Next to Next Month</option>
                        </select>
                    </div>
                </div>

                <div class="cycle-input-group">
                    <div class="flex-center">
                        <label>Payment Cycle Day:</label>
                        <input type="number" name="cycle_data[${index}][payment_day]" 
                               min="1" max="31" required>
                    </div>
                </div>

                <div class="cycle-input-group">
                    <div class="flex-center">
                        <label>GST Cycle:</label>
                        <input type="number" name="cycle_data[${index}][gst_start_day]" 
                               min="1" max="31" required>
                        <select name="cycle_data[${index}][gst_month]" required>
                            <option value="current">This Month</option>
                            <option value="next">Next Month</option>
                            <option value="next_next">Next to Next Month</option>
                        </select>
                    </div>
                </div>
            </div>
            <button type="button" class="remove-btn" onclick="this.parentNode.remove()">Remove</button>
        </div>
    `);

        // Add validation to new inputs
        const newInputs = container.lastElementChild.querySelectorAll('input[type="number"]');
    newInputs.forEach(input => {
        input.addEventListener('change', function() {
            const index = parseInt(this.dataset.cycleIndex);
            if (validateCycle(index)) {
                updateSubsequentCycles(index);
            }
        });
    });

    // Apply to existing cycles
    // document.querySelectorAll('.dynamic-section').forEach(setupCycleValidation);
};


function validateEmail(input) {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    const errorDiv = input.parentElement.querySelector('.email-error');
    
    if (input.value === '') {
        errorDiv.style.display = 'none';
        input.style.borderColor = '';
    } else if (!emailRegex.test(input.value)) {
        errorDiv.style.display = 'block';
        input.style.borderColor = 'red';
    } else {
        errorDiv.style.display = 'none';
        input.style.borderColor = '';
    }
}

        // Dynamic field management
      // Proper addSpocField function
      function addSpocField() {
    const container = document.getElementById('spoc-container');
    const index = container.children.length;
    container.insertAdjacentHTML('beforeend', `
            <div class="dynamic-section">
                <div class="form-grid">
                    <div>
                        <label>Name</label>
                        <input type="text" name="spoc_data[${index}][name]" 
                        pattern="^[A-Za-z\s]+$" 
                        title="Only letters and spaces allowed">
                    </div>
                    <div>
                        <label>Contact No</label>
                        <input type="text" name="spoc_data[${index}][number]" 
                        pattern="[0-9]{10}" 
                        title="10 digits required"
                        inputmode="numeric">
                    </div>
                    <div>
                        <label>Email</label>
                        <input type="email" name="spoc_data[${index}][email]"
                               class="email-validation"
                               oninput="validateEmail(this)">
                        <div class="email-error" style="color: red; display: none;">Invalid email format</div>
                    </div>
                    <div>
                        <label>Department</label>
                        <input type="text" name="spoc_data[${index}][department]" 
                        pattern="^[A-Za-z\s]+$" 
                        title="Only letters and spaces allowed">
                    </div>
                     <div>
                        <label>Designation</label>
                        <input type="text" name="spoc_data[${index}][designation]" 
                        pattern="^[A-Za-z\s]+$" 
                        title="Only letters and spaces allowed">
                                            </div>
                    <div>
                        <label>Source</label>
                        <select name="spoc_data[${index}][source]">
                        <option value="" disabled selected>Select Source</option>
                            <option value="internal">Internal</option>
                            <option value="external">External</option>
                        </select>
                    </div>
                    <div>
                        <label>Invoice CC</label>
                        <select name="spoc_data[${index}][invoice_cc]">
                        <option value="" disabled selected>Select </option>
                            <option value="yes">Yes</option>
                            <option value="no">No</option>
                        </select>
                    </div>
                </div>
                <button type="button" class="remove-btn" onclick="this.parentNode.remove()">Remove</button>
            </div>
    `);
}

// Add input restriction for number fields
document.addEventListener('input', function(e) {
    if (e.target.getAttribute('inputmode') === 'numeric') {
        e.target.value = e.target.value.replace(/[^0-9]/g, '');
    }
});

    </script>
</body>
</html>

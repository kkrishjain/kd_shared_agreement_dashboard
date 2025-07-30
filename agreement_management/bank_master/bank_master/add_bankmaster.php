<?php
session_start();
require '../../config/database.php';

// Initialize variables
$error = $success = '';
$entities = $brokers = $transactionTypes = $partners = [];
$modeOfPayment = ['Bank', 'Cash', 'Other'];
$entryTypes = ['Credit', 'Debit'];
$transactionCategories = ['Net', 'Gross', 'GST', 'Advance'];
$mappingOptions = ['Yes', 'No'];

function amountToWords($number) {
    if ($number == 0) return 'Zero';

    $parts = explode('.', (string)$number);
    $rupees = (int)$parts[0];
    $paise = isset($parts[1]) ? str_pad($parts[1], 2, '0') : '00';
    $paise = (int)rtrim($paise, '0');
    
    $result = '';
    
    if ($rupees > 0) {
        $result .= numberToWords($rupees) . ' Rupees';
    }
    
    if ($paise > 0) {
        if ($rupees > 0) {
            $result .= ' and ';
        }
        $result .= numberToWords($paise) . ' Paisa';
    }
    
    return trim($result) ?: 'Zero';
}
// Amount to words conversion function (PHP)
function numberToWords($number) {
    if ($number == 0) return 'Zero';
    
    $units = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine'];
    $teens = ['Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    
    $result = '';
    
    // Handle crore part (1 crore = 10,000,000)
    if ($number >= 10000000) {
        $crores = floor($number / 10000000);
        $number %= 10000000;
        $result .= numberToWords($crores) . ' Crore ';
    }
    
    // Handle lakh part (1 lakh = 100,000)
    if ($number >= 100000) {
        $lakhs = floor($number / 100000);
        $number %= 100000;
        $result .= numberToWords($lakhs) . ' Lakh ';
    }
    
    // Handle thousands
    if ($number >= 1000) {
        $thousands = floor($number / 1000);
        $number %= 1000;
        $result .= numberToWords($thousands) . ' Thousand ';
    }
    
    // Handle hundreds
    if ($number >= 100) {
        $hundreds = floor($number / 100);
        $number %= 100;
        $result .= $units[$hundreds] . ' Hundred ';
    }
    
    // Handle tens and units
    if ($number > 0) {
        if (!empty($result)) $result .= 'and ';
        
        if ($number < 10) {
            $result .= $units[$number];
        } elseif ($number < 20) {
            $result .= $teens[$number - 10];
        } else {
            $result .= $tens[floor($number / 10)];
            if ($number % 10 > 0) {
                $result .= ' ' . $units[$number % 10];
            }
        }
    }
    
    return trim($result);
}

// Check for flash messages
if (isset($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}
if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

// Fetch data for dropdowns
try {
    // Fetch entities
    $stmt = $pdo->query("SELECT id, entity_name FROM billing_repository where entity_name IS NOT NULL");
    $entities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch brokers
    $stmt = $pdo->query("SELECT br_id, br_name FROM brokers");
    $brokers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch partners
    $stmt = $pdo->query("SELECT refercode AS partner_id, rname AS partner_name FROM first_register");
    $partners = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch transaction types
    try {
        $stmt = $pdo->query("SELECT id, name FROM mst_transaction_types");
        $transactionTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $transactionTypes = [
            ['id' => 1, 'name' => 'Demo'],
            ['id' => 2, 'name' => 'Demo2'],
            ['id' => 3, 'name' => 'Demo3']
        ];
    }
    
} catch (PDOException $e) {
    $error = "Database error: " . htmlspecialchars($e->getMessage());
}

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        // Validate required fields
        $required = [
            'entity', 'mode_of_payment', 'date', 'entry_type', 
            'ref_no', 'amount', 'transaction_category',
            'invoice_mapping', 'gst_mapping'
        ];
        
        foreach ($required as $field) {
            if (empty(trim($_POST[$field] ?? ''))) {
                throw new Exception("Required field missing: " . htmlspecialchars($field));
            }
        }
        
        // Validate entry type specific fields
        $entryType = $_POST['entry_type'];
        if ($entryType === 'Credit') {
            if (empty($_POST['broker_id'])) {
                throw new Exception("Broker is required for Credit entries");
            }
            if (empty($_POST['transaction_name'])) {
                throw new Exception("Transaction name is required for Credit entries");
            }
        }
        if ($entryType === 'Debit') {
            if (empty($_POST['partner_id'])) {
                throw new Exception("Partner is required for Debit entries");
            }
        }
        
        // Handle "Other" mode of payment
        $otherModeDetail = null;
        if ($_POST['mode_of_payment'] === 'Other') {
            if (empty(trim($_POST['other_mode_detail'] ?? ''))) {
                throw new Exception("Please specify details for 'Other' payment mode");
            }
            $otherModeDetail = $_POST['other_mode_detail'];
        }
        
        // Prepare data for insertion
        $data = [
            'entity' => $_POST['entity'],
            'mode_of_payment' => $_POST['mode_of_payment'],
            'other_mode_detail' => $otherModeDetail,
            'date' => $_POST['date'],
            'entry_type' => $entryType,
            'ref_no' => $_POST['ref_no'],
            'amount' => (float)$_POST['amount'],
            'broker_id' => ($entryType === 'Credit') ? (int)$_POST['broker_id'] : null,
            'transaction_type_id' => ($entryType === 'Credit') ? (int)$_POST['transaction_name'] : null,
            'partner_id' => ($entryType === 'Debit') ? $_POST['partner_id'] : null,
            'transaction_category' => $_POST['transaction_category'],
            'invoice_mapping' => $_POST['invoice_mapping'],
            'gst_mapping' => $_POST['gst_mapping'],
        ];
        
        // Insert into database
        $stmt = $pdo->prepare("INSERT INTO ins_bank_master (
            entity, mode_of_payment, other_mode_detail, transaction_date, 
            entry_type, ref_no, amount, broker_id, transaction_type_id, 
            partner_id, transaction_category, invoice_mapping, gst_mapping, created_at
        ) VALUES (
            :entity, :mode_of_payment, :other_mode_detail, :date, 
            :entry_type, :ref_no, :amount, :broker_id, :transaction_type_id, 
            :partner_id, :transaction_category, :invoice_mapping, :gst_mapping, NOW()
        )");
        
        $stmt->execute($data);
        
        // Set success flash message and redirect
// Set success flash message and redirect
$_SESSION['flash_success'] = "Bank transaction added successfully!";
// Clear the form data from session if any
if (isset($_SESSION['form_data'])) {
    unset($_SESSION['form_data']);
}
header("Location: index.php");
exit();
        
    } catch (Exception $e) {
        $error = $e->getMessage();
        // Store form data in session to repopulate form after error
        $_SESSION['form_data'] = $_POST;
        $_SESSION['flash_error'] = $error;
        header("Location: add_bankmaster.php");
        exit();
    }
}

// Repopulate form from session after error
if (isset($_SESSION['form_data'])) {
    $_POST = $_SESSION['form_data'];
    unset($_SESSION['form_data']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="../css/bank_add_style.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Bank Transaction</title>
    <style>
        .amount-words-display {
            font-style: italic;
            color: #4a5568;
            font-size: 0.9em;
            margin-top: 5px;
            min-height: 1.2em;
            padding: 5px;
            border-top: 1px solid #e2e8f0;
        }
    </style>
</head>
<body>
    <a href="index.php" class="back-btn">← Back to Dashboard</a>
    <h2>Add Bank Transaction</h2>
    
    <?php if (!empty($error)): ?>
        <div class="notification error">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>
    
    <?php if (!empty($success)): ?>
        <div class="notification success">
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>
    
    <form method="POST" id="bankTransactionForm">
        <div class="form-section">
            <h3>Transaction Details</h3>
            <div class="form-grid">
                <div class="form-group">
                    <label for="entity">Entity</label>
                    <select class="form-control" name="entity" id="entity" required disabled>
                        <option value="">Select Entity</option>
                        <?php foreach ($entities as $entity): ?>
                            <option value="<?= htmlspecialchars($entity['id']) ?>" 
                                <?= ($_POST['entity'] ?? '') === $entity['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($entity['entity_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="mode_of_payment">Mode of Payment</label>
                    <select class="form-control" name="mode_of_payment" id="mode_of_payment" required disabled
                            onchange="toggleOtherDetail()">
                        <option value="">Select Payment Mode</option>
                        <?php foreach ($modeOfPayment as $mode): ?>
                            <option value="<?= htmlspecialchars($mode) ?>" 
                                <?= ($_POST['mode_of_payment'] ?? '') === $mode ? 'selected' : '' ?>>
                                <?= htmlspecialchars($mode) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="date">Date</label>
                    <input type="date" class="form-control" name="date" id="date" required disabled
                           value="<?= htmlspecialchars($_POST['date'] ?? '') ?>">
                </div>
                
                <div class="form-group">
                    <label for="entry_type">Type of Entry</label>
                    <select class="form-control" name="entry_type" id="entry_type" required 
                            onchange="enableFormFields(); toggleOtherDetail(); toggleEntryFields()">
                        <option value="">Select Entry Type</option>
                        <option value="Credit" <?= ($_POST['entry_type'] ?? '') === 'Credit' ? 'selected' : '' ?>>Credit</option>
                        <option value="Debit" <?= ($_POST['entry_type'] ?? '') === 'Debit' ? 'selected' : '' ?>>Debit</option>
                    </select>
                </div>
            </div>
            
           <div id="other-detail-section" class="other-detail" style="display: none;">
                <div class="form-group">
                    <label for="other_mode_detail">Please specify other payment mode</label>
                    <input type="text" class="form-control" name="other_mode_detail" id="other_mode_detail" disabled
                        value="<?= htmlspecialchars($_POST['other_mode_detail'] ?? '') ?>"
                        oninput="this.value = this.value.replace(/[^A-Za-z ]/g, '')"
                        pattern="[A-Za-z ]+"
                        title="Only letters and spaces are allowed">
                </div>
            </div>
        </div>
        
        <div class="form-section">
            <h3>Transaction Information</h3>
            <div class="form-grid">
               <div class="form-group">
                    <label for="ref_no">Ref. No. (UTR/Bank Ref)</label>
                    <input type="text" class="form-control" name="ref_no" id="ref_no" required disabled
                        value="<?= htmlspecialchars($_POST['ref_no'] ?? '') ?>"
                        oninput="this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '')"
                        pattern="[A-Z0-9]+"
                        title="Only uppercase letters and numbers are allowed">
                </div>
                
                <div class="form-group">
                    <label for="amount">Amount (₹)</label>
                    <input type="number" class="form-control" name="amount" id="amount" min="0" step="1" required disabled
                           value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>"
                           oninput="convertAmountToWords()">
                    <div id="amountInWords" class="amount-words-display">
                        <?php
                        if (!empty($_POST['amount'])) {
                            echo htmlspecialchars(numberToWords($_POST['amount']));
                        }
                        ?>
                    </div>
                </div>
                
                <div class="form-group-transaction-type">
                    <label for="transaction_category">Type of Transaction</label>
                    <select class="form-control" name="transaction_category" id="transaction_category" required disabled>
                        <option value="">Select Transaction Type</option>
                        <?php foreach ($transactionCategories as $category): ?>
                            <option value="<?= htmlspecialchars($category) ?>" 
                                <?= ($_POST['transaction_category'] ?? '') === $category ? 'selected' : '' ?>>
                                <?= htmlspecialchars($category) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        
        <div id="credit-section" class="form-section conditional-section">
            <h3>Credit Entry Details</h3>
            <div class="form-grid">
                <div class="form-group">
                    <label for="broker_id">Broker</label>
                    <select class="form-control" name="broker_id" id="broker_id" disabled>
                        <option value="">Select Broker</option>
                        <?php foreach ($brokers as $broker): ?>
                            <option value="<?= htmlspecialchars($broker['br_id']) ?>" 
                                <?= ($_POST['broker_id'] ?? '') == $broker['br_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($broker['br_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="transaction_name">Transaction Name</label>
                    <select class="form-control" name="transaction_name" id="transaction_name" disabled>
                        <option value="">Select Transaction</option>
                        <?php foreach ($transactionTypes as $type): ?>
                            <option value="<?= htmlspecialchars($type['id']) ?>" 
                                <?= ($_POST['transaction_name'] ?? '') == $type['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($type['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        
        <div id="debit-section" class="form-section conditional-section">
            <h3>Debit Entry Details</h3>
            <div class="form-group">
                <label for="partner_search">Partner (Search by ID or Name)</label>
                <div class="search-container">
                    <input type="text" class="form-control" id="partner_search" 
                           placeholder="Type to search partners..." list="partnerSuggestions"
                           value="<?= htmlspecialchars($_POST['partner_search'] ?? '') ?>" disabled>
                    <datalist id="partnerSuggestions">
                        <?php foreach ($partners as $partner): ?>
                            <option data-id="<?= htmlspecialchars($partner['partner_id']) ?>" 
                                    value="<?= htmlspecialchars($partner['partner_id'] . ' - ' . $partner['partner_name']) ?>">
                        <?php endforeach; ?>
                    </datalist>
                    <p class="input-hint">Start typing to search partners by ID or name</p>
                </div>
                <input type="hidden" name="partner_id" id="partner_id" 
                       value="<?= htmlspecialchars($_POST['partner_id'] ?? '') ?>">
            </div>
        </div>
        
        <div class="form-section">
            <h3>Additional Information</h3>
            <div class="form-grid">
                <div class="form-group">
                    <label for="invoice_mapping">Invoice Mapping</label>
                    <select class="form-control" name="invoice_mapping" id="invoice_mapping" required disabled>
                        <option value="">Select Option</option>
                        <?php foreach ($mappingOptions as $option): ?>
                            <option value="<?= htmlspecialchars($option) ?>" 
                                <?= ($_POST['invoice_mapping'] ?? '') === $option ? 'selected' : '' ?>>
                                <?= htmlspecialchars($option) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="gst_mapping">GST Mapping</label>
                    <select class="form-control" name="gst_mapping" id="gst_mapping" required disabled>
                        <option value="">Select Option</option>
                        <?php foreach ($mappingOptions as $option): ?>
                            <option value="<?= htmlspecialchars($option) ?>" 
                                <?= ($_POST['gst_mapping'] ?? '') === $option ? 'selected' : '' ?>>
                                <?= htmlspecialchars($option) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            

        </div>
        
        <button type="submit" class="btn-submit" disabled>Add Bank Transaction</button>
    </form>

    <script>
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Set today's date as default
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('date').value = today;
            
            // Setup partner autocomplete
            setupPartnerAutocomplete();
            
            // Enable form fields if entry type is already selected
            if (document.getElementById('entry_type').value) {
                enableFormFields();
            }
            
            // Initialize amount in words display
            convertAmountToWords();
        });
        
        // Enable form fields when entry type is selected
        function enableFormFields() {
            const entryType = document.getElementById('entry_type').value;
            const allFields = document.querySelectorAll('input:not([type="hidden"]), select, textarea, button');
            
            // Enable all fields except entry type dropdown
            allFields.forEach(field => {
                if (field.id !== 'entry_type') {
                    field.disabled = (entryType === '');
                }
            });
            
            // Toggle specific sections
            toggleOtherDetail();
            toggleEntryFields();
        }
        
        // Toggle "Other" payment mode detail field
        function toggleOtherDetail() {
            const modeSelect = document.getElementById('mode_of_payment');
            const otherDetailSection = document.getElementById('other-detail-section');
            
            if (modeSelect.value === 'Other') {
                otherDetailSection.style.display = 'block';
                document.getElementById('other_mode_detail').required = true;
            } else {
                otherDetailSection.style.display = 'none';
                document.getElementById('other_mode_detail').required = false;
            }
        }
        
        // Toggle credit/debit fields based on entry type
        function toggleEntryFields() {
            const entryType = document.getElementById('entry_type').value;
            const creditSection = document.getElementById('credit-section');
            const debitSection = document.getElementById('debit-section');
            
            // Reset required attributes
            document.getElementById('broker_id').required = false;
            document.getElementById('transaction_name').required = false;
            document.getElementById('partner_id').required = false;
            
            if (entryType === 'Credit') {
                creditSection.style.display = 'block';
                debitSection.style.display = 'none';
                document.getElementById('broker_id').required = true;
                document.getElementById('transaction_name').required = true;
            } else if (entryType === 'Debit') {
                creditSection.style.display = 'none';
                debitSection.style.display = 'block';
                document.getElementById('partner_id').required = true;
            } else {
                creditSection.style.display = 'none';
                debitSection.style.display = 'none';
            }
        }
        
        // Setup partner autocomplete
        function setupPartnerAutocomplete() {
            const partnerSearch = document.getElementById('partner_search');
            const partnerIdField = document.getElementById('partner_id');
            const datalist = document.getElementById('partnerSuggestions');
            
            // Initialize with existing value if any
            if (partnerIdField.value) {
                const options = datalist.querySelectorAll('option');
                options.forEach(option => {
                    if (option.dataset.id === partnerIdField.value) {
                        partnerSearch.value = option.value;
                    }
                });
            }
            
            partnerSearch.addEventListener('input', function() {
                const value = this.value.trim();
                
                // Find matching option
                const options = datalist.querySelectorAll('option');
                let found = false;
                
                options.forEach(option => {
                    if (option.value === value) {
                        partnerIdField.value = option.dataset.id;
                        found = true;
                    }
                });
                
                if (!found) {
                    partnerIdField.value = '';
                }
            });
        }
        
        // Amount in words conversion function (JavaScript)
        function convertAmountToWords() {
    const amountInput = document.getElementById('amount');
    const amount = parseFloat(amountInput.value) || 0;
    document.getElementById('amountInWords').textContent = amountToWords(amount);
}

function amountToWords(number) {
    if (number === 0) return 'Zero';
    
    const parts = number.toString().split('.');
    let rupees = parseInt(parts[0]) || 0;
    let paise = 0;
    
    if (parts.length > 1) {
        // Pad to 2 digits and remove trailing zeros
        paise = parseInt(parts[1].padEnd(2, '0').substring(0, 2)) || 0;
    }
    
    let result = '';
    
    if (rupees > 0) {
        result += numberToWordsHelper(rupees) + ' Rupees';
    }
    
    if (paise > 0) {
        if (rupees > 0) {
            result += ' and ';
        }
        result += numberToWordsHelper(paise) + ' Paisa';
    }
    
    return result.trim() || 'Zero';
}
        
      
function numberToWordsHelper(number) {
    if (number === 0) return 'Zero';
    
    const units = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine'];
    const teens = ['Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    const tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    
    let result = '';
    let n = number;
    
    // Handle crore part (1 crore = 10,000,000)
    if (n >= 10000000) {
        const crores = Math.floor(n / 10000000);
        n %= 10000000;
        result += numberToWordsHelper(crores) + ' Crore ';
    }
    
    // Handle lakh part (1 lakh = 100,000)
    if (n >= 100000) {
        const lakhs = Math.floor(n / 100000);
        n %= 100000;
        result += numberToWordsHelper(lakhs) + ' Lakh ';
    }
    
    // Handle thousands
    if (n >= 1000) {
        const thousands = Math.floor(n / 1000);
        n %= 1000;
        result += numberToWordsHelper(thousands) + ' Thousand ';
    }
    
    // Handle hundreds
    if (n >= 100) {
        const hundreds = Math.floor(n / 100);
        n %= 100;
        result += units[hundreds] + ' Hundred ';
    }
    
    // Handle tens and units
    if (n > 0) {
        if (result !== '') result += 'and ';
        
        if (n < 10) {
            result += units[n];
        } else if (n < 20) {
            result += teens[n - 10];
        } else {
            const tensDigit = Math.floor(n / 10);
            const unitsDigit = n % 10;
            result += tens[tensDigit];
            if (unitsDigit > 0) {
                result += ' ' + units[unitsDigit];
            }
        }
    }
    
    return result.trim() || 'Zero';
}
    </script>
</body>
</html>

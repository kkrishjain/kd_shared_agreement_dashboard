<?php
session_start();
require '../../config/database.php';

// Initialize variables
$errors = [];
$success = '';
$entities = $brokers = $transactionTypes = $partners = [];
$modeOfPayment = ['Bank', 'Cash', 'Other'];
$entryTypes = ['Credit', 'Debit'];
$transactionCategories = ['Net', 'Gross', 'GST', 'Advance'];
$mappingOptions = ['Yes', 'No'];

// Get transaction ID
$transaction_id = $_GET['id'] ?? null;
if (!$transaction_id) {
    die("Error: Transaction ID is required");
}

try {
    // Fetch transaction details
    $stmt = $pdo->prepare("SELECT ibm.*, br.entity_name 
                         FROM ins_bank_master ibm 
                         LEFT JOIN billing_repository br ON ibm.entity = br.id 
                         WHERE ibm.id = ?");
    $stmt->execute([$transaction_id]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$transaction) {
        die("Error: Transaction not found");
    }

    // Fetch dropdown data
    $stmt = $pdo->query("SELECT id, entity_name FROM billing_repository WHERE entity_name IS NOT NULL");
    $entities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("SELECT br_id, br_name FROM brokers");
    $brokers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("SELECT refercode AS partner_id, rname AS partner_name FROM first_register");
    $partners = $stmt->fetchAll(PDO::FETCH_ASSOC);

    try {
        $stmt = $pdo->query("SELECT id, name FROM mst_transaction_types");
        $transactionTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $transactionTypes = [
            ['id' => 1, 'name' => 'Commission Received'],
            ['id' => 2, 'name' => 'Interest'],
            ['id' => 3, 'name' => 'Other Income']
        ];
    }

    // Handle form submission
    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        $_SESSION['post_data'] = $_POST;

        // Validate required fields
        $required = [
            'entity', 'mode_of_payment', 'date', 'entry_type', 
            'ref_no', 'amount', 'transaction_category',
            'invoice_mapping', 'gst_mapping'
        ];

        foreach ($required as $field) {
            if (empty(trim($_POST[$field] ?? ''))) {
                $errors[] = "Required field missing: " . htmlspecialchars($field);
            }
        }

        // Validate entry type specific fields
        $entryType = $_POST['entry_type'];
        if ($entryType !== $transaction['entry_type']) {
            $errors[] = "Entry type cannot be changed";
        }

        if ($entryType === 'Credit') {
            if (empty($_POST['broker_id'])) {
                $errors[] = "Broker is required for Credit entries";
            }
            if (empty($_POST['transaction_name'])) {
                $errors[] = "Transaction name is required for Credit entries";
            }
        }
        if ($entryType === 'Debit') {
            if (empty($_POST['partner_id'])) {
                $errors[] = "Partner is required for Debit entries";
            }
        }

        // Validate mode of payment
        if (!in_array($_POST['mode_of_payment'], $modeOfPayment)) {
            $errors[] = "Invalid mode of payment";
        }

        // Handle "Other" mode of payment
        $otherModeDetail = null;
        if ($_POST['mode_of_payment'] === 'Other') {
            if (empty(trim($_POST['other_mode_detail'] ?? ''))) {
                $errors[] = "Please specify details for 'Other' payment mode";
            } else {
                $otherModeDetail = trim($_POST['other_mode_detail']);
                if (!preg_match('/^[A-Za-z ]+$/', $otherModeDetail)) {
                    $errors[] = "Other payment mode detail can only contain letters and spaces";
                }
            }
        }

        // Validate amount
        $amount = (float)($_POST['amount'] ?? 0);
        if ($amount <= 0) {
            $errors[] = "Amount must be greater than 0";
        }

        // Validate ref_no
        if (!preg_match('/^[A-Z0-9]+$/', $_POST['ref_no'])) {
            $errors[] = "Reference number can only contain uppercase letters and numbers";
        }

        // Validate transaction category
        if (!in_array($_POST['transaction_category'], $transactionCategories)) {
            $errors[] = "Invalid transaction category";
        }

        // Validate mapping options
        if (!in_array($_POST['invoice_mapping'], $mappingOptions)) {
            $errors[] = "Invalid invoice mapping option";
        }
        if (!in_array($_POST['gst_mapping'], $mappingOptions)) {
            $errors[] = "Invalid GST mapping option";
        }

        // Redirect on errors
        if (!empty($errors)) {
            $_SESSION['errors'] = $errors;
            header("Location: edit_bankmaster.php?id=$transaction_id");
            exit;
        }

        // Update database
        $stmt = $pdo->prepare("UPDATE ins_bank_master SET
            entity = :entity,
            mode_of_payment = :mode_of_payment,
            other_mode_detail = :other_mode_detail,
            transaction_date = :date,
            entry_type = :entry_type,
            ref_no = :ref_no,
            amount = :amount,
            broker_id = :broker_id,
            transaction_type_id = :transaction_type_id,
            partner_id = :partner_id,
            transaction_category = :transaction_category,
            invoice_mapping = :invoice_mapping,
            gst_mapping = :gst_mapping
            WHERE id = :id");

        $stmt->execute([
            'entity' => $_POST['entity'],
            'mode_of_payment' => $_POST['mode_of_payment'],
            'other_mode_detail' => $otherModeDetail,
            'date' => $_POST['date'],
            'entry_type' => $entryType,
            'ref_no' => $_POST['ref_no'],
            'amount' => $amount,
            'broker_id' => ($entryType === 'Credit') ? (int)$_POST['broker_id'] : null,
            'transaction_type_id' => ($entryType === 'Credit') ? (int)$_POST['transaction_name'] : null,
            'partner_id' => ($entryType === 'Debit') ? $_POST['partner_id'] : null,
            'transaction_category' => $_POST['transaction_category'],
            'invoice_mapping' => $_POST['invoice_mapping'],
            'gst_mapping' => $_POST['gst_mapping'],
            'id' => $transaction_id
        ]);

        $_SESSION['flash_success'] = "Bank transaction updated successfully!";
        header("Location: index.php?updated=1");
        exit;

    }
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Retrieve errors from session
$errors = $_SESSION['errors'] ?? [];
unset($_SESSION['errors']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../css/edit_bankmaster.css">
    <title>Edit Bank Transaction</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            toggleEntryFields();
            toggleOtherDetail();
            setupPartnerAutocomplete();
        });

        function toggleOtherDetail() {
            const modeSelect = document.getElementById('mode_of_payment');
            const otherDetailSection = document.getElementById('other-detail-section');
            const otherDetailInput = document.getElementById('other_mode_detail');

            if (modeSelect.value === 'Other') {
                otherDetailSection.style.display = 'block';
                otherDetailInput.required = true;
            } else {
                otherDetailSection.style.display = 'none';
                otherDetailInput.required = false;
            }
        }

        function toggleEntryFields() {
            const entryType = document.getElementById('entry_type').value;
            const creditSection = document.getElementById('credit-section');
            const debitSection = document.getElementById('debit-section');

            if (entryType === 'Credit') {
                creditSection.style.display = 'block';
                debitSection.style.display = 'none';
                document.getElementById('broker_id').required = true;
                document.getElementById('transaction_name').required = true;
                document.getElementById('partner_id').required = false;
            } else if (entryType === 'Debit') {
                creditSection.style.display = 'none';
                debitSection.style.display = 'block';
                document.getElementById('broker_id').required = false;
                document.getElementById('transaction_name').required = false;
                document.getElementById('partner_id').required = true;
            }
        }

        function setupPartnerAutocomplete() {
            const partnerSearch = document.getElementById('partner_search');
            const partnerIdField = document.getElementById('partner_id');
            const datalist = document.getElementById('partnerSuggestions');

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

        <h2>Edit Bank Transaction #<?= $transaction_id ?></h2>
        <a href="index.php" class="back-btn">← Back</a>

        <form method="POST" id="bankTransactionForm">
            <div class="form-section">
                <h3>Transaction Details</h3>
                <div class="form-grid">
                  <div class="form-group">
                    <label for="entity">Entity</label>
                    <select name="entity" id="entity" required>
                        <option value="" <?= empty($transaction['entity']) ? 'selected' : '' ?>>Select Entity</option>
                        <?php foreach ($entities as $entity): ?>
                            <option value="<?= htmlspecialchars($entity['id']) ?>" 
                                <?= $transaction['entity'] == $entity['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($entity['entity_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                    <div class="form-group">
                        <label for="mode_of_payment">Mode of Payment</label>
                        <select name="mode_of_payment" id="mode_of_payment" required onchange="toggleOtherDetail()">
                            <option value="">Select Payment Mode</option>
                            <?php foreach ($modeOfPayment as $mode): ?>
                                <option value="<?= htmlspecialchars($mode) ?>" 
                                    <?= $transaction['mode_of_payment'] === $mode ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($mode) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="date">Date</label>
                        <input type="date" name="date" id="date" required
                               value="<?= htmlspecialchars($transaction['transaction_date']) ?>">
                    </div>

                    <div class="form-group">
                        <label for="entry_type">Type of Entry</label>
                        <input type="text" value="<?= htmlspecialchars($transaction['entry_type']) ?>" readonly>
                        <input type="hidden" name="entry_type" id="entry_type" 
                               value="<?= htmlspecialchars($transaction['entry_type']) ?>">
                    </div>
                </div>

                <div id="other-detail-section" class="form-group" style="display: none;">
                    <label for="other_mode_detail">Other Payment Mode Detail</label>
                    <input type="text" name="other_mode_detail" id="other_mode_detail"
                           value="<?= htmlspecialchars($transaction['other_mode_detail'] ?? '') ?>"
                           oninput="this.value = this.value.replace(/[^A-Za-z ]/g, '')"
                           pattern="[A-Za-z ]+"
                           title="Only letters and spaces are allowed">
                </div>
            </div>

            <div class="form-section">
                <h3>Transaction Information</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="ref_no">Ref. No. (UTR/Bank Ref)</label>
                        <input type="text" name="ref_no" id="ref_no" required
                               value="<?= htmlspecialchars($transaction['ref_no']) ?>"
                               oninput="this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '')"
                               pattern="[A-Z0-9]+"
                               title="Only uppercase letters and numbers are allowed">
                    </div>

                    <div class="form-group">
                        <label for="amount">Amount (₹)</label>
                        <input type="number" name="amount" id="amount" min="0" step="1" required
                               value="<?= htmlspecialchars($transaction['amount']) ?>">
                    </div>

                    <div class="form-group">
                        <label for="transaction_category">Type of Transaction</label>
                        <select name="transaction_category" id="transaction_category" required>
                            <option value="">Select Transaction Type</option>
                            <?php foreach ($transactionCategories as $category): ?>
                                <option value="<?= htmlspecialchars($category) ?>" 
                                    <?= $transaction['transaction_category'] === $category ? 'selected' : '' ?>>
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
                        <select name="broker_id" id="broker_id">
                            <option value="">Select Broker</option>
                            <?php foreach ($brokers as $broker): ?>
                                <option value="<?= htmlspecialchars($broker['br_id']) ?>" 
                                    <?= $transaction['broker_id'] == $broker['br_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($broker['br_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="transaction_name">Transaction Name</label>
                        <select name="transaction_name" id="transaction_name">
                            <option value="">Select Transaction</option>
                            <?php foreach ($transactionTypes as $type): ?>
                                <option value="<?= htmlspecialchars($type['id']) ?>" 
                                    <?= $transaction['transaction_type_id'] == $type['id'] ? 'selected' : '' ?>>
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
                        <input type="text" id="partner_search" 
                               placeholder="Type to search partners..." list="partnerSuggestions"
                               value="<?php
                                   foreach ($partners as $partner) {
                                       if ($partner['partner_id'] === $transaction['partner_id']) {
                                           echo htmlspecialchars($partner['partner_id'] . ' - ' . $partner['partner_name']);
                                           break;
                                       }
                                   }
                               ?>">
                        <datalist id="partnerSuggestions">
                            <?php foreach ($partners as $partner): ?>
                                <option data-id="<?= htmlspecialchars($partner['partner_id']) ?>" 
                                        value="<?= htmlspecialchars($partner['partner_id'] . ' - ' . $partner['partner_name']) ?>">
                            <?php endforeach; ?>
                        </datalist>
                        <p class="input-hint">Start typing to search partners by ID or name</p>
                    </div>
                    <input type="hidden" name="partner_id" id="partner_id" 
                           value="<?= htmlspecialchars($transaction['partner_id'] ?? '') ?>">
                </div>
            </div>

            <div class="form-section">
                <h3>Additional Information</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="invoice_mapping">Invoice Mapping</label>
                        <select name="invoice_mapping" id="invoice_mapping" required>
                            <option value="">Select Option</option>
                            <?php foreach ($mappingOptions as $option): ?>
                                <option value="<?= htmlspecialchars($option) ?>" 
                                    <?= $transaction['invoice_mapping'] === $option ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($option) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="gst_mapping">GST Mapping</label>
                        <select name="gst_mapping" id="gst_mapping" required>
                            <option value="">Select Option</option>
                            <?php foreach ($mappingOptions as $option): ?>
                                <option value="<?= htmlspecialchars($option) ?>" 
                                    <?= $transaction['gst_mapping'] === $option ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($option) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
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
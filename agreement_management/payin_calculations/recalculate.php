<?php
set_time_limit(300);
error_reporting(E_ERROR);
ini_set('display_errors', 0);

require '../config/database.php';

define('TDS_PERCENT', 0); // 5%
define('GST_PERCENT', 0); // 18%

header('Content-Type: application/json');

try {
    // Step 1: Fetch eligible policies
    $lciQuery = "SELECT 
                l.id AS lead_id,
                l.customer_name,
                l.policy_no,
                l.policy_issued,
                l.policy_name,
                l.policy_premium,
                c.c_name AS insurance_company_name,
                l.company AS company_code,
                p.pl_name AS plan_name,
                l.plan_name AS plan_code,
                l.policy_termm AS pt,
                l.premiun_paying_term AS ppt,
                l.net_amt AS net_premium,
                b.br_id AS broker_code,
                b.br_name AS broker_name,
                cup.name AS rm_name,
                cup.team AS team_name,
                l.login_app_no,
                l.premium_frequencyy,
                l.case_type
            FROM life_cum_investment_form_temp l
            INNER JOIN plans p ON l.plan_name = p.pl_id
            LEFT JOIN brokers b ON l.login_broker = b.br_id
            LEFT JOIN companies c ON l.company = c.c_id
            LEFT JOIN first_register fr ON l.ref = fr.refercode
            LEFT JOIN corporate_user_permission cup ON fr.addedBy = cup.id
            WHERE l.case_type = 'New Fresh'
            AND l.status = 'Policy Issued'
            AND l.policy_issued IS NOT NULL
            AND l.net_amt IS NOT NULL
            AND l.policy_termm IS NOT NULL
            AND l.premiun_paying_term IS NOT NULL
            AND l.company IS NOT NULL
            AND l.plan_name IS NOT NULL
            AND l.login_broker IS NOT NULL";
    
    $eligiblePolicies = $pdo->query($lciQuery)->fetchAll(PDO::FETCH_ASSOC);

     // NEW: Build agreement lookup
    $agreementLookup = [];
    $agreementStmt = $pdo->query("SELECT broker_id, company_id, tds, gst FROM agreements");
    while ($row = $agreementStmt->fetch(PDO::FETCH_ASSOC)) {
        $key = $row['broker_id'] . '|' . $row['company_id'];
        $agreementLookup[$key] = [
            'tds' => $row['tds'],
            'gst' => $row['gst']
        ];
    }
    // Step 2: Fetch all relevant grid data in one query
    $gridQuery = "SELECT 
            pg.broker_code,
            pg.company_code,
            pg.plan_code,
            pg.pt,
            pg.ppt,
            pg.product_name AS product,
            pg.subproduct_name AS subproduct,
            pg.brokerage_type,
            pg.case_type,
            pg.commission_type,
            pg.applicable_percentage,
            pg.premium_from,
            pg.premium_to,
            pg.category,
            pg.brokerage_from AS applicable_from,
            pg.brokerage_to AS applicable_to,
            pg.target_amount,
            pg.incentive_from,
            pg.incentive_to,
            pg.no_of_tickets,
            pg.contest_target_amount,
            pg.incentive_target_amount,
            pg.incentive_applicable_percentage,
            a.gst,
            a.tds
        FROM payin_grid pg
        LEFT JOIN agreements a ON pg.broker_code = a.broker_id AND pg.company_code = a.company_id
        WHERE pg.broker_code IN (SELECT DISTINCT login_broker FROM life_cum_investment_form_temp WHERE case_type = 'New Fresh' AND status = 'Policy Issued')
        AND pg.company_code IN (SELECT DISTINCT company FROM life_cum_investment_form_temp WHERE case_type = 'New Fresh' AND status = 'Policy Issued')";
    
    $gridData = $pdo->query($gridQuery)->fetchAll(PDO::FETCH_ASSOC);

    // Step 3: Organize grid data for quick lookup
    $gridLookup = [];
    foreach ($gridData as $row) {
        $key = implode('|', [
            $row['broker_code'],
            $row['company_code'],
            $row['plan_code'] ?? '',
            $row['pt'] ?? '',
            $row['ppt'] ?? '',
            $row['category']
        ]);
        $gridLookup[$key][] = $row;
    }

    // Step 4: Clear existing calculations
    $pdo->exec("DELETE FROM payin_calculation WHERE lead_id IN (SELECT id FROM life_cum_investment_form_temp WHERE case_type = 'New Fresh' AND status = 'Policy Issued')");

    // Step 5: Prepare batch insert
    $insertQuery = "INSERT INTO payin_calculation (
        lead_id, product, customer_name, broker_name, company_name, plan_name, 
        pt_ppt, net_premium, base_percent, base_amount, orc_percent, orc_amount, 
        incentive_percent, incentive_amount, contest_percent, contest_amount, 
        total_payin_percent, payin_amount, tds_percent, tds_amount, gst_percent, 
        gst_amount, gross_receipt, net_receipt, rm_name, team, policy_no, 
        policy_issued, login_app_no, case_type, premium_frequencyy, 
        broker_id, company_id, plan_id
    ) VALUES ";
    
    $values = [];
    $placeholders = [];
    $batchSize = 100;
    $counter = 0;
    $valuesPerRow = 34; // Updated to 31 existing + 3 new columns

    foreach ($eligiblePolicies as $policy) {
        // Initialize percentages and amounts
        $basePercent = 0;
        $orcPercent = 0;
        $incentivePercent = 0;
        $contestPercent = 0;
        // $tdsPercent = null;
        // $gstPercent = null;

        // NEW: Get TDS/GST from agreements
        $agreementKey = $policy['broker_code'] . '|' . $policy['company_code'];
        $agreement = $agreementLookup[$agreementKey] ?? null;
        $tdsPercent = $agreement['tds'] ?? TDS_PERCENT;  // Use agreement value or default
        $gstPercent = $agreement['gst'] ?? GST_PERCENT;  // Use agreement value or default
        
        // Lookup grid data
        $keyBase = implode('|', [$policy['broker_code'], $policy['company_code'], $policy['plan_code'], $policy['pt'], $policy['ppt'], 'Base']);
        $keyORC = implode('|', [$policy['broker_code'], $policy['company_code'], $policy['plan_code'], $policy['pt'], $policy['ppt'], 'ORC']);
        $keyContest = implode('|', [$policy['broker_code'], $policy['company_code'], $policy['plan_code'], $policy['pt'], $policy['ppt'], 'Contest']);
        $keyIncentive = implode('|', [$policy['broker_code'], $policy['company_code'], '', '', '', 'Incentive']);

        foreach ([$keyBase => 'Base', $keyORC => 'ORC', $keyContest => 'Contest'] as $key => $category) {
            if (isset($gridLookup[$key])) {
                foreach ($gridLookup[$key] as $gridData) {
                    if ($policy['net_premium'] >= $gridData['premium_from'] && 
                        $policy['net_premium'] <= $gridData['premium_to'] && 
                        $policy['policy_issued'] >= $gridData['applicable_from'] && 
                        $policy['policy_issued'] <= $gridData['applicable_to']) {
                        switch ($category) {
                            case 'Base':
                                $basePercent = $gridData['applicable_percentage'];
                                break;
                            case 'ORC':
                                $orcPercent = $gridData['applicable_percentage'];
                                break;
                            case 'Contest':
                                $contestPercent = $gridData['applicable_percentage'];
                                break;
                        }
                        // $tdsPercent = $tdsPercent ?? $gridData['tds'] ?? TDS_PERCENT;
                        // $gstPercent = $gstPercent ?? $gridData['gst'] ?? GST_PERCENT;
                    }
                }
            }
        }

        // Process Incentive
        if (isset($gridLookup[$keyIncentive])) {
            foreach ($gridLookup[$keyIncentive] as $gridData) {
                if ($policy['net_premium'] >= $gridData['target_amount'] && 
                    $policy['policy_issued'] >= $gridData['incentive_from'] && 
                    $policy['policy_issued'] <= $gridData['incentive_to']) {
                    $incentivePercent = $gridData['applicable_percentage'];
                    // $tdsPercent = $tdsPercent ?? $gridData['tds'] ?? TDS_PERCENT;
                    // $gstPercent = $gstPercent ?? $gridData['gst'] ?? GST_PERCENT;
                }
            }
        }

        // Skip if no percentages are set
        if ($basePercent == 0 && $orcPercent == 0 && $incentivePercent == 0 && $contestPercent == 0) {
            continue;
        }

        // Calculate amounts
        $baseAmount = $policy['net_premium'] * $basePercent / 100;
        $orcAmount = $policy['net_premium'] * $orcPercent / 100;
        $incentiveAmount = $policy['net_premium'] * $incentivePercent / 100;
        $contestAmount = $policy['net_premium'] * $contestPercent / 100;
        
        $totalPayinPercent = $basePercent + $orcPercent + $incentivePercent + $contestPercent;
        $payinAmount = $policy['net_premium'] * $totalPayinPercent / 100;
        
        $tdsPercent = $tdsPercent ?? TDS_PERCENT;
        $gstPercent = $gstPercent ?? GST_PERCENT;
        
        $tdsAmount = $payinAmount * $tdsPercent / 100;
        $gstAmount = $payinAmount * $gstPercent / 100;
        
        $grossReceipt = $payinAmount - $tdsAmount + $gstAmount;
        $netReceipt = $payinAmount - $tdsAmount;

        // Add to batch insert
        $placeholders[] = "(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $rowValues = [
            $policy['lead_id'],
            'Insurance',
            $policy['customer_name'],
            $policy['broker_name'],
            $policy['insurance_company_name'],
            $policy['plan_name'],
            $policy['pt'] . '/' . $policy['ppt'],
            $policy['net_premium'],
            $basePercent,
            $baseAmount,
            $orcPercent,
            $orcAmount,
            $incentivePercent,
            $incentiveAmount,
            $contestPercent,
            $contestAmount,
            $totalPayinPercent,
            $payinAmount,
            $tdsPercent,
            $tdsAmount,
            $gstPercent,
            $gstAmount,
            $grossReceipt,
            $netReceipt,
            $policy['rm_name'] ?? 'N/A',
            $policy['team_name'] ?? 'N/A',
            $policy['policy_no'],
            $policy['policy_issued'],
            $policy['login_app_no'] ?? 'N/A',
            $policy['case_type'] ?? 'N/A',
            $policy['premium_frequencyy'] ?? 'N/A',
            $policy['broker_code'],
            $policy['company_code'],
            $policy['plan_code']
        ];

        // Verify correct number of values
        if (count($rowValues) !== $valuesPerRow) {
            throw new Exception("Invalid number of values for policy lead_id {$policy['lead_id']}: expected $valuesPerRow, got " . count($rowValues));
        }

        $values = array_merge($values, $rowValues);

        $counter++;
        if ($counter >= $batchSize) {
            $sql = $insertQuery . implode(', ', $placeholders);
            $stmt = $pdo->prepare($sql);
            if (count($values) !== $counter * $valuesPerRow) {
                throw new Exception("Value-placeholder mismatch: expected " . ($counter * $valuesPerRow) . " values, got " . count($values));
            }
            $stmt->execute($values);
            $placeholders = [];
            $values = [];
            $counter = 0;
        }
    }

    // Insert remaining rows
    if (!empty($placeholders)) {
        $sql = $insertQuery . implode(', ', $placeholders);
        $stmt = $pdo->prepare($sql);
        if (count($values) !== $counter * $valuesPerRow) {
            throw new Exception("Final batch value-placeholder mismatch: expected " . ($counter * $valuesPerRow) . " values, got " . count($values));
        }
        $stmt->execute($values);
    }

    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Unexpected error: ' . $e->getMessage()]);
}
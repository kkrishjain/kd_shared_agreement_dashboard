<?php
set_time_limit(300);
error_reporting(E_ERROR | E_WARNING | E_PARSE);
ini_set('display_errors', 1);

require '../config/database.php';

define('TDS_PERCENT', 0); // Default TDS percent
define('GST_PERCENT', 0); // Default GST percent

header('Content-Type: application/json');

try {
    // Step 1: Fetch eligible health insurance policies with only necessary fields
    $healthQuery = "SELECT 
                h.id AS lead_id,
                h.customer_name,
                h.policy_number,
                h.policy_issued,
                h.policy_name,
                h.net_premium,
                c.cns_name AS insurance_company_name,
                h.company AS company_code,
                pi.pl_name AS plan_name,
                h.plan_name AS plan_code,
                h.policy_term AS pt,
                h.policy_broker AS broker_code,
                b.br_name AS broker_name,
                a.tds AS tds_percent,
                a.gst AS gst_percent,
                cup.name AS mr_name,
                cup.team AS team,
                h.status
            FROM health_insurance_form h
            INNER JOIN plans_insurance pi ON h.plan_name = pi.pl_id
            INNER JOIN company_names_standardized c ON pi.pl_c_id = c.cns_id AND c.cns_product = 'hi'
            LEFT JOIN brokers b ON h.policy_broker = b.br_id
            LEFT JOIN agreements a ON h.policy_broker = a.broker_id AND h.company = a.company_id
            LEFT JOIN first_register fr ON h.ref = fr.refercode
            LEFT JOIN corporate_user_permission cup ON fr.addedBy = cup.id
            WHERE h.status = 'Policy Issued'
            AND h.policy_issued IS NOT NULL
            AND h.net_premium IS NOT NULL
            AND h.policy_term IS NOT NULL
            AND h.company IS NOT NULL
            AND h.plan_name IS NOT NULL
            AND h.policy_broker IS NOT NULL";
    
    $eligiblePolicies = $pdo->query($healthQuery);
    if ($eligiblePolicies === false) {
        $errorInfo = $pdo->errorInfo();
        throw new Exception("Error during policy fetch: Database error: SQLSTATE[{$errorInfo[0]}]: {$errorInfo[2]}");
    }
    $eligiblePolicies = $eligiblePolicies->fetchAll(PDO::FETCH_ASSOC);

    if (empty($eligiblePolicies)) {
        throw new Exception("No eligible health insurance policies found for recalculation");
    }

    // Step 2: Fetch all relevant grid data in one query with updated fields
    $gridQuery = "SELECT 
            pg.broker_code,
            pg.company_code,
            pg.plan_code,
            pg.brokerage_type,
            pg.category,
            pg.applicable_percentage,
            pg.premium_from,
            pg.premium_to,
            pg.policy_combination,
            pg.case_type,
            pg.location,
            pg.applicable_start,
            pg.applicable_end,
            pg.pt
        FROM ins_payin_grid_health pg
        WHERE pg.broker_code IN (SELECT DISTINCT policy_broker FROM health_insurance_form WHERE status = 'Policy Issued')
        AND pg.company_code IN (SELECT DISTINCT company FROM health_insurance_form WHERE status = 'Policy Issued')";
    
    $gridData = $pdo->query($gridQuery);
    if ($gridData === false) {
        $errorInfo = $pdo->errorInfo();
        throw new Exception("Error during grid data fetch: Database error: SQLSTATE[{$errorInfo[0]}]: {$errorInfo[2]}");
    }
    $gridData = $gridData->fetchAll(PDO::FETCH_ASSOC);

    // Step 3: Organize grid data for quick lookup
    $gridLookup = [];
    foreach ($gridData as $row) {
        $key = implode('|', [
            $row['broker_code'],
            $row['company_code'],
            $row['plan_code'],
            $row['pt'],
            $row['brokerage_type'],
            $row['category']
        ]);
        $gridLookup[$key][] = $row;
    }

    // Step 4: Clear existing calculations
    $deleteResult = $pdo->exec("DELETE FROM payin_health_calculation");
    if ($deleteResult === false) {
        $errorInfo = $pdo->errorInfo();
        throw new Exception("Error during calculation cleanup: Database error: SQLSTATE[{$errorInfo[0]}]: {$errorInfo[2]}");
    }

    // Step 5: Prepare batch insert with updated fields
    $insertQuery = "INSERT INTO payin_health_calculation (
        lead_id,
        product,
        customer_name,
        broker_name,
        company_name,
        plan_name,
        pt,
        net_premium,
        base_percent,
        base_amount,
        reward_percent,
        reward_amount,
        total_payin_percent,
        payin_amount,
        tds_percent,
        tds_amount,
        gst_percent,
        gst_amount,
        gross_receipt,
        net_receipt,
        mr_name,
        team,
        policy_number,
        policy_issued,
        calculation_date,
        policy_type,
        broker_id,
        company_id,
        plan_id,
        location,
        policy_name,
        status
    ) VALUES ";
    
    $values = [];
    $placeholders = [];
    $batchSize = 100;
    $counter = 0;

    foreach ($eligiblePolicies as $policy) {
        try {
            $netPremium = $policy['net_premium'];

            // Initialize percentages and amounts
            $basePercent = 0;
            $rewardPercent = 0;
            
            // Get TDS and GST from policy data
            $tdsPercent = $policy['tds_percent'] ?? TDS_PERCENT;
            $gstPercent = $policy['gst_percent'] ?? GST_PERCENT;
            
            // Lookup grid data
            $key = implode('|', [
                $policy['broker_code'], 
                $policy['company_code'], 
                $policy['plan_code'], 
                $policy['pt'], 
                'Commission', // brokerage_type
                'Standard'    // category
            ]);

            // Process commission percentage
            if (isset($gridLookup[$key])) {
                foreach ($gridLookup[$key] as $gridData) {
                    // Check premium range
                    $premiumMatch = $netPremium >= $gridData['premium_from'] && 
                                  $netPremium <= $gridData['premium_to'];
                    
                    // Check applicable date range
                    $dateMatch = $policy['policy_issued'] >= $gridData['applicable_start'] && 
                                $policy['policy_issued'] <= $gridData['applicable_end'];
                    
                    // Check policy combination if specified
                    $policyCombinationMatch = empty($gridData['policy_combination']) || 
                                            $gridData['policy_combination'] == $policy['policy_combination'];
                    
                    // Check case type if specified
                    $caseTypeMatch = empty($gridData['case_type']) || 
                                   $gridData['case_type'] == $policy['case_type'];
                    
                    // Check location if specified
                    $locationMatch = empty($gridData['location']) || 
                                    $gridData['location'] == $policy['location'];
                    
                    if ($premiumMatch && $dateMatch && $policyCombinationMatch && 
                        $caseTypeMatch && $locationMatch) {
                        $basePercent = $gridData['applicable_percentage'];
                    }
                }
            }

            // Skip if no percentage is set
            if ($basePercent == 0) {
                continue;
            }

            // Calculate amounts
            $baseAmount = $netPremium * $basePercent / 100;
            $rewardAmount = 0;
            $totalPayinPercent = $basePercent;
            $payinAmount = $netPremium * $totalPayinPercent / 100;
            $tdsAmount = $payinAmount * $tdsPercent / 100;
            $gstAmount = $payinAmount * $gstPercent / 100;
            $grossReceipt = $payinAmount - $tdsAmount + $gstAmount;
            $netReceipt = $payinAmount - $tdsAmount;

            // Add to batch insert
            $placeholders[] = "(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $values = array_merge($values, [
                $policy['lead_id'],
                'hi', // product (health insurance)
                $policy['customer_name'],
                $policy['broker_name'] ?? 'Unknown Broker',
                $policy['insurance_company_name'],
                $policy['plan_name'],
                $policy['pt'],
                $netPremium,
                $basePercent,
                $baseAmount,
                0, // reward_percent
                0, // reward_amount
                $totalPayinPercent,
                $payinAmount,
                $tdsPercent,
                $tdsAmount,
                $gstPercent,
                $gstAmount,
                $grossReceipt,
                $netReceipt,
                $policy['mr_name'] ?? 'N/A',
                $policy['team'] ?? 'N/A',
                $policy['policy_number'],
                $policy['policy_issued'],
                date('Y-m-d H:i:s'), // calculation_date
                $policy['case_type'] ?? 'N/A', // policy_type
                $policy['broker_code'], // broker_id
                $policy['company_code'], // company_id
                $policy['plan_code'], // plan_id
                $policy['location'] ?? 'N/A',
                $policy['policy_name'],
                $policy['status'] ?? 'Calculated'
            ]);

            $counter++;

            // Execute batch insert when batch size is reached
            if ($counter >= $batchSize) {
                $sql = $insertQuery . implode(', ', $placeholders);
                $stmt = $pdo->prepare($sql);
                if (!$stmt) {
                    $errorInfo = $pdo->errorInfo();
                    throw new Exception("Batch prepare failed: SQLSTATE[{$errorInfo[0]}]: {$errorInfo[2]}");
                }
                
                if (!$stmt->execute($values)) {
                    $errorInfo = $stmt->errorInfo();
                    throw new Exception("Batch execute failed: SQLSTATE[{$errorInfo[0]}]: {$errorInfo[2]}");
                }
                
                $placeholders = [];
                $values = [];
                $counter = 0;
            }
        } catch (Exception $e) {
            error_log("Error processing policy ID {$policy['lead_id']}: " . $e->getMessage());
            // Continue with next policy
        }
    }

    // Insert remaining rows
    if (!empty($placeholders)) {
        $sql = $insertQuery . implode(', ', $placeholders);
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
            $errorInfo = $pdo->errorInfo();
            throw new Exception("Final prepare failed: SQLSTATE[{$errorInfo[0]}]: {$errorInfo[2]}");
        }
        
        if (!$stmt->execute($values)) {
            $errorInfo = $stmt->errorInfo();
            throw new Exception("Final execute failed: SQLSTATE[{$errorInfo[0]}]: {$errorInfo[2]}");
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Successfully recalculated ' . count($eligiblePolicies) . ' health insurance policies',
        'processed_count' => $counter
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Unexpected error: ' . $e->getMessage()]);
}
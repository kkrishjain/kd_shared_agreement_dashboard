<?php
require_once "../config/database.php";

class Agreement {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function createAgreement($data) {
        $sql = "INSERT INTO agreements 
                (broker_id, broker_name, start_date, end_date, agreement_file, commission_file, number_of_cycles, business_cycle, gst_percentage, tds_percentage)
                VALUES 
                (:broker_id, :broker_name, :start_date, :end_date, :agreement_file, :commission_file, :number_of_cycles, :business_cycle, :gst_percentage, :tds_percentage)";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($data);
    }

    public function getAgreements() {
        $stmt = $this->pdo->query("SELECT * FROM agreements");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>

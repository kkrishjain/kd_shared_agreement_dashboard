<?php
require_once "../models/Agreement.php";

class AgreementController {
    private $model;

    public function __construct($pdo) {
        $this->model = new Agreement($pdo);
    }

    public function storeAgreement($data) {
        return $this->model->createAgreement($data);
    }

    public function fetchAgreements() {
        return $this->model->getAgreements();
    }
}
?>

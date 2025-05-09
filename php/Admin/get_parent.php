<?php
require '../db_config.php';

header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    echo json_encode(['error' => 'Не указан ID родителя']);
    exit;
}

$parentId = $_GET['id'];
$stmt = $pdo->prepare("SELECT * FROM Parents WHERE Parents_id = ?");
$stmt->execute([$parentId]);
$parent = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$parent) {
    echo json_encode(['error' => 'Родитель не найден']);
    exit;
}

echo json_encode($parent);
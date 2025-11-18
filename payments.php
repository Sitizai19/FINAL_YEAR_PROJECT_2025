<?php
// Payments Management
// File: api/payments.php

require_once 'config/database.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    $conn = getConnection();
    
    switch($method) {
        case 'GET':
            switch($action) {
                case 'list':
                    $stmt = $conn->prepare("
                        SELECT p.*, b.user_id, b.booking_date, b.booking_time,
                               u.first_name, u.last_name, u.email,
                               ps.service_name, ps.price as service_price
                        FROM payments p
                        JOIN bookings b ON p.booking_id = b.id
                        JOIN users u ON b.user_id = u.id
                        JOIN pet_services ps ON b.service_id = ps.id
                        ORDER BY p.payment_date DESC
                    ");
                    $stmt->execute();
                    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    echo json_encode(['success' => true, 'data' => $payments]);
                    break;
                    
                case 'get':
                    $id = $_GET['id'] ?? 0;
                    $stmt = $conn->prepare("
                        SELECT p.*, b.user_id, b.booking_date, b.booking_time,
                               u.first_name, u.last_name, u.email,
                               ps.service_name, ps.price as service_price
                        FROM payments p
                        JOIN bookings b ON p.booking_id = b.id
                        JOIN users u ON b.user_id = u.id
                        JOIN pet_services ps ON b.service_id = ps.id
                        WHERE p.id = ?
                    ");
                    $stmt->execute([$id]);
                    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
                    echo json_encode(['success' => true, 'data' => $payment]);
                    break;
                    
                case 'by_booking':
                    $booking_id = $_GET['booking_id'] ?? 0;
                    $stmt = $conn->prepare("SELECT * FROM payments WHERE booking_id = ? ORDER BY payment_date DESC");
                    $stmt->execute([$booking_id]);
                    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    echo json_encode(['success' => true, 'data' => $payments]);
                    break;
                    
                case 'summary':
                    $stmt = $conn->prepare("
                        SELECT 
                            COUNT(*) as total_payments,
                            SUM(CASE WHEN payment_status = 'completed' THEN amount ELSE 0 END) as total_revenue,
                            SUM(CASE WHEN payment_status = 'pending' THEN amount ELSE 0 END) as pending_amount,
                            SUM(CASE WHEN payment_status = 'failed' THEN amount ELSE 0 END) as failed_amount
                        FROM payments
                    ");
                    $stmt->execute();
                    $summary = $stmt->fetch(PDO::FETCH_ASSOC);
                    echo json_encode(['success' => true, 'data' => $summary]);
                    break;
                    
                default:
                    echo json_encode(['success' => false, 'message' => 'Invalid action']);
            }
            break;
            
        case 'POST':
            switch($action) {
                case 'create':
                    $booking_id = $_POST['booking_id'] ?? 0;
                    $payment_method = $_POST['payment_method'] ?? '';
                    $amount = $_POST['amount'] ?? 0;
                    $transaction_id = $_POST['transaction_id'] ?? '';
                    $notes = $_POST['notes'] ?? '';
                    
                    $stmt = $conn->prepare("INSERT INTO payments (booking_id, payment_method, amount, transaction_id, notes) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$booking_id, $payment_method, $amount, $transaction_id, $notes]);
                    
                    echo json_encode(['success' => true, 'message' => 'Payment created successfully', 'id' => $conn->lastInsertId()]);
                    break;
                    
                case 'update':
                    $id = $_POST['id'] ?? 0;
                    $payment_method = $_POST['payment_method'] ?? '';
                    $amount = $_POST['amount'] ?? 0;
                    $payment_status = $_POST['payment_status'] ?? 'pending';
                    $transaction_id = $_POST['transaction_id'] ?? '';
                    $notes = $_POST['notes'] ?? '';
                    
                    $stmt = $conn->prepare("UPDATE payments SET payment_method = ?, amount = ?, payment_status = ?, transaction_id = ?, notes = ? WHERE id = ?");
                    $stmt->execute([$payment_method, $amount, $payment_status, $transaction_id, $notes, $id]);
                    
                    echo json_encode(['success' => true, 'message' => 'Payment updated successfully']);
                    break;
                    
                case 'update_status':
                    $id = $_POST['id'] ?? 0;
                    $payment_status = $_POST['payment_status'] ?? 'pending';
                    
                    $stmt = $conn->prepare("UPDATE payments SET payment_status = ? WHERE id = ?");
                    $stmt->execute([$payment_status, $id]);
                    
                    echo json_encode(['success' => true, 'message' => 'Payment status updated successfully']);
                    break;
                    
                case 'delete':
                    $id = $_POST['id'] ?? 0;
                    $stmt = $conn->prepare("DELETE FROM payments WHERE id = ?");
                    $stmt->execute([$id]);
                    
                    echo json_encode(['success' => true, 'message' => 'Payment deleted successfully']);
                    break;
                    
                default:
                    echo json_encode(['success' => false, 'message' => 'Invalid action']);
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid method']);
    }
    
} catch(Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>

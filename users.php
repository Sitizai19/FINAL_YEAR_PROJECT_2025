<?php
// Users Management
// File: api/users.php

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
                    $stmt = $conn->prepare("SELECT id, first_name, last_name, email, phone_number, role, is_active, created_at FROM users ORDER BY first_name, last_name");
                    $stmt->execute();
                    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    echo json_encode(['success' => true, 'data' => $users]);
                    break;
                    
                case 'get':
                    $id = $_GET['id'] ?? 0;
                    $stmt = $conn->prepare("SELECT id, first_name, last_name, email, phone_number, address, created_at FROM users WHERE id = ?");
                    $stmt->execute([$id]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    echo json_encode(['success' => true, 'data' => $user]);
                    break;
                    
                case 'by_email':
                    $email = $_GET['email'] ?? '';
                    $stmt = $conn->prepare("SELECT id, first_name, last_name, email, phone_number, address, role, is_active FROM users WHERE email = ?");
                    $stmt->execute([$email]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    echo json_encode(['success' => true, 'data' => $user]);
                    break;
                    
                case 'stats':
                    $stmt = $conn->prepare("
                        SELECT 
                            COUNT(*) as total_users,
                            SUM(CASE WHEN role = 'user' THEN 1 ELSE 0 END) as regular_users,
                            SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as admin_users,
                            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_users
                        FROM users
                    ");
                    $stmt->execute();
                    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
                    echo json_encode(['success' => true, 'data' => $stats]);
                    break;
                    
                default:
                    echo json_encode(['success' => false, 'message' => 'Invalid action']);
            }
            break;
            
        case 'POST':
            switch($action) {
                case 'create':
                    $first_name = $_POST['first_name'] ?? '';
                    $last_name = $_POST['last_name'] ?? '';
                    $email = $_POST['email'] ?? '';
                    $phone = $_POST['phone'] ?? '';
                    $address = $_POST['address'] ?? '';
                    $password_hash = $_POST['password_hash'] ?? '';
                    $role = $_POST['role'] ?? 'user';
                    
                    $stmt = $conn->prepare("INSERT INTO users (first_name, last_name, email, phone, address, password_hash, role) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$first_name, $last_name, $email, $phone, $address, $password_hash, $role]);
                    
                    echo json_encode(['success' => true, 'message' => 'User created successfully', 'id' => $conn->lastInsertId()]);
                    break;
                    
                case 'update':
                    $id = $_POST['id'] ?? 0;
                    $first_name = $_POST['first_name'] ?? '';
                    $last_name = $_POST['last_name'] ?? '';
                    $email = $_POST['email'] ?? '';
                    $phone = $_POST['phone'] ?? '';
                    $address = $_POST['address'] ?? '';
                    $role = $_POST['role'] ?? 'user';
                    $is_active = $_POST['is_active'] ?? 1;
                    
                    $stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ?, address = ?, role = ?, is_active = ? WHERE id = ?");
                    $stmt->execute([$first_name, $last_name, $email, $phone, $address, $role, $is_active, $id]);
                    
                    echo json_encode(['success' => true, 'message' => 'User updated successfully']);
                    break;
                    
                case 'update_password':
                    $id = $_POST['id'] ?? 0;
                    $password_hash = $_POST['password_hash'] ?? '';
                    
                    $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                    $stmt->execute([$password_hash, $id]);
                    
                    echo json_encode(['success' => true, 'message' => 'Password updated successfully']);
                    break;
                    
                case 'toggle_status':
                    $id = $_POST['id'] ?? 0;
                    $stmt = $conn->prepare("UPDATE users SET is_active = NOT is_active WHERE id = ?");
                    $stmt->execute([$id]);
                    
                    echo json_encode(['success' => true, 'message' => 'User status updated successfully']);
                    break;
                    
                case 'delete':
                    $id = $_POST['id'] ?? 0;
                    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
                    $stmt->execute([$id]);
                    
                    echo json_encode(['success' => true, 'message' => 'User deleted successfully']);
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

<?php
// Pets Management
// File: pets.php

// Suppress all error output to prevent HTML from being sent
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Start output buffering to catch any unwanted output
ob_start();

require_once 'config/database.php';

header('Content-Type: application/json');

// Start session to get user ID (only if not already started)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';


    try {
        // Use the getConnection function from config/database.php
        $conn = getConnection();
        if (!$conn) {
            echo json_encode(['success' => false, 'message' => 'Database connection failed']);
            exit;
        }
    
    switch($method) {
        case 'GET':
            switch($action) {
                case 'list':
                    // Get pets for current logged-in user
                    $user_id = $_SESSION['user_id'] ?? 0;
                    if (!$user_id) {
                        echo json_encode(['success' => false, 'message' => 'User not logged in']);
                        break;
                    }
                    
                    // Handle Google OAuth user ID format
                    if (is_string($user_id) && strpos($user_id, 'google_') === 0) {
                        $googleId = str_replace('google_', '', $user_id);
                        
                        // Look up the actual database user ID
                        $stmt = $conn->prepare("SELECT id FROM users WHERE google_id = ?");
                        $stmt->execute([$googleId]);
                        $user = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($user) {
                            $user_id = $user['id'];
                        } else {
                            // No user found, return empty pets array
                            echo json_encode(['success' => true, 'data' => []]);
                            break;
                        }
                    }
                    
                    $stmt = $conn->prepare("SELECT * FROM pets WHERE user_id = ? ORDER BY name");
                    $stmt->execute([$user_id]);
                    $pets = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    echo json_encode(['success' => true, 'data' => $pets]);
                    break;
                    
                case 'get':
                    $id = $_GET['id'] ?? 0;
                    $stmt = $conn->prepare("SELECT * FROM pets WHERE id = ?");
                    $stmt->execute([$id]);
                    $pet = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($pet) {
                        // Process vaccination data similar to auth.php
                        $pet['vaccinations'] = [];
                        
                        // Clean up vaccine data - handle empty strings and invalid dates
                        $vaccine_name = trim($pet['vaccine_name'] ?? '');
                        $vaccine_date = trim($pet['vaccine_date'] ?? '');
                        
                        // Skip invalid dates like '0000-00-00' or empty strings
                        if ($vaccine_date === '0000-00-00' || $vaccine_date === '' || $vaccine_date === 'NULL') {
                            $vaccine_date = '';
                        }
                        
                        if (!empty($vaccine_name) && $vaccine_name !== 'NULL') {
                            $vaccine_names = explode(', ', $vaccine_name);
                            $vaccine_dates = !empty($vaccine_date) ? explode(', ', $vaccine_date) : [];
                            
                            for ($i = 0; $i < count($vaccine_names); $i++) {
                                $current_vaccine_name = trim($vaccine_names[$i]);
                                $current_vaccine_date = isset($vaccine_dates[$i]) ? trim($vaccine_dates[$i]) : '';
                                
                                // Skip invalid dates
                                if ($current_vaccine_date === '0000-00-00' || $current_vaccine_date === 'NULL') {
                                    $current_vaccine_date = '';
                                }
                                
                                if (!empty($current_vaccine_name)) {
                                    $pet['vaccinations'][] = [
                                        'vaccine_name' => $current_vaccine_name,
                                        'vaccination_date' => $current_vaccine_date
                                    ];
                                }
                            }
                        }
                    }
                    
                    echo json_encode(['success' => true, 'data' => $pet]);
                    break;
                    
                default:
                    echo json_encode(['success' => false, 'message' => 'Invalid GET action: ' . $action]);
            }
            break;
            
        case 'POST':
            switch($action) {
                case 'create':
                    $user_id = $_SESSION['user_id'] ?? 0;
                    if (!$user_id) {
                        echo json_encode(['success' => false, 'message' => 'User not logged in']);
                        break;
                    }
                    
                    // Handle Google OAuth user ID format
                    if (is_string($user_id) && strpos($user_id, 'google_') === 0) {
                        $googleId = str_replace('google_', '', $user_id);
                        
                        // Look up the actual database user ID
                        $stmt = $conn->prepare("SELECT id FROM users WHERE google_id = ?");
                        $stmt->execute([$googleId]);
                        $user = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($user) {
                            $user_id = $user['id'];
                        } else {
                            echo json_encode(['success' => false, 'message' => 'User not found in database']);
                            break;
                        }
                    }
                    
                    $name = $_POST['petName'] ?? '';
                    $pet_category = $_POST['petCategory'] ?? '';
                    $breed = $_POST['petBreed'] ?? '';
                    $age = $_POST['petAge'] ?? null;
                    $gender = $_POST['petGender'] ?? '';
                    $weight = $_POST['petWeight'] ?? null;
                    $spayed_neutered = $_POST['petSpayed'] ?? '';
                    $medical_type = $_POST['petEventType'] ?? '';
                    $medical_condition = $_POST['petMedicalCondition'] ?? '';
                    $notes = $_POST['petNotes'] ?? '';
                    $photo_path = $_POST['photo_url'] ?? '';
                    
                    // Handle photo upload if a new file is provided
                    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                        try {
                            // Include upload handler class
                            if (!class_exists('FileUploadHandler')) {
                                require_once 'upload.php';
                            }
                            $uploadHandler = new FileUploadHandler();
                            // Use a temporary ID for new pets, will be updated after insert
                            $tempId = time();
                            $uploadResult = $uploadHandler->uploadPetPhoto($_FILES['photo'], $tempId);
                            
                            if ($uploadResult['success']) {
                                $photo_path = $uploadResult['url'];
                                error_log("Photo uploaded successfully: " . $photo_path);
                            } else {
                                error_log("Photo upload failed: " . $uploadResult['message']);
                            }
                        } catch (Exception $e) {
                            error_log("Photo upload error: " . $e->getMessage());
                        }
                    }
                    
                    // Handle vaccination data - use the processed vaccine data from JavaScript
                    $vaccine_name = $_POST['vaccine_name'] ?? '';
                    $vaccine_date = $_POST['vaccine_date'] ?? '';
                    
                    // Debug logging
                    error_log("=== VACCINATION DEBUG (CREATE) ===");
                    error_log("Received vaccine_name: '$vaccine_name'");
                    error_log("Received vaccine_date: '$vaccine_date'");
                    error_log("==========================================");
                    
                    // Validate required fields
                    if (empty($name) || empty($pet_category) || empty($age)) {
                        echo json_encode(['success' => false, 'message' => 'Required fields must be filled']);
                        break;
                    }
                    
                    // Set default values for optional fields
                    if (empty($gender) || $gender === 'unknown') {
                        $gender = 'male'; // Default value
                    }
                    if (empty($spayed_neutered) || $spayed_neutered === 'unknown') {
                        $spayed_neutered = 'no'; // Default value
                    }
                    
                    // Check if new columns exist, otherwise use breed as fallback
                    $stmt = $conn->prepare("SHOW COLUMNS FROM pets LIKE 'pet_category'");
                    $stmt->execute();
                    $hasPetCategory = $stmt->fetch();
                    
                    // Debug logging for spayed_neutered
                    error_log("=== SPAYED/NEUTERED DEBUG (CREATE) ===");
                    error_log("Received petSpayed from POST: '" . ($_POST['petSpayed'] ?? 'NOT SET') . "'");
                    error_log("Processed spayed_neutered value: '$spayed_neutered'");
                    error_log("==========================================");
                    
                    // Check spayed_neutered column exists and get its enum values
                    $stmt = $conn->prepare("SHOW COLUMNS FROM pets WHERE Field = 'spayed_neutered'");
                    $stmt->execute();
                    $spayedColumn = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($spayedColumn) {
                        error_log("spayed_neutered column type: " . ($spayedColumn['Type'] ?? 'NOT FOUND'));
                    } else {
                        error_log("WARNING: spayed_neutered column does not exist in pets table!");
                    }
                    
                    if ($hasPetCategory) {
                        // Use pet_category field
                        try {
                            $stmt = $conn->prepare("INSERT INTO pets (user_id, name, pet_category, breed, age, gender, weight, spayed_neutered, medical_type, medical_condition, special_notes, photo_path, vaccine_name, vaccine_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            $result = $stmt->execute([$user_id, $name, $pet_category, $breed, $age, $gender, $weight, $spayed_neutered, $medical_type, $medical_condition, $notes, $photo_path, $vaccine_name, $vaccine_date]);
                            
                            if (!$result) {
                                $errorInfo = $stmt->errorInfo();
                                error_log("INSERT ERROR: " . print_r($errorInfo, true));
                                echo json_encode(['success' => false, 'message' => 'Failed to add pet: ' . ($errorInfo[2] ?? 'Unknown error')]);
                                break;
                            }
                        } catch (PDOException $e) {
                            error_log("PDO Exception during INSERT: " . $e->getMessage());
                            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
                            break;
                        }
                    } else {
                        // Fallback to old breed field for backward compatibility
                        try {
                            $stmt = $conn->prepare("INSERT INTO pets (user_id, name, breed, age, gender, weight, spayed_neutered, medical_type, medical_condition, special_notes, photo_path, vaccine_name, vaccine_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            $result = $stmt->execute([$user_id, $name, $breed, $age, $gender, $weight, $spayed_neutered, $medical_type, $medical_condition, $notes, $photo_path, $vaccine_name, $vaccine_date]);
                            
                            if (!$result) {
                                $errorInfo = $stmt->errorInfo();
                                error_log("INSERT ERROR: " . print_r($errorInfo, true));
                                echo json_encode(['success' => false, 'message' => 'Failed to add pet: ' . ($errorInfo[2] ?? 'Unknown error')]);
                                break;
                            }
                        } catch (PDOException $e) {
                            error_log("PDO Exception during INSERT: " . $e->getMessage());
                            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
                            break;
                        }
                    }
                    
                    $pet_id = $conn->lastInsertId();
                    
                    echo json_encode(['success' => true, 'message' => 'Pet added successfully', 'id' => $pet_id]);
                    break;
                    
                case 'update':
                    $id = $_POST['id'] ?? 0;
                    $name = $_POST['petName'] ?? '';
                    $pet_category = $_POST['petCategory'] ?? '';
                    $breed = $_POST['petBreed'] ?? '';
                    $age = $_POST['petAge'] ?? null;
                    $gender = $_POST['petGender'] ?? '';
                    $weight = $_POST['petWeight'] ?? null;
                    $spayed_neutered = $_POST['petSpayed'] ?? '';
                    $medical_type = $_POST['petEventType'] ?? '';
                    $medical_condition = $_POST['petMedicalCondition'] ?? '';
                    $notes = $_POST['petNotes'] ?? '';
                    $photo_path = $_POST['photo_url'] ?? '';
                    
                    // Handle vaccination data - use the processed vaccine data from JavaScript
                    $vaccine_name = $_POST['vaccine_name'] ?? '';
                    $vaccine_date = $_POST['vaccine_date'] ?? '';
                    
                    // Debug logging
                    error_log("=== VACCINATION DEBUG (UPDATE) ===");
                    error_log("Received vaccine_name: '$vaccine_name'");
                    error_log("Received vaccine_date: '$vaccine_date'");
                    error_log("==========================================");
                    
                    // Validate required fields first
                    if (empty($id) || empty($name) || empty($pet_category) || empty($age)) {
                        echo json_encode(['success' => false, 'message' => 'Required fields must be filled']);
                        break;
                    }
                    
                    // Handle photo upload if a new file is provided
                    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                        try {
                            // Include upload handler class
                            if (!class_exists('FileUploadHandler')) {
                                require_once 'upload.php';
                            }
                            $uploadHandler = new FileUploadHandler();
                            $uploadResult = $uploadHandler->uploadPetPhoto($_FILES['photo'], $id);
                            
                            if ($uploadResult['success']) {
                                $photo_path = $uploadResult['url'];
                                error_log("Photo uploaded successfully: " . $photo_path);
                            } else {
                                error_log("Photo upload failed: " . $uploadResult['message']);
                            }
                        } catch (Exception $e) {
                            error_log("Photo upload error: " . $e->getMessage());
                        }
                    }
                    
                    // Set default values for optional fields
                    if (empty($gender) || $gender === 'unknown') {
                        $gender = 'male'; // Default value
                    }
                    if (empty($spayed_neutered) || $spayed_neutered === 'unknown') {
                        $spayed_neutered = 'no'; // Default value
                    }
                    
                    // Debug logging for spayed_neutered
                    error_log("=== SPAYED/NEUTERED DEBUG (UPDATE) ===");
                    error_log("Received petSpayed from POST: '" . ($_POST['petSpayed'] ?? 'NOT SET') . "'");
                    error_log("Processed spayed_neutered value: '$spayed_neutered'");
                    error_log("==========================================");
                    
                    // Get current user ID
                    $user_id = $_SESSION['user_id'] ?? 0;
                    if (!$user_id) {
                        echo json_encode(['success' => false, 'message' => 'User not logged in']);
                        break;
                    }
                    
                    // Handle Google OAuth user ID format
                    if (is_string($user_id) && strpos($user_id, 'google_') === 0) {
                        $googleId = str_replace('google_', '', $user_id);
                        
                        // Look up the actual database user ID
                        $stmt = $conn->prepare("SELECT id FROM users WHERE google_id = ?");
                        $stmt->execute([$googleId]);
                        $user = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($user) {
                            $user_id = $user['id'];
                        } else {
                            echo json_encode(['success' => false, 'message' => 'User not found in database']);
                            break;
                        }
                    }
                    
                    // Verify the pet belongs to the current user
                    $stmt = $conn->prepare("SELECT user_id FROM pets WHERE id = ?");
                    $stmt->execute([$id]);
                    $pet = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$pet || $pet['user_id'] != $user_id) {
                        echo json_encode(['success' => false, 'message' => 'Pet not found or access denied']);
                        break;
                    }
                    
                    // Check if new columns exist, otherwise use breed as fallback
                    $stmt = $conn->prepare("SHOW COLUMNS FROM pets LIKE 'pet_category'");
                    $stmt->execute();
                    $hasPetCategory = $stmt->fetch();
                    
                    // Check spayed_neutered column exists and get its enum values
                    $stmt = $conn->prepare("SHOW COLUMNS FROM pets WHERE Field = 'spayed_neutered'");
                    $stmt->execute();
                    $spayedColumn = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($spayedColumn) {
                        error_log("spayed_neutered column type: " . ($spayedColumn['Type'] ?? 'NOT FOUND'));
                    } else {
                        error_log("WARNING: spayed_neutered column does not exist in pets table!");
                    }
                    
                    if ($hasPetCategory) {
                        // Use pet_category field
                        try {
                            $stmt = $conn->prepare("UPDATE pets SET name = ?, pet_category = ?, breed = ?, age = ?, gender = ?, weight = ?, spayed_neutered = ?, medical_type = ?, medical_condition = ?, vaccine_name = ?, vaccine_date = ?, special_notes = ?, photo_path = ? WHERE id = ? AND user_id = ?");
                            $result = $stmt->execute([$name, $pet_category, $breed, $age, $gender, $weight, $spayed_neutered, $medical_type, $medical_condition, $vaccine_name, $vaccine_date, $notes, $photo_path, $id, $user_id]);
                            
                            if (!$result) {
                                $errorInfo = $stmt->errorInfo();
                                error_log("UPDATE ERROR: " . print_r($errorInfo, true));
                                echo json_encode(['success' => false, 'message' => 'Failed to update pet: ' . ($errorInfo[2] ?? 'Unknown error')]);
                                break;
                            }
                            
                            // Verify the update
                            $verifyStmt = $conn->prepare("SELECT spayed_neutered FROM pets WHERE id = ?");
                            $verifyStmt->execute([$id]);
                            $updated = $verifyStmt->fetch(PDO::FETCH_ASSOC);
                            error_log("After UPDATE, spayed_neutered in DB: " . ($updated['spayed_neutered'] ?? 'NULL'));
                            
                        } catch (PDOException $e) {
                            error_log("PDO Exception during UPDATE: " . $e->getMessage());
                            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
                            break;
                        }
                    } else {
                        // Fallback to old breed field for backward compatibility
                        $breed = $pet_category; // Use pet_category as breed for now
                        try {
                            $stmt = $conn->prepare("UPDATE pets SET name = ?, breed = ?, age = ?, gender = ?, weight = ?, spayed_neutered = ?, medical_type = ?, medical_condition = ?, vaccine_name = ?, vaccine_date = ?, special_notes = ?, photo_path = ? WHERE id = ? AND user_id = ?");
                            $result = $stmt->execute([$name, $breed, $age, $gender, $weight, $spayed_neutered, $medical_type, $medical_condition, $vaccine_name, $vaccine_date, $notes, $photo_path, $id, $user_id]);
                            
                            if (!$result) {
                                $errorInfo = $stmt->errorInfo();
                                error_log("UPDATE ERROR: " . print_r($errorInfo, true));
                                echo json_encode(['success' => false, 'message' => 'Failed to update pet: ' . ($errorInfo[2] ?? 'Unknown error')]);
                                break;
                            }
                            
                        } catch (PDOException $e) {
                            error_log("PDO Exception during UPDATE: " . $e->getMessage());
                            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
                            break;
                        }
                    }
                    
                    echo json_encode(['success' => true, 'message' => 'Pet updated successfully']);
                    break;
                    
                case 'delete':
                    $id = $_POST['id'] ?? 0;
                    
                    if (empty($id)) {
                        echo json_encode(['success' => false, 'message' => 'ID is required for deletion']);
                        break;
                    }
                    
                    $stmt = $conn->prepare("DELETE FROM pets WHERE id = ?");
                    $stmt->execute([$id]);
                    
                    echo json_encode(['success' => true, 'message' => 'Pet deleted successfully']);
                    break;
                    
                default:
                    echo json_encode(['success' => false, 'message' => 'Invalid POST action: ' . $action]);
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid method: ' . $method]);
    }
    
} catch(Exception $e) {
    error_log("Pets API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

// Clean any unwanted output and ensure proper JSON response
$output = ob_get_clean();
if (!empty($output)) {
    // If there's output, check if it's valid JSON
    $decoded = json_decode($output, true);
    if ($decoded !== null) {
        echo $output;
    } else {
        // If not valid JSON, log the issue and send error
        error_log("Invalid JSON output: " . substr($output, 0, 200));
        echo json_encode(['success' => false, 'message' => 'Server response error']);
    }
}

?>

<?php
/**
 * File Upload Handler
 * Handles profile photos and pet photos
 */

// Suppress all error output when included as a library
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Include database connection function
require_once 'config/database.php';

class FileUploadHandler {
    private $uploadDir;
    private $allowedTypes;
    private $maxFileSize;
    
    public function __construct() {
        $this->uploadDir = 'uploads/';
        $this->allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $this->maxFileSize = PHP_INT_MAX; // No file size limit
        
        // Create upload directory if it doesn't exist
        if (!file_exists($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
        
        // Create subdirectories
        if (!file_exists($this->uploadDir . 'profiles/')) {
            mkdir($this->uploadDir . 'profiles/', 0755, true);
        }
        
        if (!file_exists($this->uploadDir . 'pets/')) {
            mkdir($this->uploadDir . 'pets/', 0755, true);
        }
    }
    
    /**
     * Upload profile photo
     */
    public function uploadProfilePhoto($file, $userId) {
        try {
            // Validate file
            $validation = $this->validateFile($file);
            if (!$validation['success']) {
                return $validation;
            }
            
            // Generate unique filename
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'profile_' . $userId . '_' . time() . '.' . $extension;
            $filepath = $this->uploadDir . 'profiles/' . $filename;
            
            // Move uploaded file
            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                // Return web-accessible URL
                $webUrl = 'uploads/profiles/' . $filename;
                return [
                    'success' => true,
                    'message' => 'Photo uploaded successfully',
                    'filename' => $filename,
                    'filepath' => $filepath,
                    'url' => $webUrl
                ];
            } else {
                return ['success' => false, 'message' => 'Failed to upload file'];
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Upload error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Upload pet photo
     */
    public function uploadPetPhoto($file, $petId) {
        try {
            // Validate file
            $validation = $this->validateFile($file);
            if (!$validation['success']) {
                return $validation;
            }
            
            // Generate unique filename
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'pet_' . $petId . '_' . time() . '.' . $extension;
            $filepath = $this->uploadDir . 'pets/' . $filename;
            
            // Move uploaded file
            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                // Return web-accessible URL
                $webUrl = 'uploads/pets/' . $filename;
                return [
                    'success' => true,
                    'message' => 'Photo uploaded successfully',
                    'filename' => $filename,
                    'filepath' => $filepath,
                    'url' => $webUrl
                ];
            } else {
                return ['success' => false, 'message' => 'Failed to upload file'];
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Upload error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Delete file
     */
    public function deleteFile($filepath) {
        try {
            if (file_exists($filepath)) {
                if (unlink($filepath)) {
                    return ['success' => true, 'message' => 'File deleted successfully'];
                } else {
                    return ['success' => false, 'message' => 'Failed to delete file'];
                }
            } else {
                return ['success' => false, 'message' => 'File not found'];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Delete error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Validate uploaded file
     */
    private function validateFile($file) {
        // Check if file was uploaded
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['success' => false, 'message' => 'No file uploaded'];
        }
        
        // File size check removed - no limit
        
        // Check file type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $this->allowedTypes)) {
            return ['success' => false, 'message' => 'Invalid file type. Only JPEG, PNG, GIF, and WebP are allowed'];
        }
        
        // Check for errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'Upload error: ' . $file['error']];
        }
        
        return ['success' => true];
    }
    
    /**
     * Convert image to base64
     */
    public function imageToBase64($filepath) {
        try {
            if (!file_exists($filepath)) {
                return ['success' => false, 'message' => 'File not found'];
            }
            
            $imageData = file_get_contents($filepath);
            $mimeType = mime_content_type($filepath);
            $base64 = 'data:' . $mimeType . ';base64,' . base64_encode($imageData);
            
            return ['success' => true, 'base64' => $base64];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Conversion error: ' . $e->getMessage()];
        }
    }
}

// Handle API requests only when accessed directly, not when included
if (basename($_SERVER['PHP_SELF']) === 'upload.php' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    // Check if user is logged in
    session_start();
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Not logged in']);
        exit;
    }
    
    $uploadHandler = new FileUploadHandler();
    $userId = $_SESSION['user_id'];
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'upload_profile_photo':
            if (!isset($_FILES['photo'])) {
                echo json_encode(['success' => false, 'message' => 'No file uploaded']);
                break;
            }
            
            // Check if user is admin
            $isAdmin = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
            $adminId = $_SESSION['admin_id'] ?? null;
            
            // Extract admin ID from user_id if in format 'admin_X'
            if ($isAdmin && !$adminId && strpos($_SESSION['user_id'], 'admin_') === 0) {
                $adminId = str_replace('admin_', '', $_SESSION['user_id']);
            }
            
            // Use admin ID or regular user ID for filename
            $photoUserId = $isAdmin && $adminId ? $adminId : $userId;
            $result = $uploadHandler->uploadProfilePhoto($_FILES['photo'], $photoUserId);
            
            // If upload successful, update database
            if ($result['success']) {
                try {
                    // Use the same database connection method as auth.php
                    $conn = getConnection();
                    
                    if ($conn) {
                        if ($isAdmin && $adminId) {
                            // Update admin_users table
                            $stmt = $conn->prepare("UPDATE admin_users SET profile_photo = ? WHERE id = ?");
                            $updateResult = $stmt->execute([$result['url'], $adminId]);
                        } else {
                            // Update users table
                            $stmt = $conn->prepare("UPDATE users SET profile_photo = ? WHERE id = ?");
                            $updateResult = $stmt->execute([$result['url'], $userId]);
                        }
                        
                        if ($updateResult) {
                            $result['message'] = 'Photo uploaded and database updated successfully';
                        } else {
                            $result['message'] = 'Photo uploaded but database update failed';
                            $result['success'] = false;
                        }
                    } else {
                        $result['message'] = 'Photo uploaded but database connection failed';
                        $result['success'] = false;
                    }
                } catch (Exception $e) {
                    $result['message'] = 'Photo uploaded but database error: ' . $e->getMessage();
                    $result['success'] = false;
                }
            }
            
            echo json_encode($result);
            break;
            
        case 'upload_pet_photo':
            if (!isset($_FILES['photo'])) {
                echo json_encode(['success' => false, 'message' => 'No file uploaded']);
                break;
            }
            
            $petId = $_POST['pet_id'] ?? 0;
            $result = $uploadHandler->uploadPetPhoto($_FILES['photo'], $petId);
            echo json_encode($result);
            break;
            
        case 'upload_image':
            if (!isset($_FILES['image'])) {
                echo json_encode(['success' => false, 'message' => 'No file uploaded']);
                break;
            }
            
            // Get pet ID from form data or use timestamp as fallback
            $petId = $_POST['pet_id'] ?? time();
            $result = $uploadHandler->uploadPetPhoto($_FILES['image'], $petId);
            echo json_encode($result);
            break;
            
        case 'delete_file':
            try {
                $filepath = $_POST['filepath'] ?? '';
                
                // Check if user is admin (same logic as upload)
                $isAdmin = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
                $adminId = $_SESSION['admin_id'] ?? null;
                
                // Extract admin ID from user_id if in format 'admin_X'
                if ($isAdmin && !$adminId && strpos($_SESSION['user_id'], 'admin_') === 0) {
                    $adminId = str_replace('admin_', '', $_SESSION['user_id']);
                }
                
                // Debug logging
                error_log("Delete file request - filepath: " . $filepath);
                error_log("User ID: " . $userId);
                error_log("Is Admin: " . ($isAdmin ? 'true' : 'false'));
                error_log("Admin ID: " . ($adminId ?? 'null'));
                
                // For profile photos, always update database first
                if (strpos($filepath, 'profile_') === 0 || empty($filepath)) {
                    try {
                        $conn = getConnection();
                        if ($conn) {
                            // Update the correct table based on user type
                            if ($isAdmin && $adminId) {
                                // Update admin_users table for admin users
                                $stmt = $conn->prepare("UPDATE admin_users SET profile_photo = NULL WHERE id = ?");
                                $stmt->execute([$adminId]);
                                error_log("Database updated successfully - admin profile photo removed (ID: " . $adminId . ")");
                            } else {
                                // Update users table for regular users
                                // Make sure we're using numeric ID, not 'admin_X' format
                                $numericUserId = is_numeric($userId) ? $userId : (strpos($userId, 'admin_') === 0 ? str_replace('admin_', '', $userId) : $userId);
                                $stmt = $conn->prepare("UPDATE users SET profile_photo = NULL WHERE id = ?");
                                $stmt->execute([$numericUserId]);
                                error_log("Database updated successfully - user profile photo removed (ID: " . $numericUserId . ")");
                            }
                            
                            // If we have a filepath, also try to delete the physical file
                            if (!empty($filepath)) {
                                $fullPath = 'uploads/profiles/' . $filepath;
                                error_log("Attempting to delete physical file: " . $fullPath);
                                
                                if (file_exists($fullPath)) {
                                    if (unlink($fullPath)) {
                                        error_log("Physical file deleted successfully");
                                    } else {
                                        error_log("Failed to delete physical file");
                                    }
                                } else {
                                    error_log("Physical file does not exist: " . $fullPath);
                                }
                            }
                            
                            echo json_encode(['success' => true, 'message' => 'Profile photo deleted successfully']);
                        } else {
                            error_log("Database connection failed");
                            echo json_encode(['success' => false, 'message' => 'Database connection failed']);
                        }
                    } catch (Exception $e) {
                        error_log('Database update error during photo deletion: ' . $e->getMessage());
                        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
                    }
                } else {
                    // For other files, use the original delete logic
                    $fullPath = $filepath;
                    $result = $uploadHandler->deleteFile($fullPath);
                    echo json_encode($result);
                }
            } catch (Exception $e) {
                error_log("Delete file error: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Delete error: ' . $e->getMessage()]);
            }
            break;
            
        case 'image_to_base64':
            $filepath = $_POST['filepath'] ?? '';
            $result = $uploadHandler->imageToBase64($filepath);
            echo json_encode($result);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
}
?>
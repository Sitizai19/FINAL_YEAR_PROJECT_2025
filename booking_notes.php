<?php
// booking_notes.php
require_once 'config/database.php';

function respond($success, $data = null, $message = '') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message
    ]);
    exit;
}

try {
    $conn = getConnection();
} catch (Exception $e) {
    respond(false, null, 'Database connection failed: ' . $e->getMessage());
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    if ($action === 'get_notes') {
        $bookingId = $_POST['booking_id'] ?? null;
        $bookingType = $_POST['booking_type'] ?? 'service';
        
        if (!$bookingId) {
            respond(false, null, 'Booking ID required');
        }
        
        // Get notes and images for the booking
        $stmt = $conn->prepare("
            SELECT 
                id,
                note_text,
                image_path,
                created_at,
                updated_at,
                created_by
            FROM booking_notes 
            WHERE booking_id = ? AND booking_type = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$bookingId, $bookingType]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $data = [
            'notes' => '',
            'images' => []
        ];
        
        // Process results
        foreach ($results as $row) {
            // Get the latest notes text
            if ($row['note_text'] && empty($data['notes'])) {
                $data['notes'] = $row['note_text'];
            }
            
            // Collect all images
            if ($row['image_path']) {
                $data['images'][] = [
                    'id' => $row['id'],
                    'image_path' => $row['image_path'],
                    'created_at' => $row['created_at']
                ];
            }
        }
        
        respond(true, $data, 'Notes retrieved successfully');
        
    } elseif ($action === 'save_notes') {
        $bookingId = $_POST['booking_id'] ?? null;
        $bookingType = $_POST['booking_type'] ?? 'service';
        $notes = $_POST['notes'] ?? '';
        
        if (!$bookingId) {
            respond(false, null, 'Booking ID required');
        }
        
        // Handle image upload if present
        $imagePath = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = 'uploads/booking_notes/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $fileExtension = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
            $fileName = 'note_' . $bookingId . '_' . $bookingType . '_' . time() . '.' . $fileExtension;
            $imagePath = $uploadDir . $fileName;
            
            if (!move_uploaded_file($_FILES['image']['tmp_name'], $imagePath)) {
                respond(false, null, 'Failed to upload image');
            }
        }
        
        // Save notes and/or image
        if ($notes || $imagePath) {
            session_start();
            // Handle both regular users (integer) and admin users (string like 'admin_1')
            $createdBy = $_SESSION['user_id'] ?? 1;
            if (is_string($createdBy) && strpos($createdBy, 'admin_') === 0) {
                // Extract admin ID from 'admin_1' format
                $createdBy = (int)str_replace('admin_', '', $createdBy);
            }
            $stmt = $conn->prepare("INSERT INTO booking_notes (booking_id, booking_type, note_text, image_path, created_by) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$bookingId, $bookingType, $notes, $imagePath, $createdBy]);
        }
        
        respond(true, null, 'Notes saved successfully');
        
    } elseif ($action === 'delete_image') {
        $bookingId = $_POST['booking_id'] ?? null;
        $bookingType = $_POST['booking_type'] ?? 'service';
        
        if (!$bookingId) {
            respond(false, null, 'Booking ID required');
        }
        
        // Get image path before deleting
        $stmt = $conn->prepare("SELECT id, image_path FROM booking_notes WHERE booking_id = ? AND booking_type = ? AND image_path IS NOT NULL ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$bookingId, $bookingType]);
        $image = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($image && $image['image_path']) {
            // Delete from database
            $stmt = $conn->prepare("DELETE FROM booking_notes WHERE id = ?");
            $stmt->execute([$image['id']]);
            
            // Delete file from server
            if (file_exists($image['image_path'])) {
                unlink($image['image_path']);
            }
            
            respond(true, null, 'Image deleted successfully');
        } else {
            respond(false, null, 'Image not found');
        }
        
    } else {
        respond(false, null, 'Invalid action');
    }
} catch (Exception $e) {
    respond(false, null, 'Error: ' . $e->getMessage());
}
?>

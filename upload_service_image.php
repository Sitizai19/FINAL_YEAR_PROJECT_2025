<?php
// Image Upload Handler for Pet Services
// File: upload_service_image.php

header('Content-Type: application/json');

// Check if file was uploaded
if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'No image uploaded or upload error']);
    exit;
}

$file = $_FILES['image'];

// Validate file type
$allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
if (!in_array($file['type'], $allowedTypes)) {
    echo json_encode(['success' => false, 'message' => 'Invalid file type. Only JPEG, PNG, GIF, and WebP are allowed.']);
    exit;
}

// Validate file size (max 5MB)
$maxSize = 5 * 1024 * 1024; // 5MB
if ($file['size'] > $maxSize) {
    echo json_encode(['success' => false, 'message' => 'File too large. Maximum size is 5MB.']);
    exit;
}

// Create uploads directory if it doesn't exist
$uploadDir = 'uploads/service_images/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// Generate unique filename
$fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
$fileName = 'service_' . time() . '_' . uniqid() . '.' . $fileExtension;
$filePath = $uploadDir . $fileName;

// Move uploaded file
if (move_uploaded_file($file['tmp_name'], $filePath)) {
    // Return success with file path
    echo json_encode([
        'success' => true, 
        'message' => 'Image uploaded successfully',
        'file_path' => $filePath,
        'file_url' => $filePath
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to save image']);
}
?>





























































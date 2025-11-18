<?php
// Simple Authentication Handler - ONE FILE FOR EVERYTHING
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Set session settings BEFORE starting the session
// Set session to expire when browser closes
ini_set('session.cookie_lifetime', 0);
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);

// Start session at the beginning
session_start();

// Handle OAuth callback (GET request)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $code = $_GET['code'] ?? '';
    $error = $_GET['error'] ?? '';
    
    if ($error) {
        echo "Error: " . htmlspecialchars($error);
        exit;
    }
    
    if ($code) {
        // Exchange code for access token
        $clientId = '643080580151-606q4i9cg9ec2e2qjt1j5kn8202tkk9o.apps.googleusercontent.com';
        $clientSecret = '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';
        // Dynamically generate redirect URI based on current server URL (works with Laragon)
        // Google OAuth only accepts localhost, not .test domains, so normalize to localhost
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'];
        $path = dirname($_SERVER['PHP_SELF']);
        
        // Convert .test domains to localhost for Google OAuth compatibility
        if (strpos($host, '.test') !== false || strpos($host, '127.0.0.1') !== false) {
            $host = 'localhost';
        }
        
        $redirectUri = $protocol . $host . $path . '/auth.php';
        
        $tokenUrl = 'https://oauth2.googleapis.com/token';
        $tokenData = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri
        ];
        
        // Use cURL if available, otherwise fallback to file_get_contents
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $tokenUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($tokenData));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            $tokenResponse = curl_exec($ch);
            curl_close($ch);
        } else {
            // Fallback using file_get_contents
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => 'Content-Type: application/x-www-form-urlencoded',
                    'content' => http_build_query($tokenData),
                    'ignore_errors' => true
                ]
            ]);
            $tokenResponse = file_get_contents($tokenUrl, false, $context);
        }
        
        $tokenResult = json_decode($tokenResponse, true);
        
        if (isset($tokenResult['access_token'])) {
            // Get user info
            $userInfoUrl = 'https://www.googleapis.com/oauth2/v2/userinfo?access_token=' . $tokenResult['access_token'];
            
            // Use cURL if available, otherwise fallback to file_get_contents
            if (function_exists('curl_init')) {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $userInfoUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                
                $userResponse = curl_exec($ch);
                curl_close($ch);
            } else {
                // Fallback using file_get_contents
                $context = stream_context_create([
                    'http' => [
                        'method' => 'GET',
                        'ignore_errors' => true
                    ]
                ]);
                $userResponse = file_get_contents($userInfoUrl, false, $context);
            }
            
            $userInfo = json_decode($userResponse, true);
            
            if ($userInfo && isset($userInfo['email'])) {
                // Process Google user data
                $email = $userInfo['email'];
                $name = $userInfo['name'];
                $googleId = $userInfo['id'];
                $googlePhoto = $userInfo['picture'] ?? '';
                
                $conn = getConnection();
                if ($conn) {
                    try {
                        // Check if user exists by Google ID
                        $stmt = $conn->prepare("SELECT * FROM users WHERE google_id = ?");
                        $stmt->execute([$googleId]);
                        $user = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($user) {
                            // Update existing user - only set Google photo if no custom photo exists
                            if (empty($user['profile_photo']) || strpos($user['profile_photo'], 'googleusercontent.com') !== false) {
                                $stmt = $conn->prepare("UPDATE users SET profile_photo = ? WHERE google_id = ?");
                                $stmt->execute([$googlePhoto, $googleId]);
                            }
                            $userId = $user['id'];
                        } else {
                            // Check if user exists by email
                            $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
                            $stmt->execute([$email]);
                            $user = $stmt->fetch(PDO::FETCH_ASSOC);
                            
                            if ($user) {
                                // Link Google account to existing user - only set Google photo if no custom photo exists
                                if (empty($user['profile_photo']) || strpos($user['profile_photo'], 'googleusercontent.com') !== false) {
                                    $stmt = $conn->prepare("UPDATE users SET google_id = ?, profile_photo = ? WHERE email = ?");
                                    $stmt->execute([$googleId, $googlePhoto, $email]);
                                } else {
                                    $stmt = $conn->prepare("UPDATE users SET google_id = ? WHERE email = ?");
                                    $stmt->execute([$googleId, $email]);
                                }
                                $userId = $user['id'];
            } else {
                                // Create new user
                                $nameParts = explode(' ', $name, 2);
                                $firstName = $nameParts[0];
                                $lastName = isset($nameParts[1]) ? $nameParts[1] : '';
                                
                                $stmt = $conn->prepare("INSERT INTO users (email, first_name, last_name, google_id, profile_photo) VALUES (?, ?, ?, ?, ?)");
                                $stmt->execute([$email, $firstName, $lastName, $googleId, $googlePhoto]);
                                $userId = $conn->lastInsertId();
                            }
                        }
                        
                        $_SESSION['user_id'] = $userId;
                        $_SESSION['user_email'] = $email;
                        $_SESSION['user_name'] = $name;
                        $_SESSION['login_method'] = 'google';
                        
                        // Generate unique browser session ID for Google login
                        $browserSessionId = uniqid('browser_', true);
                        $_SESSION['browser_session_id'] = $browserSessionId;
                        
                    } catch (Exception $e) {
                        // Fallback to session storage
                        error_log("Google OAuth Database Error: " . $e->getMessage());
                        $_SESSION['user_id'] = 'google_' . $googleId;
                        $_SESSION['user_email'] = $email;
                        $_SESSION['user_name'] = $name;
                        $_SESSION['login_method'] = 'google';
                        $_SESSION['google_id'] = $googleId;
                        $_SESSION['google_photo'] = $googlePhoto;
                    }
                } else {
                    // No database connection - use session storage
                    error_log("Google OAuth: No database connection available");
                    $_SESSION['user_id'] = 'google_' . $googleId;
                    $_SESSION['user_email'] = $email;
                    $_SESSION['user_name'] = $name;
                    $_SESSION['login_method'] = 'google';
                    $_SESSION['google_id'] = $googleId;
                    $_SESSION['google_photo'] = $googlePhoto;
                }
                
                // Close popup and redirect parent window
                echo '<script>
                    if (window.opener) {
                        window.opener.location.href = "cust-dashboard.html";
                        window.close();
            } else {
                        window.location.href = "cust-dashboard.html";
                    }
                </script>';
                exit;
            }
        }
        
        echo "Authentication failed. Please try again.";
    } else {
        echo "No authorization code received.";
    }
    exit;
}

// Handle POST requests
header('Content-Type: application/json');

// Database connection
function getConnection() {
    try {
        // Use same password as config/database.php for consistency
        $pdo = new PDO("mysql:host=localhost;dbname=petcare_db;charset=utf8mb4", "root", "zainab@0558");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    } catch (PDOException $e) {
        // Log error for debugging (only in development)
        error_log("Database connection error: " . $e->getMessage());
        return null;
    }
}

// Handle all requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'login':
            $email = $_POST['username'] ?? '';
            $password = $_POST['password'] ?? '';
            
            if (!$email || !$password) {
                echo json_encode(['success' => false, 'message' => 'Email and password required']);
                exit;
            }
            
            $conn = getConnection();
            if (!$conn) {
                // Try to get more details about the connection error
                try {
                    $testConn = new PDO("mysql:host=localhost", "root", "zainab@0558");
                    echo json_encode(['success' => false, 'message' => 'Database connection failed: petcare_db database not found. Please create the database first or check Laragon MySQL is running.']);
                } catch (PDOException $e) {
                    echo json_encode(['success' => false, 'message' => 'Database connection failed: MySQL server not running. Please start XAMPP MySQL service.']);
                }
                exit;
            }
            
            try {
                // Check if user exists in admin_users table first (separate admin authentication)
                try {
                    $adminStmt = $conn->prepare("SELECT * FROM admin_users WHERE email = ? AND is_active = 1");
                    $adminStmt->execute([$email]);
                    $admin = $adminStmt->fetch(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    // Admin table might not exist - log but continue to regular user check
                    error_log("Admin table check error: " . $e->getMessage());
                    $admin = null;
                }
                
                // If admin user found, authenticate as admin
                if ($admin && password_verify($password, $admin['password_hash'])) {
                    // Check if this is coming from admin login page (for security)
                    // Check both referer and explicit parameter
                    $referer = $_SERVER['HTTP_REFERER'] ?? '';
                    $isAdminLoginParam = isset($_POST['is_admin_login']) && $_POST['is_admin_login'] === '1';
                    $isAdminLoginReferer = strpos($referer, 'admin-login.html') !== false;
                    $isAdminLogin = $isAdminLoginParam || $isAdminLoginReferer;
                    
                    if (!$isAdminLogin) {
                        // Admin trying to login from regular form - require admin login page
                        echo json_encode(['success' => false, 'message' => 'Admin users must login through the admin login page.']);
                        exit;
                    }
                    
                    // Update last_login timestamp
                    try {
                        $updateStmt = $conn->prepare("UPDATE admin_users SET last_login = CURRENT_TIMESTAMP WHERE id = ?");
                        $updateStmt->execute([$admin['id']]);
                    } catch (Exception $e) {
                        // Log error but don't fail login
                        error_log("Failed to update last_login: " . $e->getMessage());
                    }
                    
                    // Admin login successful
                    $_SESSION['user_id'] = 'admin_' . $admin['id'];
                    $_SESSION['admin_id'] = $admin['id'];
                    $_SESSION['user_email'] = $email;
                    $_SESSION['user_name'] = ($admin['first_name'] ?? 'Admin') . ' ' . ($admin['last_name'] ?? 'User');
                    $_SESSION['user_role'] = 'admin';
                    $_SESSION['login_method'] = 'admin';
                    
                    echo json_encode(['success' => true, 'message' => 'Admin login successful', 'redirect' => 'admin-dashboard.html']);
                    exit;
                } else if ($admin) {
                    // Admin user exists but password is wrong
                    echo json_encode(['success' => false, 'message' => 'Invalid admin credentials. Access denied.']);
                    exit;
                }
                
                // Regular user login - check against users table
                // Only proceed if not an admin user
                
                // Check regular users table
                $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
                $stmt->execute([$email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user && password_verify($password, $user['password_hash'])) {
                    // Generate unique browser session ID
                    $browserSessionId = uniqid('browser_', true);
                    $_SESSION['browser_session_id'] = $browserSessionId;
                    
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
                    $_SESSION['user_role'] = 'user';
                    $_SESSION['login_method'] = 'regular';
                    
                    echo json_encode(['success' => true, 'message' => 'Login successful', 'redirect' => 'cust-dashboard.html', 'browser_session_id' => $browserSessionId]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Invalid email or password']);
                }
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Login failed: ' . $e->getMessage()]);
            }
            break;
            
        case 'signup':
            // Check if this is coming from admin login page
            $referer = $_SERVER['HTTP_REFERER'] ?? '';
            $isAdminSignup = strpos($referer, 'admin-login.html') !== false;
            
            if ($isAdminSignup) {
                // Admin/Staff signup - insert into admin_users table
                $firstName = $_POST['first_name'] ?? '';
                $lastName = $_POST['last_name'] ?? '';
                $email = $_POST['email'] ?? '';
                $phone = trim($_POST['phone'] ?? '');
                $password = $_POST['password'] ?? '';
                $role = $_POST['role'] ?? '';
                
                if (!$firstName || !$lastName || !$email || !$password || !$role) {
                    echo json_encode(['success' => false, 'message' => 'All required fields must be filled']);
                    exit;
                }
                
                $conn = getConnection();
                if (!$conn) {
                    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
                    exit;
                }
                
                try {
                    // Check if email already exists in admin_users
                    $stmt = $conn->prepare("SELECT id FROM admin_users WHERE email = ?");
                    $stmt->execute([$email]);
                    if ($stmt->fetch()) {
                        echo json_encode(['success' => false, 'message' => 'Email already registered']);
                        exit;
                    }
                    
                    // Generate username from email (part before @)
                    $username = explode('@', $email)[0];
                    // Ensure username is unique
                    $originalUsername = $username;
                    $counter = 1;
                    while (true) {
                        $checkStmt = $conn->prepare("SELECT id FROM admin_users WHERE username = ?");
                        $checkStmt->execute([$username]);
                        if (!$checkStmt->fetch()) {
                            break; // Username is available
                        }
                        $username = $originalUsername . $counter;
                        $counter++;
                    }
                    
                    // Hash password
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    
                    // Insert into admin_users table
                    $stmt = $conn->prepare("INSERT INTO admin_users (username, email, password_hash, first_name, last_name, phone, role, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $result = $stmt->execute([
                        $username,
                        $email,
                        $passwordHash,
                        $firstName,
                        $lastName,
                        $phone ?: null, // Phone is optional
                        $role,
                        1 // is_active = 1
                    ]);
                    
                    if ($result) {
                        echo json_encode(['success' => true, 'message' => 'Staff member registered successfully']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Registration failed']);
                    }
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'message' => 'Registration failed: ' . $e->getMessage()]);
                }
            } else {
                // Regular user signup - insert into users table
                $email = $_POST['email'] ?? '';
                $password = $_POST['signup-password'] ?? '';
                $firstName = $_POST['firstname'] ?? '';
                $lastName = $_POST['lastname'] ?? '';
                $phone = $_POST['phone'] ?? '';
                $address = $_POST['address'] ?? '';
                
                if (!$email || !$password || !$firstName || !$lastName) {
                    echo json_encode(['success' => false, 'message' => 'All required fields must be filled']);
                    exit;
                }
                
                $conn = getConnection();
                if (!$conn) {
                    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
                    exit;
                }
                
                try {
                    // Check if user exists
                    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
                    $stmt->execute([$email]);
                    if ($stmt->fetch()) {
                        echo json_encode(['success' => false, 'message' => 'Email already registered']);
                        exit;
                    }
                    
                    // Create user
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("INSERT INTO users (email, password_hash, first_name, last_name, phone_number, address) VALUES (?, ?, ?, ?, ?, ?)");
                    $result = $stmt->execute([$email, $passwordHash, $firstName, $lastName, $phone, $address]);
                    
                    if ($result) {
                        $_SESSION['user_id'] = $conn->lastInsertId();
                        $_SESSION['user_email'] = $email;
                        $_SESSION['user_name'] = $firstName . ' ' . $lastName;
                        $_SESSION['login_method'] = 'regular';
                        
                        echo json_encode(['success' => true, 'message' => 'Registration successful', 'redirect' => 'cust-dashboard.html']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Registration failed']);
                    }
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'message' => 'Registration failed: ' . $e->getMessage()]);
                }
            }
            break;
            
        case 'get_current_user':
            // Check for tab-specific session validation
            $tabSessionId = $_POST['tab_session_id'] ?? '';
            $storedTabSessionId = $_SESSION['tab_session_id'] ?? '';
            
            if (isset($_SESSION['user_id']) && isset($_SESSION['user_email'])) {
                // If no stored tab session ID, this is a new login - store it
                if (empty($storedTabSessionId) && !empty($tabSessionId)) {
                    $_SESSION['tab_session_id'] = $tabSessionId;
                }
                // If tab session ID doesn't match, update it (don't log out)
                else if (!empty($tabSessionId) && $tabSessionId !== $storedTabSessionId) {
                    $_SESSION['tab_session_id'] = $tabSessionId;
                }
                
                $conn = getConnection();
                if ($conn) {
                    try {
                        // Check if user is admin
                        if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
                            $adminId = $_SESSION['admin_id'] ?? null;
                            
                            // Try to extract from user_id if it's in format 'admin_X'
                            if (!$adminId && strpos($_SESSION['user_id'], 'admin_') === 0) {
                                $adminId = str_replace('admin_', '', $_SESSION['user_id']);
                            }
                            
                            // If still no admin_id, try to find by email
                            if (!$adminId) {
                                $findStmt = $conn->prepare("SELECT id FROM admin_users WHERE email = ?");
                                $findStmt->execute([$_SESSION['user_email']]);
                                $adminRecord = $findStmt->fetch(PDO::FETCH_ASSOC);
                                $adminId = $adminRecord['id'] ?? null;
                            }
                            
                            if ($adminId) {
                                $stmt = $conn->prepare("SELECT id, email, first_name, last_name, phone, address, profile_photo, role FROM admin_users WHERE id = ?");
                                $stmt->execute([$adminId]);
                                $admin = $stmt->fetch(PDO::FETCH_ASSOC);
                                
                                if ($admin) {
                                    echo json_encode([
                                        'success' => true,
                                        'user' => [
                                            'id' => $admin['id'],
                                            'email' => $admin['email'],
                                            'first_name' => $admin['first_name'],
                                            'last_name' => $admin['last_name'],
                                            'phone_number' => $admin['phone'],
                                            'address' => $admin['address'],
                                            'profile_photo' => $admin['profile_photo'],
                                            'role' => $admin['role'] ?? 'admin',
                                            'is_admin' => true // Explicit flag for admin users
                                        ]
                                    ]);
                                } else {
                                    // Admin not found in database, return session data
                                    echo json_encode([
                                        'success' => true, 
                                        'user' => [
                                            'id' => $adminId,
                                            'email' => $_SESSION['user_email'],
                                            'first_name' => explode(' ', $_SESSION['user_name'])[0] ?? 'Admin',
                                            'last_name' => explode(' ', $_SESSION['user_name'], 2)[1] ?? 'User',
                                            'phone_number' => '',
                                            'address' => '',
                                            'profile_photo' => '',
                                            'role' => 'admin',
                                            'is_admin' => true // Explicit flag for admin users
                                        ]
                                    ]);
                                }
                            } else {
                                // Admin ID not found, return session data
                                echo json_encode([
                                    'success' => true, 
                                    'user' => [
                                        'id' => $_SESSION['user_id'],
                                        'email' => $_SESSION['user_email'],
                                        'first_name' => explode(' ', $_SESSION['user_name'])[0] ?? 'Admin',
                                        'last_name' => explode(' ', $_SESSION['user_name'], 2)[1] ?? 'User',
                                        'phone_number' => '',
                                        'address' => '',
                                        'profile_photo' => '',
                                        'role' => 'admin',
                                        'is_admin' => true // Explicit flag for admin users
                                    ]
                                ]);
                            }
                        } else {
                            // Regular user
                            $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
                            $stmt->execute([$_SESSION['user_id']]);
                            $user = $stmt->fetch(PDO::FETCH_ASSOC);
                            
                            if ($user) {
                                echo json_encode(['success' => true, 'user' => $user]);
                            } else {
                                // User not found in database, return session data
                                echo json_encode([
                                    'success' => true, 
                                    'user' => [
                                        'id' => $_SESSION['user_id'],
                                        'email' => $_SESSION['user_email'],
                                        'first_name' => explode(' ', $_SESSION['user_name'])[0] ?? 'User',
                                        'last_name' => explode(' ', $_SESSION['user_name'], 2)[1] ?? '',
                                        'phone_number' => '',
                                        'address' => '',
                                        'profile_photo' => $_SESSION['google_photo'] ?? ''
                                    ]
                                ]);
                            }
                        }
                    } catch (Exception $e) {
                        // Database error, return session data
                        echo json_encode([
                            'success' => true, 
                            'user' => [
                                'id' => $_SESSION['user_id'],
                                'email' => $_SESSION['user_email'],
                                'first_name' => explode(' ', $_SESSION['user_name'])[0] ?? (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin' ? 'Admin' : 'User'),
                                'last_name' => explode(' ', $_SESSION['user_name'], 2)[1] ?? (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin' ? 'User' : ''),
                                'phone_number' => '',
                                'address' => '',
                                'profile_photo' => $_SESSION['google_photo'] ?? '',
                                'role' => $_SESSION['user_role'] ?? 'user'
                            ]
                        ]);
                    }
                } else {
                    // No database connection, return session data
                    echo json_encode([
                        'success' => true, 
                        'user' => [
                            'id' => $_SESSION['user_id'],
                            'email' => $_SESSION['user_email'],
                            'first_name' => explode(' ', $_SESSION['user_name'])[0] ?? (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin' ? 'Admin' : 'User'),
                            'last_name' => explode(' ', $_SESSION['user_name'], 2)[1] ?? (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin' ? 'User' : ''),
                            'phone_number' => '',
                            'address' => '',
                            'profile_photo' => $_SESSION['google_photo'] ?? '',
                            'role' => $_SESSION['user_role'] ?? 'user'
                        ]
                    ]);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'No user logged in']);
            }
            break;
            
        case 'check_login':
            // Check for tab-specific session validation
            $tabSessionId = $_POST['tab_session_id'] ?? '';
            $storedTabSessionId = $_SESSION['tab_session_id'] ?? '';
            
            if (isset($_SESSION['user_id']) && isset($_SESSION['user_email'])) {
                // If tab session ID doesn't match, update it (don't log out)
                if (!empty($tabSessionId) && $tabSessionId !== $storedTabSessionId) {
                    $_SESSION['tab_session_id'] = $tabSessionId;
                }
                
                // Get user's first name from database
                $conn = getConnection();
                if ($conn) {
                    try {
                        $stmt = $conn->prepare("SELECT first_name FROM users WHERE id = ?");
                        $stmt->execute([$_SESSION['user_id']]);
                        $user = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($user) {
                            echo json_encode([
                                'success' => true, 
                                'logged_in' => true,
                                'first_name' => $user['first_name'],
                                'user_id' => $_SESSION['user_id']
                            ]);
                        } else {
                            echo json_encode(['success' => true, 'logged_in' => true, 'first_name' => 'User']);
                        }
                    } catch (Exception $e) {
                        echo json_encode(['success' => true, 'logged_in' => true, 'first_name' => 'User']);
                    }
                } else {
                    echo json_encode(['success' => true, 'logged_in' => true, 'first_name' => 'User']);
                }
            } else {
                echo json_encode(['success' => false, 'logged_in' => false, 'message' => 'User is not logged in']);
            }
            break;
            
        case 'get_pets':
            // Check for tab-specific session validation
            $tabSessionId = $_POST['tab_session_id'] ?? '';
            $storedTabSessionId = $_SESSION['tab_session_id'] ?? '';
            
            if (isset($_SESSION['user_id']) && isset($_SESSION['user_email'])) {
                // If tab session ID doesn't match, this is a new browser tab - log out
                if (!empty($tabSessionId) && $tabSessionId !== $storedTabSessionId) {
                    $_SESSION = array();
                    session_destroy();
                    echo json_encode(['success' => false, 'message' => 'Session expired - new browser tab']);
                    break;
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'User not logged in']);
                break;
            }
            
            $conn = getConnection();
            if (!$conn) {
                echo json_encode(['success' => false, 'message' => 'Database connection failed']);
                break;
            }
            
            try {
                $userId = $_SESSION['user_id'];
                
                // If user_id is a string (Google fallback), try to find the actual user ID
                if (is_string($userId) && strpos($userId, 'google_') === 0) {
                    // Extract Google ID from session
                    $googleId = str_replace('google_', '', $userId);
                    
                    // Find user by Google ID
                    $stmt = $conn->prepare("SELECT id FROM users WHERE google_id = ?");
                    $stmt->execute([$googleId]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($user) {
                        $userId = $user['id'];
                    } else {
                        // No user found, return empty pets array
                        echo json_encode(['success' => true, 'pets' => []]);
                        break;
                    }
                }
                
                // Check if new columns exist, otherwise use breed as fallback
                $stmt = $conn->prepare("SHOW COLUMNS FROM pets LIKE 'pet_category'");
                $stmt->execute();
                $hasPetCategory = $stmt->fetch();
                
                if ($hasPetCategory) {
                    // Use new pet category fields
                    $stmt = $conn->prepare("SELECT *, pet_category as category FROM pets WHERE user_id = ? ORDER BY created_at DESC");
                } else {
                    // Fallback to old breed field
                    $stmt = $conn->prepare("SELECT *, breed as category FROM pets WHERE user_id = ? ORDER BY created_at DESC");
                }
                $stmt->execute([$userId]);
                $pets = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Process vaccination data from pets table
                foreach ($pets as &$pet) {
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
                
                echo json_encode(['success' => true, 'pets' => $pets]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Failed to load pets: ' . $e->getMessage()]);
            }
            break;
            
        case 'get_bookings':
            // Check for tab-specific session validation
            $tabSessionId = $_POST['tab_session_id'] ?? '';
            $storedTabSessionId = $_SESSION['tab_session_id'] ?? '';
            
            if (isset($_SESSION['user_id']) && isset($_SESSION['user_email'])) {
                // If tab session ID doesn't match, this is a new browser tab - log out
                if (!empty($tabSessionId) && $tabSessionId !== $storedTabSessionId) {
                    $_SESSION = array();
                    session_destroy();
                    echo json_encode(['success' => false, 'message' => 'Session expired - new browser tab']);
                    break;
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'User not logged in']);
                break;
            }
            
            $conn = getConnection();
            if (!$conn) {
                echo json_encode(['success' => false, 'message' => 'Database connection failed']);
                break;
            }
            
            try {
                $userId = $_SESSION['user_id'];
                
                // If user_id is a string (Google fallback), try to find the actual user ID
                if (is_string($userId) && strpos($userId, 'google_') === 0) {
                    // Extract Google ID from session
                    $googleId = str_replace('google_', '', $userId);
                    
                    // Find user by Google ID
                    $stmt = $conn->prepare("SELECT id FROM users WHERE google_id = ?");
                    $stmt->execute([$googleId]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($user) {
                        $userId = $user['id'];
                    } else {
                        // No user found, return empty bookings array
                        echo json_encode(['success' => true, 'data' => []]);
                        break;
                    }
                }
                
                // Get service bookings
                $stmt = $conn->prepare("
                    SELECT sb.*, ps.service_name, ps.service_category, 
                           p.name as pet_name, p.pet_category, p.breed, p.photo_path as pet_photo_path,
                           CASE 
                               WHEN sb.additional_pets IS NOT NULL 
                               THEN JSON_LENGTH(sb.additional_pets) + 1
                               ELSE 1
                           END as total_pets_count,
                           'service' as booking_type
                    FROM service_bookings sb
                    LEFT JOIN pet_services ps ON sb.service_id = ps.id
                    LEFT JOIN pets p ON sb.pet_id = p.id
                    WHERE sb.user_id = ? 
                    AND (sb.user_deleted = 0 OR sb.user_deleted IS NULL)
                    ORDER BY sb.booking_date DESC
                ");
                $stmt->execute([$userId]);
                $serviceBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Fetch additional pets for each booking
                foreach ($serviceBookings as &$booking) {
                    $allPetsInfo = [];
                    // Add primary pet
                    $allPetsInfo[] = [
                        'id' => $booking['pet_id'],
                        'name' => $booking['pet_name'],
                        'breed' => $booking['breed'],
                        'pet_category' => $booking['pet_category'],
                        'photo_path' => $booking['pet_photo_path']
                    ];
                    
                    // Add additional pets if any
                    if (!empty($booking['additional_pets']) && $booking['additional_pets'] !== 'null' && $booking['additional_pets'] !== NULL) {
                        $additionalPetIds = json_decode($booking['additional_pets'], true);
                        if (is_array($additionalPetIds) && !empty($additionalPetIds)) {
                            // Convert to integers and fetch pet info
                            $additionalPetIds = array_map('intval', $additionalPetIds);
                            $placeholders = str_repeat('?,', count($additionalPetIds) - 1) . '?';
                            $stmt2 = $conn->prepare("
                                SELECT id, name, breed, pet_category, photo_path 
                                FROM pets 
                                WHERE id IN ({$placeholders})
                            ");
                            $stmt2->execute($additionalPetIds);
                            $additionalPets = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                            if (!empty($additionalPets)) {
                                $allPetsInfo = array_merge($allPetsInfo, $additionalPets);
                            }
                        }
                    }
                    $booking['all_pets'] = $allPetsInfo;
                }
                unset($booking);
                
                // Get hotel bookings
                $stmt = $conn->prepare("
                    SELECT hb.*, ps.service_name, ps.service_category, 
                           p.name as pet_name, p.pet_category, p.breed, p.photo_path as pet_photo_path,
                           hr.room_code, hr.category_id,
                           CASE 
                               WHEN hr.category_id = 1 THEN 'Classic Suite'
                               WHEN hr.category_id = 2 THEN 'Deluxe Suite'
                               WHEN hr.category_id = 3 THEN 'Executive Suite'
                               WHEN hr.category_id = 4 THEN 'Studio Suite'
                               ELSE 'Unknown'
                           END as room_category,
                           CASE 
                               WHEN hb.additional_pets IS NOT NULL 
                               THEN JSON_LENGTH(hb.additional_pets) + 1
                               ELSE 1
                           END as total_pets_count,
                           'hotel' as booking_type
                    FROM hotel_bookings hb
                    LEFT JOIN pet_services ps ON hb.service_id = ps.id
                    LEFT JOIN pets p ON hb.pet_id = p.id
                    LEFT JOIN hotel_rooms hr ON hb.room_code = hr.room_code
                    WHERE hb.user_id = ?
                    AND (hb.user_deleted = 0 OR hb.user_deleted IS NULL)
                    ORDER BY hb.checkin_date DESC
                ");
                $stmt->execute([$userId]);
                $hotelBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Fetch additional pets for hotel bookings too
                foreach ($hotelBookings as &$booking) {
                    $allPetsInfo = [];
                    // Add primary pet
                    $allPetsInfo[] = [
                        'id' => $booking['pet_id'],
                        'name' => $booking['pet_name'],
                        'breed' => $booking['breed'],
                        'pet_category' => $booking['pet_category'],
                        'photo_path' => $booking['pet_photo_path']
                    ];
                    
                    // Add additional pets if any
                    if (!empty($booking['additional_pets']) && $booking['additional_pets'] !== 'null' && $booking['additional_pets'] !== NULL) {
                        $additionalPetIds = json_decode($booking['additional_pets'], true);
                        if (is_array($additionalPetIds) && !empty($additionalPetIds)) {
                            // Convert to integers and fetch pet info
                            $additionalPetIds = array_map('intval', $additionalPetIds);
                            $placeholders = str_repeat('?,', count($additionalPetIds) - 1) . '?';
                            $stmt2 = $conn->prepare("
                                SELECT id, name, breed, pet_category, photo_path 
                                FROM pets 
                                WHERE id IN ({$placeholders})
                            ");
                            $stmt2->execute($additionalPetIds);
                            $additionalPets = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                            if (!empty($additionalPets)) {
                                $allPetsInfo = array_merge($allPetsInfo, $additionalPets);
                            }
                        }
                    }
                    $booking['all_pets'] = $allPetsInfo;
                }
                unset($booking);
                
                // Combine both types of bookings
                $bookings = array_merge($serviceBookings, $hotelBookings);
                
                // Sort by date (service bookings use booking_date, hotel bookings use checkin_date)
                usort($bookings, function($a, $b) {
                    $dateA = $a['booking_type'] === 'service' ? $a['booking_date'] : $a['checkin_date'];
                    $dateB = $b['booking_type'] === 'service' ? $b['booking_date'] : $b['checkin_date'];
                    return strtotime($dateB) - strtotime($dateA);
                });
                
                echo json_encode(['success' => true, 'data' => $bookings]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Failed to load bookings: ' . $e->getMessage()]);
            }
            break;
            
        case 'add_pet':
            if (!isset($_SESSION['user_id'])) {
                echo json_encode(['success' => false, 'message' => 'User not logged in']);
                break;
            }
            
            $conn = getConnection();
            if (!$conn) {
                echo json_encode(['success' => false, 'message' => 'Database connection failed']);
                break;
            }
            
            try {
                $name = $_POST['name'] ?? '';
                $breed = $_POST['breed'] ?? '';
                $age = $_POST['age'] ?? null;
                $weight = $_POST['weight'] ?? null;
                $gender = $_POST['gender'] ?? 'unknown';
                $spayed_neutered = $_POST['spayed_neutered'] ?? 'unknown';
                $medical_type = $_POST['event_type'] ?? '';
                $medical_condition = $_POST['medical_condition'] ?? '';
                $special_notes = $_POST['special_notes'] ?? '';
                $photo_path = $_POST['photo_path'] ?? '';
                
                // Handle vaccination data - collect all selected vaccines as comma-separated values
                $vaccine_names_list = [];
                $vaccine_dates_list = [];
                
                $vaccine_names = [
                    'fvrcp', 'rabies', 'felv', 'fiv', 'bordetella', 
                    'chlamydophila', 'fip', 'giardia', 'ringworm', 'others'
                ];
                
                foreach ($vaccine_names as $vaccine) {
                    if (isset($_POST[$vaccine]) && $_POST[$vaccine] === 'on') {
                        $vaccine_name = ucfirst($vaccine);
                        if ($vaccine === 'others' && !empty($_POST['other_vaccine'])) {
                            $vaccine_name = $_POST['other_vaccine'];
                        }
                        // Handle special case for 'others' vaccine date
                        $date_field = ($vaccine === 'others') ? 'other_vaccine_date' : $vaccine . '_date';
                        $vaccine_date = $_POST[$date_field] ?? null;
                        
                        // Only add if date is provided
                        if (!empty($vaccine_date)) {
                            $vaccine_names_list[] = $vaccine_name;
                            $vaccine_dates_list[] = $vaccine_date;
                        }
                    }
                }
                
                // Convert arrays to comma-separated strings
                $vaccine_name = implode(', ', $vaccine_names_list);
                $vaccine_date = implode(', ', $vaccine_dates_list);
                
                if (empty($name)) {
                    echo json_encode(['success' => false, 'message' => 'Pet name is required']);
                    break;
                }
                
                $stmt = $conn->prepare("INSERT INTO pets (user_id, name, breed, age, weight, gender, spayed_neutered, medical_type, medical_condition, special_notes, photo_path, vaccine_name, vaccine_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$_SESSION['user_id'], $name, $breed, $age, $weight, $gender, $spayed_neutered, $medical_type, $medical_condition, $special_notes, $photo_path, $vaccine_name, $vaccine_date]);
                
                $petId = $conn->lastInsertId();
                
                echo json_encode(['success' => true, 'message' => 'Pet added successfully', 'pet_id' => $petId]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Failed to add pet: ' . $e->getMessage()]);
            }
            break;
            
        case 'update_pet':
            // Check for tab-specific session validation
            $tabSessionId = $_POST['tab_session_id'] ?? '';
            $storedTabSessionId = $_SESSION['tab_session_id'] ?? '';
            
            if (isset($_SESSION['user_id']) && isset($_SESSION['user_email'])) {
                // If tab session ID doesn't match, this is a new browser tab - log out
                if (!empty($tabSessionId) && $tabSessionId !== $storedTabSessionId) {
                    $_SESSION = array();
                    session_destroy();
                    echo json_encode(['success' => false, 'message' => 'Session expired - new browser tab']);
                    break;
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'User not logged in']);
                break;
            }
            
            $conn = getConnection();
            if (!$conn) {
                echo json_encode(['success' => false, 'message' => 'Database connection failed']);
                break;
            }
            
            try {
                $petId = $_POST['pet_id'] ?? '';
                $name = $_POST['name'] ?? '';
                $breed = $_POST['breed'] ?? '';
                $age = $_POST['age'] ?? null;
                $weight = $_POST['weight'] ?? null;
                $gender = $_POST['gender'] ?? 'unknown';
                $spayed_neutered = $_POST['spayed_neutered'] ?? 'unknown';
                $special_notes = $_POST['special_notes'] ?? '';
                $medical_type = $_POST['event_type'] ?? '';
                $medical_condition = $_POST['medical_condition'] ?? '';
                $vaccine_name = $_POST['vaccine_name'] ?? '';
                $vaccine_date = $_POST['vaccine_date'] ?? '';
                
                if (empty($petId) || empty($name)) {
                    echo json_encode(['success' => false, 'message' => 'Pet ID and name are required']);
                    break;
                }
                
                // Verify pet belongs to user
                $stmt = $conn->prepare("SELECT id, photo_path FROM pets WHERE id = ? AND user_id = ?");
                $stmt->execute([$petId, $_SESSION['user_id']]);
                $existingPet = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$existingPet) {
                    echo json_encode(['success' => false, 'message' => 'Pet not found or access denied']);
                    break;
                }
                
                // Handle photo upload
                $photo_path = $existingPet['photo_path']; // Keep existing photo by default
                
                if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = 'uploads/pets/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }
                    
                    $fileExtension = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
                    $fileName = 'pet_' . time() . '_' . $petId . '.' . $fileExtension;
                    $uploadPath = $uploadDir . $fileName;
                    
                    if (move_uploaded_file($_FILES['photo']['tmp_name'], $uploadPath)) {
                        // Delete old photo if it exists
                        if (!empty($existingPet['photo_path']) && file_exists($existingPet['photo_path'])) {
                            unlink($existingPet['photo_path']);
                        }
                        $photo_path = $uploadPath;
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Failed to upload photo']);
                        break;
                    }
                }
                
                $stmt = $conn->prepare("UPDATE pets SET name = ?, breed = ?, age = ?, weight = ?, gender = ?, spayed_neutered = ?, special_notes = ?, medical_type = ?, medical_condition = ?, vaccine_name = ?, vaccine_date = ?, photo_path = ? WHERE id = ? AND user_id = ?");
                $stmt->execute([$name, $breed, $age, $weight, $gender, $spayed_neutered, $special_notes, $medical_type, $medical_condition, $vaccine_name, $vaccine_date, $photo_path, $petId, $_SESSION['user_id']]);
                
                echo json_encode(['success' => true, 'message' => 'Pet updated successfully']);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Failed to update pet: ' . $e->getMessage()]);
            }
            break;
            
        case 'delete_pet':
            if (!isset($_SESSION['user_id'])) {
                echo json_encode(['success' => false, 'message' => 'User not logged in']);
                break;
            }
            
            $conn = getConnection();
            if (!$conn) {
                echo json_encode(['success' => false, 'message' => 'Database connection failed']);
                break;
            }
            
            try {
                $petId = $_POST['pet_id'] ?? '';
                
                if (empty($petId)) {
                    echo json_encode(['success' => false, 'message' => 'Pet ID is required']);
                    break;
                }
                
                // Verify pet belongs to user
                $stmt = $conn->prepare("SELECT id FROM pets WHERE id = ? AND user_id = ?");
                $stmt->execute([$petId, $_SESSION['user_id']]);
                if (!$stmt->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'Pet not found or access denied']);
                    break;
                }
                
                $stmt = $conn->prepare("DELETE FROM pets WHERE id = ? AND user_id = ?");
                $stmt->execute([$petId, $_SESSION['user_id']]);
                
                echo json_encode(['success' => true, 'message' => 'Pet deleted successfully']);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Failed to delete pet: ' . $e->getMessage()]);
            }
            break;
            
        case 'logout':
            // Destroy all session data
            $_SESSION = array();
            
            // Delete the session cookie
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params["path"], $params["domain"],
                    $params["secure"], $params["httponly"]
                );
            }
            
            // Destroy the session
            session_destroy();
            
            echo json_encode(['success' => true, 'message' => 'Logged out successfully']);
            break;
            
        case 'update_profile':
            if (!isset($_SESSION['user_id'])) {
                echo json_encode(['success' => false, 'message' => 'Not logged in']);
                break;
            }
            
            $conn = getConnection();
            if (!$conn) {
                echo json_encode(['success' => false, 'message' => 'Database connection failed']);
                break;
            }
            
            try {
                $firstName = $_POST['first_name'] ?? '';
                $lastName = $_POST['last_name'] ?? '';
                $email = $_POST['email'] ?? '';
                $phone = $_POST['phone'] ?? '';
                $address = $_POST['address'] ?? '';
                $role = trim($_POST['role'] ?? '');
                
                // Validate required fields
                if (empty($email)) {
                    echo json_encode(['success' => false, 'message' => 'Email is required']);
                    break;
                }
                
                // Check if user is admin
                if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
                    // Handle admin profile update
                    $adminId = $_SESSION['admin_id'] ?? null;
                    
                    if (!$adminId) {
                        // Try to extract from user_id if it's in format 'admin_X'
                        if (strpos($_SESSION['user_id'], 'admin_') === 0) {
                            $adminId = str_replace('admin_', '', $_SESSION['user_id']);
                        } else {
                            // Try to find admin by email
                            $findStmt = $conn->prepare("SELECT id FROM admin_users WHERE email = ?");
                            $findStmt->execute([$email]);
                            $adminRecord = $findStmt->fetch(PDO::FETCH_ASSOC);
                            $adminId = $adminRecord['id'] ?? null;
                        }
                    }
                    
                    if (!$adminId) {
                        echo json_encode(['success' => false, 'message' => 'Admin record not found']);
                        break;
                    }
                    
                    // Check if email is already taken by another admin
                    $checkStmt = $conn->prepare("SELECT id FROM admin_users WHERE email = ? AND id != ?");
                    $checkStmt->execute([$email, $adminId]);
                    if ($checkStmt->fetch()) {
                        echo json_encode(['success' => false, 'message' => 'Email is already taken by another admin']);
                        break;
                    }
                    
                    // Also check if email is taken by regular users
                    $userCheckStmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
                    $userCheckStmt->execute([$email]);
                    if ($userCheckStmt->fetch()) {
                        echo json_encode(['success' => false, 'message' => 'Email is already taken by a user']);
                        break;
                    }
                    
                    // Update admin profile in admin_users table (including role)
                    // Ensure role has a value (default to 'admin' if empty)
                    if (empty($role)) {
                        $role = 'admin';
                    }
                    
                    try {
                        error_log("Updating admin profile - Admin ID: $adminId, Role: $role");
                        $stmt = $conn->prepare("UPDATE admin_users SET first_name = ?, last_name = ?, email = ?, phone = ?, address = ?, role = ? WHERE id = ?");
                        $result = $stmt->execute([$firstName, $lastName, $email, $phone, $address, $role, $adminId]);
                        
                        if ($result) {
                            // Update session data
                            $_SESSION['user_email'] = $email;
                            $_SESSION['user_name'] = ($firstName ?: 'Admin') . ' ' . ($lastName ?: 'User');
                            
                            // Check if role was actually updated by querying it back
                            $checkStmt = $conn->prepare("SELECT role, address, phone FROM admin_users WHERE id = ?");
                            $checkStmt->execute([$adminId]);
                            $updatedData = $checkStmt->fetch(PDO::FETCH_ASSOC);
                            
                            // Get the actual role from database, or use the one we tried to set
                            $actualRole = $updatedData['role'] ?? '';
                            // If database returned empty but we sent a value, use the sent value
                            if (empty($actualRole) && !empty($role)) {
                                $actualRole = $role;
                            }
                            
                            error_log("Updating admin - Sent role: '$role', Database role: '" . ($updatedData['role'] ?? 'NULL') . "', Final role: '$actualRole'");
                            error_log("Updated admin data - Role: " . ($actualRole) . ", Address: " . ($updatedData['address'] ?? 'NULL'));
                            
                            echo json_encode([
                                'success' => true, 
                                'message' => 'Admin profile updated successfully',
                                'updated_role' => $actualRole ?: $role, // Always return the role we set if database is empty
                                'sent_role' => $role, // Include the role we sent for debugging
                                'updated_address' => $updatedData['address'] ?? $address,
                                'updated_phone' => $updatedData['phone'] ?? $phone
                            ]);
                        } else {
                            $errorInfo = $stmt->errorInfo();
                            error_log("Admin profile update failed: " . print_r($errorInfo, true));
                            echo json_encode(['success' => false, 'message' => 'Failed to update admin profile. Error: ' . ($errorInfo[2] ?? 'Unknown error')]);
                        }
                    } catch (PDOException $e) {
                        error_log("Admin profile update exception: " . $e->getMessage());
                        // If role is ENUM and value doesn't match, try to alter table or handle gracefully
                        if (strpos($e->getMessage(), 'enum') !== false || strpos($e->getMessage(), 'ENUM') !== false) {
                            // Role column might be ENUM - need to check and potentially modify table
                            try {
                                // Check current column type
                                $colStmt = $conn->query("SHOW COLUMNS FROM admin_users LIKE 'role'");
                                $colInfo = $colStmt->fetch(PDO::FETCH_ASSOC);
                                
                                if ($colInfo && strpos($colInfo['Type'], 'enum') !== false) {
                                    // Column is ENUM - we need to change it to VARCHAR to allow custom roles
                                    $alterStmt = $conn->prepare("ALTER TABLE admin_users MODIFY COLUMN role VARCHAR(100) DEFAULT 'admin'");
                                    $alterStmt->execute();
                                    
                                    // Retry the update
                                    $stmt = $conn->prepare("UPDATE admin_users SET first_name = ?, last_name = ?, email = ?, phone = ?, address = ?, role = ? WHERE id = ?");
                                    $result = $stmt->execute([$firstName, $lastName, $email, $phone, $address, $role, $adminId]);
                                    
                                    if ($result) {
                                        $_SESSION['user_email'] = $email;
                                        $_SESSION['user_name'] = ($firstName ?: 'Admin') . ' ' . ($lastName ?: 'User');
                                        echo json_encode(['success' => true, 'message' => 'Admin profile updated successfully (table structure updated)']);
                                    } else {
                                        echo json_encode(['success' => false, 'message' => 'Failed to update after table modification']);
                                    }
                                } else {
                                    throw $e; // Re-throw if not an ENUM issue
                                }
                            } catch (Exception $alterEx) {
                                error_log("Failed to alter table: " . $alterEx->getMessage());
                                echo json_encode(['success' => false, 'message' => 'Failed to update role. Please contact administrator to modify role column type.']);
                            }
                        } else {
                            echo json_encode(['success' => false, 'message' => 'Failed to update admin profile: ' . $e->getMessage()]);
                        }
                    }
                } else {
                    // Handle regular user profile update
                    $userId = $_SESSION['user_id'];
                    
                    if (empty($firstName)) {
                        echo json_encode(['success' => false, 'message' => 'First name and email are required']);
                        break;
                    }
                    
                    // Check if email is already taken by another user
                    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                    $stmt->execute([$email, $userId]);
                    if ($stmt->fetch()) {
                        echo json_encode(['success' => false, 'message' => 'Email is already taken by another user']);
                        break;
                    }
                    
                    // Also check if email is taken by admins
                    $adminCheckStmt = $conn->prepare("SELECT id FROM admin_users WHERE email = ?");
                    $adminCheckStmt->execute([$email]);
                    if ($adminCheckStmt->fetch()) {
                        echo json_encode(['success' => false, 'message' => 'Email is already taken by an admin']);
                        break;
                    }
                    
                    // Update user profile
                    $stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, phone_number = ?, address = ? WHERE id = ?");
                    $result = $stmt->execute([$firstName, $lastName, $email, $phone, $address, $userId]);
                    
                    if ($result) {
                        // Update session data
                        $_SESSION['user_email'] = $email;
                        $_SESSION['user_name'] = $firstName . ' ' . $lastName;
                        
                        echo json_encode(['success' => true, 'message' => 'Profile updated successfully']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Failed to update profile']);
                    }
                }
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Error updating profile: ' . $e->getMessage()]);
            }
            break;
            
        case 'cancel_booking':
            // Check for tab-specific session validation
            $tabSessionId = $_POST['tab_session_id'] ?? '';
            $storedTabSessionId = $_SESSION['tab_session_id'] ?? '';
            
            if (isset($_SESSION['user_id']) && isset($_SESSION['user_email'])) {
                // If tab session ID doesn't match, this is a new browser tab - log out
                if (!empty($tabSessionId) && $tabSessionId !== $storedTabSessionId) {
                    $_SESSION = array();
                    session_destroy();
                    echo json_encode(['success' => false, 'message' => 'Session expired - new browser tab']);
                    break;
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'User not logged in']);
                break;
            }
            
            $conn = getConnection();
            if (!$conn) {
                echo json_encode(['success' => false, 'message' => 'Database connection failed']);
                break;
            }
            
            try {
                $bookingId = $_POST['booking_id'] ?? '';
                $bookingType = $_POST['booking_type'] ?? '';
                $userId = $_SESSION['user_id'];
                
                if (empty($bookingId) || empty($bookingType)) {
                    echo json_encode(['success' => false, 'message' => 'Booking ID and type are required']);
                    break;
                }
                
                // Determine the table and column names based on booking type
                if ($bookingType === 'hotel') {
                    $tableName = 'hotel_bookings';
                    $bookingIdColumn = 'id';
                } else {
                    $tableName = 'service_bookings';
                    $bookingIdColumn = 'id';
                }
                
                // First, verify the booking belongs to the logged-in user
                $checkStmt = $conn->prepare("SELECT booking_status FROM {$tableName} WHERE id = ? AND user_id = ?");
                $checkStmt->execute([$bookingId, $userId]);
                $booking = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$booking) {
                    echo json_encode(['success' => false, 'message' => 'Booking not found or you do not have permission to cancel it']);
                    break;
                }
                
                // Only allow cancellation of pending bookings
                if ($booking['booking_status'] !== 'pending') {
                    echo json_encode(['success' => false, 'message' => 'Only pending bookings can be cancelled']);
                    break;
                }
                
                // Update the booking status to 'cancelled'
                $updateStmt = $conn->prepare("UPDATE {$tableName} SET booking_status = 'cancelled' WHERE id = ? AND user_id = ?");
                $result = $updateStmt->execute([$bookingId, $userId]);
                
                if ($result) {
                    echo json_encode(['success' => true, 'message' => 'Booking cancelled successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to cancel booking']);
                }
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Error cancelling booking: ' . $e->getMessage()]);
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>
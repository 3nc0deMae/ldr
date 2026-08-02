<?php
// Adjust the relative path to your database connection file if needed
require_once __DIR__ . '/config/database.php'; 

// Admin user credentials
$username = 'admin';
$email    = 'admin@example.com';
$password = 'admin123'; // Change to your desired password
$role     = 'admin';

// Hash password using PHP's standard password_hash (compatible with password_verify)
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

try {
    // 1. Check if an admin already exists to prevent duplicates
    $checkStmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
    $checkStmt->execute([$username, $email]);

    if ($checkStmt->rowCount() > 0) {
        echo "⚠️ Admin user or email already exists!";
        exit;
    }

    // 2. Insert the admin account
    $stmt = $pdo->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)");
    $result = $stmt->execute([$username, $email, $hashedPassword, $role]);

    if ($result) {
        echo "✅ Admin account created successfully!<br>";
        echo "<strong>Username:</strong> " . htmlspecialchars($username) . "<br>";
        echo "<strong>Password:</strong> " . htmlspecialchars($password);
    } else {
        echo "❌ Failed to insert admin user.";
    }

} catch (PDOException $e) {
    echo "❌ Database Error: " . $e->getMessage();
}
?>

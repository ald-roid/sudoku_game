<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $difficulty = isset($_POST['difficulty']) ? (int)$_POST['difficulty'] : 0;
    $size = isset($_POST['size']) ? (int)$_POST['size'] : 9;
    
    // Validate difficulty and size
    if ($difficulty >= 1 && $difficulty <= 4 && in_array($size, [4, 6, 9])) {
        // Initialize grid_sizes if not exists
        if (!isset($_SESSION['grid_sizes'])) {
            $_SESSION['grid_sizes'] = [];
        }
        
        // Save the grid size for this difficulty
        $_SESSION['grid_sizes'][$difficulty] = $size;
        
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
} 
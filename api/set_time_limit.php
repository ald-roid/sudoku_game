<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $difficulty = isset($_POST['difficulty']) ? (int)$_POST['difficulty'] : 1;
    $minutes = isset($_POST['minutes']) ? (int)$_POST['minutes'] : 3;

    // Validate difficulty
    if (!in_array($difficulty, [1, 2, 3, 4])) {
        echo json_encode(['success' => false, 'error' => 'Invalid difficulty']);
        exit;
    }

    // Initialize time_limits in session if not exists
    if (!isset($_SESSION['time_limits'])) {
        $_SESSION['time_limits'] = [];
    }

    // Save time limit for this difficulty
    $_SESSION['time_limits'][$difficulty] = $minutes;

    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
}
?> 
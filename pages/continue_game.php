<?php
session_start();
require_once __DIR__ . '/../config/db_connect.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

// Check if game ID is provided
if (!isset($_GET['id'])) {
    header('Location: home.php');
    exit();
}

$game_id = $_GET['id'];
$user_id = $_SESSION['user_id'];

try {
    // Get the saved game data
    $stmt = $pdo->prepare("
        SELECT 
            sg.*,
            l.level_number,
            l.grid_size,
            d.name as difficulty_name,
            d.id as difficulty_id
        FROM saved_games sg
        JOIN levels l ON sg.level_id = l.id
        JOIN difficulties d ON l.difficulty_id = d.id
        WHERE sg.id = ? AND sg.user_id = ?
    ");
    $stmt->execute([$game_id, $user_id]);
    $saved_game = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$saved_game) {
        header('Location: home.php');
        exit();
    }

    // Store the game state in session
    $_SESSION['current_game'] = [
        'id' => $saved_game['id'],
        'level_id' => $saved_game['level_id'],
        'difficulty_id' => $saved_game['difficulty_id'],
        'level_number' => $saved_game['level_number'],
        'grid_size' => $saved_game['grid_size'],
        'puzzle' => json_decode($saved_game['game_state'], true),
        'time_remaining' => $saved_game['time_remaining'],
        'current_score' => $saved_game['current_score'],
        'mistakes_made' => $saved_game['mistakes_made'],
        'hints_used' => $saved_game['hints_used']
    ];

    // Redirect to game.php with the saved game state
    header('Location: game.php?continue=true');
    exit();

} catch (PDOException $e) {
    error_log("Error loading saved game: " . $e->getMessage());
    header('Location: home.php');
    exit();
}
?> 
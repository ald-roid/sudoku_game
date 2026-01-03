<?php
session_start();
require_once __DIR__ . '/../config/db_connect.php';

header('Content-Type: application/json');

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['level_id']) || !isset($data['game_state']) || !isset($data['difficulty_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid data']);
    exit();
}

try {
    // Validate the game state is valid JSON
    $gameStateJson = json_encode($data['game_state']);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid game state format');
    }

    // Begin transaction
    $pdo->beginTransaction();

    try {
        // Get the level information
        $stmt = $pdo->prepare("
            SELECT l.id, l.level_number, l.difficulty_id
            FROM levels l
            WHERE l.id = ? AND l.difficulty_id = ?
        ");
        $stmt->execute([$data['level_id'], $data['difficulty_id']]);
        $level = $stmt->fetch();
        
        if (!$level) {
            throw new Exception('Level not found or invalid difficulty');
        }

        // Check if there's an existing saved game
        $stmt = $pdo->prepare("SELECT id FROM saved_games WHERE user_id = ? AND level_id = ?");
        $stmt->execute([$_SESSION['user_id'], $data['level_id']]);
        $existing = $stmt->fetch();

        if ($existing) {
            // Update existing save
            $stmt = $pdo->prepare("
                UPDATE saved_games 
                SET game_state = ?, 
                    time_remaining = ?, 
                    current_score = ?,
                    mistakes_made = ?,
                    hints_used = ?,
                    difficulty_id = ?,
                    last_played = CURRENT_TIMESTAMP 
                WHERE user_id = ? AND level_id = ?
            ");
            $result = $stmt->execute([
                $gameStateJson,
                $data['time_remaining'],
                $data['current_score'] ?? 0,
                $data['mistakes_made'] ?? 0,
                $data['hints_used'] ?? 0,
                $data['difficulty_id'],
                $_SESSION['user_id'],
                $data['level_id']
            ]);
        } else {
            // Create new save
            $stmt = $pdo->prepare("
                INSERT INTO saved_games 
                (user_id, level_id, difficulty_id, game_state, time_remaining, current_score, mistakes_made, hints_used, saved_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ");
            $result = $stmt->execute([
                $_SESSION['user_id'],
                $data['level_id'],
                $data['difficulty_id'],
                $gameStateJson,
                $data['time_remaining'],
                $data['current_score'] ?? 0,
                $data['mistakes_made'] ?? 0,
                $data['hints_used'] ?? 0
            ]);
        }

        if (!$result) {
            throw new Exception('Failed to save game state');
        }

        // Commit transaction
        $pdo->commit();

        echo json_encode([
            'success' => true, 
            'message' => 'Game saved successfully',
            'debug' => [
                'user_id' => $_SESSION['user_id'],
                'level_id' => $data['level_id'],
                'difficulty_id' => $data['difficulty_id'],
                'time_remaining' => $data['time_remaining']
            ]
        ]);

    } catch (Exception $e) {
        // Rollback transaction on error
        $pdo->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Error saving game: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Error saving game: ' . $e->getMessage(),
        'debug' => [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]
    ]);
}
?> 
<?php
// Initialize session
session_start();

// Include database connection
require_once __DIR__ . '/../config/db_connect.php';

// Simple user setup (no database dependency for user)
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $user_name = $_SESSION['user_name'] ?? 'User';
} else {
    $user_id = 1;
    $user_name = 'Guest';
}

// Get difficulty and level from URL
$difficulty_id = isset($_GET['difficulty']) ? (int)$_GET['difficulty'] : 1;
$level_number = isset($_GET['level']) ? (int)$_GET['level'] : 1;

// Get time limit from URL or session
$time_limit = isset($_GET['time_limit']) ? (int)$_GET['time_limit'] : 
              ($_SESSION['time_limits'][$difficulty_id] ?? 5); // Default to 5 minutes

// Get grid size from URL or session
$grid_size = isset($_GET['grid_size']) ? (int)$_GET['grid_size'] : 
             ($_SESSION['grid_sizes'][$difficulty_id] ?? 9);

// Validate difficulty
if (!in_array($difficulty_id, [1, 2, 3, 4])) {
    $difficulty_id = 1;
}

// Validate grid size
if (!in_array($grid_size, [4, 6, 9])) {
    $grid_size = 9;
}

// Simple difficulty setup (no database needed)
$difficulty_names = ['Easy', 'Medium', 'Hard', 'Expert'];
$difficulty = [
    'id' => $difficulty_id,
    'name' => $difficulty_names[min(max($difficulty_id - 1, 0), 3)]
];

// Initialize completed levels in session if not exists
if (!isset($_SESSION['completed_levels'])) {
    $_SESSION['completed_levels'] = [];
}

// Add this after session_start() and before using $selected_time_limit
$time_limits = [
    1 => [5, 8, 10, 15],   // Easy: 5min, 8min, 10min, 15min
    2 => [5, 8, 10, 15],   // Medium: 5min, 8min, 10min, 15min
    3 => [5, 8, 10, 12],   // Hard: 5min, 8min, 10min, 12min
    4 => [5, 8, 10, 12]    // Expert: 5min, 8min, 10min, 12min
];

// Determine the time limit from URL, session, or default
if (isset($_GET['time_limit'])) {
    $selected_time_limit = (int)$_GET['time_limit'];
} elseif (isset($_SESSION['time_limits'][$difficulty_id])) {
    $selected_time_limit = (int)$_SESSION['time_limits'][$difficulty_id];
} else {
    // Fallback default (5 minutes)
    $selected_time_limit = $time_limits[$difficulty_id][0];
}
// Validate the selected time limit
if (!in_array($selected_time_limit, $time_limits[$difficulty_id])) {
    $selected_time_limit = $time_limits[$difficulty_id][0];
}
// Convert to seconds
$selected_time_limit = $selected_time_limit * 60;

// Determine the grid size from URL, session, or default
if (isset($_GET['grid_size'])) {
    $selected_grid_size = (int)$_GET['grid_size'];
} elseif (isset($_SESSION['grid_sizes'][$difficulty_id])) {
    $selected_grid_size = (int)$_SESSION['grid_sizes'][$difficulty_id];
} else {
    // Fallback default (9x9)
    $selected_grid_size = 9;
}

// Validate grid size for the difficulty
$allowed_grid_sizes = [
    1 => [4, 6, 9],    // Easy: 4x4, 6x6, 9x9
    2 => [4, 6, 9],    // Medium: 4x4, 6x6, 9x9
    3 => [9],          // Hard: only 9x9
    4 => [9]           // Expert: only 9x9
];

if (!in_array($selected_grid_size, $allowed_grid_sizes[$difficulty_id])) {
    $selected_grid_size = $allowed_grid_sizes[$difficulty_id][0];
}

$level = null;
$savedGame = null;

// Define flags for continuing/resuming games
$isContinuing = isset($_GET['continue']) && $_GET['continue'] == 'true';
$isResuming = isset($_GET['resume']) && $_GET['resume'] == 1;

// Try to load a saved game if continuing from session
if ($isContinuing && isset($_SESSION['current_game'])) {
    $savedGame = $_SESSION['current_game'];
    $difficulty_id = $savedGame['difficulty_id'];
    $level_number = $savedGame['level_number'];
    $level_id = $savedGame['level_id'];
    $grid_size = $savedGame['grid_size'];
    $puzzle = $savedGame['puzzle'];
    $time_limit = $savedGame['time_remaining'];
    $current_score = $savedGame['current_score'];
    $mistakes = $savedGame['mistakes_made'];
    $hintsUsed = $savedGame['hints_used'];
    $gameStarted = true;
    
    // Clear the session data after loading
    unset($_SESSION['current_game']);
} else if ($isResuming && isset($_SESSION['user_id']) && isset($_GET['level_id'])) {
    $savedGame = loadSavedGame($pdo, $_SESSION['user_id'], (int)$_GET['level_id']);
    if ($savedGame) {
        // If saved game found, use its level and difficulty
        $level_id = (int)$_GET['level_id'];
        $difficulty_id = $savedGame['difficulty_id'] ?? $difficulty_id; // Use saved difficulty, fallback to URL/default
        $level_number = $savedGame['level_number'] ?? $level_number; // Use saved level number
    }
}

// Fetch level details from the database based on difficulty_id and level_number
// This will be the base level data, potentially overwritten by a saved game
try {
    $stmt = $pdo->prepare("
        SELECT l.id, l.level_number, l.grid_size, l.time_limit,
               d.id as difficulty_id, d.name as difficulty_name, d.time_multiplier
        FROM levels l
        JOIN difficulties d ON l.difficulty_id = d.id
        WHERE l.difficulty_id = ? AND l.level_number = ?
    ");
    $stmt->execute([$difficulty_id, $level_number]);
    $level = $stmt->fetch(PDO::FETCH_ASSOC);

    // If the level doesn't exist in the database, create it
    if (!$level) {
        // Use the selected grid size and time limit
        $current_grid_size = $selected_grid_size;
        $current_time_limit = $selected_time_limit;

        // Get difficulty multiplier for time limit if needed
        $stmt_diff = $pdo->prepare("SELECT time_multiplier FROM difficulties WHERE id = ?");
        $stmt_diff->execute([$difficulty_id]);
        $diff_info = $stmt_diff->fetch(PDO::FETCH_ASSOC);
        $time_multiplier = $diff_info['time_multiplier'] ?? 1;
        $current_time_limit = $current_time_limit * $time_multiplier;

        // Insert new level record
        $insert_stmt = $pdo->prepare("
            INSERT INTO levels (difficulty_id, level_number, grid_size, time_limit)
            VALUES (?, ?, ?, ?)
        ");
        $insert_stmt->execute([
            $difficulty_id,
            $level_number,
            $current_grid_size,
            $current_time_limit
        ]);
        $level_id = $pdo->lastInsertId();

        // Re-fetch the newly created level
        $stmt = $pdo->prepare("
            SELECT l.id, l.level_number, l.grid_size, l.time_limit,
                   d.id as difficulty_id, d.name as difficulty_name, d.time_multiplier
            FROM levels l
            JOIN difficulties d ON l.difficulty_id = d.id
            WHERE l.id = ?
        ");
        $stmt->execute([$level_id]);
        $level = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Override the database grid_size with the selected grid_size
    $level['grid_size'] = $selected_grid_size;

    // Generate a new puzzle for this session using the selected grid size
    $game_data = generatePuzzle($difficulty_id, $level_number, $selected_grid_size);
    $puzzle = $game_data['puzzle'];
    $solution = $game_data['solution'];

} catch (PDOException $e) {
    error_log("Database error fetching level: " . $e->getMessage());
    // Fallback to default values or handle error gracefully
    $level = [
        'id' => 1,
        'level_number' => $level_number,
        'grid_size' => $selected_grid_size,
        'time_limit' => 300,
        'difficulty_id' => $difficulty_id,
        'difficulty_name' => 'Easy', // Default difficulty name
    ];
    
    // Generate a new puzzle even for fallback
    $game_data = generatePuzzle($difficulty_id, $level_number, $selected_grid_size);
    $puzzle = $game_data['puzzle'];
    $solution = $game_data['solution'];
}

// Ensure $level is never null here for the next blocks
if ($level === null) {
    $level = [
        'id' => 1,
        'level_number' => $level_number,
        'grid_size' => $selected_grid_size,
        'time_limit' => 300,
        'difficulty_id' => $difficulty_id,
        'difficulty_name' => 'Easy', // Default difficulty name
    ];
    
    // Generate a new puzzle for null level case
    $game_data = generatePuzzle($difficulty_id, $level_number, $selected_grid_size);
    $puzzle = $game_data['puzzle'];
    $solution = $game_data['solution'];
}

// Set difficulty_name for display
$difficulty_name = $level['difficulty_name'] ?? $difficulty['name'] ?? 'Easy';

// If a saved game was loaded, override initial puzzle and time
if ($savedGame) {
    if (isset($savedGame['game_state']) && isset($savedGame['solution_data'])) {
        $puzzle = json_decode($savedGame['game_state'], true);
        $solution = json_decode($savedGame['solution_data'], true);
    } else {
        // Fallback: generate a new puzzle or show an error message
        $game_data = generatePuzzle($difficulty_id, $level_number, $selected_grid_size);
        $puzzle = $game_data['puzzle'];
        $solution = $game_data['solution'];
    }
    $time_limit = $savedGame['time_remaining'];
    $current_score = $savedGame['current_score'];
    $mistakes = $savedGame['mistakes_made'];
    $hintsUsed = $savedGame['hints_used'];
    $gameStarted = true; // Indicate that a game is being resumed
} else {
    // For a new game, use the newly generated puzzle
    $time_limit = $selected_time_limit;
    $current_score = 0;
    $mistakes = 0;
    $hintsUsed = 0;
    $gameStarted = false;
}

// Initialize game variables
$time_limit = $time_limit * 60; // Convert to seconds
$current_score = 0;
$mistakes = 0;
$hintsUsed = 0;
$gameStarted = false;

// Generate puzzle based on difficulty and level
function generatePuzzle($difficulty_id, $level, $grid_size) {
    // Base complete sudoku grid based on size
    $complete_grid = [];
    
    if ($grid_size === 4) {
        // 4x4 grid
        $complete_grid = [
            [1, 2, 3, 4],
            [3, 4, 1, 2],
            [2, 1, 4, 3],
            [4, 3, 2, 1]
        ];
    } elseif ($grid_size === 6) {
        // 6x6 grid
        $complete_grid = [
            [1, 2, 3, 4, 5, 6],
            [4, 5, 6, 1, 2, 3],
            [2, 3, 1, 5, 6, 4],
            [5, 6, 4, 2, 3, 1],
            [3, 1, 2, 6, 4, 5],
            [6, 4, 5, 3, 1, 2]
        ];
    } else {
        // 9x9 grid (original)
        $complete_grid = [
            [5,3,4,6,7,8,9,1,2],
            [6,7,2,1,9,5,3,4,8],
            [1,9,8,3,4,2,5,6,7],
            [8,5,9,7,6,1,4,2,3],
            [4,2,6,8,5,3,7,9,1],
            [7,1,3,9,2,4,8,5,6],
            [9,6,1,5,3,7,2,8,4],
            [2,8,7,4,1,9,6,3,5],
            [3,4,5,2,8,6,1,7,9]
        ];
    }
    
    // Shuffle the grid based on level for variation
    $seed = $difficulty_id * 1000 + $level;
    mt_srand($seed);
    
    // Determine how many cells to remove based on difficulty and grid size
    $cells_to_remove = [
        1 => $grid_size === 4 ? 8 + ($level % 3) : // Easy 4x4: 8-10 cells
             ($grid_size === 6 ? 20 + ($level % 5) : // Easy 6x6: 20-24 cells
             32 + ($level % 5)), // Easy 9x9: 32-36 cells
        2 => $grid_size === 4 ? 10 + ($level % 3) : // Medium 4x4: 10-12 cells
             ($grid_size === 6 ? 24 + ($level % 5) : // Medium 6x6: 24-28 cells
             37 + ($level % 8)), // Medium 9x9: 37-44 cells
        3 => 42 + ($level % 10), // Hard: 42-51 cells
        4 => 47 + ($level % 10)  // Expert: 47-56 cells
    ];
    
    $remove_count = $cells_to_remove[$difficulty_id];
    
    // Create puzzle by removing cells
    $puzzle = $complete_grid;
    $removed = 0;
    $attempts = 0;
    
    while ($removed < $remove_count && $attempts < 100) {
        $row = mt_rand(0, $grid_size - 1);
        $col = mt_rand(0, $grid_size - 1);
        
        if ($puzzle[$row][$col] != 0) {
            $puzzle[$row][$col] = 0;
            $removed++;
        }
        $attempts++;
    }
    
    return [
        'puzzle' => $puzzle,
        'solution' => $complete_grid
    ];
}

// Handle score saving via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_score'])) {
    require_once __DIR__ . '/../config/db_connect.php';
    
    $score_key = "{$difficulty_id}_{$level_number}";
    $time = (int)$_POST['time_taken'];
    $score = (int)$_POST['score'];
    
    try {
        // Get level ID from database using difficulty_id instead of name
        $stmt = $pdo->prepare("SELECT id FROM levels WHERE difficulty_id = ? AND level_number = ?");
        $stmt->execute([$difficulty_id, $level_number]);
        $level_data = $stmt->fetch();
        
        $level_id = null;
        
        if ($level_data) {
            $level_id = $level_data['id'];
        } else {
            // If level doesn't exist in database, create it
            $stmt = $pdo->prepare("
                INSERT INTO levels (difficulty_id, level_number, grid_size, time_limit, puzzle_data, solution_data)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $difficulty_id,
                $level_number,
                $grid_size,
                $time_limit * 60,
                json_encode($puzzle),
                json_encode($solution)
            ]);
            
            $level_id = $pdo->lastInsertId();
        }
        
        // Now save the progress
        $stmt = $pdo->prepare("
            INSERT INTO user_progress (user_id, level_id, completed, best_time, best_score, attempts)
            VALUES (?, ?, TRUE, ?, ?, 1)
            ON DUPLICATE KEY UPDATE 
                completed = TRUE,
                best_time = LEAST(best_time, ?),
                best_score = GREATEST(best_score, ?),
                attempts = attempts + 1
        ");
        $stmt->execute([$user_id, $level_id, $time, $score, $time, $score]);
        
        // Add to leaderboard
        $stmt = $pdo->prepare("
            INSERT INTO leaderboard (user_id, level_id, score, time_taken)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$user_id, $level_id, $score, $time]);
        
        // Update session for immediate feedback
        $_SESSION['completed_levels'][$score_key] = true;
    
    echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// Update the saveGameState function
function saveGameState($pdo, $userId, $levelId, $gameState) {
    try {
        // First, get the difficulty_id from the levels table
        $stmt = $pdo->prepare("
            SELECT l.difficulty_id, l.level_number 
            FROM levels l 
            WHERE l.id = ?
        ");
        $stmt->execute([$levelId]);
        $levelInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$levelInfo) {
            error_log("Error: Level not found for level_id: " . $levelId);
            return false;
        }

        // Check if a saved game already exists for this user and level
        $stmt = $pdo->prepare("
            SELECT id FROM saved_games 
            WHERE user_id = ? AND level_id = ?
        ");
        $stmt->execute([$userId, $levelId]);
        $existingGame = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existingGame) {
            // Update existing saved game
            $stmt = $pdo->prepare("
                UPDATE saved_games 
                SET game_state = ?, 
                    time_remaining = ?, 
                    current_score = ?,
                    mistakes_made = ?,
                    hints_used = ?,
                    last_played = CURRENT_TIMESTAMP
                WHERE user_id = ? AND level_id = ?
            ");
            $stmt->execute([
                json_encode($gameState['puzzle']),
                $gameState['timeRemaining'],
                $gameState['score'],
                $gameState['mistakes'],
                $gameState['hintsUsed'],
                $userId,
                $levelId
            ]);
        } else {
            // Insert new saved game
            $stmt = $pdo->prepare("
                INSERT INTO saved_games 
                (user_id, level_id, difficulty_id, game_state, time_remaining, current_score, mistakes_made, hints_used)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $userId,
                $levelId,
                $levelInfo['difficulty_id'],
                json_encode($gameState['puzzle']),
                $gameState['timeRemaining'],
            $gameState['score'],
            $gameState['mistakes'],
            $gameState['hintsUsed']
        ]);
        }
        return true;
    } catch (PDOException $e) {
        error_log("Error saving game state: " . $e->getMessage());
        return false;
    }
}

// Helper function to calculate stars based on score, mistakes, and hints
function calculateStars($score, $mistakes, $hintsUsed) {
    if ($mistakes === 0 && $hintsUsed === 0) {
        return 3; // Perfect game
    } else if ($mistakes <= 2 && $hintsUsed <= 1) {
        return 2; // Good game
    } else {
        return 1; // Basic completion
    }
}

// Update the AJAX handler for saving games
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_game') {
    require_once __DIR__ . '/../config/db_connect.php';
    $response = ['success' => false];
    
    try {
        if (!isset($_SESSION['user_id'])) {
            throw new Exception('User not logged in');
        }

        if (!isset($_POST['game_state']) || !isset($_POST['level_id'])) {
            throw new Exception('Missing required data');
        }

        $gameState = json_decode($_POST['game_state'], true);
        $levelId = (int)$_POST['level_id'];
        $timeRemaining = (int)$_POST['time_remaining'];
        
        if (!$gameState || !$levelId) {
            throw new Exception('Invalid game state or level ID');
        }

        // Get the difficulty_id from the levels table
        $stmt = $pdo->prepare("SELECT difficulty_id FROM levels WHERE id = ?");
        $stmt->execute([$levelId]);
        $levelInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$levelInfo) {
            throw new Exception('Level not found');
        }

        // Check if a saved game already exists
        $stmt = $pdo->prepare("SELECT id FROM saved_games WHERE user_id = ? AND level_id = ?");
        $stmt->execute([$_SESSION['user_id'], $levelId]);
        $existingGame = $stmt->fetch();

        if ($existingGame) {
            // Update existing saved game
            $stmt = $pdo->prepare("
                UPDATE saved_games 
                SET game_state = ?, 
                    time_remaining = ?, 
                    saved_at = NOW()
                WHERE user_id = ? AND level_id = ?
            ");
            $result = $stmt->execute([
                json_encode($gameState),
                $timeRemaining,
                $_SESSION['user_id'],
                $levelId
            ]);
        } else {
            // Insert new saved game
            $stmt = $pdo->prepare("
                INSERT INTO saved_games 
                (user_id, level_id, game_state, time_remaining, saved_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $result = $stmt->execute([
                $_SESSION['user_id'],
                $levelId,
                json_encode($gameState),
                $timeRemaining
            ]);
        }

        if (!$result) {
            throw new Exception('Failed to save game state');
        }

        $response['success'] = true;
        $response['message'] = 'Game saved successfully';
    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
        error_log("Error in save_game handler: " . $e->getMessage());
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Add this function to load saved game state
function loadSavedGame($pdo, $userId, $levelId) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                sg.*,
                l.level_number,
                l.difficulty_id, /* Add this to retrieve difficulty_id from levels table */
                d.name as difficulty_name,
                d.time_multiplier
            FROM saved_games sg
            JOIN levels l ON sg.level_id = l.id
            JOIN difficulties d ON sg.difficulty_id = d.id
            WHERE sg.user_id = ? AND sg.level_id = ?
        ");
        $stmt->execute([$userId, $levelId]);
        $savedGame = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($savedGame) {
            return [
                'puzzle' => json_decode($savedGame['game_state'], true),
                'timeRemaining' => $savedGame['time_remaining'],
                'score' => $savedGame['current_score'],
                'mistakes' => $savedGame['mistakes_made'],
                'hintsUsed' => $savedGame['hints_used'],
                'levelNumber' => $savedGame['level_number'],
                'difficultyName' => $savedGame['difficulty_name'],
                'difficulty_id' => $savedGame['difficulty_id'], /* Return difficulty_id */
                'timeMultiplier' => $savedGame['time_multiplier']
            ];
        }
        return null;
    } catch (PDOException $e) {
        error_log("Error loading saved game: " . $e->getMessage());
        return null;
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sudoku - <?php echo htmlspecialchars($difficulty_name); ?> Level <?php echo htmlspecialchars($level_number); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        :root {
            /* Primary Color Palette */
            --primary-green: rgb(12, 107, 75);
            --accent-green: rgb(39, 108, 82);
            --dark-green: rgb(5, 71, 52);
            --light-green: #d1fae5;
            --dark-gray: #1f2937;
            --darker-gray: #111827;
            --card-highlight: #374151;
            --light-gray: #f3f4f6;
            --white: #ffffff;
            --error-color: #ef4444;
            --success-color: #10b981;
            --shadow-soft: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-strong: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            --glow: 0 0 30px rgba(16, 185, 129, 0.4);
            --glow-strong: 0 0 50px rgba(16, 185, 129, 0.6);
            --gradient-primary: linear-gradient(135deg, var(--primary-green) 0%, var(--accent-green) 50%, var(--dark-green) 100%);
            --gradient-light: linear-gradient(135deg, var(--white) 0%, var(--light-green) 50%, rgba(209, 250, 229, 0.3) 100%);
            --gradient-card: linear-gradient(145deg, rgba(255, 255, 255, 0.95) 0%, rgba(255, 255, 255, 0.8) 100%);
        }

        body {
            background: linear-gradient(135deg,rgb(1, 11, 9) 0%,rgb(42, 116, 106) 50%,rgb(1, 7, 6) 100%);
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        header {
            background: var(--gradient-primary);
            padding: 20px 0;
            box-shadow: var(--shadow-strong);
            position: relative;
            overflow: hidden;
            color: var(--white);
            border-radius: 10px;
            margin: 10px;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .header-buttons {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .back-btn {
            color: #ffffff;
            background-color: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .back-btn:hover {
            background-color: rgba(255, 255, 255, 0.3);
            transform: translateX(-3px);
        }

        .back-btn[href*="dashboard"]:hover {
            transform: translateY(-3px);
        }

        .level-info h1 {
            font-size: 1.5rem;
            font-weight: 600;
            color: #ffffff;
            margin: 0;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .save-btn {
            background: rgba(255, 255, 255, 0.2);
            color: #ffffff;
            border: none;
            border-radius: 20px;
            padding: 8px 16px;
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 14px;
            font-weight: 500;
        }

        .save-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
        }

        .save-btn i {
            font-size: 16px;
        }

        .save-btn.saving {
            opacity: 0.7;
            cursor: not-allowed;
        }

        .user-info {
            display: flex;
            align-items: center;
            background: rgb(0 255 245 / 10%);
            backdrop-filter: blur(10px);
            padding: 8px 16px;
            margin-left: -50px;
            border-radius: 30px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            border: 2px solid #00fffc;
        }

        /* Main Container */
        .main-container {
            max-width: 1400px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .game-container {
            display: grid;
            grid-template-columns: minmax(320px, 2fr) 1fr;
            gap: 30px;
        }

        /* Sudoku Board */
        .sudoku-wrapper {
            background: var(--gradient-card);
            border-radius: 12px;
            padding: 25px;
            box-shadow: var(--shadow-soft);
            display: flex;
            justify-content: center;
            align-items: center;
            transition: all 0.3s ease;
        }

        .sudoku-wrapper:hover {
            box-shadow: var(--shadow-strong);
            transform: translateY(-2px);
        }

        .sudoku-board {
            display: grid;
            gap: 0;
            width: 100%;
            max-width: 540px;
            margin: 0 auto;
            position: relative;
            aspect-ratio: 1/1;
            background-color: #cbd5e1;
            border: 2px solid #065f46;
            border-radius: 4px;
            overflow: hidden;
        }

        /* Cell styling */
        .cell {
            background-color: #ffffff;
            aspect-ratio: 1/1;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: clamp(1.2rem, 2.5vw, 1.8rem);
            font-weight: 600;
            position: relative;
            cursor: pointer;
            transition: all 0.2s ease;
            color: #111827;
            border: 1px solid #cbd5e1;
        }

        /* Dynamic box borders based on grid size */
        /* 4x4 grid borders */
        .sudoku-board[style*="grid-template-columns: repeat(4, 1fr)"] .cell[data-col="2"] {
            border-left: 2px solid black;
        }
        .sudoku-board[style*="grid-template-columns: repeat(4, 1fr)"] .cell[data-row="2"] {
            border-top: 2px solid black;
        }

        /* 6x6 grid borders */
        .sudoku-board[style*="grid-template-columns: repeat(6, 1fr)"] .cell[data-col="2"],
        .sudoku-board[style*="grid-template-columns: repeat(6, 1fr)"] .cell[data-col="4"] {
            border-left: 2px solid black;
        }
        .sudoku-board[style*="grid-template-columns: repeat(6, 1fr)"] .cell[data-row="2"],
        .sudoku-board[style*="grid-template-columns: repeat(6, 1fr)"] .cell[data-row="4"] {
            border-top: 2px solid black;
        }

        /* 9x9 grid borders */
        .sudoku-board[style*="grid-template-columns: repeat(9, 1fr)"] .cell[data-col="3"],
        .sudoku-board[style*="grid-template-columns: repeat(9, 1fr)"] .cell[data-col="6"] {
            border-left: 2px solid black;
        }
        .sudoku-board[style*="grid-template-columns: repeat(9, 1fr)"] .cell[data-row="3"],
        .sudoku-board[style*="grid-template-columns: repeat(9, 1fr)"] .cell[data-row="6"] {
            border-top: 2px solid black;
        }

        /* Make borders thicker for 9x9 */
        .sudoku-board[style*="grid-template-columns: repeat(9, 1fr)"] {
            border: 3px solid black;
        }

        .sudoku-board[style*="grid-template-columns: repeat(9, 1fr)"] .cell {
            border: 0.5px solid #666;
        }

        /* Fixed cells styling */
        .cell.fixed {
            color: #003b2a;
            font-weight: 700;
            background-color: #a3f0cc;
        }

        /* Selected cell styling */
        .cell.selected {
            background-color: #a7f3d0;
            box-shadow: inset 0 0 0 2px black;
            transform: scale(1.05);
            transition: all 0.2s ease;
        }

        .cell.error {
            color: #ef4444;
            animation: shake 0.5s;
        }

        .cell.hint {
            animation: pulse 1s;
            color: #34d399;
        }

        /* Same number highlighting */
        .cell.same-number {
            background-color: #fef3c7;
            color: #92400e;
            font-weight: 700;
            transition: all 0.2s ease;
        }

        .cell.selected.same-number {
            background-color: #fbbf24;
            color: #78350f;
            transform: scale(1.05);
        }

        /* Remove related cell highlighting styles */
        .cell.same-row, .cell.same-col, .cell.same-box, .cell.same-number {
            background-color: inherit;
        }

        /* Notes Grid */
        .notes-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            grid-template-rows: repeat(3, 1fr);
            width: 100%;
            height: 100%;
            position: absolute;
            top: 0;
            left: 0;
            font-size: 0.7em;
        }

        .note {
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6b7280;
        }

        /* Game Controls */
        .game-controls {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .control-panel {
            background: var(--gradient-card);
            border-radius: 8px;
            padding: 30px;
            box-shadow: var(--shadow-soft);
            transition: all 0.3s ease;
        }

        .control-panel:hover {
            box-shadow: var(--shadow-strong);
            transform: translateY(-2px);
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            border-bottom: 1px solid #f3f4f6;
            padding-bottom: 10px;
        }

        .panel-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #111827;
        }

        .timer {
            font-size: 1.2rem;
            font-weight: 700;
            color: black;
        }

        .game-stats {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .stat-item {
            text-align: center;
            flex: 1;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: black;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .stat-value i {
            font-size: 1.8rem;
        }

        .stat-value i.fa-times-circle {
            color: #ef4444;
        }

        .stat-value i.fa-lightbulb {
            color: #f59e0b;
        }

        .stat-value i.fa-star {
            color: #f59e0b;
        }

        .stat-value span {
            min-width: 1.5em;
            text-align: center;
        }

        .stat-label {
            font-size: 0.9rem;
            color: #6b7280;
        }

        /* Numpad */
        .numpad {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 20px;
        }

        .num-btn {
            background: var(--gradient-light);
            border: 1px solid var(--light-green);
            border-radius: 8px;
            color: var(--dark-green);
            font-size: 1.5rem;
            font-weight: 600;
            padding: 12px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .num-btn:hover {
            background: var(--light-green);
            color: var(--dark-green);
            transform: translateY(-2px);
            box-shadow: var(--shadow-soft);
        }

        /* Control Buttons */
        .control-btns {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
        }

        .control-btn {
            background: var(--gradient-light);
            border: 1px solid var(--light-green);
            border-radius: 8px;
            color: var(--dark-green);
            font-size: 1rem;
            font-weight: 500;
            padding: 12px;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .control-btn:hover {
            background: var(--light-green);
            color: var(--dark-green);
            transform: translateY(-2px);
            box-shadow: var(--shadow-soft);
        }

        .control-btn.selected {
            background: var(--gradient-primary);
            color: var(--white);
            box-shadow: var(--glow);
        }

        #restart-btn {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: var(--error-color);
        }

        #restart-btn:hover {
            background: linear-gradient(135deg, #fecaca 0%, #fca5a5 100%);
            color: var(--error-color);
        }

        #save-btn {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #3b82f6;
        }

        #save-btn:hover {
            background: linear-gradient(135deg, #bfdbfe 0%, #93c5fd 100%);
            color: #2563eb;
        }

        /* Best Scores */
        .best-scores {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .best-time, .best-score {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            background-color: #f3f4f6;
            border-radius: 8px;
            border: 1px solid #cbd5e1;
        }

        /* Leaderboard */
        .leaderboard-list {
            list-style-type: none;
            margin-top: 10px;
            padding: 0;
        }

        .leaderboard-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            margin-bottom: 5px;
            background-color: #f3f4f6;
            border-radius: 8px;
            transition: all 0.2s ease;
            border: 1px solid #cbd5e1;
        }

        .leaderboard-item:hover {
            background-color: #d1fae5;
            transform: translateX(3px);
        }

        .leaderboard-rank {
            font-weight: 700;
            color: black;
            margin-right: 10px;
        }

        .leaderboard-name {
            flex: 1;
            color: #111827;
        }

        .leaderboard-score {
            font-weight: 600;
            color: black;
        }

        .leaderboard-item.empty {
            justify-content: center;
            color: #6b7280;
            opacity: 0.7;
        }

        /* Modal styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.3);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            opacity: 0;
            pointer-events: none;
            transition: all 0.3s ease;
            backdrop-filter: blur(5px);
        }

        .modal-overlay.active {
            opacity: 1;
            pointer-events: auto;
        }

        .modal-content {
            background: var(--gradient-card);
            border-radius: 12px;
            padding: 30px;
            width: 90%;
            max-width: 500px;
            box-shadow: var(--shadow-strong);
            transform: translateY(20px);
            transition: all 0.3s ease;
            border: 1px solid var(--light-green);
        }

        .modal-overlay.active .modal-content {
            transform: translateY(0);
        }

        .completion-header {
            text-align: center;
            margin-bottom: 20px;
        }

        .completion-header h2 {
            font-size: 2rem;
            font-weight: 700;
            color: black;
            margin-bottom: 10px;
        }

        .star-rating {
            display: flex;
            justify-content: center;
            gap: 5px;
            font-size: 1.5rem;
            color: gold;
            margin-bottom: 20px;
        }

        .completion-stats {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
        }

        .modal-buttons {
            display: flex;
            justify-content: space-between;
            gap: 15px;
        }

        .modal-btn {
            flex: 1;
            padding: 12px;
            font-size: 1rem;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .modal-btn.primary-btn {
            background: var(--gradient-primary);
            color: var(--white);
        }

        .modal-btn.secondary-btn {
            background: var(--gradient-light);
            color: var(--dark-green);
            border: 1px solid var(--light-green);
        }

        .modal-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        /* Grid highlight effect */
        .cell:hover:not(.fixed) {
            background-color: #d1fae5;
            box-shadow: inset 0 0 0 1px black;
        }

        /* Animations */
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }
        }

        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(59, 130, 246, 0); }
            100% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0); }
        }

        /* Responsive design */
        @media (max-width: 1024px) {
            .game-container {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .sudoku-wrapper {
                margin-bottom: 20px;
                padding: 15px;
            }
            
            .control-panel {
                margin-bottom: 20px;
                padding: 20px;
            }

            .header-content {
                padding: 10px;
            }

            .header-right .user-info {
                margin-left: 0;
            }
        }

        @media (max-width: 768px) {
            .main-container {
                padding: 10px;
                margin: 15px auto;
            }

            .header-content {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }

            .header-left {
                flex-direction: column;
                gap: 10px;
            }

            .header-buttons {
                justify-content: center;
            }

            .level-info h1 {
                font-size: 1.2rem;
            }
            
            .sudoku-board {
                max-width: 100%;
                aspect-ratio: 1/1;
            }

            .cell {
                font-size: 1.2rem;
            }

            .game-stats {
                flex-wrap: wrap;
                gap: 10px;
            }

            .stat-item {
                flex: 1 1 calc(33.333% - 10px);
                min-width: 100px;
            }

            .numpad {
                gap: 8px;
            }
            
            .num-btn {
                font-size: 1.2rem;
                padding: 10px;
            }

            .control-btns {
                grid-template-columns: repeat(2, 1fr);
                gap: 8px;
            }
            
            .control-btn {
                font-size: 0.9rem;
                padding: 10px;
            }
            
            .control-btn span {
                display: none;
            }
            
            .modal-content {
                padding: 15px;
                width: 95%;
            }

            .completion-header h2 {
                font-size: 1.5rem;
            }

            .modal-buttons {
                flex-direction: column;
                gap: 10px;
            }

            .modal-btn {
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            .header {
                margin: 5px;
                padding: 10px 0;
            }

            .main-container {
                margin: 10px auto;
            }

            .sudoku-wrapper {
                padding: 10px;
            }

            .cell {
                font-size: 1rem;
            }

            .stat-item {
                flex: 1 1 100%;
            }

            .numpad {
                gap: 5px;
            }

            .num-btn {
                font-size: 1rem;
                padding: 8px;
            }

            .control-btn {
                font-size: 0.8rem;
                padding: 8px;
            }

            .panel-title {
                font-size: 1rem;
            }

            .timer {
                font-size: 1rem;
            }

            .modal-content {
                padding: 10px;
            }

            .completion-header h2 {
                font-size: 1.2rem;
            }

            .star-rating {
                font-size: 1.2rem;
            }
        }

        /* Add touch-friendly styles */
        @media (hover: none) {
            .num-btn, .control-btn {
                -webkit-tap-highlight-color: transparent;
            }

            .cell {
                cursor: default;
            }

            .cell:active {
                background-color: #d1fae5;
            }

            .num-btn:active, .control-btn:active {
                transform: scale(0.95);
            }
        }

        /* Success message */
        .success-message {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: var(--gradient-primary);
            color: var(--white);
            padding: 20px;
            border-radius: 8px;
            font-weight: 600;
            z-index: 1001;
            box-shadow: var(--shadow-strong);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .success-message.show {
            opacity: 1;
        }

        /* Add these styles in the CSS section */
        .badge {
            background: rgba(16, 185, 129, 0.1);
            color: var(--dark-green);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .badge-highlight {
            background: linear-gradient(45deg, rgba(255, 215, 0, 0.2), rgba(255, 193, 7, 0.2));
            color: #f57c00;
        }

        .control-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }

        /* Mobile Navigation */
        .mobile-nav {
            display: none;
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #fff;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
            padding: 10px;
            z-index: 1000;
        }

        .mobile-nav-buttons {
            display: flex;
            justify-content: space-around;
            align-items: center;
        }

        .mobile-nav-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-decoration: none;
            color: #666;
            font-size: 12px;
        }

        .mobile-nav-btn i {
            font-size: 24px;
            margin-bottom: 4px;
        }

        .mobile-nav-btn.active {
            color: #4CAF50;
        }

        /* Media Queries */
        @media screen and (max-width: 768px) {
            .container {
                padding: 10px;
                margin-bottom: 60px;
            }

            .mobile-nav {
                display: block;
            }

            .game-header {
                flex-direction: column;
                gap: 10px;
                padding: 10px;
            }

            .game-info {
                width: 100%;
            }

            .game-controls {
                width: 100%;
                flex-wrap: wrap;
                gap: 5px;
            }

            .game-controls button {
                flex: 1;
                min-width: 80px;
                font-size: 14px;
                padding: 8px;
            }

            .sudoku-grid {
                width: 100%;
                max-width: 100%;
                margin: 10px auto;
            }

            .grid-cell {
                width: calc(100% / 9);
                height: 35px;
                font-size: 16px;
            }

            .grid-cell.selected {
                font-size: 18px;
            }

            .number-pad {
                position: fixed;
                bottom: 70px;
                left: 0;
                right: 0;
                background: #fff;
                padding: 10px;
                box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
                display: grid;
                grid-template-columns: repeat(5, 1fr);
                gap: 5px;
                z-index: 999;
            }

            .number-pad button {
                width: 100%;
                height: 40px;
                font-size: 18px;
                border-radius: 5px;
            }

            .game-stats {
                flex-direction: column;
                gap: 5px;
                padding: 10px;
            }

            .stat-item {
                width: 100%;
                padding: 8px;
            }

            .modal-content {
                width: 90%;
                max-width: 90%;
                margin: 20px auto;
            }

            .modal-header {
                padding: 10px;
            }

            .modal-body {
                padding: 15px;
            }

            .modal-footer {
                padding: 10px;
            }
        }

        /* Mobile View Styles */
        @media screen and (max-width: 768px) {
            .container {
                padding: 10px;
                margin-bottom: 60px;
            }

            .game-header {
                flex-direction: column;
                gap: 10px;
                padding: 10px;
            }

            .game-stats {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
                width: 100%;
                margin-bottom: 10px;
            }

            .stat-box {
                padding: 8px;
                font-size: 14px;
            }

            .stat-box i {
                font-size: 16px;
            }

            .game-controls {
                flex-direction: column;
                gap: 10px;
                width: 100%;
            }

            .control-btn {
                width: 100%;
                padding: 12px;
            }

            .sudoku-grid {
                width: 100%;
                max-width: 100vw;
                margin: 10px auto;
            }

            .grid-cell {
                width: calc(100% / 9);
                height: calc((100vw - 20px) / 9);
                font-size: 18px;
            }

            .number-pad {
                position: fixed;
                bottom: 60px;
                left: 0;
                right: 0;
                background: #fff;
                padding: 10px;
                display: flex;
                justify-content: space-between;
                gap: 5px;
                box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
            }

            .number-btn {
                flex: 1;
                height: 40px;
                font-size: 18px;
                border-radius: 8px;
            }

            .mobile-nav {
                display: block;
            }

            /* Hide desktop number pad */
            .number-pad.desktop {
                display: none;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="header-content">
            <div class="header-left">
                <div class="header-buttons">
                    <a href="levels.php?difficulty=<?php echo $difficulty_id; ?>" class="back-btn" title="Back to Levels">
                        <i class="fas fa-arrow-left"></i>
                    </a>
                    <a href="home.php" class="back-btn" title="Home">
                        <i class="fas fa-home"></i>
                    </a>
                </div>
                <div class="level-info">
                    <h1><?php echo htmlspecialchars($difficulty_name); ?> - Level <?php echo htmlspecialchars($level_number); ?></h1>
                </div>
            </div>
            <div class="header-right">
                <button class="save-btn" id="header-save-btn" onclick="saveGame()">
                    <i class="fas fa-save"></i>
                    Save Game
                </button>
                <div class="user-info">
                    <div class="user-avatar">
                        <i class="fas fa-user"></i>
                    </div>
                    <span><?php echo htmlspecialchars($user_name); ?></span>
                </div>
            </div>
        </div>
    </header>

    <div class="main-container">
        <div class="game-container">
            <div class="sudoku-wrapper">
                <div class="sudoku-board" id="sudoku-board">
                    <!-- Sudoku cells will be generated by JavaScript -->
                </div>
            </div>

            <div class="game-controls">
                <!-- Timer and Stats Panel -->
                <div class="control-panel">
                    <div class="panel-header">
                        <div class="panel-title">
                            <i class="fas fa-clock"></i> Game Stats
                        </div>
                        <div class="timer" id="timer">00:00</div>
                    </div>
                    <div class="game-stats">
                        <div class="stat-item">
                            <div class="stat-value">
                                <i class="fas fa-times-circle"></i>
                                <span id="mistakes">0</span>/5
                            </div>
                            <div class="stat-label">Mistakes</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value">
                                <i class="fas fa-lightbulb"></i>
                                <span id="hints-used">0</span>/5
                            </div>
                            <div class="stat-label">Hints</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value">
                                <i class="fas fa-star"></i>
                                <span id="score">0</span>
                            </div>
                            <div class="stat-label">Score</div>
                        </div>
                    </div>
                </div>

                <!-- Number Input Panel -->
                <div class="control-panel">
                    <div class="panel-header">
                        <div class="panel-title">
                            <i class="fas fa-keyboard"></i> Numbers
                        </div>
                    </div>
                    <div class="numpad">
                        <?php for ($i = 1; $i <= $grid_size; $i++): ?>
                            <button class="num-btn" data-number="<?php echo $i; ?>"><?php echo $i; ?></button>
                        <?php endfor; ?>
                    </div>
                    <div class="control-btns">
                        <button class="control-btn" id="erase-btn">
                            <i class="fas fa-eraser"></i>
                            <span>Erase</span>
                        </button>
                        <button class="control-btn" id="hint-btn">
                            <i class="fas fa-lightbulb"></i>
                            <span>Hint</span>
                        </button>
                        <button class="control-btn" id="check-btn">
                            <i class="fas fa-check"></i>
                            <span>Check</span>
                        </button>
                        <button class="control-btn" id="notes-btn">
                            <i class="fas fa-pencil-alt"></i>
                            <span>Notes</span>
                        </button>
                        <button class="control-btn" id="restart-btn" onclick="restartLevel()">
                            <i class="fas fa-redo"></i>
                            <span>Restart</span>
                        </button>
                        <button class="control-btn" id="save-btn" onclick="saveGame()">
                            <i class="fas fa-save"></i>
                            <span>Save</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Completion Modal -->
    <div class="modal-overlay" id="completion-modal">
        <div class="modal-content">
            <div class="completion-header">
                <h2>Level Complete!</h2>
                <div class="star-rating" id="star-rating">
                    <!-- Stars will be added by JavaScript -->
                </div>
            </div>
            <div class="completion-stats">
                <div class="stat-item">
                    <div class="stat-value" id="final-time">--:--</div>
                    <div class="stat-label">Time</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value" id="final-score">---</div>
                    <div class="stat-label">Score</div>
                </div>
            </div>
            <div class="modal-buttons">
                <button class="modal-btn secondary-btn" onclick="restartLevel()">
                    <i class="fas fa-redo"></i> Try Again </button>
                <button class="modal-btn primary-btn" onclick="nextLevel()">
                    <i class="fas fa-arrow-right"></i> Next Level
                </button>
            </div>
        </div>
    </div>

    <!-- Restart Confirmation Modal -->
    <div class="modal-overlay" id="restart-modal">
        <div class="modal-content">
            <div class="completion-header">
                <h2>Restart Level?</h2>
                <p>Are you sure you want to restart? All progress will be lost.</p>
            </div>
            <div class="modal-buttons">
                <button class="modal-btn secondary-btn" onclick="closeRestartModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button class="modal-btn primary-btn" onclick="confirmRestart()">
                    <i class="fas fa-redo"></i> Restart
                </button>
            </div>
        </div>
    </div>

    <!-- Save Confirmation Modal -->
    <div class="modal-overlay" id="save-modal">
        <div class="modal-content">
            <div class="completion-header">
                <h2>Save Game?</h2>
                <p>Do you want to save your progress and exit?</p>
            </div>
            <div class="modal-buttons">
                <button class="modal-btn secondary-btn" onclick="closeSaveModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button class="modal-btn primary-btn" onclick="confirmSaveAndExit()">
                    <i class="fas fa-save"></i> Save & Exit
                </button>
            </div>
        </div>
    </div>

    <!-- Success Message -->
    <div class="success-message" id="success-message"></div>

    <!-- Add before closing body tag -->
    <div class="mobile-nav">
        <div class="mobile-nav-buttons">
            <a href="home.php" class="mobile-nav-btn">
                <i class="fas fa-home"></i>
                <span>Home</span>
            </a>
            <a href="levels.php" class="mobile-nav-btn">
                <i class="fas fa-layer-group"></i>
                <span>Levels</span>
            </a>
            <button class="mobile-nav-btn" onclick="toggleMobileSection('stats')">
                <i class="fas fa-chart-bar"></i>
                <span>Stats</span>
            </button>
            <button class="mobile-nav-btn" onclick="toggleMobileSection('leaderboard')">
                <i class="fas fa-crown"></i>
                <span>Leaderboard</span>
            </button>
            <button class="mobile-nav-btn" onclick="toggleMobileSection('achievements')">
                <i class="fas fa-trophy"></i>
                <span>Achievements</span>
            </button>
        </div>
    </div>

    <!-- Mobile Number Pad -->
    <div class="number-pad mobile">
        <?php for ($i = 1; $i <= $grid_size; $i++): ?>
            <button class="number-btn" onclick="selectNumber(<?php echo $i; ?>)"><?php echo $i; ?></button>
        <?php endfor; ?>
        <button class="number-btn clear" onclick="clearCell()">
            <i class="fas fa-eraser"></i>
        </button>
    </div>

    <script>
        // Game state variables
        let gameStarted = <?php echo json_encode($gameStarted); ?>;
        let startTime = null;
        let timerInterval = null;
        let selectedCell = null;
        let mistakes = <?php echo json_encode($mistakes); ?>;
        let hintsUsed = <?php echo json_encode($hintsUsed); ?>;
        let score = <?php echo json_encode($current_score); ?>;
        let gameCompleted = false;
        let maxMistakes = 5; // Maximum allowed mistakes
        let notesMode = false; // Track if we're in notes mode
        let timeLimit = <?php echo json_encode($time_limit); ?>; // Time limit in seconds
        let timeRemaining = <?php echo json_encode($time_limit); ?>; // Time remaining
        let solvedCells = new Set(); // Track which cells have been solved

        // Puzzle data from PHP
        const initialPuzzle = <?php echo json_encode($puzzle); ?>;
        const solution = <?php echo json_encode($solution); ?>;
        let currentPuzzle = JSON.parse(JSON.stringify(initialPuzzle));

        // Game difficulty and level
        const difficultyId = <?php echo json_encode($difficulty_id); ?>;
        const currentLevel = <?php echo json_encode($level_number); ?>;

        // Update game state variables
        let gridSize = <?php echo json_encode($grid_size); ?>;
        let maxNumber = gridSize;

        // Initialize the game
        document.addEventListener('DOMContentLoaded', function() {
            initializeBoard();
            setupEventListeners();
            updateUI();
            
            // Set the timer display immediately
            const minutes = Math.floor(timeRemaining / 60);
            const seconds = timeRemaining % 60;
            document.getElementById('timer').textContent = 
                `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
            
            // Start the timer if game is already started
            if (gameStarted) {
                startTimer();
            }
        });

        function initializeBoard() {
            const board = document.getElementById('sudoku-board');
            board.innerHTML = '';
            
            // Update grid template based on size
            board.style.gridTemplateColumns = `repeat(${gridSize}, 1fr)`;
            board.style.gridTemplateRows = `repeat(${gridSize}, 1fr)`;

            // Determine box size for grouping
            let boxRows, boxCols;
            if (gridSize === 4) {
                boxRows = 2; boxCols = 2;
            } else if (gridSize === 6) {
                boxRows = 2; boxCols = 3;
            } else if (gridSize === 9) {
                boxRows = 3; boxCols = 3;
            } else {
                boxRows = Math.sqrt(gridSize); boxCols = Math.sqrt(gridSize);
            }

            let cellCount = 0;
            for (let row = 0; row < gridSize; row++) {
                for (let col = 0; col < gridSize; col++) {
                    const cell = document.createElement('div');
                    cell.className = 'cell';
                    cell.dataset.row = row;
                    cell.dataset.col = col;
                    // Assign box index for styling
                    cell.dataset.box =
                        Math.floor(row / boxRows) * (gridSize / boxCols) + Math.floor(col / boxCols);

                    const value = initialPuzzle[row][col];
                    if (value !== 0) {
                        cell.textContent = value;
                        cell.classList.add('fixed');
                    } else {
                        cell.addEventListener('click', selectCell);
                    }

                    board.appendChild(cell);
                    cellCount++;
                }
            }
            
            // Verify correct number of cells
            console.log(`Grid Size: ${gridSize}x${gridSize}, Total Cells: ${cellCount}, Expected: ${gridSize * gridSize}`);
            
            // Validate cell count
            if (cellCount !== gridSize * gridSize) {
                console.error(`Cell count mismatch! Expected ${gridSize * gridSize}, got ${cellCount}`);
            }
        }

        function setupEventListeners() {
            // Number buttons
            document.querySelectorAll('.num-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const number = parseInt(this.dataset.number);
                    
                    // Remove previous number highlights
                    document.querySelectorAll('.cell').forEach(cell => {
                        cell.classList.remove('same-number');
                    });
                    
                    // Highlight cells with the same number
                    document.querySelectorAll('.cell').forEach(cell => {
                        if (getCurrentCellValue(cell) === number) {
                            cell.classList.add('same-number');
                        }
                    });
                    
                    if (selectedCell && !selectedCell.classList.contains('fixed')) {
                        if (notesMode) {
                            toggleNote(selectedCell, number);
                        } else {
                            placeNumber(selectedCell, number);
                        }
                    }
                });
            });

            // Control buttons
            document.getElementById('erase-btn').addEventListener('click', eraseCell);
            document.getElementById('hint-btn').addEventListener('click', giveHint);
            document.getElementById('check-btn').addEventListener('click', checkSolution);
            document.getElementById('notes-btn').addEventListener('click', toggleNotesMode);

            // Keyboard input
            document.addEventListener('keydown', handleKeyboard);

            // Add home button event listener
            document.getElementById('home-btn').addEventListener('click', goHome);
        }

        function selectCell(event) {
            if (gameCompleted) return;

            // Remove previous selection
            document.querySelectorAll('.cell').forEach(cell => {
                cell.classList.remove('selected', 'same-row', 'same-col', 'same-box', 'same-number');
            });

            selectedCell = event.target;
            selectedCell.classList.add('selected');

            // Start timer on first cell selection
            if (!gameStarted) {
                startGame();
            }
        }

        function startGame() {
            gameStarted = true;
            startTime = Date.now();
            timerInterval = setInterval(updateTimer, 1000);
        }

        function updateTimer() {
            // Only decrement if the game is started and not completed
            if (!gameStarted || gameCompleted) return;
            timeRemaining--;
            if (timeRemaining <= 0) {
                timeRemaining = 0;
                clearInterval(timerInterval);
                gameOver("Time's up!");
            }
            const minutes = Math.floor(timeRemaining / 60);
            const seconds = timeRemaining % 60;
            document.getElementById('timer').textContent = 
                `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
        }

        function placeNumber(cell, number) {
            const row = parseInt(cell.dataset.row);
            const col = parseInt(cell.dataset.col);
            const cellKey = `${row}-${col}`;
            
            // Check for repetitions in row, column, and box
            if (hasRepetition(row, col, number)) {
                cell.classList.add('error');
                mistakes++;
                
                // Check if mistakes limit reached
                if (mistakes >= 5) {
                    gameOver('Too many mistakes!');
                    return;
                }
                
                score = Math.max(0, score - 50); // Deduct 50 points for each mistake
                updateUI();
                
                // Remove error class after animation
                setTimeout(() => {
                    cell.classList.remove('error');
                }, 500);
                return;
            }
            
            // Update visual
            cell.textContent = number;
            currentPuzzle[row][col] = number;

            // Check if placement is correct
            if (solution[row][col] === number) {
                cell.classList.remove('error');
                
                // Only award points if this cell hasn't been solved before
                if (!solvedCells.has(cellKey)) {
                    score += 50; // Add 50 points for each correct number
                    solvedCells.add(cellKey); // Mark this cell as solved
                    updateUI();
                }
                
                // Check if puzzle is complete
                if (isPuzzleComplete()) {
                    completeGame();
                }
            } else {
                // Wrong placement
                cell.classList.add('error');
                mistakes++;
                
                // Check if mistakes limit reached
                if (mistakes >= 5) {
                    gameOver('Too many mistakes!');
                    return;
                }
                
                score = Math.max(0, score - 50);
                updateUI();
                
                // Remove error class after animation
                setTimeout(() => {
                    cell.classList.remove('error');
                }, 500);
            }
        }

        function hasRepetition(row, col, number) {
            const boxSize = Math.sqrt(gridSize);
            
            // Check row
            for (let c = 0; c < gridSize; c++) {
                if (c !== col && currentPuzzle[row][c] === number) {
                    return true;
                }
            }

            // Check column
            for (let r = 0; r < gridSize; r++) {
                if (r !== row && currentPuzzle[r][col] === number) {
                    return true;
                }
            }

            // Check box
            const boxRow = Math.floor(row / boxSize) * boxSize;
            const boxCol = Math.floor(col / boxSize) * boxSize;
            for (let r = boxRow; r < boxRow + boxSize; r++) {
                for (let c = boxCol; c < boxCol + boxSize; c++) {
                    if (r !== row && c !== col && currentPuzzle[r][c] === number) {
                        return true;
                    }
                }
            }

            return false;
        }

        function eraseCell() {
            if (selectedCell && !selectedCell.classList.contains('fixed')) {
                const row = parseInt(selectedCell.dataset.row);
                const col = parseInt(selectedCell.dataset.col);
                
                selectedCell.textContent = '';
                currentPuzzle[row][col] = 0;
                selectedCell.classList.remove('error');
                
                // Clear notes if they exist
                if (selectedCell.notes) {
                    selectedCell.notes.clear();
                    updateNotesDisplay(selectedCell);
                }
            }
        }

        function giveHint() {
            if (!selectedCell || selectedCell.classList.contains('fixed')) {
                return;
            }

            // Check if hints are available
            if (hintsUsed >= 5) {
                showMessage('No hints remaining!', 'error');
                return;
            }

            const row = parseInt(selectedCell.dataset.row);
            const col = parseInt(selectedCell.dataset.col);
            const cellKey = `${row}-${col}`;
            const correctNumber = solution[row][col];

            selectedCell.textContent = correctNumber;
            currentPuzzle[row][col] = correctNumber;
            selectedCell.classList.add('hint');

            // Only add to solved cells if it wasn't solved before
            if (!solvedCells.has(cellKey)) {
                solvedCells.add(cellKey);
            }

            hintsUsed++;
            updateUI();

            // Update hint button state
            const hintBtn = document.getElementById('hint-btn');
            if (hintsUsed >= 5) {
                hintBtn.classList.add('disabled');
                hintBtn.style.opacity = '0.5';
                hintBtn.style.cursor = 'not-allowed';
            }

            // Remove hint class after animation
            setTimeout(() => {
                selectedCell.classList.remove('hint');
            }, 1000);

            if (isPuzzleComplete()) {
                completeGame();
            }
        }

        function checkSolution() {
            let hasErrors = false;
            
            document.querySelectorAll('.cell').forEach(cell => {
                if (!cell.classList.contains('fixed')) {
                    const row = parseInt(cell.dataset.row);
                    const col = parseInt(cell.dataset.col);
                    const currentValue = currentPuzzle[row][col];
                    
                    if (currentValue !== 0 && currentValue !== solution[row][col]) {
                        cell.classList.add('error');
                        hasErrors = true;
                        setTimeout(() => {
                            cell.classList.remove('error');
                        }, 2000);
                    }
                }
            });

            if (hasErrors) {
                showMessage('Some numbers are incorrect!', 'error');
            } else {
                showMessage('Looking good so far!', 'success');
            }
        }

        function isPuzzleComplete() {
            for (let row = 0; row < gridSize; row++) {
                for (let col = 0; col < gridSize; col++) {
                    if (currentPuzzle[row][col] === 0 || currentPuzzle[row][col] !== solution[row][col]) {
                        return false;
                    }
                }
            }
            return true;
        }

        function completeGame() {
            gameCompleted = true;
            clearInterval(timerInterval);
            
            const finalTime = Math.floor((Date.now() - startTime) / 1000);
            
            // Calculate final score
            const timeBonus = Math.max(0, 500 - finalTime); // Time bonus up to 500 points
            const mistakesPenalty = mistakes * 20; // Reduced penalty for mistakes
            const finalScore = Math.max(0, score + timeBonus - mistakesPenalty);

            // Update modal
            document.getElementById('final-time').textContent = formatTime(finalTime);
            document.getElementById('final-score').textContent = finalScore;
            
            // Add stars based on performance
            const stars = calculateStars(finalScore, mistakes, hintsUsed);
            displayStars(stars);
            
            // Save score
            saveScore(finalTime, finalScore);
            
            // Show completion modal
            document.getElementById('completion-modal').classList.add('active');
        }

        function calculateStars(score, mistakes, hints) {
            // 3 Stars: Perfect game (no mistakes, no hints) with good score
            if (mistakes === 0 && hints === 0 && score >= 1000) {
                return 3;
            }
            
            // 2 Stars: Good game (1-2 mistakes or 1-2 hints) with decent score
            if ((mistakes <= 2 || hints <= 2) && score >= 600) {
                return 2;
            }
            
            // 1 Star: Completed the game
            return 1;
        }

        function displayStars(count) {
            const starRating = document.getElementById('star-rating');
            starRating.innerHTML = '';
            
            // Add star rating explanation
            const explanation = document.createElement('div');
            explanation.className = 'star-explanation';
            explanation.style.textAlign = 'center';
            explanation.style.marginTop = '10px';
            explanation.style.color = '#666';
            
            switch(count) {
                case 3:
                    explanation.textContent = 'Perfect! No mistakes and no hints used.';
                    break;
                case 2:
                    explanation.textContent = 'Good! Few mistakes or hints used.';
                    break;
                case 1:
                    explanation.textContent = 'Completed! Keep practicing to improve.';
                    break;
            }
            
            // Add stars
            for (let i = 0; i < 3; i++) {
                const star = document.createElement('i');
                star.className = i < count ? 'fas fa-star' : 'far fa-star';
                star.style.color = i < count ? '#FFD700' : '#ccc';
                starRating.appendChild(star);
            }
            
            starRating.appendChild(explanation);
        }

        function saveScore(time, score) {
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `save_score=1&time_taken=${time}&score=${score}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update best scores display if this is a new record
                    updateBestScoresDisplay(time, score);
                    
                    // Redirect to levels page after a short delay
                    setTimeout(() => {
                        window.location.href = `levels.php?difficulty=${difficultyId}`;
                    }, 3000);
                }
            })
            .catch(error => console.error('Error saving score:', error));
        }

        function updateBestScoresDisplay(time, score) {
            const currentBestTimeText = document.getElementById('best-time-display').textContent;
            const currentBestScore = parseInt(document.getElementById('best-score-display').textContent) || 0;
            
            // Update best time if this is better
            if (currentBestTimeText === '--:--' || time < parseTime(currentBestTimeText)) {
                document.getElementById('best-time-display').textContent = formatTime(time);
            }
            
            // Update best score if this is better
            if (score > currentBestScore) {
                document.getElementById('best-score-display').textContent = score;
            }
        }

        function handleKeyboard(event) {
            if (gameCompleted) return;

            const key = event.key.toLowerCase();
            const maxNumber = gridSize;
            
            if (key >= '1' && key <= maxNumber.toString()) {
                const number = parseInt(key);
                
                // Remove previous number highlights
                document.querySelectorAll('.cell').forEach(cell => {
                    cell.classList.remove('same-number');
                });
                
                // Highlight cells with the same number
                document.querySelectorAll('.cell').forEach(cell => {
                    if (getCurrentCellValue(cell) === number) {
                        cell.classList.add('same-number');
                    }
                });
                
                if (selectedCell && !selectedCell.classList.contains('fixed')) {
                    if (notesMode) {
                        toggleNote(selectedCell, number);
                    } else {
                        placeNumber(selectedCell, number);
                    }
                }
            } else if (key === 'delete' || key === 'backspace') {
                eraseCell();
            } else if (key === 'n') {
                toggleNotesMode();
            } else if (key === 'h') {
                giveHint();
            } else if (key === 'c') {
                checkSolution();
            } else if (key === 'e') {
                eraseCell();
            }
        }

        function getCurrentCellValue(cell) {
            const text = cell.textContent.trim();
            return text && !isNaN(text) ? parseInt(text) : 0;
        }

        function formatTime(seconds) {
            const minutes = Math.floor(seconds / 60);
            const secs = seconds % 60;
            return `${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
        }

        function parseTime(timeString) {
            const [minutes, seconds] = timeString.split(':').map(Number);
            return minutes * 60 + seconds;
        }

        function updateUI() {
            document.getElementById('mistakes').textContent = mistakes;
            document.getElementById('hints-used').textContent = hintsUsed;
            document.getElementById('score').textContent = score;

            // Update hint button state
            const hintBtn = document.getElementById('hint-btn');
            if (hintsUsed >= 5) {
                hintBtn.classList.add('disabled');
                hintBtn.style.opacity = '0.5';
                hintBtn.style.cursor = 'not-allowed';
            } else {
                hintBtn.classList.remove('disabled');
                hintBtn.style.opacity = '1';
                hintBtn.style.cursor = 'pointer';
            }
        }

        function showMessage(text, type = 'success') {
            const message = document.getElementById('success-message');
            message.textContent = text;
            message.className = `success-message ${type}`;
            message.classList.add('show');
            
            setTimeout(() => {
                message.classList.remove('show');
            }, 2000);
        }

        function restartLevel() {
            document.getElementById('restart-modal').classList.add('active');
        }

        function closeRestartModal() {
            document.getElementById('restart-modal').classList.remove('active');
        }

        function confirmRestart() {
            // Clear solved cells tracking
            solvedCells.clear();
            location.reload();
        }

        function nextLevel() {
            const nextLevelNum = currentLevel + 1;
            window.location.href = `game.php?difficulty=${difficultyId}&level=${nextLevelNum}`;
        }

        function toggleNotesMode() {
            notesMode = !notesMode;
            const notesBtn = document.getElementById('notes-btn');
            notesBtn.classList.toggle('selected');
            showMessage(notesMode ? 'Notes Mode: ON' : 'Notes Mode: OFF');
        }

        function toggleNote(cell, number) {
            if (!cell.notes) {
                cell.notes = new Set();
            }
            
            if (cell.notes.has(number)) {
                cell.notes.delete(number);
            } else {
                cell.notes.add(number);
            }
            
            updateNotesDisplay(cell);
        }

        function updateNotesDisplay(cell) {
            // Clear existing notes
            cell.innerHTML = '';
            
            if (cell.notes && cell.notes.size > 0) {
                const notesGrid = document.createElement('div');
                notesGrid.className = 'notes-grid';
                
                // Create 3x3 grid for notes
                for (let i = 1; i <= 9; i++) {
                    const note = document.createElement('div');
                    note.className = 'note';
                    if (cell.notes.has(i)) {
                        note.textContent = i;
                    }
                    notesGrid.appendChild(note);
                }
                
                cell.appendChild(notesGrid);
            }
        }

        function gameOver(reason = 'Too many mistakes!') {
            gameCompleted = true;
            clearInterval(timerInterval);
            
            // Show game over modal
            const modal = document.getElementById('completion-modal');
            const modalContent = modal.querySelector('.modal-content');
            
            modalContent.innerHTML = `
                <div class="completion-header">
                    <h2>Game Over!</h2>
                    <p>${reason}</p>
                </div>
                <div class="completion-stats">
                    <div class="stat-item">
                        <div class="stat-value">${mistakes}</div>
                        <div class="stat-label">Mistakes</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value">${score}</div>
                        <div class="stat-label">Final Score</div>
                    </div>
                </div>
                <div class="modal-buttons">
                    <button class="modal-btn secondary-btn" onclick="restartLevel()">
                        <i class="fas fa-redo"></i> Try Again
                    </button>
                    <button class="modal-btn primary-btn" onclick="window.location.href='levels.php?difficulty=${difficultyId}'">
                        <i class="fas fa-home"></i> Back to Levels
                    </button>
                </div>
            `;
            
            modal.classList.add('active');
        }

        // Close modals when clicking outside
        document.querySelectorAll('.modal-overlay').forEach(modal => {
            modal.addEventListener('click', function(event) {
                if (event.target === this) {
                    this.classList.remove('active');
                }
            });
        });

        // Add tooltips to show keyboard shortcuts
        document.addEventListener('DOMContentLoaded', function() {
            // Add tooltips to control buttons
            document.getElementById('hint-btn').title = 'Hint (H)';
            document.getElementById('notes-btn').title = 'Notes (N)';
            document.getElementById('check-btn').title = 'Check (C)';
            document.getElementById('erase-btn').title = 'Erase (E)';
        });

        // Add this function to handle home button click
        function goHome() {
            // Save current progress before redirecting
            saveGame(); // Call saveGame to ensure data is saved to DB
            // Redirect to home.php after a short delay to allow save to complete
            setTimeout(() => {
                window.location.href = 'home.php';
            }, 500); // Small delay
        }

        function saveProgress() {
            // This function seems to be for localStorage saving, which is deprecated by current approach
            // The saveGame() function now handles DB saving.
            console.warn("saveProgress() is deprecated. Use saveGame() instead.");
        }

        function updateBoardFromSave() {
            // This function is likely no longer needed as PHP directly populates currentPuzzle
            console.warn("updateBoardFromSave() is likely deprecated.");
            document.querySelectorAll('.cell').forEach(cell => {
                const row = parseInt(cell.dataset.row);
                const col = parseInt(cell.dataset.col);
                if (!cell.classList.contains('fixed')) {
                    cell.textContent = currentPuzzle[row][col] || '';
                }
            });
            updateUI();
        }

        function saveGame() {
            const data = {
                action: 'save_game',
                level_id: gameState.levelId,
                difficulty_id: gameState.difficultyId,
                game_state: {
                    puzzle: currentPuzzle, // Use currentPuzzle from JS
                    score: score,
                    mistakes: mistakes,
                    hints: hintsUsed
                },
                time_remaining: timeRemaining,
                current_score: score,
                mistakes_made: mistakes,
                hints_used: hintsUsed
            };

            fetch('../api/save_game.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(data)
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    showMessage('Game saved successfully!', 'success');
                } else {
                    showMessage('Failed to save game: ' + result.message, 'error');
                }
            })
            .catch(error => {
                showMessage('Error saving game: ' + error.message, 'error');
            });
        }

        function closeSaveModal() {
            document.getElementById('save-modal').classList.remove('active');
        }

        function confirmSaveAndExit() {
            const saveButton = document.getElementById('save-btn');
            saveButton.disabled = true;
            saveButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

            // Use the same data structure as saveGame() for consistency
            const dataToSave = {
                action: 'save_game',
                level_id: gameState.levelId,
                difficulty_id: gameState.difficultyId,
                game_state: {
                    puzzle: currentPuzzle,
                    score: score,
                    mistakes: mistakes,
                    hints: hintsUsed
                },
                time_remaining: timeRemaining,
                current_score: score,
                mistakes_made: mistakes,
                hints_used: hintsUsed
            };

            fetch('../api/save_game.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(dataToSave)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showMessage('Game saved successfully!', 'success');
                    // Redirect to home.php after successful save
                    window.location.href = 'home.php';
                } else {
                    showMessage('Error saving game: ' + data.message, 'error');
                    saveButton.disabled = false;
                    saveButton.innerHTML = '<i class="fas fa-save"></i><span>Save</span>';
                }
            })
            .catch(error => {
                console.error('Save error:', error);
                showMessage('Error saving game. Please try again.', 'error');
                saveButton.disabled = false;
                saveButton.innerHTML = '<i class="fas fa-save"></i><span>Save</span>';
            });
        }

        // Add auto-save functionality
        let autoSaveInterval = setInterval(saveGame, 300000); // Auto-save every 5 minutes

        // Clean up auto-save on page unload
        window.addEventListener('beforeunload', function() {
            clearInterval(autoSaveInterval);
            saveGame(); // Save one last time before leaving
        });

        // Add to existing JavaScript
        function handleNumberInput(number) {
            if (selectedCell) {
                if (number === 0) {
                    selectedCell.textContent = '';
                    selectedCell.classList.remove('error');
                } else {
                    selectedCell.textContent = number;
                    checkCell(selectedCell);
                }
                updateGameState();
            }
        }

        // Update cell click handler
        document.querySelectorAll('.grid-cell').forEach(cell => {
            cell.addEventListener('click', function() {
                if (!this.classList.contains('fixed')) {
                    document.querySelectorAll('.grid-cell').forEach(c => c.classList.remove('selected'));
                    this.classList.add('selected');
                    selectedCell = this;
                }
            });
        });

        function toggleMobileSection(section) {
            const sections = ['stats', 'leaderboard', 'achievements'];
            sections.forEach(s => {
                const element = document.querySelector(`.mobile-${s}`);
                if (s === section) {
                    element.classList.toggle('active');
                } else {
                    element.classList.remove('active');
                }
            });
        }

        // Update the selectNumber function to work with mobile number pad
        function selectNumber(num) {
            if (selectedCell) {
                const row = selectedCell.dataset.row;
                const col = selectedCell.dataset.col;
                if (isValidMove(row, col, num)) {
                    selectedCell.textContent = num;
                    selectedCell.classList.add('user-input');
                    updateGameState();
                }
            }
        }

        // Update the clearCell function to work with mobile number pad
        function clearCell() {
            if (selectedCell && !selectedCell.classList.contains('fixed')) {
                selectedCell.textContent = '';
                selectedCell.classList.remove('user-input');
                updateGameState();
            }
        }

        function startTimer() {
            if (timerInterval) clearInterval(timerInterval);
            timerInterval = setInterval(updateTimer, 1000);
        }
    </script>
</body>
</html>
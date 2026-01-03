<?php
// Initialize session
session_start();
require_once __DIR__ . '/config/db_connect.php';

// Simple user setup (no database dependency for user)
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $user_name = $_SESSION['user_name'] ?? 'User';
} else {
    // Redirect to login if no user session
    header('Location: index.php');
    exit();
}

// Define difficulty names and classes (based on your DB structure)
$difficulty_names = [
    1 => 'Easy',
    2 => 'Medium', 
    3 => 'Hard',
    4 => 'Expert'
];

$difficulty_classes = [
    1 => ['icon' => 'fas fa-leaf', 'color' => '#28a745'],
    2 => ['icon' => 'fas fa-fire', 'color' => '#ffc107'],
    3 => ['icon' => 'fas fa-bolt', 'color' => '#fd7e14'],
    4 => ['icon' => 'fas fa-crown', 'color' => '#dc3545']
];

// Get max levels from difficulties table
$max_levels = [];
try {
    $stmt = $pdo->prepare("SELECT id, max_level FROM difficulties ORDER BY id");
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $max_levels[$row['id']] = $row['max_level'];
    }
} catch (PDOException $e) {
    error_log("Database error getting max levels: " . $e->getMessage());
    // Fallback values
    $max_levels = [1 => 30, 2 => 50, 3 => 100, 4 => 200];
}

// Initialize progress variables
$total_completed = 0;
$total_stars = 0;
$total_possible_levels = array_sum($max_levels);
$total_possible_stars = $total_possible_levels * 3;
$modes_played = 0;

// Get difficulty statistics
$difficulty_stats = [];
try {
    foreach ([1, 2, 3, 4] as $difficulty_id) {
        // Initialize default values
        $difficulty_stats[$difficulty_id] = [
            'name' => $difficulty_names[$difficulty_id],
            'completed' => 0,
            'max_levels' => $max_levels[$difficulty_id],
            'perfect_games' => 0,
            'avg_score' => 0,
            'best_time' => null,
            'speed_games' => 0,
            'stars' => 0,
            'max_stars' => $max_levels[$difficulty_id] * 3,
            'icon' => $difficulty_classes[$difficulty_id]['icon'],
            'color' => $difficulty_classes[$difficulty_id]['color']
        ];

        // Get completed levels and statistics for this difficulty
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(DISTINCT up.level_id) as completed_levels,
                COALESCE(AVG(up.best_score), 0) as avg_score,
                MIN(up.best_time) as best_time,
                COUNT(DISTINCT CASE WHEN up.best_time IS NOT NULL AND up.best_time <= 120 THEN up.level_id END) as speed_games,
                SUM(CASE 
                    WHEN up.best_score >= 1000 THEN 3
                    WHEN up.best_score >= 600 THEN 2
                    ELSE 1
                END) as total_stars
            FROM user_progress up
            JOIN levels l ON up.level_id = l.id
            WHERE up.user_id = ? AND l.difficulty_id = ? AND up.completed = 1
        ");
        $stmt->execute([$user_id, $difficulty_id]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($stats && $stats['completed_levels'] > 0) {
            $difficulty_stats[$difficulty_id]['completed'] = (int)$stats['completed_levels'];
            $difficulty_stats[$difficulty_id]['avg_score'] = (int)$stats['avg_score'];
            $difficulty_stats[$difficulty_id]['best_time'] = $stats['best_time'];
            $difficulty_stats[$difficulty_id]['speed_games'] = (int)$stats['speed_games'];
            $difficulty_stats[$difficulty_id]['stars'] = (int)$stats['total_stars'];
            
            $total_completed += (int)$stats['completed_levels'];
            $total_stars += (int)$stats['total_stars'];
            $modes_played++;
        }

        // Get perfect games count (from recent_games table where mistakes = 0)
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT rg.level_id) as perfect_games
            FROM recent_games rg
            JOIN levels l ON rg.level_id = l.id
            WHERE rg.user_id = ? AND l.difficulty_id = ? AND rg.mistakes = 0 AND rg.completed = 1
        ");
        $stmt->execute([$user_id, $difficulty_id]);
        $perfect_result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($perfect_result) {
            $difficulty_stats[$difficulty_id]['perfect_games'] = (int)$perfect_result['perfect_games'];
        }
    }
} catch (PDOException $e) {
    error_log("Database error in difficulty stats: " . $e->getMessage());
    // Keep default values on error
}

$total_progress_percentage = $total_possible_levels > 0 ? ($total_completed / $total_possible_levels) * 100 : 0;

// Get user achievements with proper badge display
$achievements = [];
try {
    $stmt = $pdo->prepare("
        SELECT a.*, ua.earned_at,
        CASE 
            WHEN a.requirement_type = 'levels_completed' THEN 
                (SELECT COUNT(*) FROM user_progress WHERE user_id = ? AND completed = TRUE) >= a.requirement_value
            WHEN a.requirement_type = 'perfect_games' THEN 
                (SELECT COUNT(*) FROM recent_games WHERE user_id = ? AND mistakes = 0 AND completed = TRUE) >= a.requirement_value
            WHEN a.requirement_type = 'total_score' THEN 
                (SELECT COALESCE(SUM(score), 0) FROM leaderboard WHERE user_id = ?) >= a.requirement_value
            WHEN a.requirement_type = 'time_bonus' THEN 
                (SELECT COUNT(*) FROM recent_games WHERE user_id = ? AND time_taken <= a.requirement_value AND completed = TRUE) >= 1
            ELSE FALSE
        END as is_achieved
        FROM achievements a
        LEFT JOIN user_achievements ua ON a.id = ua.achievement_id AND ua.user_id = ?
        ORDER BY a.id
    ");
    $stmt->execute([$user_id, $user_id, $user_id, $user_id, $user_id]);
    $achievements = $stmt->fetchAll();
} catch (PDOException $e) {
    // Handle error silently
}

// Update the recent games query to include both saved and recent games
$recent_games = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            rg.*,
            COALESCE(sg.level_number, l.level_number) as level_number,
            COALESCE(d.name, (SELECT name FROM difficulties WHERE id = sg.difficulty_id)) as difficulty_name,
            sg.game_state,
            sg.time_remaining,
            CASE 
                WHEN rg.mistakes = 0 THEN 'Perfect'
                WHEN rg.mistakes <= 2 THEN 'Good'
                ELSE 'Normal'
            END as performance
        FROM recent_games rg
        JOIN levels l ON rg.level_id = l.id
        JOIN difficulties d ON l.difficulty_id = d.id
        LEFT JOIN saved_games sg ON rg.user_id = sg.user_id AND rg.level_id = sg.level_id
        WHERE rg.user_id = ? AND rg.completed = FALSE
        ORDER BY rg.played_at DESC
        LIMIT 5
    ");
    $stmt->execute([$user_id]);
    $recent_games = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching recent games: " . $e->getMessage());
}

// Get leaderboard data
$leaderboard = [];
try {
    $stmt = $pdo->prepare("
        SELECT u.username, SUM(l.score) as total_score, COUNT(DISTINCT l.level_id) as levels_completed
        FROM leaderboard l
        JOIN users u ON l.user_id = u.id
        GROUP BY u.id
        ORDER BY total_score DESC
        LIMIT 10
    ");
    $stmt->execute();
    $leaderboard = $stmt->fetchAll();
} catch (PDOException $e) {
    // Handle error silently
}

// Get user's saved games
$saved_games = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            sg.*,
            l.level_number,
            d.name as difficulty_name
        FROM saved_games sg
        JOIN levels l ON sg.level_id = l.id
        JOIN difficulties d ON l.difficulty_id = d.id
        WHERE sg.user_id = ?
        ORDER BY sg.saved_at DESC
        LIMIT 5
    ");
    $stmt->execute([$user_id]);
    $saved_games = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching saved games: " . $e->getMessage());
}

// Get user's recent progress
$recent_progress = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            up.*,
            l.level_number,
            d.name as difficulty_name
        FROM user_progress up
        JOIN levels l ON up.level_id = l.id
        JOIN difficulties d ON l.difficulty_id = d.id
        WHERE up.user_id = ?
        ORDER BY up.last_played DESC
        LIMIT 5
    ");
    $stmt->execute([$user_id]);
    $recent_progress = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching recent progress: " . $e->getMessage());
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sudoku Game - Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    
    <style>
     :root {
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

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: linear-gradient(135deg,rgb(1, 11, 9) 0%,rgb(42, 116, 106) 50%,rgb(1, 7, 6) 100%);
    min-height: 100vh;
    position: relative;
    overflow-x: hidden;
}



@keyframes gradientShift {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.8; }
}

@keyframes slideInUp {
    from {
        opacity: 0;
        transform: translateY(50px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes fadeInScale {
    from {
        opacity: 0;
        transform: scale(0.9);
    }
    to {
        opacity: 1;
        transform: scale(1);
    }
}

@keyframes iconBounce {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-5px); }
}

@keyframes glowPulse {
    0%, 100% { box-shadow: var(--shadow-soft); }
    50% { box-shadow: var(--glow); }
}

/* Header */
header {
    background: var(--gradient-primary);
    padding: 1rem 0;
    box-shadow: var(--shadow-strong);
    position: relative;
    overflow: hidden;
}

header::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
    animation: shimmer 3s infinite;
}

@keyframes shimmer {
    0% { left: -100%; }
    100% { left: 100%; }
}

.container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 1rem;
    margin-top: 2rem;
}

.header-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    color: var(--white);
}

.logo-section {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.logo-icon {
    background: rgba(255, 255, 255, 0.2);
    padding: 0.8rem;
    border-radius: 50%;
    backdrop-filter: blur(10px);
    animation: iconBounce 3s ease-in-out infinite;
}

.logo-icon i {
    font-size: 1.8rem;
    color: var(--white);
}

.logo-info h1 {
    font-size: 1.8rem;
    font-weight: 700;
    margin-bottom: 0.2rem;
}

.logo-info p {
    opacity: 0.9;
    font-size: 0.9rem;
}

.header-right {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.user-info {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    background: rgba(255, 255, 255, 0.1);
    padding: 0.5rem 1rem;
    border-radius: 2rem;
    backdrop-filter: blur(10px);
}

.user-avatar {
    background: var(--white);
    color: var(--primary-green);
    width: 2rem;
    height: 2rem;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.logout-btn {
    background: rgba(255, 255, 255, 0.2);
    color: var(--white);
    padding: 0.5rem 1rem;
    border-radius: 2rem;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.3s ease;
    backdrop-filter: blur(10px);
}

.logout-btn:hover {
    background: rgba(255, 255, 255, 0.3);
    transform: translateY(-2px);
}

/* Cards */
.card {
    background: var(--gradient-card);
    border-radius: 1rem;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: var(--shadow-soft);
    backdrop-filter: blur(20px);
    border: 1px solid rgba(255, 255, 255, 0.2);
    animation: slideInUp 0.6s ease-out;
    transition: all 0.3s ease;
}

.card:hover {
    transform: translateY(-5px);
    box-shadow: var(--shadow-strong);
}

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid var(--light-green);
}

.card-header h2 {
    color: var(--dark-green);
    font-size: 1.4rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.card-header h2 i {
    animation: iconBounce 2s ease-in-out infinite;
}

/* Progress Section */
.progress-container {
    margin-bottom: 1.5rem;
}

.progress-bar {
    width: 100%;
    height: 0.8rem;
    background: var(--light-green);
    border-radius: 2rem;
    overflow: hidden;
    position: relative;
}

.progress-fill {
    height: 100%;
    background: var(--gradient-primary);
    border-radius: 2rem;
    transition: width 1.5s ease-in-out;
    position: relative;
    overflow: hidden;
}

.progress-fill::after {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
    animation: progressShimmer 2s infinite;
}

@keyframes progressShimmer {
    0% { left: -100%; }
    100% { left: 100%; }
}

.progress-text {
    display: flex;
    justify-content: space-between;
    margin-top: 0.5rem;
    font-size: 0.9rem;
    color: var(--dark-green);
    font-weight: 500;
}

.progress-overview {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 1rem;
}

.stat-card {
    background: rgba(255, 255, 255, 0.7);
    padding: 1rem;
    border-radius: 0.8rem;
    text-align: center;
    border: 1px solid var(--light-green);
    transition: all 0.3s ease;
}

.stat-card:hover {
    transform: translateY(-3px);
    box-shadow: var(--glow);
}

.stat-number {
    font-size: 1.8rem;
    font-weight: 700;
    color: var(--primary-green);
    margin-bottom: 0.3rem;
}

.stat-label {
    color: var(--dark-green);
    font-size: 0.8rem;
}

/* Game Modes */
.game-modes {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1.5rem;
    overflow-x: auto;
    padding-bottom: 1rem;
}

.mode-card {
    background: var(--gradient-card);
    border-radius: 1rem;
    padding: 1.5rem;
    text-align: center;
    transition: all 0.3s ease;
    border: 2px solid transparent;
    position: relative;
    overflow: hidden;
}

.mode-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
    transition: left 0.5s ease;
}

.mode-card:hover::before {
    left: 100%;
}

.mode-card:hover {
    transform: translateY(-8px);
    box-shadow: var(--glow-strong);
    border-color: var(--accent-green);
}

.mode-icon-container {
    background: var(--gradient-primary);
    width: 4rem;
    height: 4rem;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 1rem;
    transition: all 0.3s ease;
}

.mode-card:hover .mode-icon-container {
    transform: scale(1.1) rotate(5deg);
}

.mode-icon {
    color: var(--white);
    font-size: 1.8rem;
}

.mode-title {
    color: var(--dark-green);
    font-size: 1.3rem;
    font-weight: 600;
    margin-bottom: 0.5rem;
}

.mode-subtitle {
    color: var(--accent-green);
    margin-bottom: 1rem;
}

.mode-stats {
    display: flex;
    justify-content: space-around;
    margin: 1rem 0;
}

.mode-stat {
    text-align: center;
}

.mode-stat-number {
    font-size: 1.2rem;
    font-weight: 700;
    color: var(--primary-green);
}

.mode-stat-label {
    font-size: 0.8rem;
    color: var(--dark-green);
}

.play-btn {
    background: var(--gradient-primary);
    color: var(--white);
    padding: 0.8rem 2rem;
    border-radius: 2rem;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 600;
    transition: all 0.3s ease;
    box-shadow: var(--shadow-soft);
}

.play-btn:hover {
    transform: translateY(-2px);
    box-shadow: var(--glow);
}

/* Achievements */
.achievements-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1rem;
    max-height: 400px;
    overflow-y: auto;
    padding-right: 0.5rem;
}

.achievement-card {
    background: rgba(255, 255, 255, 0.8);
    border-radius: 0.8rem;
    padding: 1rem;
    text-align: center;
    transition: all 0.3s ease;
    border: 2px solid var(--light-green);
}

.achievement-card.unlocked {
    border-color: var(--success-color);
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.1) 0%, rgba(255, 255, 255, 0.9) 100%);
}

.achievement-card:hover {
    transform: scale(1.02);
}

.achievement-icon {
    font-size: 2rem;
    margin-bottom: 0.5rem;
    color: var(--primary-green);
}

.achievement-card.unlocked .achievement-icon {
    color: var(--success-color);
    animation: iconBounce 2s ease-in-out infinite;
}

/* Leaderboard */
.leaderboard-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 1rem;
}

.leaderboard-table th {
    background: var(--gradient-primary);
    color: var(--white);
    padding: 1rem;
    text-align: left;
    font-weight: 600;
}

.leaderboard-table td {
    padding: 0.8rem 1rem;
    border-bottom: 1px solid var(--light-green);
    color: var(--dark-green);
}

.leaderboard-table tr:hover {
    background: var(--light-green);
}

.rank {
    font-weight: 700;
    color: var(--primary-green);
}

/* Recent Games */
.recent-games {
    max-height: 400px;
    overflow-y: auto;
}

.recent-game-item {
    background: rgba(255, 255, 255, 0.7);
    border-radius: 0.8rem;
    padding: 1rem;
    margin-bottom: 1rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    transition: all 0.3s ease;
    border-left: 4px solid var(--accent-green);
}

.recent-game-item:hover {
    transform: translateX(5px);
    box-shadow: var(--shadow-soft);
}

.game-stats {
    display: flex;
    gap: 1rem;
    margin-top: 0.5rem;
}

.stat {
    display: flex;
    align-items: center;
    gap: 0.3rem;
    font-size: 0.9rem;
    color: var(--dark-green);
}

.difficulty-badge {
    background: var(--primary-green);
    color: var(--white);
    padding: 0.2rem 0.8rem;
    border-radius: 1rem;
    font-size: 0.8rem;
    font-weight: 600;
}

/* Difficulty Statistics */
.difficulty-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 1.5rem;
}

.difficulty-stat-card {
    background: var(--gradient-card);
    border-radius: 1rem;
    padding: 1.5rem;
    border: 2px solid var(--light-green);
    transition: all 0.3s ease;
}

.difficulty-stat-card:hover {
    transform: translateY(-5px);
    box-shadow: var(--glow);
}

.stat-header {
    display: flex;
    align-items: center;
    gap: 0.8rem;
    margin-bottom: 1rem;
    padding-bottom: 0.8rem;
    border-bottom: 2px solid var(--light-green);
}

.stat-header i {
    font-size: 1.5rem;
}

.stat-header h3 {
    color: var(--dark-green);
    font-size: 1.2rem;
}

.stat-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 0.8rem;
    padding: 0.5rem 0;
}

.stat-label {
    color: var(--dark-green);
    font-size: 0.9rem;
}

.stat-value {
    color: var(--primary-green);
    font-weight: 600;
}

/* Responsive Design */
@media (max-width: 1200px) {
    .game-modes {
        grid-template-columns: repeat(4, 280px);
    }
}

@media (max-width: 768px) {
    .container {
        padding: 0 0.8rem;
    }
    
    .header-content {
        flex-direction: column;
        gap: 1rem;
        text-align: center;
    }
    
    .card {
        padding: 1rem;
        margin-bottom: 1rem;
    }
    
    .card-header {
        flex-direction: column;
        text-align: center;
        gap: 0.5rem;
    }
    
    .progress-overview {
        grid-template-columns: repeat(2, 1fr);
        gap: 0.8rem;
    }
    
    .game-modes {
        grid-template-columns: repeat(4, 250px);
    }
    
    .mode-card {
        padding: 1rem;
    }
    
    .mode-icon-container {
        width: 3rem;
        height: 3rem;
    }
    
    .mode-icon {
        font-size: 1.4rem;
    }
    
    .achievements-grid {
        grid-template-columns: 1fr;
        max-height: 300px;
    }
    
    .difficulty-stats-grid {
        grid-template-columns: 1fr;
    }
    
    .leaderboard-table {
        font-size: 0.9rem;
    }
    
    .game-stats {
        flex-direction: column;
        gap: 0.5rem;
    }
    
    .recent-game-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.8rem;
    }
}

@media (max-width: 480px) {
    .logo-info h1 {
        font-size: 1.4rem;
    }
    
    .user-info span {
        font-size: 0.8rem;
    }
    
    .stat-number {
        font-size: 1.4rem;
    }
    
    .mode-title {
        font-size: 1.1rem;
    }
    
    .progress-overview {
        grid-template-columns: 1fr;
    }
    
    .game-modes {
        grid-template-columns: 1fr;
    }
}

/* Scrollbar Styling */
::-webkit-scrollbar {
    width: 8px;
}

::-webkit-scrollbar-track {
    background: var(--light-green);
    border-radius: 4px;
}

::-webkit-scrollbar-thumb {
    background: var(--gradient-primary);
    border-radius: 4px;
}

::-webkit-scrollbar-thumb:hover {
    background: var(--dark-green);
}

/* Animation delays for staggered effects */
.card:nth-child(1) { animation-delay: 0.1s; }
.card:nth-child(2) { animation-delay: 0.2s; }
.card:nth-child(3) { animation-delay: 0.3s; }
.card:nth-child(4) { animation-delay: 0.4s; }
.card:nth-child(5) { animation-delay: 0.5s; }

/* Utility classes */
.fade-in {
    animation: fadeInScale 0.6s ease-out;
}

.bounce-icon {
    animation: iconBounce 2s ease-in-out infinite;
}

.glow-effect {
    animation: glowPulse 2s ease-in-out infinite;
}

/* Add these styles for the recent games section */
.no-games-message {
    text-align: center;
    padding: 2rem;
    color: var(--dark-green);
}

.no-games-message i {
    font-size: 3rem;
    margin-bottom: 1rem;
    opacity: 0.5;
}

.game-actions {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.resume-btn {
    background: var(--gradient-primary);
    color: var(--white);
    padding: 0.5rem 1rem;
    border-radius: 2rem;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.3s ease;
}

.resume-btn:hover {
    transform: translateY(-2px);
    box-shadow: var(--glow);
}

.game-performance {
    padding: 0.3rem 0.8rem;
    border-radius: 1rem;
    font-size: 0.9rem;
    font-weight: 600;
}

.game-performance.perfect {
    background: var(--success-color);
    color: var(--white);
}

.game-performance.good {
    background: var(--accent-green);
    color: var(--white);
}

.game-performance.normal {
    background: var(--light-green);
    color: var(--dark-green);
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

/* Mobile View Styles */
@media screen and (max-width: 768px) {
    .container {
        padding: 10px;
        margin-bottom: 60px;
        height: calc(100vh - 60px);
        overflow: hidden;
    }

    .mobile-nav {
        display: block;
    }

    /* Hide desktop-only sections */
    .achievements-grid,
    .leaderboard-table,
    .difficulty-stats-grid {
        display: none;
    }

    /* Mobile-specific sections */
    .mobile-stats,
    .mobile-leaderboard,
    .mobile-achievements {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 60px;
        background: #fff;
        z-index: 999;
        padding: 20px;
        overflow-y: auto;
    }

    .mobile-stats.active,
    .mobile-leaderboard.active,
    .mobile-achievements.active {
        display: block;
    }

    /* Progress and Game Modes Layout */
    .progress-container {
        margin-bottom: 15px;
    }

    .game-modes {
        display: grid;
        grid-template-columns: 1fr;
        gap: 15px;
        height: calc(100vh - 250px);
        overflow-y: auto;
        padding: 10px;
    }

    .mode-card {
        margin: 0;
        height: auto;
    }

    .recent-games {
        margin-top: 15px;
    }

    .recent-game-item {
        margin-bottom: 10px;
    }
}

/* Mobile Sections */
.mobile-stats,
.mobile-leaderboard,
.mobile-achievements {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 60px;
    background: #fff;
    z-index: 999;
    padding: 20px;
    overflow-y: auto;
}

.mobile-stats.active,
.mobile-leaderboard.active,
.mobile-achievements.active {
    display: block;
}

.close-btn {
    background: none;
    border: none;
    color: var(--dark-green);
    font-size: 20px;
    cursor: pointer;
    padding: 5px;
}

.close-btn:hover {
    color: var(--primary-green);
}

/* Mobile Section Content Styles */
.mobile-section-content {
    margin-top: 20px;
}

.mobile-section-content .card {
    margin-bottom: 15px;
}

.mobile-section-content .card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px;
    background: var(--gradient-primary);
    color: white;
    border-radius: 8px 8px 0 0;
}

.mobile-section-content .card-header h2 {
    margin: 0;
    font-size: 18px;
    display: flex;
    align-items: center;
    gap: 8px;
}

/* Saved Games Section Styles */
.saved-games {
    margin: 2rem 0;
}

.saved-games h2 {
    color: var(--white);
    margin-bottom: 1rem;
    font-size: 1.5rem;
}

.saved-games-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 1rem;
}

.saved-game-card {
    background: var(--gradient-card);
    border-radius: 1rem;
    padding: 1.5rem;
    box-shadow: var(--shadow-soft);
    transition: transform 0.3s ease, box-shadow 0.3s ease;
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.saved-game-card:hover {
    transform: translateY(-5px);
    box-shadow: var(--shadow-strong);
}

.game-info h3 {
    color: var(--dark-green);
    margin-bottom: 0.5rem;
    font-size: 1.2rem;
}

.game-info p {
    color: var(--dark-gray);
    font-size: 0.9rem;
    margin-bottom: 1rem;
}

.game-stats {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0.5rem;
}

.game-stats span {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: var(--dark-gray);
    font-size: 0.9rem;
}

.game-stats i {
    color: var(--primary-green);
}

.resume-btn {
    background: var(--gradient-primary);
    color: var(--white);
    padding: 0.8rem 1.5rem;
    border-radius: 2rem;
    text-decoration: none;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    transition: all 0.3s ease;
    font-weight: 600;
    margin-top: auto;
}

.resume-btn:hover {
    transform: translateY(-2px);
    box-shadow: var(--glow);
}

.resume-btn i {
    font-size: 1.1rem;
}

.no-games {
    color: var(--white);
    text-align: center;
    padding: 2rem;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 1rem;
    backdrop-filter: blur(10px);
}

@media screen and (max-width: 768px) {
    .saved-games-grid {
        grid-template-columns: 1fr;
    }

    .saved-game-card {
        margin: 0 1rem;
    }
}
    </style>
</head>
<body>
    <header>
        <div class="header-bg"></div>
        <div class="container">
            <div class="header-content">
                <div class="header-left">
                    <div class="logo-section">
                        <div class="logo-icon">
                            <i class="fas fa-th-large"></i>
                        </div>
                        <div class="logo-info">
                            <h1>Sudoku Master</h1>
                            <p>Challenge your mind with numbers</p>
                        </div>
                    </div>
                </div>
                <div class="header-right">
                    <div class="user-info">
                        <div class="user-avatar">
                            <i class="fas fa-user"></i>
                        </div>
                        <span>Welcome, <?php echo htmlspecialchars($user_name); ?>!</span>
                    </div>
                    <a href="pages/logout.php" class="logout-btn">
                        <i class="fas fa-sign-out-alt"></i>
                        Logout
                    </a>
                </div>
            </div>
        </div>
    </header>

    <div class="container">
        <!-- Overall Progress Card -->
        <div class="card animate__animated animate__fadeIn">
            <div class="card-header">
                <h2><i class="fas fa-chart-line"></i> Overall Progress</h2>
                <div class="stars">
                    <?php for ($i = 0; $i < min(5, floor($total_stars / 20)); $i++): ?>
                        <i class="fas fa-star"></i>
                    <?php endfor; ?>
                    <?php if ($total_stars == 0): ?>
                        <span style="color: #666;">0 stars</span>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="progress-container">
                <div class="progress-bar">
                    <div class="progress-fill" id="total-progress" data-width="<?php echo $total_progress_percentage; ?>"></div>
                </div>
                <div class="progress-text">
                    <span>Levels Completed: <?php echo $total_completed; ?> of <?php echo $total_possible_levels; ?></span>
                    <span>Progress: <?php echo round($total_progress_percentage); ?>%</span>
                </div>
            </div>
            
            <div class="progress-overview">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_completed; ?></div>
                    <div class="stat-label">Levels Completed</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_stars; ?></div>
                    <div class="stat-label">Stars Earned</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo round($total_progress_percentage); ?>%</div>
                    <div class="stat-label">Total Progress</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $modes_played; ?></div>
                    <div class="stat-label">Modes Played</div>
                </div>
            </div>
        </div>

        <!-- Game Modes Card -->
        <div class="card animate__animated animate__fadeIn">
            <div class="card-header">
                <h2><i class="fas fa-gamepad"></i> Game Modes</h2>
                <span style="color: #666; font-size: 16px;">Choose your challenge</span>
            </div>
            
            <div class="game-modes">
                <?php foreach ($difficulty_stats as $diff_id => $stats): ?>
                <div class="mode-card <?php echo strtolower($stats['name']); ?>">
                    <div class="mode-icon-container">
                        <i class="mode-icon <?php echo $stats['icon']; ?>"></i>
                    </div>
                    <h3 class="mode-title"><?php echo $stats['name']; ?></h3>
                    <p class="mode-subtitle"><?php echo $stats['max_levels']; ?> levels available</p>
                    
                    <div class="mode-stats">
                        <div class="mode-stat">
                            <div class="mode-stat-number"><?php echo $stats['completed']; ?>/<?php echo $stats['max_levels']; ?></div>
                            <div class="mode-stat-label">Completed</div>
                        </div>
                        <div class="mode-stat">
                            <div class="mode-stat-number"><?php echo $stats['stars']; ?>/<?php echo $stats['max_stars']; ?></div>
                            <div class="mode-stat-label">Stars</div>
                        </div>
                    </div>
                    
                    <a href="pages/levels.php?difficulty=<?php echo $diff_id; ?>" class="play-btn">
                        <i class="fas fa-play"></i>
                        Start Playing
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Achievements Card -->
        <div class="card animate__animated animate__fadeIn">
            <div class="card-header">
                <h2><i class="fas fa-trophy"></i> Achievements</h2>
            </div>
            <div class="achievements-grid">
                <?php foreach ($achievements as $achievement): ?>
                <div class="achievement-card <?php echo $achievement['is_achieved'] ? 'unlocked' : 'locked'; ?>">
                    <div class="achievement-icon">
                        <?php if ($achievement['is_achieved']): ?>
                            <i class="fas fa-medal"></i>
                        <?php else: ?>
                            <i class="fas fa-lock"></i>
                        <?php endif; ?>
                    </div>
                    <div class="achievement-name"><?php echo htmlspecialchars($achievement['name']); ?></div>
                    <div class="achievement-desc"><?php echo htmlspecialchars($achievement['description']); ?></div>
                    <?php if ($achievement['is_achieved']): ?>
                    <div class="achievement-date">
                        <i class="fas fa-calendar-alt"></i>
                        <?php echo $achievement['earned_at'] ? date('M d, Y', strtotime($achievement['earned_at'])) : 'Just earned!'; ?>
                    </div>
                    <?php endif; ?>
                    <div class="achievement-progress">
                        <?php if ($achievement['requirement_type'] == 'levels_completed'): ?>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo min(100, ($total_completed / $achievement['requirement_value']) * 100); ?>%"></div>
                            </div>
                            <span class="progress-text"><?php echo $total_completed; ?>/<?php echo $achievement['requirement_value']; ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Leaderboard Card -->
        <div class="card animate__animated animate__fadeIn">
            <div class="card-header">
                <h2><i class="fas fa-crown"></i> Global Leaderboard</h2>
            </div>
            <table class="leaderboard-table">
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Player</th>
                        <th>Score</th>
                        <th>Levels</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($leaderboard as $index => $player): ?>
                    <tr>
                        <td class="rank">#<?php echo $index + 1; ?></td>
                        <td><?php echo htmlspecialchars($player['username']); ?></td>
                        <td><?php echo number_format($player['total_score']); ?></td>
                        <td><?php echo $player['levels_completed']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="leaderboard-footer">
                <a href="#" class="see-all-btn" onclick="alert('Leaderboard page coming soon!'); return false;">
                    <i class="fas fa-list"></i>
                    View Full Leaderboard
                </a>
            </div>
        </div>

        <!-- Saved Games Section -->
        <div class="section saved-games">
            <h2>Saved Games</h2>
            <?php if (empty($saved_games)): ?>
                <p class="no-games">No saved games found.</p>
            <?php else: ?>
                <div class="saved-games-grid">
                    <?php foreach ($saved_games as $game): ?>
                        <div class="saved-game-card">
                            <div class="game-info">
                                <h3><?php echo htmlspecialchars($game['difficulty_name']); ?> - Level <?php echo htmlspecialchars($game['level_number']); ?></h3>
                                <p>Saved: <?php echo date('M d, Y H:i', strtotime($game['saved_at'])); ?></p>
                                <div class="game-stats">
                                    <span><i class="fas fa-clock"></i> <?php echo floor($game['time_remaining'] / 60); ?>:<?php echo str_pad($game['time_remaining'] % 60, 2, '0', STR_PAD_LEFT); ?></span>
                                    <span><i class="fas fa-star"></i> <?php echo $game['current_score']; ?></span>
                                    <span><i class="fas fa-times-circle"></i> <?php echo $game['mistakes_made']; ?></span>
                                    <span><i class="fas fa-lightbulb"></i> <?php echo $game['hints_used']; ?></span>
                                </div>
                            </div>
                            <a href="pages/continue_game.php?id=<?php echo $game['id']; ?>" class="resume-btn">
                                <i class="fas fa-play"></i> Continue
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="sudoku-background" id="sudokuBackground"></div>

        <!-- JS animations and interactive effects removed as requested -->

        <!-- Add before closing body tag -->
        <div class="mobile-nav">
            <div class="mobile-nav-buttons">
                <a href="home.php" class="mobile-nav-btn active">
                    <i class="fas fa-home"></i>
                    <span>Home</span>
                </a>
                <a href="pages/levels.php" class="mobile-nav-btn">
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

        <!-- Mobile Sections -->
        <div class="mobile-stats">
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-chart-bar"></i> Statistics</h2>
                    <button class="close-btn" onclick="toggleMobileSection('stats')">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="mobile-section-content">
                    <div class="difficulty-stats-grid">
                        <?php foreach ($difficulty_stats as $diff_id => $stats): ?>
                        <div class="difficulty-stat-card">
                            <div class="stat-header">
                                <i class="<?php echo $stats['icon']; ?>" style="color: <?php echo $stats['color']; ?>"></i>
                                <h3><?php echo $stats['name']; ?></h3>
                            </div>
                            <div class="stat-content">
                                <div class="stat-row">
                                    <span class="stat-label">Completed Levels</span>
                                    <span class="stat-value"><?php echo $stats['completed']; ?>/<?php echo $stats['max_levels']; ?></span>
                                </div>
                                <div class="stat-row">
                                    <span class="stat-label">Perfect Games</span>
                                    <span class="stat-value"><?php echo $stats['perfect_games']; ?></span>
                                </div>
                                <div class="stat-row">
                                    <span class="stat-label">Average Score</span>
                                    <span class="stat-value"><?php echo number_format($stats['avg_score']); ?></span>
                                </div>
                                <div class="stat-row">
                                    <span class="stat-label">Best Time</span>
                                    <span class="stat-value">
                                        <?php 
                                        if ($stats['best_time']) {
                                            $minutes = floor($stats['best_time'] / 60);
                                            $seconds = $stats['best_time'] % 60;
                                            echo $minutes . 'm ' . $seconds . 's';
                                        } else {
                                            echo 'N/A';
                                        }
                                        ?>
                                    </span>
                                </div>
                                <div class="stat-row">
                                    <span class="stat-label">Speed Games (&lt;2min)</span>
                                    <span class="stat-value"><?php echo $stats['speed_games']; ?></span>
                                </div>
                                <div class="stat-row">
                                    <span class="stat-label">Completion Rate</span>
                                    <span class="stat-value">
                                        <?php 
                                        $completion_rate = $stats['max_levels'] > 0 ? 
                                            round(($stats['completed'] / $stats['max_levels']) * 100, 1) : 0;
                                        echo $completion_rate . '%';
                                        ?>
                                    </span>
                                </div>
                            </div>
                            
                            <!-- Progress Bar -->
                            <div class="progress-container">
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?php echo $completion_rate; ?>%; background-color: <?php echo $stats['color']; ?>"></div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="mobile-leaderboard">
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-crown"></i> Leaderboard</h2>
                    <button class="close-btn" onclick="toggleMobileSection('leaderboard')">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="mobile-section-content">
                    <table class="leaderboard-table">
                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Player</th>
                                <th>Score</th>
                                <th>Levels</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($leaderboard as $index => $player): ?>
                            <tr>
                                <td class="rank">#<?php echo $index + 1; ?></td>
                                <td><?php echo htmlspecialchars($player['username']); ?></td>
                                <td><?php echo number_format($player['total_score']); ?></td>
                                <td><?php echo $player['levels_completed']; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="mobile-achievements">
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-trophy"></i> Achievements</h2>
                    <button class="close-btn" onclick="toggleMobileSection('achievements')">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="mobile-section-content">
                    <div class="achievements-grid">
                        <?php foreach ($achievements as $achievement): ?>
                        <div class="achievement-card <?php echo $achievement['is_achieved'] ? 'unlocked' : 'locked'; ?>">
                            <div class="achievement-icon">
                                <?php if ($achievement['is_achieved']): ?>
                                    <i class="fas fa-medal"></i>
                                <?php else: ?>
                                    <i class="fas fa-lock"></i>
                                <?php endif; ?>
                            </div>
                            <div class="achievement-name"><?php echo htmlspecialchars($achievement['name']); ?></div>
                            <div class="achievement-desc"><?php echo htmlspecialchars($achievement['description']); ?></div>
                            <?php if ($achievement['is_achieved']): ?>
                            <div class="achievement-date">
                                <i class="fas fa-calendar-alt"></i>
                                <?php echo $achievement['earned_at'] ? date('M d, Y', strtotime($achievement['earned_at'])) : 'Just earned!'; ?>
                            </div>
                            <?php endif; ?>
                            <div class="achievement-progress">
                                <?php if ($achievement['requirement_type'] == 'levels_completed'): ?>
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?php echo min(100, ($total_completed / $achievement['requirement_value']) * 100); ?>%"></div>
                                    </div>
                                    <span class="progress-text"><?php echo $total_completed; ?>/<?php echo $achievement['requirement_value']; ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <script>
        function toggleMobileSection(section) {
            const sections = ['stats', 'leaderboard', 'achievements'];
            sections.forEach(s => {
                const element = document.querySelector(`.mobile-${s}`);
                if (s === section) {
                    element.classList.toggle('active');
                    // Scroll to top when opening a section
                    if (element.classList.contains('active')) {
                        element.scrollTop = 0;
                    }
                } else {
                    element.classList.remove('active');
                }
            });
        }
        </script>
    </div>
</body>
</html>

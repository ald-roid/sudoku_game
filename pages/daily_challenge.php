<?php
session_start();
require_once __DIR__ . '/../config/db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

// Get the current month's challenges
$year = date('Y');
$month = date('m');
$days_in_month = date('t');

$stmt = $pdo->prepare("
    SELECT date, puzzle_data 
    FROM daily_challenges 
    WHERE YEAR(date) = ? AND MONTH(date) = ?
    ORDER BY date ASC
");
$stmt->execute([$year, $month]);
$challenges = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Generate challenges for missing days
for ($day = 1; $day <= $days_in_month; $day++) {
    $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
    if (!isset($challenges[$date])) {
        // Generate a new puzzle
        $puzzle = generateSudokuPuzzle(9); // 9x9 grid for daily challenges
        
        $stmt = $pdo->prepare("
            INSERT INTO daily_challenges (date, puzzle_data, solution_data) 
            VALUES (?, ?, ?)
        ");
        $stmt->execute([
            $date,
            json_encode($puzzle['puzzle']),
            json_encode($puzzle['solution'])
        ]);
        
        $challenges[$date] = json_encode($puzzle['puzzle']);
    }
}

// Function to generate a Sudoku puzzle
function generateSudokuPuzzle($size) {
    // Initialize empty grid
    $grid = array_fill(0, $size, array_fill(0, $size, 0));
    
    // Fill diagonal boxes first (they are independent)
    $box_size = sqrt($size);
    for ($box = 0; $box < $size; $box += $box_size) {
        $numbers = range(1, $size);
        shuffle($numbers);
        for ($i = 0; $i < $box_size; $i++) {
            for ($j = 0; $j < $box_size; $j++) {
                $grid[$box + $i][$box + $j] = array_pop($numbers);
            }
        }
    }
    
    // Solve the rest of the grid
    solveSudoku($grid);
    
    // Create a copy of the solution
    $solution = array_map(function($row) { return $row; }, $grid);
    
    // Remove numbers to create the puzzle
    $cells_to_remove = $size * $size * 0.6; // Remove 60% of the numbers
    $positions = range(0, $size * $size - 1);
    shuffle($positions);
    
    for ($i = 0; $i < $cells_to_remove; $i++) {
        $pos = $positions[$i];
        $row = floor($pos / $size);
        $col = $pos % $size;
        $grid[$row][$col] = 0;
    }
    
    return [
        'puzzle' => $grid,
        'solution' => $solution
    ];
}

// Function to solve Sudoku using backtracking
function solveSudoku(&$grid) {
    $size = count($grid);
    $empty = findEmpty($grid);
    
    if (!$empty) {
        return true; // Puzzle is solved
    }
    
    list($row, $col) = $empty;
    
    for ($num = 1; $num <= $size; $num++) {
        if (isValid($grid, $row, $col, $num)) {
            $grid[$row][$col] = $num;
            
            if (solveSudoku($grid)) {
                return true;
            }
            
            $grid[$row][$col] = 0;
        }
    }
    
    return false;
}

// Helper function to find empty cell
function findEmpty($grid) {
    $size = count($grid);
    for ($i = 0; $i < $size; $i++) {
        for ($j = 0; $j < $size; $j++) {
            if ($grid[$i][$j] === 0) {
                return [$i, $j];
            }
        }
    }
    return null;
}

// Helper function to check if a number is valid in a position
function isValid($grid, $row, $col, $num) {
    $size = count($grid);
    $box_size = sqrt($size);
    
    // Check row
    for ($i = 0; $i < $size; $i++) {
        if ($grid[$row][$i] === $num) {
            return false;
        }
    }
    
    // Check column
    for ($i = 0; $i < $size; $i++) {
        if ($grid[$i][$col] === $num) {
            return false;
        }
    }
    
    // Check box
    $box_row = floor($row / $box_size) * $box_size;
    $box_col = floor($col / $box_size) * $box_size;
    
    for ($i = 0; $i < $box_size; $i++) {
        for ($j = 0; $j < $box_size; $j++) {
            if ($grid[$box_row + $i][$box_col + $j] === $num) {
                return false;
            }
        }
    }
    
    return true;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Challenges - Sudoku Master</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .calendar {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 5px;
            max-width: 600px;
            margin: 0 auto;
        }
        .calendar-day {
            aspect-ratio: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .calendar-day:hover {
            background-color: #e9ecef;
        }
        .calendar-day.available {
            background-color: #28a745;
            color: white;
        }
        .calendar-day.unavailable {
            background-color: #dc3545;
            color: white;
        }
        .calendar-day.today {
            border: 2px solid #007bff;
        }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="home.php">Sudoku Master</a>
            <div class="navbar-text text-light">
                Daily Challenges
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="row">
            <div class="col-md-8 mx-auto">
                <div class="card">
                    <div class="card-header">
                        <h2 class="text-center"><?php echo date('F Y'); ?></h2>
                    </div>
                    <div class="card-body">
                        <div class="calendar">
                            <?php
                            // Print day headers
                            $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                            foreach ($days as $day) {
                                echo "<div class='text-center fw-bold'>{$day}</div>";
                            }
                            
                            // Print calendar days
                            $first_day = date('w', strtotime("$year-$month-01"));
                            for ($i = 0; $i < $first_day; $i++) {
                                echo "<div></div>";
                            }
                            
                            $today = date('Y-m-d');
                            for ($day = 1; $day <= $days_in_month; $day++) {
                                $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
                                $is_available = strtotime($date) <= strtotime($today);
                                $is_today = $date === $today;
                                
                                $class = 'calendar-day';
                                if ($is_available) $class .= ' available';
                                if ($is_today) $class .= ' today';
                                
                                echo "<div class='{$class}' onclick='playChallenge(\"{$date}\")'>{$day}</div>";
                            }
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function playChallenge(date) {
            const today = new Date().toISOString().split('T')[0];
            if (date <= today) {
                window.location.href = `game.php?mode=daily&date=${date}`;
            }
        }
    </script>
</body>
</html> 
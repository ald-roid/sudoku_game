<?php
require_once __DIR__ . '/../config/db_connect.php';

// Function to generate a Sudoku puzzle
function generateSudokuPuzzle($size, $difficulty) {
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
    
    // Remove numbers to create the puzzle based on difficulty
    $removal_percentages = [
        'easy' => 0.4,    // Remove 40% of numbers
        'medium' => 0.5,  // Remove 50% of numbers
        'hard' => 0.6,    // Remove 60% of numbers
        'expert' => 0.7   // Remove 70% of numbers
    ];
    
    $cells_to_remove = $size * $size * $removal_percentages[$difficulty];
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

// Generate puzzles for each difficulty level
$difficulties = [
    'easy' => ['levels' => 30, 'grid_sizes' => [4, 6, 9], 'time_limits' => [3, 5, 8, 10]],
    'medium' => ['levels' => 50, 'grid_sizes' => [4, 6, 9], 'time_limits' => [5, 8, 10]],
    'hard' => ['levels' => 100, 'grid_sizes' => [9], 'time_limits' => [5, 8, 10, 12]],
    'expert' => ['levels' => 200, 'grid_sizes' => [9], 'time_limits' => [5, 8, 10, 12]]
];

try {
    foreach ($difficulties as $difficulty => $config) {
        for ($level = 1; $level <= $config['levels']; $level++) {
            // Select grid size and time limit
            $grid_size = $config['grid_sizes'][array_rand($config['grid_sizes'])];
            $time_limit = $config['time_limits'][array_rand($config['time_limits'])];
            
            // Generate puzzle
            $puzzle = generateSudokuPuzzle($grid_size, $difficulty);
            
            // Insert into database
            $stmt = $pdo->prepare("
                INSERT INTO levels (difficulty, level_number, grid_size, time_limit, puzzle_data, solution_data)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $difficulty,
                $level,
                $grid_size,
                $time_limit,
                json_encode($puzzle['puzzle']),
                json_encode($puzzle['solution'])
            ]);
            
            echo "Generated {$difficulty} level {$level} ({$grid_size}x{$grid_size}, {$time_limit} minutes)\n";
        }
    }
    
    echo "All puzzles generated successfully!\n";
} catch (PDOException $e) {
    echo "Error generating puzzles: " . $e->getMessage() . "\n";
}
?> 
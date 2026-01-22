<?php
/**
 * Get Lesson Content API
 * 
 * Endpoint: GET /api/get_lesson_content.php
 * Description: Retrieves lesson content with adaptive pacing
 * 
 * Parameters:
 * - node_id (required): Node ID
 * - placement_level (optional): 1=Beginner, 2=Intermediate, 3=Advanced
 * 
 * Response:
 * {
 *   "success": true,
 *   "lesson": {...},
 *   "pacing": {...}
 * }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

require_once __DIR__ . '/src/db.php';

try {
    $nodeId = isset($_GET['node_id']) ? intval($_GET['node_id']) : 0;
    $placementLevel = isset($_GET['placement_level']) ? intval($_GET['placement_level']) : 2;
    
    if ($nodeId === 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Node ID is required'
        ]);
        exit;
    }
    
    // Get node content
    $stmt = $conn->prepare("
        SELECT 
            n.NodeID,
            n.NodeNumber,
            n.LessonTitle,
            n.LessonObjective,
            n.LessonContent,
            n.NodeType,
            n.Quarter,
            n.ModuleID,
            m.ModuleName
        FROM Nodes n
        JOIN Modules m ON n.ModuleID = m.ModuleID
        WHERE n.NodeID = ?
    ");
    
    $stmt->execute([$nodeId]);
    $node = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$node) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Node not found'
        ]);
        exit;
    }
    
    // Get pacing strategy
    $pacing = getPacingStrategy($placementLevel);
    
    // Generate adaptive content
    $content = generateAdaptiveContent($node['LessonContent'], $placementLevel);
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'lesson' => [
            'node_id' => $node['NodeID'],
            'node_number' => $node['NodeNumber'],
            'title' => $node['LessonTitle'],
            'objective' => $node['LessonObjective'],
            'content' => $content,
            'module_id' => $node['ModuleID'],
            'module_name' => $node['ModuleName'],
            'quarter' => $node['Quarter'],
            'is_final_assessment' => $node['NodeType'] === 'FINAL_ASSESSMENT'
        ],
        'pacing' => $pacing
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error',
        'error' => (($_ENV['DEBUG_MODE'] ?? 'false') === 'true') ? $e->getMessage() : null
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error',
        'error' => (($_ENV['DEBUG_MODE'] ?? 'false') === 'true') ? $e->getMessage() : null
    ]);
}


function getPacingStrategy($level) {
    switch ($level) {
        case 1:
            return [
                'speed' => 'SLOW',
                'scaffolding' => 'HIGH',
                'description' => '📖 BEGINNER MODE: Detailed explanations with step-by-step guidance'
            ];
        case 2:
            return [
                'speed' => 'MODERATE',
                'scaffolding' => 'BALANCED',
                'description' => '📘 INTERMEDIATE MODE: Balanced content'
            ];
        case 3:
            return [
                'speed' => 'FAST',
                'scaffolding' => 'MINIMAL',
                'description' => '📕 ADVANCED MODE: Concise content'
            ];
        default:
            return [
                'speed' => 'MODERATE',
                'scaffolding' => 'BALANCED',
                'description' => '📘 INTERMEDIATE MODE: Balanced content'
            ];
    }
}

function generateAdaptiveContent($baseContent, $level) {
    if (empty($baseContent)) {
        switch ($level) {
            case 1:
                return "Welcome! Let's learn step by step! 🌟\n\n" .
                       "Take your time and read carefully.\n" .
                       "We'll practice together!";
            case 2:
                return "Let's dive into this lesson! 📚\n\n" .
                       "Read through the content and apply what you learn.";
            case 3:
                return "Advanced Challenge! 🚀\n\n" .
                       "Apply critical thinking and advanced strategies.";
        }
    }
    
    return $baseContent;
}
?>

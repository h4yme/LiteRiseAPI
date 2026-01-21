<?php
/**
 * Submit Quiz API
 * POST /submit_quiz.php
 * 
 * Request Body:
 * {
 *   "student_id": 1,
 *   "node_id": 5,
 *   "quiz_score": 85,
 *   "attempt_count": 1,
 *   "recent_scores": [75, 80],
 *   "time_spent": 180
 * }
 */

require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/auth.php';

// Get JSON input
$data = getJsonInput();

// Validate required fields
validateRequired($data, ['student_id', 'node_id', 'quiz_score', 'attempt_count', 'time_spent']);

$studentId = intval($data['student_id']);
$nodeId = intval($data['node_id']);
$quizScore = intval($data['quiz_score']);
$attemptCount = intval($data['attempt_count']);
$recentScores = $data['recent_scores'] ?? [];
$timeSpent = intval($data['time_spent']);

// Constants
$PASS_THRESHOLD = 70;
$BORDERLINE_THRESHOLD = 80;
$MASTERY_THRESHOLD = 90;

try {
    $conn->beginTransaction();
    
    // Get student placement level
    $stmt = $conn->prepare("SELECT PlacementLevel FROM Students WHERE StudentID = ?");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch();
    $placementLevel = $student['PlacementLevel'];
    
    // Get node info
    $stmt = $conn->prepare("SELECT * FROM Nodes WHERE NodeID = ?");
    $stmt->execute([$nodeId]);
    $node = $stmt->fetch();
    $moduleId = $node['ModuleID'];
    
    // Calculate score trend
    $scoreTrend = 'STABLE';
    if (count($recentScores) >= 2) {
        $lastScore = $recentScores[count($recentScores) - 1];
        $prevScore = $recentScores[count($recentScores) - 2];
        if ($quizScore > $lastScore && $lastScore > $prevScore) {
            $scoreTrend = 'IMPROVING';
        } elseif ($quizScore < $lastScore && $lastScore < $prevScore) {
            $scoreTrend = 'DECLINING';
        }
    }
    
    // Adaptive decision logic
    $decision = '';
    $nextNodeId = null;
    $supplementalNodeId = null;
    $reason = '';
    $xpEarned = 0;
    
    if ($quizScore < $PASS_THRESHOLD) {
        // INTERVENTION: Failed quiz
        $decision = 'ADD_INTERVENTION';
        $reason = "Score below 70% - additional help needed";
        
        // Find intervention node
        $stmt = $conn->prepare("
            SELECT SupplementalNodeID FROM SupplementalNodes 
            WHERE AfterNodeID = ? AND NodeType = 'INTERVENTION'
            LIMIT 1
        ");
        $stmt->execute([$nodeId]);
        $suppNode = $stmt->fetch();
        
        if ($suppNode) {
            $supplementalNodeId = $suppNode['SupplementalNodeID'];
            // Mark as visible
            $stmt = $conn->prepare("UPDATE SupplementalNodes SET IsVisible = 1 WHERE SupplementalNodeID = ?");
            $stmt->execute([$supplementalNodeId]);
        }
        $xpEarned = 5;
        
    } elseif ($quizScore >= $PASS_THRESHOLD && $quizScore < $BORDERLINE_THRESHOLD) {
        // SUPPLEMENTAL: Borderline pass
        if ($placementLevel == 1) {
            $decision = 'ADD_SUPPLEMENTAL';
            $reason = "Borderline pass - extra practice recommended for beginners";
            
            $stmt = $conn->prepare("
                SELECT SupplementalNodeID FROM SupplementalNodes 
                WHERE AfterNodeID = ? AND NodeType = 'SUPPLEMENTAL'
                LIMIT 1
            ");
            $stmt->execute([$nodeId]);
            $suppNode = $stmt->fetch();
            
            if ($suppNode) {
                $supplementalNodeId = $suppNode['SupplementalNodeID'];
                $stmt = $conn->prepare("UPDATE SupplementalNodes SET IsVisible = 1 WHERE SupplementalNodeID = ?");
                $stmt->execute([$supplementalNodeId]);
            }
        } else {
            $decision = 'PROCEED';
            $reason = "Passed quiz - ready for next lesson";
        }
        
        // Get next node
        $stmt = $conn->prepare("
            SELECT NodeID FROM Nodes 
            WHERE ModuleID = ? AND NodeNumber > ?
            ORDER BY NodeNumber ASC 
            LIMIT 1
        ");
        $stmt->execute([$moduleId, $node['NodeNumber']]);
        $nextNode = $stmt->fetch();
        if ($nextNode) {
            $nextNodeId = $nextNode['NodeID'];
        }
        $xpEarned = 15;
        
    } elseif ($quizScore >= $MASTERY_THRESHOLD && $placementLevel == 3) {
        // ENRICHMENT: High mastery
        $decision = 'OFFER_ENRICHMENT';
        $reason = "Excellent performance - challenge content available";
        
        $stmt = $conn->prepare("
            SELECT SupplementalNodeID FROM SupplementalNodes 
            WHERE AfterNodeID = ? AND NodeType = 'ENRICHMENT'
            LIMIT 1
        ");
        $stmt->execute([$nodeId]);
        $suppNode = $stmt->fetch();
        
        if ($suppNode) {
            $supplementalNodeId = $suppNode['SupplementalNodeID'];
            $stmt = $conn->prepare("UPDATE SupplementalNodes SET IsVisible = 1 WHERE SupplementalNodeID = ?");
            $stmt->execute([$supplementalNodeId]);
        }
        
        // Get next node
        $stmt = $conn->prepare("
            SELECT NodeID FROM Nodes 
            WHERE ModuleID = ? AND NodeNumber > ?
            ORDER BY NodeNumber ASC 
            LIMIT 1
        ");
        $stmt->execute([$moduleId, $node['NodeNumber']]);
        $nextNode = $stmt->fetch();
        if ($nextNode) {
            $nextNodeId = $nextNode['NodeID'];
        }
        $xpEarned = 25;
        
    } else {
        // PROCEED: Good pass
        $decision = 'PROCEED';
        $reason = "Good performance - ready for next lesson";
        
        $stmt = $conn->prepare("
            SELECT NodeID FROM Nodes 
            WHERE ModuleID = ? AND NodeNumber > ?
            ORDER BY NodeNumber ASC 
            LIMIT 1
        ");
        $stmt->execute([$moduleId, $node['NodeNumber']]);
        $nextNode = $stmt->fetch();
        if ($nextNode) {
            $nextNodeId = $nextNode['NodeID'];
        }
        $xpEarned = 20;
    }
    
    // Check if StudentNodeProgress record exists
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count FROM StudentNodeProgress 
        WHERE StudentID = ? AND NodeID = ?
    ");
    $stmt->execute([$studentId, $nodeId]);
    $exists = $stmt->fetch()['count'] > 0;
    
    if ($exists) {
        // Update existing record
        $stmt = $conn->prepare("
            UPDATE StudentNodeProgress 
            SET QuizCompleted = 1, LastQuizScore = ? 
            WHERE StudentID = ? AND NodeID = ?
        ");
        $stmt->execute([$quizScore, $studentId, $nodeId]);
    } else {
        // Insert new record
        $stmt = $conn->prepare("
            INSERT INTO StudentNodeProgress (StudentID, NodeID, QuizCompleted, LastQuizScore)
            VALUES (?, ?, 1, ?)
        ");
        $stmt->execute([$studentId, $nodeId, $quizScore]);
    }
    
    // Update student XP
    $stmt = $conn->prepare("UPDATE Students SET TotalXP = TotalXP + ? WHERE StudentID = ?");
    $stmt->execute([$xpEarned, $studentId]);
    
    // Save quiz attempt
    $stmt = $conn->prepare("
        INSERT INTO QuizAttempts (StudentID, NodeID, Score, AttemptNumber, TimeSpent, CompletedDate)
        VALUES (?, ?, ?, ?, ?, GETDATE())
    ");
    $stmt->execute([$studentId, $nodeId, $quizScore, $attemptCount, $timeSpent]);
    
    // Save adaptive decision
    $stmt = $conn->prepare("
        INSERT INTO AdaptiveDecisions 
        (StudentID, NodeID, DecisionType, Reason, QuizScore, AttemptCount, ScoreTrend, SupplementalNodeTriggered, CreatedDate)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, GETDATE())
    ");
    $stmt->execute([$studentId, $nodeId, $decision, $reason, $quizScore, $attemptCount, $scoreTrend, $supplementalNodeId]);
    
    $conn->commit();
    
    // Determine pacing
    $pacingStrategy = 'MODERATE';
    if ($placementLevel == 1 || $scoreTrend == 'DECLINING') {
        $pacingStrategy = 'SLOW';
    } elseif ($placementLevel == 3 && $scoreTrend == 'IMPROVING') {
        $pacingStrategy = 'FAST';
    }
    
    // Generate message
    $messages = [
        'ADD_INTERVENTION' => "Let's practice this topic more before moving on.",
        'ADD_SUPPLEMENTAL' => "Good work! Here's some extra practice to strengthen your skills.",
        'OFFER_ENRICHMENT' => "Excellent! Ready for a challenge?",
        'PROCEED' => "Great job! Moving to the next lesson."
    ];
    
    sendResponse([
        'decision' => $decision,
        'reason' => $reason,
        'next_node_id' => $nextNodeId,
        'supplemental_node_id' => $supplementalNodeId,
        'pacing_strategy' => $pacingStrategy,
        'message' => $messages[$decision],
        'xp_earned' => $xpEarned
    ]);
    
} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("Database error in submit_quiz: " . $e->getMessage());
    sendError("Failed to submit quiz", 500, $e->getMessage());
}
?>

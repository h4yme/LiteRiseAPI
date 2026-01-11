<?php
/**
 * Submit Student Answer API
 * POST /api/submit_answer.php
 *
 * Records a student's answer to an assessment question
 * and updates item statistics for ML analytics
 *
 * Request Body:
 * {
 *   "student_id": 27,
 *   "item_id": 15,
 *   "session_id": 1736149200,
 *   "assessment_type": "PreAssessment",
 *   "selected_answer": "C",
 *   "is_correct": true,
 *   "student_theta": 0.3,
 *   "response_time": 12,
 *   "question_number": 6,
 *   "device_info": "realme RMX3286, Android 13",
 *   "interaction_data": "{\"hesitations\": 2}"  // Optional JSON
 * }
 *
 * Response:
 * {
 *   "success": true,
 *   "response_id": 1234,
 *   "is_correct": true,
 *   "feedback": {
 *     "message": "Correct! Great job!",
 *     "expected_probability": 0.65,
 *     "new_theta_estimate": 0.35
 *   }
 * }
 */

require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/auth.php';

// Require authentication
$authUser = requireAuth();

// Get JSON input
$data = getJsonInput();

// Validate required fields (is_correct is now optional - we'll determine it server-side)
// Note: selected_answer can be empty string for skipped questions
validateRequired($data, [
    'student_id',
    'item_id',
    'session_id',
    'assessment_type',
    'student_theta',
    'question_number'
]);

$studentID = (int)$data['student_id'];
$itemID = (int)$data['item_id'];
$sessionID = (int)$data['session_id'];
$assessmentType = sanitizeInput($data['assessment_type']);

// selected_answer is optional - can be empty string for skipped questions
$selectedAnswer = isset($data['selected_answer']) ? sanitizeInput($data['selected_answer']) : '';
$studentTheta = (float)$data['student_theta'];

// Determine correctness server-side by comparing with correct answer in database
$isCorrect = false;
if (!empty($selectedAnswer)) {
    $correctAnswerStmt = $conn->prepare("
        SELECT CorrectAnswer FROM dbo.AssessmentItems WHERE ItemID = ?
    ");
    $correctAnswerStmt->execute([$itemID]);
    $correctAnswerRow = $correctAnswerStmt->fetch(PDO::FETCH_ASSOC);

    if ($correctAnswerRow) {
        $correctAnswer = $correctAnswerRow['CorrectAnswer'];
        // Case-insensitive comparison
        $isCorrect = (strcasecmp(trim($selectedAnswer), trim($correctAnswer)) === 0);
    }
}
$responseTime = isset($data['response_time']) ? (int)$data['response_time'] : null;
$questionNumber = (int)$data['question_number'];
$deviceInfo = isset($data['device_info']) ? sanitizeInput($data['device_info']) : null;
$interactionData = isset($data['interaction_data']) ? $data['interaction_data'] : null;

// Verify user can only submit answers for themselves
if ($authUser['studentID'] != $studentID) {
    sendError("Unauthorized: Cannot submit answer for another student", 403);
}

// Validate assessment type
if (!in_array($assessmentType, ['PreAssessment', 'PostAssessment'])) {
    sendError("Invalid assessment_type. Must be 'PreAssessment' or 'PostAssessment'", 400);
}

try {
    // Call stored procedure to record response
    $stmt = $conn->prepare("EXEC dbo.SP_RecordStudentResponse
        @StudentID = ?,
        @ItemID = ?,
        @SessionID = ?,
        @AssessmentType = ?,
        @SelectedAnswer = ?,
        @IsCorrect = ?,
        @StudentThetaAtTime = ?,
        @ResponseTime = ?,
        @QuestionNumber = ?,
        @DeviceInfo = ?,
        @InteractionData = ?
    ");

    $stmt->execute([
        $studentID,
        $itemID,
        $sessionID,
        $assessmentType,
        $selectedAnswer,
        $isCorrect ? 1 : 0,
        $studentTheta,
        $responseTime,
        $questionNumber,
        $deviceInfo,
        $interactionData
    ]);

    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $responseID = $result ? (int)$result['ResponseID'] : 0;

    // Calculate new theta estimate using simple EAP method
    // (This is a simplified version - ML model will do better)
    $thetaAdjustment = $isCorrect ? 0.1 : -0.1;
    $newTheta = $studentTheta + $thetaAdjustment;
    $newTheta = max(-3.0, min(3.0, $newTheta)); // Clamp to [-3, 3]

    // Get expected probability for feedback
    // Using 3PL IRT model: P(correct) = c + (1-c) / (1 + exp(-1.7*a*(theta-b)))
    $itemStmt = $conn->prepare("
        SELECT DifficultyParam, DiscriminationParam, GuessingParam
        FROM dbo.AssessmentItems
        WHERE ItemID = ?
    ");
    $itemStmt->execute([$itemID]);
    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);

    $expectedProbability = 0.5;
    if ($item) {
        $b = (float)$item['DifficultyParam'];
        $a = (float)$item['DiscriminationParam'];
        $c = (float)$item['GuessingParam'];

        $expectedProbability = $c + (1 - $c) / (1 + exp(-1.7 * $a * ($studentTheta - $b)));
    }

    // Generate feedback message
    $feedbackMessage = $isCorrect
        ? "Correct! Great job! 🎉"
        : "Not quite. Keep trying! 💪";

    $response = [
        'success' => true,
        'response_id' => $responseID,
        'is_correct' => $isCorrect,
        'feedback' => [
            'message' => $feedbackMessage,
            'expected_probability' => round($expectedProbability, 3),
            'new_theta_estimate' => round($newTheta, 3)
        ]
    ];

    sendResponse($response, 201);

} catch (PDOException $e) {
    error_log("Submit answer error: " . $e->getMessage());
    sendError("Failed to submit answer", 500, $e->getMessage());
} catch (Exception $e) {
    error_log("Submit answer error: " . $e->getMessage());
    sendError("An error occurred", 500, $e->getMessage());
}
?>
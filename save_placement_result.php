<?php
/**
 * LiteRise Save Placement Result API
 * POST /api/save_placement_result.php
 *
 * Request Body:
 * {
 *   "student_id": 1,
 *   "session_id": 123,
 *   "assessment_type": "PreAssessment",
 *   "final_theta": 0.75,
 *   "placement_level": 3,
 *   "level_name": "Developing Reader",
 *   "total_questions": 25,
 *   "correct_answers": 18,
 *   "accuracy_percentage": 72.0,
 *   "category_scores": {
 *     "category1": 75.0,
 *     "category2": 80.0,
 *     "category3": 65.0,
 *     "category4": 70.0
 *   },
 *   "category_theta": {
 *     "category1": 0.5,
 *     "category2": 0.8,
 *     "category3": 0.3,
 *     "category4": 0.6
 *   },
 *   "time_spent_seconds": 1800,
 *   "device_info": "Android 12",
 *   "app_version": "1.0.0"
 * }
 *
 * Response:
 * {
 *   "success": true,
 *   "message": "Placement result saved successfully",
 *   "result": {
 *     "ResultID": 1,
 *     "AssessmentType": "PreAssessment",
 *     "PlacementLevel": 3,
 *     "LevelName": "Developing Reader"
 *   }
 * }
 */

require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/auth.php';

// Require authentication
$authUser = requireAuth();

// Get JSON input
$data = getJsonInput();

// Extract required fields
$studentID = $data['student_id'] ?? 0;
$sessionID = $data['session_id'] ?? 0;
$assessmentType = $data['assessment_type'] ?? '';
$finalTheta = $data['final_theta'] ?? 0.0;
$placementLevel = $data['placement_level'] ?? 0;
$levelName = $data['level_name'] ?? '';
$totalQuestions = $data['total_questions'] ?? 0;
$correctAnswers = $data['correct_answers'] ?? 0;
$accuracyPercentage = $data['accuracy_percentage'] ?? 0.0;

// Extract optional fields
$categoryScores = $data['category_scores'] ?? [];
$categoryTheta = $data['category_theta'] ?? [];
$timeSpentSeconds = $data['time_spent_seconds'] ?? null;
$deviceInfo = $data['device_info'] ?? null;
$appVersion = $data['app_version'] ?? null;

// Validate required fields
if ($studentID == 0 || $sessionID == 0 || empty($assessmentType) || empty($levelName)) {
    sendError("Missing required fields: student_id, session_id, assessment_type, and level_name are required", 400);
}

// Validate assessment type
if (!in_array($assessmentType, ['PreAssessment', 'PostAssessment'])) {
    sendError("Invalid assessment_type. Must be 'PreAssessment' or 'PostAssessment'", 400);
}

// Verify the authenticated user matches the student_id (security check)
if ($authUser['studentID'] != $studentID) {
    sendError("Unauthorized: Cannot save result for another student", 403);
}

try {
    // Call stored procedure to save placement result
    $stmt = $conn->prepare("
        EXEC SP_SavePlacementResult
            @StudentID = :studentID,
            @SessionID = :sessionID,
            @AssessmentType = :assessmentType,
            @FinalTheta = :finalTheta,
            @PlacementLevel = :placementLevel,
            @LevelName = :levelName,
            @TotalQuestions = :totalQuestions,
            @CorrectAnswers = :correctAnswers,
            @AccuracyPercentage = :accuracyPercentage,
            @Category1Score = :category1Score,
            @Category2Score = :category2Score,
            @Category3Score = :category3Score,
            @Category4Score = :category4Score,
            @Category1Theta = :category1Theta,
            @Category2Theta = :category2Theta,
            @Category3Theta = :category3Theta,
            @Category4Theta = :category4Theta,
            @TimeSpentSeconds = :timeSpentSeconds,
            @DeviceInfo = :deviceInfo,
            @AppVersion = :appVersion
    ");

    $stmt->bindValue(':studentID', $studentID, PDO::PARAM_INT);
    $stmt->bindValue(':sessionID', $sessionID, PDO::PARAM_INT);
    $stmt->bindValue(':assessmentType', $assessmentType, PDO::PARAM_STR);
    $stmt->bindValue(':finalTheta', $finalTheta, PDO::PARAM_STR);
    $stmt->bindValue(':placementLevel', $placementLevel, PDO::PARAM_INT);
    $stmt->bindValue(':levelName', $levelName, PDO::PARAM_STR);
    $stmt->bindValue(':totalQuestions', $totalQuestions, PDO::PARAM_INT);
    $stmt->bindValue(':correctAnswers', $correctAnswers, PDO::PARAM_INT);
    $stmt->bindValue(':accuracyPercentage', $accuracyPercentage, PDO::PARAM_STR);
    $stmt->bindValue(':category1Score', $categoryScores['category1'] ?? null, PDO::PARAM_STR);
    $stmt->bindValue(':category2Score', $categoryScores['category2'] ?? null, PDO::PARAM_STR);
    $stmt->bindValue(':category3Score', $categoryScores['category3'] ?? null, PDO::PARAM_STR);
    $stmt->bindValue(':category4Score', $categoryScores['category4'] ?? null, PDO::PARAM_STR);
    $stmt->bindValue(':category1Theta', $categoryTheta['category1'] ?? null, PDO::PARAM_STR);
    $stmt->bindValue(':category2Theta', $categoryTheta['category2'] ?? null, PDO::PARAM_STR);
    $stmt->bindValue(':category3Theta', $categoryTheta['category3'] ?? null, PDO::PARAM_STR);
    $stmt->bindValue(':category4Theta', $categoryTheta['category4'] ?? null, PDO::PARAM_STR);
    $stmt->bindValue(':timeSpentSeconds', $timeSpentSeconds, PDO::PARAM_INT);
    $stmt->bindValue(':deviceInfo', $deviceInfo, PDO::PARAM_STR);
    $stmt->bindValue(':appVersion', $appVersion, PDO::PARAM_STR);

    $stmt->execute();

    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$result) {
        sendError("Failed to save placement result", 500);
    }

    // Log activity
    logActivity($studentID, 'PlacementSaved', "Saved $assessmentType result: Level $placementLevel");

    // Format response
    $response = [
        'success' => true,
        'message' => 'Placement result saved successfully',
        'result' => [
            'ResultID' => (int)$result['ResultID'],
            'StudentID' => (int)$result['StudentID'],
            'AssessmentType' => $result['AssessmentType'],
            'PlacementLevel' => (int)$result['PlacementLevel'],
            'LevelName' => $result['LevelName'],
            'AccuracyPercentage' => (float)$result['AccuracyPercentage'],
            'CompletedDate' => $result['CompletedDate']
        ]
    ];

    sendResponse($response, 201);

} catch (PDOException $e) {
    error_log("Save placement result error: " . $e->getMessage());
    sendError("Failed to save placement result", 500, $e->getMessage());
} catch (Exception $e) {
    error_log("Save placement result error: " . $e->getMessage());
    sendError("An error occurred", 500, $e->getMessage());
}
?>
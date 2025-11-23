<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'student') {
    http_response_code(401);
    die('Unauthorized');
}

// Get file ID from URL
$fileId = $_GET['file'] ?? '';
if (empty($fileId)) {
    http_response_code(400);
    die('No file specified');
}

// Get file info from database
include '../../includes/database.php';
$username = $_SESSION['username'];

$sql = "SELECT r.* FROM results r 
        JOIN students s ON r.student_id = s.student_id 
        WHERE s.username = ? AND r.drive_file_id = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ss', $username, $fileId);
$stmt->execute();
$result = $stmt->get_result();
$file = $result->fetch_assoc();

if (!$file) {
    http_response_code(404);
    die('File not found or access denied');
}

// Check if this is a preview request or download
$isPreview = isset($_GET['preview']) && $_GET['preview'] === '1';

// Generate authorized download URL
require_once __DIR__ . '/../../api/backblaze_b2.php';
$b2Config = require __DIR__ . '/../../api/b2_config.php';

try {
    $b2 = new BackblazeB2(
        $b2Config['keyId'],
        $b2Config['applicationKey'],
        $b2Config['bucketName']
    );
    
    // Extract the full file path from drive_link
    // B2 URL format: https://f000.backblazeb2.com/file/bucket-name/path/to/file.pdf
    $urlParts = parse_url($file['drive_link']);
    $fullPath = ltrim($urlParts['path'], '/');
    
    // Remove the '/file/bucket-name/' prefix to get the actual file path
    $pathSegments = explode('/', $fullPath);
    // Skip 'file' and bucket name, get the rest
    $fileName = implode('/', array_slice($pathSegments, 2));
    
    // Get authorized URL (valid for 1 hour)
    $authorizedUrl = $b2->getDownloadAuthorization($fileName, 3600);
    
    if ($isPreview) {
        // For preview, fetch the file and serve it directly to avoid CORS issues
        $ch = curl_init($authorizedUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $fileContent = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            http_response_code(500);
            die('Failed to fetch file from B2');
        }
        
        // Serve the file with proper headers for preview
        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . strlen($fileContent));
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: public, max-age=3600');
        echo $fileContent;
        exit;
    } else {
        // For download, redirect to authorized URL
        header('Location: ' . $authorizedUrl);
        exit;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    die('Download failed: ' . $e->getMessage());
}

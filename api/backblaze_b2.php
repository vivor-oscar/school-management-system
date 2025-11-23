<?php
/**
 * Backblaze B2 Storage Helper
 * Simple integration for uploading files to B2
 */

class BackblazeB2 {
    private $keyId;
    private $applicationKey;
    private $bucketId;
    private $bucketName;
    private $authToken;
    private $apiUrl;
    private $downloadUrl;
    
    public function __construct($keyId, $applicationKey, $bucketName) {
        $this->keyId = $keyId;
        $this->applicationKey = $applicationKey;
        $this->bucketName = $bucketName;
        
        $this->authorize();
    }
    
    /**
     * Authorize with B2 and get auth token
     */
    private function authorize() {
        $ch = curl_init('https://api.backblazeb2.com/b2api/v2/b2_authorize_account');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Basic ' . base64_encode($this->keyId . ':' . $this->applicationKey)
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception('B2 Authorization failed: ' . $response);
        }
        
        $data = json_decode($response, true);
        $this->authToken = $data['authorizationToken'];
        $this->apiUrl = $data['apiUrl'];
        $this->downloadUrl = $data['downloadUrl'];
        
        // Get bucket ID
        $this->getBucketId();
    }
    
    /**
     * Get bucket ID by name
     */
    private function getBucketId() {
        $ch = curl_init($this->apiUrl . '/b2api/v2/b2_list_buckets');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: ' . $this->authToken
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'accountId' => $this->keyId,
            'bucketName' => $this->bucketName
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        $data = json_decode($response, true);
        if (isset($data['buckets'][0]['bucketId'])) {
            $this->bucketId = $data['buckets'][0]['bucketId'];
        } else {
            throw new Exception('Bucket not found: ' . $this->bucketName);
        }
    }
    
    /**
     * Upload file to B2
     * 
     * @param string $filePath Local file path
     * @param string $fileName Name to store in B2
     * @return array File info (fileId, fileName, downloadUrl)
     */
    public function uploadFile($filePath, $fileName) {
        if (!file_exists($filePath)) {
            throw new Exception('File not found: ' . $filePath);
        }
        
        // Get upload URL
        $uploadData = $this->getUploadUrl();
        
        // Read file
        $fileContent = file_get_contents($filePath);
        $sha1 = sha1_file($filePath);
        $contentType = mime_content_type($filePath);
        
        // Upload file
        $ch = curl_init($uploadData['uploadUrl']);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: ' . $uploadData['authorizationToken'],
            'X-Bz-File-Name: ' . urlencode($fileName),
            'Content-Type: ' . $contentType,
            'X-Bz-Content-Sha1: ' . $sha1
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fileContent);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception('Upload failed: ' . $response);
        }
        
        $data = json_decode($response, true);
        
        return [
            'fileId' => $data['fileId'],
            'fileName' => $data['fileName'],
            'downloadUrl' => $this->downloadUrl . '/file/' . $this->bucketName . '/' . $fileName,
            'size' => $data['contentLength']
        ];
    }
    
    /**
     * Get authorized download URL for a file
     * This creates a temporary URL that works with private buckets
     * 
     * @param string $fileName File name in B2
     * @param int $validDurationSeconds How long the URL is valid (default 24 hours)
     * @return string Authorized download URL
     */
    public function getDownloadAuthorization($fileName, $validDurationSeconds = 86400) {
        $ch = curl_init($this->apiUrl . '/b2api/v2/b2_get_download_authorization');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: ' . $this->authToken
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'bucketId' => $this->bucketId,
            'fileNamePrefix' => $fileName,
            'validDurationInSeconds' => $validDurationSeconds
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception('Failed to get download authorization: ' . $response);
        }
        
        $data = json_decode($response, true);
        $authToken = $data['authorizationToken'];
        
        // Return URL with authorization token
        return $this->downloadUrl . '/file/' . $this->bucketName . '/' . $fileName . '?Authorization=' . $authToken;
    }
    
    /**
     * Get upload URL for bucket
     */
    private function getUploadUrl() {
        $ch = curl_init($this->apiUrl . '/b2api/v2/b2_get_upload_url');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: ' . $this->authToken
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'bucketId' => $this->bucketId
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        return json_decode($response, true);
    }
    
    /**
     * Delete file from B2
     */
    public function deleteFile($fileId, $fileName) {
        $ch = curl_init($this->apiUrl . '/b2api/v2/b2_delete_file_version');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: ' . $this->authToken
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'fileId' => $fileId,
            'fileName' => $fileName
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $httpCode === 200;
    }
    
    /**
     * List files in bucket
     */
    public function listFiles($prefix = '', $maxFileCount = 100) {
        $ch = curl_init($this->apiUrl . '/b2api/v2/b2_list_file_names');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: ' . $this->authToken
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'bucketId' => $this->bucketId,
            'prefix' => $prefix,
            'maxFileCount' => $maxFileCount
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        $data = json_decode($response, true);
        return $data['files'] ?? [];
    }
}

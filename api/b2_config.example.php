<?php
/**
 * Backblaze B2 Configuration Template
 * 
 * Copy this file to b2_config.php and fill in your credentials
 * DO NOT commit b2_config.php to version control!
 */

return [
    // Your Backblaze B2 Key ID (starts with "005...")
    'keyId' => 'YOUR_KEY_ID_HERE',
    
    // Your Backblaze B2 Application Key
    'applicationKey' => 'YOUR_APPLICATION_KEY_HERE',
    
    // Your bucket name (must match the bucket you created)
    'bucketName' => 'your-bucket-name',
    
    // Optional: Folder prefix for organizing files
    'folderPrefix' => 'results/'
];

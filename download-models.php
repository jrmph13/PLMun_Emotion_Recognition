<?php
// download-models.php - Script to download face-api.js models
$models = [
    'https://raw.githubusercontent.com/justadudewhohacks/face-api.js/master/weights/tiny_face_detector_model-weights_manifest.json',
    'https://raw.githubusercontent.com/justadudewhohacks/face-api.js/master/weights/tiny_face_detector_model-shard1',
    'https://raw.githubusercontent.com/justadudewhohacks/face-api.js/master/weights/face_landmark_68_model-weights_manifest.json',
    'https://raw.githubusercontent.com/justadudewhohacks/face-api.js/master/weights/face_landmark_68_model-shard1',
    'https://raw.githubusercontent.com/justadudewhohacks/face-api.js/master/weights/face_recognition_model-weights_manifest.json',
    'https://raw.githubusercontent.com/justadudewhohacks/face-api.js/master/weights/face_recognition_model-shard1',
    'https://raw.githubusercontent.com/justadudewhohacks/face-api.js/master/weights/face_expression_model-weights_manifest.json',
    'https://raw.githubusercontent.com/justadudewhohacks/face-api.js/master/weights/face_expression_model-shard1'
];

// Create models directory
if (!file_exists('models')) {
    mkdir('models', 0777, true);
    echo "Created 'models' directory.\n";
}

// Download each model
foreach ($models as $modelUrl) {
    $filename = basename($modelUrl);
    $filepath = 'models/' . $filename;
    
    echo "Downloading: $filename... ";
    
    $content = file_get_contents($modelUrl);
    if ($content !== false) {
        file_put_contents($filepath, $content);
        echo "✓ Downloaded (" . strlen($content) . " bytes)\n";
    } else {
        echo "✗ Failed to download\n";
    }
    
    sleep(1); // Be nice to the server
}

echo "\nAll models downloaded to /models directory!\n";
echo "Now you can use the emotion detection feature.\n";
?>